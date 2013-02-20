<?php

/*
Copyright (c) 2013, Shawn Webb
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

   * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
   * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

class Service {
    public $path;
    public $jail;

    public static function Load($jail) {
        global $db;

        $services = array();

        $sth = $db->Query("SELECT * FROM jailadmin_services WHERE jail = :jail");
        $sth->execute(array(":jail" => $jail->name));

        foreach ($sth->fetchAll() as $record)
            $services[] = Service::LoadFromRecord($jail, $record);

        return $services;
    }

    public static function LoadFromRecord($jail, $record=array()) {
        $service = new Service;

        $service->path = $record['path'];
        $service->jail = $jail;

        return $service;
    }

    public function Create() {
        global $db;

        $sth = $db->Query("INSERT INTO jailadmin_services (path, jail) VALUES (:path, :jail)");
        return $sth->execute(array(":path" => $this->path, ":jail" => $this->jail->name));
    }

    public function Delete() {
        global $db;

        $sth = $db->Query("DELETE FROM jailadmin_services WHERE jail = :jail AND path = :path");
        return $sth->execute(array(":jail" => $this->jail->name, ":path" => $this->path));
    }
}
