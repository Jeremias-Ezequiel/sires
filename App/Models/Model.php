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
}
