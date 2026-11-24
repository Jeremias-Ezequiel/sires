<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Clientes;
use App\Models\Nacionalidad;
use App\Services\ClienteService;

class ClienteController extends Controller
{
    private ClienteService $clientes;

    public function __construct()
    {
        $this->clientes = new ClienteService();
    }

    public function showClients(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

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
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $countries = (new Nacionalidad())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/addClient.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function addClient(): void
    {
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->clientes->alta($_POST);
        }, '/dashboard/clients/add', null, true);
    }

    public function showEditClientForm(array $vars): void
    {
        $errorMessage = $this->tomarDeSesion('auth_error');

        [$flashMessage, $flashStatus] = $this->tomarFlash();

        $old = $this->tomarInputs();

        $id = filter_var($vars['id'] ?? '', FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            $this->flash('ID de cliente inválido.', 'error');
            $this->redirect('/dashboard/clients');
        }

        $cliente = (new Clientes())->getById($id);
        if (!$cliente) {
            $this->flash('El cliente solicitado no existe.', 'error');
            $this->redirect('/dashboard/clients');
        }

        $countries = (new Nacionalidad())->getAll();

        $contentView = __DIR__ . '/../views/dashboard/editClient.phtml';

        require_once __DIR__ . '/../views/dashboard/layout.phtml';
    }

    public function editClient(): void
    {
        $this->ejecutarAccion(function (): array {
            csrf_check();
            return $this->clientes->editar($this->idDe('id', 'ID de cliente inválido.'), $_POST);
        }, '/dashboard/clients', '/dashboard/clients/edit?id=' . urlencode((string)($_POST['id'] ?? '')), true);
    }
}
