<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Provincia;
use App\Models\Localidad;

class ApiController
{
    public function getProvinces(array $vars): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $nacionalidadId = isset($vars['nacionalidadId']) ? (int) $vars['nacionalidadId'] : 0;

        if ($nacionalidadId <= 0) {
            echo json_encode([]);
            return;
        }

        $model = new Provincia();
        $provinces = $model->getByPais($nacionalidadId);

        $result = [];
        if (!empty($provinces)) {
            foreach ($provinces as $p) {
                $result[] = [
                    'id' => $p->getId(),
                    'descripcion' => $p->getDescripcion(),
                ];
            }
        }

        echo json_encode($result);
    }

    public function getCities(array $vars): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $provinciaId = isset($vars['provinciaId']) ? (int) $vars['provinciaId'] : 0;

        if ($provinciaId <= 0) {
            echo json_encode([]);
            return;
        }

        $model = new Localidad();
        $cities = $model->getByProvincia($provinciaId);

        $result = [];
        if (!empty($cities)) {
            foreach ($cities as $c) {
                $result[] = [
                    'id' => $c->getId(),
                    'descripcion' => $c->getDescripcion(),
                ];
            }
        }

        echo json_encode($result);
    }
}
