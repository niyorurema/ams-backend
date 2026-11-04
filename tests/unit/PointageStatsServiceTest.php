<?php

use App\Services\PointageStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointageStatsServiceTest extends CIUnitTestCase
{
    public function testCalculeLesStatistiquesDunePeriode(): void
    {
        $service = new PointageStatsService();

        $resultat = $service->calculerDepuisCollection([
            ['date_pointage' => '2026-09-01 07:30:00', 'type_evenement' => 'CHECK_IN', 'employe_id' => 1, 'capteur_id' => 10],
            ['date_pointage' => '2026-09-01 17:30:00', 'type_evenement' => 'CHECK_OUT', 'employe_id' => 1, 'capteur_id' => 10],
            ['date_pointage' => '2026-09-02 08:00:00', 'type_evenement' => 'CHECK_IN', 'employe_id' => 2, 'capteur_id' => 11],
            ['date_pointage' => '2026-09-02 12:00:00', 'type_evenement' => 'BREAK_IN', 'employe_id' => 2, 'capteur_id' => 11],
            ['date_pointage' => '2026-09-02 13:00:00', 'type_evenement' => 'BREAK_OUT', 'employe_id' => 2, 'capteur_id' => 11],
        ], '2026-09-01', '2026-09-02');

        $this->assertSame(5, $resultat['total']);
        $this->assertSame(2.5, $resultat['moyenne_par_jour']);
        $this->assertSame(2, $resultat['employes_actifs']);
        $this->assertSame(2, $resultat['capteurs_utilises']);
        $this->assertSame(['CHECK_IN' => 2, 'CHECK_OUT' => 1, 'BREAK_IN' => 1, 'BREAK_OUT' => 1], $resultat['par_type']);
        $this->assertSame([
            ['date' => '2026-09-01', 'count' => 2],
            ['date' => '2026-09-02', 'count' => 3],
        ], $resultat['par_jour']);
    }

    public function testCompteLesEmployesEtCapteursDistinctsParTenant(): void
    {
        $resultat = (new PointageStatsService())->calculerDepuisCollection([
            [
                'tenant_id' => 1,
                'date_pointage' => '2026-09-01 07:30:00',
                'type_evenement' => 'CHECK_IN',
                'employe_id' => 5,
                'capteur_id' => 2,
            ],
            [
                'tenant_id' => 2,
                'date_pointage' => '2026-09-01 07:30:00',
                'type_evenement' => 'CHECK_IN',
                'employe_id' => 5,
                'capteur_id' => 2,
            ],
        ], '2026-09-01', '2026-09-01');

        $this->assertSame(2, $resultat['employes_actifs']);
        $this->assertSame(2, $resultat['capteurs_utilises']);
    }
}
