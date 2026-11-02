<?php

namespace App\Controllers\Api;

use App\Models\CapteurModel;
use App\Services\AffectationService;
use App\Services\ZKTecoService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Endpoints API de consultation des capteurs.
 */
class CapteursController extends BaseApiController
{
    public function index(?int $requestedTenantId = null): ResponseInterface
    {
        $tenantId = $this->tenantId($requestedTenantId);
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $requestedLimit = filter_var($this->request->getGet('limit') ?? 10, FILTER_VALIDATE_INT);
        $requestedOffset = filter_var($this->request->getGet('offset') ?? 0, FILTER_VALIDATE_INT);
        $limit = $requestedLimit === false ? 10 : max(1, min(1000, $requestedLimit));
        $offset = $requestedOffset === false ? 0 : max(0, $requestedOffset);

        $query = (new CapteurModel())
            ->select($this->champsPublics())
            ->where('tenant_id', $tenantId)
            ->orderBy('nom', 'ASC');
        $statuts = $this->request->getGet('statut');
        if ($statuts !== null && $statuts !== '') {
            if (is_array($statuts) && array_filter($statuts, 'is_string') !== $statuts) {
                return $this->errorResponse('Filtre de statut invalide.', 400);
            }
            $statuts = is_array($statuts) ? $statuts : explode(',', (string) $statuts);
            $statuts = array_values(array_unique(array_filter(array_map('trim', $statuts), static fn (string $statut): bool => $statut !== '')));
            if ($statuts === [] || array_diff($statuts, ['ACTIF', 'INACTIF', 'HORS_LIGNE']) !== []) {
                return $this->errorResponse('Filtre de statut invalide.', 400);
            }
            $query->whereIn('statut', $statuts);
        }
        $countQuery = (new CapteurModel())->where('tenant_id', $tenantId);
        if ($statuts !== null && $statuts !== '') {
            $countQuery->whereIn('statut', $statuts);
        }
        $total = $countQuery->countAllResults();
        $capteurs = $query->findAll($limit, $offset);

        return $this->successResponse([
            'items' => $capteurs,
            'total' => $total,
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $capteur = $this->trouverCapteur($id);
        return $capteur === null
            ? $this->errorResponse('Capteur introuvable.', 404)
            : $this->successResponse($capteur);
    }

    public function create(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $input = $this->jsonInput();
        $tenantId = $this->tenantId(isset($input['tenant_id']) ? (int) $input['tenant_id'] : null);
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $data = array_intersect_key($input, array_flip([
            'nom', 'numero_serie', 'adresse_ip', 'port', 'type_reseau', 'com_key', 'timeout_secondes', 'statut', 'notes',
        ]));
        $data['tenant_id'] = $tenantId;
        $data['port'] = $data['port'] ?? 4370;
        $data['type_reseau'] = $data['type_reseau'] ?? 'LAN';
        $data['statut'] = $data['statut'] ?? 'ACTIF';
        $data['com_key'] = array_key_exists('com_key', $data) && trim((string) $data['com_key']) !== ''
            ? (string) $data['com_key']
            : '0';
        $data['timeout_secondes'] = $data['timeout_secondes'] ?? 60;
        $model = new CapteurModel();
        if (! $model->insert($data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($this->trouverCapteur((int) $model->getInsertID()), 'Capteur créé.', 201);
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        $data = array_intersect_key($this->jsonInput(), array_flip([
            'nom', 'numero_serie', 'adresse_ip', 'port', 'type_reseau', 'com_key', 'timeout_secondes', 'notes', 'statut',
        ]));
        if ($data === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }

        $model = new CapteurModel();
        if (! $model->update($id, $data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($this->trouverCapteur($id), 'Capteur mis à jour.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        (new CapteurModel())->delete($id);
        return $this->successResponse(null, 'Capteur supprimé.');
    }

    public function deviceInfo(int $id): ResponseInterface
    {
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            return $this->successResponse((new ZKTecoService())->getDeviceInfo($id));
        } catch (Throwable $e) {
            log_message('error', 'Lecture informations capteur #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de joindre le capteur.', 502);
        }
    }

    public function test(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            $info = (new ZKTecoService())->testConnection($id);

            return $this->successResponse($info, 'Le capteur répond.');
        } catch (Throwable $e) {
            log_message('warning', 'Test de connexion du capteur #{id} échoué : {error}', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse('Capteur injoignable. Vérifiez son alimentation et sa connexion réseau.', 502);
        }
    }

    public function restart(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            (new ZKTecoService())->restartDevice($id);

            return $this->successResponse(['capteur_id' => $id], 'Commande de redémarrage envoyée.');
        } catch (Throwable $e) {
            log_message('error', 'Redémarrage du capteur #{id} échoué : {error}', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse(
                'Impossible de redémarrer le capteur. S’il est hors tension ou injoignable, une intervention physique peut être nécessaire.',
                502
            );
        }
    }

    public function enable(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            (new ZKTecoService())->enableDevice($id);

            return $this->successResponse(['capteur_id' => $id], 'Capteur activé.');
        } catch (Throwable $e) {
            log_message('error', 'Activation du capteur #{id} échouée : {error}', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse('Impossible d’activer le capteur. Vérifiez son alimentation et sa connexion réseau.', 502);
        }
    }

    public function syncUsers(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            return $this->successResponse((new ZKTecoService())->syncEmployes($id), 'Synchronisation des utilisateurs terminée.');
        } catch (Throwable $e) {
            log_message('error', 'Sync utilisateurs capteur #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('La synchronisation du capteur a échoué.', 502);
        }
    }

    public function syncAttendance(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            return $this->successResponse((new ZKTecoService())->syncPointages($id), 'Synchronisation des pointages terminée.');
        } catch (Throwable $e) {
            log_message('error', 'Sync pointages capteur #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('La synchronisation du capteur a échoué.', 502);
        }
    }

    public function syncAssignments(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverCapteur($id) === null) {
            return $this->errorResponse('Capteur introuvable.', 404);
        }

        try {
            $resultat = (new AffectationService())->syncAffectationsCapteur($id);
            return ($resultat['success'] ?? false)
                ? $this->successResponse($resultat, 'Affectations synchronisées sur le capteur.')
                : $this->errorResponse($resultat['message'] ?? 'Échec de la synchronisation des affectations.', 502);
        } catch (Throwable $e) {
            log_message('error', 'Sync affectations capteur #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de synchroniser les affectations.', 502);
        }
    }

    /** @return ?array<string, mixed> */
    private function trouverCapteur(int $id): ?array
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return null;
        }

        return (new CapteurModel())
            ->select($this->champsPublics())
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    /** @return array<int, string> */
    private function champsPublics(): array
    {
        return [
            'id', 'tenant_id', 'nom', 'numero_serie', 'adresse_ip', 'port', 'type_reseau',
            'firmware', 'platform', 'statut', 'dernier_scan', 'derniere_sync_users',
            'derniere_sync_attendance', 'timeout_secondes', 'notes',
        ];
    }
}