<?php

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
}
