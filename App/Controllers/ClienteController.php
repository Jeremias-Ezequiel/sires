<?php

namespace App\Controllers;

use Exception;
use App\Models\Clientes;
use App\Models\Nacionalidad;
use App\Helpers\UrlHelper;

class ClienteController
{
    public function showClients(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($currentPage < 1) { $currentPage = 1; }

        $limit = 10;
        $offset = ($currentPage - 1) * $limit;

        $search = $vars['search'] ?? "";

        $hasSearch = !empty($vars['search']);

        $clienteModel = new Clientes();

        $totalClientes = $clienteModel->countClientes($search, null);
        $totalPages = (int)ceil($totalClientes / $limit);
        if ($totalPages < 1) { $totalPages = 1; }
        if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $limit; }

        $listaClientes = $clienteModel->listClientes($search, null, $limit, $offset);

        $contentView = __DIR__ . '/../views/dashboard/clientes.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function showNewClientForm(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $old = $_SESSION['old_inputs'] ?? [];
        unset($_SESSION['old_inputs']);

        $countries = (new Nacionalidad())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addClient.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addClient(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $nombre        = trim($_POST['nombre'] ?? '');
            $apellido      = trim($_POST['apellido'] ?? '');
            $dni           = trim($_POST['dni_pasaporte'] ?? '');
            $telefono      = trim($_POST['telefono'] ?? '');
            $mail          = trim($_POST['mail'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $idNacionalidad = (int)($_POST['id_nacionalidad'] ?? 0);
            $idProvincia   = (int)($_POST['id_provincia'] ?? 0);
            $idLocalidad   = (int)($_POST['id_localidad'] ?? 0);

            if (empty($nombre)) {
                throw new Exception("El nombre es obligatorio.");
            }
            if (empty($apellido)) {
                throw new Exception("El apellido es obligatorio.");
            }
            if (empty($dni)) {
                throw new Exception("El DNI/Pasaporte es obligatorio.");
            }
            if (!preg_match('/^(?:[0-9]{6,8}|[0-9]{1,3}(?:\.[0-9]{3}){1,2}|[A-Za-z0-9]{5,9})$/', $dni)) {
                throw new Exception("El formato del DNI o Pasaporte no es válido (normativa OACI 9303).");
            }
            if (empty($mail)) {
                throw new Exception("El email es obligatorio.");
            }
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El formato del email no es válido.");
            }
            if ($telefono !== '') {
                $telefono = '+' . preg_replace('/[^0-9]/', '', $telefono);
                if (!preg_match('/^\+[0-9]{8,15}$/', $telefono)) {
                    throw new Exception("El teléfono debe cumplir el formato internacional UIT-T E.164 (ej: +5491123456789).");
                }
            }
            if ($idNacionalidad <= 0) {
                throw new Exception("Debe seleccionar una nacionalidad.");
            }
            if ($idProvincia <= 0) {
                throw new Exception("Debe seleccionar una provincia.");
            }
            if ($idLocalidad <= 0) {
                throw new Exception("Debe seleccionar una localidad.");
            }

            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) {
                throw new Exception("El nombre solo puede contener letras y espacios.");
            }
            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $apellido)) {
                throw new Exception("El apellido solo puede contener letras y espacios.");
            }

            $cliente = new Clientes();
            $cliente->setNombre($nombre);
            $cliente->setApellido($apellido);
            $cliente->setDniPasaporte($dni);
            $cliente->setTelefono($telefono);
            $cliente->setMail($mail);
            $cliente->setObservaciones($observaciones !== '' ? $observaciones : null);
            $cliente->setIdNacionalidad($idNacionalidad);
            $cliente->setIdProvincia($idProvincia);
            $cliente->setIdLocalidad($idLocalidad);

            $success = $cliente->save($cliente);

            if (!$success) {
                throw new Exception("No se pudo guardar el registro. Por favor, revise la información ingresada.");
            }

            $_SESSION['flash_message'] = "Cliente registrado exitosamente.";
            $_SESSION['flash_status']  = "success";
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";
        }

        header('Location: ' . UrlHelper::to('/dashboard/clients/add'));
        exit;
    }

    public function showEditClientForm(array $vars): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $errorMessage = $_SESSION['auth_error'] ?? '';
        unset($_SESSION['auth_error']);

        $flashMessage = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);

        $flashStatus = $_SESSION['flash_status'] ?? '';
        unset($_SESSION['flash_status']);

        $old = $_SESSION['old_inputs'] ?? [];
        unset($_SESSION['old_inputs']);

        $id = $vars['id'] ?? '';
        if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
            $_SESSION['flash_message'] = "ID de cliente inválido.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/clients'));
            exit;
        }

        $clienteModel = new Clientes();
        $cliente = $clienteModel->getById((int)$id);

        if (!$cliente) {
            $_SESSION['flash_message'] = "El cliente solicitado no existe.";
            $_SESSION['flash_status']  = "error";
            header('Location: ' . UrlHelper::to('/dashboard/clients'));
            exit;
        }

        $countries = (new Nacionalidad())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/editClient.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function editClient(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            csrf_check();

            $id = $_POST['id'] ?? '';
            if (empty($id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new Exception("ID de cliente inválido.");
            }

            $nombre        = trim($_POST['nombre'] ?? '');
            $apellido      = trim($_POST['apellido'] ?? '');
            $dni           = trim($_POST['dni_pasaporte'] ?? '');
            $telefono      = trim($_POST['telefono'] ?? '');
            $mail          = trim($_POST['mail'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');
            $idNacionalidad = (int)($_POST['id_nacionalidad'] ?? 0);
            $idProvincia   = (int)($_POST['id_provincia'] ?? 0);
            $idLocalidad   = (int)($_POST['id_localidad'] ?? 0);

            if (empty($nombre)) {
                throw new Exception("El nombre es obligatorio.");
            }
            if (empty($apellido)) {
                throw new Exception("El apellido es obligatorio.");
            }
            if (empty($dni)) {
                throw new Exception("El DNI/Pasaporte es obligatorio.");
            }
            if (!preg_match('/^(?:[0-9]{6,8}|[0-9]{1,3}(?:\.[0-9]{3}){1,2}|[A-Za-z0-9]{5,9})$/', $dni)) {
                throw new Exception("El formato del DNI o Pasaporte no es válido (normativa OACI 9303).");
            }
            if (empty($mail)) {
                throw new Exception("El email es obligatorio.");
            }
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El formato del email no es válido.");
            }
            if ($telefono !== '') {
                $telefono = '+' . preg_replace('/[^0-9]/', '', $telefono);
                if (!preg_match('/^\+[0-9]{8,15}$/', $telefono)) {
                    throw new Exception("El teléfono debe cumplir el formato internacional UIT-T E.164 (ej: +5491123456789).");
                }
            }
            if ($idNacionalidad <= 0) {
                throw new Exception("Debe seleccionar una nacionalidad.");
            }
            if ($idProvincia <= 0) {
                throw new Exception("Debe seleccionar una provincia.");
            }
            if ($idLocalidad <= 0) {
                throw new Exception("Debe seleccionar una localidad.");
            }

            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) {
                throw new Exception("El nombre solo puede contener letras y espacios.");
            }
            if (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $apellido)) {
                throw new Exception("El apellido solo puede contener letras y espacios.");
            }

            $cliente = new Clientes();
            $cliente->setId((int)$id);
            $cliente->setNombre($nombre);
            $cliente->setApellido($apellido);
            $cliente->setDniPasaporte($dni);
            $cliente->setTelefono($telefono);
            $cliente->setMail($mail);
            $cliente->setObservaciones($observaciones !== '' ? $observaciones : null);
            $cliente->setIdNacionalidad($idNacionalidad);
            $cliente->setIdProvincia($idProvincia);
            $cliente->setIdLocalidad($idLocalidad);

            $success = $cliente->update($cliente);

            if (!$success) {
                throw new Exception("No se pudo actualizar el cliente. Verifique los datos ingresados.");
            }

            $_SESSION['flash_message'] = "Cliente actualizado exitosamente.";
            $_SESSION['flash_status']  = "success";

            header('Location: ' . UrlHelper::to('/dashboard/clients'));
            exit;
        } catch (Exception $e) {
            $_SESSION['old_inputs']    = $_POST;
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_status']  = "error";

            $redirectId = $_POST['id'] ?? '';
            header('Location: ' . UrlHelper::to('/dashboard/clients/edit?id=' . urlencode((string)$redirectId)));
            exit;
        }
    }
}
