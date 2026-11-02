<?php

namespace App\Controllers\Api;

use App\Models\CapteurModel;
use App\Models\EmployeModel;
use App\Services\AffectationService;
use App\Services\EmployeAccountService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * API REST des employés et de leurs affectations aux capteurs.
 */
class EmployesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $model = new EmployeModel();
        $model->where('tenant_id', $tenantId);

        $visibleIds = $this->visibleEmployeeIds();
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                return $this->successResponse(['items' => [], 'total' => 0, 'limit' => 10, 'offset' => 0]);
            }
            $model->whereIn('id', $visibleIds);
        }

        $search = trim((string) ($this->request->getGet('search') ?? ''));
        if ($search !== '') {
            $model->groupStart()
                ->like('nom', $search)
                ->orLike('prenom', $search)
                ->orLike('matricule', $search)
                ->orLike('email', $search)
                ->groupEnd();
        }

        $uniteId = filter_var($this->request->getGet('unite_id'), FILTER_VALIDATE_INT);
        if ($uniteId !== false && $uniteId !== null && $uniteId > 0) {
            $model->where('unite_id', $uniteId);
        }

        $sansUnite = $this->request->getGet('sans_unite');
        if ($sansUnite !== null && $sansUnite !== '') {
            if ((string) $sansUnite !== '1') {
                return $this->errorResponse('Le filtre sans unité organisationnelle doit valoir 1.', 422);
            }
            $model->where('unite_id', null);
        }

        $actif = $this->request->getGet('actif');
        if ($actif === '0' || $actif === '1') {
            $model->where('actif', (int) $actif);
        }

        $dateFilters = [
            'created_from' => ['created_at', '>='],
            'created_to' => ['created_at', '<='],
            'sync_from' => ['derniere_sync', '>='],
            'sync_to' => ['derniere_sync', '<='],
            'createdAtFrom' => ['created_at', '>='],
            'createdAtTo' => ['created_at', '<='],
            'syncAtFrom' => ['derniere_sync', '>='],
            'syncAtTo' => ['derniere_sync', '<='],
        ];

        foreach ($dateFilters as $param => [$field, $operator]) {
            $value = trim((string) ($this->request->getGet($param) ?? ''));
            if ($value === '') {
                continue;
            }

            $formatted = $this->normalizeDateFilterValue($value, str_contains($param, 'to') || str_contains($param, 'To'));
            if ($formatted !== null) {
                $model->where($field . ' ' . $operator, $formatted);
            }
        }

        $requestedLimit = filter_var($this->request->getGet('limit') ?? 10, FILTER_VALIDATE_INT);
        $limit = $requestedLimit === false ? 10 : max(1, min(1000, $requestedLimit));
        $requestedOffset = filter_var($this->request->getGet('offset') ?? 0, FILTER_VALIDATE_INT);
        $offset = $requestedOffset === false ? 0 : max(0, $requestedOffset);

        $total = $model->countAllResults(false);
        $employes = $model
            ->orderBy('nom')->orderBy('prenom')
            ->findAll($limit, $offset);

        $uniteIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column($employes, 'unite_id'))
        )));
        $nomsUnites = [];
        if ($uniteIds !== []) {
            $unites = Database::connect()->table('unites_organisationnelles')
                ->select('id, nom')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->whereIn('id', $uniteIds)
                ->get()
                ->getResultArray();

            foreach ($unites as $unite) {
                $nomsUnites[(int) $unite['id']] = $unite['nom'];
            }
        }

        foreach ($employes as &$employe) {
            $uniteId = (int) ($employe['unite_id'] ?? 0);
            $employe['unite_nom'] = $nomsUnites[$uniteId] ?? null;
        }
        unset($employe);

        return $this->successResponse([
            'items' => $employes,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function exportExcel(): ResponseInterface
    {
        return $this->exportEmployees('excel');
    }

    public function exportPdf(): ResponseInterface
    {
        return $this->exportEmployees('pdf');
    }

    private function exportEmployees(string $format): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            $employees = $this->employeeExportQuery($tenantId)->get()->getResultArray();
            $headers = ['Matricule', 'Nom', 'Prénom', 'Email', 'Téléphone', 'Unité', 'Poste', 'Statut'];
            $rows = array_map(static fn (array $employee): array => [
                (string) ($employee['matricule'] ?? ''),
                (string) ($employee['nom'] ?? ''),
                (string) ($employee['prenom'] ?? ''),
                (string) ($employee['email'] ?? ''),
                (string) ($employee['telephone'] ?? ''),
                (string) ($employee['unite_nom'] ?? ''),
                (string) ($employee['poste'] ?? ''),
                (int) ($employee['actif'] ?? 0) === 1 ? 'Actif' : 'Inactif',
            ], $employees);
            $filename = 'employes_' . date('Ymd_His');

            if ($format === 'excel') {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Employés');
                foreach ($headers as $column => $header) {
                    $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
                }
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $rowIndex + 2], $value, DataType::TYPE_STRING);
                    }
                }
                foreach (range(1, count($headers)) as $column) {
                    $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
                }

                ob_start();
                (new Xlsx($spreadsheet))->save('php://output');
                $content = ob_get_clean();
                if (! is_string($content)) {
                    throw new \RuntimeException('Impossible de générer le fichier Excel.');
                }

                return $this->response
                    ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.xlsx"')
                    ->setHeader('Cache-Control', 'no-store')
                    ->setBody($content);
            }

            $htmlRows = '';
            foreach ($rows as $row) {
                $htmlRows .= '<tr>';
                foreach ($row as $value) {
                    $htmlRows .= '<td>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
                }
                $htmlRows .= '</tr>';
            }
            $htmlHeaders = implode('', array_map(
                static fn (string $header): string => '<th>' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>',
                $headers
            ));
            $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
                . 'body{font-family:sans-serif;font-size:9pt}h1{font-size:16pt}table{width:100%;border-collapse:collapse}'
                . 'th,td{border:1px solid #bbb;padding:5px;text-align:left}th{background:#eee}'
                . '</style></head><body><h1>Liste des employés</h1><table><thead><tr>'
                . $htmlHeaders . '</tr></thead><tbody>' . $htmlRows . '</tbody></table></body></html>';
            $pdf = new \Mpdf\Mpdf(['orientation' => 'L', 'format' => 'A4']);
            $pdf->WriteHTML($html);

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.pdf"')
                ->setHeader('Cache-Control', 'no-store')
                ->setBody($pdf->Output('', 'S'));
        } catch (Throwable $exception) {
            log_message('error', 'Export des employés impossible : {error}', [
                'error' => $exception->getMessage(),
                'tenant_id' => $tenantId,
                'format' => $format,
            ]);

            return $this->errorResponse('Impossible de générer l’export des employés.', 500);
        }
    }

    private function employeeExportQuery(int $tenantId): \CodeIgniter\Database\BaseBuilder
    {
        $query = Database::connect()->table('employes e')
            ->select('e.matricule, e.nom, e.prenom, e.email, e.telephone, e.poste, e.actif, u.nom AS unite_nom')
            ->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id AND u.deleted_at IS NULL', 'left')
            ->where('e.tenant_id', $tenantId)
            ->where('e.deleted_at', null);

        $visibleIds = $this->visibleEmployeeIds();
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                $query->where('1 = 0');
            } else {
                $query->whereIn('e.id', $visibleIds);
            }
        }

        $search = trim((string) ($this->request->getGet('search') ?? ''));
        if ($search !== '') {
            $query->groupStart()
                ->like('e.nom', $search)
                ->orLike('e.prenom', $search)
                ->orLike('e.matricule', $search)
                ->orLike('e.email', $search)
                ->groupEnd();
        }
        $unitId = filter_var($this->request->getGet('unite_id'), FILTER_VALIDATE_INT);
        if ($unitId !== false && $unitId !== null && $unitId > 0) {
            $query->where('e.unite_id', $unitId);
        }
        $active = $this->request->getGet('actif');
        if ($active === '0' || $active === '1') {
            $query->where('e.actif', (int) $active);
        }
        foreach ([
            'created_from' => ['e.created_at', '>=', false],
            'created_to' => ['e.created_at', '<=', true],
            'sync_from' => ['e.derniere_sync', '>=', false],
            'sync_to' => ['e.derniere_sync', '<=', true],
        ] as $parameter => [$field, $operator, $endOfRange]) {
            $value = trim((string) ($this->request->getGet($parameter) ?? ''));
            $formatted = $value === '' ? null : $this->normalizeDateFilterValue($value, $endOfRange);
            if ($formatted !== null) {
                $query->where($field . ' ' . $operator, $formatted);
            }
        }

        return $query->orderBy('e.nom')->orderBy('e.prenom');
    }

    public function show(int $id): ResponseInterface
    {
        $employe = $this->trouverEmploye($id);
        return $employe === null
            ? $this->errorResponse('Employé introuvable.', 404)
            : $this->successResponse($employe);
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

        if (isset($input['unite_id']) && ! $this->uniteAppartientAuTenant((int) $input['unite_id'], $tenantId)) {
            return $this->errorResponse('Unité organisationnelle introuvable pour ce tenant.', 422);
        }

        $data = array_intersect_key($input, array_flip([
            'unite_id', 'poste', 'est_responsable_unite', 'matricule', 'badge_id', 'user_id_capteur',
            'nom', 'prenom', 'email', 'telephone', 'date_embauche', 'date_depart', 'genre',
            'photo_url', 'empreinte_enrolee', 'nb_empreintes', 'role_capteur', 'actif', 'notes',
        ]));
        $data['tenant_id'] = $tenantId;
        $data['actif'] ??= 1;
        $data['empreinte_enrolee'] ??= 0;
        $data['nb_empreintes'] ??= 0;
        $data['role_capteur'] ??= 0;
        $db = Database::connect();
        $db->transBegin();
        try {
            $model = new EmployeModel();
            if (! $model->insert($data)) {
                $db->transRollback();
                return $this->errorResponse(implode(' ', $model->errors()), 422);
            }

            $employeId = (int) $model->getInsertID();
            $employe = $model->find($employeId);
            if (! is_array($employe)) {
                throw new \RuntimeException('Employé créé mais impossible à relire pour créer son compte.');
            }
            (new EmployeAccountService($db))->ensureReaderAccount($employe, $tenantId);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaction de création de l’employé invalide.');
            }
            $db->transCommit();

            return $this->successResponse($this->trouverEmploye($employeId), 'Employé créé avec son compte lecteur.', 201);
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Création employé et compte lecteur impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de créer l’employé et son compte lecteur.', 500);
        }
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $employe = $this->trouverEmploye($id);
        if ($employe === null) {
            return $this->errorResponse('Employé introuvable.', 404);
        }

        $input = $this->jsonInput();
        if (isset($input['unite_id']) && $input['unite_id'] !== null
            && ! $this->uniteAppartientAuTenant((int) $input['unite_id'], (int) $employe['tenant_id'])
        ) {
            return $this->errorResponse('Unité organisationnelle introuvable pour ce tenant.', 422);
        }

        $data = array_intersect_key($input, array_flip([
            'unite_id', 'poste', 'est_responsable_unite', 'matricule', 'badge_id', 'user_id_capteur',
            'nom', 'prenom', 'email', 'telephone', 'date_embauche', 'date_depart', 'genre',
            'photo_url', 'empreinte_enrolee', 'nb_empreintes', 'role_capteur', 'actif', 'notes',
        ]));
        if ($data === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }

        $model = new EmployeModel();
        if (! $model->update($id, array_merge($employe, $data))) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($this->trouverEmploye($id), 'Employé mis à jour.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $employe = $this->trouverEmploye($id);
        if ($employe === null) {
            return $this->errorResponse('Employé introuvable.', 404);
        }

        try {
            $resultat = (new AffectationService())->desactiverEmploye($id);
            if (($resultat['operations'] ?? []) !== [] && ! ($resultat['success'] ?? false)) {
                return $this->errorResponse('Impossible de retirer l’employé de tous ses capteurs.', 502);
            }

            (new EmployeModel())->delete($id);
            return $this->successResponse(null, 'Employé supprimé et affectations désactivées.');
        } catch (Throwable $e) {
            log_message('error', 'Suppression employé #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de supprimer cet employé.', 502);
        }
    }

    public function capteurs(int $id): ResponseInterface
    {
        if ($this->trouverEmploye($id) === null) {
            return $this->errorResponse('Employé introuvable.', 404);
        }

        try {
            $capteurs = (new AffectationService())->getCapteursEmploye($id);
            $champs = ['id', 'tenant_id', 'nom', 'numero_serie', 'adresse_ip', 'port', 'type_reseau', 'statut'];
            $capteurs = array_map(static fn (array $capteur): array => array_intersect_key($capteur, array_flip($champs)), $capteurs);
            return $this->successResponse($capteurs);
        } catch (Throwable $e) {
            log_message('error', 'Lecture affectations employé #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de lire les affectations de cet employé.', 500);
        }
    }

    public function addCapteur(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $employe = $this->trouverEmploye($id);
        $input = $this->jsonInput();
        $capteurId = filter_var($input['capteur_id'] ?? null, FILTER_VALIDATE_INT);
        if ($employe === null || $capteurId === false || $capteurId < 1) {
            return $this->errorResponse('Employé ou capteur introuvable.', 404);
        }
        if (! $this->capteurAppartientAuTenant((int) $capteurId, (int) $employe['tenant_id'])) {
            return $this->errorResponse('Capteur introuvable pour ce tenant.', 404);
        }

        $badgeId = isset($input['badge_id']) ? (int) $input['badge_id'] : null;
        try {
            $resultat = (new AffectationService())->ajouterEmployeCapteur($id, (int) $capteurId, $badgeId);
            return ($resultat['success'] ?? false)
                ? $this->successResponse($resultat, 'Affectation créée.', 201)
                : $this->errorResponse($resultat['error'] ?? 'Échec de l’affectation.', 502);
        } catch (Throwable $e) {
            log_message('error', 'Affectation capteur/employé impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible d’affecter cet employé au capteur.', 502);
        }
    }

    public function removeCapteur(int $id, int $capteurId): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $employe = $this->trouverEmploye($id);
        if ($employe === null || ! $this->capteurAppartientAuTenant($capteurId, (int) $employe['tenant_id'])) {
            return $this->errorResponse('Employé ou capteur introuvable.', 404);
        }

        try {
            $resultat = (new AffectationService())->retirerEmployeCapteur($id, $capteurId);
            return ($resultat['success'] ?? false)
                ? $this->successResponse($resultat, 'Affectation retirée.')
                : $this->errorResponse($resultat['message'] ?? 'Aucune affectation active.', 409);
        } catch (Throwable $e) {
            log_message('error', 'Retrait affectation impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de retirer cet employé du capteur.', 502);
        }
    }

    public function deactivate(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverEmploye($id) === null) {
            return $this->errorResponse('Employé introuvable.', 404);
        }

        try {
            $input = $this->jsonInput();
            $raison = trim((string) ($input['raison'] ?? ''));
            $resultat = (new AffectationService())->desactiverEmploye($id, $raison !== '' ? ['raison' => $raison] : []);
            if (! ($resultat['success'] ?? false) && ($resultat['operations'] ?? []) !== []) {
                return $this->errorResponse('Impossible de désactiver toutes les affectations.', 502);
            }

            (new EmployeModel())->update($id, ['actif' => 0]);
            return $this->successResponse($resultat, 'Employé désactivé.');
        } catch (Throwable $e) {
            log_message('error', 'Désactivation employé #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de désactiver cet employé.', 502);
        }
    }

    public function reactivate(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $employe = $this->trouverEmploye($id);
        if ($employe === null) {
            return $this->errorResponse('Employé introuvable.', 404);
        }

        try {
            $resultat = (new AffectationService())->reactiverEmploye($id, [
                'user_id' => (string) ($employe['user_id_capteur'] ?? $employe['badge_id']),
                'name' => trim($employe['nom'] . ' ' . $employe['prenom']),
                'role' => (int) $employe['role_capteur'],
            ]);
            if (! ($resultat['success'] ?? false) && ($resultat['operations'] ?? []) !== []) {
                return $this->errorResponse('Impossible de réactiver toutes les affectations.', 502);
            }

            (new EmployeModel())->update($id, ['actif' => 1]);
            return $this->successResponse($resultat, 'Employé réactivé.');
        } catch (Throwable $e) {
            log_message('error', 'Réactivation employé #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de réactiver cet employé.', 502);
        }
    }

    public function moveCapteur(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $employe = $this->trouverEmploye($id);
        $input = $this->jsonInput();
        $sourceId = filter_var($input['capteur_source_id'] ?? null, FILTER_VALIDATE_INT);
        $destinationId = filter_var($input['capteur_destination_id'] ?? null, FILTER_VALIDATE_INT);
        if ($employe === null || $sourceId === false || $destinationId === false
            || ! $this->capteurAppartientAuTenant((int) $sourceId, (int) $employe['tenant_id'])
            || ! $this->capteurAppartientAuTenant((int) $destinationId, (int) $employe['tenant_id'])
        ) {
            return $this->errorResponse('Employé ou capteur introuvable pour ce tenant.', 404);
        }

        try {
            $resultat = (new AffectationService())->deplacerEmploye($id, (int) $sourceId, (int) $destinationId, $input);
            return ($resultat['success'] ?? false)
                ? $this->successResponse($resultat, 'Employé déplacé.')
                : $this->errorResponse($resultat['error'] ?? 'Échec du déplacement.', 502);
        } catch (Throwable $e) {
            log_message('error', 'Déplacement employé #{id} impossible : {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de déplacer cet employé.', 502);
        }
    }

    private function normalizeDateFilterValue(string $value, bool $isEndOfRange = false): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $time = strtotime($trimmed);
        if ($time === false) {
            return null;
        }

        if ($isEndOfRange) {
            return date('Y-m-d 23:59:59', $time);
        }

        return date('Y-m-d 00:00:00', $time);
    }

    private function trouverEmploye(int $id): ?array
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return null;
        }

        $visibleIds = $this->visibleEmployeeIds();
        if ($visibleIds !== null && ! in_array($id, $visibleIds, true)) {
            return null;
        }

        return (new EmployeModel())
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    private function capteurAppartientAuTenant(int $capteurId, int $tenantId): bool
    {
        return (new CapteurModel())
            ->where('tenant_id', $tenantId)
            ->find($capteurId) !== null;
    }

    private function uniteAppartientAuTenant(int $uniteId, int $tenantId): bool
    {
        return Database::connect()->table('unites_organisationnelles')
            ->where('id', $uniteId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }
}
