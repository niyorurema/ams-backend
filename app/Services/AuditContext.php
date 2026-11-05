<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RequestInterface;
use Config\Database;

final class AuditContext
{
    public static function apply(
        ?int $actorId,
        ?int $tenantId,
        string $source,
        ?int $deviceId,
        string $requestId,
        ?string $ipAddress,
        ?string $userAgent,
        ?BaseConnection $db = null
    ): void {
        $db ??= Database::connect();
        if (! self::usesMySql($db->DBDriver)) {
            return;
        }

        if (! in_array($source, ['API', 'DEVICE', 'SYSTEM'], true)) {
            throw new \InvalidArgumentException('Source d’audit invalide.');
        }

        $db->query(
            'SET @ams_audit_actor_id = ?, @ams_audit_tenant_id = ?, @ams_audit_source = ?, '
            . '@ams_audit_device_id = ?, @ams_audit_request_id = ?, @ams_audit_ip = ?, @ams_audit_user_agent = ?',
            [
                $actorId,
                $tenantId,
                $source,
                $deviceId,
                $requestId,
                $ipAddress === null ? null : mb_substr($ipAddress, 0, 45),
                $userAgent === null ? null : mb_substr($userAgent, 0, 1024),
            ]
        );
    }

    public static function usesMySql(string $driver): bool
    {
        return strcasecmp(substr($driver, -6), 'MySQLi') === 0;
    }

    public static function requestMetadata(RequestInterface $request): array
    {
        return [
            'ip_address' => $request->getIPAddress() ?: null,
            'user_agent' => $request->getUserAgent()?->getAgentString() ?: null,
        ];
    }
}
