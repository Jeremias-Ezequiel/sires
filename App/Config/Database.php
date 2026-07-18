<?php

namespace App\Config; // O el namespace que uses para tus clases base

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?PDO $instance = null;

    public function __construct()
    {
    }

    public function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host   = $_ENV['DB_HOST'];
            $port   = $_ENV['DB_PORT'] ?? '3306';
            $dbname = $_ENV['DB_NAME'];
            $user   = $_ENV['DB_USER'];
            $pass   = $_ENV['DB_PASS'];

            try {
                $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

                self::$instance = new PDO($dsn, $user, $pass);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$instance->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } catch (PDOException $e) {
                error_log("[SIRES DB ERROR] Connection Failed: " . $e->getMessage());
                throw new Exception("Error interno de configuración. Intente más tarde.", 500, $e);
            }
        }

        return self::$instance;
    }
}
