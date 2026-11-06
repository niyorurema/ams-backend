<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAbsenceStatusMappingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'source_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false],
            'source_label' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'absence_status' => [
                'type' => 'ENUM',
                'constraint' => ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'],
                'null' => false,
            ],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addUniqueKey(
            ['tenant_id', 'source_code', 'source_label'],
            'uk_absence_status_mapping_source'
        );
        $this->forge->addKey(['tenant_id', 'absence_status']);
        $this->forge->createTable('absence_status_mappings', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('absence_status_mappings', true);
    }
}
