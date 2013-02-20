<?php
class DB {
    private $dbh;
    private $server;
    private $username;
    private $password;
    private $db;

    function __construct($server, $username, $password, $db) {
        $this->server = $server;
        $this->username = $username;
        $this->password = $password;
        $this->db = $db;

        $this->dbh = new PDO("mysql:host={$this->server};dbname={$this->db}", $this->username, $this->password);
        var_dump($this->dbh);
    }

    function Query($query) {
        return $this->dbh->prepare($query);
    }
}
