<?php

namespace App;

use Carbon\Carbon;

class Database
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = $this->connect();
    }

    public function connect(): \PDO
    {
        $databaseUrl = parse_url(getenv('DATABASE_URL'));
        if ($databaseUrl === false) {
            throw new \Exception("Error reading database configuration file");
        }

        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $databaseUrl['host'],
            $databaseUrl['port'],
            ltrim($databaseUrl['path'], '/'),
            $databaseUrl['user'],
            $databaseUrl['pass']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function insert($siteUrl)
    {
        $date = Carbon::now()->toDateTimeString();
        $sql = "insert into urls(name, created_at) VALUES ('$siteUrl', '$date')";
        $this->pdo->exec($sql);
        return $this->pdo->lastInsertId();
    }

    public function query(string $sql): array|null
    {
        if ($this->pdo->query($sql)->rowCount() > 0) {
            return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        }
        return null;
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
