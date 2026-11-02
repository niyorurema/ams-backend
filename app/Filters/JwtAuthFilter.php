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
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) !== 1) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'data' => null, 'message' => 'Jeton Bearer requis.']);
        }

        try {
            $claims = $tokenService->decodeToken($matches[1]);
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

        $tenant = (new TenantModel())->find((int) $claims->tenant_id);
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