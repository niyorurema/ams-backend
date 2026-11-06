<?php

namespace App\Controllers\Api;

use App\Services\AbsenceImportService;
use App\Services\AbsenceErpContract;
use App\Services\AbsenceManagementService;
use App\Services\AbsenceStatusMappingService;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

final class AbsencesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $debut = trim((string) $this->request->getGet('date_debut'));
        $fin = trim((string) $this->request->getGet('date_fin'));
        $statut = trim((string) $this->request->getGet('statut'));
        $source = trim((string) $this->request->getGet('source'));
        $matricule = trim((string) $this->request->getGet('matricule'));
        $numeroDemande = trim((string) $this->request->getGet('numero_demande'));
        $limit = filter_var($this->request->getGet('limit') ?? 10, FILTER_VALIDATE_INT);
        $offset = filter_var($this->request->getGet('offset') ?? 0, FILTER_VALIDATE_INT);
        if (($debut !== '' && ! $this->dateValide($debut))
            || ($fin !== '' && ! $this->dateValide($fin))
            || ($debut !== '' && $fin !== '' && $debut > $fin)
            || ($statut !== '' && ! in_array($statut, ['PENDING', 'MANAGER_APPROVED', 'APPROVED', 'REJECTED', 'CANCELLED'], true))
            || ($source !== '' && ! in_array($source, ['AMS', 'OBR'], true))
            || mb_strlen($matricule) > 100 || mb_strlen($numeroDemande) > 100
            || $limit === false || $limit < 1 || $limit > 500 || $offset === false || $offset < 0
        ) {
            return $this->errorResponse('Filtres d’absence invalides.', 400);
        }

        $builder = db_connect()->table('absences a')
            ->select('a.id, a.employe_id, a.source, a.source_request_id, a.source_status_label, a.source_type_code, a.source_day_type, a.categorie, a.motif, a.date_debut, a.date_fin, a.journee_complete, a.heure_debut, a.heure_fin, a.duree_heures, a.statut, a.created_at, e.matricule, e.nom, e.prenom')
            ->join('employes e', 'e.id = a.employe_id AND e.tenant_id = a.tenant_id', 'inner')
            ->where('a.tenant_id', $tenantId);
        if ($debut !== '') {
            $builder->where('a.date_fin >=', $debut);
        }
        if ($fin !== '') {
            $builder->where('a.date_debut <=', $fin);
        }
        if ($statut !== '') {
            $builder->where('a.statut', $statut);
        }
        if ($source !== '') {
            $builder->where('a.source', $source);
        }
        if ($matricule !== '') {
            $builder->like('e.matricule', $matricule);
        }
        if ($numeroDemande !== '') {
            $builder->like('a.source_request_id', $numeroDemande);
        }

        $visibleIds = $this->visibleEmployeeIds($debut !== '' ? $debut : null);
        if ($visibleIds === []) {
            $builder->where('1 = 0');
        } elseif ($visibleIds !== null) {
            $builder->whereIn('a.employe_id', $visibleIds);
        }
        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('a.date_debut', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return $this->successResponse([
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function forecast(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $timezoneService = new \App\Services\TenantTimezoneService();
        $today = $timezoneService->now($tenantId)->format('Y-m-d');
        $debut = trim((string) ($this->request->getGet('date_debut') ?? $today));
        $fin = trim((string) ($this->request->getGet('date_fin') ?? (new DateTimeImmutable($today))->modify('+89 days')->format('Y-m-d')));
        if (! $this->dateValide($debut)
            || ! $this->dateValide($fin)
            || $debut < $today
            || $debut > $fin
            || (new DateTimeImmutable($debut))->diff(new DateTimeImmutable($fin))->days > 89
        ) {
            return $this->errorResponse('La prévision doit couvrir une période future valide de 1 à 90 jours.', 400);
        }

        $visibleIds = $this->visibleEmployeeIds($debut);
        try {
            $forecast = (new AbsenceManagementService())->prevoirCongesObr(
                $tenantId,
                $debut,
                $fin,
                $visibleIds
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        } catch (Throwable $exception) {
            log_message('error', 'Prévision des congés ERP impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible de charger la prévision des congés ERP.', 500);
        }

        return $this->successResponse($forecast);
    }

    public function categories(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        return $this->successResponse((new AbsenceManagementService())->getCategories($tenantId, true));
    }

    public function statusMappings(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        return $this->successResponse((new AbsenceStatusMappingService())->getMappings($tenantId));
    }

    public function saveStatusMapping(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }
        $input = $this->jsonInput();
        if (count($input) !== 3
            || ! isset($input['source_code'], $input['source_label'], $input['absence_status'])
            || ! is_string($input['source_code'])
            || ! is_string($input['source_label'])
            || ! is_string($input['absence_status'])
        ) {
            return $this->errorResponse('Envoyez le code, le libellé et le statut AMS du mapping.', 400);
        }

        try {
            $id = (new AbsenceStatusMappingService())->save(
                $tenantId,
                $input['source_code'],
                $input['source_label'],
                $input['absence_status'],
                (int) $user['id']
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Enregistrement du mapping de statut ERP impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’enregistrer le mapping de statut ERP.', 500);
        }

        return $this->successResponse(['id' => $id], 'Mapping de statut enregistré.');
    }

    public function deleteStatusMapping(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            (new AbsenceStatusMappingService())->delete($tenantId, $id);
        } catch (\OutOfBoundsException $exception) {
            return $this->errorResponse($exception->getMessage(), 404);
        } catch (Throwable $exception) {
            log_message('error', 'Suppression du mapping de statut ERP impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible de supprimer le mapping de statut ERP.', 500);
        }

        return $this->successResponse(['id' => $id], 'Mapping de statut supprimé.');
    }

    public function erpContract(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }

        return $this->successResponse([
            'version' => 1,
            'direct_sync' => [
                'enabled' => false,
                'status' => 'DISABLED',
                'message' => 'La synchronisation directe reste désactivée tant que le connecteur ERP et son contrat technique ne sont pas configurés.',
            ],
            'fields' => AbsenceErpContract::apiFields(),
        ]);
    }

    public function importTemplate(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }

        try {
            $workbook = AbsenceErpContract::createManualTemplate();
            ob_start();
            (new Xlsx($workbook))->save('php://output');
            $content = ob_get_clean();
            $workbook->disconnectWorksheets();
            if (! is_string($content)) {
                throw new \RuntimeException('Impossible de générer le modèle Excel.');
            }

            return $this->response
                ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="modele_import_conges_ams.xlsx"')
                ->setHeader('Cache-Control', 'no-store')
                ->setBody($content);
        } catch (Throwable $exception) {
            log_message('error', 'Génération du modèle Excel de congés impossible : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible de générer le modèle Excel de congés.', 500);
        }
    }

    public function createCategory(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            $id = (new AbsenceManagementService())->createCategory(
                $tenantId,
                (string) ($this->jsonInput()['nom'] ?? '')
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        }

        return $this->successResponse(['id' => $id], 'Catégorie créée.', 201);
    }

    public function updateCategory(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $service = new AbsenceManagementService();
        try {
            if (array_key_exists('actif', $input)) {
                $active = filter_var($input['actif'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($active === null || array_key_exists('nom', $input)) {
                    return $this->errorResponse('Les informations de catégorie sont invalides.', 400);
                }
                $service->setCategoryActive($tenantId, $id, $active);
            } else {
                $service->updateCategory($tenantId, $id, (string) ($input['nom'] ?? ''));
            }
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 400);
        } catch (\OutOfBoundsException $exception) {
            return $this->errorResponse($exception->getMessage(), 404);
        }

        return $this->successResponse(['id' => $id], 'Catégorie modifiée.');
    }

    public function deleteCategory(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            $result = (new AbsenceManagementService())->deleteCategory($tenantId, $id);
        } catch (\OutOfBoundsException $exception) {
            return $this->errorResponse($exception->getMessage(), 404);
        }

        return $this->successResponse($result, $result['deleted']
            ? 'Catégorie supprimée.'
            : ($result['used']
                ? 'Catégorie désactivée car elle est utilisée par des demandes existantes.'
                : 'Une catégorie système ne peut pas être supprimée; elle a été désactivée.'));
    }

    public function settings(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        return $this->successResponse((new AbsenceManagementService())->getSettings($tenantId));
    }

    public function updateSettings(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }

        $workflow = strtoupper(trim((string) ($this->jsonInput()['workflow'] ?? '')));
        if (! in_array($workflow, ['RH_ONLY', 'MANAGER_THEN_RH', 'SUPERVISOR_ONLY'], true)) {
            return $this->errorResponse('Circuit de validation invalide.', 400);
        }
        (new AbsenceManagementService())->saveWorkflow($tenantId, $workflow, (int) $user['id']);

        return $this->successResponse(['workflow' => $workflow], 'Circuit de validation mis à jour.');
    }

    public function createImpediment(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $employeId = filter_var($input['employe_id'] ?? $user['employe_id'] ?? null, FILTER_VALIDATE_INT);
        $debut = trim((string) ($input['date_debut'] ?? ''));
        $fin = trim((string) ($input['date_fin'] ?? ''));
        $journeeComplete = filter_var($input['journee_complete'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $heureDebut = trim((string) ($input['heure_debut'] ?? ''));
        $heureFin = trim((string) ($input['heure_fin'] ?? ''));
        $categorie = strtoupper(trim((string) ($input['categorie'] ?? '')));
        $motif = trim((string) ($input['motif'] ?? ''));

        if ($employeId === false || $employeId < 1
            || ! $this->dateValide($debut) || ! $this->dateValide($fin) || $debut > $fin
            || $journeeComplete === null
            || ! (new AbsenceManagementService())->categoryIsActive($tenantId, $categorie)
            || mb_strlen($motif) > 500
        ) {
            return $this->errorResponse('Les informations de l’empêchement sont invalides.', 400);
        }
        $days = (new DateTimeImmutable($debut))->diff(new DateTimeImmutable($fin))->days;
        if ($days > 365) {
            return $this->errorResponse('La période d’empêchement ne peut dépasser 366 jours.', 400);
        }
        if (! $journeeComplete && ($debut !== $fin
            || ! $this->heureValide($heureDebut)
            || ! $this->heureValide($heureFin)
            || $heureDebut >= $heureFin
        )) {
            return $this->errorResponse('Un empêchement partiel doit couvrir une plage horaire valide d’une seule journée.', 400);
        }
        if ($journeeComplete) {
            $heureDebut = '';
            $heureFin = '';
        }

        $visibleIds = $this->visibleEmployeeIds($debut);
        $isOwnEmployee = ! empty($user['employe_id']) && (int) $user['employe_id'] === $employeId;
        if (! $this->canManage()
            && ! $isOwnEmployee
            && ($visibleIds === null || ! in_array($employeId, $visibleIds ?? [], true))
        ) {
            return $this->errorResponse('Vous ne pouvez signaler un empêchement que pour vous-même ou un employé visible.', 403);
        }
        if (! $this->employeExiste($tenantId, $employeId)) {
            return $this->errorResponse('Employé introuvable dans ce tenant.', 404);
        }

        $overlap = db_connect()->table('absences')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->whereIn('statut', ['PENDING', 'MANAGER_APPROVED', 'APPROVED'])
            ->where('date_debut <=', $fin)
            ->where('date_fin >=', $debut)
            ->get()
            ->getRowArray();
        if ($overlap !== null) {
            return $this->errorResponse('Une absence existe déjà sur tout ou partie de cette période; vérifiez-la avant de créer un doublon.', 409);
        }

        $now = date('Y-m-d H:i:s');
        $db = db_connect();
        $db->transBegin();
        try {
            $db->table('absences')->insert([
                'tenant_id' => $tenantId,
                'employe_id' => $employeId,
                'source' => 'AMS',
                'source_request_id' => 'AMS-' . bin2hex(random_bytes(16)),
                'source_status_code' => null,
                'source_status_label' => null,
                'source_type_code' => null,
                'categorie' => $categorie,
                'motif' => $motif !== '' ? $motif : null,
                'date_debut' => $debut,
                'date_fin' => $fin,
                'journee_complete' => $journeeComplete ? 1 : 0,
                'heure_debut' => $heureDebut !== '' ? $heureDebut . ':00' : null,
                'heure_fin' => $heureFin !== '' ? $heureFin . ':00' : null,
                'duree_heures' => $journeeComplete
                    ? null
                    : (new DateTimeImmutable($fin . ' ' . $heureDebut))->diff(new DateTimeImmutable($fin . ' ' . $heureFin))->h
                        + ((int) (new DateTimeImmutable($fin . ' ' . $heureFin))->format('i') - (int) (new DateTimeImmutable($fin . ' ' . $heureDebut))->format('i')) / 60,
                'statut' => 'PENDING',
                'created_by' => (int) $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $absenceId = (int) $db->insertID();
            if ($absenceId < 1 || $db->transStatus() === false) {
                throw new \RuntimeException('Impossible d’enregistrer l’empêchement.');
            }
            (new AbsenceManagementService($db))->enregistrerHistorique(
                $tenantId,
                $absenceId,
                (int) $user['id'],
                'CREATED',
                null,
                'PENDING'
            );
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Impossible d’enregistrer l’historique de l’empêchement.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Création d’empêchement échouée : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’enregistrer l’empêchement.', 500);
        }
        (new AbsenceManagementService($db))->recalculerJours($tenantId, []);

        return $this->successResponse(['id' => $absenceId, 'statut' => 'PENDING'], 'Empêchement soumis à validation.', 201);
    }

    public function approve(int $id): ResponseInterface
    {
        return $this->decide($id, true);
    }

    public function reject(int $id): ResponseInterface
    {
        return $this->decide($id, false);
    }

    public function approveBatch(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $rawIds = $input['ids'] ?? null;
        if (! is_array($rawIds) || $rawIds === [] || count($rawIds) > 100) {
            return $this->errorResponse('Sélectionnez entre 1 et 100 demandes à valider.', 400);
        }
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                return $this->errorResponse('La sélection contient un identifiant invalide.', 400);
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) !== count($rawIds)) {
            return $this->errorResponse('La sélection contient des demandes en double.', 400);
        }

        $workflow = (new AbsenceManagementService())->getSettings($tenantId)['workflow'];
        $absences = db_connect()->table('absences')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->getResultArray();
        if (count($absences) !== count($ids)) {
            return $this->errorResponse('Une ou plusieurs demandes sont introuvables ou ne sont plus accessibles.', 409);
        }
        foreach ($absences as $absence) {
            if (! $this->canApproveAbsence($absence, $user, $workflow)) {
                return $this->errorResponse(
                    'La validation groupée est refusée : chaque demande doit correspondre à votre rôle, périmètre et étape du circuit.',
                    409
                );
            }
        }

        $db = db_connect();
        $db->transBegin();
        $affectedDays = [];
        try {
            foreach ($absences as $absence) {
                $oldStatus = (string) $absence['statut'];
                $isManagerStep = in_array($workflow, ['MANAGER_THEN_RH', 'SUPERVISOR_ONLY'], true)
                    && ! $this->canManage();
                $newStatus = $isManagerStep && $workflow === 'MANAGER_THEN_RH'
                    ? 'MANAGER_APPROVED'
                    : 'APPROVED';
                $updates = [
                    'statut' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($newStatus === 'MANAGER_APPROVED') {
                    $updates['manager_approved_by'] = (int) $user['id'];
                    $updates['manager_approved_at'] = date('Y-m-d H:i:s');
                } elseif ($isManagerStep) {
                    $updates['manager_approved_by'] = (int) $user['id'];
                    $updates['manager_approved_at'] = date('Y-m-d H:i:s');
                } else {
                    $updates['rh_approved_by'] = (int) $user['id'];
                    $updates['rh_approved_at'] = date('Y-m-d H:i:s');
                }
                if ($newStatus === 'APPROVED') {
                    $date = new DateTimeImmutable((string) $absence['date_debut']);
                    $end = new DateTimeImmutable((string) $absence['date_fin']);
                    while ($date <= $end) {
                        $affectedDays[] = [
                            'employe_id' => (int) $absence['employe_id'],
                            'date' => $date->format('Y-m-d'),
                        ];
                        $date = $date->modify('+1 day');
                    }
                }

                $db->table('absences')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $absence['id'])
                    ->where('statut', $oldStatus)
                    ->update($updates);
                if ($db->transStatus() === false) {
                    throw new \RuntimeException('Échec de mise à jour pendant la validation groupée.');
                }
                if ($db->affectedRows() !== 1) {
                    throw new \DomainException('Une demande a changé pendant la validation groupée.');
                }
                (new AbsenceManagementService($db))->enregistrerHistorique(
                    $tenantId,
                    (int) $absence['id'],
                    (int) $user['id'],
                    'APPROVED',
                    $oldStatus,
                    $newStatus
                );
                if ($db->transStatus() === false) {
                    throw new \RuntimeException('Impossible d’enregistrer l’historique de validation.');
                }
            }
            if (! $db->transCommit()) {
                throw new \RuntimeException('Impossible de confirmer la validation groupée.');
            }
        } catch (\DomainException $exception) {
            $db->transRollback();
            return $this->errorResponse('Une demande a changé pendant la validation groupée; aucune n’a été validée. Rechargez la liste.', 409);
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Validation groupée des empêchements échouée : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Échec technique de la validation groupée; aucune demande n’a été validée.', 500);
        }

        try {
            (new AbsenceManagementService($db))->recalculerJours($tenantId, $affectedDays);
        } catch (Throwable $exception) {
            log_message('error', 'Décisions groupées enregistrées mais recalcul quotidien échoué : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse(
                'Les décisions sont enregistrées, mais le recalcul des présences a échoué. Relancez le recalcul.',
                500
            );
        }

        return $this->successResponse([
            'validees' => count($ids),
            'statut' => $workflow === 'MANAGER_THEN_RH' && ! $this->canManage()
                ? 'MANAGER_APPROVED'
                : 'APPROVED',
        ], 'Validation groupée enregistrée.');
    }

    public function cancel(int $id): ResponseInterface
    {
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }
        $absence = $this->findAbsence($tenantId, $id);
        if ($absence === null) {
            return $this->errorResponse('Absence introuvable.', 404);
        }
        if ($absence['source'] === 'OBR') {
            return $this->errorResponse('Un congé OBR ne peut être annulé que par le prochain import officiel.', 409);
        }
        $isCreator = (int) ($absence['created_by'] ?? 0) === (int) $user['id'];
        $isOwn = ! empty($user['employe_id']) && (int) $absence['employe_id'] === (int) $user['employe_id'];
        if (! $this->canManage() && ! $isCreator && ! $isOwn) {
            return $this->errorResponse('Vous ne pouvez pas annuler cet empêchement.', 403);
        }
        if (! in_array($absence['statut'], ['PENDING', 'MANAGER_APPROVED', 'APPROVED'], true)) {
            return $this->errorResponse('Cet empêchement ne peut plus être annulé.', 409);
        }

        $ancienStatut = (string) $absence['statut'];
        $db = db_connect();
        $db->transBegin();
        try {
            $db->table('absences')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('statut', $ancienStatut)
                ->update(['statut' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($db->affectedRows() !== 1 || $db->transStatus() === false) {
                $db->transRollback();
                return $this->errorResponse('Cet empêchement a été modifié; rechargez la liste avant de réessayer.', 409);
            }
            (new AbsenceManagementService($db))->enregistrerHistorique(
                $tenantId,
                $id,
                (int) $user['id'],
                'CANCELLED',
                $ancienStatut,
                'CANCELLED'
            );
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Impossible d’enregistrer l’historique de l’annulation.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Annulation d’empêchement échouée : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’annuler cet empêchement.', 500);
        }
        $service = new AbsenceManagementService($db);
        try {
            if ($ancienStatut === 'APPROVED') {
                $this->recalculer($service, $tenantId, $absence);
            } else {
                $service->recalculerJours($tenantId, []);
            }
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 500);
        }

        return $this->successResponse(['id' => $id, 'statut' => 'CANCELLED'], 'Empêchement annulé.');
    }

    public function importPreview(): ResponseInterface
    {
        return $this->import(false);
    }

    public function importCommit(): ResponseInterface
    {
        return $this->import(true);
    }

    private function import(bool $commit): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits RH ou administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }
        $file = $this->request->getFile('fichier');
        if ($file === null) {
            return $this->errorResponse('Le fichier de congés est requis.', 400);
        }

        try {
            if (! $commit) {
                return $this->successResponse((new AbsenceImportService())->preview($file, $tenantId));
            }
            $ignorerErreurs = filter_var(
                $this->request->getPost('ignorer_erreurs') ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $resultat = (new AbsenceImportService())->importer(
                $file,
                $tenantId,
                (int) $user['id'],
                $ignorerErreurs
            );
            return $this->successResponse($resultat, 'Import des congés terminé.');
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        }
    }

    private function decide(int $id, bool $approve): ResponseInterface
    {
        $tenantId = $this->tenantId();
        $user = $this->currentUser();
        if ($tenantId === null || $user === null) {
            return $this->authorizationError();
        }
        $absence = $this->findAbsence($tenantId, $id);
        if ($absence === null) {
            return $this->errorResponse('Absence introuvable.', 404);
        }
        if ($absence['source'] === 'OBR') {
            return $this->errorResponse('Les congés OBR sont validés dans le logiciel officiel, pas dans AMS.', 409);
        }
        if (! in_array($absence['statut'], ['PENDING', 'MANAGER_APPROVED'], true)) {
            return $this->errorResponse('Cette demande a déjà été traitée.', 409);
        }

        $workflow = (new AbsenceManagementService())->getSettings($tenantId)['workflow'];
        $canManage = $this->canManage();
        $visibleIds = $this->visibleEmployeeIds((string) $absence['date_debut']);
        $isSupervisor = (string) ($user['role'] ?? '') === 'superviseur'
            && is_array($visibleIds)
            && in_array((int) $absence['employe_id'], $visibleIds, true);
        $isManager = ! $canManage
            && is_array($visibleIds)
            && in_array((int) $absence['employe_id'], $visibleIds, true)
            && in_array(
                (string) ($user['role'] ?? ''),
                ['superviseur', 'chef_service', 'directeur', 'commissaire'],
                true
            );
        if (! $canManage && ! $isManager) {
            return $this->errorResponse('Vous ne pouvez pas valider cette demande.', 403);
        }
        if ($workflow === 'RH_ONLY' && ! $canManage) {
            return $this->errorResponse('Seuls les RH ou administrateurs peuvent valider les empêchements.', 403);
        }
        if ($workflow === 'SUPERVISOR_ONLY' && ! $isSupervisor) {
            return $this->errorResponse('Seuls les superviseurs du périmètre concerné peuvent traiter cet empêchement.', 403);
        }
        if ($workflow === 'MANAGER_THEN_RH' && $approve
            && $absence['statut'] === 'PENDING' && $canManage
        ) {
            return $this->errorResponse('La validation du superviseur est requise avant la validation RH.', 409);
        }
        if ($workflow === 'MANAGER_THEN_RH' && $approve && $isManager
            && $absence['statut'] !== 'PENDING'
        ) {
            return $this->errorResponse('Cette étape a déjà été validée par le superviseur.', 409);
        }
        if (! $approve && $workflow === 'MANAGER_THEN_RH' && ! $canManage && $absence['statut'] !== 'PENDING') {
            return $this->errorResponse('Seuls les RH peuvent rejeter après validation du superviseur.', 403);
        }

        $input = $this->jsonInput();
        $commentaire = trim((string) ($input['commentaire'] ?? ''));
        if (! $approve && $commentaire === '') {
            return $this->errorResponse('Un motif de refus est obligatoire.', 400);
        }
        if (mb_strlen($commentaire) > 500) {
            return $this->errorResponse('Le commentaire dépasse 500 caractères.', 400);
        }
        $oldStatus = (string) $absence['statut'];
        if (! $approve) {
            $newStatus = 'REJECTED';
        } elseif (($workflow === 'MANAGER_THEN_RH' && $isManager)
            || ($workflow === 'SUPERVISOR_ONLY' && $isSupervisor)
        ) {
            $newStatus = $workflow === 'MANAGER_THEN_RH' ? 'MANAGER_APPROVED' : 'APPROVED';
        } else {
            $newStatus = 'APPROVED';
        }
        $updates = ['statut' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
        if ($newStatus === 'MANAGER_APPROVED' || ($newStatus === 'APPROVED' && $workflow === 'SUPERVISOR_ONLY')) {
            $updates['manager_approved_by'] = (int) $user['id'];
            $updates['manager_approved_at'] = date('Y-m-d H:i:s');
        } elseif ($newStatus === 'APPROVED') {
            $updates['rh_approved_by'] = (int) $user['id'];
            $updates['rh_approved_at'] = date('Y-m-d H:i:s');
        }
        $db = db_connect();
        $db->transBegin();
        try {
            $db->table('absences')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('statut', $oldStatus)
                ->update($updates);
            if ($db->affectedRows() !== 1 || $db->transStatus() === false) {
                $db->transRollback();
                return $this->errorResponse('Cette demande a été modifiée; rechargez la liste avant de réessayer.', 409);
            }
            (new AbsenceManagementService($db))->enregistrerHistorique(
                $tenantId,
                $id,
                (int) $user['id'],
                $approve ? 'APPROVED' : 'REJECTED',
                $oldStatus,
                $newStatus,
                $commentaire !== '' ? $commentaire : null
            );
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Impossible d’enregistrer l’historique de la décision.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Décision sur empêchement échouée : {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’enregistrer cette décision.', 500);
        }
        $service = new AbsenceManagementService($db);
        try {
            if ($oldStatus === 'APPROVED' || $newStatus === 'APPROVED') {
                $this->recalculer($service, $tenantId, $absence);
            } else {
                $service->recalculerJours($tenantId, []);
            }
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 500);
        }

        return $this->successResponse(['id' => $id, 'statut' => $newStatus], 'Décision enregistrée.');
    }

    /** @param array<string, mixed> $absence
     *  @param array<string, mixed> $user
     */
    private function canApproveAbsence(array $absence, array $user, string $workflow): bool
    {
        if ($absence['source'] !== 'AMS') {
            return false;
        }
        $canManage = $this->canManage();
        if ($workflow === 'RH_ONLY') {
            return $canManage && $absence['statut'] === 'PENDING';
        }
        if ($workflow === 'SUPERVISOR_ONLY') {
            if ((string) ($user['role'] ?? '') !== 'superviseur' || $absence['statut'] !== 'PENDING') {
                return false;
            }
            $visibleIds = $this->visibleEmployeeIds((string) $absence['date_debut']);
            return is_array($visibleIds) && in_array((int) $absence['employe_id'], $visibleIds, true);
        }
        if ($workflow !== 'MANAGER_THEN_RH') {
            return false;
        }
        if ($canManage) {
            return $absence['statut'] === 'MANAGER_APPROVED';
        }

        $visibleIds = $this->visibleEmployeeIds((string) $absence['date_debut']);

        return in_array((string) ($user['role'] ?? ''), [
            'superviseur',
            'chef_service',
            'directeur',
            'commissaire',
        ], true)
            && is_array($visibleIds)
            && in_array((int) $absence['employe_id'], $visibleIds, true)
            && $absence['statut'] === 'PENDING';
    }

    /** @param array<string, mixed> $absence */
    private function recalculer(AbsenceManagementService $service, int $tenantId, array $absence): void
    {
        $days = [];
        $date = new DateTimeImmutable((string) $absence['date_debut']);
        $end = new DateTimeImmutable((string) $absence['date_fin']);
        while ($date <= $end) {
            $days[] = ['employe_id' => (int) $absence['employe_id'], 'date' => $date->format('Y-m-d')];
            $date = $date->modify('+1 day');
        }
        try {
            $service->recalculerJours($tenantId, $days);
        } catch (Throwable $exception) {
            log_message('error', 'Absence enregistrée mais recalcul quotidien échoué : {message}', [
                'message' => $exception->getMessage(),
            ]);
            throw new \RuntimeException('La décision est enregistrée, mais le recalcul des présences a échoué. Relancez le recalcul.', 0, $exception);
        }
    }

    /** @return array<string, mixed>|null */
    private function findAbsence(int $tenantId, int $id): ?array
    {
        return db_connect()->table('absences')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();
    }

    private function employeExiste(int $tenantId, int $employeId): bool
    {
        return db_connect()->table('employes')
            ->where('id', $employeId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    private function dateValide(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && (int) $parsed->format('Y') >= 2000
            && (int) $parsed->format('Y') <= 2100;
    }

    private function heureValide(string $heure): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $heure) === 1;
    }
}
