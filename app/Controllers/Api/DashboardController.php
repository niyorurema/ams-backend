<?php

namespace App\Controllers\Api;

use App\Services\DashboardKpiService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/** Indicateurs opérationnels du dashboard, strictement isolés par tenant. */
class DashboardController extends BaseApiController
{
    public function kpis(): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->errorResponse('Compte inactif ou employé associé inactif.', 403);
        }
        $allowedRoles = ['admin', 'rh', 'superviseur', 'chef_service', 'directeur', 'commissaire'];
        if (! $this->isSuperAdmin() && ! in_array((string) $user['role'], $allowedRoles, true)) {
            return $this->errorResponse('Vous n’avez pas accès au tableau de bord.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            return $this->successResponse($this->service()->getPourTenant($tenantId));
        } catch (Throwable $exception) {
            log_message('error', 'Chargement des KPIs du dashboard impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Impossible de charger les indicateurs du dashboard.', 500);
        }
    }

    protected function service(): DashboardKpiService
    {
        return new DashboardKpiService(Database::connect());
    }
}
