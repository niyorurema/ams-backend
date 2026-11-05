<?php

namespace Tests\Unit;

use App\Services\AuditContext;
use App\Services\AuditTriggerManager;
use PHPUnit\Framework\TestCase;

final class AuditTriggerManagerTest extends TestCase
{
    public function testQualifiedMySqlDriverNameIsRecognized(): void
    {
        self::assertTrue(AuditContext::usesMySql('App\\Database\\MySQLi'));
        self::assertTrue(AuditContext::usesMySql('MySQLi'));
        self::assertFalse(AuditContext::usesMySql('SQLite3'));
    }

    public function testSensitiveValuesAreExcludedFromAuditSnapshots(): void
    {
        foreach ([
            'password_hash',
            'ldap_bind_password_encrypted',
            'smtp_secret',
            'api_token',
            'credential_data',
            'com_key',
            'empreinte_enrolee',
            'raw_data',
            'contenu_json',
            'setting_value',
            'email',
            'nom_complet',
            'matricule',
            'notes',
        ] as $column) {
            self::assertTrue(AuditTriggerManager::isSensitiveColumn($column), $column);
        }
    }

    public function testBusinessFieldsRemainAvailableForBeforeAndAfterSnapshots(): void
    {
        foreach (['tenant_id', 'actif', 'date_effet', 'parametres', 'creneaux', 'statut'] as $column) {
            self::assertFalse(AuditTriggerManager::isSensitiveColumn($column), $column);
        }
    }
}
