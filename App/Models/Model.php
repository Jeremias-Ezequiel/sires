<?php

namespace App\Models;

use App\Config\Database;
use PDO;

abstract class Model
{
    protected PDO $db;

    public function __construct()
    {
        $database = new Database();

        $this->db = $database->getConnection();
    }

    public function getConnection(): PDO
    {
        return $this->db;
    }

    public function setConnection(PDO $db): void
    {
        $this->db = $db;
    }

    protected function nextId(string $table): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(id), 0) + 1 FROM {$table}");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
