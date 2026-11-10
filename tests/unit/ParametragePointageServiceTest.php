<?php

use App\Services\ParametragePointageService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ParametragePointageServiceTest extends CIUnitTestCase
{
    public function testInitialisationDesParametresEstIdempotente(): void
    {
        $stockage = new class() extends ParametragePointageService {
            /** @var array<string, array<string, mixed>> */
            public array $parametres = [];

            public function __construct()
            {
            }

            protected function tableDisponible(): bool
            {
                return true;
            }

            protected function chargerParametre(int $tenantId, string $cle): ?array
            {
                return $this->parametres[$tenantId . ':' . $cle] ?? null;
            }

            protected function insererParametreParDefaut(array $parametre): void
            {
                $this->parametres[$parametre['tenant_id'] . ':' . $parametre['cle']] = $parametre;
            }
        };

        $stockage->initialiserParametresParDefaut(12);
        $stockage->initialiserParametresParDefaut(12);

        $this->assertCount(7, $stockage->parametres);
        $this->assertSame('360', $stockage->parametres['12:ecart_max_association_minutes']['valeur']);
        $this->assertSame('5', $stockage->parametres['12:marge_pointage_futur_minutes']['valeur']);
        $this->assertSame('ARRONDI', $stockage->parametres['12:mode_calcul_heures_sup']['valeur']);
        $this->assertSame('15', $stockage->parametres['12:pas_arrondi_heures_sup_minutes']['valeur']);
    }

    public function testInitialisationNeRemplacePasUneValeurPersonnaliseeExistante(): void
    {
        $stockage = new class() extends ParametragePointageService {
            /** @var array<string, array<string, mixed>> */
            public array $parametres = [];

            public function __construct()
            {
                $this->parametres['12:ecart_max_association_minutes'] = [
                    'tenant_id' => 12,
                    'cle' => 'ecart_max_association_minutes',
                    'valeur' => '240',
                    'type' => 'INT',
                ];
            }

            protected function tableDisponible(): bool
            {
                return true;
            }

            protected function chargerParametre(int $tenantId, string $cle): ?array
            {
                return $this->parametres[$tenantId . ':' . $cle] ?? null;
            }

            protected function insererParametreParDefaut(array $parametre): void
            {
                $this->parametres[$parametre['tenant_id'] . ':' . $parametre['cle']] = $parametre;
            }
        };

        $stockage->initialiserParametresParDefaut(12);

        $this->assertSame('240', $stockage->parametres['12:ecart_max_association_minutes']['valeur']);
        $this->assertCount(7, $stockage->parametres);
    }

    public function testRetourneLesValeursDeRepliSiLaTableOuLaCleEstAbsente(): void
    {
        $service = new class() extends ParametragePointageService {
            public function __construct()
            {
            }

            protected function tableDisponible(): bool
            {
                return false;
            }

            protected function chargerParametre(int $tenantId, string $cle): ?array
            {
                return null;
            }
        };

        $this->assertSame(360, $service->get(3, 'ecart_max_association_minutes'));
        $this->assertSame(5, $service->get(3, 'marge_pointage_futur_minutes'));
        $this->assertSame(77, $service->get(3, 'cle_absente', 77));
        $this->assertNull($service->get(3, 'cle_absente'));
        $this->assertSame(5, $service->getConfiguration(3)['marge_pointage_futur_minutes']['valeur']);
    }
}
