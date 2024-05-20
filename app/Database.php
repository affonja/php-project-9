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
            5432,
            ltrim($databaseUrl['path'], '/'),
            $databaseUrl['user'],
            $databaseUrl['pass']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function createTables(): Database
    {
        $sql = file_get_contents('database.sql');
        $this->pdo->exec($sql);
        return $this;
    }

    public function query($sql)
    {
        $this->pdo->query($sql);
        return $this->pdo->lastInsertId();
    }

    public function getAll(string $sql): array|null
    {
        if ($this->pdo->query($sql)->rowCount() > 0) {
            return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        }
        return null;
    }
}
