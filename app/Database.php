<?php

namespace App;

class Database
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = $this->connect();
    }

    public function connect(): \PDO
    {
        $params = parse_ini_file('database.ini');
        if ($params === false) {
            throw new \Exception("Error reading database configuration file");
        }
        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['password']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function insert()
    {
    }

    public function query($sql)
    {
        $result = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    public function createTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS urls (
                id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
                name varchar(255) UNIQUE NOT NULL,
                created_at timestamp
                                );';

        $this->pdo->exec($sql);

        return $this;
    }

    public function dropTable(string $tableName): void
    {
        $sql = "DROP TABLE IF EXISTS $tableName";
        $this->pdo->exec($sql);
    }

    public function clear()
    {
    }
}
