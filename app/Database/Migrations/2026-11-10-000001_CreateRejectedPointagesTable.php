<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRejectedPointagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'employe_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'capteur_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'badge_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'source' => ['type' => 'ENUM', 'constraint' => ['ZKTECO', 'ADMS'], 'null' => false],
            'type_evenement' => [
                'type' => 'ENUM',
                'constraint' => ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT', 'UNKNOWN'],
                'default' => 'UNKNOWN',
            ],
            'date_capteur' => ['type' => 'VARCHAR', 'constraint' => 19, 'null' => false],
            'motif' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'DATE_FUTURE'],
            'recu_le' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('employe_id', 'employes', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('capteur_id', 'capteurs', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addUniqueKey(
            ['tenant_id', 'capteur_id', 'badge_id', 'date_capteur'],
            'uk_pointages_rejetes_evenement'
        );
        $this->forge->addKey(['tenant_id', 'employe_id', 'recu_le'], false, false, 'idx_pointages_rejetes_employe');
        $this->forge->createTable('pointages_rejetes', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('pointages_rejetes', true);
    }
}
