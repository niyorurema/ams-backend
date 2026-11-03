<?php

namespace App\Controllers\Api;

use App\Services\AuthService;
use App\Services\LdapAuthService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Authentification JWT de l’API.
 */
class AuthController extends BaseApiController
{
    /**
     * Échange les identifiants d’un tenant contre un jeton Bearer.
     */
    public function login(): ResponseInterface
    {
        $input = $this->jsonInput();

        $tenantIdInput = $input['tenant_id'] ?? null;
        $tenantId = $tenantIdInput === null
            ? null
            : filter_var($tenantIdInput, FILTER_VALIDATE_INT);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return $this->errorResponse('Email et mot de passe requis.', 400);
        }

        if ($tenantIdInput !== null && ($tenantId === false || $tenantId < 1)) {
            return $this->errorResponse('tenant_id doit être un entier positif.', 400);
        }

        $ip = $this->request->getIPAddress();
        $rateLimit = $this->rateLimitStatus($tenantId, $email, $ip);
        if ($rateLimit !== null) {
            return $this->errorResponse('Trop de tentatives de connexion. Réessayez dans quelques minutes.', 429)
                ->setHeader('Retry-After', (string) $rateLimit);
        }

        $service = new AuthService();
        $options = [
            'ip' => $ip,
            'user_agent' => $this->request->getUserAgent()->getAgentString(),
        ];

        try {
            $resultat = $tenantId === null
                ? $service->loginParEmail($email, $password, $options)
                : $service->login((int) $tenantId, $email, $password, $options);
        } catch (Throwable $e) {
            log_message('error', 'Échec login API : {error}', ['error' => $e->getMessage()]);

            return $this->errorResponse('Erreur interne pendant l’authentification.', 500);
        }

        if (! $resultat['success'] || ! is_array($resultat['user']) || ! is_string($resultat['token'])) {
            $this->registerFailedLogin($tenantId, $email, $ip);
            return $this->errorResponse($resultat['message'] ?? 'Identifiants invalides.', 401);
        }

        $this->resetFailedLogin($tenantId, $email, $ip);

        $ttl = max(60, min(86400, (int) env('JWT_TTL', 3600)));
        $secureCookie = filter_var(env('AUTH_COOKIE_SECURE', true), FILTER_VALIDATE_BOOLEAN);
        $sameSite = trim((string) env('AUTH_COOKIE_SAMESITE', 'None')) ?: 'None';
        $cookieDomain = trim((string) env('AUTH_COOKIE_DOMAIN', ''));
        $user = $resultat['user'];
        $response = $this->successResponse([
            'user' => [
                'id' => (int) $user['id'],
                'tenant_id' => (int) $user['tenant_id'],
                'email' => $user['email'],
                'nom_complet' => $user['nom_complet'],
                'role' => $user['role'],
                'is_super_admin' => (bool) $user['is_super_admin'],
            ],
            'expires_in' => $ttl,
        ], 'Connexion réussie.');

        $response->setCookie(
            'ams_access_token',
            (string) $resultat['token'],
            time() + $ttl,
            $cookieDomain,
            '/',
            '',
            $secureCookie,
            true,
            $sameSite
        );
        return $response;
    }

    public function logout(): ResponseInterface
    {
        $cookieDomain = trim((string) env('AUTH_COOKIE_DOMAIN', ''));
        $secureCookie = filter_var(env('AUTH_COOKIE_SECURE', true), FILTER_VALIDATE_BOOLEAN);
        $sameSite = trim((string) env('AUTH_COOKIE_SAMESITE', 'None')) ?: 'None';
        $response = $this->successResponse(null, 'Déconnexion réussie.');
        $response->setCookie('ams_access_token', '', time() - 3600, $cookieDomain, '/', '', $secureCookie, true, $sameSite);
        return $response;
    }

    private function loginRateKeys(?int $tenantId, string $email, string $ip): array
    {
        $tenantPart = $tenantId === null ? 'any' : (string) $tenantId;
        return [
            'login_ip_' . hash('sha256', $tenantPart . '|' . $ip),
            'login_account_' . hash('sha256', $tenantPart . '|' . strtolower($email)),
        ];
    }

    private function rateLimitStatus(?int $tenantId, string $email, string $ip): ?int
    {
        foreach ($this->loginRateKeys($tenantId, $email, $ip) as $key) {
            $failed = service('cache')->get($key);
            if (is_array($failed) && (int) ($failed['count'] ?? 0) >= 5) {
                $retryAfter = max(0, (int) ($failed['until'] ?? time()) - time());
                if ($retryAfter > 0) return $retryAfter;
                service('cache')->delete($key);
            }
        }
        return null;
    }

    private function registerFailedLogin(?int $tenantId, string $email, string $ip): void
    {
        foreach ($this->loginRateKeys($tenantId, $email, $ip) as $key) {
            $failed = service('cache')->get($key);
            $count = is_array($failed) ? (int) ($failed['count'] ?? 0) + 1 : 1;
            service('cache')->save($key, [
                'count' => $count,
                'until' => time() + (15 * 60),
            ], 15 * 60);
        }
    }

    private function resetFailedLogin(?int $tenantId, string $email, string $ip): void
    {
        foreach ($this->loginRateKeys($tenantId, $email, $ip) as $key) {
            service('cache')->delete($key);
        }
    }

    public function profile(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $user = (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->first();
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }

        return $this->successResponse([
            'id' => (int) $user['id'],
            'tenant_id' => (int) $user['tenant_id'],
            'email' => $user['email'],
            'nom_complet' => $user['nom_complet'],
            'role' => $user['role'],
            'is_super_admin' => (bool) $user['is_super_admin'],
            'derniere_connexion' => $user['derniere_connexion'],
        ]);
    }

    public function ldapTest(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        try {
            $resultat = (new LdapAuthService())->tester((int) $tenantId);
            if (! $resultat['success']) {
                return $this->response->setStatusCode(502)->setJSON([
                    'success' => false,
                    'data' => $resultat,
                    'message' => $resultat['message'] ?? 'Échec du test LDAP.',
                ]);
            }

            return $this->successResponse($resultat, 'Test LDAP terminé.');
        } catch (Throwable $e) {
            log_message('error', 'Test LDAP API impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de tester la connexion LDAP.', 500);
        }
    }

    public function ldapSyncUsers(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        $dryRun = filter_var($this->jsonInput()['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            $resultat = (new LdapAuthService())->syncUtilisateurs((int) $tenantId, $dryRun);
            return $this->successResponse($resultat, $dryRun ? 'Simulation LDAP terminée.' : 'Synchronisation LDAP terminée.');
        } catch (Throwable $e) {
            log_message('error', 'Synchronisation LDAP API impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de synchroniser les utilisateurs LDAP.', 502);
        }
    }
}