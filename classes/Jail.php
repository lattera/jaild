<?php

class Jail {
    public $name;
    public $path;
    public $dataset;
    public $routes;
    public $network;
    public $services;
    public $mounts;
    private $_snapshots;

    function __construct() {
        $this->network = array();
        $this->services = array();
        $this->_snapshots = array();
    }

    public static function LoadAll() {
        global $db;

        $sth = $db->Query('SELECT name FROM jailadmin_jails');
        $sth->execute();
        $jails = array();

        foreach ($sth->fetchAll() as $record)
            $jails[] = Jail::Load($record->name);

        return $jails;
    }

    public static function Load($name) {
        global $db;

        $sth = $db->Query('SELECT * FROM jailadmin_jails WHERE name = :name');
        $sth->execute(array(':name' => $name));

        foreach ($sth->fetchAll() as $record)
            return Jail::LoadFromRecord($record);

        return false;
    }

    protected static function LoadFromRecord($record=array()) {
        global $db;

        if (count($record) == 0)
            return FALSE;

        $jail = new Jail;
        $jail->name = $record['name'];
        $jail->dataset = $record['dataset'];
        $jail->network = NetworkDevice::Load($jail);
        $jail->services = Service::Load($jail);
        $jail->mounts = Mount::Load($jail);

        $sth = $db->Query("SELECT source, destination FROM jailadmin_routes WHERE jail = :name");
        $sth->execute(array(":name" => $jail->name));

        $jail->routes = array();
        foreach ($sth->fetchAll() as $route) {
            $arr = array();
            $arr['source'] = $route["source"];
            $arr['destination'] = $route["destination"];

            $jail->routes[] = $arr;
        }

        $jail->path = exec("/sbin/zfs get -H -o value mountpoint {$jail->dataset}");

        return $jail;
    }

    private function load_snapshots() {
        exec("/sbin/zfs list -rH -oname -t snapshot {$this->dataset}", $this->_snapshots);
    }

    public function GetSnapshots() {
        if (count($this->_snapshots) > 0)
            return $this->_snapshots;

        $this->load_snapshots();

        return $this->_snapshots;
    }

    public function RevertSnapshot($snapshot) {
        exec("/usr/local/bin/sudo /sbin/zfs rollback -rf \"{$snapshot}\"");

        return TRUE;
    }

    public function CreateTemplateFromSnapshot($snapshot, $name='') {
        global $db;

        if ($name == '')
            $name = $snapshot;

        $sth = $db->Query("INSERT INTO jailadmin_templates (name, snapshot) VALUES (:name, :snapshot)");
        return $sth->execute(array(":name" => $name, ":snapshot" => $snapshot));
    }

    public function DeleteSnapshot($snapshot) {
        exec("/usr/local/bin/sudo /sbin/zfs destroy -rf \"{$snapshot}\"");

        return true;
    }

    public function IsOnline() {
        $o = exec("/usr/sbin/jls -n -j \"{$this->name}\" jid 2>&1 | grep -v \"{$this->name}\"");
        return strlen($o) > 0;
    }

    public function IsOnlineString() {
        if ($this->IsOnline())
            return 'Online';

        return 'Offline';
    }

    public function NetworkStatus() {
        $status = "";

        foreach ($this->network as $n) {
            $status .= (strlen($status) ? ", " : "") . $n->device . " { ";
            if ($n->is_span)
                $status .= "(SPAN)";

            if ($n->dhcp)
                $status .= " (DHCP), ";

            if (!count($n->ips))
                $status .= " (NO STATIC IP)";

            foreach ($n->ips as $ip)
                $status .= " {$ip}";

            $status .= ($n->IsOnline()) ? " (online)" : " (offline)";

            $status .= " }";
        }

        return $status;
    }

