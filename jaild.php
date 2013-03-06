#!/usr/bin/env php
<?php

/*
Copyright (c) 2013, Shawn Webb
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

   * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
   * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE. 
 */

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
$sock4 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock4 === false) {
    echo "ERROR: Could not create listening socket";
    exit(1);
}
socket_set_nonblock($sock4);
if (socket_set_option($sock4, SOL_SOCKET, SO_REUSEADDR, 1) == FALSE) {
    echo "ERROR: Could not set SO_REUSEADDR: " . socket_strerror(socket_last_error($sock4));
    socket_close($sock4);
    exit(1);
}
if (socket_bind($sock4, "0.0.0.0", $argv[1]) == FALSE) {
    echo "ERROR: Could not bind to 0.0.0.0";
    socket_close($sock4);
    exit(1);
}
$sock6 = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
if ($sock6 === false) {
    socket_close($sock4);
    echo "ERROR: Could not create listening socket";
    exit(1);
}
socket_set_nonblock($sock6);
if (socket_set_option($sock6, SOL_SOCKET, SO_REUSEADDR, 1) == FALSE) {
    echo "ERROR: Could not set SO_REUSEADDR: " . socket_strerror(socket_last_error($sock4));
    socket_close($sock4);
    socket_close($sock6);
    exit(1);
}
if (socket_bind($sock6, "::0", $argv[1]) == FALSE) {
    echo "ERROR: Could not bind to ::0";
    socket_close($sock4);
    socket_close($sock6);
    exit(1);
}

$clients = array();

function sig_handler($signo) {
    global $clients;

    foreach ($clients as $client)
        socket_close($client->fd);

    socket_close($sock4);
    socket_close($sock6);

    exit(0);
}

pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP, "sig_handler");
pcntl_signal(SIGINT, "sig_handler");

/* Boot up all the jails that are set to autoboot */
foreach (Jail::LoadAllAutoboot() as $jail)
    $jail->Start();

while (true) {
    $read = array($sock4, $sock6);
    $null = NULL;
    foreach ($clients as $client)
        $read[] = $client->fd;

    $num = socket_select($read, $null, $null, $null);
    if (in_array($sock4, $read)) {
        if (($newsock = socket_accept($sock4)) != NULL) {
            $newSock = new Socket;
            $newSock->fd = $newsock;
            $newSock->buffer = "";

            $clients[] = $newSock;
        }

        $key = array_search($sock4, $read);
        unset($read[$key]);
    }
    if (in_array($sock6, $read)) {
        if (($newsock = socket_accept($sock6)) != NULL) {
            $newSock = new Socket;
            $newSock->fd = $newsock;
            $newSock->buffer = "";

            $clients[] = $newSock;
        }

        $key = array_search($sock6, $read);
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
