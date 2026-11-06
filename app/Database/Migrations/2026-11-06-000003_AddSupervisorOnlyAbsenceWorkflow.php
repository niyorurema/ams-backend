<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSupervisorOnlyAbsenceWorkflow extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('tenant_absence_settings', [
            'workflow' => [
                'type' => 'ENUM',
                'constraint' => ['RH_ONLY', 'MANAGER_THEN_RH', 'SUPERVISOR_ONLY'],
                'default' => 'RH_ONLY',
            ],
        ]);
    }

    public function down(): void
    {
        $this->db->table('tenant_absence_settings')
            ->where('workflow', 'SUPERVISOR_ONLY')
            ->update(['workflow' => 'RH_ONLY']);
        $this->forge->modifyColumn('tenant_absence_settings', [
            'workflow' => [
                'type' => 'ENUM',
                'constraint' => ['RH_ONLY', 'MANAGER_THEN_RH'],
                'default' => 'RH_ONLY',
            ],
        ]);
    }
}
