<?php

namespace App\Config; // O el namespace que uses para tus clases base

use PDO;
use PDOException;
use Exception;

class Database
{
    protected PDO $db;

    public function __construct()
    {
        $host   = $_ENV['DB_HOST'];
        $port   = $_ENV['DB_PORT'] ?? '3306';
        $dbname = $_ENV['DB_NAME'];
        $user   = $_ENV['DB_USER'];
        $pass   = $_ENV['DB_PASS'];

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

            $this->db = new PDO($dsn, $user, $pass);

            // Configuraciones profesionales de PDO
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Estricto y seguro contra SQL Injection

        } catch (PDOException $e) {
            // 1. REGISTRO PROFESIONAL: Guardamos el error real en los logs de Apache/PHP
            // Esto va a escribir en tu archivo de logs de Bitnami o Fedora la causa exacta
            error_log("[SIRES DB ERROR] Connection Failed: " . $e->getMessage());

            // 2. ENCAPSULAMIENTO: Lanzamos una excepción genérica para el usuario,
            // pero le pasamos $e como tercer parámetro (el "Previous Exception").
            // Esto mantiene viva la cadena del error real para herramientas de desarrollo.
            throw new Exception("Error interno de configuración. Intente más tarde.", 500, $e);
        }
    }

    // Método para poder usar la conexión en tus modelos
    public function getConnection(): PDO
    {
        return $this->db;
    }
}
