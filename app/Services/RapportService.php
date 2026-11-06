<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Génère les rapports de présence selon les droits hiérarchiques.
 */
class RapportService
{
    protected BaseConnection $db;
    protected UniteOrganisationnelleService $uniteService;
    protected CalculPresenceService $calculPresenceService;

    public function __construct(?UniteOrganisationnelleService $uniteService = null, ?CalculPresenceService $calculPresenceService = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->uniteService = $uniteService ?? new UniteOrganisationnelleService($this->db);
        $this->calculPresenceService = $calculPresenceService ?? new CalculPresenceService(null, $this->db);
    }

    /** @return array<int, array<string, mixed>> */
    public function getEmployesVisibles(int $userId, ?string $date = null, ?int $uniteScopeId = null): array
    {
        $contexte = $this->getContexteUtilisateur($userId);
        if ($contexte === null || ($contexte['role'] === 'lecteur' && $uniteScopeId === null)) { return []; }

        $requete = $this->db->table('employes e')->select('e.id, e.matricule, e.nom, e.prenom, e.unite_id, u.nom AS unite_nom')->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id', 'left')->where('e.tenant_id', $contexte['tenant_id'])->where('e.actif', 1)->where('e.deleted_at', null);
        if ($uniteScopeId !== null) {
            $unite = $this->db->table('unites_organisationnelles')
                ->select('id')
                ->where('id', $uniteScopeId)
                ->where('tenant_id', (int) $contexte['tenant_id'])
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($unite === null) {
                throw new \InvalidArgumentException('Unité de périmètre invalide pour ce tenant.');
            }
            $uniteIds = [$uniteScopeId];
            foreach ($this->uniteService->getUnitesDescendantes($uniteScopeId) as $descendante) {
                $uniteIds[] = (int) $descendante['id'];
            }
            $requete->whereIn('e.unite_id', array_values(array_unique($uniteIds)));
        } elseif ((int) $contexte['is_super_admin'] !== 1) {
            $dateMandat = $date ?? date('Y-m-d');
            $mandats = $this->db->table('unit_chefs m')
                ->select('m.unite_id')
                ->join(
                    'unites_organisationnelles u',
                    'u.id = m.unite_id AND u.tenant_id = m.tenant_id AND u.actif = 1 AND u.deleted_at IS NULL',
                    'inner'
                )
                ->where('m.tenant_id', (int) $contexte['tenant_id'])
                ->where('m.user_id', $userId)
                ->where('m.est_actif', 1)
                ->where('m.deleted_at', null)
                ->where('m.date_debut <=', $dateMandat)
                ->groupStart()
                    ->where('m.date_fin', null)
                    ->orWhere('m.date_fin >=', $dateMandat)
                ->groupEnd()
                ->get()
                ->getResultArray();
            $uniteIds = array_values(array_unique(array_map(
                static fn (array $mandat): int => (int) $mandat['unite_id'],
                $mandats
            )));
            if ($uniteIds === []) {
                if (empty($contexte['unite_id'])) { return []; }
                $uniteIds[] = (int) $contexte['unite_id'];
            }
            if (in_array($contexte['role'], ['chef_service', 'directeur', 'commissaire'], true) || $mandats !== []) {
                foreach (array_values($uniteIds) as $uniteId) {
                    foreach ($this->uniteService->getUnitesDescendantes($uniteId) as $unite) {
                        $uniteIds[] = (int) $unite['id'];
                    }
                }
            }
            $requete->whereIn('e.unite_id', array_unique($uniteIds));
        }
        return $requete->orderBy('e.nom')->orderBy('e.prenom')->get()->getResultArray();
    }

    /**
     * Liste les destinataires automatiques du rapport journalier pour un tenant.
     *
     * @return array<int, array{user_id:int,unite_id:?int,unite_nom:?string,nom_complet:string,email:?string,role:string}>
     */
    public function listerDestinatairesRapportJournalier(int $tenantId, string $date, ?int $userId = null): array
    {
        $rolesResponsables = ['superviseur', 'chef_service', 'directeur', 'commissaire'];
        $mandats = $this->db->table('unit_chefs m')
            ->select('m.user_id, m.unite_id, o.nom AS unite_nom, u.nom_complet, u.email, u.role')
            ->join(
                'users u',
                'u.id = m.user_id AND u.tenant_id = m.tenant_id',
                'inner'
            )
            ->join(
                'unites_organisationnelles o',
                'o.id = m.unite_id AND o.tenant_id = m.tenant_id AND o.actif = 1 AND o.deleted_at IS NULL',
                'inner'
            )
            ->where('m.tenant_id', $tenantId)
            ->where('m.est_actif', 1)
            ->where('m.deleted_at', null)
            ->where('m.date_debut <=', $date)
            ->where('u.is_active', 1)
            ->where('u.deleted_at', null)
            ->whereIn('u.role', $rolesResponsables)
            ->groupStart()
                ->where('m.date_fin', null)
                ->orWhere('m.date_fin >=', $date)
            ->groupEnd()
            ->groupBy(['m.user_id', 'm.unite_id', 'o.nom', 'u.nom_complet', 'u.email', 'u.role'])
            ->get()
            ->getResultArray();

        $supplementaires = $this->db->table('rapport_journalier_destinataires_unites d')
            ->select('d.user_id, d.unite_id, o.nom AS unite_nom, u.nom_complet, u.email, u.role')
            ->join('users u', 'u.id = d.user_id AND u.tenant_id = d.tenant_id', 'inner')
            ->join('unites_organisationnelles o', 'o.id = d.unite_id AND o.tenant_id = d.tenant_id', 'left')
            ->where('d.tenant_id', $tenantId)
            ->where('u.is_active', 1)
            ->where('u.deleted_at', null)
            ->get()
            ->getResultArray();

        $destinatairesUniques = [];
        foreach (array_merge($mandats, $supplementaires) as $destinataire) {
            $destinataireUserId = (int) $destinataire['user_id'];
            if ($userId !== null && $destinataireUserId !== $userId) {
                continue;
            }

            $uniteId = $destinataire['unite_id'] === null ? null : (int) $destinataire['unite_id'];
            $cle = $destinataireUserId . ':' . ($uniteId ?? 'global');
            $destinatairesUniques[$cle] = [
                'user_id' => $destinataireUserId,
                'unite_id' => $uniteId,
                'unite_nom' => $destinataire['unite_nom'] === null ? null : (string) $destinataire['unite_nom'],
                'nom_complet' => (string) $destinataire['nom_complet'],
                'email' => $destinataire['email'] === null ? null : (string) $destinataire['email'],
                'role' => (string) $destinataire['role'],
            ];
        }

        $destinataires = array_values($destinatairesUniques);
        usort($destinataires, static function (array $gauche, array $droite): int {
            return strnatcasecmp((string) ($gauche['unite_nom'] ?? ''), (string) ($droite['unite_nom'] ?? ''))
                ?: strnatcasecmp($gauche['nom_complet'], $droite['nom_complet'])
                ?: ($gauche['user_id'] <=> $droite['user_id'])
                ?: (($gauche['unite_id'] ?? 0) <=> ($droite['unite_id'] ?? 0));
        });

        return $destinataires;
    }

