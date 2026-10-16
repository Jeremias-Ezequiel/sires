<?php

namespace App\Controllers;

use Exception;
use App\Models\Clientes;
use App\Models\Nacionalidad;
use App\Models\Provincia;
use App\Models\Localidad;
use App\Helpers\Validator;
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

            $data = $this->validarYNormalizarCliente($_POST);

            $cliente = new Clientes();
            $cliente->setNombre($data['nombre']);
            $cliente->setApellido($data['apellido']);
            $cliente->setDniPasaporte($data['dni_pasaporte']);
            $cliente->setTelefono($data['telefono']);
            $cliente->setEmail($data['email']);
            $cliente->setObservaciones($data['observaciones']);
            $cliente->setIdNacionalidad($data['id_nacionalidad']);
            $cliente->setIdProvincia($data['id_provincia']);
            $cliente->setIdLocalidad($data['id_localidad']);

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

            $data = $this->validarYNormalizarCliente($_POST);

            $cliente = new Clientes();
            $cliente->setId((int)$id);
            $cliente->setNombre($data['nombre']);
            $cliente->setApellido($data['apellido']);
            $cliente->setDniPasaporte($data['dni_pasaporte']);
            $cliente->setTelefono($data['telefono']);
            $cliente->setEmail($data['email']);
            $cliente->setObservaciones($data['observaciones']);
            $cliente->setIdNacionalidad($data['id_nacionalidad']);
            $cliente->setIdProvincia($data['id_provincia']);
            $cliente->setIdLocalidad($data['id_localidad']);

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

    /**
     * Valida y normaliza los datos de un cliente provenientes del formulario.
     * Aplica normativa OACI 9303 para DNI/pasaporte, E.164 para teléfono y
     * verifica la coherencia de la cascada nacionalidad / provincia / localidad.
     *
     * @throws Exception cuando algún dato no supera la validación.
     */
    private function validarYNormalizarCliente(array $post): array
    {
        $nombre         = trim($post['nombre'] ?? '');
        $apellido       = trim($post['apellido'] ?? '');
        $dni            = trim($post['dni_pasaporte'] ?? '');
        $telefono       = trim($post['telefono'] ?? '');
        $email          = trim($post['mail'] ?? '');
        $observaciones  = trim($post['observaciones'] ?? '');
        $idNacionalidad = (int)($post['id_nacionalidad'] ?? 0);
        $idProvincia    = (int)($post['id_provincia'] ?? 0);
        $idLocalidad    = !empty($post['id_localidad']) ? (int)$post['id_localidad'] : null;

        // --- Textos libres ---
        Validator::letrasYEspacios($nombre, 'nombre', Validator::MAX_NOMBRE);
        Validator::letrasYEspacios($apellido, 'apellido', Validator::MAX_APELLIDO);
        $dni           = Validator::documentoCliente($dni);
        $telefono      = Validator::telefonoE164($telefono);
        $email         = Validator::emailValido($email);
        $observaciones = Validator::observacionesValidas($observaciones);

        // --- Selects obligatorios ---
        if ($idNacionalidad <= 0) {
            throw new Exception("Debe seleccionar una nacionalidad.");
        }
        if ($idProvincia <= 0) {
            throw new Exception("Debe seleccionar una provincia.");
        }

        // --- Existencia y coherencia de la cascada geográfica ---
        $nacionalidadModel = new Nacionalidad();
        if (!$nacionalidadModel->exists($idNacionalidad)) {
            throw new Exception("La nacionalidad seleccionada no es válida.");
        }

        $provincias = (new Provincia())->getByPais($idNacionalidad) ?? [];
        $provinciaValida = false;
        foreach ($provincias as $prov) {
            if ((int)$prov->getId() === $idProvincia) {
                $provinciaValida = true;
                break;
            }
        }
        if (!$provinciaValida) {
            throw new Exception("La provincia seleccionada no corresponde a la nacionalidad elegida.");
        }

        if ($idLocalidad !== null) {
            $localidades = (new Localidad())->getByProvincia($idProvincia) ?? [];
            $localidadValida = false;
            foreach ($localidades as $loc) {
                if ((int)$loc->getId() === $idLocalidad) {
                    $localidadValida = true;
                    break;
                }
            }
            if (!$localidadValida) {
                throw new Exception("La localidad seleccionada no corresponde a la provincia elegida.");
            }
        }

        return [
            'nombre'         => $nombre,
            'apellido'       => $apellido,
            'dni_pasaporte'  => $dni,
            'telefono'       => $telefono,
            'email'          => $email,
            'observaciones'  => $observaciones,
            'id_nacionalidad' => $idNacionalidad,
            'id_provincia'   => $idProvincia,
            'id_localidad'   => $idLocalidad,
        ];
    }
}
