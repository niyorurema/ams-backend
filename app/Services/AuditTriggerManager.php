<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class AuditTriggerManager
{
    private const AUDIT_TABLE = 'audit_logs';
    private const TRIGGER_PREFIX = 'ams_audit_';

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function install(): int
    {
        if (! AuditContext::usesMySql($this->db->DBDriver)) {
            throw new RuntimeException('Les déclencheurs d’audit nécessitent MySQL ou MariaDB.');
        }
        if (! $this->db->tableExists(self::AUDIT_TABLE)) {
            throw new RuntimeException('La table audit_logs doit être créée avant les déclencheurs.');
        }

        $this->dropManagedTriggers();
        $installed = 0;
        foreach ($this->db->listTables() as $table) {
            if (in_array($table, [self::AUDIT_TABLE, 'migrations'], true)) {
                continue;
            }

            $fields = $this->db->getFieldData($table);
            if ($fields === []) {
                continue;
            }
            $columns = array_map(static fn (object $field): string => (string) $field->name, $fields);
            $safeColumns = [];
            foreach ($fields as $field) {
                if (! self::isSensitiveColumn((string) $field->name)
                    && preg_match('/(?:blob|binary)/i', (string) ($field->type ?? '')) !== 1
                ) {
                    $safeColumns[] = (string) $field->name;
                }
            }
            $primaryKeys = $this->primaryKeys($table);
            $objectIdInsert = $this->objectIdExpression('NEW', $primaryKeys);
            $objectIdDelete = $this->objectIdExpression('OLD', $primaryKeys);
            $tenantInsert = $this->tenantExpression('NEW', $table, $columns);
            $tenantDelete = $this->tenantExpression('OLD', $table, $columns);
            $deviceInsert = $this->deviceExpression('NEW', $table, $columns);
            $deviceDelete = $this->deviceExpression('OLD', $table, $columns);
            $before = $this->jsonExpression('OLD', $safeColumns);
            $after = $this->jsonExpression('NEW', $safeColumns);
            $quotedTable = $this->quoteIdentifier($table);

            $triggers = [
                'INSERT' => [
                    $objectIdInsert,
                    $tenantInsert,
                    'NULL',
                    $after,
                    $deviceInsert,
                ],
                'UPDATE' => [
                    $objectIdInsert,
                    $tenantInsert,
                    $before,
                    $after,
                    $deviceInsert,
                ],
                'DELETE' => [
                    $objectIdDelete,
                    $tenantDelete,
                    $before,
                    'NULL',
                    $deviceDelete,
                ],
            ];

            foreach ($triggers as $event => [$objectId, $tenant, $beforeJson, $afterJson, $deviceId]) {
                $triggerName = $this->triggerName($table, $event);
                $sql = sprintf(
                    'CREATE TRIGGER %s AFTER %s ON %s FOR EACH ROW INSERT INTO %s '
                    . '(tenant_id, actor_id, action, object_type, object_id, occurred_at, before_values, after_values, '
                    . 'ip_address, user_agent, request_id, source, device_id) '
                    . 'VALUES (%s, @ams_audit_actor_id, %s, %s, %s, CURRENT_TIMESTAMP, %s, %s, '
                    . '@ams_audit_ip, @ams_audit_user_agent, @ams_audit_request_id, COALESCE(@ams_audit_source, \'SYSTEM\'), '
                    . '%s)',
                    $this->quoteIdentifier($triggerName),
                    $event,
                    $quotedTable,
                    $this->quoteIdentifier(self::AUDIT_TABLE),
                    $tenant,
                    $this->db->escape($event),
                    $this->db->escape($table),
                    $objectId,
                    $beforeJson,
                    $afterJson,
                    $deviceId
                );
                $this->db->query($sql);
                $installed++;
            }
        }

        return $installed;
    }

    public function dropManagedTriggers(): void
    {
        if (! AuditContext::usesMySql($this->db->DBDriver)) {
            return;
        }

        foreach ($this->db->query('SHOW TRIGGERS')->getResultArray() as $trigger) {
            $name = (string) ($trigger['Trigger'] ?? $trigger['trigger'] ?? '');
            if (str_starts_with($name, self::TRIGGER_PREFIX)) {
                $this->db->query('DROP TRIGGER IF EXISTS ' . $this->quoteIdentifier($name));
            }
        }
    }

    public static function isSensitiveColumn(string $column): bool
    {
        return preg_match(
            '/(?:password|passwd|secret|token|credential|private|api[_]?key|com[_]?key|bind|hash|fingerprint|empreinte|biometric|raw_data|contenu_json|options_json|setting_value|message_erreur|error_message|details|photo|certificate|(?:^|_)(?:pass|pwd|email|telephone|phone|matricule|nom(?:_complet)?|prenom|adresse|notes|message|erreur)(?:_|$))/i',
            $column
        ) === 1;
    }

    /** @param array<int, string> $columns */
    private function primaryKeys(string $table): array
    {
        $rows = $this->db->query('SHOW KEYS FROM ' . $this->quoteIdentifier($table) . " WHERE Key_name = 'PRIMARY'")
            ->getResultArray();
        usort($rows, static fn (array $left, array $right): int => (int) ($left['Seq_in_index'] ?? 0) <=> (int) ($right['Seq_in_index'] ?? 0));

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['Column_name'] ?? ''),
            $rows
        )));
    }

    /** @param array<int, string> $primaryKeys */
    private function objectIdExpression(string $row, array $primaryKeys): string
    {
        if ($primaryKeys === []) {
            return 'NULL';
        }
        if (count($primaryKeys) === 1) {
            return 'CAST(' . $row . '.' . $this->quoteIdentifier($primaryKeys[0]) . ' AS CHAR)';
        }

        $pairs = [];
        foreach ($primaryKeys as $key) {
            $pairs[] = $this->db->escape($key);
            $pairs[] = $row . '.' . $this->quoteIdentifier($key);
        }

        return 'CAST(JSON_OBJECT(' . implode(', ', $pairs) . ') AS CHAR)';
    }

    /** @param array<int, string> $columns */
    private function tenantExpression(string $row, string $table, array $columns): string
    {
        if (in_array('tenant_id', $columns, true)) {
            return 'COALESCE(' . $row . '.`tenant_id`, @ams_audit_tenant_id)';
        }
        if ($table === 'tenants' && in_array('id', $columns, true)) {
            return $row . '.`id`';
        }

        return '@ams_audit_tenant_id';
    }

    /** @param array<int, string> $columns */
    private function deviceExpression(string $row, string $table, array $columns): string
    {
        if (in_array('capteur_id', $columns, true)) {
            return $row . '.`capteur_id`';
        }
        if ($table === 'capteurs' && in_array('id', $columns, true)) {
            return $row . '.`id`';
        }

        return '@ams_audit_device_id';
    }

    /** @param array<int, string> $columns */
    private function jsonExpression(string $row, array $columns): string
    {
        if ($columns === []) {
            return "CAST('{}' AS JSON)";
        }

        $pairs = [];
        foreach ($columns as $column) {
            $pairs[] = $this->db->escape($column);
            $pairs[] = $row . '.' . $this->quoteIdentifier($column);
        }

        return 'JSON_OBJECT(' . implode(', ', $pairs) . ')';
    }

    private function triggerName(string $table, string $event): string
    {
        return self::TRIGGER_PREFIX . substr(hash('sha256', $table), 0, 12) . '_' . strtolower(substr($event, 0, 3));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