    public function rapportJournalierDejaArchive(
        int $tenantId,
        int $configurationId,
        int $userId,
        ?int $uniteId,
        string $date
    ): bool {
        $existant = $this->db->table('rapports_envoyes')
            ->where('tenant_id', $tenantId)
            ->where('rapport_journalier_config_id', $configurationId)
            ->where('user_id', $userId)
            ->where('rapport_unite_id', $uniteId)
            ->where('date_rapport', $date)
            ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE'])
            ->countAllResults() > 0;

        if (! $existant && $uniteId === null) {
            $existant = $this->db->table('rapports_envoyes')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('date_rapport', $date)
                ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE'])
                ->where('rapport_planifie_id IS NOT NULL', null, false)
                ->countAllResults() > 0;
        }

        return $existant;
    }

    /**
     * @param array{user_id:int,unite_id:?int} $destinataire
     * @return array<string, mixed>
     */
    public function preparerRapportPourDestinataire(
        array $destinataire,
        string $dateRapport,
        string $dateExecution
    ): array {
        return $this->genererRapportQuotidien(
            $destinataire['user_id'],
            $dateRapport,
            $dateExecution,
            $destinataire['unite_id']
        );
    }

    /** @return array<string, true> */
    public function listerClesRapportsJournaliersArchives(int $tenantId, int $configurationId, string $date): array
    {
        $archives = $this->db->table('rapports_envoyes')
            ->select('user_id, rapport_unite_id, rapport_journalier_config_id, rapport_planifie_id')
            ->where('tenant_id', $tenantId)
            ->where('date_rapport', $date)
            ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE'])
            ->groupStart()
                ->where('rapport_journalier_config_id', $configurationId)
                ->orWhere('rapport_planifie_id IS NOT NULL', null, false)
            ->groupEnd()
            ->get()
            ->getResultArray();

        $cles = [];
        foreach ($archives as $archive) {
            if ((int) ($archive['rapport_journalier_config_id'] ?? 0) === $configurationId) {
                $uniteId = $archive['rapport_unite_id'] === null ? null : (int) $archive['rapport_unite_id'];
                $cles[(int) $archive['user_id'] . ':' . ($uniteId ?? 'global')] = true;
            } elseif ($archive['rapport_planifie_id'] !== null) {
                $cles[(int) $archive['user_id'] . ':global'] = true;
            }
        }

        return $cles;
    }

