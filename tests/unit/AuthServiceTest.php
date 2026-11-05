<?php

use App\Services\AuthService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class AuthServiceTest extends CIUnitTestCase
{
    public function testLeModeHybrideBasculeVersLocalApresEchecLdap(): void
    {
        $service = new class extends AuthService {
            public array $ldapResult = ['success' => false];
            public array $localResult = ['success' => true, 'auth_source' => 'LOCAL'];
            public int $localCalls = 0;

            public function __construct() {}
            public function loginLdap(int $tenantId, string $email, string $password, array $options = []): array { return $this->ldapResult; }
            public function loginLocal(int $tenantId, string $email, string $password, array $options = []): array
            {
                $this->localCalls++;
                return $this->localResult;
            }
            public function loginHybrideForTest(int $tenantId, string $email, string $password): array
            {
                return $this->loginHybride($tenantId, $email, $password);
            }
        };

        $result = $service->loginHybrideForTest(1, 'admin@example.test', 'password');

        $this->assertTrue($result['success']);
        $this->assertSame('LOCAL', $result['auth_source']);
        $this->assertSame(1, $service->localCalls);
    }

    public function testLeModeHybrideNeBasculePasVersLocalApresSuccesLdap(): void
    {
        $service = new class extends AuthService {
            public array $ldapResult = ['success' => true, 'auth_source' => 'LDAP'];
            public int $localCalls = 0;

            public function __construct() {}
            public function loginLdap(int $tenantId, string $email, string $password, array $options = []): array { return $this->ldapResult; }
            public function loginLocal(int $tenantId, string $email, string $password, array $options = []): array
            {
                $this->localCalls++;
                return ['success' => false];
            }
            public function loginHybrideForTest(int $tenantId, string $email, string $password): array
            {
                return $this->loginHybride($tenantId, $email, $password);
            }
        };

        $result = $service->loginHybrideForTest(1, 'user@example.test', 'password');

        $this->assertTrue($result['success']);
        $this->assertSame('LDAP', $result['auth_source']);
        $this->assertSame(0, $service->localCalls);
    }
}
