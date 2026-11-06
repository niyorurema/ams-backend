<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;

final class AbsenceImportService
{
    private const MAX_FILE_BYTES = 10_485_760;
    private const MAX_ROWS = 10_000;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array<string, mixed> */
    public function preview(UploadedFile $file, int $tenantId): array
    {
        $parsed = $this->parse($file, $tenantId);
        $statuts = [];
        $joursCompletsFuturs = 0;
        $demiJournees = 0;
        $periodesMultiJours = 0;
        $annulationsExplicites = 0;
        $aujourdhui = (new TenantTimezoneService($this->db))->now($tenantId)->format('Y-m-d');
        foreach ($parsed['rows'] as $row) {
            $statuts[$row['source_status_label']] = ($statuts[$row['source_status_label']] ?? 0) + 1;
            if ($row['statut'] === 'APPROVED' && $row['date_fin'] >= $aujourdhui) {
                if ($row['journee_complete'] === 1) {
                    $debutFutur = max($aujourdhui, $row['date_debut']);
                    $joursCompletsFuturs += (new DateTimeImmutable($debutFutur))
                        ->diff(new DateTimeImmutable($row['date_fin']))->days + 1;
                }
            }
            if ($row['journee_complete'] === 0) {
                $demiJournees++;
            }
            if ($row['date_debut'] !== $row['date_fin']) {
                $periodesMultiJours++;
            }
            if ($row['statut'] === 'CANCELLED') {
                $annulationsExplicites++;
            }
        }

        return [
            'total_lignes' => $parsed['source_rows'],
            'lignes_valides' => count($parsed['rows']),
            'lignes_en_erreur' => count($parsed['issues']),
            'statuts_source' => $statuts,
            'jours_conge_futurs_confirmes' => $joursCompletsFuturs,
            'periodes_multi_jours' => $periodesMultiJours,
            'demi_journees' => $demiJournees,
            'annulations_explicites' => $annulationsExplicites,
            'avertissements' => array_slice($parsed['issues'], 0, 100),
            'avertissements_tronques' => count($parsed['issues']) > 100,
            'import_partiel_requis' => $parsed['issues'] !== [],
        ];
    }

