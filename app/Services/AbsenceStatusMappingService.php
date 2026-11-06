<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use OutOfBoundsException;
use RuntimeException;

final class AbsenceStatusMappingService
{
    private const DEFAULTS = [
        ['code' => '50', 'label' => 'En attente', 'status' => 'PENDING'],
        ['code' => '55', 'label' => 'Congé refusé', 'status' => 'REJECTED'],
        ['code' => '58', 'label' => 'Congé approuvé par N1', 'status' => 'PENDING'],
        ['code' => '59', 'label' => 'Congé approuvé par N2', 'status' => 'PENDING'],
        ['code' => '60', 'label' => 'Approbation finale', 'status' => 'APPROVED'],
        ['code' => '72', 'label' => 'Deletion Approved by L2', 'status' => 'CANCELLED'],
        ['code' => '77', 'label' => 'Final Deletion', 'status' => 'CANCELLED'],
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return list<array{id:int|null,source_code:string,source_label:string,absence_status:string,is_default:bool}> */
    public function getMappings(int $tenantId): array
    {
        $rows = $this->db->table('absence_status_mappings')
            ->where('tenant_id', $tenantId)
            ->orderBy('source_code', 'ASC')
            ->orderBy('source_label', 'ASC')
            ->get()
            ->getResultArray();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->key((string) $row['source_code'], (string) $row['source_label'])] = $row;
        }

        $result = [];
        foreach (self::DEFAULTS as $default) {
            $key = $this->key($default['code'], $default['label']);
            $row = $byKey[$key] ?? null;
            $result[] = [
                'id' => $row === null ? null : (int) $row['id'],
                'source_code' => $default['code'],
                'source_label' => $default['label'],
                'absence_status' => (string) ($row['absence_status'] ?? $default['status']),
                'is_default' => true,
            ];
            unset($byKey[$key]);
        }
        foreach ($byKey as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'source_code' => (string) $row['source_code'],
                'source_label' => (string) $row['source_label'],
                'absence_status' => (string) $row['absence_status'],
                'is_default' => false,
            ];
        }

        return $result;
    }

    public function resolve(int $tenantId, string $sourceCode, string $sourceLabel): ?string
    {
        return $this->resolveFromMap($this->getResolutionMap($tenantId), $sourceCode, $sourceLabel);
    }

    /** @return array<string, string> */
    public function getResolutionMap(int $tenantId): array
    {
        $map = [];
        foreach (self::DEFAULTS as $default) {
            $map[$this->key($default['code'], $default['label'])] = $default['status'];
        }
        $rows = $this->db->table('absence_status_mappings')
            ->select('source_code, source_label, absence_status')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getResultArray();
        foreach ($rows as $row) {
            $map[$this->key((string) $row['source_code'], (string) $row['source_label'])] =
                (string) $row['absence_status'];
        }

        return $map;
    }

    /** @param array<string, string> $map */
    public function resolveFromMap(array $map, string $sourceCode, string $sourceLabel): ?string
    {
        $code = trim($sourceCode);
        $label = trim($sourceLabel);
        $key = $this->key($code, $label);
        if (isset($map[$key])) {
            return $map[$key];
        }
        foreach (self::DEFAULTS as $default) {
            if ($default['code'] === $code && mb_strtolower($default['label']) === mb_strtolower($label)) {
                return $default['status'];
            }
        }

        return null;
    }

    public function save(
        int $tenantId,
        string $sourceCode,
        string $sourceLabel,
        string $absenceStatus,
        int $actorId
    ): int {
        $sourceCode = trim($sourceCode);
        $sourceLabel = trim($sourceLabel);
        $absenceStatus = strtoupper(trim($absenceStatus));
        if ($sourceCode === '' || mb_strlen($sourceCode) > 30
            || $sourceLabel === '' || mb_strlen($sourceLabel) > 100
            || ! in_array($absenceStatus, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)
        ) {
            throw new \InvalidArgumentException('Le code, le libellé et le statut AMS du mapping sont obligatoires et doivent être valides.');
        }

        $existing = $this->db->table('absence_status_mappings')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('source_code', $sourceCode)
            ->where('source_label', $sourceLabel)
            ->get()
            ->getRowArray();
        $values = [
            'absence_status' => $absenceStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing === null) {
            $inserted = $this->db->table('absence_status_mappings')->insert([
                'tenant_id' => $tenantId,
                'source_code' => $sourceCode,
                'source_label' => $sourceLabel,
                'absence_status' => $absenceStatus,
                'created_by' => $actorId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if ($inserted === false) {
                throw new RuntimeException('Impossible d’enregistrer le mapping de statut ERP.');
            }

            return (int) $this->db->insertID();
        }

        $updated = $this->db->table('absence_status_mappings')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $existing['id'])
            ->update($values);
        if ($updated === false) {
            throw new RuntimeException('Impossible de modifier le mapping de statut ERP.');
        }

        return (int) $existing['id'];
    }

    public function delete(int $tenantId, int $mappingId): void
    {
        $mapping = $this->db->table('absence_status_mappings')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('id', $mappingId)
            ->get()
            ->getRowArray();
        if ($mapping === null) {
            throw new OutOfBoundsException('Mapping de statut introuvable.');
        }
        if ($this->db->table('absence_status_mappings')
            ->where('tenant_id', $tenantId)
            ->where('id', $mappingId)
            ->delete() === false
        ) {
            throw new RuntimeException('Impossible de supprimer le mapping de statut ERP.');
        }
    }

    private function key(string $code, string $label): string
    {
        return $code . "\0" . trim($label);
    }
}
