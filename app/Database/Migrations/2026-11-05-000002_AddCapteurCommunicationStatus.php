<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCapteurCommunicationStatus extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('capteurs', [
            'derniere_communication' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'statut',
            ],
            'source_derniere_communication' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => true,
                'after' => 'derniere_communication',
            ],
            'frequence_adms_secondes' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'source_derniere_communication',
            ],
            'frequence_polling_secondes' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'frequence_adms_secondes',
            ],
        ]);

        $this->db->table('capteurs')
            ->where('statut', 'HORS_LIGNE')
            ->update(['statut' => 'ACTIF']);
    }

    public function down(): void
    {
        $this->forge->dropColumn('capteurs', [
            'derniere_communication',
            'source_derniere_communication',
            'frequence_adms_secondes',
            'frequence_polling_secondes',
        ]);
    }
}
