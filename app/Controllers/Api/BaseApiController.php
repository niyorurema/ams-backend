<?php

namespace App\Controllers\Api;

use App\Libraries\JwtTokenService;
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
        $authorization = $this->request->getServer('HTTP_AUTHORIZATION');//$authorization = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) !== 1) {
            return null;
        }

        try {
            return (new JwtTokenService())->decodeToken($matches[1]);
        } catch (Throwable) {
            return null;
        }
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

        return $claims !== null
            && ((bool) $claims->is_super_admin || in_array((string) $claims->role, ['admin', 'rh'], true));
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
