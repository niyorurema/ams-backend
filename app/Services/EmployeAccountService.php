<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Crée de façon idempotente le compte lecteur lié à un employé.
 */
final class EmployeAccountService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function syncEmployeeStatus(int $employeeId, int $tenantId, int $active): bool
    {
        return $this->db->table('users')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeeId)
            ->update([
                'is_active' => $active === 1 ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @param array<string, mixed> $employe
     */
    public function ensureReaderAccount(array $employe, int $tenantId): bool
    {
        $employeeId = filter_var($employe['id'] ?? null, FILTER_VALIDATE_INT);
        if ($employeeId === false || $employeeId < 1 || (int) ($employe['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('Employé invalide pour la création de son compte.');
        }

        $existing = $this->db->table('users')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeeId)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            $email = strtolower(trim((string) ($employe['email'] ?? '')));
            if (($existing['email'] ?? null) === null
                && $email !== ''
                && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                && mb_strlen($email) <= 150
                && $this->db->table('users')->where('tenant_id', $tenantId)->where('email', $email)->countAllResults() === 0
            ) {
                $updated = $this->db->table('users')
                    ->where('id', (int) $existing['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('email', null)
                    ->update(['email' => $email, 'updated_at' => date('Y-m-d H:i:s')]);
                if (! $updated || $this->db->affectedRows() !== 1) {
                    throw new RuntimeException('Impossible de renseigner l’email du compte lecteur existant.');
                }
            }
            return false;
        }

        $email = strtolower(trim((string) ($employe['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150) {
            $email = null;
        } elseif ($this->db->table('users')->where('tenant_id', $tenantId)->where('email', $email)->countAllResults() > 0) {
            log_message('warning', 'Compte lecteur créé sans email : adresse déjà associée à un autre compte du tenant #{tenant}.', [
                'tenant' => $tenantId,
            ]);
            $email = null;
        }

        $tenant = $this->db->table('tenants')
            ->select('auth_mode')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            throw new RuntimeException('Tenant introuvable pour la création du compte employé.');
        }

        $authMode = strtoupper((string) ($tenant['auth_mode'] ?? 'LOCAL'));
        $name = trim((string) ($employe['nom'] ?? '') . ' ' . (string) ($employe['prenom'] ?? ''));
        $inserted = $this->db->table('users')->insert([
            'tenant_id' => $tenantId,
            'employe_id' => (int) $employeeId,
            'email' => $email,
            'password_hash' => null,
            'nom_complet' => mb_substr($name, 0, 150) ?: null,
            'telephone' => mb_substr(trim((string) ($employe['telephone'] ?? '')), 0, 30) ?: null,
            'role' => 'lecteur',
            'is_super_admin' => 0,
            'is_active' => (int) ($employe['actif'] ?? 1) === 1 ? 1 : 0,
            'auth_source' => in_array($authMode, ['LDAP', 'HYBRIDE'], true) ? 'LDAP' : 'LOCAL',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if (! $inserted) {
            throw new RuntimeException('Impossible de créer le compte lecteur de l’employé.');
        }

        return true;
    }
}
