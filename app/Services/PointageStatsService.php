<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Calcule les agrégats statistiques à partir d'un lot de pointages.
 */
final class PointageStatsService
{
    /**
     * @param array<int, array<string, mixed>> $pointages
     * @return array<string, mixed>
     */
    public function calculerDepuisCollection(array $pointages, string $dateDebut, string $dateFin): array
    {
        $total = count($pointages);

        $parJour = [];
        $parType = [];
        $employes = [];
        $capteurs = [];

        foreach ($pointages as $pointage) {
            $date = (string) ($pointage['date_pointage'] ?? '');
            $type = (string) ($pointage['type_evenement'] ?? 'UNKNOWN');

            if ($date !== '') {
                $jour = date('Y-m-d', strtotime($date));
                if (! isset($parJour[$jour])) {
                    $parJour[$jour] = 0;
                }

                $parJour[$jour]++;
            }

            if ($type !== '') {
                if (! isset($parType[$type])) {
                    $parType[$type] = 0;
                }

                $parType[$type]++;
            }

            $employeId = $pointage['employe_id'] ?? null;
            if ($employeId !== null && $employeId !== '' && (int) $employeId > 0) {
                $employes[(int) ($pointage['tenant_id'] ?? 0) . ':' . (int) $employeId] = true;
            }

            $capteurId = $pointage['capteur_id'] ?? null;
            if ($capteurId !== null && $capteurId !== '' && (int) $capteurId > 0) {
                $capteurs[(int) ($pointage['tenant_id'] ?? 0) . ':' . (int) $capteurId] = true;
            }
        }

        ksort($parJour);
        $joursCouverts = $this->nbJoursEntre($dateDebut, $dateFin);
        $moyenneParJour = $joursCouverts > 0 ? round($total / $joursCouverts, 2) : 0.0;

        return [
            'periode' => [
                'debut' => $dateDebut,
                'fin' => $dateFin,
            ],
            'total' => $total,
            'moyenne_par_jour' => $moyenneParJour,
            'employes_actifs' => count($employes),
            'capteurs_utilises' => count($capteurs),
            'par_jour' => array_map(
                static fn (string $jour, int $count): array => ['date' => $jour, 'count' => $count],
                array_keys($parJour),
                array_values($parJour)
            ),
            'par_type' => $parType,
        ];
    }

    private function nbJoursEntre(string $dateDebut, string $dateFin): int
    {
        $debut = new DateTimeImmutable($dateDebut);
        $fin = new DateTimeImmutable($dateFin);

        return (int) $debut->diff($fin)->days + 1;
    }
}
