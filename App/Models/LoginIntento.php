<?php

declare(strict_types=1);

namespace App\Models;

use PDOException;
use Exception;

/**
 * Historial de intentos de login.
 *
 * El throttle que reemplaza el de sesion necesita sobrevivir al cierre de
 * navegador y al reinicio de la cookie, asi que el estado vive en la base.
 * Esta tabla es el detalle para auditar; el estado que bloquea de verdad esta
 * en Usuarios (intentos_fallidos + bloqueado_hasta), que se puede leer en una
 * sola consulta sin contar filas.
 */
class LoginIntento extends Model
{
    public const FALLO  = 0;
    public const EXITO  = 1;

    /**
     * Maximo de fallos desde una misma IP en la ventana. Pienso el caso de un
     * hotel: toda la recepcion suele salir por una unica IP publica, asi que el
     * limite por IP tiene que ser bien mas alto que el de cuenta. Si se pusiera
     * en 5, un compañero que se equivoca tres veces dejaria sin poder entrar a
     * los demas. 20 en 15 minutos filtra el barrido automatizado (que prueba
     * cientos de mails) sin molestar al uso normal.
     */
    public const MAX_FALLOS_POR_IP = 20;

    public const VENTANA_MINUTOS = 15;

    /**
     * Guarda un intento. El id_usuario va NULL cuando el mail no existe en la
     * base, que es justamente el caso interesante para detectar barridos.
     *
     * Nunca lanza: si falla el registro no se puede permitir que tumbe el
     * login, porque perder el historial es molesto pero dejar a alguien sin
     * poder entrar es inaceptable.
     */
    public function registrar(?int $idUsuario, string $email, bool $exitoso): void
    {
        try {
            $sql = "INSERT INTO Login_Intentos (id_usuario, email, exitoso, ip, user_agent)
                    VALUES (:id_usuario, :email, :exitoso, :ip, :ua)";

            $this->db->prepare($sql)->execute([
                ':id_usuario' => $idUsuario,
                ':email'      => mb_substr($email, 0, 150),
                ':exitoso'    => $exitoso ? self::EXITO : self::FALLO,
                ':ip'         => client_ip(),
                ':ua'         => client_user_agent()
            ]);
        } catch (PDOException $e) {
            error_log("No se pudo registrar Login_Intento: " . $e->getMessage());
        }
    }

    /**
     * Fallos recientes desde una IP. Devuelve 0 si no se puede consultar, para
     * que un problema de base no bloquee a todo el mundo.
     */
    public function contarFallosDesdeIp(?string $ip, int $minutos = self::VENTANA_MINUTOS): int
    {
        if ($ip === null) {
            return 0;
        }

        try {
            $sql = "SELECT COUNT(*) FROM Login_Intentos
                    WHERE ip = :ip
                      AND exitoso = :fallo
                      AND fecha >= DATE_SUB(NOW(), INTERVAL :min MINUTE)";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':fallo', self::FALLO, \PDO::PARAM_INT);
            $stmt->bindValue(':min', $minutos, \PDO::PARAM_INT);
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("No se pudo contar Login_Intentos por IP: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Historial de una cuenta, para la pantalla de seguridad del usuario.
     */
    public function ultimosDeUsuario(int $idUsuario, int $limite = 10): array
    {
        try {
            $sql = "SELECT id, email, exitoso, ip, user_agent, fecha
                    FROM Login_Intentos
                    WHERE id_usuario = :id
                    ORDER BY id DESC
                    LIMIT :limite";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $idUsuario, \PDO::PARAM_INT);
            $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("No se pudo leer el historial de Login_Intentos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Borra los intentos viejos. Esta tabla crece sin freno si nadie la limpia
     * (un login por dia por persona son pocos, pero un barrido puede generar
     * miles). Llamar desde un cron.
     */
    public function purgarAntiguos(int $dias = 90): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM Login_Intentos WHERE fecha < DATE_SUB(NOW(), INTERVAL :d DAY)");
            $stmt->bindValue(':d', $dias, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("No se pudo purgar Login_Intentos: " . $e->getMessage());
            return 0;
        }
    }
}
