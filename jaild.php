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
$sock = socket_create_listen($argv[1]);
if ($sock === false) {
    echo "ERROR: Could not create listening socket";
    exit(1);
}
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
