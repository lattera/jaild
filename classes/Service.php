<?php

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
