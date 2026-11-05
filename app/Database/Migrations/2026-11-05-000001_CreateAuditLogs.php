<?php

namespace App\Database\Migrations;

use App\Services\AuditContext;
use App\Services\AuditTriggerManager;
use CodeIgniter\Database\Migration;

class CreateAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'object_type' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'object_id' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'occurred_at' => ['type' => 'DATETIME', 'null' => false],
            'before_values' => ['type' => 'JSON', 'null' => true],
            'after_values' => ['type' => 'JSON', 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 1024, 'null' => true],
            'request_id' => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'source' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'SYSTEM'],
            'device_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'occurred_at']);
        $this->forge->addKey(['actor_id', 'occurred_at']);
        $this->forge->addKey(['object_type', 'object_id', 'occurred_at']);
        $this->forge->addKey(['device_id', 'occurred_at']);
        $this->forge->addKey('request_id');
        $this->forge->createTable('audit_logs', true, ['ENGINE' => 'InnoDB']);

        if (AuditContext::usesMySql($this->db->DBDriver)) {
            (new AuditTriggerManager($this->db))->install();
        }
    }

    public function down(): void
    {
        (new AuditTriggerManager($this->db))->dropManagedTriggers();
        $this->forge->dropTable('audit_logs', true);
    }
}
