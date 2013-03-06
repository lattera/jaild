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
            case "autoboot":
                $jails = Jail::LoadAllAutoboot();
                if ($jail === false) {
                    $ret["statusCode"] = "ERROR";
                    $ret["statusMsg"] = "No such jail";
                } else {
                    foreach ($jails as $jail) {
                        if ($jail->Start() == false) {
                            $ret["statusCode"] = "ERROR";
                            $ret["statusMsg"] .= (strlen($ret["statusMsg"]) ? ", " : "") . "Failed to start jail {$jail->name}";
                        } else {
                            $ret["statusCode"] = "OKAY";
                        }
                    }
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
        global $db;

        if (!array_key_exists("subaction", $this->json))
            return array("statusCode" => "ERROR", "statusMsg" => "Subaction required");

        if (!array_key_exists("jail", $this->json))
            return array("statusCode" => "ERROR", "statusMsg" => "Jail not specified");

        $jail = Jail::Load($this->json["jail"]);
        if ($jail === false)
            return array("statusCode" => "ERROR", "statusMsg" => "Jail not found");

        switch ($this->json["subaction"]) {
            case "create":
                if (!array_key_exists("network", $this->json))
                    return array("statusCode" => "ERROR", "statusMsg" => "Network not specified");

                $network = Network::Load($this->json["network"]);
                if ($network === false)
                    return array("statusCode" => "ERROR", "statusMsg" => "Network not found");

                if (!NetworkDevice::IsDeviceAvailable($this->json["device"])) {
                    return array(
                        "statusCode" => "ERROR",
                        "statusMsg" => "Device unavailable. Suggested device: "
                            . NetworkDevice::NextAvailableDevice()
                        );
                }

                $device = new NetworkDevice;
                $device->jail = $jail;
                $device->bridge = $network;
                $device->device = $this->json["device"];
                $device->is_span = false;
                $device->dhcp = false;

                if (array_key_exists("is_span", $this->json) && $this->json["is_span"])
                    $this->is_span = true;
                if (array_key_exists("dhcp", $this->json) && $this->json["dhcp"])
                    $this->dhcp = true;

                $device->Create();

                $statusMsg = "";
                if (array_key_exists("ips", $this->json)) {
                    foreach ($this->json["ips"] as $ip) {
                        if (Network::IsIPAvailable($ip)) {
                            $db->Execute("INSERT INTO jailadmin_epair_aliases (device, ip) VALUES (:device, :ip)",
                                array(":device" => $device->device, ":ip" => $ip));
                        } else {
                            $statusMsg .= (strlen($statusMsg) ? ", " : "") . "IP {$ip} is unavailable";
                        }
                    }
                }

                if (strlen($statusMsg))
                    return array("statusCode" => "WARNING", "statusMsg" => $statusMsg);

                break;
            case "delete":
                foreach ($jail->network as $n)
                    if ($n->device == $this->json["device"])
                        $n->device->Delete();
                break;
            case "add ip":
                if (!Network::IsIPAvailable($this->json["ip"]))
                    return array("statusCode" => "ERROR", "statusMsg" => "The IP is unavailable");

                $device = null;
                foreach ($jail->network as $n)
                    if ($n->device == $this->json["device"])
                        $device = $n;

                if ($device == null)
                    return array("statusCode" => "ERROR", "statusMsg" => "The device doesn't exist");

                $db->Execute("INSERT INTO jailadmin_epair_aliases (device, ip) VALUES (:device, :ip)",
                    array(":device" => $device->device, ":ip" => $this->json["ip"]));

                break;
            case "delete ip":
                foreach ($jail->network as $n) {
                    if ($n->device == $this->json["device"] && in_array($this->json["ip"], $n->ips)) {
                        $db->Execute("DELETE FROM jailadmin_epair_aliases WHERE device = :device AND ip = :ip",
                            array(":device" => $n->device, ":ip" => $this->json["ip"]));
                        break;
                    }
                }

                break;
            case "set option":
                switch ($this->json["option"]) {
                    case "dhcp":
                        foreach ($jail->network as $n) {
                            if ($n->device == $this->json["device"]) {
                                $db->Execute("UPDATE jailadmin_epair SET dhcp = :dhcp WHERE device = :device",
                                    array(":dhcp" => ($this->json["dhcp"] ? "1" : "0"), ":device" => $n->device));
                                break;
                            }
                        }

                        break;
                    case "span":
                        foreach ($jail->network as $n) {
                            if ($n->device == $this->json["device"]) {
                                $db->Execute("UPDATE jailadmin SET is_span = :span WHERE device = :device",
                                    array(":span" => ($this->json["span"] ? "1" : "0"), ":device" => $n->device));
                                break;
                            }
                        }

                        break;
                    default:
                        return array("statusCode" => "ERROR", "statusMsg" => "Invalid option");
                }
            default:
                return array("statusCode" => "ERROR", "statusMsg" => "Invalid subaction");
        }

        return array("statusCode" => "OKAY");
    }

    public static function search_sockets($clients, $fd) {
        foreach ($clients as $client)
            if ($client->fd == $fd)
                return $client;

        return false;
    }
}
