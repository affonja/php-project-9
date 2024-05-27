<?php

namespace App;

use Exception;
use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->connect();
        $this->createTables();
    }

    public function connect(): PDO
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!$databaseUrl) {
            throw new Exception("Error reading DATABASE_URL");
        }
        $parseUrl = parse_url(getenv('DATABASE_URL'));
        $connectionStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $parseUrl['host'],
            5432,
            ltrim($parseUrl['path'], '/'),
            $parseUrl['user'],
            $parseUrl['pass']
        );

        try {
            $pdo = new PDO($connectionStr);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function createTables(): void
    {
//        $sql = file_get_contents('database.sql');
        $sql = file_get_contents(__DIR__ . "/../database.sql");
        $this->pdo->exec($sql);
    }

    public function executeQuery(string $sql, array $data): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception('Error execute query: ' . $e->getMessage());
        }
    }

    public function insert(string $sql, array $data): string
    {
        $this->executeQuery($sql, $data);
        return $this->pdo->lastInsertId();
    }

    public function getAll(string $sql, array $data): array
    {
        $result = $this->executeQuery($sql, $data);
        return $result->fetchAll(PDO::FETCH_ASSOC);
    }
}