    /** @return array<string, mixed> */
    public function genererRapportQuotidien(
        int $userId,
        string $date,
        ?string $dateMandat = null,
        ?int $uniteScopeId = null,
        bool $inclureStatsInternes = false
    ): array
    {
        $contexte = $this->getContexteUtilisateur($userId);
        if ($contexte === null) { throw new \RuntimeException('Utilisateur introuvable.'); }
        $details = [];
        $alertes = [];
        $employesPlanifies = 0;
        $stats = [
            'total_employes' => 0,
            'presents' => 0,
            'absents' => 0,
            'normaux' => 0,
            'retards' => 0,
            'anormaux' => 0,
            'feries' => 0,
            'conges' => 0,
            'empechements' => 0,
            'absences_autorisees' => 0,
            'absences_partielles' => 0,
            'conflits_absence_pointage' => 0,
            'repos' => 0,
            'sans_jugement' => 0,
            'heures_totales' => 0.0,
        ];
        $jourTenant = (new JourTravailleService())->verifierJour((int) $contexte['tenant_id'], $date);
        foreach ($this->getEmployesVisibles($userId, $dateMandat ?? $date, $uniteScopeId) as $employe) {
            $calcul = $this->calculPresenceService->interpreterJournee((int) $employe['id'], $date);
            $analyse = $calcul['analyse_plages'];
            $sansJugement = in_array(
                $calcul['details']['evaluation'] ?? null,
                ['AUCUN_JUGEMENT', 'SANS_JUGEMENT', 'ABSENCE_AUTORISEE'],
                true
            );
            if (! $sansJugement && (empty($calcul['jour_ferie']) || ! empty($calcul['ferie_travail']))) {
                $employesPlanifies++;
            }
            $details[] = [
                'matricule' => $employe['matricule'],
                'nom' => $employe['nom'],
                'prenom' => $employe['prenom'],
                'unite' => $employe['unite_nom'],
                'heure_arrivee' => $this->heureReelle($analyse, 'CHECK_IN'),
                'heure_depart' => $this->heureReelle($analyse, 'CHECK_OUT'),
                'pointages' => array_values(array_filter(array_map(
                    static fn (array $plage): ?array => $plage['reel'] === null
                        ? null
                        : ['type' => $plage['type'], 'heure' => $plage['reel']],
                    $analyse
                ))),
                'heures_travaillees' => $calcul['heures_travaillees'],
                'arrivee_tardive' => $this->heureArriveeTardive($analyse),
                'depart_anticipe' => $this->heureDepartAnticipe($analyse),
                'retard_minutes' => $calcul['retard_total_minutes'],
                'depart_anticipe_minutes' => array_sum(array_map(
                    static fn (array $plage): int => $plage['type'] === 'CHECK_OUT'
                        ? max(0, -(int) ($plage['ecart_min'] ?? 0))
                        : 0,
                    $analyse
                )),
                'statut' => $calcul['statut'],
                'jour_ferie' => (bool) ($calcul['jour_ferie'] ?? false),
                'jour_ferie_nom' => $calcul['jour_ferie_nom'] ?? null,
                'absences_autorisees' => $calcul['details']['absences_autorisees'] ?? [],
                'conflit_absence_pointage' => (bool) ($calcul['details']['conflit_absence_pointage'] ?? false),
                'prime_potentielle' => (bool) ($calcul['prime_potentielle'] ?? false),
                'nb_pointages' => count(array_filter($analyse, static fn (array $plage): bool => $plage['reel'] !== null)),
                'horaire_nom' => $calcul['horaire_nom'] ?? $calcul['details']['horaire_nom'] ?? null,
                'source_calcul' => $calcul['source_calcul'] ?? $calcul['details']['source'] ?? null,
                'creneaux' => $calcul['details']['creneaux'] ?? array_map(
                    static fn (array $plage): array => [
                        'creneau' => match ($plage['type'] ?? '') {
                            'CHECK_IN' => 'ARRIVEE',
                            'BREAK_OUT' => 'DEBUT_PAUSE',
                            'BREAK_IN' => 'FIN_PAUSE',
                            'CHECK_OUT' => 'DEPART',
                            default => (string) ($plage['type'] ?? 'INCONNU'),
                        },
                        'attendu' => $plage['attendu'] ?? null,
                        'reel' => $plage['reel'] ?? null,
                        'statut' => $plage['statut'] ?? 'INCONNU',
                    ],
                    $analyse
                ),
            ];
            $stats['total_employes']++;
            if (! $sansJugement) {
                $stats['heures_totales'] += $calcul['heures_travaillees'];
            }
            if (! empty($calcul['jour_ferie'])) {
                $stats['feries']++;
            }
            $absencesAutorisees = $calcul['details']['absences_autorisees'] ?? [];
            if (! empty($calcul['absence_autorisee'])) {
                $absencesAutorisees[] = [
                    'source' => $calcul['details']['absence_source'] ?? null,
                    'journee_complete' => true,
                ];
            }
            if ($absencesAutorisees !== []) {
                $stats['absences_autorisees']++;
                if (array_filter(
                    $absencesAutorisees,
                    static fn (array $absence): bool => ($absence['source'] ?? null) === 'OBR'
                ) !== []) {
                    $stats['conges']++;
                } else {
                    $stats['empechements']++;
                }
                if (array_filter(
                    $absencesAutorisees,
                    static fn (array $absence): bool => empty($absence['journee_complete'])
                ) !== []) {
                    $stats['absences_partielles']++;
                }
            }
            if (! empty($calcul['details']['conflit_absence_pointage'])) {
                $stats['conflits_absence_pointage']++;
            }
            if ($sansJugement) {
                $stats['sans_jugement']++;
            } else {
                match ($calcul['statut']) {
                    'ABSENT' => $stats['absents']++,
                    'NORMAL' => [$stats['presents']++, $stats['normaux']++],
                    'RETARD' => [$stats['presents']++, $stats['retards']++],
                    'EN_COURS' => $stats['presents']++,
                    'FERIE' => null,
                    'REPOS' => $stats['repos']++,
                    default => [$stats['presents']++, $stats['anormaux']++],
                };
            }
            foreach ($calcul['pointages_manquants'] as $manquant) {
                $alertes[] = $employe['nom'] . ' ' . $employe['prenom'] . ' : pointage ' . $manquant . ' manquant';
            }
        }
        $stats['heures_totales'] = round($stats['heures_totales'], 2);
        if ($inclureStatsInternes) {
            $stats['employes_planifies'] = $employesPlanifies;
        }
        $stats['moyenne_heures'] = $employesPlanifies <= 0
            ? 0.0
            : round($stats['heures_totales'] / $employesPlanifies, 2);
        $employesComptabilises = $stats['presents'] + $stats['absents'];
        $stats['taux_presence'] = $employesComptabilises === 0
            ? 0.0
            : round($stats['presents'] * 100 / $employesComptabilises, 2);
        return [
            'titre' => 'Rapport de présence du ' . date('d/m/Y', strtotime($date)),
            'destinataire' => ['user_id' => $userId, 'tenant_id' => (int) $contexte['tenant_id'], 'nom' => $contexte['nom_complet'], 'role' => $contexte['role'], 'unite' => $contexte['unite_nom']],
            'date' => $date,
            'horaire' => 'Horaire déterminé par les affectations de chaque employé',
            'est_jour_travaille' => $jourTenant['travaille'],
            'raison_non_travaille' => $jourTenant['travaille'] ? null : $jourTenant['raison'],
            'stats' => $stats,
            'details' => $details,
            'alertes' => $alertes,
        ];
    }

