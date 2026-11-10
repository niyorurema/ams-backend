<?php

use App\Services\RealtimeService;
use CodeIgniter\Test\CIUnitTestCase;
use Predis\Client;

/**
 * @internal
 */
final class RealtimeServiceTest extends CIUnitTestCase
{
    public function testPublishesPointageOnTenantScopedStream(): void
    {
        $redis = $this->createMock(Client::class);
        $arguments = null;
        $redis->expects($this->once())
            ->method('executeRaw')
            ->willReturnCallback(static function (array $command) use (&$arguments): string {
                $arguments = $command;
                return '1000-1';
            });

        $id = (new RealtimeService($redis))->publierPointage([
            'id' => 42,
            'tenant_id' => 7,
            'employe_id' => 15,
            'employe_nom' => 'Employé Test',
            'employe_matricule' => '11514',
            'badge_id' => 45779,
            'date_pointage' => '2026-10-06 09:00:00',
            'type_evenement' => 'CHECK_IN',
            'statut_calcul' => 'VALIDE',
            'capteur_id' => 3,
            'raw_data' => 'ne doit pas être diffusé',
        ]);

        $this->assertSame('1000-1', $id);
        $this->assertSame('XADD', $arguments[0]);
        $this->assertSame('ams:tenant:7:pointages', $arguments[1]);
        $payload = json_decode($arguments[7], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('nouveau_pointage', $payload['type']);
        $this->assertSame(42, $payload['data']['id']);
        $this->assertSame('11514', $payload['data']['employe_matricule']);
        $this->assertSame(45779, $payload['data']['badge_id']);
        $this->assertArrayNotHasKey('raw_data', $payload['data']);
    }

    public function testRejectsSentinelYearAttendanceBeforePublishing(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->never())->method('executeRaw');

        $this->expectException(InvalidArgumentException::class);
        (new RealtimeService($redis))->publierPointage([
            'id' => 43,
            'tenant_id' => 7,
            'date_pointage' => '2000-01-01 09:06:08',
            'type_evenement' => 'CHECK_IN',
        ]);
    }

    public function testRejectsFarFutureAttendanceBeforePublishing(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->never())->method('executeRaw');

        $this->expectException(InvalidArgumentException::class);
        (new RealtimeService($redis))->publierPointage([
            'id' => 44,
            'tenant_id' => 7,
            'date_pointage' => '2103-08-18 06:43:39',
            'type_evenement' => 'CHECK_IN',
        ]);
    }

    public function testReadsEventsAfterCursorForOnlyTheRequestedTenant(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->once())
            ->method('executeRaw')
            ->with([
                'XREAD',
                'BLOCK',
                '1000',
                'COUNT',
                '50',
                'STREAMS',
                'ams:tenant:9:pointages',
                '1000-0',
            ])
            ->willReturn([[
                'ams:tenant:9:pointages',
                [['1000-1', ['data', '{"type":"nouveau_pointage","data":{"id":12}}']]],
            ]]);

        $events = (new RealtimeService($redis))->lirePointagesDepuis(9, '1000-0');

        $this->assertCount(1, $events);
        $this->assertSame('1000-1', $events[0]['id']);
        $this->assertSame(12, $events[0]['payload']['data']['id']);
    }

    public function testCapsBlockingReadDuration(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->once())
            ->method('executeRaw')
            ->with($this->callback(static fn (array $command): bool => $command[2] === '5000'))
            ->willReturn(null);

        $events = (new RealtimeService($redis))->lirePointagesDepuis(9, '1000-0', 10000);

        $this->assertSame([], $events);
    }

    public function testRejectsInvalidStreamCursor(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->expects($this->never())->method('executeRaw');

        $this->expectException(InvalidArgumentException::class);
        (new RealtimeService($redis))->lirePointagesDepuis(1, 'not-a-stream-id');
    }
}
