<?php

/*
Copyright (c) 2013, Shawn Webb
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

   * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
   * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
class NetworkDevice {
    public $device;
    public $ips;
    public $bridge;
    public $is_span;
    public $dhcp;
    public $jail;
    public $ipv6;

    public static function Load($jail) {
        global $db;

        $results = $db->Query("SELECT * FROM jailadmin_epairs WHERE jail = :jail", array(":jail" => $jail->name));

        $devices = array();

        foreach ($results as $record)
            $devices[] = NetworkDevice::LoadFromRecord($jail, $record);

        return $devices;
    }

    public static function LoadByDeviceName($jail, $name) {
        global $db;

        $results = $db->Query("SELECT * FROM jailadmin_epairs WHERE device = :device", array(":device" => $name));
        foreach ($results as $record)
            return NetworkDevice::LoadFromRecord($jail, $record);
    }

    public function IsOnline() {
        $o = exec("/sbin/ifconfig {$this->device}a 2>&1 | grep -v \"does not exist\"");
        return strlen($o) > 0;
    }

    public function BringHostOnline() {
        if ($this->IsOnline())
            return TRUE;

        if ($this->bridge->BringOnline() == FALSE)
            return FALSE;

        exec("/sbin/ifconfig {$this->device} create");
        if ($this->is_span) {
            exec("/sbin/ifconfig {$this->bridge->device} span {$this->device}a");
        } else {
            exec("/sbin/ifconfig {$this->bridge->device} addm {$this->device}a");
        }

        exec("/sbin/ifconfig {$this->device}a up");

        return TRUE;
    }

    public function BringGuestOnline() {
        if ($this->jail->IsOnline() == FALSE)
            return FALSE;

        if ($this->IsOnline() == FALSE)
            if ($this->BringHostOnline() == FALSE)
                return FALSE;

        exec("/sbin/ifconfig {$this->device}b vnet \"{$this->jail->name}\"");
        foreach ($this->ips as $ip) {
            echo "Attempting to assign {$this->device} IP {$ip}\n";
            $inet = (strstr($ip, ':') === FALSE) ? 'inet' : 'inet6';
            exec("/usr/sbin/jexec \"{$this->jail->name}\" ifconfig {$this->device}b {$inet} \"{$ip}\" alias");
        }

        exec("/usr/sbin/jexec \"{$this->jail->name}\" /sbin/ifconfig {$this->device}b up");

        if ($this->dhcp)
            exec("/usr/sbin/jexec \"{$this->jail->name}\" /sbin/dhclient {$this->device}b > /dev/null 2>&1 &");

        return TRUE;
    }

    public function BringOffline() {
        if ($this->IsOnline() == FALSE)
            return TRUE;

        exec("/sbin/ifconfig {$this->device}a destroy");

        return TRUE;
    }

    protected static function LoadFromRecord($jail, $record=array()) {
        global $db;

        if (count($record) == 0)
            return FALSE;

        $net_device = new NetworkDevice;
        $net_device->device = $record['device'];
        $net_device->is_span = ($record['is_span'] == 1) ? TRUE : FALSE;
        $net_device->dhcp = ($record['dhcp'] == 1) ? TRUE : FALSE;
        $net_device->bridge = Network::Load($record['bridge']);
        $net_device->jail = $jail;
        $net_device->ipv6 = false;

        $net_device->ips = array();
        $results = $db->Query("SELECT * FROM jailadmin_epair_aliases WHERE device = :device", array(":device" => $net_device->device);
        foreach ($results as $ip_record) {
            $net_device->ips[] = $ip_record["ip"];
            if (strstr($ip_record["ip"], ":") !== FALSE)
                $net_device->ipv6 = true;
        }

        return $net_device;
    }

    public static function IsDeviceAvailable($device) {
        global $db;

        $results = $db->Query("SELECT device FROM jailadmin_epairs");

        foreach ($results as $record)
            if (!strcmp($record["device"], $device))
                return FALSE;

        return TRUE;
    }

    public static function NextAvailableDevice() {
        global $db;

        $results = $db->Query("SELECT device FROM jailadmin_epairs");

        $id = 0;
        foreach ($results as $record) {
            $i = substr($record["device"], strlen("epair"));
            if (intval($i) > $id)
                $id = intval($i);
        }

        for (++$id; ; $id++)
            if (NetworkDevice::IsDeviceAvailable("epair{$id}"))
                break;

        return $id;
    }

    public function Create() {
        global $db;

        if (NetworkDevice::IsDeviceAvailable($this->device) == FALSE)
            return FALSE;

        $sth = $db->Query("INSERT INTO jailadmin_epairs (jail, device, bridge, is_span, dhcp) VALUES (:jail, :device, :bridge, :is_span, :dhcp");
        return $sth->execute(array(
            ':jail' => $this->jail->name,
            ':device' => $this->device,
            ':bridge' => $this->bridge->name,
            ':is_span' => ($this->is_span) ? 1 : 0,
            ':dhcp' => ($this->dhcp) ? 1 : 0,
        ));
    }

    public function Delete() {
        global $db;

        $db->Execute("DELETE FROM jailadmin_epairs WHERE device = :device AND jail = :jail", array(":device" => $this->device, ":jail" => $this->jail->name));
        $db->Execute("DELETE FROM jailadmin_epair_aliases WHERE device = :device", array(":device" => $this->device));
    }

    public function Serialize() {
        return array(
            "device" => $this->device,
            "ips" => $this->ips,
            "bridge" => $this->bridge->Serialize(),
            "is_span" => $this->is_span,
            "dhcp" => $this->dhcp,
            "ipv6" => $this->ipv6,
        );
    }
}
