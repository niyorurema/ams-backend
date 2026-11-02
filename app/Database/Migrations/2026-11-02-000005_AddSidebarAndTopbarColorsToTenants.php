<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSidebarAndTopbarColorsToTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'sidebar_color' => [
                'type' => 'CHAR',
                'constraint' => 7,
                'null' => true,
                'after' => 'secondary_color',
            ],
            'topbar_color' => [
                'type' => 'CHAR',
                'constraint' => 7,
                'null' => true,
                'after' => 'sidebar_color',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', [
            'sidebar_color',
            'topbar_color',
        ]);
    }
}
