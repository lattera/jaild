<?php

/*
Copyright (c) 2013, Shawn Webb
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

   * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
   * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class Network {
    public $name;
    public $device;
    public $ips;
    public $physicals;
    private $_jails;

    public function __construct() {
        $this->physicals = array();
        $this->_jails = NULL;
    }

    public static function Load($name) {
        global $db;

        $sth = $db->Query("SELECT * FROM jailadmin_bridges WHERE name = :name");

        $sth->execute(array(":name" => $name));

        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result == false)
            return false;

        return Network::LoadFromRecord($result);
    }

    public static function LoadAll() {
        global $db;

        $sth = $db->Query("SELECT * FROM jailadmin_bridges");
        $sth->execute();

        $networks = array();

        foreach ($sth->fetchAll() as $record)
            $networks[] = Network::LoadFromRecord($record);

        return $networks;
    }

    public static function LoadFromRecord($record=array()) {
        global $db;

        $network = new Network;

        $network->name = $record['name'];
        $network->device = $record['device'];

        /* Load physical devices to add to the bridge */
        $sth = $db->Query("SELECT device FROM jailadmin_bridge_physicals WHERE bridge = :bridge");
        $sth->execute(array(":bridge" => $network->name));

        foreach ($sth->fetchAll() as $physical)
            $network->physicals[] = $physical["device"];

        $sth = $db->Query("SELECT ip FROM jailadmin_bridge_aliases WHERE device = :device");
        $sth->execute(array(":device" => $network->device));

        $network->ips = array();
        foreach ($sth->fetchAll() as $ip)
            $network->ips[] = $ip["ip"];

        return $network;
    }

    public static function IsIPAvailable($ip) {
        global $db;

        $sth = $db->Query("SELECT ip FROM jailadmin_bridge_aliases WHERE CHAR_LENGTH(ip) > 0");
        $sth->execute();

        foreach ($sth->fetchAll() as $record)
            if (!strcmp($record["ip"], $ip))
                return FALSE;

        $sth = $db->query("SELECT ip FROM jailadmin_epair_aliases WHERE CHAR_LENGTH(ip) > 0");
        $sth->execute();

        foreach ($$sth->fetchAll() as $record)
            if (!strcmp($record["ip"], $ip))
                return FALSE;

        return TRUE;
    }

    public static function IsDeviceAvailable($device) {
        global $db;

        $sth = $db->Query("SELECT device FROM jailadmin_bridges");
        $sth->execute();

        foreach ($sth->fetchAll() as $record)
            if (!strcmp($record["device"], $device))
                return FALSE;

        return TRUE;
    }

    public function IsOnline() {
        $o = exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} 2>&1 | grep -v \"does not exist\"");
        return strlen($o) > 0;
    }

    public function BringOnline() {
        if ($this->IsOnline())
            return TRUE;

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} create 2>&1");
        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} up 2>&1");

        foreach ($this->ips as $ip) {
            $inet = (strstr($ip, ':') === FALSE) ? 'inet' : 'inet6';
            exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} {$inet} {$ip} alias");
        }

        foreach ($this->physicals as $physical)
            exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} addm {$physical}");

        return TRUE;
    }

    public function BringOffline() {
        if ($this->IsOnline() == FALSE)
            return TRUE;

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} destroy");

        return TRUE;
    }

    public function Persist() {
        global $db;

        $sth = $db->Query("UPDATE jailadmin_bridges SET device = :device WHERE name = :name");
        return $sth->execute(array(":device" => $this->device, ":name" => $this->name));
    }

    public function Create() {
        global $db;

        $sth = $db->Query("INSERT INTO jailadmin_bridges (name, device) VALUES (:name, :device)");
        return $sth->execute(array(":name" => $this->name, ":device" => $this->device));
    }

    public function Delete() {
        global $db;

        $sth = $db->Query("DELETE FROM jailadmin_bridges WHERE name = :name");
        $sth->execute(array(":name" => $this->name));

        $sth = $db->query("DELETE FROM jailadmin_bridge_aliases WHERE device = :device");
        $sth->execute(array(":device" => $this->device));
    }

    public function AddIP($ip) {
        global $db;

        $sth = $db->execute("INSERT INTO jailadmin_bridge_aliases (device, ip) VALUES (:device, :ip)");
        $sth->execute(array(":device" => $this->device, ":ip" => $ip));

        $this->ips[] = $ip;
    }

    public function AssignedJails() {
        if ($this->_jails != NULL)
            return $this->_jails;

        $this->_jails = array();

        $jails = Jail::LoadAll();
        foreach ($jails as $jail)
            foreach ($jail->network as $network)
                if (!strcmp($network->bridge->name, $this->name))
                    $this->_jails[] = $jail;

        return $this->_jails;
    }

    public function CanBeModified() {
        $jails = $this->AssignedJails();
        foreach ($jails as $jail)
            if ($jail->IsOnline())
                return FALSE;

        return TRUE;
    }

    public static function SanitizedIP($ip) {
        $result = strstr($ip, '/', TRUE);
        if ($result === FALSE)
            return $ip;

        return $result;
    }

    public function Serialize() {
        return array(
            "name" => $this->name,
            "device" => $this->device,
            "ips" => $this->ips,
            "physicals" => $this->physicals,
        );
    }
}
