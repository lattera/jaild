#!/usr/bin/env php
<?php

require_once("classes/db.php");
require_once("socket.php");

if (count($argv) != 3) {
    echo "USAGE: {$argv[0]}: [port] [path to config]";
    exit(1);
}

if (posix_getuid() != 0) {
    echo "ERROR: {$argv[0]} needs root privileges";
    exit(1);
}

require_once($argv[2]);

$db = new DB($server, $username, $password, $database);
$sock = socket_create_listen($argv[1]);
socket_set_nonblock($sock);
$clients = array();

while (true) {
    $read = array($sock);
    $null = NULL;
    foreach ($clients as $client)
        $read[] = $client->fd;

    $num = socket_select($read, $null, $null, $null);
    if (in_array($sock, $read)) {
        if (($newsock = socket_accept($sock)) != NULL) {
            $newSock = new Socket;
            $newSock->fd = $newsock;
            $newSock->buffer = "";

            $clients[] = $newSock;
        }

        $key = array_search($sock, $read);
        unset($read[$key]);
    }

    foreach ($read as $socket) {
        $sockobj = Socket::search_sockets($clients, $socket);
        $json = $sockobj->read_data();
        if ($json === false) {
            $key = array_search($socket, $clients);
            socket_close($socket);
            unset($clients[$key]);
            continue;
        }

        if ($json != null)
            $sockobj->act();
    }
}
