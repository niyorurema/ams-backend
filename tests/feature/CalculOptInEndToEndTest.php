<?php

namespace Tests\Feature;

use App\Services\CalculPresenceService;
use App\Services\ContratsHoraireService;
use App\Services\HoraireService;
use App\Services\ParametragePointageService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class CalculOptInEndToEndTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testPersisteUneJourneeOptInPresente(): void
    {
        $this->preparerJournee([['CHECK_IN', '07:25:00'], ['CHECK_OUT', '17:35:00']]);

        $id = (new CalculPresenceService(null, $this->attendanceDb))
            ->recalculerJournee($this->employeId, '2026-10-19');
        $projection = $this->attendanceDb->table('pointages_journee')->where('id', $id)->get()->getRowArray();

        $this->assertGreaterThan(0, $id);
        $this->assertSame('OK', $projection['statut_arrivee']);
        $this->assertSame('OK', $projection['statut_depart']);
        $this->assertSame('PRESENT', $projection['statut_global']);
        $this->assertEqualsWithDelta(10.17, (float) $projection['heures_travaillees'], 0.02);
    }

    public function testPersisteUnRetardPourUneArriveeTardive(): void
    {
        $this->preparerJournee([['CHECK_IN', '13:00:00'], ['CHECK_OUT', '17:35:00']]);

        $id = (new CalculPresenceService(null, $this->attendanceDb))
            ->recalculerJournee($this->employeId, '2026-10-19');
        $projection = $this->attendanceDb->table('pointages_journee')->where('id', $id)->get()->getRowArray();

        $this->assertSame('RETARD', $projection['statut_arrivee']);
        $this->assertSame('RETARD', $projection['statut_global']);
    }

    public function testEmpechementPartielEnDebutDeJourneeDecaleLAttenteDArrivee(): void
    {
        $this->preparerJournee([['CHECK_IN', '10:00:00'], ['CHECK_OUT', '17:30:00']]);
        $this->ajouterAbsencePartielle('07:30:00', '10:00:00', 2.5);

        $resultat = (new CalculPresenceService(null, $this->attendanceDb))
            ->interpreterJournee($this->employeId, '2026-10-19');

        $this->assertSame('PRESENT', $resultat['statut_global']);
        $this->assertSame('10:00', $resultat['analyse_plages'][0]['attendu']);
        $this->assertSame(2.5, $resultat['details']['heures_absence_autorisee']);
        $this->assertSame(0.0, $resultat['heures_supplementaires']);
    }

    public function testEmpechementPartielEnFinDeJourneeAvanceLAttenteDeDepart(): void
    {
        $this->preparerJournee([['CHECK_IN', '07:30:00'], ['CHECK_OUT', '15:00:00']]);
        $this->ajouterAbsencePartielle('15:00:00', '17:30:00', 2.5);

        $resultat = (new CalculPresenceService(null, $this->attendanceDb))
            ->interpreterJournee($this->employeId, '2026-10-19');

        $this->assertSame('15:00', $resultat['analyse_plages'][1]['attendu']);
        $this->assertSame('PRESENT', $resultat['statut_global']);
        $this->assertSame(0.0, $resultat['heures_supplementaires']);
    }

    public function testEmpechementHorsHorairesNeReduitPasLesHeuresAttendues(): void
    {
        $this->preparerJournee([['CHECK_IN', '07:30:00'], ['CHECK_OUT', '17:30:00']]);
        $this->ajouterAbsencePartielle('18:00:00', '19:00:00', 1.0);

        $resultat = (new CalculPresenceService(null, $this->attendanceDb))
            ->interpreterJournee($this->employeId, '2026-10-19');

        $this->assertSame(0.0, $resultat['heures_supplementaires']);
        $this->assertSame(0.0, $resultat['details']['heures_absence_prise_en_compte']);
    }

    public function testEmpechementPartielAdapteAussiLeCalculHistorique(): void
    {
        $this->preparerJournee([['CHECK_IN', '10:00:00'], ['CHECK_OUT', '17:30:00']]);
        $this->ajouterAbsencePartielle('07:30:00', '10:00:00', 2.5);
        (new HoraireService($this->attendanceDb))->creerHoraireDefaut($this->tenantId, 'STANDARD');
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['actif_regles_nouvelles' => 0]);

        $resultat = (new CalculPresenceService(null, $this->attendanceDb))
            ->interpreterJournee($this->employeId, '2026-10-19');

        $this->assertSame('10:00', $resultat['analyse_plages'][0]['attendu']);
        $this->assertSame(0, $resultat['retard_total_minutes']);
        $this->assertSame(2.5, $resultat['details']['heures_absence_autorisee']);
    }

    public function testArriveeTresAnticipeeEtDepartApresMinuitSontRattachesAuJourDeTravail(): void
    {
        $this->preparerJournee([]);
        $this->ajouterPointage('2026-10-19', 'CHECK_IN', '04:00:00');
        $this->ajouterPointage('2026-10-20', 'CHECK_OUT', '02:00:00');
        $this->ajouterPointage('2026-10-20', 'CHECK_IN', '07:20:00');
        $service = new CalculPresenceService(null, $this->attendanceDb);

        $veille = $service->interpreterJournee($this->employeId, '2026-10-19');
        $jourSuivant = $service->interpreterJournee($this->employeId, '2026-10-20');

        $this->assertSame('04:00:00', $veille['heure_arrivee']);
        $this->assertSame('02:00:00', $veille['heure_depart']);
        $this->assertSame('PRESENT', $veille['statut_global']);
        $this->assertSame(22.0, $veille['heures_travaillees']);
        $this->assertSame('07:20:00', $jourSuivant['heure_arrivee']);
        $this->assertNull($jourSuivant['heure_depart']);
        $this->assertNotContains('HORS_CRENEAU', array_column($jourSuivant['details']['creneaux'], 'statut'));
    }

    public function testShiftDeNuitRattacheLaSortieEtDeduitLaPauseReelleSansAppliquerLesCreneauxObrDeMidi(): void
    {
        $this->preparerJournee([]);
        $this->attendanceDb->table('employe_contrats_horaire')
            ->where('tenant_id', $this->tenantId)
            ->where('employe_id', $this->employeId)
            ->update(['actif' => 0]);
        $horodatage = date('Y-m-d H:i:s');
        $this->attendanceDb->table('shifts')->insert([
            'tenant_id' => $this->tenantId,
            'nom' => 'Nuit de test',
            'code' => 'NUIT',
            'heure_debut' => '22:00:00',
            'heure_fin' => '06:00:00',
            'created_at' => $horodatage,
        ]);
        $shiftId = (int) $this->attendanceDb->insertID();
        $this->attendanceDb->table('shift_assignments')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'shift_id' => $shiftId,
            'date_debut' => '2026-10-19',
            'date_fin' => '2026-10-20',
            'actif' => 1,
            'created_at' => $horodatage,
        ]);
        $this->ajouterPointage('2026-10-19', 'CHECK_IN', '22:00:00');
        $this->ajouterPointage('2026-10-20', 'BREAK_OUT', '02:00:00');
        $this->ajouterPointage('2026-10-20', 'BREAK_IN', '02:30:00');
        $this->ajouterPointage('2026-10-20', 'CHECK_OUT', '06:30:00');
        $this->ajouterPointage('2026-10-20', 'CHECK_IN', '22:00:00');
        $service = new CalculPresenceService(null, $this->attendanceDb);

        $nuit = $service->interpreterJournee($this->employeId, '2026-10-19');
        $nuitSuivante = $service->interpreterJournee($this->employeId, '2026-10-20');

        $this->assertSame('SHIFT', $nuit['source_calcul']);
        $this->assertSame('06:30:00', $nuit['heure_depart']);
        $this->assertSame('PRESENT', $nuit['statut_global']);
        $this->assertSame(8.0, $nuit['heures_travaillees']);
        $this->assertSame(['ARRIVEE', 'DEPART'], array_column($nuit['details']['creneaux'], 'creneau'));
        $this->assertSame('22:00:00', $nuitSuivante['heure_arrivee']);
        $this->assertNotContains('HORS_CRENEAU', array_column($nuitSuivante['details']['creneaux'], 'statut'));
    }

    public function testDureeMaximaleDePauseParDefautInclutLesDixMinutesDeTolerance(): void
    {
        $this->assertSame(
            130,
            (new ParametragePointageService($this->attendanceDb))
                ->get($this->tenantId, 'duree_pause_maximum_minutes')
        );
    }

    public function testStatistiquesOptInReutilisentLeResultatJournalier(): void
    {
        $this->preparerJournee([]);

        $stats = (new CalculPresenceService(null, $this->attendanceDb))
            ->getStatsPresenceParJour($this->tenantId, '2026-10-19', '2026-10-19');

        $this->assertSame(1, $stats['totaux']['absents']);
        $this->assertSame(0, $stats['totaux']['en_cours']);
        $this->assertSame(1, $stats['donnees'][0]['total']);
    }

    public function testStatistiquesNeComptentPasUnJourHorsContratCommeAbsence(): void
    {
        $this->preparerJournee([]);
        $this->attendanceDb->table('employe_contrats_horaire')
            ->where('tenant_id', $this->tenantId)
            ->where('employe_id', $this->employeId)
            ->update(['jours_travail' => json_encode([2], JSON_THROW_ON_ERROR)]);

        $stats = (new CalculPresenceService(null, $this->attendanceDb))
            ->getStatsPresenceParJour($this->tenantId, '2026-10-19', '2026-10-19');

        $this->assertSame(0, $stats['totaux']['absents']);
        $this->assertSame(1, $stats['totaux']['repos']);
    }

    public function testStatistiquesNeComptentPasUneDateApresLaFinDuContratCommeAbsence(): void
    {
        $this->preparerJournee([]);
        $this->attendanceDb->table('employe_contrats_horaire')
            ->where('tenant_id', $this->tenantId)
            ->where('employe_id', $this->employeId)
            ->update(['date_fin' => '2026-10-18']);

        $stats = (new CalculPresenceService(null, $this->attendanceDb))
            ->getStatsPresenceParJour($this->tenantId, '2026-10-19', '2026-10-19');

        $this->assertSame(0, $stats['totaux']['absents']);
        $this->assertSame(1, $stats['totaux']['repos']);
    }

    public function testHoraireLibreEstSansJugementMemeSansActivationDesNouvellesRegles(): void
    {
        $this->preparerJournee([]);
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['actif_regles_nouvelles' => 0]);
        $this->attendanceDb->table('employe_contrats_horaire')
            ->where('tenant_id', $this->tenantId)
            ->where('employe_id', $this->employeId)
            ->update(['mode_travail' => 'HORAIRE_LIBRE']);

        $stats = (new CalculPresenceService(null, $this->attendanceDb))
            ->getStatsPresenceParJour($this->tenantId, '2026-10-19', '2026-10-19');

        $this->assertSame(0, $stats['totaux']['presents']);
        $this->assertSame(0, $stats['totaux']['absents']);
        $this->assertSame(1, $stats['totaux']['sans_jugement']);
    }

    /** @param array<int, array{string, string}> $pointages */
    private function preparerJournee(array $pointages): void
    {
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['actif_regles_nouvelles' => 1, 'jours_travailles' => '1,2,3,4,5,6,7']);

        $service = new ContratsHoraireService($this->attendanceDb);
        $service->creer($this->tenantId, $this->employeId, [
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30',
            'heure_fin' => '17:30',
            'jours_travail' => [1, 2, 3, 4, 5, 6, 7],
            'date_debut' => '2026-10-19',
        ]);

        $horodatage = date('Y-m-d H:i:s');
        foreach ([
            ['ARRIVEE', 'LIMITE_TARDIVE', '07:30:00', 10],
            ['DEPART', 'LIMITE_PRECOCE', '17:30:00', 10],
        ] as [$type, $mode, $cible, $tolerance]) {
            $this->attendanceDb->table('tenant_creneaux_pointage')->insert([
                'tenant_id' => $this->tenantId,
                'type_creneau' => $type,
                'mode' => $mode,
                'heure_cible' => $cible,
                'tolerance_minutes' => $tolerance,
                'actif' => 1,
                'ordre_affichage' => 0,
                'created_at' => $horodatage,
            ]);
        }

        foreach ($pointages as [$type, $heure]) {
            $this->ajouterPointage('2026-10-19', $type, $heure);
        }
    }

    private function ajouterPointage(string $date, string $type, string $heure): void
    {
        $this->attendanceDb->table('pointages')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'badge_id' => (int) $this->attendanceDb->table('employes')->select('badge_id')
                ->where('id', $this->employeId)->get()->getRowArray()['badge_id'],
            'type_evenement' => $type,
            'date_pointage' => $date . ' ' . $heure,
            'source' => 'IMPORT',
            'statut' => 'VALIDE',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ajouterAbsencePartielle(string $debut, string $fin, float $duree): void
    {
        $this->attendanceDb->table('absences')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'source' => 'AMS',
            'source_request_id' => 'AMS-TEST-' . bin2hex(random_bytes(8)),
            'categorie' => 'MEDICAL',
            'date_debut' => '2026-10-19',
            'date_fin' => '2026-10-19',
            'journee_complete' => 0,
            'heure_debut' => $debut,
            'heure_fin' => $fin,
            'duree_heures' => $duree,
            'statut' => 'APPROVED',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
