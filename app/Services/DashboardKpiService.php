<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/** Agrège les indicateurs quotidiens du dashboard pour un seul tenant. */
final class DashboardKpiService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function getPourTenant(int $tenantId): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }

        $maintenant = (new TenantTimezoneService($this->db))->now($tenantId);
        $date = $maintenant->format('Y-m-d');
        $debutHistorique = $maintenant->modify('-89 days')->format('Y-m-d');
        $debutMois = $maintenant->modify('first day of this month')->format('Y-m-d');
        $debutMoisPrecedent = $maintenant->modify('first day of last month')->format('Y-m-d');
        $finMoisPrecedent = $maintenant->modify('last day of last month')->format('Y-m-d');

        $cacheKey = 'dashboard_kpis_' . $tenantId . '_' . $date;
        $cached = service('cache')->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
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

        $joursTenant = JourTravailleService::normaliserJours($tenant['jours_travailles'] ?? null);
        $employesActifs = $this->db->table('employes')
            ->select('id, unite_id')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $employeIds = array_map(static fn (array $row): int => (int) $row['id'], $employesActifs);
        $uniteParEmploye = [];
        foreach ($employesActifs as $employe) {
            $uniteParEmploye[(int) $employe['id']] = isset($employe['unite_id'])
                ? (int) $employe['unite_id']
                : null;
        }

        $contrats = $this->chargerContrats($tenantId, $employeIds, $debutHistorique, $date);
        $joursFeries = $this->chargerJoursFeries($tenantId, $debutHistorique, $date);
        $projections = $this->chargerProjections($tenantId, $debutHistorique, $date);
        $pointagesParJour = $this->chargerPointagesParJour($tenantId, $debutHistorique, $date);
        // The dashboard uses cached projections; live per-employee interpretation belongs to explicit daily views.
        $interpretateur = null;

        $employesAttendusParJour = [];
        $presenceInterpreteeParJour = [];
        $unitesAttendues = [];
        $dateCourante = new DateTimeImmutable($debutHistorique);
        $dateFin = new DateTimeImmutable($date);
        while ($dateCourante <= $dateFin) {
            $jour = $dateCourante->format('Y-m-d');
            $jourSemaine = (int) $dateCourante->format('N');
            if ($interpretateur !== null) {
                $attendus = [];
                $journees = [];
                foreach ($employeIds as $employeId) {
                    $resultat = $interpretateur->interpreterJournee($employeId, $jour);
                    $statut = (string) ($resultat['statut_global'] ?? $resultat['statut'] ?? 'ANORMAL');
                    if ($statut === 'FERIE'
                        || $statut === 'REPOS'
                        || ($resultat['details']['evaluation'] ?? null) === 'AUCUN_JUGEMENT'
                    ) {
                        continue;
                    }
                    $attendus[] = $employeId;
                    $journees[$employeId] = $resultat;
                    if ($jour === $date && $uniteParEmploye[$employeId] !== null) {
                        $unitesAttendues[$uniteParEmploye[$employeId]][$employeId] = true;
                    }
                }
                $employesAttendusParJour[$jour] = array_fill_keys($attendus, true);
                $presenceInterpreteeParJour[$jour] = [
                    'attendus' => $attendus,
                    'journees' => $journees,
                    'stats' => self::calculerPresenceJour($attendus, $journees),
                ];
            } else {
                $ferie = $this->jourFeriePourDate($joursFeries, $jour);
                $jourOuvre = $ferie === null
                    ? in_array($jourSemaine, $joursTenant, true)
                    : (int) $ferie['travaille'] === 1;
                if ($jourOuvre) {
                    foreach ($contrats as $employeId => $contratsEmploye) {
                        foreach ($contratsEmploye as $contrat) {
                            if ($contrat['date_debut'] > $jour
                                || ($contrat['date_fin'] !== null && $contrat['date_fin'] < $jour)
                                || ! in_array($jourSemaine, $contrat['jours_travail'], true)
                            ) {
                                continue;
                            }
                            $employesAttendusParJour[$jour][$employeId] = true;
                            if ($jour === $date && $uniteParEmploye[$employeId] !== null) {
                                $unitesAttendues[$uniteParEmploye[$employeId]][$employeId] = true;
                            }
                            break;
                        }
                    }
                }
            }
            $dateCourante = $dateCourante->modify('+1 day');
        }

        $attendusAujourdhui = array_keys($employesAttendusParJour[$date] ?? []);
        $presenceJour = $presenceInterpreteeParJour[$date]['stats']
            ?? self::calculerPresenceJour($attendusAujourdhui, $projections[$date] ?? []);
        $totalAttendus = count($attendusAujourdhui);
        if ($interpretateur === null && isset($pointagesParJour[$date])) {
            $observesAujourdhui = $pointagesParJour[$date];
            $employesObserves = array_keys($observesAujourdhui);
            if ($attendusAujourdhui === []) {
                $employesPresents = $employesObserves;
            } else {
                $employesPresents = array_intersect($attendusAujourdhui, $employesObserves);
            }
            $retardsObserves = count(array_filter(
                array_intersect_key(
                    $observesAujourdhui,
                    array_fill_keys($employesPresents, true)
                ),
                static fn (array $pointage): bool => $pointage['retard']
            ));
            $presenceJour['presents'] = max($presenceJour['presents'], count($employesPresents));
            $presenceJour['en_retard'] = max($presenceJour['en_retard'], $retardsObserves);
            $presenceJour['absents'] = max(
                0,
                $totalAttendus - $presenceJour['presents'] - $presenceJour['en_cours']
            );
        }
        $totalEmployes = count($employeIds);

        $evolution = [];
        $dateCourante = new DateTimeImmutable($debutHistorique);
        while ($dateCourante <= $dateFin) {
            $jour = $dateCourante->format('Y-m-d');
            if ($interpretateur !== null) {
                $journeeInterpretee = $presenceInterpreteeParJour[$jour];
                $stats = $journeeInterpretee['stats'];
                $evolution[] = [
                    'date' => $jour,
                    'presents' => $stats['presents'],
                    'retards' => $stats['en_retard'],
                    'absents' => $stats['absents'],
                    'en_cours' => $stats['en_cours'],
                    'total' => count($journeeInterpretee['attendus']),
                ];
                $dateCourante = $dateCourante->modify('+1 day');
                continue;
            }

            $jourSemaine = (int) $dateCourante->format('N');
            $ferie = $this->jourFeriePourDate($joursFeries, $jour);
            $jourOuvre = $ferie === null
                ? in_array($jourSemaine, $joursTenant, true)
                : (int) $ferie['travaille'] === 1;
            if (! $jourOuvre) {
                $dateCourante = $dateCourante->modify('+1 day');
                continue;
            }

            $attendus = array_keys($employesAttendusParJour[$jour] ?? []);
            $stats = self::calculerPresenceJour($attendus, $projections[$jour] ?? []);
            $observes = $pointagesParJour[$jour] ?? [];
            $observesIds = array_keys($observes);
            if ($attendus === []) {
                $presents = count($observesIds);
                $retards = count(array_filter(
                    $observes,
                    static fn (array $pointage): bool => $pointage['retard']
                ));
            } else {
                $observesAttendus = array_intersect($attendus, $observesIds);
                $presents = max($stats['presents'], count($observesAttendus));
                $retardsObserves = count(array_filter(
                    array_intersect_key($observes, array_fill_keys($attendus, true)),
                    static fn (array $pointage): bool => $pointage['retard']
                ));
                $retards = max($stats['en_retard'], $retardsObserves);
            }
            $enCours = $stats['en_cours'];
            $total = max(count($attendus), $presents);
            $evolution[] = [
                'date' => $jour,
                'presents' => $presents,
                'retards' => $retards,
                'absents' => max(0, count($attendus) - $presents - $enCours),
                'en_cours' => $enCours,
                'total' => $total,
            ];
            $dateCourante = $dateCourante->modify('+1 day');
        }

        $unitIds = array_keys($unitesAttendues);
        $nomsUnites = $unitIds === []
            ? []
            : $this->db->table('unites_organisationnelles')
                ->select('id, nom')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $unitIds)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
        $nomsParUnite = [];
        foreach ($nomsUnites as $unite) {
            $nomsParUnite[(int) $unite['id']] = (string) $unite['nom'];
        }
        $topUnites = [];
        foreach ($unitesAttendues as $uniteId => $attendus) {
            $stats = self::calculerPresenceJour(
                array_keys($attendus),
                $presenceInterpreteeParJour[$date]['journees'] ?? $projections[$date] ?? []
            );
            $total = count($attendus);
            $topUnites[] = [
                'unite_id' => (int) $uniteId,
                'unite' => $nomsParUnite[(int) $uniteId] ?? '',
                'taux_absence' => $total === 0 ? 0.0 : round($stats['absents'] / $total * 100, 1),
                'absents' => $stats['absents'],
                'total' => $total,
            ];
        }
        usort($topUnites, static fn (array $a, array $b): int =>
            ($b['taux_absence'] <=> $a['taux_absence']) ?: ($b['absents'] <=> $a['absents'])
        );

        $heuresSup = $this->chargerHeuresSupplementaires(
            $tenantId,
            $debutMois,
            $date,
            $debutMoisPrecedent,
            $finMoisPrecedent
        );
        $capteurs = $this->chargerCapteurs($tenantId);
        $shifts = $this->chargerShifts($tenantId, $date);
        $pointagesAujourdhui = (int) $this->db->table('pointages p')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'inner')
            ->where('e.actif', 1)
            ->where('e.deleted_at', null)
            ->where('p.tenant_id', $tenantId)
            ->where('p.date_pointage >=', $date . ' 00:00:00')
            ->where('p.date_pointage <=', $date . ' 23:59:59')
            ->where('p.deleted_at', null)
            ->countAllResults();
        $pointagesRecents = $this->chargerPointagesRecents($tenantId);
        $alertes = $this->construireAlertes(
            $tenantId,
            $date,
            $presenceJour['sans_pointage_depart'],
            $contrats === []
        );

        $hier = $maintenant->modify('-1 day')->format('Y-m-d');
        $comparaisonHier = null;
        foreach ($evolution as $jourEvolution) {
            if ((string) $jourEvolution['date'] === $hier) {
                $comparaisonHier = [
                    'attendus' => $jourEvolution['total'],
                    'presents' => $jourEvolution['presents'],
                    'en_retard' => $jourEvolution['retards'],
                    'absents' => $jourEvolution['absents'],
                    'en_cours' => $jourEvolution['en_cours'],
                ];
                break;
            }
        }

        $pointagesEntrees = (int) $this->db->table('pointages p')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'inner')
            ->where('e.actif', 1)->where('e.deleted_at', null)->where('p.tenant_id', $tenantId)
            ->where('p.date_pointage >=', $date . ' 00:00:00')
            ->where('p.date_pointage <=', $date . ' 23:59:59')
            ->where('p.type_evenement', 'CHECK_IN')->where('p.deleted_at', null)
            ->countAllResults();
        $pointagesSorties = (int) $this->db->table('pointages p')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'inner')
            ->where('e.actif', 1)->where('e.deleted_at', null)->where('p.tenant_id', $tenantId)
            ->where('p.date_pointage >=', $date . ' 00:00:00')
            ->where('p.date_pointage <=', $date . ' 23:59:59')
            ->where('p.type_evenement', 'CHECK_OUT')->where('p.deleted_at', null)
            ->countAllResults();

        $resultat = [
            'date' => $date,
            'updated_at' => $maintenant->format(\DateTimeInterface::ATOM),
            'comparaison_hier' => $comparaisonHier,
            'employes' => [
                'total' => $totalEmployes,
                'attendus_aujourdhui' => $totalAttendus,
                'presents' => $presenceJour['presents'],
                'en_retard' => $presenceJour['en_retard'],
                'absents' => $presenceJour['absents'],
                'en_cours' => $presenceJour['en_cours'],
                'sans_pointage_depart' => $presenceJour['sans_pointage_depart'],
            ],
            'pointages' => [
                'aujourdhui' => $pointagesAujourdhui,
                'entrees' => $pointagesEntrees,
                'sorties' => $pointagesSorties,
            ],
            'pointages_recents' => $pointagesRecents,
            'capteurs' => [
                'actifs' => count(array_filter($capteurs, static fn (array $item): bool => $item['statut'] === 'ACTIF')),
                'indisponibles' => array_values(array_filter($capteurs, static fn (array $item): bool => $item['statut'] !== 'ACTIF')),
            ],
            'heures_sup' => $heuresSup,
            'alertes' => $alertes,
            'top_unites_absentes' => array_slice($topUnites, 0, 3),
            'evolution_presence' => $evolution,
            'shifts' => $shifts,
        ];
        service('cache')->save($cacheKey, $resultat, 30);
        return $resultat;
    }

    /**
     * L'absence est calculée exclusivement sur les employés attendus, ce qui
     * empêche les absences de dépasser l'effectif planifié.
     *
     * @param list<int|string> $employesAttendus
     * @param array<int|string, array<string, mixed>> $projectionsParEmploye
    * @return array{presents:int,en_retard:int,absents:int,en_cours:int,sans_pointage_depart:int}
     */
    public static function calculerPresenceJour(array $employesAttendus, array $projectionsParEmploye): array
    {
        $presents = 0;
        $retards = 0;
        $sansDepart = 0;
        $enCours = 0;
        foreach ($employesAttendus as $employeId) {
            $projection = $projectionsParEmploye[$employeId] ?? $projectionsParEmploye[(int) $employeId] ?? null;
            if ($projection === null) {
                continue;
            }
            if (($projection['statut_global'] ?? null) === 'EN_COURS') {
                $enCours++;
                continue;
            }
            if (($projection['heure_arrivee'] ?? null) === null) {
                continue;
            }
            $presents++;
            if (($projection['statut_arrivee'] ?? null) === 'RETARD') {
                $retards++;
            }
            if (($projection['heure_depart'] ?? null) === null) {
                $sansDepart++;
            }
        }

        $attendus = count(array_unique(array_map('intval', $employesAttendus)));

        return [
            'presents' => $presents,
            'en_retard' => $retards,
            'absents' => max(0, $attendus - $presents - $enCours),
            'en_cours' => $enCours,
            'sans_pointage_depart' => $sansDepart,
        ];
    }

    /**
     * @param list<int> $employeIds
     * @return array<int, list<array{date_debut:string,date_fin:?string,jours_travail:list<int>}>>
     */
    private function chargerContrats(int $tenantId, array $employeIds, string $debut, string $fin): array
    {
        if ($employeIds === []) {
            return [];
        }
        $rows = $this->db->table('employe_contrats_horaire')
            ->select('employe_id, date_debut, date_fin, jours_travail')
            ->where('tenant_id', $tenantId)
            ->whereIn('employe_id', $employeIds)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->where('date_debut <=', $fin)
            ->groupStart()
                ->where('date_fin', null)
                ->orWhere('date_fin >=', $debut)
            ->groupEnd()
            ->orderBy('date_debut', 'DESC')
            ->get()
            ->getResultArray();
        $contrats = [];
        foreach ($rows as $row) {
            $jours = json_decode((string) $row['jours_travail'], true);
            if (! is_array($jours)) {
                throw new \UnexpectedValueException('Calendrier de contrat invalide.');
            }
            $contrats[(int) $row['employe_id']][] = [
                'date_debut' => (string) $row['date_debut'],
                'date_fin' => $row['date_fin'] === null ? null : (string) $row['date_fin'],
                'jours_travail' => array_values(array_unique(array_map('intval', $jours))),
            ];
        }

        return $contrats;
    }

    /** @return list<array<string, mixed>> */
    private function chargerJoursFeries(int $tenantId, string $debut, string $fin): array
    {
        return $this->db->table('jours_feries')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->groupStart()
                ->groupStart()->where('date >=', $debut)->where('date <=', $fin)->groupEnd()
                ->orWhere('recurrent', 1)
            ->groupEnd()
            ->get()
            ->getResultArray();
    }

    /** @param list<array<string, mixed>> $joursFeries */
    private function jourFeriePourDate(array $joursFeries, string $date): ?array
    {
        $moisJour = substr($date, 5);
        $recurrent = null;
        foreach ($joursFeries as $ferie) {
            if ((string) $ferie['date'] === $date) {
                return $ferie;
            }
            if ((int) $ferie['recurrent'] === 1 && substr((string) $ferie['date'], 5) === $moisJour) {
                $recurrent = $ferie;
            }
        }

        return $recurrent;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function chargerProjections(int $tenantId, string $debut, string $fin): array
    {
        $rows = $this->db->table('pointages_journee')
            ->select('employe_id, date, heure_arrivee, statut_arrivee, heure_depart, statut_global')
            ->where('tenant_id', $tenantId)
            ->where('date >=', $debut)
            ->where('date <=', $fin)
            ->get()
            ->getResultArray();
        $projections = [];
        foreach ($rows as $row) {
            $projections[(string) $row['date']][(int) $row['employe_id']] = $row;
        }

        return $projections;
    }

    /**
     * @return array<string, array<int, array{retard:bool}>>
     */
    private function chargerPointagesParJour(int $tenantId, string $debut, string $fin): array
    {
        $rows = $this->db->table('pointages p')
            ->select('DATE(p.date_pointage) AS jour, p.employe_id, p.statut_calcul', false)
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id', 'inner')
            ->where('e.actif', 1)
            ->where('e.deleted_at', null)
            ->where('p.tenant_id', $tenantId)
            ->where('p.date_pointage >=', $debut . ' 00:00:00')
            ->where('p.date_pointage <=', $fin . ' 23:59:59')
            ->where('p.date_pointage >=', '2001-01-01 00:00:00')
            ->where('p.deleted_at', null)
            ->where('p.employe_id IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $pointages = [];
        foreach ($rows as $row) {
            $jour = (string) $row['jour'];
            $employeId = (int) $row['employe_id'];
            $pointages[$jour][$employeId] ??= ['retard' => false];
            if ((string) ($row['statut_calcul'] ?? '') === 'RETARD') {
                $pointages[$jour][$employeId]['retard'] = true;
            }
        }

        return $pointages;
    }

    /** @return list<array<string, mixed>> */
    private function chargerPointagesRecents(int $tenantId): array
    {
        $rows = $this->db->table('pointages p')
            ->select(
                'p.id, p.employe_id, e.nom AS employe_nom, e.prenom AS employe_prenom, ' .
                'e.matricule AS employe_matricule, p.badge_id, p.date_pointage, ' .
                'p.type_evenement, p.statut_calcul, p.capteur_id'
            )
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL AND e.actif = 1', 'inner')
            ->where('p.tenant_id', $tenantId)
            ->where('p.date_pointage >=', '2001-01-01 00:00:00')
            ->where('p.deleted_at', null)
            ->orderBy('p.date_pointage', 'DESC')
            ->orderBy('p.id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        return array_map(static function (array $pointage): array {
            $nom = trim((string) ($pointage['employe_nom'] ?? '') . ' ' . (string) ($pointage['employe_prenom'] ?? ''));

            return [
                'id' => (int) $pointage['id'],
                'employe_id' => isset($pointage['employe_id']) ? (int) $pointage['employe_id'] : null,
                'employe_nom' => $nom !== '' ? $nom : null,
                'employe_matricule' => $pointage['employe_matricule'] ?? null,
                'badge_id' => (int) $pointage['badge_id'],
                'date_pointage' => (string) $pointage['date_pointage'],
                'type_evenement' => (string) $pointage['type_evenement'],
                'statut_calcul' => (string) ($pointage['statut_calcul'] ?? 'UNKNOWN'),
                'capteur_id' => isset($pointage['capteur_id']) ? (int) $pointage['capteur_id'] : null,
            ];
        }, $rows);
    }

    /** @return array{mois_en_cours:float,mois_precedent:float} */
    private function chargerHeuresSupplementaires(
        int $tenantId,
        string $debutMois,
        string $date,
        string $debutMoisPrecedent,
        string $finMoisPrecedent
    ): array {
        $somme = static function (BaseConnection $db, int $tenantId, string $debut, string $fin): float {
            $row = $db->table('pointages_journee')
                ->select('COALESCE(SUM(heures_supplementaires), 0) AS total', false)
                ->where('tenant_id', $tenantId)
                ->where('date >=', $debut)
                ->where('date <=', $fin)
                ->get()
                ->getRowArray();

            return round((float) ($row['total'] ?? 0), 2);
        };

        return [
            'mois_en_cours' => $somme($this->db, $tenantId, $debutMois, $date),
            'mois_precedent' => $somme($this->db, $tenantId, $debutMoisPrecedent, $finMoisPrecedent),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function chargerCapteurs(int $tenantId): array
    {
        return $this->db->table('capteurs')
            ->select('id, nom, adresse_ip, port, statut')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->orderBy('nom', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function chargerShifts(int $tenantId, string $date): array
    {
        return $this->db->table('shifts s')
            ->select(
                's.id, s.nom, s.heure_debut, s.heure_fin, s.couleur, ' .
                'COUNT(DISTINCT CASE WHEN sa.actif = 1 AND sa.deleted_at IS NULL ' .
                'AND sa.date_debut <= ' . $this->db->escape($date) .
                ' AND (sa.date_fin IS NULL OR sa.date_fin >= ' . $this->db->escape($date) .
                ') THEN sa.employe_id END) AS employes_assignes, ' .
                'COUNT(DISTINCT CASE WHEN p.heure_arrivee IS NOT NULL ' .
                'AND sa.actif = 1 AND sa.deleted_at IS NULL ' .
                'AND sa.date_debut <= ' . $this->db->escape($date) .
                ' AND (sa.date_fin IS NULL OR sa.date_fin >= ' . $this->db->escape($date) .
                ') THEN sa.employe_id END) AS employes_presents',
                false
            )
            ->join('shift_assignments sa', 'sa.shift_id = s.id AND sa.tenant_id = s.tenant_id', 'left')
            ->join('pointages_journee p', 'p.employe_id = sa.employe_id AND p.tenant_id = sa.tenant_id AND p.date = ' . $this->db->escape($date), 'left')
            ->where('s.tenant_id', $tenantId)
            ->where('s.actif', 1)
            ->where('s.deleted_at', null)
            ->groupBy('s.id')
            ->orderBy('s.ordre_affichage', 'ASC')
            ->orderBy('s.nom', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function construireAlertes(int $tenantId, string $date, int $sansDepart, bool $contratsAbsents): array
    {
        $alertes = [];
        if ($contratsAbsents) {
            $alertes[] = [
                'type' => 'CONTRATS_HORAIRES_ABSENTS',
                'count' => 0,
                'severite' => 'warning',
            ];
        }
        if ($sansDepart > 0) {
            $alertes[] = [
                'type' => 'SANS_DEPART',
                'count' => $sansDepart,
                'severite' => 'warning',
            ];
        }

        $orphans = $this->db->table('pointages p')
            ->select('c.nom AS capteur, COUNT(*) AS count')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
            ->join('employes eb', 'eb.tenant_id = p.tenant_id AND eb.badge_id = p.badge_id AND eb.deleted_at IS NULL', 'left')
            ->join('capteurs c', 'c.id = p.capteur_id AND c.tenant_id = p.tenant_id', 'left')
            ->where('p.tenant_id', $tenantId)
            ->where('e.id', null)
            ->where('eb.id', null)
            ->where('p.date_pointage >=', $date . ' 00:00:00')
            ->where('p.date_pointage <=', $date . ' 23:59:59')
            ->where('p.deleted_at', null)
            ->groupBy('p.capteur_id')
            ->get()
            ->getResultArray();
        foreach ($orphans as $orphan) {
            $alertes[] = [
                'type' => 'ORPHELINS',
                'count' => (int) $orphan['count'],
                'capteur' => $orphan['capteur'] ?? null,
                'severite' => 'danger',
            ];
        }

        return $alertes;
    }
}
