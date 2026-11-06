<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

final class AbsenceErpSyncService
{
    private AbsenceImportService $imports;

    public function __construct(
        private readonly bool $enabled = false,
        ?BaseConnection $db = null
    ) {
        $this->imports = new AbsenceImportService($db);
    }

    /**
     * Ingests a page already fetched and mapped by a future ERP connector.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, int>
     */
    public function synchroniser(array $records, int $tenantId, int $actorId): array
    {
        if (! $this->enabled) {
            throw new DomainException('La synchronisation directe ERP est désactivée.');
        }

        return $this->imports->importerEnregistrementsApi($records, $tenantId, $actorId);
    }
}