    /**
     * Builds a period report by aggregating the daily attendance interpretation.
     *
     * @return array<string, mixed>
     */
    public function genererRapportPeriode(
        int $userId,
        string $dateDebut,
        string $dateFin,
        ?int $uniteScopeId = null,
        string $type = 'PERIODE'
    ): array {
        if (! $this->dateRapportValide($dateDebut)
            || ! $this->dateRapportValide($dateFin)
            || $dateDebut > $dateFin
        ) {
            throw new \InvalidArgumentException('Période de rapport invalide.');
        }

        $date = new \DateTimeImmutable($dateDebut);
        $fin = new \DateTimeImmutable($dateFin);
        $details = [];
        $alertes = [];
        $matricules = [];
        $stats = [
            'total_employes' => 0,
            'jours_employes' => 0,
            'employes_planifies' => 0,
            'presents' => 0,
            'absents' => 0,
            'normaux' => 0,
            'retards' => 0,
            'anormaux' => 0,
            'feries' => 0,
            'conges' => 0,
            'empechements' => 0,
            'absences_autorisees' => 0,
            'absences_partielles' => 0,
            'conflits_absence_pointage' => 0,
            'repos' => 0,
            'sans_jugement' => 0,
            'heures_totales' => 0.0,
        ];
        $sommeStats = [
            'employes_planifies',
            'presents',
            'absents',
            'normaux',
            'retards',
            'anormaux',
            'feries',
            'conges',
            'empechements',
            'absences_autorisees',
            'absences_partielles',
            'conflits_absence_pointage',
            'repos',
            'sans_jugement',
        ];

        while ($date <= $fin) {
            $dateJour = $date->format('Y-m-d');
            $rapportJour = $this->genererRapportQuotidien(
                $userId,
                $dateJour,
                $dateJour,
                $uniteScopeId,
                true
            );
            foreach ($sommeStats as $cle) {
                $stats[$cle] += (int) ($rapportJour['stats'][$cle] ?? 0);
            }
            $stats['jours_employes'] += (int) ($rapportJour['stats']['total_employes'] ?? 0);
            $stats['heures_totales'] += (float) ($rapportJour['stats']['heures_totales'] ?? 0);

            foreach ($rapportJour['details'] as $detail) {
                $details[] = [...$detail, 'date' => $dateJour];
                $identite = trim((string) ($detail['matricule'] ?? ''));
                if ($identite === '') {
                    $identite = trim((string) ($detail['nom'] ?? '') . ' ' . (string) ($detail['prenom'] ?? ''));
                }
                if ($identite !== '') {
                    $matricules[$identite] = true;
                }
            }
            foreach ($rapportJour['alertes'] as $alerte) {
                $alertes[] = $dateJour . ' : ' . $alerte;
            }
            $date = $date->modify('+1 day');
        }

        $dernierRapport = $rapportJour;
        $stats['total_employes'] = count($matricules);
        $stats['heures_totales'] = round($stats['heures_totales'], 2);
        $stats['moyenne_heures'] = $stats['employes_planifies'] === 0
            ? 0.0
            : round($stats['heures_totales'] / $stats['employes_planifies'], 2);
        $employesComptabilises = $stats['presents'] + $stats['absents'];
        $stats['taux_presence'] = $employesComptabilises === 0
            ? 0.0
            : round($stats['presents'] * 100 / $employesComptabilises, 2);

        return [
            ...$dernierRapport,
            'titre' => 'Rapport de présence du ' . date('d/m/Y', strtotime($dateDebut))
                . ' au ' . date('d/m/Y', strtotime($dateFin)),
            'date' => $dateFin,
            'periode' => ['type' => $type, 'debut' => $dateDebut, 'fin' => $dateFin],
            'stats' => $stats,
            'details' => $details,
            'alertes' => $alertes,
        ];
    }

    /** @return array{debut:string,fin:string} */
    public static function bornesPeriodePlanifiee(string $type, \DateTimeImmutable $reference): array
    {
        if ($type === 'HEBDOMADAIRE') {
            $debut = $reference->modify('monday this week')->modify('-7 days');
            $fin = $debut->modify('+6 days');
        } elseif ($type === 'MENSUEL') {
            $debut = $reference->modify('first day of this month')->modify('-1 month');
            $fin = $reference->modify('first day of this month')->modify('-1 day');
        } else {
            throw new \InvalidArgumentException('Type de période de rapport invalide.');
        }

        return ['debut' => $debut->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')];
    }

    /** Génère un PDF paysage et retourne son chemin temporaire. */
    public function genererPDF(array $rapport): string
    {
        $repertoire = $this->repertoireRapports();
        $chemin = $repertoire . DIRECTORY_SEPARATOR . $this->nomFichier($rapport, 'pdf');
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
            'tempDir' => $repertoire,
        ]);
        $mpdf->WriteHTML(view('rapports/quotidien_pdf', ['rapport' => $rapport]));
        $mpdf->Output($chemin, \Mpdf\Output\Destination::FILE);

