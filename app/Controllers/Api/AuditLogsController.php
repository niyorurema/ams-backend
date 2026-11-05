<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

final class AuditLogsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        if (! (bool) ($claims->is_super_admin ?? false) && (string) $claims->role !== 'admin') {
            return $this->errorResponse('La consultation du journal est réservée aux administrateurs.', 403);
        }

        $from = (string) ($this->request->getGet('date_debut') ?? '');
        $to = (string) ($this->request->getGet('date_fin') ?? '');
        if (($from !== '' && ! $this->validDate($from))
            || ($to !== '' && ! $this->validDate($to))
            || ($from !== '' && $to !== '' && $from > $to)
        ) {
            return $this->errorResponse('La plage de dates est invalide.', 422);
        }

        $action = strtoupper(trim((string) ($this->request->getGet('action') ?? '')));
        if ($action !== '' && ! in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            return $this->errorResponse('Action d’audit invalide.', 422);
        }
        $objectType = trim((string) ($this->request->getGet('object_type') ?? ''));
        if ($objectType !== '' && preg_match('/^[A-Za-z0-9_]{1,128}$/', $objectType) !== 1) {
            return $this->errorResponse('Type d’objet invalide.', 422);
        }
        $actorId = $this->optionalPositiveInt($this->request->getGet('actor_id'));
        $requestedTenantId = $this->optionalPositiveInt($this->request->getGet('tenant_id'));
        $deviceId = $this->optionalPositiveInt($this->request->getGet('device_id'));
        $requestId = strtolower(trim((string) ($this->request->getGet('request_id') ?? '')));
        $objectId = trim((string) ($this->request->getGet('object_id') ?? ''));
        if ($actorId === false || $requestedTenantId === false || $deviceId === false) {
            return $this->errorResponse('Identifiant d’audit invalide.', 422);
        }
        if (($requestId !== '' && preg_match('/^[a-f0-9]{32}$/', $requestId) !== 1)
            || mb_strlen($objectId) > 512
        ) {
            return $this->errorResponse('Identifiant de requête ou d’objet invalide.', 422);
        }
        if (! (bool) ($claims->is_super_admin ?? false)
            && $requestedTenantId !== null
            && $requestedTenantId !== (int) $claims->tenant_id
        ) {
            return $this->errorResponse('Accès interdit à ce tenant.', 403);
        }

        $limit = $this->boundedInt($this->request->getGet('limit'), 50, 1, 100);
        $offset = $this->boundedInt($this->request->getGet('offset'), 0, 0, 1000000);
        $db = Database::connect();
        $query = $db->table('audit_logs a')
            ->select('a.id, a.tenant_id, t.name AS tenant_name, a.actor_id, u.nom_complet AS actor_name, a.action, a.object_type, a.object_id, '
                . 'a.occurred_at, a.before_values, a.after_values, a.ip_address, a.user_agent, a.request_id, a.source, a.device_id')
            ->join('users u', 'u.id = a.actor_id AND u.tenant_id = a.tenant_id', 'left')
            ->join('tenants t', 't.id = a.tenant_id', 'left');

        if (! (bool) ($claims->is_super_admin ?? false)) {
            $query->where('a.tenant_id', (int) $claims->tenant_id);
        } elseif ($requestedTenantId !== null) {
            $query->where('a.tenant_id', $requestedTenantId);
        }
        if ($from !== '') {
            $query->where('a.occurred_at >=', $from . ' 00:00:00');
        }
        if ($to !== '') {
            $query->where('a.occurred_at <=', $to . ' 23:59:59');
        }
        if ($action !== '') {
            $query->where('a.action', $action);
        }
        if ($objectType !== '') {
            $query->where('a.object_type', $objectType);
        }
        if ($actorId !== null) {
            $query->where('a.actor_id', $actorId);
        }
        if ($deviceId !== null) {
            $query->where('a.device_id', $deviceId);
        }
        if ($requestId !== '') {
            $query->where('a.request_id', $requestId);
        }
        if ($objectId !== '') {
            $query->where('a.object_id', $objectId);
        }

        $total = (clone $query)->countAllResults();
        $items = $query->orderBy('a.occurred_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();
        foreach ($items as &$item) {
            foreach (['before_values', 'after_values'] as $field) {
                if ($item[$field] !== null) {
                    $decoded = json_decode((string) $item[$field], true);
                    $item[$field] = is_array($decoded) ? $decoded : null;
                }
            }
        }
        unset($item);

        return $this->successResponse([
            'items' => $items,
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ]);
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function optionalPositiveInt(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed !== false && $parsed > 0 ? $parsed : false;
    }

    private function boundedInt(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? $default : max($minimum, min($maximum, (int) $parsed));
    }
}
