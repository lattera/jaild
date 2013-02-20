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

        $ret = array("statusCode" => "", "statusMsg" => "");
        switch ($this->json["action"]) {
            case "search jails":
                $jail = Jail::Load($this->json["name"]);
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "Jail not found";
                } else {
                    $ret = array_merge($ret, $jail->Serialize());
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

    public static function search_sockets($clients, $fd) {
        foreach ($clients as $client)
            if ($client->fd == $fd)
                return $client;

        return false;
    }
}
