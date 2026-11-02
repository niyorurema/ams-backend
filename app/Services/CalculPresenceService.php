<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calcule la présence quotidienne à partir de l'horaire et des pointages.
 */
class CalculPresenceService
{
    /** Tolérance, en minutes, avant qu'un écart devienne un retard. */
    protected const TOLERANCE_RETARD_MINUTES = 10;
    protected const TOLERANCE_HORAIRE_DEFAUT_MINUTES = 10;

    protected BaseConnection $db;
    protected HoraireService $horaireService;
    protected ParametragePointageService $parametrageService;
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $creneauxParTenant = [];
    /** @var array<int, array<string, mixed>> */
    private array $parametresParTenant = [];

    public function __construct(?HoraireService $horaireService = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->horaireService = $horaireService ?? new HoraireService($this->db);
        $this->parametrageService = new ParametragePointageService($this->db);
    }

    /**
     * Calcule le statut de présence d'un employé pour une journée.
     *
     * @return array{statut:string,heures_travaillees:float,retard_total_minutes:int,pointages_manquants:array<int,string>,analyse_plages:array<int,array<string,mixed>>,horaire_nom:?string}
     */
    public function calculerJournee(int $employeId, string $date): array
    {
        $this->normaliserDate($date);
        $jourFerie = $this->trouverJourFerie($employeId, $date);
        if ($jourFerie !== null && (int) $jourFerie['travaille'] === 0) {
            return $this->resultatJourFerie($jourFerie);
        }
        if ($jourFerie === null && ! $this->estJourSemaineTravaille($employeId, $date)) {
            return $this->resultatRepos();
        }

        $shift = $this->trouverShift($employeId, $date);
        $horaire = $shift === null
            ? $this->trouverHoraire($employeId, $date)
            : $this->horaireDepuisShift($shift, $date);
        $pointages = $this->trouverPointagesDuJour($employeId, $date, $shift !== null && $this->shiftTraverseMinuit($shift));

        if ($jourFerie !== null && $pointages === []) {
            return $this->resultatJourFerie($jourFerie);
        }

        $contexteEmploye = isset($this->db)
            ? $this->db->table('employes')
                ->select('tenant_id')
                ->where('id', $employeId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray()
            : null;
        $timezone = $contexteEmploye === null
            ? new DateTimeZone('Africa/Bujumbura')
            : (new TenantTimezoneService($this->db))->forTenant((int) $contexteEmploye['tenant_id']);
        $maintenant = new DateTimeImmutable('now', $timezone);

        $resultat = $this->calculerDepuisDonnees(
            $horaire,
            $pointages,
            $date,
            $date === $maintenant->format('Y-m-d') ? $maintenant : null
        );
        if ($jourFerie !== null) {
            $resultat['jour_ferie'] = true;
            $resultat['jour_ferie_nom'] = $jourFerie['nom'];
            $resultat['ferie_travail'] = (int) $jourFerie['travaille'] === 1;
            $resultat['prime_potentielle'] = (int) $jourFerie['travaille'] === 1
                && $resultat['heures_travaillees'] > 0;
        }

        return $resultat;
    }

    /**
     * Interprète une journée selon les règles opt-in, en conservant le calcul
     * historique tant que le tenant n'a pas configuré ses nouveaux créneaux.
     *
     * @return array<string, mixed>
     */
    public function interpreterJournee(int $employeId, string $date): array
    {
        $this->normaliserDate($date);
        if ($employeId < 1) {
            throw new \InvalidArgumentException('Identifiant employé invalide.');
        }

        $contexte = $this->chargerContexteInterpretation($employeId);
        if ($contexte === null) {
            throw new \RuntimeException('Employé introuvable.');
        }
        $tenantId = (int) $contexte['tenant_id'];
        $horaireChoisi = null;
        if (isset($this->db) && $this->db->tableExists('tenant_regles_pointage_versions')) {
            $horaireChoisi = $this->chargerHoraireInterpretation($contexte, $date);
            $version = (new AttendanceRuleVersionService($this->db))->getApplicable(
                $tenantId,
                $date,
                isset($horaireChoisi['horaire']['id']) ? (int) $horaireChoisi['horaire']['id'] : null
            );
            if ($version !== null && $version['actif'] && $version['creneaux'] !== []) {
                return $this->ajouterCompatibiliteResultatHistorique(
                    $this->calculerJourneeAvecNouvellesRegles(
                        $contexte,
                        $date,
                        $version['creneaux'],
                        $horaireChoisi,
                        $version['parametres'],
                        (int) $version['id']
                    )
                );
            }
        }
        if (isset($this->db)) {
            $horaireChoisi ??= $this->chargerHoraireInterpretation($contexte, $date);
            $evaluation = $horaireChoisi['evaluation'] ?? null;
            if ($evaluation === null
                && $horaireChoisi['contrat'] === null
                && ! $this->tenantTravailleCeJour($contexte, $date)
            ) {
                $evaluation = 'REPOS';
            }
            if ($evaluation !== null) {
                return $this->ajouterCompatibiliteResultatHistorique(
                    $this->resultatSansEvaluation($contexte, $date, $horaireChoisi, $evaluation)
                );
            }
        }
        if ((int) $contexte['actif_regles_nouvelles'] !== 1) {
            return $this->calculerJournee($employeId, $date);
        }

        $creneaux = $this->chargerCreneauxInterpretation((int) $contexte['tenant_id']);
        if ($creneaux === []) {
            return $this->calculerJournee($employeId, $date);
        }

        return $this->ajouterCompatibiliteResultatHistorique(
            $this->calculerJourneeAvecNouvellesRegles($contexte, $date, $creneaux)
        );
    }

    /**
     * @param array<string, mixed> $contexte
     * @param array<string, mixed> $horaireChoisi
     * @return array<string, mixed>
     */
    protected function resultatSansEvaluation(array $contexte, string $date, array $horaireChoisi, string $evaluation): array
    {
        return [
            'tenant_id' => (int) $contexte['tenant_id'],
            'employe_id' => (int) $contexte['employe_id'],
            'source_calcul' => $horaireChoisi['source'],
            'heure_arrivee' => null,
            'statut_arrivee' => null,
            'heure_debut_pause' => null,
            'statut_debut_pause' => null,
            'heure_fin_pause' => null,
            'statut_fin_pause' => null,
            'heure_depart' => null,
            'statut_depart' => null,
            'heures_travaillees' => 0.0,
            'heures_supplementaires' => 0.0,
            'statut_global' => 'PRESENT',
            'details' => [
                'source' => $horaireChoisi['source'],
                'date' => $date,
                'horaire_id' => isset($horaireChoisi['horaire']['id']) ? (int) $horaireChoisi['horaire']['id'] : null,
                'horaire_nom' => $horaireChoisi['horaire']['nom'] ?? null,
                'mode_travail' => $horaireChoisi['contrat']['mode_travail'] ?? null,
                'evaluation' => $evaluation,
                'creneaux' => [],
            ],
        ];
    }

    /**
     * Compares the persisted calculation with a proposed rule configuration without writing projections.
     *
     * @param array<string, mixed> $parametres
     * @param array<int, array<string, mixed>> $creneaux
     * @return array<string, mixed>
     */
    public function comparerJourneeAvecRegles(
        int $employeId,
        string $date,
        ?int $horaireId,
        bool $actif,
        array $parametres,
        array $creneaux
    ): array {
        $this->normaliserDate($date);
        if ($employeId < 1) {
            throw new \InvalidArgumentException('Identifiant employé invalide.');
        }
        $contexte = $this->chargerContexteInterpretation($employeId);
        if ($contexte === null) {
            throw new \RuntimeException('Employé introuvable.');
        }

        $horaireChoisi = $this->chargerHoraireInterpretation($contexte, $date);
        $horaireEmployeId = isset($horaireChoisi['horaire']['id'])
            ? (int) $horaireChoisi['horaire']['id']
            : null;
        if ($horaireId !== null && $horaireId !== $horaireEmployeId) {
            throw new \InvalidArgumentException('L’employé sélectionné n’utilise pas l’horaire choisi pour le profil.');
        }
        $configuration = (new AttendanceRuleVersionService($this->db))->preparer(
            (int) $contexte['tenant_id'],
            $horaireId,
            $actif,
            $parametres,
            $creneaux
        );

        $avant = $this->interpreterJournee($employeId, $date);
        $apres = $actif
            ? $this->ajouterCompatibiliteResultatHistorique(
                $this->calculerJourneeAvecNouvellesRegles(
                    $contexte,
                    $date,
                    $configuration['creneaux'],
                    $horaireChoisi,
                    $configuration['parametres']
                )
            )
            : $this->calculerJournee($employeId, $date);

        return [
            'date' => $date,
            'employe_id' => $employeId,
            'avant' => $this->resumerResultatComparaison($avant),
            'apres' => $this->resumerResultatComparaison($apres),
            'different' => $this->resumerResultatComparaison($avant) !== $this->resumerResultatComparaison($apres),
            'pointages_pause_observes' => $configuration['pointages_pause_observes'],
            'creneaux_pause_configures' => in_array('DEBUT_PAUSE', array_column($configuration['creneaux'], 'type_creneau'), true)
                && in_array('FIN_PAUSE', array_column($configuration['creneaux'], 'type_creneau'), true),
        ];
    }

    /** @param array<string, mixed> $resultat
     *  @return array<string, mixed>
     */
    private function resumerResultatComparaison(array $resultat): array
    {
        return [
            'statut' => $resultat['statut'] ?? $resultat['statut_global'] ?? 'ANORMAL',
            'statut_global' => $resultat['statut_global'] ?? null,
            'heure_arrivee' => $resultat['heure_arrivee'] ?? null,
            'heure_depart' => $resultat['heure_depart'] ?? null,
            'heures_travaillees' => (float) ($resultat['heures_travaillees'] ?? 0),
            'horaire_nom' => $resultat['horaire_nom'] ?? $resultat['details']['horaire_nom'] ?? null,
            'evaluation' => $resultat['details']['evaluation'] ?? null,
            'creneaux' => $resultat['details']['creneaux'] ?? [],
        ];
    }

    /** @param array<string, mixed> $resultat
     *  @return array<string, mixed>
     */
    protected function ajouterCompatibiliteResultatHistorique(array $resultat): array
    {
        $statuts = [
            'PRESENT' => 'NORMAL',
            'RETARD' => 'RETARD',
            'ABSENT' => 'ABSENT',
            'EN_COURS' => 'EN_COURS',
            'INCOMPLET' => 'ANORMAL',
            'HORS_CRENEAU' => 'ANORMAL',
            'ANTICIPE' => 'ANORMAL',
            'ANORMAL' => 'ANORMAL',
            'FERIE' => 'FERIE',
        ];
        $resultat['statut'] = ($resultat['details']['evaluation'] ?? null) === 'AUCUN_JUGEMENT'
            || ($resultat['details']['evaluation'] ?? null) === 'SANS_JUGEMENT'
                ? 'SANS_JUGEMENT'
                : (($resultat['details']['evaluation'] ?? null) === 'REPOS'
                    ? 'REPOS'
                    : ($statuts[$resultat['statut_global']] ?? 'ANORMAL'));
        $resultat['retard_total_minutes'] = 0;
        $resultat['pointages_manquants'] = [];
        $resultat['analyse_plages'] = [];

        foreach ($resultat['details']['creneaux'] ?? [] as $creneau) {
            $type = match ($creneau['creneau'] ?? '') {
                'ARRIVEE' => 'CHECK_IN',
                'DEBUT_PAUSE' => 'BREAK_OUT',
                'FIN_PAUSE' => 'BREAK_IN',
                'DEPART' => 'CHECK_OUT',
                default => (string) ($creneau['creneau'] ?? 'UNKNOWN'),
            };
            $statut = match ($creneau['statut'] ?? '') {
                'INCOMPLET', 'ABSENT' => 'MANQUANT',
                'HORS_CRENEAU', 'ANTICIPE', 'ANORMAL' => 'ANORMAL',
                default => (string) ($creneau['statut'] ?? 'UNKNOWN'),
            };
            $reel = $creneau['reel'] ?? null;
            $resultat['analyse_plages'][] = [
                'type' => $type,
                'attendu' => $creneau['attendu'] ?? '',
                'reel' => is_string($reel) ? substr($reel, 11, 5) : null,
                'ecart_min' => $creneau['ecart_minutes'] ?? null,
                'statut' => $statut,
            ];
            if ($statut === 'RETARD') {
                $resultat['retard_total_minutes'] += max(0, (int) ($creneau['ecart_minutes'] ?? 0));
            }
            if ($statut === 'MANQUANT') {
                $resultat['pointages_manquants'][] = sprintf('%s à %s', $type, $creneau['attendu'] ?? '');
            }
        }

        return $resultat;
    }

    /**
     * Recalcule et persiste la projection quotidienne de l'employé.
     *
     * Retourne l'identifiant de la ligne de projection créée ou mise à jour.
     */
    public function recalculerJournee(int $employeId, string $date): int
    {
        $resultat = $this->interpreterJournee($employeId, $date);
        if (! isset($resultat['statut_global'], $resultat['tenant_id'], $resultat['source_calcul'])) {
            return 0;
        }
        if (in_array($resultat['details']['evaluation'] ?? null, ['AUCUN_JUGEMENT', 'SANS_JUGEMENT', 'REPOS'], true)) {
            return 0;
        }

        $horodatage = date('Y-m-d H:i:s');
        $donnees = [
            'tenant_id' => (int) $resultat['tenant_id'],
            'employe_id' => $employeId,
            'date' => $date,
            'source_calcul' => $resultat['source_calcul'],
            'heure_arrivee' => $resultat['heure_arrivee'] ?? null,
            'statut_arrivee' => $resultat['statut_arrivee'] ?? null,
            'heure_debut_pause' => $resultat['heure_debut_pause'] ?? null,
            'statut_debut_pause' => $resultat['statut_debut_pause'] ?? null,
            'heure_fin_pause' => $resultat['heure_fin_pause'] ?? null,
            'statut_fin_pause' => $resultat['statut_fin_pause'] ?? null,
            'heure_depart' => $resultat['heure_depart'] ?? null,
            'statut_depart' => $resultat['statut_depart'] ?? null,
            'heures_travaillees' => $resultat['heures_travaillees'] ?? null,
            'heures_supplementaires' => $resultat['heures_supplementaires'] ?? 0,
            'statut_global' => $resultat['statut_global'],
            'details' => json_encode($resultat['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'calcule_le' => $horodatage,
            'updated_at' => $horodatage,
        ];

        $this->db->transBegin();
        try {
            $existant = $this->db->table('pointages_journee')
                ->select('id')
                ->where('tenant_id', $donnees['tenant_id'])
                ->where('employe_id', $employeId)
                ->where('date', $date)
                ->get()
                ->getRowArray();

            if ($existant === null) {
                $donnees['created_at'] = $horodatage;
                $this->db->table('pointages_journee')->insert($donnees);
                $id = (int) $this->db->insertID();
            } else {
                $this->db->table('pointages_journee')
                    ->where('id', (int) $existant['id'])
                    ->where('tenant_id', $donnees['tenant_id'])
                    ->update($donnees);
                $id = (int) $existant['id'];
            }

            if ($id < 1 || $this->db->transStatus() === false) {
                throw new \RuntimeException('Impossible d’enregistrer la projection quotidienne.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        return $id;
    }

    /**
     * Recalcule les projections d'un jour pour un tenant, un employé ou tous
     * les tenants. Les paramètres et créneaux sont mis en cache par tenant.
     *
     * @return array{traitees:int,ignorees:int,ids:array<int,int>}
     */
    public function recalculerPlage(string $date, ?int $tenantId = null, ?int $employeId = null): array
    {
        $this->normaliserDate($date);
        if (($tenantId !== null && $tenantId < 1) || ($employeId !== null && $employeId < 1)) {
            throw new \InvalidArgumentException('Filtre tenant ou employé invalide.');
        }

        $requete = $this->db->table('employes')
            ->select('id')
            ->where('actif', 1)
            ->where('deleted_at', null);
        if ($tenantId !== null) {
            $requete->where('tenant_id', $tenantId);
        }
        if ($employeId !== null) {
            $requete->where('id', $employeId);
        }

        $employes = $requete->orderBy('tenant_id', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        $ids = [];
        $ignorees = 0;
        foreach ($employes as $employe) {
            $id = $this->recalculerJournee((int) $employe['id'], $date);
            if ($id > 0) {
                $ids[] = $id;
            } else {
                $ignorees++;
            }
        }

        return ['traitees' => count($ids), 'ignorees' => $ignorees, 'ids' => $ids];
    }

    /** @return ?array<string, mixed> */
    protected function chargerContexteInterpretation(int $employeId): ?array
    {
        return $this->db->table('employes e')
            ->select('e.id AS employe_id, e.tenant_id, t.actif_regles_nouvelles, t.jours_travailles')
            ->join('tenants t', 't.id = e.tenant_id AND t.deleted_at IS NULL')
            ->where('e.id', $employeId)
            ->where('e.deleted_at', null)
            ->get()
            ->getRowArray();
    }

    /** @return array<int, array<string, mixed>> */
    protected function chargerCreneauxInterpretation(int $tenantId): array
    {
        if (array_key_exists($tenantId, $this->creneauxParTenant)) {
            return $this->creneauxParTenant[$tenantId];
        }

        return $this->creneauxParTenant[$tenantId] = $this->db->table('tenant_creneaux_pointage')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('ordre_affichage', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $contexte
     * @return array{source:string,horaire:array<string,mixed>,contrat:?array<string,mixed>,sans_jugement:bool}
     */
    protected function chargerHoraireInterpretation(array $contexte, string $date): array
    {
        $employeId = (int) $contexte['employe_id'];
        $tenantId = (int) $contexte['tenant_id'];
        $shift = $this->trouverShift($employeId, $date);
        if ($shift !== null) {
            return [
                'source' => 'SHIFT',
                'horaire' => $this->horaireDepuisShift($shift, $date),
                'contrat' => null,
                'sans_jugement' => false,
                'evaluation' => null,
            ];
        }

        $contratActif = $this->chargerContratActif($tenantId, $employeId, $date);
        $contratHorsPeriode = $contratActif === null && isset($this->db)
            ? $this->chargerContratReference($tenantId, $employeId)
            : null;
        $contrat = $contratActif ?? $contratHorsPeriode;

        $source = $contrat === null ? 'TENANT_DEFAUT' : 'CONTRAT';
        $horaire = [];
        if ($contrat === null) {
            $affectation = $this->chargerHoraireIndividuel($tenantId, $employeId, $date);
            if ($affectation !== null) {
                $horaire = $affectation;
                $source = 'HORAIRE_INDIVIDUEL';
            }
        }

        if ($horaire === []) {
            $horaire = $this->chargerHoraireTenantDefaut($tenantId);
        }
        $horaire = $this->normaliserHoraire($horaire);

        if ($contratHorsPeriode !== null) {
            return [
                'source' => 'CONTRAT',
                'horaire' => $horaire,
                'contrat' => $contratHorsPeriode,
                'sans_jugement' => false,
                'evaluation' => 'REPOS',
            ];
        }

        if ($contrat !== null) {
            $mode = (string) $contrat['mode_travail'];
            if ($mode === 'HORAIRE_LIBRE' || ! $this->contratActifCeJour($contrat, $date)) {
                return [
                    'source' => 'CONTRAT',
                    'horaire' => $horaire,
                    'contrat' => $contrat,
                    'sans_jugement' => true,
                    'evaluation' => $mode === 'HORAIRE_LIBRE' ? 'SANS_JUGEMENT' : 'REPOS',
                ];
            }

            $horaire['plages_horaires'] = $this->adapterPlagesAuContrat($horaire['plages_horaires'], $contrat);
        }

        return [
            'source' => $source,
            'horaire' => $horaire,
            'contrat' => $contrat,
            'sans_jugement' => false,
            'evaluation' => null,
        ];
    }

    /** @return ?array<string, mixed> */
    protected function chargerContratActif(int $tenantId, int $employeId, string $date): ?array
    {
        return $this->db->table('employe_contrats_horaire')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->where('date_debut <=', $date)
            ->groupStart()
                ->where('date_fin', null)
                ->orWhere('date_fin >=', $date)
            ->groupEnd()
            ->orderBy('date_debut', 'DESC')
            ->get()
            ->getRowArray();
    }

    /** @return ?array<string, mixed> */
    protected function chargerContratReference(int $tenantId, int $employeId): ?array
    {
        return $this->db->table('employe_contrats_horaire')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('date_debut', 'DESC')
            ->get()
            ->getRowArray();
    }

    /** @return ?array<string, mixed> */
    protected function chargerHoraireIndividuel(int $tenantId, int $employeId, string $date): ?array
    {
        return $this->db->table('employe_horaire eh')
            ->select('th.*')
            ->join('tenant_horaires th', 'th.id = eh.horaire_id AND th.tenant_id = eh.tenant_id')
            ->where('eh.tenant_id', $tenantId)
            ->where('eh.employe_id', $employeId)
            ->where('eh.date_debut <=', $date)
            ->groupStart()
                ->where('eh.date_fin', null)
                ->orWhere('eh.date_fin >=', $date)
            ->groupEnd()
            ->where('th.actif', 1)
            ->where('th.deleted_at', null)
            ->orderBy('eh.date_debut', 'DESC')
            ->get()
            ->getRowArray();
    }

    /** @return array<string, mixed> */
    protected function chargerHoraireTenantDefaut(int $tenantId): array
    {
        return $this->db->table('tenant_horaires')
            ->where('tenant_id', $tenantId)
            ->where('est_defaut', 1)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray() ?? [];
    }

    /**
     * @param array<string, mixed> $contexte
     * @param array<int, array<string, mixed>> $creneaux
     * @return array<string, mixed>
     */
    protected function calculerJourneeAvecNouvellesRegles(
        array $contexte,
        string $date,
        array $creneaux,
        ?array $horaireChoisi = null,
        ?array $parametresVersion = null,
        ?int $versionId = null
    ): array
    {
        $tenantId = (int) $contexte['tenant_id'];
        $employeId = (int) $contexte['employe_id'];
        $jourFerie = $this->trouverJourFerie($employeId, $date);
        if ($jourFerie !== null && (int) $jourFerie['travaille'] === 0) {
            return $this->resultatJourFerieInterprete($contexte, $date, $jourFerie);
        }

        $horaireChoisi ??= $this->chargerHoraireInterpretation($contexte, $date);
        $base = [
            'tenant_id' => $tenantId,
            'employe_id' => $employeId,
            'source_calcul' => $horaireChoisi['source'],
            'heure_arrivee' => null,
            'statut_arrivee' => null,
            'heure_debut_pause' => null,
            'statut_debut_pause' => null,
            'heure_fin_pause' => null,
            'statut_fin_pause' => null,
            'heure_depart' => null,
            'statut_depart' => null,
            'heures_travaillees' => 0.0,
            'heures_supplementaires' => 0.0,
            'details' => [
                'source' => $horaireChoisi['source'],
                'date' => $date,
                'horaire_id' => isset($horaireChoisi['horaire']['id']) ? (int) $horaireChoisi['horaire']['id'] : null,
                'horaire_nom' => $horaireChoisi['horaire']['nom'] ?? null,
                'mode_travail' => $horaireChoisi['contrat']['mode_travail'] ?? null,
                'regle_version_id' => $versionId,
            ],
        ];
        $jourTenantNonTravaille = $horaireChoisi['contrat'] === null
            && ! $this->tenantTravailleCeJour($contexte, $date);
        if ($horaireChoisi['sans_jugement'] || $jourTenantNonTravaille) {
            $evaluation = $horaireChoisi['evaluation'] ?? ($jourTenantNonTravaille ? 'REPOS' : 'AUCUN_JUGEMENT');

            return [...$base, 'statut_global' => 'PRESENT', 'details' => [...$base['details'], 'evaluation' => $evaluation]];
        }

        $horaire = $horaireChoisi['horaire'];
        $plagesParType = [];
        foreach ($horaire['plages_horaires'] ?? [] as $plage) {
            $plagesParType[$this->normaliserType((string) ($plage['type'] ?? ''))] = $plage;
        }
        $mapping = [
            'ARRIVEE' => ['evenement' => 'CHECK_IN', 'heure' => 'heure_arrivee', 'statut' => 'statut_arrivee', 'reference' => 'fin'],
            'DEBUT_PAUSE' => ['evenement' => 'BREAK_OUT', 'heure' => 'heure_debut_pause', 'statut' => 'statut_debut_pause', 'reference' => 'debut'],
            'FIN_PAUSE' => ['evenement' => 'BREAK_IN', 'heure' => 'heure_fin_pause', 'statut' => 'statut_fin_pause', 'reference' => 'debut'],
            'DEPART' => ['evenement' => 'CHECK_OUT', 'heure' => 'heure_depart', 'statut' => 'statut_depart', 'reference' => 'fin'],
        ];
        $parametres = $parametresVersion ?? $this->chargerParametresInterpretation($tenantId);
        $ecartMax = (int) $parametres['ecart_max_association_minutes'];
        $minutesPauseMin = (int) $parametres['duree_pause_minimum_minutes'];
        $minutesPauseMax = (int) $parametres['duree_pause_maximum_minutes'];
        $stages = [];
        foreach ($creneaux as $creneau) {
            $typeCreneau = (string) $creneau['type_creneau'];
            $specification = $mapping[$typeCreneau] ?? null;
            if ($specification === null) {
                throw new \UnexpectedValueException('Type de créneau de pointage inconnu.');
            }
            $plage = $plagesParType[$specification['evenement']] ?? [];
            if (! empty($horaire['est_shift']) && $plage === []) {
                continue;
            }
            $debut = $this->normaliserHeureOptionnelle($creneau['plage_debut'] ?? null)
                ?? $this->normaliserHeureOptionnelle($plage['debut'] ?? null);
            $fin = $this->normaliserHeureOptionnelle($creneau['plage_fin'] ?? null)
                ?? $this->normaliserHeureOptionnelle($plage['fin'] ?? null);
            $cibleHoraire = $this->normaliserHeureOptionnelle($plage[$specification['reference']] ?? null)
                ?? $this->normaliserHeureOptionnelle($plage['debut'] ?? null);
            $cibleContrat = $this->heureCibleContrat(
                $horaireChoisi['contrat'],
                $specification['evenement']
            );
            $cibleConfiguree = $this->normaliserHeureOptionnelle($creneau['heure_cible'] ?? null);
            $cible = $cibleContrat
                ?? ($horaireChoisi['source'] === 'TENANT_DEFAUT' ? $cibleConfiguree : $cibleHoraire)
                ?? $cibleHoraire
                ?? $cibleConfiguree;
            $mode = (string) $creneau['mode'];
            if (($mode === 'PLAGE' && ($debut === null || $fin === null))
                || ($mode !== 'PLAGE' && $cible === null)
            ) {
                throw new \UnexpectedValueException('Le créneau actif ne contient pas les heures nécessaires à son mode.');
            }
            if (! in_array($mode, ['LIMITE_TARDIVE', 'LIMITE_PRECOCE', 'PLAGE'], true)) {
                throw new \UnexpectedValueException('Mode de créneau de pointage inconnu.');
            }
            $stages[] = [
                'type' => $typeCreneau,
                'evenement' => $specification['evenement'],
                'heure' => $specification['heure'],
                'statut' => $specification['statut'],
                'mode' => $mode,
                'cible' => $cible,
                'debut' => $debut,
                'fin' => $fin,
                'tolerance' => max(0, (int) ($creneau['tolerance_minutes'] ?? 0)),
                'jour_offset' => (int) ($plage['jour_offset'] ?? 0),
                'autoriser_avance' => $typeCreneau === 'ARRIVEE',
                'autoriser_retard' => $typeCreneau === 'DEPART',
                'heure_limite_lendemain' => $this->normaliserHeureOptionnelle(
                    $plagesParType['CHECK_IN']['debut'] ?? $plagesParType['CHECK_IN']['fin'] ?? null
                ),
            ];
        }

        $heureLimiteLendemain = null;
        foreach ($stages as $stage) {
            if ($stage['type'] === 'ARRIVEE') {
                $heureLimiteLendemain = $stage['cible'] ?? $stage['fin'] ?? null;
                break;
            }
        }
        $shiftTraverseMinuit = ! empty($horaire['est_shift']) && $this->shiftTraverseMinuit($horaire);
        if (! empty($horaire['est_shift'])) {
            $heureLimiteLendemain = $shiftTraverseMinuit
                ? substr((string) $horaire['heure_debut'], 0, 5)
                : '00:00';
        }
        $heureLimiteLendemain ??= '07:30';
        foreach ($stages as &$stage) {
            if ($stage['type'] === 'DEPART') {
                $stage['heure_limite_lendemain'] = $heureLimiteLendemain;
                $stage['autoriser_lendemain'] = empty($horaire['est_shift']) || $shiftTraverseMinuit;
            }
        }
        unset($stage);
        $pointages = $this->chargerPointagesInterpretation(
            $tenantId,
            $employeId,
            $date,
            $heureLimiteLendemain
        );
        $pointages = $this->filtrerPointagesPourHoraire(
            $pointages,
            $date,
            $horaire,
            $ecartMax,
            $heureLimiteLendemain
        );
        if ($jourFerie !== null && $pointages === []) {
            return $this->resultatJourFerieInterprete($contexte, $date, $jourFerie);
        }

        $maintenantTenant = $this->maintenantTenant($tenantId);
        $stageArrivee = null;
        foreach ($stages as $stage) {
            if ($stage['type'] === 'ARRIVEE') {
                $stageArrivee = $stage;
                break;
            }
        }
        $aUnEvenementArrivee = false;
        foreach ($pointages as $pointage) {
            if ($this->normaliserType((string) ($pointage['type_evenement'] ?? '')) === 'CHECK_IN') {
                $aUnEvenementArrivee = true;
                break;
            }
        }
        $arriveeManquante = $stageArrivee !== null
            && ! $aUnEvenementArrivee
            && $this->pointageLePlusProche($stageArrivee, $pointages, [], $date, $ecartMax) === null;
        if ($arriveeManquante && $date === $maintenantTenant->format('Y-m-d')) {
            $stage = $stageArrivee;
                $echeance = $this->echeanceArrivee($stage, $date, $maintenantTenant->getTimezone());
                if ($maintenantTenant <= $echeance) {
                    $base['statut_global'] = 'EN_COURS';
                    $base['details'] = [
                        ...$base['details'],
                        'evaluation' => 'AUCUNE_ARRIVEE_AVANT_ECHEANCE',
                        'heure_attendue' => $stage['cible'] ?? $stage['fin'],
                        'echeance_arrivee' => $echeance->format('Y-m-d H:i:s'),
                        'creneaux' => array_map(static fn (array $item): array => [
                            'creneau' => $item['type'],
                            'attendu' => $item['cible'] ?? null,
                            'statut' => $item['type'] === 'ARRIVEE' ? 'EN_COURS' : 'A_VENIR',
                        ], $stages),
                    ];

                    return $base;
                }
        }

        $utilises = [];
        $statuts = [];
        $traces = [];
        if ($pointages === []) {
            $statuts[] = 'ABSENT';
        }
        foreach ($stages as $stage) {
            $candidat = $this->pointageLePlusProche($stage, $pointages, $utilises, $date, $ecartMax);
            if ($candidat === null) {
                $stageStatus = $stage['type'] === 'ARRIVEE' && $arriveeManquante
                    ? 'ABSENT'
                    : ($pointages === [] && $stage['type'] === 'ARRIVEE' ? 'ABSENT' : 'INCOMPLET');
                $base[$stage['statut']] = $stageStatus;
                $statuts[] = $stageStatus;
                $traces[] = [
                    'creneau' => $stage['type'],
                    'mode' => $stage['mode'],
                    'attendu' => $stage['cible'],
                    'plage' => [$stage['debut'], $stage['fin']],
                    'reel' => null,
                    'ecart_minutes' => null,
                    'statut' => $stageStatus,
                ];
                continue;
            }
            $utilises[$candidat['index']] = true;
            $reel = new DateTimeImmutable((string) $candidat['pointage']['date_pointage']);
            $reelHeure = $reel->format('H:i:s');
            $ecart = $this->ecartStageMinutes($stage, $date, $reel);
            $stageStatus = match ($stage['mode']) {
                'LIMITE_TARDIVE' => $ecart > $stage['tolerance'] ? 'RETARD' : 'OK',
                'LIMITE_PRECOCE' => $ecart < -$stage['tolerance'] ? 'ANTICIPE' : 'OK',
                'PLAGE' => $this->heureDansPlage($reelHeure, $stage['debut'], $stage['fin']) ? 'OK' : 'HORS_CRENEAU',
            };
            $base[$stage['heure']] = $reelHeure;
            if ($stageStatus !== 'OK') {
                $statuts[] = $stageStatus;
            }
            $base[$stage['statut']] = $this->statutEtapePersistable($stage['statut'], $stageStatus);
            $traces[] = [
                'creneau' => $stage['type'],
                'mode' => $stage['mode'],
                'attendu' => $stage['cible'],
                'plage' => [$stage['debut'], $stage['fin']],
                'reel' => $reel->format('Y-m-d H:i:s'),
                'ecart_minutes' => $ecart,
                'statut' => $stageStatus,
                'pointage_id' => isset($candidat['pointage']['id']) ? (int) $candidat['pointage']['id'] : null,
            ];
        }

        $pointagesUtilises = [];
        foreach ($utilises as $index => $_) {
            $pointagesUtilises[] = $pointages[$index];
        }
        if (! empty($horaire['est_shift'])) {
            $pointagesUtilises = $this->ajouterPauseShiftPourCalculHeures(
                $pointagesUtilises,
                $pointages
            );
        }
        $pointagesHorsCreneau = [];
        foreach ($pointages as $index => $pointage) {
            if (! isset($utilises[$index])) {
                if (! empty($horaire['est_shift'])
                    && in_array($this->normaliserType((string) $pointage['type_evenement']), ['BREAK_OUT', 'BREAK_IN'], true)
                ) {
                    continue;
                }
                $pointagesHorsCreneau[] = $pointage;
            }
        }
        if ($pointagesHorsCreneau !== []) {
            $statuts[] = 'HORS_CRENEAU';
        }

        if (($base['heure_debut_pause'] ?? null) !== null && ($base['heure_fin_pause'] ?? null) !== null) {
            $pause = $this->dureeEntre(
                substr((string) $base['heure_debut_pause'], 0, 5),
                substr((string) $base['heure_fin_pause'], 0, 5)
            );
            if ($pause < $minutesPauseMin || $pause > $minutesPauseMax) {
                $base['statut_debut_pause'] = 'ANORMAL';
                $statuts[] = 'ANORMAL';
            }
        }

        $base['heures_travaillees'] = ! empty($horaire['est_shift'])
            ? $this->calculerHeuresShiftAvecPauses($pointagesUtilises)
            : $this->calculerHeuresAvecHorodatages($pointagesUtilises);
        $heuresNormales = $this->heuresNormalesHoraire($horaire);
        if ($horaireChoisi['contrat'] !== null
            && in_array($horaireChoisi['contrat']['mode_travail'], ['MI_TEMPS_MATIN', 'MI_TEMPS_APRES_MIDI'], true)
        ) {
            $heuresNormales /= 2;
        }
        $seuilHeuresSup = (int) $parametres['seuil_heures_sup_minutes'];
        $minutesSup = max(
            0,
            (int) round($base['heures_travaillees'] * 60) - (int) round($heuresNormales * 60) - $seuilHeuresSup
        );
        $modeHeuresSup = (string) $parametres['mode_calcul_heures_sup'];
        if (! in_array($modeHeuresSup, ['ARRONDI', 'EXACT'], true)) {
            throw new \UnexpectedValueException('Mode de calcul des heures supplémentaires invalide.');
        }
        if ($modeHeuresSup === 'ARRONDI') {
            $pas = (int) $parametres['pas_arrondi_heures_sup_minutes'];
            if ($pas < 1) {
                throw new \UnexpectedValueException('Le pas d’arrondi des heures supplémentaires doit être positif.');
            }
            $minutesSup = (int) (round($minutesSup / $pas) * $pas);
        }
        $base['heures_supplementaires'] = round($minutesSup / 60, 2);
        $base['statut_global'] = $this->aggregerStatuts($statuts);
        $base['details'] = [
            'source' => $horaireChoisi['source'],
            'date' => $date,
            'horaire_id' => isset($horaire['id']) ? (int) $horaire['id'] : null,
            'horaire_nom' => $horaire['nom'] ?? null,
            'mode_travail' => $horaireChoisi['contrat']['mode_travail'] ?? null,
            'regle_version_id' => $versionId,
            'creneaux' => $traces,
            'pointages_non_associes' => array_values(array_map(
                static fn (array $pointage): array => [
                    'id' => isset($pointage['id']) ? (int) $pointage['id'] : null,
                    'type' => $pointage['type_evenement'] ?? null,
                    'date_pointage' => $pointage['date_pointage'] ?? null,
                ],
                $pointagesHorsCreneau
            )),
            'parametres' => [
                'ecart_max_association_minutes' => $ecartMax,
                'seuil_heures_sup_minutes' => $seuilHeuresSup,
                'mode_calcul_heures_sup' => $modeHeuresSup,
                'pas_arrondi_heures_sup_minutes' => $parametres['pas_arrondi_heures_sup_minutes'],
            ],
        ];

        if ($jourFerie !== null) {
            $base['jour_ferie'] = true;
            $base['jour_ferie_nom'] = $jourFerie['nom'];
            $base['ferie_travail'] = true;
            $base['prime_potentielle'] = $base['heures_travaillees'] > 0;
            $base['details']['jour_ferie_nom'] = $jourFerie['nom'];
            $base['details']['evaluation'] = 'JOUR_FERIE_TRAVAILLE';
        }

        return $base;
    }

    /** @param array<string, mixed> $contexte
     *  @param array<string, mixed> $jourFerie
     *  @return array<string, mixed>
     */
    protected function resultatJourFerieInterprete(array $contexte, string $date, array $jourFerie): array
    {
        return [
            'tenant_id' => (int) $contexte['tenant_id'],
            'employe_id' => (int) $contexte['employe_id'],
            'source_calcul' => 'JOUR_FERIE',
            'heure_arrivee' => null,
            'statut_arrivee' => null,
            'heure_debut_pause' => null,
            'statut_debut_pause' => null,
            'heure_fin_pause' => null,
            'statut_fin_pause' => null,
            'heure_depart' => null,
            'statut_depart' => null,
            'heures_travaillees' => 0.0,
            'heures_supplementaires' => 0.0,
            'statut_global' => 'FERIE',
            'jour_ferie' => true,
            'jour_ferie_nom' => $jourFerie['nom'],
            'ferie_travail' => (int) $jourFerie['travaille'] === 1,
            'prime_potentielle' => false,
            'details' => [
                'source' => 'JOUR_FERIE',
                'date' => $date,
                'evaluation' => 'JOUR_FERIE',
                'jour_ferie_nom' => $jourFerie['nom'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function chargerPointagesInterpretation(
        int $tenantId,
        int $employeId,
        string $date,
        string $heureLimiteLendemain
    ): array {
        return $this->db->table('pointages')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('date_pointage >=', $date . ' 00:00:00')
            ->where(
                'date_pointage <',
                (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' ' . $heureLimiteLendemain . ':00'
            )
            ->where('statut', 'VALIDE')
            ->where('deleted_at', null)
            ->orderBy('date_pointage', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Exclut la sortie du service précédent et les événements qui ne peuvent
     * appartenir à la journée courante ou à sa sortie tardive.
     *
     * @param array<int, array<string, mixed>> $pointages
     * @param array<string, mixed> $horaire
     * @return array<int, array<string, mixed>>
     */
    protected function filtrerPointagesPourHoraire(
        array $pointages,
        string $date,
        array $horaire,
        int $ecartMax,
        string $heureDebutJournee
    ): array {
        $estShift = ! empty($horaire['est_shift']);
        $dateSuivante = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $debutShift = $estShift
            ? new DateTimeImmutable($date . ' ' . substr((string) $horaire['heure_debut'], 0, 8))
                ->modify('-' . max(0, $ecartMax) . ' minutes')
            : null;

        return array_values(array_filter($pointages, function (array $pointage) use (
            $date,
            $dateSuivante,
            $estShift,
            $debutShift,
            $heureDebutJournee
        ): bool {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            $horodatage = new DateTimeImmutable((string) $pointage['date_pointage']);
            $jour = $horodatage->format('Y-m-d');
            if ($jour === $dateSuivante) {
                return $type === 'CHECK_OUT'
                    || ($estShift && in_array($type, ['BREAK_OUT', 'BREAK_IN'], true));
            }
            if ($jour !== $date) {
                return false;
            }
            if ($estShift) {
                return $debutShift !== null && $horodatage >= $debutShift;
            }

            return ! in_array($type, ['CHECK_OUT', 'BREAK_OUT', 'BREAK_IN'], true)
                || $horodatage->format('H:i:s') >= $heureDebutJournee . ':00';
        }));
    }

    /**
     * Les horaires de shift ne définissent pas encore de créneaux de pause.
     * Les pointages réels appariés restent toutefois déduits des heures travaillées.
     *
     * @param array<int, array<string, mixed>> $pointagesSelectionnes
     * @param array<int, array<string, mixed>> $pointages
     * @return array<int, array<string, mixed>>
     */
    protected function ajouterPauseShiftPourCalculHeures(
        array $pointagesSelectionnes,
        array $pointages
    ): array {
        $arrivee = null;
        $depart = null;
        foreach ($pointagesSelectionnes as $pointage) {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            if ($type === 'CHECK_IN') {
                $arrivee = new DateTimeImmutable((string) $pointage['date_pointage']);
            } elseif ($type === 'CHECK_OUT') {
                $depart = new DateTimeImmutable((string) $pointage['date_pointage']);
            }
        }
        if ($arrivee === null || $depart === null) {
            return $pointagesSelectionnes;
        }

        $debutPause = null;
        $finPause = null;
        foreach ($pointages as $pointage) {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            $instant = new DateTimeImmutable((string) $pointage['date_pointage']);
            if ($instant <= $arrivee || $instant >= $depart) {
                continue;
            }
            if ($type === 'BREAK_OUT' && $debutPause === null) {
                $debutPause = $pointage;
            } elseif ($type === 'BREAK_IN' && $debutPause !== null && $finPause === null
                && $instant > new DateTimeImmutable((string) $debutPause['date_pointage'])
            ) {
                $finPause = $pointage;
            }
        }
        if ($debutPause !== null && $finPause !== null) {
            $pointagesSelectionnes[] = $debutPause;
            $pointagesSelectionnes[] = $finPause;
        }

        return $pointagesSelectionnes;
    }

    /**
     * Calcule une durée de shift avec horodatages complets pour gérer minuit.
     *
     * @param array<int, array<string, mixed>> $pointages
     */
    protected function calculerHeuresShiftAvecPauses(array $pointages): float
    {
        $arrivee = null;
        $depart = null;
        $debutPause = null;
        $finPause = null;
        foreach ($pointages as $pointage) {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            $instant = new DateTimeImmutable((string) $pointage['date_pointage']);
            if ($type === 'CHECK_IN' && ($arrivee === null || $instant < $arrivee)) {
                $arrivee = $instant;
            } elseif ($type === 'CHECK_OUT' && ($depart === null || $instant > $depart)) {
                $depart = $instant;
            } elseif ($type === 'BREAK_OUT' && ($debutPause === null || $instant < $debutPause)) {
                $debutPause = $instant;
            } elseif ($type === 'BREAK_IN' && ($finPause === null || $instant > $finPause)) {
                $finPause = $instant;
            }
        }
        if ($arrivee === null || $depart === null || $depart <= $arrivee) {
            return 0.0;
        }

        $minutes = (int) floor(($depart->getTimestamp() - $arrivee->getTimestamp()) / 60);
        if ($debutPause !== null && $finPause !== null
            && $debutPause >= $arrivee && $finPause <= $depart && $finPause > $debutPause
        ) {
            $minutes -= (int) floor(($finPause->getTimestamp() - $debutPause->getTimestamp()) / 60);
        }

        return round(max(0, $minutes) / 60, 2);
    }

    /**
     * Calcule les pointages réellement associés avec leurs dates complètes,
     * notamment lorsque la sortie de journée est le lendemain.
     *
     * @param array<int, array<string, mixed>> $pointages
     */
    protected function calculerHeuresAvecHorodatages(array $pointages): float
    {
        $arrivee = null;
        $depart = null;
        $debutPause = null;
        $finPause = null;
        foreach ($pointages as $pointage) {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            $instant = new DateTimeImmutable((string) $pointage['date_pointage']);
            if ($type === 'CHECK_IN' && ($arrivee === null || $instant < $arrivee)) {
                $arrivee = $instant;
            } elseif ($type === 'CHECK_OUT' && ($depart === null || $instant > $depart)) {
                $depart = $instant;
            } elseif ($type === 'BREAK_OUT' && ($debutPause === null || $instant < $debutPause)) {
                $debutPause = $instant;
            } elseif ($type === 'BREAK_IN' && ($finPause === null || $instant > $finPause)) {
                $finPause = $instant;
            }
        }
        if ($arrivee === null || $depart === null || $depart <= $arrivee) {
            return $this->calculerHeuresTravaillees($pointages);
        }

        $minutes = (int) floor(($depart->getTimestamp() - $arrivee->getTimestamp()) / 60);
        if ($debutPause !== null && $finPause !== null
            && $debutPause >= $arrivee && $finPause <= $depart && $finPause > $debutPause
        ) {
            $minutes -= (int) floor(($finPause->getTimestamp() - $debutPause->getTimestamp()) / 60);
        }

        return round(max(0, $minutes) / 60, 2);
    }

    /** @return array<string, mixed> */
    protected function chargerParametresInterpretation(int $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->parametresParTenant)) {
            $configuration = $this->parametrageService->getConfiguration($tenantId);
            $this->parametresParTenant[$tenantId] = array_map(
                static fn (array $parametre): mixed => $parametre['valeur'],
                $configuration
            );
        }

        return $this->parametresParTenant[$tenantId];
    }

    /** @param array<string, mixed> $contrat */
    protected function contratActifCeJour(array $contrat, string $date): bool
    {
        $jours = json_decode((string) ($contrat['jours_travail'] ?? '[]'), true);
        if (! is_array($jours) || $jours === []) {
            return false;
        }

        return in_array((int) (new DateTimeImmutable($date))->format('N'), array_map('intval', $jours), true);
    }

    /**
     * @param array<int, array<string, mixed>> $plages
     * @param array<string, mixed> $contrat
     * @return array<int, array<string, mixed>>
     */
    protected function adapterPlagesAuContrat(array $plages, array $contrat): array
    {
        $mode = (string) $contrat['mode_travail'];
        $typesAutorises = match ($mode) {
            'MI_TEMPS_MATIN' => ['CHECK_IN', 'BREAK_OUT'],
            'MI_TEMPS_APRES_MIDI' => ['BREAK_IN', 'CHECK_OUT'],
            default => ['CHECK_IN', 'BREAK_OUT', 'BREAK_IN', 'CHECK_OUT'],
        };
        $plages = array_values(array_filter(
            $plages,
            static fn (array $plage): bool => in_array((string) ($plage['type'] ?? ''), $typesAutorises, true)
        ));

        $typeDebut = $mode === 'MI_TEMPS_APRES_MIDI' ? 'BREAK_IN' : 'CHECK_IN';
        $typeFin = $mode === 'MI_TEMPS_MATIN' ? 'BREAK_OUT' : 'CHECK_OUT';
        foreach ($plages as &$plage) {
            if (($plage['type'] ?? null) === $typeDebut && ! empty($contrat['heure_debut'])) {
                $plage['debut'] = substr((string) $contrat['heure_debut'], 0, 5);
            }
            if (($plage['type'] ?? null) === $typeFin && ! empty($contrat['heure_fin'])) {
                $plage['fin'] = substr((string) $contrat['heure_fin'], 0, 5);
            }
        }
        unset($plage);

        return $plages;
    }

    /** @param ?array<string, mixed> $contrat */
    protected function heureCibleContrat(?array $contrat, string $evenement): ?string
    {
        if ($contrat === null) {
            return null;
        }

        $mode = (string) $contrat['mode_travail'];
        $champ = match ([$mode, $evenement]) {
            ['TEMPS_PLEIN', 'CHECK_IN'], ['MI_TEMPS_MATIN', 'CHECK_IN'], ['MI_TEMPS_APRES_MIDI', 'BREAK_IN'] => 'heure_debut',
            ['TEMPS_PLEIN', 'CHECK_OUT'], ['MI_TEMPS_MATIN', 'BREAK_OUT'], ['MI_TEMPS_APRES_MIDI', 'CHECK_OUT'] => 'heure_fin',
            default => null,
        };
        if ($champ === null || empty($contrat[$champ])) {
            return null;
        }

        return substr((string) $contrat[$champ], 0, 5);
    }

    /**
     * @param array<string, mixed> $contexte
     */
    protected function tenantTravailleCeJour(array $contexte, string $date): bool
    {
        return in_array(
            (int) (new DateTimeImmutable($date))->format('N'),
            JourTravailleService::normaliserJours($contexte['jours_travailles'] ?? null),
            true
        );
    }

    protected function maintenantTenant(int $tenantId): DateTimeImmutable
    {
        $timezone = isset($this->db)
            ? (new TenantTimezoneService($this->db))->forTenant($tenantId)
            : new DateTimeZone('Africa/Bujumbura');

        return new DateTimeImmutable('now', $timezone);
    }

    /** @param array<string, mixed> $stage */
    protected function echeanceArrivee(array $stage, string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $heure = $stage['mode'] === 'PLAGE'
            ? (string) $stage['fin']
            : (string) ($stage['cible'] ?? $stage['debut']);
        $dateAttendue = (new DateTimeImmutable($date, $timezone))
            ->modify(sprintf('%+d day', (int) ($stage['jour_offset'] ?? 0)))
            ->format('Y-m-d');
        $echeance = new DateTimeImmutable($dateAttendue . ' ' . $heure . ':00', $timezone);

        return $stage['mode'] === 'PLAGE'
            ? $echeance
            : $echeance->modify('+' . (int) $stage['tolerance'] . ' minutes');
    }

    /**
     * @param array<string, mixed> $stage
     * @param array<int, array<string, mixed>> $pointages
     * @param array<int, bool> $utilises
     * @return array{index:int,pointage:array<string,mixed>}|null
     */
    protected function pointageLePlusProche(
        array $stage,
        array $pointages,
        array $utilises,
        string $date,
        int $ecartMax
    ): ?array {
        $reference = $stage['cible'] ?? $stage['debut'];
        if ($stage['mode'] === 'PLAGE') {
            $debut = $this->convertirHeureEnMinutes((string) $stage['debut']);
            $fin = $this->convertirHeureEnMinutes((string) $stage['fin']);
            $milieu = intdiv($debut + $fin, 2);
            $reference = sprintf('%02d:%02d', intdiv($milieu, 60), $milieu % 60);
        }
        $meilleur = null;
        $meilleurEcart = PHP_INT_MAX;
        foreach ($pointages as $index => $pointage) {
            if (isset($utilises[$index])
                || $this->normaliserType((string) ($pointage['type_evenement'] ?? '')) !== $stage['evenement']
            ) {
                continue;
            }
            $heurePointage = new DateTimeImmutable((string) $pointage['date_pointage']);
            $referenceDate = (new DateTimeImmutable($date))
                ->modify(sprintf('%+d day', (int) ($stage['jour_offset'] ?? 0)))
                ->format('Y-m-d');
            $heureCible = new DateTimeImmutable($referenceDate . ' ' . $reference . ':00');
            $jourSuivant = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            if ($heurePointage->format('Y-m-d') === $jourSuivant) {
                $limiteLendemain = $stage['heure_limite_lendemain'] ?? null;
                if (empty($stage['autoriser_lendemain'])
                    || $limiteLendemain === null
                    || $heurePointage->format('H:i:s') >= $limiteLendemain . ':00'
                ) {
                    continue;
                }
                $ecart = 0;
            } elseif ($stage['evenement'] === 'CHECK_IN'
                && ! empty($stage['autoriser_avance'])
                && $heurePointage <= $heureCible
            ) {
                $ecart = 0;
            } elseif ($stage['evenement'] === 'CHECK_OUT'
                && ! empty($stage['autoriser_retard'])
                && $heurePointage >= $heureCible
            ) {
                $ecart = 0;
            } else {
                $ecart = (int) floor(abs($heurePointage->getTimestamp() - $heureCible->getTimestamp()) / 60);
            }
            if ($ecart <= $ecartMax && $ecart < $meilleurEcart) {
                $meilleur = ['index' => $index, 'pointage' => $pointage];
                $meilleurEcart = $ecart;
            }
        }

        return $meilleur;
    }

    /** @param array<string, mixed> $stage */
    protected function ecartStageMinutes(array $stage, string $date, DateTimeImmutable $reel): int
    {
        $reference = $stage['cible'] ?? $stage['debut'];
        $attenduDate = (new DateTimeImmutable($date))
            ->modify(sprintf('%+d day', (int) ($stage['jour_offset'] ?? 0)))
            ->format('Y-m-d');
        $attendu = new DateTimeImmutable($attenduDate . ' ' . $reference . ':00', $reel->getTimezone());

        return (int) floor(($reel->getTimestamp() - $attendu->getTimestamp()) / 60);
    }

    protected function normaliserHeureOptionnelle(mixed $heure): ?string
    {
        if ($heure === null || $heure === '') {
            return null;
        }
        $texte = substr((string) $heure, 0, 8);
        $format = strlen($texte) === 8 ? 'H:i:s' : 'H:i';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $texte);
        if ($date === false || $date->format($format) !== $texte) {
            throw new \UnexpectedValueException('Heure de créneau invalide.');
        }

        return $date->format('H:i');
    }

    protected function heureDansPlage(string $heure, string $debut, string $fin): bool
    {
        $minutes = $this->convertirHeureEnMinutes(substr($heure, 0, 5));

        return $minutes >= $this->convertirHeureEnMinutes($debut)
            && $minutes <= $this->convertirHeureEnMinutes($fin);
    }

    /** @param array<int, string> $statuts */
    protected function aggregerStatuts(array $statuts): string
    {
        $priorite = ['ABSENT', 'HORS_CRENEAU', 'INCOMPLET', 'RETARD', 'ANTICIPE', 'ANORMAL', 'PRESENT'];
        foreach ($priorite as $statut) {
            if (in_array($statut, $statuts, true)) {
                return $statut;
            }
        }

        return 'PRESENT';
    }

    /** @param array<string, mixed> $horaire */
    protected function heuresNormalesHoraire(array $horaire): float
    {
        $heures = (float) ($horaire['heures_travail_normales'] ?? 0);
        if ($heures > 0) {
            return $heures;
        }

        $plages = $horaire['plages_horaires'] ?? [];
        $debut = null;
        $fin = null;
        $pauseDebut = null;
        $pauseFin = null;
        foreach ($plages as $plage) {
            $type = $this->normaliserType((string) ($plage['type'] ?? ''));
            $heure = (string) ($plage['debut'] ?? '');
            if ($type === 'CHECK_IN') {
                $debut = $heure;
            } elseif ($type === 'CHECK_OUT') {
                $fin = $heure;
            } elseif ($type === 'BREAK_OUT') {
                $pauseDebut = $heure;
            } elseif ($type === 'BREAK_IN') {
                $pauseFin = $heure;
            }
        }
        if ($debut === null || $fin === null) {
            return 0.0;
        }
        $minutes = $this->convertirHeureEnMinutes(substr($fin, 0, 5))
            - $this->convertirHeureEnMinutes(substr($debut, 0, 5));
        if ($minutes < 0 || ! empty($horaire['est_shift']) && $this->shiftTraverseMinuit($horaire)) {
            $minutes += 1440;
        }
        if ($pauseDebut !== null && $pauseFin !== null) {
            $dureePause = $this->convertirHeureEnMinutes(substr($pauseFin, 0, 5))
                - $this->convertirHeureEnMinutes(substr($pauseDebut, 0, 5));
            if ($dureePause < 0) {
                $dureePause += 1440;
            }
            $minutes -= $dureePause;
        }

        return round(max(0, $minutes) / 60, 2);
    }

    protected function statutEtapePersistable(string $champ, string $statut): string
    {
        $statutsAutorises = match ($champ) {
            'statut_arrivee' => ['OK', 'RETARD', 'ABSENT', 'INCOMPLET', 'HORS_CRENEAU'],
            'statut_debut_pause' => ['OK', 'ANORMAL', 'INCOMPLET', 'HORS_CRENEAU'],
            'statut_fin_pause' => ['OK', 'RETARD', 'INCOMPLET', 'HORS_CRENEAU'],
            'statut_depart' => ['OK', 'ANTICIPE', 'INCOMPLET', 'HORS_CRENEAU'],
            default => throw new \UnexpectedValueException('Champ de statut de créneau inconnu.'),
        };

        if (in_array($statut, $statutsAutorises, true)) {
            return $statut;
        }

        return match ($champ) {
            'statut_debut_pause' => 'ANORMAL',
            'statut_fin_pause' => 'HORS_CRENEAU',
            default => 'OK',
        };
    }

    /**
     * Calcule les statistiques quotidiennes en chargeant les employés,
     * horaires et pointages par lots plutôt qu'une requête par employé/jour.
     *
     * @return array{
     *   periode:array{debut:string,fin:string},
     *   granularite:string,
    *   donnees:array<int,array{date:string,presents:int,retards:int,absents:int,en_cours:int,feries:int,repos:int,total:int}>,
    *   totaux:array{presents:int,retards:int,absents:int,en_cours:int,feries:int,repos:int,total:int}
     * }
     */
    public function getStatsPresenceParJour(
        int $tenantId,
        string $dateDebut,
        string $dateFin,
        string $granularite = 'jour',
        ?array $employeIdsAutorises = null
    ): array {
        $this->normaliserDate($dateDebut);
        $this->normaliserDate($dateFin);
        if ($tenantId < 1 || $dateDebut > $dateFin) {
            throw new \InvalidArgumentException('Tenant ou plage de dates invalide.');
        }
        if (! in_array($granularite, ['jour', 'semaine', 'mois'], true)) {
            throw new \InvalidArgumentException('La granularité doit être jour, semaine ou mois.');
        }
        $maintenantTenant = new DateTimeImmutable(
            'now',
            isset($this->db)
                ? (new TenantTimezoneService($this->db))->forTenant($tenantId)
                : new DateTimeZone('Africa/Bujumbura')
        );

        $debut = new DateTimeImmutable($dateDebut);
        $fin = new DateTimeImmutable($dateFin);
        if ((int) $debut->diff($fin)->days > 365) {
            throw new \InvalidArgumentException('La période ne peut pas dépasser 366 jours.');
        }

        $employesQuery = $this->db->table('employes')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null);
        if ($employeIdsAutorises !== null) {
            if ($employeIdsAutorises === []) {
                return $this->construireStatistiquesPresence([], $dateDebut, $dateFin, $granularite);
            }
            $employesQuery->whereIn('id', array_values(array_unique(array_map('intval', $employeIdsAutorises))));
        }
        $employes = $employesQuery->get()->getResultArray();
        $employeIds = array_map(static fn (array $employe): int => (int) $employe['id'], $employes);

        if ($employeIds === []) {
            return $this->construireStatistiquesPresence([], $dateDebut, $dateFin, $granularite);
        }

        $horairesParEmploye = [];
        $affectations = $this->db->table('employe_horaire eh')
            ->select('eh.employe_id, eh.date_debut, eh.date_fin, th.id AS horaire_id, th.nom, th.plages_horaires, th.seuil_heures_anormal')
            ->join('tenant_horaires th', 'th.id = eh.horaire_id AND th.tenant_id = eh.tenant_id')
            ->where('eh.tenant_id', $tenantId)
            ->whereIn('eh.employe_id', $employeIds)
            ->where('eh.date_debut <=', $dateFin)
            ->groupStart()
            ->where('eh.date_fin', null)
            ->orWhere('eh.date_fin >=', $dateDebut)
            ->groupEnd()
            ->where('th.actif', 1)
            ->where('th.deleted_at', null)
            ->orderBy('eh.date_debut', 'DESC')
            ->get()
            ->getResultArray();
        foreach ($affectations as $affectation) {
            $horairesParEmploye[(int) $affectation['employe_id']][] = $this->normaliserHoraire($affectation);
        }

        $shiftsParEmploye = [];
        $shiftAffectations = $this->db->table('shift_assignments sa')
            ->select('sa.employe_id, sa.date_debut, sa.date_fin, s.nom, s.code, s.heure_debut, s.heure_fin, s.couleur')
            ->join('shifts s', 's.id = sa.shift_id AND s.tenant_id = sa.tenant_id')
            ->where('sa.tenant_id', $tenantId)
            ->whereIn('sa.employe_id', $employeIds)
            ->where('sa.actif', 1)
            ->where('s.actif', 1)
            ->where('sa.date_debut <=', $dateFin)
            ->groupStart()
                ->where('sa.date_fin', null)
                ->orWhere('sa.date_fin >=', $dateDebut)
            ->groupEnd()
            ->where('sa.deleted_at', null)
            ->where('s.deleted_at', null)
            ->orderBy('sa.date_debut', 'DESC')
            ->get()
            ->getResultArray();
        foreach ($shiftAffectations as $affectation) {
            $shiftsParEmploye[(int) $affectation['employe_id']][] = $affectation;
        }

        $horaireDefautRow = $this->db->table('tenant_horaires')
            ->where('tenant_id', $tenantId)
            ->where('est_defaut', 1)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();
        $horaireDefaut = $horaireDefautRow === null ? [] : $this->normaliserHoraire($horaireDefautRow);

        $pointagesParJourEtEmploye = [];
        $pointages = $this->db->table('pointages')
            ->select('employe_id, type_evenement, date_pointage')
            ->where('tenant_id', $tenantId)
            ->whereIn('employe_id', $employeIds)
            ->where('date_pointage >=', $dateDebut . ' 00:00:00')
            ->where('date_pointage <=', (new DateTimeImmutable($dateFin))->modify('+1 day')->format('Y-m-d') . ' 23:59:59')
            ->where('statut', 'VALIDE')
            ->where('deleted_at', null)
            ->orderBy('date_pointage', 'ASC')
            ->get()
            ->getResultArray();
        foreach ($pointages as $pointage) {
            $jour = substr((string) $pointage['date_pointage'], 0, 10);
            $employeId = (int) $pointage['employe_id'];
            $pointagesParJourEtEmploye[$jour][$employeId][] = $pointage;
        }

        $joursFeries = $this->db->table('jours_feries')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->groupStart()
                ->groupStart()
                    ->where('date >=', $dateDebut)
                    ->where('date <=', $dateFin)
                ->groupEnd()
                ->orWhere('recurrent', 1)
            ->groupEnd()
            ->orderBy('date', 'ASC')
            ->get()
            ->getResultArray();
        $joursFeriesParDate = [];
        for ($dateFerie = $debut; $dateFerie <= $fin; $dateFerie = $dateFerie->modify('+1 day')) {
            $dateTexte = $dateFerie->format('Y-m-d');
            $joursFeriesParDate[$dateTexte] = $this->jourFeriePourDate($joursFeries, $dateTexte);
        }
        $tenant = $this->db->table('tenants')
            ->select('jours_travailles, actif_regles_nouvelles')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            throw new \RuntimeException('Tenant introuvable.');
        }
        $joursTravailles = JourTravailleService::normaliserJours($tenant['jours_travailles'] ?? null);
        $utiliserNouvellesRegles = (int) ($tenant['actif_regles_nouvelles'] ?? 0) === 1;
        if ($this->db->tableExists('tenant_regles_pointage_versions')) {
            $utiliserNouvellesRegles = $utiliserNouvellesRegles
                || $this->db->table('tenant_regles_pointage_versions')
                    ->where('tenant_id', $tenantId)
                    ->where('actif', 1)
                    ->where('date_effet <=', $dateFin)
                    ->countAllResults() > 0;
        }
        if ($this->db->tableExists('employe_contrats_horaire')) {
            $utiliserNouvellesRegles = $utiliserNouvellesRegles
                || $this->db->table('employe_contrats_horaire')
                    ->where('tenant_id', $tenantId)
                    ->where('actif', 1)
                    ->where('deleted_at', null)
                    ->countAllResults() > 0;
        }
        $serviceInterpretation = $utiliserNouvellesRegles ? new self(null, $this->db) : null;

        $parJour = [];
        $date = $debut;
        while ($date <= $fin) {
            $jour = $date->format('Y-m-d');
            $statistiques = ['presents' => 0, 'retards' => 0, 'absents' => 0, 'en_cours' => 0, 'feries' => 0, 'repos' => 0, 'sans_jugement' => 0];

            if ($serviceInterpretation !== null) {
                $statistiques = $this->statistiquesJourInterpretees(
                    $serviceInterpretation,
                    $employeIds,
                    $jour
                );
            } else {

            foreach ($employeIds as $employeId) {
                $jourFerie = $joursFeriesParDate[$jour] ?? null;
                if ($jourFerie !== null && (int) $jourFerie['travaille'] === 0) {
                    $statistiques['feries']++;
                    continue;
                }
                if ($jourFerie === null && ! in_array((int) $date->format('N'), $joursTravailles, true)) {
                    $statistiques['repos']++;
                    continue;
                }

                $shift = null;
                foreach ($shiftsParEmploye[$employeId] ?? [] as $affectation) {
                    if ($affectation['date_debut'] <= $jour
                        && ($affectation['date_fin'] === null || $affectation['date_fin'] >= $jour)
                    ) {
                        $shift = $affectation;
                        break;
                    }
                }
                $horaire = $shift === null ? $horaireDefaut : $this->horaireDepuisShift($shift, $jour);
                if ($shift === null) {
                    foreach ($horairesParEmploye[$employeId] ?? [] as $affectation) {
                        if ($affectation['date_debut'] <= $jour
                            && ($affectation['date_fin'] === null || $affectation['date_fin'] >= $jour)
                        ) {
                            $horaire = $affectation;
                            break;
                        }
                    }
                }

                $resultat = $this->calculerDepuisDonnees(
                    $horaire,
                    [
                        ...($pointagesParJourEtEmploye[$jour][$employeId] ?? []),
                        ...($shift !== null && $this->shiftTraverseMinuit($shift)
                            ? ($pointagesParJourEtEmploye[(new DateTimeImmutable($jour))->modify('+1 day')->format('Y-m-d')][$employeId] ?? [])
                            : []),
                    ],
                    $jour,
                    $jour === $maintenantTenant->format('Y-m-d') ? $maintenantTenant : null
                );
                if ($jourFerie !== null) {
                    $statistiques['feries']++;
                    if ($resultat['statut'] === 'ABSENT') {
                        continue;
                    }
                }
                match ($resultat['statut']) {
                    'ABSENT' => $statistiques['absents']++,
                    'RETARD' => $statistiques['retards']++,
                    default => $statistiques['presents']++,
                };
            }
            }

            $parJour[] = [
                'date' => $jour,
                ...$statistiques,
                'total' => count($employeIds),
            ];
            $date = $date->modify('+1 day');
        }

        return $this->construireStatistiquesPresence($parJour, $dateDebut, $dateFin, $granularite);
    }

    /**
     * @param list<int> $employeIds
     * @return array{presents:int,retards:int,absents:int,en_cours:int,feries:int,repos:int,sans_jugement:int}
     */
    private function statistiquesJourInterpretees(self $service, array $employeIds, string $date): array
    {
        $statistiques = ['presents' => 0, 'retards' => 0, 'absents' => 0, 'en_cours' => 0, 'feries' => 0, 'repos' => 0, 'sans_jugement' => 0];
        foreach ($employeIds as $employeId) {
            $resultat = $service->interpreterJournee($employeId, $date);
            $statut = (string) ($resultat['statut_global'] ?? $resultat['statut'] ?? 'ANORMAL');
            if (in_array($resultat['details']['evaluation'] ?? null, ['AUCUN_JUGEMENT', 'SANS_JUGEMENT'], true)) {
                $statistiques['sans_jugement']++;
                continue;
            }
            if ($statut === 'REPOS' || ($resultat['details']['evaluation'] ?? null) === 'REPOS') {
                $statistiques['repos']++;
                continue;
            }
            if ($statut === 'FERIE') {
                $statistiques['feries']++;
                continue;
            }

            match ($statut) {
                'ABSENT' => $statistiques['absents']++,
                'RETARD' => $statistiques['retards']++,
                'EN_COURS' => $statistiques['en_cours']++,
                default => $statistiques['presents']++,
            };
        }

        return $statistiques;
    }

    /**
     * @param array<string, mixed> $horaire
     * @param array<int, array<string, mixed>> $pointages
     * @return array{statut:string,heures_travaillees:float,retard_total_minutes:int,pointages_manquants:array<int,string>,analyse_plages:array<int,array<string,mixed>>,horaire_nom:?string}
     */
    protected function calculerDepuisDonnees(
        array $horaire,
        array $pointages,
        ?string $dateEvaluation = null,
        ?DateTimeImmutable $maintenant = null
    ): array
    {
        $resultat = [
            'statut' => 'NORMAL',
            'heures_travaillees' => 0.0,
            'retard_total_minutes' => 0,
            'pointages_manquants' => [],
            'analyse_plages' => [],
            'horaire_nom' => $horaire['nom'] ?? null,
        ];

        if ($pointages === []) {
            $resultat['statut'] = 'ABSENT';
            $aVenir = false;
            foreach ($horaire['plages_horaires'] ?? [] as $plage) {
                $type = $this->normaliserType((string) ($plage['type'] ?? 'INCONNU'));
                $attendu = $this->heureReferencePlage($plage, $type);
                $etapeFuture = $this->etapeFuture($horaire, $dateEvaluation, $maintenant, $attendu, (int) ($plage['jour_offset'] ?? 0));
                if ($etapeFuture) {
                    $aVenir = true;
                } else {
                    $resultat['pointages_manquants'][] = sprintf('%s à %s', $type, $attendu);
                }
                $resultat['analyse_plages'][] = [
                    'type' => $type,
                    'attendu' => $attendu,
                    'reel' => null,
                    'ecart_min' => null,
                    'statut' => $etapeFuture ? 'A_VENIR' : 'MANQUANT',
                ];
            }
            if ($aVenir && $resultat['pointages_manquants'] === []) {
                $resultat['statut'] = 'EN_COURS';
            }

            return $resultat;
        }

        if ($horaire === [] || ! isset($horaire['plages_horaires']) || ! is_array($horaire['plages_horaires'])) {
            $resultat['statut'] = 'ANORMAL';

            return $resultat;
        }

        $pointagesUtilises = [];
        $pointagesSelectionnes = [];
        $pointagesParType = [];
        $sortieAnticipee = false;
        foreach ($pointages as $index => $pointage) {
            $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
            $pointagesParType[$type][] = ['index' => $index, 'pointage' => $pointage];
        }

        foreach ($horaire['plages_horaires'] as $plage) {
            $type = $this->normaliserType((string) ($plage['type'] ?? ''));
            $attendu = $this->heureReferencePlage($plage, $type);
            $jourOffset = (int) ($plage['jour_offset'] ?? 0);
            $correspondant = ! empty($horaire['est_shift'])
                ? $this->prendrePointageShift($pointagesParType[$type] ?? [], $pointagesUtilises, (string) $horaire['date_calcul'], $attendu, $jourOffset)
                : $this->prendrePointageCorrespondant($pointagesParType[$type] ?? [], $pointagesUtilises);

            if ($correspondant === null) {
                $aVenir = $this->etapeFuture($horaire, $dateEvaluation, $maintenant, $attendu, $jourOffset);
                if (! $aVenir) {
                    $resultat['pointages_manquants'][] = sprintf('%s à %s', $type, $attendu);
                }
                $resultat['analyse_plages'][] = [
                    'type' => $type,
                    'attendu' => $attendu,
                    'reel' => null,
                    'ecart_min' => null,
                    'statut' => $aVenir ? 'A_VENIR' : 'MANQUANT',
                ];
                continue;
            }

            $pointagesUtilises[$correspondant['index']] = true;
            $pointagesSelectionnes[] = $correspondant['pointage'];
            $reel = $this->extraireHeure((string) $correspondant['pointage']['date_pointage']);
            $ecart = ! empty($horaire['est_shift'])
                ? $this->calculerEcartShiftMinutes((string) $horaire['date_calcul'], $attendu, $jourOffset, (string) $correspondant['pointage']['date_pointage'])
                : $this->calculerEcartMinutes($attendu, $reel);
            $estEntree = in_array($type, ['CHECK_IN', 'BREAK_IN'], true);
            $tolerance = ! empty($horaire['est_shift'])
                ? self::TOLERANCE_RETARD_MINUTES
                : max(0, (int) ($plage['tolerance_min'] ?? self::TOLERANCE_HORAIRE_DEFAUT_MINUTES));
            $statut = $estEntree && $ecart > $tolerance ? 'RETARD' : 'OK';
            if (! empty($horaire['est_shift']) && $type === 'CHECK_OUT' && $ecart < -self::TOLERANCE_RETARD_MINUTES) {
                $sortieAnticipee = true;
            }
            if ($statut === 'RETARD') {
                $resultat['retard_total_minutes'] += $ecart;
            }

            $resultat['analyse_plages'][] = [
                'type' => $type,
                'attendu' => $attendu,
                'reel' => $reel,
                'ecart_min' => $ecart,
                'statut' => $statut,
            ];
        }

        $resultat['heures_travaillees'] = $this->calculerHeuresTravaillees(
            ! empty($horaire['est_shift']) ? $pointagesSelectionnes : $pointages,
            ! empty($horaire['est_shift'])
        );
        $aVenir = count(array_filter($resultat['analyse_plages'], static fn (array $plage): bool => $plage['statut'] === 'A_VENIR')) > 0;
        $horaireTermine = ! $aVenir;
        if ($resultat['pointages_manquants'] !== []
            || $sortieAnticipee
            || ($horaireTermine && $resultat['heures_travaillees'] < (float) ($horaire['seuil_heures_anormal'] ?? 0))
        ) {
            $resultat['statut'] = 'ANORMAL';
        } elseif ($resultat['retard_total_minutes'] > 0) {
            $resultat['statut'] = 'RETARD';
        } elseif ($aVenir) {
            $resultat['statut'] = 'EN_COURS';
        }

        return $resultat;
    }

    /** @param array<string,mixed> $plage */
    private function heureReferencePlage(array $plage, string $type): string
    {
        if (in_array($type, ['CHECK_OUT', 'BREAK_IN'], true) && isset($plage['fin'])) {
            return (string) $plage['fin'];
        }

        return (string) ($plage['debut'] ?? '');
    }

    /** Détermine si une étape planifiée n'est pas encore exigible. */
    private function etapeFuture(
        array $horaire,
        ?string $dateEvaluation,
        ?DateTimeImmutable $maintenant,
        string $heure,
        int $jourOffset
    ): bool {
        if ($dateEvaluation === null || $maintenant === null || ! preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $heure)) {
            return false;
        }

        $dateReference = ! empty($horaire['est_shift'])
            ? (string) ($horaire['date_calcul'] ?? $dateEvaluation)
            : $dateEvaluation;
        $dateAttendue = (new DateTimeImmutable($dateReference, $maintenant->getTimezone()))
            ->modify(($jourOffset >= 0 ? '+' : '') . $jourOffset . ' days')
            ->format('Y-m-d');
        $dateHeureAttendue = new DateTimeImmutable($dateAttendue . ' ' . $heure, $maintenant->getTimezone());

        return $dateHeureAttendue > $maintenant;
    }

    /**
     * @param array<string, mixed> $horaire
     * @return array<string, mixed>
     */
    private function normaliserHoraire(array $horaire): array
    {
        $plages = $horaire['plages_horaires'] ?? [];
        if (is_string($plages)) {
            $plages = json_decode($plages, true);
        }
        $horaire['plages_horaires'] = is_array($plages) ? $plages : [];
        $horaire['date_debut'] = $horaire['date_debut'] ?? null;
        $horaire['date_fin'] = $horaire['date_fin'] ?? null;

        return $horaire;
    }

    /**
     * @param array<int, array{date:string,presents:int,retards:int,absents:int,total:int}> $parJour
     * @return array<string, mixed>
     */
    private function construireStatistiquesPresence(
        array $parJour,
        string $dateDebut,
        string $dateFin,
        string $granularite
    ): array {
        $totaux = ['presents' => 0, 'retards' => 0, 'absents' => 0, 'en_cours' => 0, 'feries' => 0, 'repos' => 0, 'sans_jugement' => 0, 'total' => 0];
        $groupes = [];

        foreach ($parJour as $ligne) {
            foreach (['presents', 'retards', 'absents', 'en_cours', 'feries', 'repos', 'sans_jugement', 'total'] as $cle) {
                $totaux[$cle] += $ligne[$cle];
            }
            $cleGroupe = match ($granularite) {
                'semaine' => (new DateTimeImmutable($ligne['date']))->modify('monday this week')->format('Y-m-d'),
                'mois' => substr($ligne['date'], 0, 7) . '-01',
                default => $ligne['date'],
            };
            $groupes[$cleGroupe] ??= ['date' => $cleGroupe, 'presents' => 0, 'retards' => 0, 'absents' => 0, 'en_cours' => 0, 'feries' => 0, 'repos' => 0, 'sans_jugement' => 0, 'total' => 0];
            foreach (['presents', 'retards', 'absents', 'en_cours', 'feries', 'repos', 'sans_jugement', 'total'] as $cle) {
                $groupes[$cleGroupe][$cle] += $ligne[$cle];
            }
        }

        return [
            'periode' => ['debut' => $dateDebut, 'fin' => $dateFin],
            'granularite' => $granularite,
            'donnees' => array_values($groupes),
            'totaux' => $totaux,
        ];
    }

    /** @return array<string, mixed> */
    protected function trouverHoraire(int $employeId, string $date): array
    {
        return $this->horaireService->getHoraireEmploye($employeId, $date);
    }

    protected function estJourSemaineTravaille(int $employeId, string $date): bool
    {
        $tenant = $this->db->table('employes e')
            ->select('t.jours_travailles')
            ->join('tenants t', 't.id = e.tenant_id')
            ->where('e.id', $employeId)
            ->where('e.deleted_at', null)
            ->where('t.deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            throw new \RuntimeException('Employé ou tenant introuvable.');
        }

        return in_array(
            (int) (new DateTimeImmutable($date))->format('N'),
            JourTravailleService::normaliserJours($tenant['jours_travailles'] ?? null),
            true
        );
    }

    /** @return ?array<string, mixed> */
    protected function trouverJourFerie(int $employeId, string $date): ?array
    {
        $employe = $this->db->table('employes')
            ->select('tenant_id')
            ->where('id', $employeId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($employe === null) {
            return null;
        }

        return (new JourFerieService())->getJourFerie((int) $employe['tenant_id'], $date);
    }

    /** @param array<int, array<string, mixed>> $joursFeries
     * @return ?array<string, mixed>
     */
    private function jourFeriePourDate(array $joursFeries, string $date): ?array
    {
        $moisJour = substr($date, 5);
        $recurrent = null;
        foreach ($joursFeries as $jourFerie) {
            if ($jourFerie['date'] === $date) {
                return $jourFerie;
            }
            if ((int) $jourFerie['recurrent'] === 1 && substr((string) $jourFerie['date'], 5) === $moisJour) {
                $recurrent = $jourFerie;
            }
        }

        return $recurrent;
    }

    /** @param array<string, mixed> $jourFerie
     * @return array<string, mixed>
     */
    private function resultatJourFerie(array $jourFerie): array
    {
        return [
            'statut' => 'FERIE',
            'heures_travaillees' => 0.0,
            'retard_total_minutes' => 0,
            'pointages_manquants' => [],
            'analyse_plages' => [],
            'horaire_nom' => 'Jour férié',
            'jour_ferie' => true,
            'jour_ferie_nom' => $jourFerie['nom'],
            'ferie_travail' => (int) $jourFerie['travaille'] === 1,
            'prime_potentielle' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function resultatRepos(): array
    {
        return [
            'statut' => 'REPOS',
            'heures_travaillees' => 0.0,
            'retard_total_minutes' => 0,
            'pointages_manquants' => [],
            'analyse_plages' => [],
            'horaire_nom' => 'Repos',
            'jour_ferie' => false,
            'raison_non_travaille' => 'WEEKEND',
            'prime_potentielle' => false,
        ];
    }

    /** @return ?array<string, mixed> */
    protected function trouverShift(int $employeId, string $date): ?array
    {
        $shift = $this->db->table('shift_assignments sa')
            ->select('s.nom, s.code, s.heure_debut, s.heure_fin')
            ->join('shifts s', 's.id = sa.shift_id AND s.tenant_id = sa.tenant_id')
            ->join('employes e', 'e.id = sa.employe_id AND e.tenant_id = sa.tenant_id')
            ->where('sa.employe_id', $employeId)
            ->where('sa.actif', 1)
            ->where('s.actif', 1)
            ->where('sa.date_debut <=', $date)
            ->groupStart()
                ->where('sa.date_fin', null)
                ->orWhere('sa.date_fin >=', $date)
            ->groupEnd()
            ->where('sa.deleted_at', null)
            ->where('s.deleted_at', null)
            ->orderBy('sa.date_debut', 'DESC')
            ->get()
            ->getRowArray();

        return $shift === null ? null : $shift;
    }

    /** @return array<int, array<string, mixed>> */
    protected function trouverPointagesDuJour(int $employeId, string $date, bool $inclureLendemain = false): array
    {
        $query = $this->db->table('pointages')
            ->where('employe_id', $employeId)
            ->where('date_pointage >=', $date . ' 00:00:00')
            ->where('date_pointage <=', $inclureLendemain
                ? (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 23:59:59'
                : $date . ' 23:59:59')
            ->where('statut', 'VALIDE')
            ->where('deleted_at', null)
            ->orderBy('date_pointage', 'ASC');

        return $query->get()->getResultArray();
    }

    /** @param array<int, array{index:int,pointage:array<string,mixed>}> $candidats */
    protected function prendrePointageCorrespondant(array $candidats, array $pointagesUtilises): ?array
    {
        foreach ($candidats as $candidat) {
            if (! isset($pointagesUtilises[$candidat['index']])) {
                return $candidat;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $pointages */
    protected function calculerHeuresTravaillees(array $pointages, bool $shift = false): float
    {
        if ($shift) {
            $heures = [];
            foreach ($pointages as $pointage) {
                $type = $this->normaliserType((string) ($pointage['type_evenement'] ?? ''));
                if (in_array($type, ['CHECK_IN', 'CHECK_OUT'], true) && ! isset($heures[$type])) {
                    $heures[$type] = new DateTimeImmutable((string) $pointage['date_pointage']);
                }
            }
            if (isset($heures['CHECK_IN'], $heures['CHECK_OUT'])) {
                $minutes = (int) floor(($heures['CHECK_OUT']->getTimestamp() - $heures['CHECK_IN']->getTimestamp()) / 60);

                return round(max(0, $minutes) / 60, 2);
            }

            return 0.0;
        }

        $heures = [];
        foreach ($pointages as $pointage) {
            $heures[$this->normaliserType((string) ($pointage['type_evenement'] ?? ''))] = $this->extraireHeure((string) $pointage['date_pointage']);
        }

        $minutes = $this->dureeEntre($heures['CHECK_IN'] ?? null, $heures['BREAK_OUT'] ?? null)
            + $this->dureeEntre($heures['BREAK_IN'] ?? null, $heures['CHECK_OUT'] ?? null);
        if ($minutes === 0) {
            $minutes = $this->dureeEntre($heures['CHECK_IN'] ?? null, $heures['CHECK_OUT'] ?? null);
        }

        return round($minutes / 60, 2);
    }

    protected function normaliserType(string $type): string
    {
        return match (strtoupper($type)) {
            'BREAK_START' => 'BREAK_OUT',
            'BREAK_END' => 'BREAK_IN',
            default => strtoupper($type),
        };
    }

    protected function calculerEcartMinutes(string $attendu, string $reel): int
    {
        return $this->convertirHeureEnMinutes($reel) - $this->convertirHeureEnMinutes($attendu);
    }

    protected function dureeEntre(?string $debut, ?string $fin): int
    {
        if ($debut === null || $fin === null) {
            return 0;
        }

        return max(0, $this->convertirHeureEnMinutes($fin) - $this->convertirHeureEnMinutes($debut));
    }

    /** @param array<int, array{index:int,pointage:array<string,mixed>}> $candidats */
    protected function prendrePointageShift(array $candidats, array $utilises, string $date, string $heure, int $jourOffset): ?array
    {
        $jourAttendu = (new DateTimeImmutable($date))->modify(sprintf('+%d day', $jourOffset))->format('Y-m-d');
        $attendu = new DateTimeImmutable($jourAttendu . ' ' . $heure . ':00');
        $meilleur = null;
        $ecartMin = PHP_INT_MAX;
        foreach ($candidats as $candidat) {
            if (isset($utilises[$candidat['index']])) {
                continue;
            }
            $ecart = (new DateTimeImmutable((string) $candidat['pointage']['date_pointage']))->getTimestamp() - $attendu->getTimestamp();
            if ($ecart >= -3 * 3600 && $ecart <= 12 * 3600 && abs($ecart) < $ecartMin) {
                $meilleur = $candidat;
                $ecartMin = abs($ecart);
            }
        }

        return $meilleur;
    }

    protected function calculerEcartShiftMinutes(string $date, string $heure, int $jourOffset, string $datePointage): int
    {
        $jourAttendu = (new DateTimeImmutable($date))->modify(sprintf('+%d day', $jourOffset))->format('Y-m-d');
        $attendu = new DateTimeImmutable($jourAttendu . ' ' . $heure . ':00');
        $reel = new DateTimeImmutable($datePointage);

        return (int) floor(($reel->getTimestamp() - $attendu->getTimestamp()) / 60);
    }

    /** @param array<string, mixed> $shift */
    protected function shiftTraverseMinuit(array $shift): bool
    {
        return substr((string) $shift['heure_fin'], 0, 8) <= substr((string) $shift['heure_debut'], 0, 8);
    }

    /**
     * @param array<string, mixed> $shift
     * @return array<string, mixed>
     */
    protected function horaireDepuisShift(array $shift, string $date): array
    {
        $traverseMinuit = $this->shiftTraverseMinuit($shift);
        $heureDebut = substr((string) $shift['heure_debut'], 0, 5);
        $heureFin = substr((string) $shift['heure_fin'], 0, 5);

        return [
            'nom' => $shift['nom'],
            'seuil_heures_anormal' => 0,
            'est_shift' => true,
            'date_calcul' => $date,
            'heure_debut' => $shift['heure_debut'],
            'heure_fin' => $shift['heure_fin'],
            'plages_horaires' => [
                ['type' => 'CHECK_IN', 'debut' => $heureDebut, 'jour_offset' => 0],
                ['type' => 'CHECK_OUT', 'debut' => $heureFin, 'jour_offset' => $traverseMinuit ? 1 : 0],
            ],
        ];
    }

    protected function extraireHeure(string $datePointage): string
    {
        return (new DateTimeImmutable($datePointage))->format('H:i');
    }

    protected function convertirHeureEnMinutes(string $heure): int
    {
        $date = DateTimeImmutable::createFromFormat('!H:i', $heure);
        if ($date === false || $date->format('H:i') !== $heure) {
            throw new \InvalidArgumentException('Heure de pointage invalide.');
        }

        return ((int) $date->format('H') * 60) + (int) $date->format('i');
    }

    protected function normaliserDate(string $date): void
    {
        $dateNormalisee = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dateNormalisee === false || $dateNormalisee->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('La date doit respecter le format Y-m-d.');
        }
    }
}
