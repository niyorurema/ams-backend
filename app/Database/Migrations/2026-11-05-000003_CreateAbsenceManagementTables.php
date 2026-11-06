<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAbsenceManagementTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'workflow' => [
                'type' => 'ENUM',
                'constraint' => ['RH_ONLY', 'MANAGER_THEN_RH'],
                'default' => 'RH_ONLY',
            ],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('tenant_absence_settings', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'uploaded_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'file_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'file_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'source_rows' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'imported_rows' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'skipped_rows' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('uploaded_by', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->createTable('absence_import_batches', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'employe_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'source' => ['type' => 'ENUM', 'constraint' => ['AMS', 'OBR'], 'null' => false],
            'source_request_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'source_status_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'source_status_label' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'source_type_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'source_day_type' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'categorie' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'motif' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'date_debut' => ['type' => 'DATE', 'null' => false],
            'date_fin' => ['type' => 'DATE', 'null' => false],
            'journee_complete' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'heure_debut' => ['type' => 'TIME', 'null' => true],
            'heure_fin' => ['type' => 'TIME', 'null' => true],
            'duree_heures' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'statut' => [
                'type' => 'ENUM',
                'constraint' => ['PENDING', 'MANAGER_APPROVED', 'APPROVED', 'REJECTED', 'CANCELLED'],
                'default' => 'PENDING',
            ],
            'manager_approved_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'manager_approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rh_approved_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'rh_approved_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'import_batch_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey(
            ['tenant_id', 'employe_id'],
            'employes',
            ['tenant_id', 'id'],
            'CASCADE',
            'CASCADE',
            'fk_absences_tenant_employe'
        );
        $this->forge->addForeignKey('manager_approved_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('rh_approved_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('import_batch_id', 'absence_import_batches', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addUniqueKey(
            ['tenant_id', 'source', 'source_request_id', 'date_debut'],
            'uk_absences_source_request_day'
        );
        $this->forge->addKey(['tenant_id', 'date_debut', 'date_fin', 'statut'], false, false, 'idx_absences_dates');
        $this->forge->addKey(['tenant_id', 'employe_id', 'date_debut'], false, false, 'idx_absences_employee');
        $this->forge->createTable('absences', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'absence_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'ancien_statut' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'nouveau_statut' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'commentaire' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('absence_id', 'absences', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('actor_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addKey(['tenant_id', 'absence_id', 'created_at']);
        $this->forge->createTable('absence_history', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('absence_history', true);
        $this->forge->dropTable('absences', true);
        $this->forge->dropTable('absence_import_batches', true);
        $this->forge->dropTable('tenant_absence_settings', true);
    }
}
