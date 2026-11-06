<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAbsenceCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'code' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'nom' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'actif' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'code'], 'uk_absence_categories_tenant_code');
        $this->forge->addUniqueKey(['tenant_id', 'nom'], 'uk_absence_categories_tenant_name');
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->createTable('absence_categories', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('absence_categories', true);
    }
}
