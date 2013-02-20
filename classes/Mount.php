<?php

class Mount {
    public $source;
    public $target;
    public $driver;
    public $options;
    public $jail;

    public static function Load($jail) {
        global $db;

        $mounts = array();

        $sth = $db->Query("SELECT * FROM jailadmin_mounts WHERE jail = :name");
        $sth->execute(array(":name" => $jail->name));

        while ($record = $sth->fetch(PDO::FETCH_ASSOC))
            $mounts[] = Mount::LoadFromRecord($jail, $record);

        return $mounts;
    }

    public static function LoadByTarget($jail, $target) {
        global $db;

        $sth = $db->Query("SELECT * FROM jailadmin_mounts WHRE jail = :name AND target = :target");
        $sth->execute(array(":name" => $jail->name, ":target" => $target));

        return Mount::LoadFromRecord($jail, $sth->fetch(PDO::FETCH_ASSOC));
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

        $sth = $db->Query("INSERT INTO jailadmin_mounts (jail, source, target, driver, options) VALUES (:jail, :source, :target, :driver, :options)");

        return $sth->execute(array(
            "jail" => $this->jail->name,
            "source" => $this->source,
            "target" => $this->target,
            "driver" => $this->driver,
            "options" => $this->options,
        ));
    }

    public function Delete() {
        global $db;

        $sth = $db->Query("DELETE FROM jailadmin_mounts WHERE jail = :jail AND target = :target");

        return  $sth->execute(array(":jail" => $this->jail->name, ":target" => $this->target));
    }
}
