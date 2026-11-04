<?php

namespace App\Controllers\Api;

use App\Models\EmployeModel;
use App\Models\PointageModel;
use App\Models\UserModel;
use App\Services\PointageReclassificationService;
use App\Services\PointageStatsService;
use App\Services\CalculPresenceService;
use App\Services\EmployeHistoryService;
use App\Services\HoraireService;
use App\Services\TenantTimezoneService;
use App\Services\PointageTypeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * API de consultation des pointages, historiques et présences calculées.
 */
class PointagesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $dateDebut = (string) $this->request->getGet('date_debut');
        $dateFin = (string) $this->request->getGet('date_fin');
        if (($dateDebut !== '' && ! $this->dateValide($dateDebut))
            || ($dateFin !== '' && ! $this->dateValide($dateFin))
            || ($dateDebut !== '' && $dateFin !== '' && $dateDebut > $dateFin)
        ) {
            return $this->errorResponse('Filtres de date invalides.', 400);
        }

        $query = Database::connect()->table('pointages p')
            ->select('p.id, p.tenant_id, COALESCE(e.id, eb.id) AS employe_id, p.badge_id, p.capteur_id, p.type_evenement, p.statut_calcul, p.date_pointage, p.date_heure_locale, p.source, p.statut, p.notes, COALESCE(e.matricule, eb.matricule) AS employe_matricule, COALESCE(e.nom, eb.nom) AS employe_nom, COALESCE(e.prenom, eb.prenom) AS employe_prenom, u.nom AS employe_unite, c.nom AS capteur_nom')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
            ->join('employes eb', 'eb.tenant_id = p.tenant_id AND eb.badge_id = p.badge_id AND eb.deleted_at IS NULL AND e.id IS NULL', 'left')
            ->join('unites_organisationnelles u', 'u.id = COALESCE(e.unite_id, eb.unite_id) AND u.tenant_id = p.tenant_id AND u.deleted_at IS NULL', 'left')
            ->join('capteurs c', 'c.id = p.capteur_id', 'left')
            ->join('tenants t', 't.id = p.tenant_id', 'left')
            ->select('t.name AS tenant_name')
            ->where('p.deleted_at', null);
        if ($tenantId !== null) {
            $query->where('p.tenant_id', $tenantId);
        }

        $search = trim((string) $this->request->getGet('search'));
        if (mb_strlen($search) > 100) {
            return $this->errorResponse('La recherche est trop longue.', 400);
        }
        if ($search !== '') {
            $query->groupStart()
                ->like('e.nom', $search)
                ->orLike('e.prenom', $search)
                ->orLike('e.matricule', $search)
                ->orLike('eb.nom', $search)
                ->orLike('eb.prenom', $search)
                ->orLike('eb.matricule', $search)
                ->orLike('c.nom', $search)
                ->groupEnd();
        }

        $visibleIds = $this->visibleEmployeeIds($dateDebut !== '' ? $dateDebut : $dateFin);
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                return $this->successResponse(['items' => [], 'pagination' => ['limit' => 10, 'offset' => 0, 'total' => 0]]);
            }
            $query->whereIn('COALESCE(e.id, eb.id)', $visibleIds);
        }

        $employeId = $this->request->getGet('employe_id');
        if ($employeId !== null) {
            if (filter_var($employeId, FILTER_VALIDATE_INT) === false || (int) $employeId < 1) {
                return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
            }
            if ($tenantId !== null && ! $this->employeAppartientAuTenant((int) $employeId, $tenantId)) {
                return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
            }
            $query->where('COALESCE(e.id, eb.id)', (int) $employeId);
        }

        $capteurId = $this->request->getGet('capteur_id');
        if ($capteurId !== null) {
            if (filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
                return $this->errorResponse('Identifiant capteur invalide.', 400);
            }
            $query->where('p.capteur_id', (int) $capteurId);
        }

        $typeEvenement = $this->request->getGet('type_evenement');
        if ($typeEvenement !== null && $typeEvenement !== '') {
            if (! in_array($typeEvenement, ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT', 'UNKNOWN'], true)) {
                return $this->errorResponse('Type de pointage invalide.', 400);
            }
            $query->where('p.type_evenement', $typeEvenement);
        }

        if ($dateDebut !== '') {
            $query->where('p.date_pointage >=', $dateDebut . ' 00:00:00');
        }
        if ($dateFin !== '') {
            $query->where('p.date_pointage <=', $dateFin . ' 23:59:59');
        }

        $statut = $this->request->getGet('statut');
        if ($statut !== null && $statut !== '') {
            if (! in_array($statut, ['VALIDE', 'INVALIDE', 'REJETE', 'SUPPRIME'], true)) {
                return $this->errorResponse('Statut de pointage invalide.', 400);
            }
            $query->where('p.statut', $statut);
        }

        $sortColumns = [
            'date_pointage' => 'p.date_pointage',
            'employe' => 'employe_nom',
            'type_evenement' => 'p.type_evenement',
            'statut_calcul' => 'p.statut_calcul',
            'capteur' => 'capteur_nom',
            'source' => 'p.source',
            'statut' => 'p.statut',
        ];
        $sortBy = (string) ($this->request->getGet('sort_by') ?? 'date_pointage');
        if (! array_key_exists($sortBy, $sortColumns)) {
            return $this->errorResponse('Colonne de tri invalide.', 400);
        }
        $sortDirection = strtoupper((string) ($this->request->getGet('sort_dir') ?? 'DESC'));
        if (! in_array($sortDirection, ['ASC', 'DESC'], true)) {
            return $this->errorResponse('Sens de tri invalide.', 400);
        }

        $limit = min(1000, max(1, (int) ($this->request->getGet('limit') ?? 10)));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));
        $total = (clone $query)->countAllResults();
        $items = $query->orderBy($sortColumns[$sortBy], $sortDirection)
            ->orderBy('p.id', 'DESC')
            ->limit($limit, $offset)->get()->getResultArray();

        return $this->successResponse([
            'items' => $items,
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ]);
    }

    public function stats(): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $dateDebut = (string) $this->request->getGet('date_debut');
        $dateFin = (string) $this->request->getGet('date_fin');

        if ($dateDebut === '' || $dateFin === ''
            || ! $this->dateValide($dateDebut)
            || ! $this->dateValide($dateFin)
            || $dateDebut > $dateFin
        ) {
            return $this->errorResponse('La plage de dates est invalide.', 400);
        }

        $db = Database::connect();
        $activeEmployeesQuery = $db->table('employes')
            ->select('id')
            ->where('actif', 1)
            ->where('deleted_at', null);
        if ($tenantId !== null) {
            $activeEmployeesQuery->where('tenant_id', $tenantId);
        }
        $activeEmployeeIds = array_map(
            'intval',
            array_column(
                $activeEmployeesQuery->get()->getResultArray(),
                'id'
            )
        );
        if ($activeEmployeeIds === []) {
            return $this->successResponse((new PointageStatsService())->calculerDepuisCollection([], $dateDebut, $dateFin));
        }

        $primedRows = $db->table('pointages p')
            ->select('p.tenant_id, p.date_pointage, p.type_evenement, COALESCE(e.id, eb.id) AS employe_id, p.capteur_id')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'left')
            ->join('employes eb', 'eb.tenant_id = p.tenant_id AND eb.badge_id = p.badge_id AND eb.deleted_at IS NULL AND e.id IS NULL', 'left')
            ->where('p.deleted_at', null)
            ->whereIn('COALESCE(e.id, eb.id)', $activeEmployeeIds)
            ->where('p.date_pointage >=', $dateDebut . ' 00:00:00')
            ->where('p.date_pointage <=', $dateFin . ' 23:59:59');
        if ($tenantId !== null) $primedRows->where('p.tenant_id', $tenantId);

        $visibleIds = $this->visibleEmployeeIds($dateDebut);
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                return $this->successResponse((new PointageStatsService())->calculerDepuisCollection([], $dateDebut, $dateFin));
            }
            $primedRows = $primedRows->whereIn('COALESCE(e.id, eb.id)', $visibleIds);
        }
        $primedRows = $primedRows->orderBy('p.date_pointage', 'ASC')
            ->get()
            ->getResultArray();

        $stats = (new PointageStatsService())->calculerDepuisCollection($primedRows, $dateDebut, $dateFin);

        return $this->successResponse($stats);
    }

    public function statsPresence(): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $dateDebut = (string) ($this->request->getGet('date_debut') ?? date('Y-m-d', strtotime('-30 days')));
        $dateFin = (string) ($this->request->getGet('date_fin') ?? date('Y-m-d'));
        $granularite = (string) ($this->request->getGet('granularite') ?? 'jour');
        if (! $this->dateValide($dateDebut) || ! $this->dateValide($dateFin) || $dateDebut > $dateFin) {
            return $this->errorResponse('La plage de dates est invalide.', 400);
        }
        if (! in_array($granularite, ['jour', 'semaine', 'mois'], true)) {
            return $this->errorResponse('La granularité doit être jour, semaine ou mois.', 400);
        }
        if ((int) (new DateTimeImmutable($dateDebut))->diff(new DateTimeImmutable($dateFin))->days > 365) {
            return $this->errorResponse('La période ne peut pas dépasser 366 jours.', 400);
        }

        try {
            $visibleIds = $this->visibleEmployeeIds($dateDebut);
            $stats = (new CalculPresenceService())->getStatsPresenceParJour(
                $tenantId,
                $dateDebut,
                $dateFin,
                $granularite,
                $visibleIds
            );

            return $this->successResponse($stats);
        } catch (Throwable $exception) {
            log_message('error', 'Erreur des statistiques de présence: {message}', ['message' => $exception->getMessage()]);

            return $this->errorResponse('Impossible de calculer les statistiques de présence.', 500);
        }
    }

    /** Recalcule les projections d'une journée dans le périmètre du tenant courant. */
    public function recalculerJournee(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits de gestion requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        if (array_diff(array_keys($input), ['date', 'employe_id', 'action']) !== []) {
            return $this->errorResponse('Filtres de recalcul invalides.', 400);
        }
        $action = (string) ($input['action'] ?? 'recalculer');
        if (! in_array($action, ['recalculer', 'previsualiser'], true)) {
            return $this->errorResponse('Action de présence invalide.', 422);
        }
        $date = $input['date'] ?? null;
        if (! is_string($date) || ! $this->dateValide($date)) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 422);
        }
        $employeId = null;
        if (array_key_exists('employe_id', $input) && $input['employe_id'] !== null && $input['employe_id'] !== '') {
            $employeValide = filter_var($input['employe_id'], FILTER_VALIDATE_INT);
            if ($employeValide === false || $employeValide < 1) {
                return $this->errorResponse('Identifiant employé invalide.', 422);
            }
            $employeId = (int) $employeValide;
        }
        if ($action === 'previsualiser' && $employeId === null) {
            return $this->errorResponse('Un employé doit être sélectionné pour prévisualiser une journée.', 422);
        }

        $db = $this->attendanceDatabase();
        $requete = $db->table('employes')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null);
        if ($employeId !== null) {
            $requete->where('id', $employeId);
        }
        $employes = $requete->orderBy('id', 'ASC')->get()->getResultArray();
        if ($employes === []) {
            return $this->errorResponse(
                $employeId === null ? 'Aucun employé actif à recalculer.' : 'Employé actif introuvable pour ce tenant.',
                404
            );
        }
        if ($action === 'previsualiser') {
            try {
                $resultat = $this->presenceCalculator($db)->interpreterJournee($employeId, $date);
            } catch (Throwable $exception) {
                log_message('error', 'Prévisualisation de présence impossible pour le tenant #{tenant}, employé #{employe}, date {date}: {message}', [
                    'tenant' => $tenantId,
                    'employe' => $employeId,
                    'date' => $date,
                    'message' => $exception->getMessage(),
                ]);

                return $this->errorResponse('Impossible de prévisualiser cette journée.', 500);
            }

            return $this->successResponse([
                'date' => $date,
                'employe_id' => $employeId,
                'resultat' => $resultat,
            ]);
        }
        if ($employeId === null && count($employes) > 1000) {
            return $this->errorResponse(
                'Le recalcul tenant-wide est limité à 1000 employés par requête. Sélectionnez un employé.',
                413
            );
        }

        $service = $this->presenceCalculator($db);
        $traitees = 0;
        $ignorees = 0;
        $erreurs = [];
        foreach ($employes as $employe) {
            $id = (int) $employe['id'];
            try {
                if ($service->recalculerJournee($id, $date) > 0) {
                    $traitees++;
                } else {
                    $ignorees++;
                }
            } catch (Throwable $exception) {
                log_message('error', 'Recalcul de présence impossible pour le tenant #{tenant}, employé #{employe}, date {date}: {message}', [
                    'tenant' => $tenantId,
                    'employe' => $id,
                    'date' => $date,
                    'message' => $exception->getMessage(),
                ]);
                $erreurs[] = ['employe_id' => $id, 'message' => 'Le calcul de cette journée a échoué.'];
            }
        }

        return $this->successResponse([
            'date' => $date,
            'portee' => $employeId === null ? 'TENANT' : 'EMPLOYE',
            'employe_id' => $employeId,
            'total' => count($employes),
            'traitees' => $traitees,
            'ignorees' => $ignorees,
            'erreurs' => $erreurs,
        ], 'Recalcul terminé.');
    }

    /** Compare l’interprétation actuelle et une proposition sans enregistrer de projection. */
    public function comparerReglesJournee(): ResponseInterface
    {
        if ($this->claims() === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits de gestion requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $champs = ['date_debut', 'date_fin', 'employe_id', 'horaire_id', 'actif_regles_nouvelles', 'parametres', 'creneaux'];
        if (array_diff(array_keys($input), $champs) !== []
            || ! is_array($input['parametres'] ?? null)
            || ! is_array($input['creneaux'] ?? null)
        ) {
            return $this->errorResponse('Configuration de comparaison invalide.', 422);
        }
        $debut = $input['date_debut'] ?? null;
        $fin = $input['date_fin'] ?? null;
        $employeId = filter_var($input['employe_id'] ?? null, FILTER_VALIDATE_INT);
        $horaireId = $input['horaire_id'] ?? null;
        if (! is_string($debut) || ! $this->dateValide($debut)
            || ! is_string($fin) || ! $this->dateValide($fin)
            || $debut > $fin
            || $employeId === false || $employeId < 1
            || ($horaireId !== null && (filter_var($horaireId, FILTER_VALIDATE_INT) === false || (int) $horaireId < 1))
            || ! in_array($input['actif_regles_nouvelles'] ?? null, [0, 1, '0', '1', false, true], true)
        ) {
            return $this->errorResponse('Employé, période ou activation invalide.', 422);
        }
        if ((int) (new DateTimeImmutable($debut))->diff(new DateTimeImmutable($fin))->days > 30) {
            return $this->errorResponse('La période de comparaison ne peut pas dépasser 31 jours.', 422);
        }

        $db = $this->attendanceDatabase();
        if (! $this->employeAppartientAuTenant((int) $employeId, $tenantId)) {
            return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
        }
        $service = $this->presenceCalculator($db);
        $jours = [];
        $date = new DateTimeImmutable($debut);
        $dateFin = new DateTimeImmutable($fin);
        try {
            while ($date <= $dateFin) {
                $jours[] = $service->comparerJourneeAvecRegles(
                    (int) $employeId,
                    $date->format('Y-m-d'),
                    $horaireId === null ? null : (int) $horaireId,
                    in_array($input['actif_regles_nouvelles'], [1, '1', true], true),
                    $input['parametres'],
                    $input['creneaux']
                );
                $date = $date->modify('+1 day');
            }
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Comparaison des règles impossible pour le tenant #{tenant}, employé #{employe}: {message}', [
                'tenant' => $tenantId,
                'employe' => $employeId,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Impossible de comparer ces règles sur la période sélectionnée.', 500);
        }

        return $this->successResponse([
            'date_debut' => $debut,
            'date_fin' => $fin,
            'employe_id' => (int) $employeId,
            'jours_compares' => count($jours),
            'jours_differents' => count(array_filter($jours, static fn (array $jour): bool => $jour['different'])),
            'avertissement_pointages_pause' => (bool) array_filter(
                $jours,
                static fn (array $jour): bool => $jour['pointages_pause_observes'] && ! $jour['creneaux_pause_configures']
            ),
            'jours' => $jours,
        ]);
    }

    protected function attendanceDatabase(): BaseConnection
    {
        return Database::connect();
    }

    protected function presenceCalculator(BaseConnection $db): CalculPresenceService
    {
        return new CalculPresenceService(null, $db);
    }

    public function export(): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $dateDebut = (string) $this->request->getGet('date_debut');
        $dateFin = (string) $this->request->getGet('date_fin');
        $format = strtolower((string) ($this->request->getGet('format') ?? 'csv'));
        if (! in_array($format, ['csv', 'excel'], true)) {
            return $this->errorResponse('Format d’export invalide.', 400);
        }

        if ($dateDebut === '' || $dateFin === ''
            || ! $this->dateValide($dateDebut)
            || ! $this->dateValide($dateFin)
            || $dateDebut > $dateFin
        ) {
            return $this->errorResponse('La plage de dates est invalide.', 400);
        }

        $employeId = $this->request->getGet('employe_id');
        if ($employeId !== null && ($employeId === '' || filter_var($employeId, FILTER_VALIDATE_INT) === false)) {
            return $this->errorResponse('Identifiant employé invalide.', 400);
        }

        $capteurId = $this->request->getGet('capteur_id');
        if ($capteurId !== null && ($capteurId === '' || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1)) {
            return $this->errorResponse('Identifiant capteur invalide.', 400);
        }

        $typeEvenement = $this->request->getGet('type_evenement');
        if ($typeEvenement !== null && ! in_array($typeEvenement, ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT', 'UNKNOWN'], true)) {
            return $this->errorResponse('Type de pointage invalide.', 400);
        }
        $statut = $this->request->getGet('statut');
        if ($statut !== null && ! in_array($statut, ['VALIDE', 'INVALIDE', 'REJETE', 'SUPPRIME'], true)) {
            return $this->errorResponse('Statut de pointage invalide.', 400);
        }
        $search = trim((string) $this->request->getGet('search'));
        if (mb_strlen($search) > 100) {
            return $this->errorResponse('La recherche est trop longue.', 400);
        }

        $db = Database::connect();
        $builder = $db->table('pointages p')
            ->select("DATE(p.date_pointage) AS date_pointage, TIME(p.date_pointage) AS heure_pointage, t.name AS tenant_name, CONCAT(COALESCE(e.nom, eb.nom, ''), ' ', COALESCE(e.prenom, eb.prenom, '')) AS employe, COALESCE(e.matricule, eb.matricule) AS matricule, p.type_evenement AS type_evenement, p.statut_calcul AS statut_calcul, p.statut AS statut, c.nom AS capteur_nom, p.source AS source")
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'left')
            ->join('employes eb', 'eb.tenant_id = p.tenant_id AND eb.badge_id = p.badge_id AND eb.deleted_at IS NULL AND e.id IS NULL', 'left')
            ->join('capteurs c', 'c.id = p.capteur_id', 'left')
            ->join('tenants t', 't.id = p.tenant_id', 'left')
            ->where('p.deleted_at', null)
            ->where('p.date_pointage >=', $dateDebut . ' 00:00:00')
            ->where('p.date_pointage <=', $dateFin . ' 23:59:59')
            ->orderBy('p.date_pointage', 'ASC');
        if ($tenantId !== null) {
            $builder->where('p.tenant_id', $tenantId);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('e.nom', $search)
                ->orLike('e.prenom', $search)
                ->orLike('e.matricule', $search)
                ->orLike('eb.nom', $search)
                ->orLike('eb.prenom', $search)
                ->orLike('eb.matricule', $search)
                ->orLike('c.nom', $search)
                ->groupEnd();
        }

        $visibleIds = $this->visibleEmployeeIds($dateDebut);
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                $builder->where('1 = 0');
            } else {
                $builder->whereIn('COALESCE(e.id, eb.id)', $visibleIds);
            }
        }

        if ($employeId !== null) {
            $builder->where('COALESCE(e.id, eb.id)', (int) $employeId);
        }
        if ($capteurId !== null) {
            $builder->where('p.capteur_id', (int) $capteurId);
        }
        if ($typeEvenement !== null) {
            $builder->where('p.type_evenement', $typeEvenement);
        }
        if ($statut !== null) {
            $builder->where('p.statut', $statut);
        }

        if ($format === 'excel') {
            return $this->exportExcel($builder, $dateDebut, $dateFin);
        }

        $filename = 'pointages_' . $dateDebut . '_' . $dateFin . '.csv';
        $output = fopen('php://temp', 'w+b');
        if ($output === false) {
            return $this->errorResponse('Impossible de préparer le fichier CSV.', 500);
        }
        fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, ['Date', 'Heure', 'Tenant', 'Employé', 'Matricule', 'Type', 'Statut calculé', 'Statut', 'Capteur', 'Source']);

        $pageSize = 1000;
        $offset = 0;

        do {
            $rows = $builder->limit($pageSize, $offset)->get()->getResultArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($output, [
                    (string) ($row['date_pointage'] ?? ''),
                    (string) ($row['heure_pointage'] ?? ''),
                    (string) ($row['tenant_name'] ?? ''),
                    trim((string) ($row['employe'] ?? '')),
                    (string) ($row['matricule'] ?? ''),
                    (string) ($row['type_evenement'] ?? ''),
                    (string) ($row['statut_calcul'] ?? ''),
                    (string) ($row['statut'] ?? ''),
                    (string) ($row['capteur_nom'] ?? ''),
                    (string) ($row['source'] ?? ''),
                ]);
            }

            $offset += $pageSize;
        } while (count($rows) === $pageSize);

        rewind($output);
        $contenu = stream_get_contents($output);
        fclose($output);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0')
            ->setBody($contenu === false ? '' : $contenu);
    }

    /**
     * Crée un pointage manuel pour un autre employé, réservé aux rôles de gestion.
     */
    public function create(): ResponseInterface
    {
        return $this->enregistrerPointage($this->jsonInput(), false);
    }

    /**
     * Enregistre un pointage personnel à l'heure du serveur, sans accepter de date fournie par le client.
     */
    public function pointerPersonnel(): ResponseInterface
    {
        return $this->enregistrerPointage($this->jsonInput(), true);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function enregistrerPointage(array $data, bool $personnel): ResponseInterface
    {
        $claims = $this->claims();
        $tenantId = $this->tenantId();
        if ($claims === null || $tenantId === null) {
            return $this->authorizationError();
        }

        $utilisateur = (new UserModel())
            ->where('tenant_id', $tenantId)
            ->find((int) $claims->sub);
        if ($utilisateur === null) {
            return $this->errorResponse('Utilisateur introuvable pour ce tenant.', 401);
        }
        $role = (string) $utilisateur['role'];
        $peutCreerPourUnAutre = (bool) $utilisateur['is_super_admin']
            || in_array($role, ['admin', 'rh'], true);
        if (! $personnel && ! $peutCreerPourUnAutre) {
            return $this->errorResponse('Seuls les administrateurs et les ressources humaines peuvent enregistrer un pointage pour un autre employé.', 403);
        }
        $db = Database::connect();
        $timezoneService = new TenantTimezoneService($db);
        $dateServeur = $timezoneService->now($tenantId);

        if ($personnel) {
            if (! isset($utilisateur['employe_id']) || (int) $utilisateur['employe_id'] < 1) {
                return $this->errorResponse('Aucun employé n’est associé à votre compte.', 422);
            }
            $employeId = (int) $utilisateur['employe_id'];
            $date = $dateServeur;
            $datePointage = $date->format('Y-m-d H:i:s');
        } else {
            $employeId = filter_var($data['employe_id'] ?? null, FILTER_VALIDATE_INT);
            if ($employeId === false || $employeId < 1) {
                return $this->errorResponse('Identifiant employé invalide.', 422);
            }
            if (isset($utilisateur['employe_id']) && (int) $utilisateur['employe_id'] === (int) $employeId) {
                return $this->errorResponse('Pour votre propre dossier, utilisez Mon dossier et la validation passkey.', 403);
            }
            $datePointage = trim((string) ($data['date_pointage'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datePointage, $dateServeur->getTimezone());
        }

        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d H:i:s') !== $datePointage
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ HH:MM:SS.', 422);
        }
        $type = strtoupper(trim((string) ($data['type_evenement'] ?? '')));
        if (! in_array($type, ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT'], true)) {
            return $this->errorResponse('Type de pointage invalide.', 422);
        }
        $raison = trim((string) ($data['raison'] ?? ''));
        if (mb_strlen($raison) > 500) {
            return $this->errorResponse('La raison ne peut pas dépasser 500 caractères.', 422);
        }

        $employe = (new EmployeModel())
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->find((int) $employeId);
        if ($employe === null) {
            return $this->errorResponse('Employé actif introuvable pour ce tenant.', 404);
        }

        if ($personnel) {
            $journee = (new CalculPresenceService(new HoraireService($db), $db))
                ->interpreterJournee((int) $employeId, $date->format('Y-m-d'));
            $prochainPointage = null;
            foreach ($journee['analyse_plages'] ?? [] as $plage) {
                if (($plage['statut'] ?? null) === 'MANQUANT') {
                    $prochainPointage = (string) ($plage['type'] ?? '');
                    break;
                }
            }
            if ($prochainPointage === null) {
                return $this->errorResponse('Aucun pointage n’est actuellement attendu selon votre horaire.', 422);
            }
            if ($type !== $prochainPointage) {
                return $this->errorResponse('Le prochain type de pointage attendu est ' . $prochainPointage . '.', 422);
            }
        }

        $credentialHash = null;
        if ($personnel) {
            $challengeId = trim((string) ($data['challenge_id'] ?? ''));
            if (! is_array($data['credential'] ?? null)) {
                return $this->errorResponse('Une validation passkey est requise pour pointer.', 401);
            }
            try {
                $credentialHash = (new \App\Services\WebAuthnPasskeyService($db))->verifyAssertion(
                    $tenantId,
                    (int) $utilisateur['id'],
                    $challengeId,
                    $data['credential']
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->errorResponse($exception->getMessage(), 401);
            } catch (Throwable $exception) {
                log_message('error', 'Vérification passkey du pointage impossible pour le compte #{user}: {error}', [
                    'user' => (int) $utilisateur['id'],
                    'error' => $exception->getMessage(),
                ]);
                return $this->errorResponse('Impossible de vérifier la passkey.', 500);
            }
        }

        $capteurId = null;
        if (! $personnel && array_key_exists('capteur_id', $data) && $data['capteur_id'] !== null && $data['capteur_id'] !== '') {
            $capteurIdValide = filter_var($data['capteur_id'], FILTER_VALIDATE_INT);
            if ($capteurIdValide === false || $capteurIdValide < 1) {
                return $this->errorResponse('Identifiant capteur invalide.', 422);
            }
            $capteur = $db->table('capteurs')
                ->select('id')
                ->where('id', (int) $capteurIdValide)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($capteur === null) {
                return $this->errorResponse('Capteur introuvable pour ce tenant.', 404);
            }
            $capteurId = (int) $capteur['id'];
        }

        $transactionOuverte = true;
        $db->transBegin();
        try {
            $model = new PointageModel();
            $id = $model->insert([
                'tenant_id' => $tenantId,
                'employe_id' => (int) $employeId,
                'badge_id' => (int) $employe['badge_id'],
                'capteur_id' => $capteurId,
                'type_evenement' => $type,
                'statut_calcul' => 'UNKNOWN',
                'date_pointage' => $datePointage,
                'source' => 'MANUEL',
                'statut' => 'VALIDE',
                'raw_data' => json_encode([
                    'raison' => $raison !== '' ? $raison : 'Pointage manuel',
                    'created_by' => (int) $utilisateur['id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $raison !== '' ? $raison : 'Pointage manuel',
            ], true);
            if (! is_int($id) && ! ctype_digit((string) $id)) {
                throw new \RuntimeException('La création du pointage a échoué : ' . json_encode($model->errors(), JSON_UNESCAPED_UNICODE));
            }
            $id = (int) $id;
            $db->table('pointage_manual_audit')->insert([
                'tenant_id' => $tenantId,
                'user_id' => (int) $utilisateur['id'],
                'employe_id' => (int) $employeId,
                'pointage_id' => $id,
                'auth_source' => strtoupper((string) ($utilisateur['auth_source'] ?? 'LOCAL')),
                'ip_address' => $this->request->getIPAddress() ?: null,
                'user_agent' => mb_substr((string) $this->request->getUserAgent(), 0, 2000) ?: null,
                'credential_hash' => $credentialHash,
                'timezone' => $dateServeur->getTimezone()->getName(),
                'created_at' => $dateServeur->format('Y-m-d H:i:s'),
            ]);
            if ($db->transStatus() === false) {
                throw new \RuntimeException('L’audit du pointage manuel n’a pas pu être enregistré.');
            }

            (new PointageReclassificationService($db))->reclassifierJournee(
                $tenantId,
                $date->format('Y-m-d'),
                true
            );
            if ($db->transStatus() === false) {
                throw new \RuntimeException('La transaction de création du pointage a échoué.');
            }
            $db->transCommit();
            $transactionOuverte = false;

            $pointage = $db->table('pointages')
                ->select('id, type_evenement, statut_calcul, date_pointage')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->get()
                ->getRowArray();
            if ($pointage === null) {
                throw new \RuntimeException('Le pointage créé est introuvable après validation.');
            }

            try {
                (new \App\Services\RealtimeService())->publierPointage([
                    ...$pointage,
                    'tenant_id' => $tenantId,
                    'employe_id' => (int) $employe['id'],
                    'employe_nom' => trim($employe['nom'] . ' ' . $employe['prenom']),
                    'capteur_id' => $capteurId,
                ]);
            } catch (Throwable $exception) {
                log_message('error', 'Publication temps réel du pointage manuel #{id} impossible : {message}', [
                    'id' => $id,
                    'message' => $exception->getMessage(),
                ]);
            }

            return $this->successResponse([
                'id' => $id,
                ...$pointage,
                'server_now' => $dateServeur->format(DateTimeImmutable::ATOM),
                'timezone' => $dateServeur->getTimezone()->getName(),
            ], 'Pointage manuel créé.', 201);
        } catch (Throwable $exception) {
            if ($transactionOuverte) {
                $db->transRollback();
            }
            log_message('error', 'Création manuelle du pointage impossible : {message}', [
                'tenant' => $tenantId,
                'employe' => (int) $employeId,
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible de créer ce pointage manuel.', 500);
        }
    }

    /**
     * Exporte les lignes de la requête filtrée dans un classeur XLSX.
     */
    private function exportExcel(\CodeIgniter\Database\BaseBuilder $builder, string $dateDebut, string $dateFin): ResponseInterface
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Pointages');
        $entetes = ['Date', 'Heure', 'Employé', 'Matricule', 'Type', 'Statut calculé', 'Statut', 'Capteur', 'Source'];
        foreach ($entetes as $index => $entete) {
            $feuille->setCellValueExplicit([$index + 1, 1], $entete, DataType::TYPE_STRING);
        }
        $feuille->getStyle('A1:I1')->getFont()->setBold(true);
        $feuille->freezePane('A2');
        $feuille->setAutoFilter('A1:I1');

        $ligneExcel = 2;
        $offset = 0;
        $taillePage = 1000;
        do {
            $lignes = $builder->limit($taillePage, $offset)->get()->getResultArray();
            foreach ($lignes as $ligne) {
                $valeurs = [
                    (string) ($ligne['date_pointage'] ?? ''),
                    (string) ($ligne['heure_pointage'] ?? ''),
                    trim((string) ($ligne['employe'] ?? '')),
                    (string) ($ligne['matricule'] ?? ''),
                    (string) ($ligne['type_evenement'] ?? ''),
                    (string) ($ligne['statut_calcul'] ?? ''),
                    (string) ($ligne['statut'] ?? ''),
                    (string) ($ligne['capteur_nom'] ?? ''),
                    (string) ($ligne['source'] ?? ''),
                ];
                foreach ($valeurs as $index => $valeur) {
                    $feuille->setCellValueExplicit([$index + 1, $ligneExcel], $valeur, DataType::TYPE_STRING);
                }
                $ligneExcel++;
            }
            $offset += $taillePage;
        } while (count($lignes) === $taillePage);

        foreach (range('A', 'I') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $cheminTemporaire = tempnam(sys_get_temp_dir(), 'ams-pointages-');
        if ($cheminTemporaire === false) {
            return $this->errorResponse('Impossible de préparer le fichier Excel.', 500);
        }
        try {
            (new Xlsx($classeur))->save($cheminTemporaire);
            $contenu = file_get_contents($cheminTemporaire);
            if ($contenu === false) {
                throw new \RuntimeException('Impossible de lire le fichier Excel généré.');
            }
        } catch (Throwable $exception) {
            log_message('error', 'Génération de l’export Excel impossible : {message}', ['message' => $exception->getMessage()]);
            return $this->errorResponse('Impossible de générer le fichier Excel.', 500);
        } finally {
            if (is_file($cheminTemporaire)) {
                unlink($cheminTemporaire);
            }
            $classeur->disconnectWorksheets();
        }

        $filename = 'pointages_' . $dateDebut . '_' . $dateFin . '.xlsx';
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($contenu);
    }

    /**
     * Recalcule un seul résultat à titre consultatif, sans écrire en base.
     */
    public function recalculer(int $id): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $db = Database::connect();
        $pointageQuery = $this->pointageRecalculQuery($db)->where('p.id', $id);
        if ($tenantId !== null) {
            $pointageQuery->where('p.tenant_id', $tenantId);
        }
        $pointage = $pointageQuery->get()->getRowArray();
        if ($pointage === null) {
            return $this->errorResponse('Pointage introuvable pour ce tenant.', 404);
        }

        if (! $this->verifierVisibilitePointage($pointage)) {
            return $this->errorResponse('Pointage hors de votre périmètre.', 403);
        }
        try {
            return $this->successResponse($this->calculerPointageRecalcul($pointage, $db));
        } catch (Throwable $exception) {
            log_message('error', 'Recalcul consultatif du pointage #{id} impossible : {message}', [
                'id' => $id,
                'message' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible de recalculer ce pointage.', 500);
        }
    }

    /** Simule en lot le recalcul des pointages sélectionnés sans modifier les données. */
    public function recalculerMasse(): ResponseInterface
    {
        $tenantId = $this->requestedPointagesTenant();
        if ($tenantId === false) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $ids = $input['pointage_ids'] ?? null;
        if (! is_array($ids) || $ids === [] || count($ids) > 100) {
            return $this->errorResponse('Sélectionnez entre 1 et 100 pointages.', 422);
        }
        $idsFiltres = [];
        foreach ($ids as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT);
            if ($validatedId === false || $validatedId < 1) {
                return $this->errorResponse('La sélection contient un identifiant invalide.', 422);
            }
            $idsFiltres[] = (int) $validatedId;
        }
        $idsFiltres = array_values(array_unique($idsFiltres));

        $db = Database::connect();
        $query = $this->pointageRecalculQuery($db)->whereIn('p.id', $idsFiltres);
        if ($tenantId !== null) {
            $query->where('p.tenant_id', $tenantId);
        }
        $pointages = $query->get()->getResultArray();
        if (count($pointages) !== count($idsFiltres)) {
            return $this->errorResponse('Un ou plusieurs pointages sont introuvables dans votre périmètre.', 404);
        }

        $resultats = [];
        $erreurs = [];
        foreach ($pointages as $pointage) {
            if (! $this->verifierVisibilitePointage($pointage)) {
                return $this->errorResponse('Un ou plusieurs pointages sont hors de votre périmètre.', 403);
            }
            try {
                $resultats[] = [
                    'id' => (int) $pointage['id'],
                    'tenant_id' => (int) $pointage['tenant_id'],
                    ...$this->calculerPointageRecalcul($pointage, $db),
                ];
            } catch (Throwable $exception) {
                log_message('error', 'Recalcul consultatif en lot du pointage #{id} impossible : {message}', [
                    'id' => (int) $pointage['id'],
                    'message' => $exception->getMessage(),
                ]);
                $erreurs[] = ['id' => (int) $pointage['id']];
            }
        }

        return $this->successResponse([
            'total' => count($idsFiltres),
            'calcules' => count($resultats),
            'erreurs' => $erreurs,
            'resultats' => $resultats,
        ], 'Simulation de recalcul terminée. Aucune donnée n’a été modifiée.');
    }

    private function pointageRecalculQuery(\CodeIgniter\Database\BaseConnection $db): \CodeIgniter\Database\BaseBuilder
    {
        return $db->table('pointages p')
            ->select('p.id, p.tenant_id, p.employe_id, p.badge_id, p.date_pointage, p.type_evenement, p.statut_calcul, COALESCE(e.id, eb.id) AS employe_resolu_id')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
            ->join('employes eb', 'eb.tenant_id = p.tenant_id AND eb.badge_id = p.badge_id AND eb.deleted_at IS NULL AND e.id IS NULL', 'left')
            ->where('p.deleted_at', null);
    }

    private function verifierVisibilitePointage(array $pointage): bool
    {
        $employeId = (int) ($pointage['employe_resolu_id'] ?? 0);
        $jour = (new DateTimeImmutable((string) $pointage['date_pointage']))->format('Y-m-d');
        $visibleIds = $this->visibleEmployeeIds($jour);
        return $visibleIds === null || ($employeId > 0 && in_array($employeId, $visibleIds, true));
    }

    private function calculerPointageRecalcul(array $pointage, \CodeIgniter\Database\BaseConnection $db): array
    {
        $id = (int) $pointage['id'];
        $tenantId = (int) $pointage['tenant_id'];
        $jour = (new DateTimeImmutable((string) $pointage['date_pointage']))->format('Y-m-d');
        $employeId = (int) ($pointage['employe_resolu_id'] ?? 0);
        $requeteJour = $db->table('pointages')
            ->select('id, employe_id, badge_id, date_pointage, type_evenement, statut_calcul')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->where('date_pointage >=', $jour . ' 00:00:00')
            ->where('date_pointage <=', $jour . ' 23:59:59');
        if ($employeId > 0) {
            $requeteJour->groupStart()
                ->where('employe_id', $employeId)
                ->orGroupStart()
                ->where('employe_id', null)
                ->where('badge_id', (int) $pointage['badge_id'])
                ->groupEnd()
                ->groupEnd();
        } else {
            $requeteJour->where('badge_id', (int) $pointage['badge_id']);
        }
        $pointagesJour = $requeteJour->orderBy('date_pointage', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $horaire = $employeId > 0
            ? (new HoraireService($db))->getHoraireEmploye($employeId, $jour)
            : [];
        if ($horaire === []) {
            $horaire = $db->table('tenant_horaires')
                ->where('tenant_id', $tenantId)
                ->where('est_defaut', 1)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get()->getRowArray() ?? [];
        }
        $plages = $horaire['plages_horaires'] ?? [];
        if (is_string($plages)) $plages = json_decode($plages, true);
        if (! is_array($plages)) $plages = [];

        $resultats = (new PointageTypeService())->determinerTypesEtStatuts($pointagesJour, $plages);
        foreach ($resultats as $index => $resultat) {
            if ((int) $resultat['id'] === $id) {
                return [
                    'pointage_avant' => [
                        'type_evenement' => $pointage['type_evenement'],
                        'statut_calcul' => $pointage['statut_calcul'],
                    ],
                    'pointage_apres' => [
                        'type_evenement' => $resultat['type_evenement'],
                        'statut_calcul' => $resultat['statut_calcul'],
                    ],
                    'contexte' => [
                        'date' => $jour,
                        'nb_pointages_jour' => count($pointagesJour),
                        'position' => $index + 1,
                        'horaire' => $horaire['nom'] ?? 'Standard',
                    ],
                ];
            }
        }
        throw new \RuntimeException('Le pointage sélectionné est absent du résultat de simulation.');
    }

    public function presence(int $employeId): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->employeAppartientAuTenant($employeId, $tenantId)) {
            return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
        }

        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        if (! $this->dateValide($date)) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 400);
        }

        try {
            return $this->successResponse((new CalculPresenceService())->interpreterJournee($employeId, $date));
        } catch (Throwable $e) {
            log_message('error', 'Calcul présence employé #{id} impossible : {error}', ['id' => $employeId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de calculer cette présence.', 500);
        }
    }

    public function historiquePersonnel(): ResponseInterface
    {
        $claims = $this->claims();
        $tenantId = $this->tenantId();
        if ($claims === null || $tenantId === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $nowTenant = (new TenantTimezoneService())->now($tenantId);
        $filtres = [
            'date_debut' => (string) ($this->request->getGet('date_debut') ?? ''),
            'date_fin' => (string) ($this->request->getGet('date_fin') ?? ''),
            'date_jour' => (string) ($this->request->getGet('date_jour') ?? $nowTenant->format('Y-m-d')),
            'periode_debut' => (string) ($this->request->getGet('periode_debut') ?? ''),
            'periode_fin' => (string) ($this->request->getGet('periode_fin') ?? ''),
            'matricule' => trim((string) ($this->request->getGet('matricule') ?? '')),
            'limit' => (int) ($this->request->getGet('limit') ?? 50),
            'offset' => (int) ($this->request->getGet('offset') ?? 0),
        ];
        foreach (['date_debut', 'date_fin', 'date_jour', 'periode_debut', 'periode_fin'] as $cle) {
            if ($filtres[$cle] !== '' && ! $this->dateValide($filtres[$cle])) {
                return $this->errorResponse('Filtres de date invalides.', 400);
            }
            if ($filtres[$cle] !== '' && $filtres[$cle] > $nowTenant->format('Y-m-d')) {
                return $this->errorResponse('Une date future ne peut pas être consultée.', 400);
            }
        }
        if (($filtres['periode_debut'] === '') !== ($filtres['periode_fin'] === '')
            || ($filtres['periode_debut'] !== '' && $filtres['periode_debut'] > $filtres['periode_fin'])
            || ($filtres['periode_debut'] !== ''
                && (int) (new DateTimeImmutable($filtres['periode_debut']))->diff(new DateTimeImmutable($filtres['periode_fin']))->days > 365)
        ) {
            return $this->errorResponse('La période d’autoévaluation est invalide ou dépasse 366 jours.', 400);
        }
        if (mb_strlen($filtres['matricule']) > 64) {
            return $this->errorResponse('Le matricule recherché est trop long.', 400);
        }

        try {
            $data = (new EmployeHistoryService())->getMonHistorique((int) $claims->sub, $filtres);
            return $this->successResponse($data);
        } catch (\App\Services\EmployeHistoryAccessException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getCode());
        } catch (Throwable $e) {
            log_message('error', 'Chargement de l’historique personnel impossible pour le compte #{user}: {error}', [
                'user' => (int) $claims->sub,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Impossible de charger cet historique.', 500);
        }
    }

    private function employeAppartientAuTenant(int $employeId, int $tenantId): bool
    {
        return (new EmployeModel())->where('tenant_id', $tenantId)->find($employeId) !== null;
    }

    /**
     * false = accès refusé, null = super-admin sur tous les tenants, int = tenant choisi.
     */
    private function requestedPointagesTenant(): int|false|null
    {
        $requested = $this->request->getGet('tenant_id');
        if ($requested === null || $requested === '') {
            return $this->isSuperAdmin() ? null : ($this->tenantId() ?? false);
        }

        $tenantId = filter_var($requested, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            return false;
        }
        return $this->tenantId((int) $tenantId) ?? false;
    }

    private function dateValide(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
