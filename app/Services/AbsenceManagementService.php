<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class AbsenceManagementService
{
    private const DEFAULT_CATEGORIES = [
        ['code' => 'MEDICAL', 'nom' => 'Empêchement médical'],
        ['code' => 'FAMILY', 'nom' => 'Empêchement familial'],
        ['code' => 'ADMINISTRATIVE', 'nom' => 'Obligation administrative'],
        ['code' => 'OTHER', 'nom' => 'Autre empêchement'],
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array{workflow:string} */
    public function getSettings(int $tenantId): array
    {
        $settings = $this->db->table('tenant_absence_settings')
            ->select('workflow')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        return ['workflow' => (string) ($settings['workflow'] ?? 'RH_ONLY')];
    }

    public function saveWorkflow(int $tenantId, string $workflow, int $actorId): void
    {
        if (! in_array($workflow, ['RH_ONLY', 'MANAGER_THEN_RH', 'SUPERVISOR_ONLY'], true)) {
            throw new \InvalidArgumentException('Circuit de validation invalide.');
        }

        $now = date('Y-m-d H:i:s');
        $exists = $this->db->table('tenant_absence_settings')
            ->select('tenant_id')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        if ($exists === null) {
            $this->db->table('tenant_absence_settings')->insert([
                'tenant_id' => $tenantId,
                'workflow' => $workflow,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('tenant_absence_settings')
                ->where('tenant_id', $tenantId)
                ->update([
                    'workflow' => $workflow,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);
        }

        if ($workflow !== 'MANAGER_THEN_RH') {
            $this->db->table('absences')
                ->where('tenant_id', $tenantId)
                ->where('source', 'AMS')
                ->where('statut', 'MANAGER_APPROVED')
                ->update([
                    'statut' => 'PENDING',
                    'manager_approved_by' => null,
                    'manager_approved_at' => null,
                    'updated_at' => $now,
                ]);
        }
    }

    /** @return array<int, array{id:int,code:string,nom:string,actif:bool}> */
    public function getCategories(int $tenantId, bool $includeInactive = false): array
    {
        $this->initialiserCategories($tenantId);
        $builder = $this->db->table('absence_categories')
            ->select('id, code, nom, actif')
            ->where('tenant_id', $tenantId);
        if (! $includeInactive) {
            $builder->where('actif', 1);
        }

        return array_map(
            static fn (array $category): array => [
                'id' => (int) $category['id'],
                'code' => (string) $category['code'],
                'nom' => (string) $category['nom'],
                'actif' => (bool) $category['actif'],
            ],
            $builder->orderBy('nom', 'ASC')->get()->getResultArray()
        );
    }

    public function createCategory(int $tenantId, string $name): int
    {
        $this->initialiserCategories($tenantId);
        $name = self::normaliserNomCategorie($name);
        $this->assertNomCategorieDisponible($tenantId, $name);
        $codeBase = self::codeCategorie($name);
        $code = $codeBase;
        $suffixe = 2;
        while ($this->db->table('absence_categories')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->countAllResults() > 0
        ) {
            $code = substr($codeBase, 0, 60) . '_' . $suffixe++;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('absence_categories')->insert([
            'tenant_id' => $tenantId,
            'code' => $code,
            'nom' => $name,
            'actif' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    public function updateCategory(int $tenantId, int $categoryId, string $name): void
    {
        $name = self::normaliserNomCategorie($name);
        $this->assertNomCategorieDisponible($tenantId, $name, $categoryId);
        $category = $this->db->table('absence_categories')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->get()
            ->getRowArray();
        if ($category === null) {
            throw new \OutOfBoundsException('Catégorie introuvable.');
        }
        $updated = $this->db->table('absence_categories')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->update(['nom' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($updated === false) {
            throw new \RuntimeException('Impossible de modifier la catégorie.');
        }
    }

    public function setCategoryActive(int $tenantId, int $categoryId, bool $active): void
    {
        $category = $this->db->table('absence_categories')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->get()
            ->getRowArray();
        if ($category === null) {
            throw new \OutOfBoundsException('Catégorie introuvable.');
        }
        $updated = $this->db->table('absence_categories')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->update(['actif' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($updated === false) {
            throw new \RuntimeException('Impossible de modifier la catégorie.');
        }
    }

    /** @return array{deleted:bool,active:bool,used:bool} */
    public function deleteCategory(int $tenantId, int $categoryId): array
    {
        $category = $this->db->table('absence_categories')
            ->select('code, actif')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->get()
            ->getRowArray();
        if ($category === null) {
            throw new \OutOfBoundsException('Catégorie introuvable.');
        }

        $used = $this->db->table('absences')
            ->where('tenant_id', $tenantId)
            ->where('source', 'AMS')
            ->where('categorie', $category['code'])
            ->countAllResults() > 0;
        if ($used || in_array($category['code'], ['MEDICAL', 'FAMILY', 'ADMINISTRATIVE', 'OTHER'], true)) {
            $this->db->table('absence_categories')
                ->where('tenant_id', $tenantId)
                ->where('id', $categoryId)
                ->update(['actif' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

            return ['deleted' => false, 'active' => false, 'used' => $used];
        }

        $this->db->table('absence_categories')
            ->where('tenant_id', $tenantId)
            ->where('id', $categoryId)
            ->delete();

        return ['deleted' => true, 'active' => false, 'used' => false];
    }

    public function categoryIsActive(int $tenantId, string $code): bool
    {
        foreach ($this->getCategories($tenantId) as $category) {
            if ($category['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public function approvedFullDayAbsence(int $tenantId, int $employeId, string $date): ?array
    {
        return $this->db->table('absences')
            ->select('id, source, source_request_id, categorie, date_debut, date_fin, duree_heures')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('statut', 'APPROVED')
            ->where('journee_complete', 1)
            ->where('date_debut <=', $date)
            ->where('date_fin >=', $date)
            ->orderBy('source', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function approvedAbsencesForEmployeeDate(int $tenantId, int $employeId, string $date): array
    {
        return $this->db->table('absences')
            ->select('id, source, source_request_id, categorie, date_debut, date_fin, duree_heures, journee_complete, heure_debut, heure_fin')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('statut', 'APPROVED')
            ->where('date_debut <=', $date)
            ->where('date_fin >=', $date)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Builds a bounded forecast from final ERP-approved leave only.
     *
     * @param array<int, int>|null $visibleEmployeeIds Null means tenant-wide access.
     * @return array{date_debut:string,date_fin:string,jours:array<int,array<string,mixed>>}
     */
    public function prevoirCongesObr(
        int $tenantId,
        string $dateDebut,
        string $dateFin,
        ?array $visibleEmployeeIds = null
    ): array {
        if (! self::dateValide($dateDebut)
            || ! self::dateValide($dateFin)
            || $dateDebut > $dateFin
            || (new DateTimeImmutable($dateDebut))->diff(new DateTimeImmutable($dateFin))->days > 89
        ) {
            throw new \InvalidArgumentException('La période de prévision doit couvrir au maximum 90 jours valides.');
        }

        $jours = [];
        $date = new DateTimeImmutable($dateDebut);
        $fin = new DateTimeImmutable($dateFin);
        while ($date <= $fin) {
            $cle = $date->format('Y-m-d');
            $jours[$cle] = [
                'date' => $cle,
                'effectif' => 0,
                'employes_journee_complete' => 0,
                'absences_partielles' => 0,
                'heures_partielles' => 0.0,
                'taux_absence_complete' => 0.0,
                'par_unite' => [],
                'absences' => [],
                '_employes_journee_complete' => [],
            ];
            $date = $date->modify('+1 day');
        }

        $employeesQuery = $this->db->table('employes e')
            ->select('e.id, e.unite_id, u.nom AS unite_nom')
            ->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id', 'left')
            ->where('e.tenant_id', $tenantId)
            ->where('e.actif', 1)
            ->where('e.deleted_at', null);
        if ($visibleEmployeeIds === []) {
            return ['date_debut' => $dateDebut, 'date_fin' => $dateFin, 'jours' => array_values($jours)];
        }
        if ($visibleEmployeeIds !== null) {
            $employeesQuery->whereIn('e.id', $visibleEmployeeIds);
        }
        $employees = $employeesQuery->get()->getResultArray();

        $employesParId = [];
        $effectifsParUnite = [];
        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];
            $unitId = $employee['unite_id'] === null ? null : (int) $employee['unite_id'];
            $unitKey = $unitId === null ? 'sans_unite' : (string) $unitId;
            $employesParId[$employeeId] = [
                'unite_id' => $unitId,
                'unite_nom' => $employee['unite_nom'] === null ? null : (string) $employee['unite_nom'],
            ];
            $effectifsParUnite[$unitKey] = ($effectifsParUnite[$unitKey] ?? 0) + 1;
        }

        foreach ($jours as &$jour) {
            $jour['effectif'] = count($employees);
        }
        unset($jour);

        if ($employesParId === []) {
            return ['date_debut' => $dateDebut, 'date_fin' => $dateFin, 'jours' => array_values($jours)];
        }

        $absencesQuery = $this->db->table('absences a')
            ->select('a.employe_id, a.source_request_id, a.categorie, a.date_debut, a.date_fin, a.journee_complete, a.heure_debut, a.heure_fin, a.duree_heures, e.matricule, e.nom, e.prenom, e.unite_id, u.nom AS unite_nom')
            ->join('employes e', 'e.id = a.employe_id AND e.tenant_id = a.tenant_id', 'inner')
            ->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id', 'left')
            ->where('a.tenant_id', $tenantId)
            ->where('a.source', 'OBR')
            ->where('a.statut', 'APPROVED')
            ->where('e.actif', 1)
            ->where('e.deleted_at', null)
            ->where('a.date_debut <=', $dateFin)
            ->where('a.date_fin >=', $dateDebut);
        if ($visibleEmployeeIds !== null) {
            $absencesQuery->whereIn('a.employe_id', array_keys($employesParId));
        }
        $absences = $absencesQuery->orderBy('a.date_debut')->orderBy('e.nom')->orderBy('e.prenom')
            ->get()->getResultArray();

        foreach ($absences as $absence) {
            $employeId = (int) $absence['employe_id'];
            if (! isset($employesParId[$employeId])) {
                continue;
            }
            $dateAbsence = new DateTimeImmutable(max($dateDebut, (string) $absence['date_debut']));
            $finAbsence = new DateTimeImmutable(min($dateFin, (string) $absence['date_fin']));
            $journeeComplete = (int) $absence['journee_complete'] === 1;
            while ($dateAbsence <= $finAbsence) {
                $dateKey = $dateAbsence->format('Y-m-d');
                $unitId = $employesParId[$employeId]['unite_id'];
                $unitKey = $unitId === null ? 'sans_unite' : (string) $unitId;
                $detail = [
                    'employe_id' => $employeId,
                    'matricule' => (string) $absence['matricule'],
                    'nom' => (string) $absence['nom'],
                    'prenom' => (string) $absence['prenom'],
                    'unite_id' => $unitId,
                    'unite' => $employesParId[$employeId]['unite_nom'],
                    'numero_demande' => (string) $absence['source_request_id'],
                    'categorie' => (string) $absence['categorie'],
                    'journee_complete' => $journeeComplete,
                    'heure_debut' => $journeeComplete ? null : $absence['heure_debut'],
                    'heure_fin' => $journeeComplete ? null : $absence['heure_fin'],
                    'duree_heures' => $absence['duree_heures'] === null ? null : (float) $absence['duree_heures'],
                ];
                $jours[$dateKey]['absences'][] = $detail;
                if ($journeeComplete) {
                    $jours[$dateKey]['_employes_journee_complete'][$employeId] = true;
                } else {
                    $jours[$dateKey]['absences_partielles']++;
                    $jours[$dateKey]['heures_partielles'] += (float) ($absence['duree_heures'] ?? 0);
                }

                if (! isset($jours[$dateKey]['par_unite'][$unitKey])) {
                    $jours[$dateKey]['par_unite'][$unitKey] = [
                        'unite_id' => $unitId,
                        'unite' => $employesParId[$employeId]['unite_nom'] ?? 'Sans unité',
                        'effectif' => $effectifsParUnite[$unitKey] ?? 0,
                        'employes_journee_complete' => [],
                        'absences_partielles' => 0,
                        'taux_absence_complete' => 0.0,
                    ];
                }
                if ($journeeComplete) {
                    $jours[$dateKey]['par_unite'][$unitKey]['employes_journee_complete'][$employeId] = true;
                } else {
                    $jours[$dateKey]['par_unite'][$unitKey]['absences_partielles']++;
                }
                $dateAbsence = $dateAbsence->modify('+1 day');
            }
        }

        foreach ($jours as &$jour) {
            $jour['employes_journee_complete'] = count($jour['_employes_journee_complete']);
            $jour['taux_absence_complete'] = $jour['effectif'] === 0
                ? 0.0
                : round($jour['employes_journee_complete'] * 100 / $jour['effectif'], 2);
            foreach ($jour['par_unite'] as &$unit) {
                $unit['employes_journee_complete'] = count($unit['employes_journee_complete']);
                $unit['taux_absence_complete'] = $unit['effectif'] === 0
                    ? 0.0
                    : round($unit['employes_journee_complete'] * 100 / $unit['effectif'], 2);
            }
            unset($unit);
            $jour['heures_partielles'] = round($jour['heures_partielles'], 2);
            $jour['par_unite'] = array_values($jour['par_unite']);
            unset($jour['_employes_journee_complete']);
        }
        unset($jour);

        return [
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'jours' => array_values($jours),
        ];
    }

    /**
     * Recalculates affected daily projections after an absence transition.
     *
     * @param array<int, array{employe_id:int,date:string}> $jours
     */
    public function recalculerJours(int $tenantId, array $jours): void
    {
        $uniques = [];
        foreach ($jours as $jour) {
            $employeId = (int) ($jour['employe_id'] ?? 0);
            $date = (string) ($jour['date'] ?? '');
            if ($employeId < 1 || ! self::dateValide($date)) {
                throw new \InvalidArgumentException('Jour à recalculer invalide.');
            }
            $uniques[$employeId . ':' . $date] = ['employe_id' => $employeId, 'date' => $date];
        }

        $calcul = new CalculPresenceService(null, $this->db);
        foreach ($uniques as $jour) {
            $employe = $this->db->table('employes')
                ->select('id')
                ->where('id', $jour['employe_id'])
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($employe === null) {
                continue;
            }
            $calcul->recalculerJournee($jour['employe_id'], $jour['date']);
        }

        $this->invaliderKpis($tenantId);
    }

    public function enregistrerHistorique(
        int $tenantId,
        int $absenceId,
        ?int $actorId,
        string $action,
        ?string $ancienStatut,
        ?string $nouveauStatut,
        ?string $commentaire = null
    ): void {
        $this->db->table('absence_history')->insert([
            'tenant_id' => $tenantId,
            'absence_id' => $absenceId,
            'actor_id' => $actorId,
            'action' => $action,
            'ancien_statut' => $ancienStatut,
            'nouveau_statut' => $nouveauStatut,
            'commentaire' => $commentaire,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function invaliderKpis(int $tenantId): void
    {
        $timezone = (new TenantTimezoneService($this->db))->forTenant($tenantId);
        $date = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $key = 'dashboard_kpis_' . $tenantId . '_' . $date . '_presence_v6';
        service('cache')->delete($key);
    }

    private function initialiserCategories(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Organisation invalide.');
        }
        if ($this->db->table('absence_categories')->where('tenant_id', $tenantId)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULT_CATEGORIES as $category) {
            $this->db->table('absence_categories')->insert([
                'tenant_id' => $tenantId,
                ...$category,
                'actif' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function assertNomCategorieDisponible(int $tenantId, string $name, ?int $exceptId = null): void
    {
        $builder = $this->db->table('absence_categories')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('nom', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }
        if ($builder->get()->getRowArray() !== null) {
            throw new \InvalidArgumentException('Une catégorie porte déjà ce nom.');
        }
    }

    private static function normaliserNomCategorie(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Le nom de catégorie doit contenir entre 2 et 100 caractères.');
        }

        return $name;
    }

    private static function codeCategorie(string $name): string
    {
        $translittere = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $code = strtoupper(trim((string) $translittere));
        $code = preg_replace('/[^A-Z0-9]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return substr($code !== '' ? $code : 'CATEGORIE', 0, 60);
    }

    private static function dateValide(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && (int) $parsed->format('Y') >= 2000
            && (int) $parsed->format('Y') <= 2100;
    }
}
