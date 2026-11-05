<?php

namespace Tests\Unit;

use App\Services\CapteurPresenceService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CapteurPresenceServiceTest extends TestCase
{
    public function testDisabledSensorRemainsInactiveRegardlessOfRecentCommunication(): void
    {
        $sensor = [
            'statut' => 'INACTIF',
            'derniere_communication' => '2026-11-05 12:00:00',
            'source_derniere_communication' => 'ADMS',
            'frequence_adms_secondes' => 60,
        ];

        self::assertSame('INACTIVE', CapteurPresenceService::status(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:00:01')
        ));
        self::assertSame('ONLINE', CapteurPresenceService::communicationStatus(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:00:01')
        ));
    }

    public function testNoKnownCommunicationProducesUnknownStatus(): void
    {
        self::assertSame('UNKNOWN', CapteurPresenceService::status([
            'statut' => 'ACTIF',
            'derniere_communication' => null,
            'source_derniere_communication' => null,
            'frequence_adms_secondes' => null,
            'frequence_polling_secondes' => null,
        ], new DateTimeImmutable('2026-11-05 12:00:00')));
    }

    public function testAdmsStatusUsesThreeConfiguredTransmissionIntervals(): void
    {
        $sensor = [
            'statut' => 'ACTIF',
            'derniere_communication' => '2026-11-05 12:00:00',
            'source_derniere_communication' => 'ADMS',
            'frequence_adms_secondes' => 60,
        ];

        self::assertSame('ONLINE', CapteurPresenceService::status(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:03:00')
        ));
        self::assertSame('OFFLINE', CapteurPresenceService::status(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:03:01')
        ));
    }

    public function testServerPollingStatusUsesConfiguredScannerInterval(): void
    {
        $sensor = [
            'statut' => 'ACTIF',
            'derniere_communication' => '2026-11-05 12:00:00',
            'source_derniere_communication' => 'SERVER_POLL',
            'frequence_polling_secondes' => 5,
        ];

        self::assertSame('ONLINE', CapteurPresenceService::status(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:00:15')
        ));
        self::assertSame('OFFLINE', CapteurPresenceService::status(
            $sensor,
            new DateTimeImmutable('2026-11-05 12:00:16')
        ));
    }
}
