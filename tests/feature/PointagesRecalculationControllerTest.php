<?php

namespace Tests\Feature;

use App\Controllers\Api\PointagesController;
use App\Services\CalculPresenceService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class PointagesRecalculationControllerTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    private IncomingRequest $controllerRequest;

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

    public function testSuperviseurNePeutPasLancerUnRecalcul(): void
    {
        $this->setJsonInput(['date' => '2026-10-19']);

        $this->assertSame(403, $this->controller('superviseur')->recalculerJournee()->getStatusCode());
    }

    public function testRecalculMasseRefusePlusDeCentPointages(): void
    {
        $this->setJsonInput(['pointage_ids' => range(1, 101)]);

        $response = $this->controller('rh')->recalculerMasse();

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testRecalculTenantEstLimiteAuTenantDuJeton(): void
    {
        $autreTenantId = $this->creerTenant();
        $this->creerEmploye($autreTenantId);
        $this->setJsonInput(['date' => '2026-10-19']);

        $response = $this->controller('admin')->recalculerJournee();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['data']['total']);
        $this->assertSame(1, $payload['data']['traitees']);
        $this->assertSame('TENANT', $payload['data']['portee']);
    }

    public function testEmployeDUnAutreTenantNestPasAccessible(): void
    {
        $autreTenantId = $this->creerTenant();
        $employeIdExterne = $this->creerEmploye($autreTenantId);
        $this->setJsonInput(['date' => '2026-10-19', 'employe_id' => $employeIdExterne]);

        $this->assertSame(404, $this->controller('rh')->recalculerJournee()->getStatusCode());
    }

    public function testPrevisualisationNePersistePasDeProjection(): void
    {
        $this->setJsonInput([
            'date' => '2026-10-19',
            'employe_id' => $this->employeId,
            'action' => 'previsualiser',
        ]);
        $avant = $this->attendanceDb->table('pointages_journee')->countAllResults();

        $response = $this->controller('rh')->recalculerJournee();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $apres = $this->attendanceDb->table('pointages_journee')->countAllResults();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PRESENT', $payload['data']['resultat']['statut_global']);
        $this->assertSame('CONTRAT', $payload['data']['resultat']['source_calcul']);
        $this->assertSame($avant, $apres);
    }

    public function testComparaisonEstLimiteeAUnEmployeDuTenantEtNePersisteRien(): void
    {
        $this->setJsonInput($this->donneesComparaison([
            'date_debut' => '2026-10-19',
            'date_fin' => '2026-10-20',
        ]));
        $avant = $this->attendanceDb->table('pointages_journee')->countAllResults();

        $response = $this->controller('rh')->comparerReglesJournee();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $apres = $this->attendanceDb->table('pointages_journee')->countAllResults();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $payload['data']['jours_compares']);
        $this->assertSame(0, $payload['data']['jours_differents']);
        $this->assertSame($avant, $apres);
    }

    public function testComparaisonRefuseUnePeriodeDePlusDe31Jours(): void
    {
        $this->setJsonInput($this->donneesComparaison([
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-11-01',
        ]));

        $response = $this->controller('rh')->comparerReglesJournee();

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testComparaisonNePermetPasDeLireUnEmployeDUnAutreTenant(): void
    {
        $autreTenantId = $this->creerTenant();
        $employeIdExterne = $this->creerEmploye($autreTenantId);
        $this->setJsonInput($this->donneesComparaison(['employe_id' => $employeIdExterne]));

        $response = $this->controller('rh')->comparerReglesJournee();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testComparaisonEstInterditeAuSuperviseur(): void
    {
        $this->setJsonInput($this->donneesComparaison());

        $response = $this->controller('superviseur')->comparerReglesJournee();

        $this->assertSame(403, $response->getStatusCode());
    }

    private function controller(string $role): PointagesController
    {
        $tenantId = $this->tenantId;
        $db = $this->attendanceDb;
        $this->controllerRequest ??= Services::incomingrequest(new App(), false);
        $controller = new class($tenantId, $role, $db) extends PointagesController {
            public function __construct(
                private readonly int $testTenantId,
                private readonly string $testRole,
                private readonly BaseConnection $testDb
            ) {
            }

            protected function claims(): ?\stdClass
            {
                return (object) ['role' => $this->testRole, 'is_super_admin' => false];
            }

            protected function tenantId(?int $requestedTenantId = null): ?int
            {
                return $requestedTenantId ?? $this->testTenantId;
            }

            protected function attendanceDatabase(): BaseConnection
            {
                return $this->testDb;
            }

            protected function presenceCalculator(BaseConnection $db): CalculPresenceService
            {
                return new class() extends CalculPresenceService {
                    public function __construct()
                    {
                    }

                    public function recalculerJournee(int $employeId, string $date): int
                    {
                        return $employeId;
                    }

                    public function interpreterJournee(int $employeId, string $date): array
                    {
                        return [
                            'statut_global' => 'PRESENT',
                            'source_calcul' => 'CONTRAT',
                            'heure_arrivee' => '07:30:00',
                            'heure_depart' => '17:30:00',
                            'heures_travaillees' => 8.0,
                        ];
                    }

                    public function comparerJourneeAvecRegles(
                        int $employeId,
                        string $date,
                        ?int $horaireId,
                        bool $actif,
                        array $parametres,
                        array $creneaux
                    ): array {
                        return [
                            'different' => false,
                            'pointages_pause_observes' => false,
                            'creneaux_pause_configures' => true,
                        ];
                    }
                };
            }
        };

        $controller->initController(
            $this->controllerRequest,
            Services::response(new App(), false),
            Services::logger()
        );

        return $controller;
    }

    /** @param array<string, mixed> $input */
    private function setJsonInput(array $input): void
    {
        $this->controllerRequest ??= Services::incomingrequest(new App(), false);
        $this->controllerRequest->setBody(json_encode($input, JSON_THROW_ON_ERROR));
        $this->controllerRequest->setHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function donneesComparaison(array $overrides = []): array
    {
        return array_replace([
            'date_debut' => '2026-10-19',
            'date_fin' => '2026-10-19',
            'employe_id' => $this->employeId,
            'horaire_id' => null,
            'actif_regles_nouvelles' => true,
            'parametres' => [],
            'creneaux' => [],
        ], $overrides);
    }
}