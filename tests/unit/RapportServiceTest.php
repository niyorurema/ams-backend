<?php

use App\Services\RapportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RapportServiceTest extends CIUnitTestCase
{
    public function testExtraitLesHeuresDeRetardEtDeDepartAnticipe(): void
    {
        $service = new class extends RapportService {
            public function __construct()
            {
            }

            public function arrival(array $analyse): ?string
            {
                return $this->heureArriveeTardive($analyse);
            }

            public function earlyDeparture(array $analyse): ?string
            {
                return $this->heureDepartAnticipe($analyse);
            }
        };
        $analyse = [
            ['type' => 'CHECK_IN', 'reel' => '09:04:42', 'statut' => 'RETARD', 'ecart_min' => 64],
            ['type' => 'BREAK_IN', 'reel' => '12:05:00', 'statut' => 'RETARD', 'ecart_min' => 5],
            ['type' => 'CHECK_OUT', 'reel' => '16:45:00', 'statut' => 'OK', 'ecart_min' => -15],
        ];

        $this->assertSame('09:04:42', $service->arrival($analyse));
        $this->assertSame('16:45:00', $service->earlyDeparture($analyse));
    }

    public function testNIndiquePasDeRetardOuDeDepartAnticipeSansEcart(): void
    {
        $service = new class extends RapportService {
            public function __construct()
            {
            }

            public function arrival(array $analyse): ?string
            {
                return $this->heureArriveeTardive($analyse);
            }

            public function earlyDeparture(array $analyse): ?string
            {
                return $this->heureDepartAnticipe($analyse);
            }
        };
        $analyse = [
            ['type' => 'CHECK_IN', 'reel' => '08:00:00', 'statut' => 'OK', 'ecart_min' => 0],
            ['type' => 'CHECK_OUT', 'reel' => '17:00:00', 'statut' => 'OK', 'ecart_min' => 0],
        ];

        $this->assertNull($service->arrival($analyse));
        $this->assertNull($service->earlyDeparture($analyse));
    }

    public function testToutesLesEntreesQuotidiennesUtilisentLeTraitementJ1Unique(): void
    {
        $service = new class extends RapportService {
            public int $preparations = 0;

            public function __construct()
            {
            }

            public function contexteRapportsQuotidiens(): array
            {
                $this->preparations++;

                return [];
            }
        };

        $resultatCommande = $service->envoyerRapportsQuotidiens();
        $resultatType = $service->envoyerRapportsParType('QUOTIDIEN');

        $this->assertSame(['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0], $resultatCommande);
        $this->assertSame($resultatCommande, $resultatType);
        $this->assertSame(2, $service->preparations);
    }

    public function testPreparationUnitaireDelegueAuMemeGenerateurAvecLePerimetre(): void
    {
        $service = new class extends RapportService {
            /** @var array{int,string,string,?int}|null */
            public ?array $arguments = null;

            public function __construct()
            {
            }

            public function genererRapportQuotidien(
                int $userId,
                string $date,
                ?string $dateMandat = null,
                ?int $uniteScopeId = null,
                bool $inclureStatsInternes = false
            ): array {
                $this->arguments = [$userId, $date, (string) $dateMandat, $uniteScopeId];

                return ['date' => $date];
            }
        };

        $rapport = $service->preparerRapportPourDestinataire(
            ['user_id' => 42, 'unite_id' => 17],
            '2026-10-23',
            '2026-10-24'
        );

        $this->assertSame(['date' => '2026-10-23'], $rapport);
        $this->assertSame([42, '2026-10-23', '2026-10-24', 17], $service->arguments);
    }

    public function testBornesDesRapportsHebdomadairesEtMensuels(): void
    {
        $semaine = RapportService::bornesPeriodePlanifiee(
            'HEBDOMADAIRE',
            new DateTimeImmutable('2026-01-01')
        );
        $mois = RapportService::bornesPeriodePlanifiee(
            'MENSUEL',
            new DateTimeImmutable('2024-03-01')
        );

        $this->assertSame(['debut' => '2025-12-22', 'fin' => '2025-12-28'], $semaine);
        $this->assertSame(['debut' => '2024-02-01', 'fin' => '2024-02-29'], $mois);
    }

    public function testAgregeLesRapportsJournaliersEnConservantLesDatesDeDetails(): void
    {
        $service = new class extends RapportService {
            public function __construct()
            {
            }

            public function genererRapportQuotidien(
                int $userId,
                string $date,
                ?string $dateMandat = null,
                ?int $uniteScopeId = null,
                bool $inclureStatsInternes = false
            ): array {
                $deuxiemeJour = $date === '2026-01-02';

                return [
                    'date' => $date,
                    'destinataire' => ['nom' => 'Utilisateur'],
                    'horaire' => 'Horaire',
                    'est_jour_travaille' => true,
                    'raison_non_travaille' => null,
                    'stats' => [
                        'total_employes' => 2,
                        'employes_planifies' => 2,
                        'presents' => $deuxiemeJour ? 2 : 1,
                        'absents' => $deuxiemeJour ? 0 : 1,
                        'normaux' => 1,
                        'retards' => 0,
                        'anormaux' => 0,
                        'feries' => 0,
                        'conges' => 0,
                        'empechements' => 0,
                        'absences_autorisees' => 0,
                        'absences_partielles' => 0,
                        'conflits_absence_pointage' => 0,
                        'repos' => 0,
                        'sans_jugement' => 0,
                        'heures_totales' => $deuxiemeJour ? 16.0 : 8.0,
                    ],
                    'details' => [
                        ['matricule' => 'A001', 'nom' => 'A', 'prenom' => 'Employé'],
                        ['matricule' => 'A002', 'nom' => 'B', 'prenom' => 'Employé'],
                    ],
                    'alertes' => [],
                ];
            }
        };

        $rapport = $service->genererRapportPeriode(42, '2026-01-01', '2026-01-02', null, 'HEBDOMADAIRE');

        $this->assertSame(2, $rapport['stats']['total_employes']);
        $this->assertSame(4, $rapport['stats']['jours_employes']);
        $this->assertSame(24.0, $rapport['stats']['heures_totales']);
        $this->assertSame(6.0, $rapport['stats']['moyenne_heures']);
        $this->assertSame(75.0, $rapport['stats']['taux_presence']);
        $this->assertSame('2026-01-02', $rapport['details'][2]['date']);
        $this->assertSame(
            ['type' => 'HEBDOMADAIRE', 'debut' => '2026-01-01', 'fin' => '2026-01-02'],
            $rapport['periode']
        );
    }
}
