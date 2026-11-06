<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddAbsenceSourceToDailyProjection extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('pointages_journee', [
            'source_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['SHIFT', 'CONTRAT', 'HORAIRE_INDIVIDUEL', 'TENANT_DEFAUT', 'JOUR_FERIE', 'ABSENCE'],
                'null' => false,
            ],
        ]);
    }

    public function down(): void
    {
        $count = (int) $this->db->table('pointages_journee')
            ->where('source_calcul', 'ABSENCE')
            ->countAllResults();
        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'Rollback refusé : %d projection(s) dépendent de la source ABSENCE.',
                $count
            ));
        }

        $this->forge->modifyColumn('pointages_journee', [
            'source_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['SHIFT', 'CONTRAT', 'HORAIRE_INDIVIDUEL', 'TENANT_DEFAUT', 'JOUR_FERIE'],
                'null' => false,
            ],
        ]);
    }
}
