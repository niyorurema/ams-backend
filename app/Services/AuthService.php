<?php

namespace App\Services;

use App\Libraries\JwtTokenService;
use App\Models\TenantModel;
use App\Models\UserModel;
use Config\Database;
use Throwable;

/**
 * Unifie les authentifications locale, LDAP et hybride.
 */
class AuthService
{
    protected TenantModel $tenantModel;
    protected UserModel $userModel;
    protected LdapAuthService $ldapAuthService;
    protected JwtTokenService $jwtTokenService;

    public function __construct(
        ?TenantModel $tenantModel = null,
        ?UserModel $userModel = null,
        ?LdapAuthService $ldapAuthService = null,
        ?JwtTokenService $jwtTokenService = null
    ) {
        $this->tenantModel = $tenantModel ?? new TenantModel();
        $this->userModel = $userModel ?? new UserModel();
        $this->ldapAuthService = $ldapAuthService ?? new LdapAuthService();
        $this->jwtTokenService = $jwtTokenService ?? new JwtTokenService();
    }

    /**
     * Authentifie un utilisateur selon le mode configuré pour le tenant.
     *
     * @param array<string, mixed> $options Contexte HTTP d’audit, sans identifiants secrets.
     * @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string}
     */
    public function login(int $tenantId, string $email, string $password, array $options = []): array
    {
        $email = strtolower(trim($email));
        $tenant = $this->tenantModel->find($tenantId);
        if (! is_array($tenant) || (int) $tenant['is_active'] !== 1) {
            return $this->echec('Tenant introuvable ou inactif.');
        }

        $mode = strtoupper((string) ($tenant['auth_mode'] ?? 'LOCAL'));
        return match ($mode) {
            'LDAP' => $this->loginLdap($tenantId, $email, $password, $options),
            'HYBRIDE' => $this->loginHybride($tenantId, $email, $password, $options),
            default => $this->loginLocal($tenantId, $email, $password, $options),
        };
    }

