<?php

/*
Copyright (c) 2013, Shawn Webb
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

   * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
   * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

class Mount {
    public $source;
    public $target;
    public $driver;
    public $options;
    public $jail;

    public static function Load($jail) {
        global $db;

        $mounts = array();

        $results = $db->Query("SELECT * FROM jailadmin_mounts WHERE jail = :name", array(":name" => $jail->name));

        foreach ($results as $record)
            $mounts[] = Mount::LoadFromRecord($jail, $record);

        return $mounts;
    }

    public static function LoadByTarget($jail, $target) {
        global $db;

        $results = $db->Query("SELECT * FROM jailadmin_mounts WHRE jail = :name AND target = :target", array(":name" => $jail->name, ":target" => $target));

        foreach ($results as $record)
            return Mount::LoadFromRecord($jail, $record);
    }

    public static function LoadFromRecord($jail, $record=array()) {
        if (count($record) == 0)
            return null;

        $mount = new Mount;

        $mount->jail = $jail;
        $mount->source = $record['source'];
        $mount->target = $record['target'];
        $mount->driver = $record['driver'];
        $mount->options = $record['options'];

        return $mount;
    }

    public function Create() {
        global $db;

        return $db->Execute("INSERT INTO jailadmin_mounts (jail, source, target, driver, options) VALUES (:jail, :source, :target, :driver, :options)", array(
            "jail" => $this->jail->name,
            "source" => $this->source,
            "target" => $this->target,
            "driver" => $this->driver,
            "options" => $this->options,
        ));
    }

    public function Delete() {
        global $db;

        return $db->Execute("DELETE FROM jailadmin_mounts WHERE jail = :jail AND target = :target", array(":jail" => $this->jail->name, ":target" => $this->target));
    }

    public function Serialize() {
        return array(
            "source" => $this->source,
            "target" => $this->target,
            "driver" => $this->driver,
            "options" => $this->options,
        );
    }
}
