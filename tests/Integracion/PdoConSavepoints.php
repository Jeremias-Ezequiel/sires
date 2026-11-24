<?php

declare(strict_types=1);

namespace Tests\Integracion;

use PDO;

/**
 * PDO para los tests de integración: el test abre la transacción real y las
 * transacciones que abren los Services pasan a ser SAVEPOINTs dentro de ella.
 * Así los Services funcionan igual (commit / rollBack propios) y al final del
 * test deshacerTodo() hace ROLLBACK de todo: la base queda como estaba.
 *
 * inTransaction() oculta la transacción del test, para que los Services que
 * preguntan si ya hay una abierta (ej. PagoService::recalcularResumen) se
 * comporten como en producción.
 */
final class PdoConSavepoints extends PDO
{
    private int $nivel = 0;

    public function iniciarTest(): void
    {
        if ($this->nivel !== 0) {
            $this->deshacerTodo();
        }
        parent::beginTransaction();
        $this->nivel = 1;
    }

    public function deshacerTodo(): void
    {
        if (parent::inTransaction()) {
            parent::rollBack();
        }
        $this->nivel = 0;
    }

    public function beginTransaction(): bool
    {
        if ($this->nivel === 0) {
            throw new \LogicException('Transacción abierta fuera de un test de integración.');
        }
        $this->exec('SAVEPOINT sp' . $this->nivel);
        $this->nivel++;
        return true;
    }

    public function commit(): bool
    {
        if ($this->nivel <= 1) {
            throw new \PDOException('There is no active transaction');
        }
        $this->nivel--;
        $this->exec('RELEASE SAVEPOINT sp' . $this->nivel);
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->nivel <= 1) {
            throw new \PDOException('There is no active transaction');
        }
        $this->nivel--;
        $this->exec('ROLLBACK TO SAVEPOINT sp' . $this->nivel);
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->nivel > 1;
    }
}
