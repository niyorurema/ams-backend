<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class RejectedPointageService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<int, array<string, mixed>> $employes
     */
    public function enregistrer(
        int $tenantId,
        int $capteurId,
        int $badgeId,
        string $userIdCapteur,
        string $dateCapteur,
        string $source,
        string $typeEvenement,
        array $employes
    ): void {
        if (! in_array($source, ['ZKTECO', 'ADMS'], true)) {
            throw new \InvalidArgumentException('Source de pointage rejeté invalide.');
        }

        $employe = (new PointageEmployeResolver())->resoudreUnique(
            $userIdCapteur,
            $badgeId,
            $tenantId,
            $employes
        );
        $employeId = $employe === null ? null : (int) $employe['id'];
        $existant = $this->db->table('pointages_rejetes')
            ->select('id, employe_id')
            ->where('tenant_id', $tenantId)
            ->where('capteur_id', $capteurId)
            ->where('badge_id', $badgeId)
            ->where('date_capteur', $dateCapteur)
            ->get()
            ->getRowArray();
        if ($existant !== null) {
            if ($employeId !== null && $existant['employe_id'] === null) {
                $misAJour = $this->db->table('pointages_rejetes')
                    ->where('id', (int) $existant['id'])
                    ->where('tenant_id', $tenantId)
                    ->update(['employe_id' => $employeId]);
                if ($misAJour === false) {
                    throw new RuntimeException('Impossible d’associer l’employé au pointage rejeté : ' . $this->db->error()['message']);
                }
            }
            return;
        }

        $insere = $this->db->table('pointages_rejetes')->insert([
            'tenant_id' => $tenantId,
            'employe_id' => $employeId,
            'capteur_id' => $capteurId,
            'badge_id' => $badgeId,
            'source' => $source,
            'type_evenement' => $typeEvenement,
            'date_capteur' => $dateCapteur,
            'motif' => 'DATE_FUTURE',
            'recu_le' => (new TenantTimezoneService($this->db))
                ->now($tenantId)
                ->format('Y-m-d H:i:s'),
        ]);
        if ($insere === false) {
            throw new RuntimeException('Impossible d’enregistrer le pointage rejeté : ' . $this->db->error()['message']);
        }
    }

}
