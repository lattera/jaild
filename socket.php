<?php

require_once('classes/Mount.php');
require_once('classes/Service.php');
require_once('classes/Network.php');
require_once('classes/NetworkDevice.php');
require_once('classes/Jail.php');

class Socket {
    public $fd;
    public $buffer;
    public $json;

    public function read_data() {
        /* Prevent using a lot of memory. Force clients to send <=1024 bytes. */
        if (strlen($this->buffer) > 1024)
            $this->buffer = "";

        $buffer .= socket_read($this->fd, 1024);
        if ($buffer === false)
            return false;

        $this->buffer .= $buffer;
        $this->json = json_decode($this->buffer, true);
        if ($this->json == null)
            return null;

        $this->buffer = "";
        return $this->json;
    }

    public function act() {
        if (!array_key_exists("action", $this->json)) {
            $ret = json_encode(array("statusCode" => "ERROR", "statusMsg" => "Invalid action"));
            socket_write($this->fd, $ret);
            return false;
        }

        /* This switch is HUGE but there's not really any other way to do this */
        $ret = array();
        switch ($this->json["action"]) {
            case "epair":
                $ret = array_merge($ret, $this->epair_act());
                break;
            case "create jail":
                $template = "";
                $jail = new Jail;
                $jail->name = $this->json["name"];
                $jail->dataset = $this->json["dataset"];

                if (array_key_exists("template", $this->json))
                    if (strlen($this->json["template"]))
                        $template = $this->json["template"];

                $jail->Create($template);
                $ret["statusCode"] = "OKAY";
                break;
            case "delete jail":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    $destroy = false;
                    if (array_key_exists("destroy", $this->json))
                        if ($this->json["destroy"] == "true")
                            $destroy = true;

                    $jail->Delete($destroy);
                    $ret["statusCode"] = "OKAY";
                }
                break;
            case "search jails":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    $ret = array_merge($ret, $jail->Serialize());
                    $ret["statusCode"] = "OKAY";
                }
                break;
            case "list jails":
                $jails = Jail::LoadAll();
                $i=0;
                foreach ($jails as $jail)
                    $ret["jail_" . $i++] = $jail->Serialize();

                $ret["statusCode"] = "OKAY";

                break;
            case "snapshot jail":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    if ($jail->Snapshot() == false) {
                        $ret["statusCode"] = "ERROR";
                        $ret["statusMsg"] = "Snapshot failed";
                    } else {
                        $ret["statusCode"] = "OKAY";
                    }
                }
                break;
            case "revert snapshot":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    $jail->RevertSnapshot($this->json["snapshot"]);
                    $ret["statusCode"] = "OKAY";
                }
                break;
            case "promote snapshot to template":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    $jail->CreateTemplateFromSnapshot($this->json["snapshot"]);
                    $jail["statusCode"] = "OKAY";
                }
                break;
            case "start jail":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "No such jail";
                } else {
                    if ($jail->Start() == false) {
                        $ret["statusCode"] = "ERROR";
                        $ret["statusMsg"] = "Failed to start jail {$jail->name}";
                    } else {
                        $ret["statusCode"] = "OKAY";
                    }
                }
                break;
            case "stop jail":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "No such jail";
                } else {
                    if ($jail->Stop() == false) {
                        $ret["statusCode"] = "ERROR";
                        $ret["statusMsg"] = "Failed to stop jail {$jail->name}";
                    } else {
                        $ret["statusCode"] = "OKAY";
                    }
                }
                break;
            default:
                $ret["statusCode"] = "ERROR";
                $ret["statusMsg"] = "Invalid action";
                break;
        }

        socket_write($this->fd, json_encode($ret) . "\n");

        return ($ret["statusCode"] == "OKAY");
    }

    public function epair_act() {
        return array("statusCode" => "OKAY");
    }

    public static function search_sockets($clients, $fd) {
        foreach ($clients as $client)
            if ($client->fd == $fd)
                return $client;

        return false;
    }
}