        return $chemin;
    }

    /** Génère un classeur Excel de synthèse et de détails. */
    public function genererExcel(array $rapport): string
    {
        $repertoire = $this->repertoireRapports();
        $chemin = $repertoire . DIRECTORY_SEPARATOR . $this->nomFichier($rapport, 'xlsx');
        $classeur = new Spreadsheet();
        $synthese = $classeur->getActiveSheet();
        $synthese->setTitle('Synthèse');
        $this->setTexte($synthese, 'A1', (string) ($rapport['titre'] ?? 'Rapport de présence'));
        $this->setTexte($synthese, 'A2', isset($rapport['periode']) ? 'Période' : 'Date');
        $this->setTexte(
            $synthese,
            'B2',
            isset($rapport['periode'])
                ? (string) $rapport['periode']['debut'] . ' – ' . (string) $rapport['periode']['fin']
                : (string) ($rapport['date'] ?? '')
        );
        $this->setTexte($synthese, 'A4', 'Indicateur');
        $this->setTexte($synthese, 'B4', 'Valeur');

        $statistiques = $rapport['stats'] ?? [];
        $libelles = [
            'total_employes' => 'Total employés',
            'presents' => 'Présents',
            'absents' => 'Absents',
            'feries' => 'Jours fériés',
            'repos' => 'Jours de repos',
            'sans_jugement' => 'Sans jugement',
            'normaux' => 'Normaux',
            'retards' => 'Retards',
            'anormaux' => 'Anormaux',
            'heures_totales' => 'Heures totales',
            'moyenne_heures' => 'Moyenne heures/jour',
            'taux_presence' => 'Taux de présence',
            'conges' => 'Congés confirmés',
            'empechements' => 'Empêchements validés',
            'absences_autorisees' => 'Absences autorisées',
            'absences_partielles' => 'Absences partielles',
            'conflits_absence_pointage' => 'Pointages pendant une absence',
        ];
        if (isset($rapport['periode'])) {
            $libelles['moyenne_heures'] = 'Moyenne heures/journée-employé';
        }
        $ligne = 5;
        foreach ($libelles as $cle => $libelle) {
            $this->setTexte($synthese, 'A' . $ligne, $libelle);
            $this->setNombre($synthese, 'B' . $ligne, (float) ($statistiques[$cle] ?? 0));
            $ligne++;
        }
        if (isset($rapport['periode'])) {
            $this->setTexte($synthese, 'A' . $ligne, 'Journées-employés');
            $this->setNombre($synthese, 'B' . $ligne, (int) ($statistiques['jours_employes'] ?? 0));
            $ligne++;
        }
        $this->styliserEntete($synthese, 'A4:B4');
        $synthese->getColumnDimension('A')->setAutoSize(true);
        $synthese->getColumnDimension('B')->setAutoSize(true);

        $details = $classeur->createSheet();
        $details->setTitle('Détails');
        $entetes = [
            'Date',
            'Matricule',
            'Nom',
            'Prénom',
            'Unité',
            'Horaire appliqué',
            'Détails pointages',
            'Heure arrivée',
            'Heure départ',
            'Heures travaillées',
            'Arrivée tardive',
            'Départ anticipé',
            'Retard (min)',
            'Départ anticipé (min)',
            'Statut',
            'Nombre de pointages',
            'Source du calcul',
            'Détail des créneaux',
        ];
        foreach ($entetes as $index => $entete) {
            $this->setTexte($details, Coordinate::stringFromColumnIndex($index + 1) . '1', $entete);
        }
        $this->styliserEntete($details, 'A1:R1');

        foreach (($rapport['details'] ?? []) as $index => $detail) {
            $ligne = $index + 2;
            $this->setTexte($details, 'A' . $ligne, (string) ($detail['date'] ?? $rapport['date'] ?? ''));
            foreach (['matricule', 'nom', 'prenom', 'unite'] as $colonne => $cle) {
                $this->setTexte($details, Coordinate::stringFromColumnIndex($colonne + 2) . $ligne, (string) ($detail[$cle] ?? ''));
            }
            $pointages = array_map(
                static fn (array $pointage): string => (string) ($pointage['type'] ?? '')
                    . ' ' . (string) ($pointage['heure'] ?? ''),
                $detail['pointages'] ?? []
            );
            $this->setTexte($details, 'F' . $ligne, (string) ($detail['horaire_nom'] ?? ''));
            $this->setTexte($details, 'G' . $ligne, implode(', ', $pointages));
            $this->setTexte($details, 'H' . $ligne, (string) ($detail['heure_arrivee'] ?? ''));
            $this->setTexte($details, 'I' . $ligne, (string) ($detail['heure_depart'] ?? ''));
            $this->setNombre($details, 'J' . $ligne, (float) ($detail['heures_travaillees'] ?? 0));
            $this->setTexte($details, 'K' . $ligne, (string) ($detail['arrivee_tardive'] ?? ''));
            $this->setTexte($details, 'L' . $ligne, (string) ($detail['depart_anticipe'] ?? ''));
            $this->setNombre($details, 'M' . $ligne, (int) ($detail['retard_minutes'] ?? 0));
            $this->setNombre($details, 'N' . $ligne, (int) ($detail['depart_anticipe_minutes'] ?? 0));
            $this->setTexte($details, 'O' . $ligne, (string) ($detail['statut'] ?? ''));
            $this->setNombre($details, 'P' . $ligne, (int) ($detail['nb_pointages'] ?? 0));
            $this->setTexte($details, 'Q' . $ligne, (string) ($detail['source_calcul'] ?? ''));
            $this->setTexte($details, 'R' . $ligne, json_encode($detail['creneaux'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $details->freezePane('A2');
        $details->setAutoFilter('A1:R' . max(1, count($rapport['details'] ?? []) + 1));
        foreach (range('A', 'R') as $colonne) {
            $details->getColumnDimension($colonne)->setAutoSize(true);
        }

        (new Xlsx($classeur))->save($chemin);
        $classeur->disconnectWorksheets();

        return $chemin;
    }

    private function repertoireRapports(): string
    {
        $repertoire = WRITEPATH . 'rapports';
        if (! is_dir($repertoire) && ! mkdir($repertoire, 0750, true) && ! is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de créer le répertoire des rapports.');
        }

        return $repertoire;
    }

    private function nomFichier(array $rapport, string $extension): string
    {
        $date = preg_replace('/[^0-9-]/', '', (string) ($rapport['date'] ?? date('Y-m-d')));

        return sprintf('rapport_%s_%s.%s', $date, bin2hex(random_bytes(6)), $extension);
    }

    private function setTexte(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, string $valeur): void
    {
        $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING);
    }

    private function setNombre(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, float|int $valeur): void
    {
        $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_NUMERIC);
    }

    private function styliserEntete(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $plage): void
    {
        $feuille->getStyle($plage)->getFont()->setBold(true);
        $feuille->getStyle($plage)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBE8');
    }

    /** Envoie un rapport au compte actif du tenant et journalise le résultat. */
    public function envoyerEmail(
        int $userId,
        array $rapport,
        string $format = 'PDF',
        string $langue = 'fr',
        ?int $rapportPlanifieId = null,
        ?int $rapportJournalierConfigId = null,
        ?int $rapportUniteId = null
    ): bool
    {
        $format = strtoupper($format);
        $langue = in_array($langue, ['fr', 'en', 'rn'], true) ? $langue : 'fr';
        $tenantId = (int) ($rapport['destinataire']['tenant_id'] ?? 0);
        if (! $this->configurationRapportJournalierActive($tenantId, $rapportJournalierConfigId)) {
            throw new \RuntimeException('Les envois de rapports sont désactivés pour ce tenant.');
        }
        $user = $this->db->table('users')
            ->select('id, tenant_id, email, nom_complet')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($tenantId < 1 || $user === null || ! filter_var($user['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Destinataire introuvable ou adresse e-mail invalide pour ce tenant.');
        }
        if (! in_array($format, ['PDF', 'EXCEL'], true)) {
            throw new \InvalidArgumentException('Format invalide (PDF ou EXCEL).');
        }

        $maintenant = date('Y-m-d H:i:s');
        $this->db->table('rapports_envoyes')->insert([
            'tenant_id' => $tenantId,
            'rapport_planifie_id' => $rapportPlanifieId,
            'rapport_journalier_config_id' => $rapportJournalierConfigId,
            'rapport_unite_id' => $rapportUniteId,
            'user_id' => $userId,
            'date_rapport' => (string) $rapport['date'],
            'heure_envoi' => $maintenant,
            'format' => $format,
            'fichier_path' => null,
            'contenu_json' => json_encode($rapport, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'destinataire_email' => (string) $user['email'],
            'destinataire_nom' => (string) $user['nom_complet'],
            'statut' => 'EN_ATTENTE',
            'message_erreur' => null,
            'created_at' => $maintenant,
        ]);
        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Impossible de créer l’archive du rapport avant son envoi.');
        }
        $historiqueId = (int) $this->db->insertID();
        if ($historiqueId < 1) {
            throw new \RuntimeException('Identifiant d’archive du rapport invalide.');
        }

        $fichier = null;
        try {
            $fichier = $format === 'PDF' ? $this->genererPDF($rapport) : $this->genererExcel($rapport);
            $smtp = (new ApplicationIntegrationSettings())->smtpConfiguration();
            if (! $smtp['enabled'] || trim((string) $smtp['host']) === '') {
                throw new \RuntimeException('Le serveur SMTP doit être configuré et activé dans Paramètres > Intégrations.');
            }
            if (! filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('L’adresse d’expédition SMTP est invalide; configurez-la dans Paramètres > Intégrations.');
            }
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) $smtp['host'];
            $mail->Port = (int) $smtp['port'];
            $mail->SMTPAuth = (string) $smtp['username'] !== '';
            $mail->Username = (string) $smtp['username'];
            $mail->Password = (string) $smtp['password'];
            $mail->Timeout = 10;
            $mail->SMTPSecure = $smtp['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : ($smtp['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) $smtp['from_email'], (string) $smtp['from_name']);
            $mail->addAddress((string) $user['email'], (string) $user['nom_complet']);
            $mail->isHTML(true);
            $mail->Subject = $this->sujetRapport($rapport, $langue);
            $mail->Body = view('rapports/email', ['rapport' => $rapport, 'langue' => $langue]);
            $mail->AltBody = (string) ($rapport['titre'] ?? 'Rapport de présence');
            $mail->addAttachment($fichier, basename($fichier));
            $mail->send();

        } catch (\Throwable $e) {
            log_message('error', 'Envoi du rapport par e-mail impossible : {error}', ['error' => $e->getMessage()]);
            $misAJour = $this->db->table('rapports_envoyes')
                ->where('id', $historiqueId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'statut' => 'ECHEC',
                    'message_erreur' => mb_substr($e->getMessage(), 0, 2000),
                    'heure_envoi' => date('Y-m-d H:i:s'),
                ]);
            if (! $misAJour || $this->db->affectedRows() !== 1) {
                throw new \RuntimeException('L’envoi a échoué et son statut n’a pas pu être archivé.', 0, $e);
            }
            return false;
        } finally {
            if ($fichier !== null && is_file($fichier)) {
                unlink($fichier);
            }
        }

        $this->db->table('rapports_envoyes')
            ->where('id', $historiqueId)
            ->where('tenant_id', $tenantId)
            ->update([
                'statut' => 'ENVOYE',
                'heure_envoi' => date('Y-m-d H:i:s'),
            ]);
        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Le courriel a été envoyé, mais l’archive n’a pas pu être mise à jour.');
        }

        return true;
    }

    private function sujetRapport(array $rapport, string $langue): string
    {
        $date = isset($rapport['periode'])
            ? (string) $rapport['periode']['debut'] . ' – ' . (string) $rapport['periode']['fin']
            : (string) ($rapport['date'] ?? '');

        return match ($langue) {
            'en' => 'Attendance report for ' . $date,
            'rn' => 'Raporo y’ivyashikiye ku ' . $date,
            default => 'Rapport de présence du ' . $date,
        };
    }

    /**
     * Envoie les rapports quotidiens planifiés pour la date J-1.
     *
     * @return array<string, int>
     */
    public function contexteRapportsQuotidiens(): array
    {
        $configs = $this->db->table('rapport_journalier_configs c')
            ->select('c.*')
            ->join('tenants t', 't.id = c.tenant_id', 'inner')
            ->where('c.actif', 1)
            ->where('t.is_active', 1)
            ->where('t.deleted_at', null)
            ->get()
            ->getResultArray();
        $context = [];
        $joursTravailles = new JourTravailleService();
        $joursFeries = new JourFerieService();
        foreach ($configs as $config) {
            $tenantId = (int) $config['tenant_id'];
            $now = (new TenantTimezoneService($this->db))->now($tenantId);
            if ((string) $config['heure_envoi'] > $now->format('H:i:s')) {
                continue;
            }
            $executionDate = $now->format('Y-m-d');
            $reportDate = $now->modify('-1 day')->format('Y-m-d');
            $context[] = [
                'tenant_id' => $tenantId,
                'configuration_id' => (int) $config['id'],
                'date_execution' => $executionDate,
                'date_rapport' => $reportDate,
                'eligible' => $joursTravailles->estTravaille($tenantId, $executionDate)
                    && $joursFeries->getJourFerie($tenantId, $reportDate) === null,
                'format' => (string) $config['format'],
                'langue' => (string) $config['langue'],
                'recipients' => array_map(
                    static fn (array $recipient): array => [
                        'user_id' => $recipient['user_id'],
                        'unite_id' => $recipient['unite_id'],
                    ],
                    $this->listerDestinatairesRapportJournalier($tenantId, $executionDate)
                ),
            ];
        }

        return $context;
    }

    /**
     * @param ?array<int, array<string,mixed>> $retryContext Immutable tenant/date/recipient parameters from the source run.
     * @return array<string, int>
     */
    public function envoyerRapportsQuotidiens(?array $retryContext = null): array
    {
        $context = $retryContext ?? $this->contexteRapportsQuotidiens();
        $result = ['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0];

        foreach ($context as $entry) {
            $tenantId = (int) ($entry['tenant_id'] ?? 0);
            $configId = (int) ($entry['configuration_id'] ?? 0);
            $executionDate = (string) ($entry['date_execution'] ?? '');
            $reportDate = (string) ($entry['date_rapport'] ?? '');
            if ($tenantId < 1 || $configId < 1
                || ! $this->dateRapportValide($executionDate)
                || ! $this->dateRapportValide($reportDate)
                || ! is_bool($entry['eligible'] ?? null)
                || ! in_array($entry['format'] ?? null, ['PDF', 'EXCEL'], true)
                || ! in_array($entry['langue'] ?? null, ['fr', 'en', 'rn'], true)
                || ! is_array($entry['recipients'] ?? null)
            ) {
                throw new \InvalidArgumentException('Le contexte de relance des rapports est invalide.');
            }

            $config = $this->db->table('rapport_journalier_configs c')
                ->select('c.*')
                ->join('tenants t', 't.id = c.tenant_id')
                ->where('c.id', $configId)
                ->where('c.tenant_id', $tenantId)
                ->where('c.actif', 1)
                ->where('t.is_active', 1)
                ->where('t.deleted_at', null)
                ->get()
                ->getRowArray();
            if ($config === null) {
                $result['ignores']++;
                continue;
            }
            $result['traite']++;
            if (! $entry['eligible']) {
                $result['ignores']++;
                continue;
            }
            if ($entry['recipients'] === []) {
                log_message('error', 'Aucun destinataire enregistré pour le rapport journalier du tenant {tenant}.', [
                    'tenant' => $tenantId,
                ]);
                $result['erreurs']++;
                continue;
            }

            foreach ($entry['recipients'] as $recipient) {
                $userId = (int) ($recipient['user_id'] ?? 0);
                $unitId = isset($recipient['unite_id']) ? (int) $recipient['unite_id'] : null;
                if ($userId < 1 || (isset($recipient['unite_id']) && $unitId < 1)) {
                    throw new \InvalidArgumentException('Un destinataire du contexte de relance est invalide.');
                }
                if ($this->rapportJournalierDejaArchive($tenantId, $configId, $userId, $unitId, $reportDate)) {
                    $result['ignores']++;
                    continue;
                }

                try {
                    $report = $this->preparerRapportPourDestinataire(
                        ['user_id' => $userId, 'unite_id' => $unitId],
                        $reportDate,
                        $executionDate
                    );
                    if (! $this->envoyerEmail(
                        $userId,
                        $report,
                        (string) $entry['format'],
                        (string) $entry['langue'],
                        null,
                        $configId,
                        $unitId
                    )) {
                        throw new \RuntimeException('L’envoi du rapport journalier a échoué.');
                    }
                    $result['envoyes']++;
                } catch (\Throwable $exception) {
                    log_message('error', 'Échec du rapport journalier du tenant {tenant}, destinataire {user} : {error}', [
                        'tenant' => $tenantId,
                        'user' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                    $result['erreurs']++;
                }
            }

            $this->db->table('rapport_journalier_configs')
                ->where('id', $configId)
                ->update(['updated_at' => date('Y-m-d H:i:s')]);
        }

        return $result;
    }

    private function dateRapportValide(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Envoie les rapports planifiés selon le type demandé.
     *
     * @param string $type Type de rapport : QUOTIDIEN, HEBDOMADAIRE, MENSUEL.
     * @return array<string, int>
     */
    public function envoyerRapportsParType(string $type): array
    {
        if ($type === 'QUOTIDIEN') {
            return $this->envoyerRapportsQuotidiens();
        }
        if (! in_array($type, ['HEBDOMADAIRE', 'MENSUEL'], true)) {
            throw new \InvalidArgumentException('Type de rapport planifié invalide.');
        }

        $planifications = $this->db->table('rapports_planifies')
            ->where('actif', 1)
            ->where('type', $type)
            ->get()
            ->getResultArray();

        $result = ['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0];

        foreach ($planifications as $planification) {
            $tenantId = (int) $planification['tenant_id'];
            $maintenant = (new TenantTimezoneService($this->db))->now($tenantId);
            if ((string) $planification['heure_envoi'] > $maintenant->format('H:i:s')) {
                continue;
            }

            if ($type === 'HEBDOMADAIRE') {
                $joursSemaine = trim((string) ($planification['jours_semaine'] ?? ''));
                $joursSemaineParts = $joursSemaine === ''
                    ? []
                    : (preg_split('/\s*,\s*/', $joursSemaine) ?: []);
                $joursInvalides = array_filter(
                    $joursSemaineParts,
                    static fn (string $jour): bool => preg_match('/^[1-7]$/', $jour) !== 1
                );
                if ($joursInvalides !== []) {
                    log_message('error', 'Jours de semaine invalides pour le rapport planifié {planification}.', [
                        'planification' => (int) $planification['id'],
                    ]);
                    $result['erreurs']++;
                    continue;
                }
                $joursAutorises = $joursSemaine === ''
                    ? [1, 2, 3, 4, 5]
                    : array_map('intval', $joursSemaineParts);
                if (! in_array((int) $maintenant->format('N'), $joursAutorises, true)) {
                    continue;
                }
            } elseif ($maintenant->format('j') !== '1') {
                continue;
            }

            $bornes = self::bornesPeriodePlanifiee($type, $maintenant);
            $dateRapport = $bornes['fin'];
            $result['traite']++;

            $dejaEnvoyeQuery = $this->db->table('rapports_envoyes')
                ->where('tenant_id', (int) $planification['tenant_id'])
                ->where('user_id', (int) $planification['user_id'])
                ->where('date_rapport', $dateRapport)
                ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE']);
            if ($type === 'QUOTIDIEN') {
                $dejaEnvoyeQuery->groupStart()
                    ->where('rapport_planifie_id', (int) $planification['id'])
                    ->orWhere('rapport_journalier_config_id IS NOT NULL', null, false)
                    ->groupEnd();
            } else {
                $dejaEnvoyeQuery->where('rapport_planifie_id', (int) $planification['id']);
            }
            $dejaEnvoye = $dejaEnvoyeQuery->countAllResults() > 0;

            if ($dejaEnvoye) {
                $result['ignores']++;
                continue;
            }

            try {
                $this->envoyerRapportPlanifie($planification, $dateRapport, $bornes['debut']);
                $result['envoyes']++;
            } catch (\Throwable $e) {
                log_message('error', 'Échec du rapport planifié {planification} : {error}', [
                    'planification' => (int) $planification['id'],
                    'error' => $e->getMessage(),
                ]);
                $result['erreurs']++;
            }
        }

        return $result;
    }

    /**
     * Envoie un rapport planifié donné.
     *
     * @param array<string, mixed> $planification
     * @return array<string, mixed>
     */
    protected function envoyerRapportPlanifie(
        array $planification,
        string $dateRapport,
        ?string $dateDebut = null
    ): array
    {
        if (in_array($planification['type'] ?? null, ['HEBDOMADAIRE', 'MENSUEL'], true)) {
            if ($dateDebut === null || ! $this->dateRapportValide($dateDebut)) {
                throw new \InvalidArgumentException('Date de début du rapport périodique invalide.');
            }
            $rapport = $this->genererRapportPeriode(
                (int) $planification['user_id'],
                $dateDebut,
                $dateRapport,
                isset($planification['perimetre_unite_id']) ? (int) $planification['perimetre_unite_id'] : null,
                (string) $planification['type']
            );
        } else {
            $rapport = $this->preparerRapportPourDestinataire(
                ['user_id' => (int) $planification['user_id'], 'unite_id' => null],
                $dateRapport,
                $dateRapport
            );
        }
        if (! $this->envoyerEmail(
            (int) $planification['user_id'],
            $rapport,
            (string) ($planification['format'] ?? 'PDF'),
            (string) ($planification['langue'] ?? 'fr'),
            (int) $planification['id']
        )) {
            throw new \RuntimeException('L’envoi du rapport planifié a échoué.');
        }

        $this->db->table('rapports_planifies')
            ->where('id', (int) $planification['id'])
            ->update([
                'dernier_envoi' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $rapport;
    }

    private function configurationRapportJournalierActive(int $tenantId, ?int $configurationId = null): bool
    {
        if ($tenantId < 1) {
            return false;
        }

        $query = $this->db->table('rapport_journalier_configs')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1);
        if ($configurationId !== null) {
            $query->where('id', $configurationId);
        }

        return $query->countAllResults() > 0;
    }

    public function envoiRapportActifPourTenant(int $tenantId): bool
    {
        return $this->configurationRapportJournalierActive($tenantId);
    }

    /** @return ?array<string, mixed> */
    protected function getContexteUtilisateur(int $userId): ?array
    {
        $resultat = $this->db->table('users us')->select('us.id, us.tenant_id, us.role, us.is_super_admin, us.nom_complet, e.unite_id, u.nom AS unite_nom')->join('employes e', 'e.id = us.employe_id AND e.tenant_id = us.tenant_id', 'left')->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = us.tenant_id', 'left')->where('us.id', $userId)->where('us.deleted_at', null)->get()->getRowArray();
        return $resultat ?: null;
    }
    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureReelle(array $analyse, string $type): ?string { foreach ($analyse as $plage) { if ($plage['type'] === $type) { return $plage['reel']; } } return null; }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureSelonAnalyse(array $analyse, string $type, callable $condition): ?string
    {
        foreach ($analyse as $plage) {
            if ($plage['type'] === $type && $plage['reel'] !== null && $condition($plage)) {
                return (string) $plage['reel'];
            }
        }

        return null;
    }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureArriveeTardive(array $analyse): ?string
    {
        return $this->heureSelonAnalyse(
            $analyse,
            'CHECK_IN',
            static fn (array $plage): bool => $plage['statut'] === 'RETARD'
        );
    }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureDepartAnticipe(array $analyse): ?string
    {
        return $this->heureSelonAnalyse(
            $analyse,
            'CHECK_OUT',
            static fn (array $plage): bool => (int) ($plage['ecart_min'] ?? 0) < 0
        );
    }
}
