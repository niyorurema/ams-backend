<?php

namespace App\Filters;

use App\Libraries\JwtTokenService;
use App\Models\TenantModel;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * Protège les routes API avec un jeton Bearer.
 */
class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $tokenService = new JwtTokenService();

        if (! $tokenService->isConfigured()) {
            return Services::response()
                ->setStatusCode(503)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Authentification API non configurée.']);
        }

        $authorization = $request->getHeaderLine('Authorization');
        $token = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) === 1) {
            $token = $matches[1];
        } elseif (is_string($request->getCookie('ams_access_token'))) {
            $token = $request->getCookie('ams_access_token');
        }
        if ($token === null || $token === '') {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Jeton Bearer requis.']);
        }

        try {
            $claims = $tokenService->decodeToken($token);
        } catch (Throwable $e) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Jeton invalide ou expiré.']);
        }

        $user = (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->first();
        if ($user === null || (int) $user['is_active'] !== 1) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Compte utilisateur inactif ou introuvable.']);
        }
        if (! empty($user['employe_id'])) {
            $employee = (new \App\Models\EmployeModel())
                ->where('id', (int) $user['employe_id'])
                ->where('tenant_id', (int) $claims->tenant_id)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->first();
            if ($employee === null) {
                return Services::response()
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'data' => null, 'message' => 'Compte utilisateur lié à un employé inactif ou introuvable.']);
            }
        }

        $tenant = (new TenantModel())->find((int) $claims->tenant_id);
        $claims->role = (string) $user['role'];
        $claims->is_super_admin = (bool) $user['is_super_admin'];

        if ($tenant === null || (int) $tenant['is_active'] !== 1) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Tenant inactif ou introuvable.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}