    /** @return array<string, int> */
    public function importer(UploadedFile $file, int $tenantId, int $actorId, bool $ignorerErreurs): array
    {
        $parsed = $this->parse($file, $tenantId);
        if ($parsed['issues'] !== [] && ! $ignorerErreurs) {
            throw new \InvalidArgumentException('Confirmez l’import des lignes valides en excluant les lignes en erreur.');
        }
        if ($parsed['rows'] === []) {
            throw new \InvalidArgumentException('Aucune ligne valide à importer.');
        }

        return $this->enregistrerLignes(
            $parsed['rows'],
            $tenantId,
            $actorId,
            $parsed['source_rows'],
            count($parsed['issues']),
            [
                'file_name' => mb_substr(basename($file->getClientName()), 0, 255),
                'file_sha256' => hash_file('sha256', $file->getTempName()),
            ]
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, int>
     */
    public function importerEnregistrementsApi(array $records, int $tenantId, int $actorId): array
    {
        if ($records === [] || count($records) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Le lot ERP doit contenir de 1 à 10 000 demandes.');
        }

        $employees = $this->indexerEmployes($tenantId);
        $statusMappingService = new AbsenceStatusMappingService($this->db);
        $statusMappings = $statusMappingService->getResolutionMap($tenantId);
        $seen = [];
        $rows = [];
        $issues = [];
        foreach ($records as $index => $record) {
            $requestId = trim((string) ($record['request_id'] ?? ''));
            $employeeNumber = trim((string) ($record['employee_number'] ?? ''));
            $statusCode = trim((string) ($record['status_code'] ?? ''));
            $statusLabel = trim((string) ($record['status_label'] ?? ''));
            $typeCode = trim((string) ($record['type_code'] ?? ''));
            $typeLabel = trim((string) ($record['type_label'] ?? ''));
            $dayType = trim((string) ($record['day_type'] ?? ''));
            $dayPart = strtoupper(trim((string) ($record['day_part'] ?? '')));
            $startDate = $this->dateApi($record['start_date'] ?? null);
            $endDate = $this->dateApi($record['end_date'] ?? null);
            $duration = $this->nombre($record['duration_hours'] ?? null);
            $startTime = $this->heureApi($record['time_start'] ?? null);
            $endTime = $this->heureApi($record['time_end'] ?? null);
            $status = $statusMappingService->resolveFromMap($statusMappings, $statusCode, $statusLabel);
            $reason = null;
            $matches = $employees[$employeeNumber] ?? [];

            if ($requestId === '' || strlen($requestId) > 100) {
                $reason = 'request_id manquant ou invalide.';
            } elseif (count($matches) !== 1) {
                $reason = count($matches) === 0
                    ? 'employee_number inconnu dans ce tenant.'
                    : 'employee_number correspond à plusieurs employés.';
            } elseif ($startDate === null || $endDate === null || $startDate > $endDate
                || (new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days > 365
            ) {
                $reason = 'Période start_date/end_date invalide ou supérieure à 366 jours.';
            } elseif (! in_array($dayPart, ['F', 'H'], true)) {
                $reason = 'day_part doit valoir F ou H.';
            } elseif ($dayPart === 'H' && ($startDate !== $endDate
                || $startTime === null || $endTime === null || $endTime <= $startTime)
            ) {
                $reason = 'Une demi-journée doit couvrir une date et contenir une plage horaire valide.';
            } elseif ($status === null) {
                $reason = 'status_code et status_label ne correspondent pas à un statut ERP OBR reconnu.';
            } elseif ($typeCode === '' || $typeLabel === '' || $dayType === '') {
                $reason = 'type_code, type_label et day_type sont obligatoires.';
            } elseif ($duration === null || $duration <= 0
                || $duration > (($dayPart === 'F'
                    ? (new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days + 1
                    : 1) * 24)
            ) {
                $reason = 'duration_hours invalide.';
            } elseif (! $this->dateHeureApiValide($record['source_updated_at'] ?? null)) {
                $reason = 'source_updated_at doit être une date/heure ISO 8601 avec fuseau.';
            }

            $duplicateKey = $requestId . ':' . ($startDate ?? 'invalid');
            if ($reason === null && isset($seen[$duplicateKey])) {
                $reason = 'Demande dupliquée pour request_id et start_date.';
            }
            if ($reason !== null) {
                $issues[] = sprintf('Enregistrement %d : %s', $index + 1, $reason);
                continue;
            }

            $seen[$duplicateKey] = true;
            $rows[] = [
                'employe_id' => $matches[0],
                'source_request_id' => $requestId,
                'source_status_code' => $statusCode,
                'source_status_label' => mb_substr($statusLabel, 0, 100),
                'source_type_code' => mb_substr($typeCode, 0, 30),
                'source_day_type' => mb_substr($dayType, 0, 10),
                'categorie' => mb_substr($typeLabel, 0, 100),
                'date_debut' => $startDate,
                'date_fin' => $endDate,
                'journee_complete' => $dayPart === 'F' ? 1 : 0,
                'heure_debut' => $dayPart === 'H' ? $startTime : null,
                'heure_fin' => $dayPart === 'H' ? $endTime : null,
                'duree_heures' => $dayPart === 'H'
                    ? round((strtotime($endTime) - strtotime($startTime)) / 3600, 2)
                    : round($duration, 2),
                'statut' => $status,
            ];
        }
        if ($issues !== []) {
            throw new \InvalidArgumentException(implode(' ', array_slice($issues, 0, 10)));
        }

        return $this->enregistrerLignes($rows, $tenantId, $actorId, count($records), 0);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{file_name:string,file_sha256:string}|null $file
     * @return array<string, int>
     */
    private function enregistrerLignes(
        array $rows,
        int $tenantId,
        int $actorId,
        int $sourceRows,
        int $skippedRows,
        ?array $file = null
    ): array {
        $now = date('Y-m-d H:i:s');
        $affectedDays = [];
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        $this->db->transBegin();
        try {
            $batchId = null;
            if ($file !== null) {
                $this->db->table('absence_import_batches')->insert([
                    'tenant_id' => $tenantId,
                    'uploaded_by' => $actorId,
                    'file_name' => $file['file_name'],
                    'file_sha256' => $file['file_sha256'],
                    'source_rows' => $sourceRows,
                    'imported_rows' => 0,
                    'skipped_rows' => $skippedRows,
                    'created_at' => $now,
                ]);
                $batchId = (int) $this->db->insertID();
                if ($batchId < 1) {
                    throw new \RuntimeException('Impossible d’enregistrer le lot d’import.');
                }
            }

            foreach ($rows as $row) {
                $existing = $this->db->table('absences')
                    ->where('tenant_id', $tenantId)
                    ->where('source', 'OBR')
                    ->where('source_request_id', $row['source_request_id'])
                    ->where('date_debut', $row['date_debut'])
                    ->get()
                    ->getRowArray();

                $values = [
                    'tenant_id' => $tenantId,
                    'employe_id' => $row['employe_id'],
                    'source' => 'OBR',
                    'source_request_id' => $row['source_request_id'],
                    'source_status_code' => $row['source_status_code'],
                    'source_status_label' => $row['source_status_label'],
                    'source_type_code' => $row['source_type_code'],
                    'source_day_type' => $row['source_day_type'],
                    'categorie' => $row['categorie'],
                    'motif' => null,
                    'date_debut' => $row['date_debut'],
                    'date_fin' => $row['date_fin'],
                    'journee_complete' => $row['journee_complete'],
                    'heure_debut' => $row['heure_debut'],
                    'heure_fin' => $row['heure_fin'],
                    'duree_heures' => $row['duree_heures'],
                    'statut' => $row['statut'],
                    'import_batch_id' => $batchId,
                    'updated_at' => $now,
                ];

                if ($existing === null) {
                    $values['created_by'] = $actorId;
                    $values['created_at'] = $now;
                    $this->db->table('absences')->insert($values);
                    $absenceId = (int) $this->db->insertID();
                    if ($absenceId < 1) {
                        throw new \RuntimeException('Impossible d’enregistrer une journée d’absence importée.');
                    }
                    $this->history($tenantId, $absenceId, $actorId, 'OBR_IMPORTED', null, $row['statut']);
                    $inserted++;
                    if ($row['statut'] === 'APPROVED') {
                        $this->ajouterJoursAffectes(
                            $affectedDays,
                            (int) $row['employe_id'],
                            (string) $row['date_debut'],
                            (string) $row['date_fin']
                        );
                    }
                    continue;
                }

                $changed = $this->changed($existing, $values);
                if (! $changed) {
                    $unchanged++;
                    continue;
                }

                $oldStatus = (string) $existing['statut'];
                if ($batchId === null) {
                    unset($values['import_batch_id']);
                }
                $this->db->table('absences')
                    ->where('id', (int) $existing['id'])
                    ->where('tenant_id', $tenantId)
                    ->update($values);
                $this->history(
                    $tenantId,
                    (int) $existing['id'],
                    $actorId,
                    'OBR_SYNCED',
                    $oldStatus,
                    $row['statut']
                );
                $updated++;
                if ($oldStatus === 'APPROVED' || $row['statut'] === 'APPROVED') {
                    $this->ajouterJoursAffectes(
                        $affectedDays,
                        (int) $existing['employe_id'],
                        (string) $existing['date_debut'],
                        (string) $existing['date_fin']
                    );
                    $this->ajouterJoursAffectes(
                        $affectedDays,
                        (int) $row['employe_id'],
                        (string) $row['date_debut'],
                        (string) $row['date_fin']
                    );
                }
            }

            if ($batchId !== null) {
                $this->db->table('absence_import_batches')
                    ->where('id', $batchId)
                    ->where('tenant_id', $tenantId)
                    ->update([
                        'imported_rows' => $inserted + $updated,
                        'skipped_rows' => $skippedRows + $unchanged,
                    ]);
            }
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Échec de l’enregistrement de l’import.');
            }
            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        (new AbsenceManagementService($this->db))->recalculerJours($tenantId, $affectedDays);

        return [
            'importe' => $inserted + $updated,
            'ajoute' => $inserted,
            'mis_a_jour' => $updated,
            'sans_changement' => $unchanged,
            'ignorees' => $skippedRows,
            'annulations_explicites' => count(array_filter(
                $rows,
                static fn (array $row): bool => $row['statut'] === 'CANCELLED'
            )),
        ];
    }

    /**
     * @return array{source_rows:int,rows:array<int,array<string,mixed>>,issues:array<int,array<string,mixed>>}
     */
    private function parse(UploadedFile $file, int $tenantId): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new \InvalidArgumentException('Le fichier transmis est invalide.');
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Le fichier dépasse la limite de 10 Mo.');
        }

        $path = $file->getTempName();
        try {
            $format = IOFactory::identify($path);
            if (! in_array($format, ['Xls', 'Xlsx', 'Xml', 'Csv'], true)) {
                throw new \InvalidArgumentException('Format de fichier non pris en charge.');
            }
            $reader = IOFactory::createReader($format);
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
            $sheet = $workbook->getActiveSheet();
            if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1
                || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 20
            ) {
                throw new \InvalidArgumentException('Le classeur dépasse les limites de lignes ou de colonnes.');
            }
            $matrix = $sheet->toArray(null, false, false, false);
            $workbook->disconnectWorksheets();
            unset($workbook);
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            log_message('warning', 'Lecture du fichier de congés impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);
            throw new \InvalidArgumentException('Le fichier ne peut pas être lu comme un classeur de congés.');
        }

        if ($matrix === []) {
            throw new \InvalidArgumentException('Le classeur est vide.');
        }
        $headers = array_map(static fn (mixed $value): string => trim((string) $value), $matrix[0]);
        $indices = $this->indicesColonnes($headers);
        $employes = $this->indexerEmployes($tenantId);
        $statusMappingService = new AbsenceStatusMappingService($this->db);
        $statusMappings = $statusMappingService->getResolutionMap($tenantId);
        $seen = [];
        $rows = [];
        $issues = [];
        $sourceRows = 0;

        foreach (array_slice($matrix, 1, self::MAX_ROWS, true) as $index => $line) {
            if ($this->ligneVide($line)) {
                continue;
            }
            $sourceRows++;
            $excelRow = $index + 1;
            $requestId = $this->identifiant($line[$indices['request']] ?? null);
            $employeeNumber = $this->identifiant($line[$indices['employee']] ?? null);
            $matchedIds = $employes[$employeeNumber] ?? [];
            $dateDebut = $this->dateExcel($line[$indices['start']] ?? null);
            $dateFin = $this->dateExcel($line[$indices['end']] ?? null);
            $statusCode = $this->identifiant($line[$indices['status_code']] ?? null);
            $statusLabel = trim((string) ($line[$indices['status_label']] ?? ''));
            $typeCode = trim((string) ($line[$indices['type_code']] ?? ''));
            $dayType = trim((string) ($line[$indices['day_type']] ?? ''));
            $category = trim((string) ($line[$indices['type_label']] ?? ''));
            $dayPart = strtoupper(trim((string) ($line[$indices['day_part']] ?? '')));
            $duration = $this->nombre($line[$indices['duration']] ?? null);
            $status = $statusMappingService->resolveFromMap($statusMappings, $statusCode, $statusLabel);
            $heureDebut = $this->heureExcel(
                $indices['time_start'] >= 0 ? ($line[$indices['time_start']] ?? null) : null
            );
            $heureFin = $this->heureExcel(
                $indices['time_end'] >= 0 ? ($line[$indices['time_end']] ?? null) : null
            );
            $dureeMax = $dateDebut !== null && $dateFin !== null && $dateDebut <= $dateFin
                ? (($dayPart === 'F'
                    ? (new DateTimeImmutable($dateDebut))->diff(new DateTimeImmutable($dateFin))->days + 1
                    : 1) * 24)
                : 24;
            $reason = null;

            if ($requestId === '' || $requestId === '0') {
                $reason = 'Référence de demande manquante ou invalide.';
            } elseif (count($matchedIds) !== 1) {
                $reason = count($matchedIds) === 0
                    ? 'Numéro d’employé inconnu dans ce tenant.'
                    : 'Le numéro d’employé correspond à plusieurs employés.';
            } elseif ($dateDebut === null || $dateFin === null) {
                $reason = 'Date de congé invalide ou hors de la plage 2000–2100.';
            } elseif ($dateDebut > $dateFin || (new DateTimeImmutable($dateDebut))->diff(new DateTimeImmutable($dateFin))->days > 365) {
                $reason = 'La période de congé est invalide ou dépasse 366 jours.';
            } elseif (! in_array($dayPart, ['F', 'H'], true)) {
                $reason = 'Type de journée OBR inconnu; seul F (journée complète) ou H (demi-journée) est pris en charge.';
            } elseif ($dayPart === 'H' && $dateDebut !== $dateFin) {
                $reason = 'Une demi-journée OBR doit correspondre à une seule date.';
            } elseif ($dayPart === 'H' && ($heureDebut === null || $heureFin === null || $heureFin <= $heureDebut)) {
                $reason = 'Les heures de début et de fin de la demi-journée OBR sont absentes ou invalides.';
            } elseif ($status === null) {
                $reason = 'Statut source inconnu ou incohérent avec son code; configurez son mapping dans les paramètres des absences.';
            } elseif ($category === '') {
                $reason = 'Type de congé manquant.';
            } elseif ($duration === null || $duration <= 0 || $duration > $dureeMax) {
                $reason = 'Durée de congé invalide.';
            }

            $key = $requestId . ':' . ($dateDebut ?? 'invalid');
            if ($reason === null && isset($seen[$key])) {
                $reason = 'Cette demande contient plusieurs lignes pour la même journée.';
            }
            if ($reason !== null) {
                $issues[] = ['ligne' => $excelRow, 'raison' => $reason];
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'employe_id' => $matchedIds[0],
                'source_request_id' => $requestId,
                'source_status_code' => $statusCode,
                'source_status_label' => mb_substr($statusLabel, 0, 100),
                'source_type_code' => mb_substr($typeCode, 0, 30),
                'source_day_type' => mb_substr($dayType, 0, 10),
                'categorie' => mb_substr($category, 0, 100),
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'journee_complete' => $dayPart === 'F' ? 1 : 0,
                'heure_debut' => $dayPart === 'H' ? $heureDebut : null,
                'heure_fin' => $dayPart === 'H' ? $heureFin : null,
                'duree_heures' => $dayPart === 'H'
                    ? round((strtotime($heureFin) - strtotime($heureDebut)) / 3600, 2)
                    : round($duration, 2),
                'statut' => $status,
            ];
        }

        return ['source_rows' => $sourceRows, 'rows' => $rows, 'issues' => $issues];
    }

    /** @return array<string, int> */
    private function indicesColonnes(array $headers): array
    {
        $findAll = static function (string $name) use ($headers): array {
            $indices = [];
            foreach ($headers as $index => $header) {
                if (mb_strtolower($header) === mb_strtolower($name)) {
                    $indices[] = $index;
                }
            }
            return $indices;
        };
        $status = $findAll('Leave Status');
        $types = $findAll('Leave Type');
        $required = [
            'request' => 'Leave Request Number',
            'employee' => 'Employee Number',
            'start' => 'Leave Begin Date',
            'end' => 'Leave End Date',
            'duration' => 'Leave Duration',
            'day_part' => 'Half / Full Day',
            'day_type' => 'Day Type',
        ];
        $indices = [];
        foreach ($required as $key => $header) {
            $matches = $findAll($header);
            if (count($matches) !== 1) {
                throw new \InvalidArgumentException('Le classeur ne contient pas les colonnes attendues.');
            }
            $indices[$key] = $matches[0];
        }
        if (count($status) < 2 || count($types) < 2) {
            throw new \InvalidArgumentException('Les colonnes de statut et de type OBR sont incomplètes.');
        }
        $indices['status_code'] = $status[0];
        $indices['status_label'] = $status[1];
        $indices['type_code'] = $types[0];
        $indices['type_label'] = $types[1];
        $timeStart = $findAll('Time In');
        $timeEnd = $findAll('Time Out');
        if (count($timeStart) > 1 || count($timeEnd) > 1) {
            throw new \InvalidArgumentException('Les colonnes d’heures OBR sont ambiguës.');
        }
        $indices['time_start'] = $timeStart[0] ?? -1;
        $indices['time_end'] = $timeEnd[0] ?? -1;

        return $indices;
    }

    private function heureExcel(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        if (is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', trim($value)) === 1) {
            return strlen(trim($value)) === 5 ? trim($value) . ':00' : trim($value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if ($number >= 0 && $number < 1) {
            return ExcelDate::excelToDateTimeObject($number)->format('H:i:s');
        }
        if (! is_finite($number) || $number < 0 || floor($number) !== $number || $number > 235959) {
            return null;
        }
        $digits = str_pad((string) (int) $number, 6, '0', STR_PAD_LEFT);
        $hour = (int) substr($digits, 0, 2);
        $minute = (int) substr($digits, 2, 2);
        $second = (int) substr($digits, 4, 2);
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /** @param array<int, array{employe_id:int,date:string}> $affectedDays */
    private function ajouterJoursAffectes(array &$affectedDays, int $employeId, string $debut, string $fin): void
    {
        $date = new DateTimeImmutable($debut);
        $dateFin = new DateTimeImmutable($fin);
        while ($date <= $dateFin) {
            $affectedDays[] = ['employe_id' => $employeId, 'date' => $date->format('Y-m-d')];
            $date = $date->modify('+1 day');
        }
    }

    /** @return array<string, array<int, int>> */
    private function indexerEmployes(int $tenantId): array
    {
        $index = [];
        $employees = $this->db->table('employes')
            ->select('id, matricule')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        foreach ($employees as $employee) {
            $number = trim((string) $employee['matricule']);
            if ($number !== '') {
                $index[$number][] = (int) $employee['id'];
            }
        }
        return $index;
    }

    private function dateExcel(mixed $value): ?string
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($value);
            } elseif (is_numeric($value)) {
                if ((float) $value < 1 || (float) $value > 100_000) {
                    return null;
                }
                $date = ExcelDate::excelToDateTimeObject((float) $value);
            } elseif (is_string($value) && trim($value) !== '') {
                $date = new DateTimeImmutable(trim($value));
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $year = (int) $date->format('Y');
        if ($year < 2000 || $year > 2100) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    private function dateApi(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
            || (int) $date->format('Y') < 2000
            || (int) $date->format('Y') > 2100
        ) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function heureApi(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) !== 1
        ) {
            return null;
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private function dateHeureApiValide(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1
        ) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            $parsed = date_parse($value);
            return $parsed['warning_count'] === 0 && $parsed['error_count'] === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function identifiant(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }
        return is_string($value) ? trim($value) : '';
    }

    private function nombre(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private function ligneVide(array $line): bool
    {
        foreach ($line as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $existing
     *  @param array<string, mixed> $newValues
     */
    private function changed(array $existing, array $newValues): bool
    {
        foreach ([
            'employe_id',
            'source_status_code',
            'source_status_label',
            'source_type_code',
            'source_day_type',
            'categorie',
            'date_fin',
            'journee_complete',
            'heure_debut',
            'heure_fin',
            'duree_heures',
            'statut',
        ] as $field) {
            if ($field === 'duree_heures'
                && is_numeric($existing[$field] ?? null)
                && is_numeric($newValues[$field] ?? null)
                && abs((float) $existing[$field] - (float) $newValues[$field]) < 0.005
            ) {
                continue;
            }
            if ((string) ($existing[$field] ?? '') !== (string) ($newValues[$field] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function history(
        int $tenantId,
        int $absenceId,
        int $actorId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus
    ): void {
        (new AbsenceManagementService($this->db))->enregistrerHistorique(
            $tenantId,
            $absenceId,
            $actorId,
            $action,
            $oldStatus,
            $newStatus
        );
    }
}