    /**
     * Authentifie par email lorsque le tenant n'est pas fourni.
     * Les utilisateurs supprimés logiquement sont exclus par UserModel.
     *
     * @param array<string, mixed> $options Contexte HTTP d’audit, sans identifiants secrets.
     * @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string}
     */
    public function loginParEmail(string $email, string $password, array $options = []): array
    {
        $email = strtolower(trim($email));
        $users = $this->userModel->where('email', $email)->findAll();

        if ($users === []) {
            return $this->echec('Identifiants invalides.');
        }

        if (count($users) > 1) {
            return $this->echec('Cet email est associé à plusieurs entreprises. Contactez votre administrateur.');
        }

        $tenantId = filter_var($users[0]['tenant_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            return $this->echec('Identifiants invalides.');
        }

        return $this->login((int) $tenantId, $email, $password, $options);
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    public function loginLocal(int $tenantId, string $email, string $password, array $options = []): array
    {
        $user = $this->trouverUtilisateur($tenantId, $email);
        if ($user === null || ! password_verify($password, (string) $user['password_hash'])) {
            $this->logAuth($tenantId, null, $email, 'LOCAL', 'ECHEC_MDP', 'Identifiants locaux invalides.');
            return $this->echec('Identifiants invalides.');
        }
        return $this->finaliserConnexion($user, 'LOCAL', $options);
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    public function loginLdap(int $tenantId, string $email, string $password, array $options = []): array
    {
        try {
            $username = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
            $ldapUser = $this->ldapAuthService->authenticate($tenantId, $username, $password);
            if ($ldapUser === null) {
                return $this->echec('Identifiants LDAP invalides.');
            }
            $ldapEmail = strtolower(trim((string) ($ldapUser['email'] ?? '')));
            $emailForLookup = filter_var($ldapEmail, FILTER_VALIDATE_EMAIL) ? $ldapEmail : $email;
            $user = filter_var($emailForLookup, FILTER_VALIDATE_EMAIL)
                ? $this->trouverUtilisateur($tenantId, $emailForLookup)
                : null;
            if ($user === null && filter_var($ldapEmail, FILTER_VALIDATE_EMAIL)) {
                $matricule = trim((string) ($ldapUser['matricule'] ?? ''));
                $user = $this->lierLecteurEtauApresConnexion(
                    $tenantId,
                    $matricule !== '' ? $matricule : $username,
                    $ldapEmail,
                    $options
                );
            }
            if ($user === null) {
                $this->logAuth($tenantId, null, $email, 'LDAP', 'COMPTE_INACTIF', 'Compte LDAP non synchronisé ou inactif.');
                return $this->echec('Compte utilisateur indisponible.');
            }
            return $this->finaliserConnexion($user, 'LDAP', $options);
        } catch (Throwable $exception) {
            $this->logAuth($tenantId, null, $email, 'LDAP', 'ECHEC_LDAP', $exception->getMessage());
            return $this->echec('Service LDAP indisponible.');
        }
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    protected function loginHybride(int $tenantId, string $email, string $password, array $options = []): array
    {
        $ldap = $this->loginLdap($tenantId, $email, $password, $options);
        return $ldap['success'] ? $ldap : $this->loginLocal($tenantId, $email, $password, $options);
    }

    /**
     * Journalise une tentative sans jamais conserver le mot de passe.
     */
    public function logAuth(int $tenantId, ?int $userId, string $email, string $source, string $statut, ?string $message = null): void
    {
        Database::connect()->table('auth_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => mb_substr($email, 0, 150),
            'auth_source' => $source,
            'statut' => $statut,
            'message_erreur' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return ?array<string, mixed> */
    protected function trouverUtilisateur(int $tenantId, string $email): ?array
    {
        $user = $this->userModel->where('tenant_id', $tenantId)->where('email', $email)->first();
        return is_array($user) && (int) $user['is_active'] === 1 ? $user : null;
    }

    /**
     * Lie le premier login LDAP à l'unique compte lecteur ETau correspondant au matricule.
     *
     * @return ?array<string, mixed>
     */
    private function lierLecteurEtauApresConnexion(
        int $tenantId,
        string $matricule,
        string $email,
        array $options = []
    ): ?array
    {
        if ($tenantId !== 1 || $matricule === '' || mb_strlen($matricule) > 50) {
            return null;
        }

        $db = Database::connect();
        $matches = $db->query(
            'SELECT u.id AS user_id, e.id AS employe_id
             FROM users u
             INNER JOIN employes e ON e.id = u.employe_id AND e.tenant_id = u.tenant_id
             WHERE u.tenant_id = ? AND u.role = ? AND u.is_active = 1 AND u.email IS NULL
               AND u.deleted_at IS NULL AND e.deleted_at IS NULL
               AND e.source_systeme = ? AND e.matricule = ?
             LIMIT 2',
            [$tenantId, 'lecteur', 'etau', $matricule]
        )->getResultArray();
        if (count($matches) !== 1) {
            return null;
        }

        $userId = (int) $matches[0]['user_id'];
        $employeeId = (int) $matches[0]['employe_id'];
        $this->appliquerContexteAudit($userId, $tenantId, $options);
        if ($db->table('users')
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->countAllResults() > 0
        ) {
            return null;
        }

        $db->transBegin();
        try {
            $updatedUser = $db->table('users')
                ->where('id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('role', 'lecteur')
                ->where('email', null)
                ->where('is_active', 1)
                ->update(['email' => $email, 'auth_source' => 'LDAP', 'updated_at' => date('Y-m-d H:i:s')]);
            if (! $updatedUser || $db->affectedRows() !== 1) {
                $db->transRollback();
                return null;
            }

            if (! $db->table('employes')->where('id', $employeeId)->where('tenant_id', $tenantId)->update([
                'email' => $email,
                'updated_at' => date('Y-m-d H:i:s'),
            ]) || $db->transStatus() === false) {
                throw new \RuntimeException('Mise à jour du profil ETau après authentification refusée.');
            }

            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        return $this->userModel->where('tenant_id', $tenantId)->find($userId);
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    protected function finaliserConnexion(array $user, string $source, array $options = []): array
    {
        try {
            $this->appliquerContexteAudit((int) $user['id'], (int) $user['tenant_id'], $options);
            $jeton = $this->jwtTokenService->issueForUser($user);
            $this->userModel->update((int) $user['id'], ['derniere_connexion' => date('Y-m-d H:i:s')]);
            $this->logAuth((int) $user['tenant_id'], (int) $user['id'], (string) $user['email'], $source, 'SUCCESS');
            unset($user['password_hash']);
            return ['success' => true, 'user' => $user, 'token' => $jeton['access_token'], 'auth_source' => $source, 'message' => null];
        } catch (Throwable $exception) {
            log_message('error', 'Finalisation authentification utilisateur #{id} impossible : {error}', [
                'id' => $user['id'] ?? 0,
                'error' => $exception->getMessage(),
            ]);
            return $this->echec('Impossible de créer le jeton.');
        }
    }

    /** @param array<string, mixed> $options */
    private function appliquerContexteAudit(int $actorId, int $tenantId, array $options): void
    {
        $requestId = $options['request_id'] ?? null;
        if (! is_string($requestId) || preg_match('/^[a-f0-9]{32}$/i', $requestId) !== 1) {
            return;
        }

        AuditContext::apply(
            $actorId,
            $tenantId,
            'API',
            null,
            strtolower($requestId),
            isset($options['ip']) ? (string) $options['ip'] : null,
            isset($options['user_agent']) ? (string) $options['user_agent'] : null
        );
    }

    /** @return array{success:false,user:null,token:null,auth_source:null,message:string} */
    protected function echec(string $message): array
    {
        return ['success' => false, 'user' => null, 'token' => null, 'auth_source' => null, 'message' => $message];
    }
}
