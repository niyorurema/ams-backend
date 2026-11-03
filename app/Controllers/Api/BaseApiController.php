<?php

namespace App\Controllers\Api;

use App\Libraries\JwtTokenService;
use App\Models\UserModel;
use App\Services\RapportService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Fonctions communes aux contrôleurs REST protégés par JWT.
 */
abstract class BaseApiController extends Controller
{
    /** @return ?\stdClass */
    protected function claims(): ?\stdClass
    {
        $authorization = $this->request->getServer('HTTP_AUTHORIZATION');
        $token = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) === 1) {
            $token = $matches[1];
        } elseif (is_string($this->request->getCookie('ams_access_token'))) {
            $token = $this->request->getCookie('ams_access_token');
        }
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $claims = (new JwtTokenService())->decodeToken($token);
        } catch (Throwable) {
            return null;
        }

        $user = (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->first();
        if (! is_array($user)
            || (int) ($user['is_active'] ?? 0) !== 1
            || ! empty($user['employe_id']) && $this->employeUtilisateurActif((int) $user['employe_id'], (int) $claims->tenant_id) === false
        ) {
            return null;
        }

        $claims->role = (string) $user['role'];
        $claims->is_super_admin = (bool) $user['is_super_admin'];
        return $claims;
    }

    private function employeUtilisateurActif(int $employeId, int $tenantId): bool
    {
        return (new \App\Models\EmployeModel())
            ->where('id', $employeId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->first() !== null;
    }

    protected function tenantId(?int $requestedTenantId = null): ?int
    {
        $claims = $this->claims();
        if ($claims === null) {
            return null;
        }

        $tokenTenantId = (int) $claims->tenant_id;
        if ((bool) $claims->is_super_admin) {
            return $requestedTenantId ?? $tokenTenantId;
        }

        if ($requestedTenantId !== null && $requestedTenantId !== $tokenTenantId) {
            return null;
        }

        return $tokenTenantId;
    }

    protected function isSuperAdmin(): bool
    {
        return (bool) ($this->claims()?->is_super_admin ?? false);
    }

    protected function canManage(): bool
    {
        $claims = $this->claims();
        if ($claims === null) {
            return false;
        }

        // Le filtre JWT vérifie déjà le rôle et le statut actuels en base.
        return (bool) ($claims->is_super_admin ?? false)
            || in_array((string) ($claims->role ?? ''), ['admin', 'rh'], true);
    }

    /** Utilisateur courant rechargé depuis la base, donc jamais basé sur un ancien rôle JWT. */
    protected function currentUser(): ?array
    {
        $claims = $this->claims();
        if ($claims === null) {
            return null;
        }

        if (! isset($claims->sub)) {
            return [
                'id' => 0,
                'tenant_id' => (int) ($claims->tenant_id ?? 0),
                'role' => (string) ($claims->role ?? ''),
                'is_super_admin' => (int) (bool) ($claims->is_super_admin ?? false),
                'employe_id' => null,
            ];
        }
        if (! isset($claims->tenant_id)) {
            return null;
        }

        $user = (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->where('is_active', 1)
            ->first();

        if ($user !== null) {
            if (! empty($user['employe_id'])) {
                $employee = (new \App\Models\EmployeModel())
                    ->where('id', (int) $user['employe_id'])
                    ->where('tenant_id', (int) $claims->tenant_id)
                    ->where('actif', 1)
                    ->where('deleted_at', null)
                    ->first();
                if ($employee === null) {
                    return null;
                }
            }
            return $user;
        }

        // Compatibilité pour les appels unitaires utilisant directement les claims.
        return [
            'id' => (int) $claims->sub,
            'tenant_id' => (int) $claims->tenant_id,
            'role' => (string) ($claims->role ?? ''),
            'is_super_admin' => (int) (bool) ($claims->is_super_admin ?? false),
            'employe_id' => null,
        ];
    }

    /**
     * null signifie aucune restriction tenant-wide (admin/RH/super-admin).
     * Sinon retourne les identifiants d'employés visibles pour ce compte.
     *
     * @return array<int, int>|null
     */
    protected function visibleEmployeeIds(?string $date = null): ?array
    {
        $user = $this->currentUser();
        if ($user === null) {
            return [];
        }

        if ((int) $user['is_super_admin'] === 1 || in_array((string) $user['role'], ['admin', 'rh'], true)) {
            return null;
        }

        if ((string) $user['role'] === 'lecteur') {
            return ! empty($user['employe_id']) ? [(int) $user['employe_id']] : [];
        }

        try {
            $employes = (new RapportService())->getEmployesVisibles((int) $user['id'], $date);
            return array_values(array_unique(array_map(
                static fn (array $employe): int => (int) $employe['id'],
                $employes
            )));
        } catch (Throwable) {
            return [];
        }
    }

    /** Les superviseurs peuvent consulter les réglages de pointage, sans les modifier. */
    protected function canReadAttendanceSettings(): bool
    {
        $claims = $this->claims();

        return $claims !== null
            && ((bool) $claims->is_super_admin
                || in_array((string) $claims->role, ['admin', 'rh', 'superviseur'], true));
    }

    /** @return array<string, mixed> */
    protected function jsonInput(): array
    {
        $input = $this->request->getJSON(true);
        if (is_array($input)) {
            return $input;
        }

        $input = $this->request->getPost();
        return is_array($input) ? $input : [];
    }

    protected function successResponse(mixed $data, ?string $message = null, int $statusCode = 200): ResponseInterface
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return $this->response->setStatusCode($statusCode)->setJSON($payload);
    }

    protected function errorResponse(string $message, int $statusCode): ResponseInterface
    {
        return $this->response->setStatusCode($statusCode)->setJSON([
            'success' => false,
            'data' => null,
            'message' => $message,
        ]);
    }

    protected function authorizationError(): ResponseInterface
    {
        return $this->claims() === null
            ? $this->errorResponse('Jeton invalide ou expiré.', 401)
            : $this->errorResponse('Accès interdit à ce tenant.', 403);
    }
}
