<?php

use App\Services\PointageEmployeResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointageEmployeResolverTest extends CIUnitTestCase
{
    public function testMatriculeIsPreferredWhenBadgeBelongsToAnotherEmployee(): void
    {
        $employeeByMatricule = ['11514' => ['id' => 9, 'matricule' => '11514']];
        $employeeByBadge = ['45779' => ['id' => 2, 'matricule' => '1022']];

        $employee = (new PointageEmployeResolver())->resoudre(
            '11514',
            45779,
            $employeeByMatricule,
            $employeeByBadge
        );

        $this->assertSame(9, $employee['id']);
    }

    public function testFallsBackToBadgeWhenMatriculeDoesNotMatch(): void
    {
        $employeeByBadge = ['45779' => ['id' => 2, 'matricule' => '1022']];

        $employee = (new PointageEmployeResolver())->resoudre(
            'matricule-inconnu',
            45779,
            [],
            $employeeByBadge
        );

        $this->assertSame(2, $employee['id']);
    }

    public function testReturnsNullWhenNeitherIdentifierMatches(): void
    {
        $employee = (new PointageEmployeResolver())->resoudre('11514', 45779, [], []);

        $this->assertNull($employee);
    }

    public function testRejectedPunchResolverRequiresAnUnambiguousEmployeeInTheTenant(): void
    {
        $resolver = new PointageEmployeResolver();
        $employees = [
            ['id' => 1, 'tenant_id' => 4, 'badge_id' => 100, 'matricule' => 'A100', 'user_id_capteur' => 'device-1'],
            ['id' => 2, 'tenant_id' => 4, 'badge_id' => 100, 'matricule' => 'A101', 'user_id_capteur' => 'device-2'],
            ['id' => 3, 'tenant_id' => 5, 'badge_id' => 200, 'matricule' => 'A100', 'user_id_capteur' => 'device-1'],
        ];

        $this->assertSame(
            1,
            $resolver->resoudreUnique('device-1', 100, 4, $employees)['id']
        );
        $this->assertNull($resolver->resoudreUnique('', 100, 4, $employees));
        $this->assertNull($resolver->resoudreUnique('device-3', 200, 4, $employees));
    }
}