    public function Start() {
        if ($this->IsOnline())
            if ($this->Stop() == FALSE)
                return FALSE;

        exec("/usr/local/bin/sudo /sbin/mount -t devfs devfs {$this->path}/dev");
        exec("/usr/local/bin/sudo /usr/sbin/jail -c vnet 'name={$this->name}' 'host.hostname={$this->name}' 'path={$this->path}' persist");

        foreach ($this->network as $n)
            $n->BringHostOnline();

        foreach ($this->network as $n)
            $n->BringGuestOnline();

        foreach ($this->routes as $route) {
            $inet = (strstr($route['destination'], ':') === FALSE) ? 'inet' : 'inet6';

            exec("/usr/local/bin/sudo /usr/sbin/jexec '{$this->name}' route add -{$inet} '{$route['source']}' '{$route['destination']}'");
        }

        foreach ($this->mounts as $mount) {
            $command = "/usr/local/bin/sudo /sbin/mount ";
            if (strlen($mount->driver))
                $command .= "-t {$mount->driver} ";
            if (strlen($mount->options))
                $command .= "-o {$mount->options} ";

            exec("{$command} {$mount->source} {$this->path}/{$mount->target}");
        }

        foreach ($this->services as $service)
            exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->name}\" {$service->path} start");

        exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->name}\" /bin/sh /etc/rc");

        foreach ($this->network as $n)
            if ($n->ipv6)
                exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->name}\" /sbin/ifconfig {$n->device}b inet6 -ifdisabled");

        exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->name}\" /sbin/ifconfig lo0 inet 127.0.0.1");

        return TRUE;
    }

    public function Stop() {
        if ($this->IsOnline() == FALSE)
            return TRUE;

        exec("/usr/local/bin/sudo /usr/sbin/jail -r \"{$this->name}\"");
        exec("/usr/local/bin/sudo /sbin/umount {$this->path}/dev");

        foreach ($this->mounts as $mount) {
            $command = "/usr/local/bin/sudo /sbin/umount ";

            exec("{$command} -f {$this->path}/{$mount->target}");
        }

        foreach ($this->network as $n)
            $n->BringOffline();

        return TRUE;
    }

    public function Snapshot() {
        $date = strftime("%F_%T");

        exec("/usr/local/bin/sudo /sbin/zfs snapshot {$this->dataset}@{$date}");

        return TRUE;
    }

    public function UpgradeWorld() {
        if ($this->IsOnline())
            return FALSE;

        $date = strftime("%F_%T");

        if ($this->Snapshot() == FALSE)
            return FALSE;

        exec("cd /usr/src; /usr/local/bin/sudo make installworld DESTDIR={$this->path} > \"/tmp/upgrade-{$this->name}-{$date}.log\" 2>&1");

        return TRUE;
    }

    public function SetupServices() {
        if (count($this->network)) {
            $ip = Network::SanitizedIP($this->network[0]->ip);
            exec("/usr/local/bin/sudo /bin/sh -c '/bin/echo \"ListenAddress {$ip}\" >> {$this->path}/etc/ssh/sshd_config'");
            exec("/usr/local/bin/sudo /bin/sh -c '/bin/echo sshd_enable=\\\"YES\\\" >> {$this->path}/etc/rc.conf'");
        }
    }

    public function Create($template='') {
        global $db;

        if (strlen($template)) {
            /* If $template is set, we need to create this jail */
            exec("/usr/local/bin/sudo zfs clone {$template} {$this->dataset}");
        }

        $sth = $db->Query("INSERT INTO jailadmin_jails (name, dataset) VALUES (:name, :dataset)");
        return $sth->execute(array(":name" => $this->name, ":dataset" => $this->dataset));
    }

    public function Delete($destroy) {
        global $db;

        foreach ($this->network as $n)
            $n->Delete();

        foreach ($this->services as $s)
            $s->Delete();

        foreach ($this->mounts as $m)
            $m->Delete();

        $sth = $db->Query("DELETE FROM jailadmin_routes WHERE jail = :name");
        $sth->execute(array(":name" => $this->name));

        $sth = $db->Query("DELETE FROM jailadmin_jails WHERE name = :name");
        $sth->execute(array(":name" => $this->name));

        if ($destroy)
            exec("/usr/local/bin/sudo /sbin/zfs destroy {$this->dataset}");
    }

    public function Persist() {
        global $db;

        $sth = $db->Query("UPDATE jailadmin_jails SET dataset = :dataset WHERE name = :name");

        return $sth->execute(array(":dataset" => $this->dataset, ":name" => $this->name));
    }

    public function Serialize() {
        $serialized = array();
        $serialized["name"] = $this->name;
        $serialized["path"] = $this->path;
        $serialized["dataset"] = $this->dataset;
        $serialized["online"] = $this->IsOnline();

        return $serialized;
    }
}
