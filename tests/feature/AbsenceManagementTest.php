<?php

namespace Tests\Feature;

use App\Services\AbsenceImportService;
use App\Services\AbsenceErpContract;
use App\Services\AbsenceErpSyncService;
use App\Services\AbsenceManagementService;
use App\Services\AbsenceStatusMappingService;
use App\Services\CalculPresenceService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class AbsenceManagementTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testApprovedFullDayAbsenceCreatesAnAuthorizedProjection(): void
    {
        $date = (new DateTimeImmutable('now', new \DateTimeZone('Africa/Bujumbura')))->format('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $this->attendanceDb->table('absences')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'source' => 'OBR',
            'source_request_id' => 'OBR-TEST-1',
            'source_status_code' => '60',
            'source_status_label' => 'Approbation finale',
            'source_type_code' => 'AL',
            'source_day_type' => 'W',
            'categorie' => 'Congé annuel',
            'date_debut' => $date,
            'date_fin' => $date,
            'journee_complete' => 1,
            'duree_heures' => 8,
            'statut' => 'APPROVED',
            'created_at' => $now,
        ]);

        $service = new CalculPresenceService(null, $this->attendanceDb);
        $resultat = $service->interpreterJournee($this->employeId, $date);

        $this->assertSame('CONGE', $resultat['statut']);
        $this->assertSame('ABSENCE', $resultat['source_calcul']);
        $this->assertSame('ABSENCE_AUTORISEE', $resultat['details']['evaluation']);
        $this->assertSame('OBR', $resultat['details']['absence_source']);

        $projectionId = $service->recalculerJournee($this->employeId, $date);
        $projection = $this->attendanceDb->table('pointages_journee')->where('id', $projectionId)->get()->getRowArray();
        $this->assertSame('ABSENCE', $projection['source_calcul']);
        $this->assertSame('FERIE', $projection['statut_global']);
    }

    public function testImportPreviewIsolatesInvalidDateWithoutExposingEmployeeDetails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ams-absence-');
        if ($path === false) {
            $this->fail('Impossible de créer un fichier temporaire.');
        }
        $this->fichiersTemporaires[] = $path;

        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray([
            'Leave Request Number', 'Employee Number', 'Employee Name', 'Soc. Princ.', 'Co',
            'Day', 'Day Type', 'Half / Full Day', 'Leave Duration', 'Leave Begin Date',
            'Leave End Date', 'Leave Status', 'Leave Status', 'Leave Type', 'Leave Type',
            'Time In', 'Time Out', 'N° Ligne', 'Supérieur', 'Supervisor',
        ], null, 'A1');
        $validDay = SpreadsheetDate::PHPToExcel(new DateTimeImmutable('2026-11-06'));
        $invalidDay = SpreadsheetDate::PHPToExcel(new DateTimeImmutable('2206-04-11'));
        $base = [
            1001, $this->matriculeEmploye(), 'Identité confidentielle', '', '', 5, 'W', 'F', 8,
            $validDay, $validDay, 60, 'Approbation finale', 'AL', 'Congé Annuel',
            73000, 173000, 1, '', '',
        ];
        $invalid = $base;
        $invalid[0] = 1002;
        $invalid[9] = $invalidDay;
        $invalid[10] = $invalidDay;
        $sheet->fromArray($base, null, 'A2');
        $sheet->fromArray($invalid, null, 'A3');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        $file = new class($path, 'test-leaves.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return is_file($this->getTempName());
            }
        };
        $preview = (new AbsenceImportService($this->attendanceDb))->preview($file, $this->tenantId);

        $this->assertSame(2, $preview['total_lignes']);
        $this->assertSame(1, $preview['lignes_valides']);
        $this->assertSame(1, $preview['lignes_en_erreur']);
        $this->assertSame(3, $preview['avertissements'][0]['ligne']);
        $this->assertStringContainsString('Date de congé invalide', $preview['avertissements'][0]['raison']);
        $this->assertStringNotContainsString('Identité confidentielle', json_encode($preview, JSON_THROW_ON_ERROR));

        unlink($path);
        $this->fichiersTemporaires = [];
    }

    public function testImportObrEstIdempotentEtConserveLaValidationFinale(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $file = $this->creerFichierImportObr('OBR-IMPORT-TEST');
        $service = new AbsenceImportService($this->attendanceDb);

        $premierImport = $service->importer($file, $this->tenantId, $actorId, false);
        $secondImport = $service->importer($file, $this->tenantId, $actorId, false);

        $absence = $this->attendanceDb->table('absences')
            ->where('tenant_id', $this->tenantId)
            ->where('source_request_id', 'OBR-IMPORT-TEST')
            ->get()
            ->getRowArray();
        $this->assertSame(1, $premierImport['ajoute']);
        $this->assertSame(1, $secondImport['sans_changement']);
        $this->assertSame('APPROVED', $absence['statut']);
        $this->assertSame(1, $this->attendanceDb->table('absence_history')
            ->where('absence_id', (int) $absence['id'])
            ->countAllResults());
    }

    public function testTenantStatusMappingOverridesOBrDefaultsAndImportsCustomStatus(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $mappings = new AbsenceStatusMappingService($this->attendanceDb);
        $mappings->save($this->tenantId, 'ERP-OK', 'Validée définitivement', 'APPROVED', $actorId);

        $this->assertSame('APPROVED', $mappings->resolve(
            $this->tenantId,
            'ERP-OK',
            'Validée définitivement'
        ));
        $this->assertSame('APPROVED', $mappings->resolve($this->tenantId, '60', 'Approbation finale'));
        $this->assertNull($mappings->resolve($this->tenantId, 'ERP-OK', 'Autre libellé'));

        $file = $this->creerFichierImportObr(
            'ERP-CUSTOM-STATUS',
            'F',
            null,
            73000,
            173000,
            'ERP-OK',
            'Validée définitivement'
        );
        $result = (new AbsenceImportService($this->attendanceDb))->importer(
            $file,
            $this->tenantId,
            $actorId,
            false
        );
        $absence = $this->attendanceDb->table('absences')
            ->where('source_request_id', 'ERP-CUSTOM-STATUS')
            ->get()
            ->getRowArray();

        $this->assertSame(1, $result['ajoute']);
        $this->assertSame('APPROVED', $absence['statut']);
    }

    public function testTenantCanOverrideAndRemoveDefaultStatusMapping(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $mappings = new AbsenceStatusMappingService($this->attendanceDb);
        $id = $mappings->save(
            $this->tenantId,
            '60',
            'Approbation finale',
            'PENDING',
            $actorId
        );

        $this->assertSame('PENDING', $mappings->resolve(
            $this->tenantId,
            '60',
            'Approbation finale'
        ));
        $mappings->delete($this->tenantId, $id);
        $this->assertSame('APPROVED', $mappings->resolve(
            $this->tenantId,
            '60',
            'Approbation finale'
        ));
    }

    public function testDirectErpSyncIsDisabledByDefault(): void
    {
        $service = new AbsenceErpSyncService(false, $this->attendanceDb);

        $this->expectException(\DomainException::class);
        $service->synchroniser([], $this->tenantId, $this->creerUtilisateurImport());
    }

    public function testDirectErpSyncUsesNormalizedContractAndIsIdempotent(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $date = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $record = [
            'request_id' => 'OBR-API-TEST',
            'employee_number' => $this->matriculeEmploye(),
            'status_code' => '60',
            'status_label' => 'Approbation finale',
            'type_code' => 'AL',
            'type_label' => 'Congé annuel',
            'day_type' => 'W',
            'day_part' => 'F',
            'duration_hours' => 8,
            'start_date' => $date->format('Y-m-d'),
            'end_date' => $date->format('Y-m-d'),
            'time_start' => null,
            'time_end' => null,
            'source_updated_at' => $date->setTime(8, 0)->format('c'),
        ];
        $service = new AbsenceErpSyncService(true, $this->attendanceDb);

        $first = $service->synchroniser([$record], $this->tenantId, $actorId);
        $second = $service->synchroniser([$record], $this->tenantId, $actorId);

        $this->assertSame(1, $first['ajoute']);
        $this->assertSame(1, $second['sans_changement']);
        $this->assertSame(1, $this->attendanceDb->table('absences')
            ->where('source_request_id', 'OBR-API-TEST')
            ->countAllResults());
    }

    public function testDirectErpSyncRejectsWholeBatchWhenOneRecordIsInvalid(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $date = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $validRecord = [
            'request_id' => 'OBR-API-ATOMIC-1',
            'employee_number' => $this->matriculeEmploye(),
            'status_code' => '60',
            'status_label' => 'Approbation finale',
            'type_code' => 'AL',
            'type_label' => 'Congé annuel',
            'day_type' => 'W',
            'day_part' => 'F',
            'duration_hours' => 8,
            'start_date' => $date->format('Y-m-d'),
            'end_date' => $date->format('Y-m-d'),
            'time_start' => null,
            'time_end' => null,
            'source_updated_at' => $date->setTime(8, 0)->format('c'),
        ];
        $invalidRecord = $validRecord;
        $invalidRecord['request_id'] = 'OBR-API-ATOMIC-2';
        $invalidRecord['employee_number'] = 'MATRICULE-INCONNU';

        try {
            (new AbsenceErpSyncService(true, $this->attendanceDb))->synchroniser(
                [$validRecord, $invalidRecord],
                $this->tenantId,
                $actorId
            );
            $this->fail('Le lot contenant un employé inconnu doit être rejeté.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, $this->attendanceDb->table('absences')
                ->whereIn('source_request_id', ['OBR-API-ATOMIC-1', 'OBR-API-ATOMIC-2'])
                ->countAllResults());
        }
    }

    public function testManualImportTemplateUsesAcceptedHeadersAndIsoDateFormatting(): void
    {
        $workbook = AbsenceErpContract::createManualTemplate();
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('Leave Request Number', $sheet->getCell('A1')->getValue());
        $this->assertSame('Leave Begin Date', $sheet->getCell('F1')->getValue());
        $this->assertSame('Leave End Date', $sheet->getCell('G1')->getValue());
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('F2')->getNumberFormat()->getFormatCode());
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame('Leave Status', $sheet->getCell('H1')->getValue());
        $this->assertSame('Leave Status', $sheet->getCell('I1')->getValue());

        $date = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $excelDate = SpreadsheetDate::PHPToExcel($date);
        $sheet->fromArray([
            'MODEL-TEST',
            $this->matriculeEmploye(),
            'W',
            'F',
            8,
            $excelDate,
            $excelDate,
            '60',
            'Approbation finale',
            'AL',
            'Congé annuel',
            null,
            null,
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'ams-absence-template-');
        if ($path === false) {
            $this->fail('Impossible de créer un fichier temporaire.');
        }
        $this->fichiersTemporaires[] = $path;
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        $file = new class($path, 'modele.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return is_file($this->getTempName());
            }
        };
        $preview = (new AbsenceImportService($this->attendanceDb))->preview($file, $this->tenantId);
        $this->assertSame(1, $preview['lignes_valides']);
        $this->assertSame(0, $preview['lignes_en_erreur']);
    }

    public function testImportObrAccepteUnePeriodeFutureEtLaPrevisionCompteChaqueJour(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $debut = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $fin = $debut->modify('+2 days');
        $file = $this->creerFichierImportObr('OBR-MULTI-DAY', 'F', $fin);
        $import = (new AbsenceImportService($this->attendanceDb))->importer($file, $this->tenantId, $actorId, false);

        $absence = $this->attendanceDb->table('absences')
            ->where('tenant_id', $this->tenantId)
            ->where('source_request_id', 'OBR-MULTI-DAY')
            ->get()
            ->getRowArray();
        $forecast = (new AbsenceManagementService($this->attendanceDb))->prevoirCongesObr(
            $this->tenantId,
            $debut->format('Y-m-d'),
            $fin->format('Y-m-d')
        );

        $this->assertSame(1, $import['ajoute']);
        $this->assertSame($debut->format('Y-m-d'), $absence['date_debut']);
        $this->assertSame($fin->format('Y-m-d'), $absence['date_fin']);
        $this->assertCount(3, $forecast['jours']);
        foreach ($forecast['jours'] as $day) {
            $this->assertSame(1, $day['employes_journee_complete']);
            $this->assertSame('OBR-MULTI-DAY', $day['absences'][0]['numero_demande']);
        }
    }

    public function testImportObrAssocieLesHeuresDeLaDemiJourneeEtLaPrevoit(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $date = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $file = $this->creerFichierImportObr('OBR-HALF-DAY', 'H', $date, 73000, 113000);
        (new AbsenceImportService($this->attendanceDb))->importer($file, $this->tenantId, $actorId, false);

        $absence = $this->attendanceDb->table('absences')
            ->where('tenant_id', $this->tenantId)
            ->where('source_request_id', 'OBR-HALF-DAY')
            ->get()
            ->getRowArray();
        $forecast = (new AbsenceManagementService($this->attendanceDb))->prevoirCongesObr(
            $this->tenantId,
            $date->format('Y-m-d'),
            $date->format('Y-m-d')
        );

        $this->assertSame('0', (string) $absence['journee_complete']);
        $this->assertSame('07:30:00', $absence['heure_debut']);
        $this->assertSame('11:30:00', $absence['heure_fin']);
        $this->assertEquals(4.0, (float) $absence['duree_heures']);
        $this->assertSame(0, $forecast['jours'][0]['employes_journee_complete']);
        $this->assertSame(1, $forecast['jours'][0]['absences_partielles']);
        $this->assertSame(4.0, $forecast['jours'][0]['heures_partielles']);
    }

    public function testSeuleUneAnnulationExpliciteDansLErpRetireLeCongeDuPrevisionnel(): void
    {
        $actorId = $this->creerUtilisateurImport();
        $date = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $service = new AbsenceImportService($this->attendanceDb);
        $service->importer(
            $this->creerFichierImportObr('OBR-EXPLICIT-CANCEL'),
            $this->tenantId,
            $actorId,
            false
        );
        $service->importer(
            $this->creerFichierImportObr('OBR-EXPLICIT-CANCEL', 'F', null, 73000, 173000, '77', 'Final Deletion'),
            $this->tenantId,
            $actorId,
            false
        );

        $absence = $this->attendanceDb->table('absences')
            ->where('tenant_id', $this->tenantId)
            ->where('source_request_id', 'OBR-EXPLICIT-CANCEL')
            ->get()
            ->getRowArray();
        $forecast = (new AbsenceManagementService($this->attendanceDb))->prevoirCongesObr(
            $this->tenantId,
            $date->format('Y-m-d'),
            $date->format('Y-m-d')
        );

        $this->assertSame('CANCELLED', $absence['statut']);
        $this->assertSame([], $forecast['jours'][0]['absences']);
    }

    public function testCategoriesPeuventEtreCreeesModifieesEtSupprimees(): void
    {
        $service = new AbsenceManagementService($this->attendanceDb);

        $this->assertCount(4, $service->getCategories($this->tenantId));
        $categoryId = $service->createCategory($this->tenantId, 'Formation externe');
        $categories = $service->getCategories($this->tenantId, true);
        $category = array_values(array_filter(
            $categories,
            static fn (array $item): bool => $item['id'] === $categoryId
        ))[0];
        $this->assertSame('FORMATION_EXTERNE', $category['code']);

        $service->updateCategory($this->tenantId, $categoryId, 'Formation professionnelle');
        $this->assertSame('Formation professionnelle', array_values(array_filter(
            $service->getCategories($this->tenantId),
            static fn (array $item): bool => $item['id'] === $categoryId
        ))[0]['nom']);
        $this->assertSame(
            ['deleted' => true, 'active' => false, 'used' => false],
            $service->deleteCategory($this->tenantId, $categoryId)
        );
    }

    public function testSupervisorOnlyWorkflowIsSavedAndRequeuesPendingManagerApprovals(): void
    {
        $service = new AbsenceManagementService($this->attendanceDb);
        $approverId = $this->creerUtilisateurImport();
        $now = date('Y-m-d H:i:s');
        $this->attendanceDb->table('absences')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'source' => 'AMS',
            'source_request_id' => 'AMS-SUPERVISOR-WORKFLOW-TEST',
            'categorie' => 'ADMINISTRATIVE',
            'date_debut' => '2026-11-06',
            'date_fin' => '2026-11-06',
            'journee_complete' => 1,
            'statut' => 'MANAGER_APPROVED',
            'manager_approved_by' => $approverId,
            'manager_approved_at' => $now,
            'created_at' => $now,
        ]);
        $absenceId = (int) $this->attendanceDb->insertID();

        $service->saveWorkflow($this->tenantId, 'SUPERVISOR_ONLY', $approverId);

        $absence = $this->attendanceDb->table('absences')->where('id', $absenceId)->get()->getRowArray();
        $this->assertSame('SUPERVISOR_ONLY', $service->getSettings($this->tenantId)['workflow']);
        $this->assertSame('PENDING', $absence['statut']);
        $this->assertNull($absence['manager_approved_by']);
        $this->assertNull($absence['manager_approved_at']);
    }

    public function testCategorieUtiliseeEstDesactiveeEtResteVisibleDansLHistorique(): void
    {
        $service = new AbsenceManagementService($this->attendanceDb);
        $categoryId = $service->createCategory($this->tenantId, 'Convocation');
        $category = array_values(array_filter(
            $service->getCategories($this->tenantId, true),
            static fn (array $item): bool => $item['id'] === $categoryId
        ))[0];
        $this->attendanceDb->table('absences')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'source' => 'AMS',
            'source_request_id' => 'AMS-CATEGORY-TEST',
            'categorie' => $category['code'],
            'date_debut' => '2026-11-06',
            'date_fin' => '2026-11-06',
            'journee_complete' => 1,
            'statut' => 'PENDING',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(
            ['deleted' => false, 'active' => false, 'used' => true],
            $service->deleteCategory($this->tenantId, $categoryId)
        );
        $this->assertFalse($service->categoryIsActive($this->tenantId, $category['code']));
        $inactive = array_values(array_filter(
            $service->getCategories($this->tenantId, true),
            static fn (array $item): bool => $item['id'] === $categoryId
        ))[0];
        $this->assertSame('Convocation', $inactive['nom']);
        $this->assertFalse($inactive['actif']);
    }

    /** @var array<int, string> */
    private array $fichiersTemporaires = [];

    private function matriculeEmploye(): string
    {
        return (string) $this->attendanceDb->table('employes')
            ->select('matricule')
            ->where('id', $this->employeId)
            ->get()
            ->getRowArray()['matricule'];
    }

    private function creerUtilisateurImport(): int
    {
        $this->attendanceDb->table('users')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'email' => 'absence-import-' . bin2hex(random_bytes(6)) . '@example.test',
            'password_hash' => null,
            'role' => 'rh',
            'is_super_admin' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->attendanceDb->insertID();
    }

    private function creerFichierImportObr(
        string $requestId,
        string $dayPart = 'F',
        ?DateTimeImmutable $dateEnd = null,
        int $timeStart = 73000,
        int $timeEnd = 173000,
        string $statusCode = '60',
        string $statusLabel = 'Approbation finale'
    ): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ams-absence-import-');
        if ($path === false) {
            $this->fail('Impossible de créer un fichier temporaire.');
        }
        $this->fichiersTemporaires[] = $path;

        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray([
            'Leave Request Number', 'Employee Number', 'Employee Name', 'Soc. Princ.', 'Co',
            'Day', 'Day Type', 'Half / Full Day', 'Leave Duration', 'Leave Begin Date',
            'Leave End Date', 'Leave Status', 'Leave Status', 'Leave Type', 'Leave Type',
            'Time In', 'Time Out', 'N° Ligne', 'Supérieur', 'Supervisor',
        ], null, 'A1');
        $dateStart = new DateTimeImmutable('today', new \DateTimeZone('Africa/Bujumbura'));
        $date = SpreadsheetDate::PHPToExcel($dateStart);
        $sheet->fromArray([
            $requestId, $this->matriculeEmploye(), 'Identité confidentielle', '', '', 5, 'W', $dayPart, 8,
            $date, $dateEnd === null ? $date : SpreadsheetDate::PHPToExcel($dateEnd), $statusCode, $statusLabel, 'AL', 'Congé Annuel',
            $timeStart, $timeEnd, 1, '', '',
        ], null, 'A2');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        return new class($path, 'test-leaves.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return is_file($this->getTempName());
            }
        };
    }
}
