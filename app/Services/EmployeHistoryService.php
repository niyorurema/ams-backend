<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Expose l'historique personnel de pointages d'un employé lié à un utilisateur.
 */
class EmployeHistoryService
{
    protected BaseConnection $db;
    protected CalculPresenceService $calculPresenceService;

    public function __construct(?CalculPresenceService $calculPresenceService = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->calculPresenceService = $calculPresenceService ?? new CalculPresenceService(null, $this->db);
    }

    /**
     * Retourne les pointages personnels enrichis et les statuts quotidiens.
     *
     * @param array{date_debut?:string,date_fin?:string,date_jour?:string,periode_debut?:string,periode_fin?:string,matricule?:string,limit?:int,offset?:int,rejets_offset?:int} $filtres
     * @return array<string, mixed>
     */
    public function getMonHistorique(int $userId, array $filtres = []): array
    {
        $utilisateur = $this->db->table('users')
            ->select('users.id, users.tenant_id, users.employe_id, users.role, users.is_super_admin, users.webauthn_enrollment_approved, e.unite_id')
            ->join('employes e', 'e.id = users.employe_id', 'left')
            ->where('users.deleted_at', null)
            ->where('users.id', $userId)
            ->get()
            ->getRowArray();
        if ($utilisateur === null) {
            throw new EmployeHistoryAccessException('Compte utilisateur introuvable.', 404);
        }

        $matriculeRecherche = trim((string) ($filtres['matricule'] ?? ''));
        $role = (string) $utilisateur['role'];
        $administrateur = (int) $utilisateur['is_super_admin'] === 1 || $role === 'admin';
        $requeteEmploye = $this->db->table('employes e')
            ->select('e.id, e.tenant_id, e.unite_id, e.matricule, e.nom, e.prenom, e.poste, u.nom AS unite')
            ->join('unites_organisationnelles u', 'u.id = e.unite_id', 'left')
            ->where('e.tenant_id', (int) $utilisateur['tenant_id'])
            ->where('e.actif', 1)
            ->where('e.deleted_at', null);
        if ($matriculeRecherche !== '') {
            if (! $administrateur && ! in_array($role, ['chef_service', 'directeur', 'commissaire', 'superviseur'], true)) {
                throw new EmployeHistoryAccessException('La recherche par matricule n’est pas autorisée.', 403);
            }
            $requeteEmploye->where('e.matricule', $matriculeRecherche);
            if (! $administrateur) {
                $visibles = (new RapportService(null, null, $this->db))->getEmployesVisibles($userId);
                $visiblesIds = array_map('intval', array_column($visibles, 'id'));
                if ($visiblesIds === []) {
                    throw new EmployeHistoryAccessException('Aucun employé n’est autorisé dans ce périmètre.', 403);
                }
                if ($role === 'chef_service') {
                    if (empty($utilisateur['unite_id'])) {
                        throw new EmployeHistoryAccessException('Aucune unité n’est associée à votre compte.', 403);
                    }
                    $requeteEmploye->where('e.unite_id', (int) $utilisateur['unite_id']);
                } else {
                    $requeteEmploye->whereIn('e.id', $visiblesIds);
                }
            }
        } elseif (! empty($utilisateur['employe_id'])) {
            $requeteEmploye->where('e.id', (int) $utilisateur['employe_id']);
        } else {
            throw new EmployeHistoryAccessException('Aucun employé n’est associé à ce compte.', 404);
        }
        $employe = $requeteEmploye->get()->getRowArray();
        if ($employe === null) {
            throw new EmployeHistoryAccessException('Employé introuvable ou hors périmètre.', 404);
        }

        $timezone = (new TenantTimezoneService($this->db))->forTenant((int) $utilisateur['tenant_id']);
        $maintenant = new \DateTimeImmutable('now', $timezone);
        $dateDuJour = (string) ($filtres['date_jour'] ?? $maintenant->format('Y-m-d'));
        $presenceDuJour = $this->calculPresenceService->interpreterJournee((int) $employe['id'], $dateDuJour);
        $etapesDuJour = $presenceDuJour['analyse_plages'] ?? [];
        $nombreEtapes = count($etapesDuJour);
        $etapesEffectuees = count(array_filter(
            $etapesDuJour,
            static fn (array $etape): bool => in_array($etape['statut'] ?? null, ['OK', 'RETARD'], true)
        ));
        $presenceDuJour['progression_pourcentage'] = $nombreEtapes > 0
            ? (int) round($etapesEffectuees / $nombreEtapes * 100)
            : null;
        $presenceDuJour['etapes_effectuees'] = $etapesEffectuees;
        $presenceDuJour['etapes_attendues'] = $nombreEtapes;

        $limit = min(500, max(1, (int) ($filtres['limit'] ?? 50)));
        $offset = max(0, (int) ($filtres['offset'] ?? 0));
        $rejetsLimit = 20;
        $rejetsOffset = max(0, (int) ($filtres['rejets_offset'] ?? 0));
        $requete = $this->db->table('pointages p')
            ->select("p.id, p.date_pointage, p.type_evenement, COALESCE(p.statut_calcul, 'UNKNOWN') AS statut_calcul, COALESCE(p.source, 'UNKNOWN') AS source, c.nom AS capteur")
            ->join('capteurs c', 'c.id = p.capteur_id', 'left')
            ->where('p.employe_id', (int) $employe['id'])
            ->where('p.statut', 'VALIDE')
            ->where('p.deleted_at', null);
        if (! empty($filtres['date_debut'])) { $requete->where('p.date_pointage >=', $filtres['date_debut'] . ' 00:00:00'); }
        if (! empty($filtres['date_fin'])) { $requete->where('p.date_pointage <=', $filtres['date_fin'] . ' 23:59:59'); }

        $total = (clone $requete)->countAllResults();
        $pointages = $requete->orderBy('p.date_pointage', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $requeteRejets = $this->db->table('pointages_rejetes p')
            ->select('p.id, p.date_capteur, p.recu_le, p.motif, p.source, p.type_evenement, c.nom AS capteur')
            ->join('capteurs c', 'c.id = p.capteur_id', 'left')
            ->where('p.tenant_id', (int) $employe['tenant_id'])
            ->where('p.employe_id', (int) $employe['id']);
        $totalRejets = (clone $requeteRejets)->countAllResults();
        $pointagesRejetes = $requeteRejets
            ->orderBy('p.recu_le', 'DESC')
            ->limit($rejetsLimit, $rejetsOffset)
            ->get()
            ->getResultArray();
        $pointagesDuJour = $this->db->table('pointages p')
            ->select("p.id, p.date_pointage, p.type_evenement, COALESCE(p.statut_calcul, 'UNKNOWN') AS statut_calcul, COALESCE(p.source, 'UNKNOWN') AS source, c.nom AS capteur")
            ->join('capteurs c', 'c.id = p.capteur_id', 'left')
            ->where('p.employe_id', (int) $employe['id'])
            ->where('p.date_pointage >=', $dateDuJour . ' 00:00:00')
            ->where('p.date_pointage <=', $dateDuJour . ' 23:59:59')
            ->where('p.statut', 'VALIDE')
            ->where('p.deleted_at', null)
            ->orderBy('p.date_pointage', 'ASC')
            ->get()
            ->getResultArray();
        $statuts = [];
        foreach ($pointages as &$pointage) {
            $pointage['type_libelle'] = $this->libelleType((string) $pointage['type_evenement']);
            $pointage['capteur'] ??= null;
            $pointage['unite'] = $employe['unite'];
            $jour = substr((string) $pointage['date_pointage'], 0, 10);
            if (! isset($statuts[$jour])) {
                $calcul = $this->calculPresenceService->interpreterJournee((int) $employe['id'], $jour);
                $statuts[$jour] = ['statut' => $calcul['statut'], 'heures_travaillees' => $calcul['heures_travaillees'], 'retard_minutes' => $calcul['retard_total_minutes']];
            }
        }
        unset($pointage);

        $resultat = [
            'employe' => [
                'id' => (int) $employe['id'],
                'matricule' => $employe['matricule'],
                'nom' => $employe['nom'],
                'prenom' => $employe['prenom'],
                'unite' => $employe['unite'],
                'poste' => $employe['poste'],
            ],
            'date_du_jour' => $dateDuJour,
            'date_courante' => $maintenant->format('Y-m-d'),
            'server_now' => $maintenant->format(\DateTimeInterface::ATOM),
            'timezone' => $timezone->getName(),
            'passkey_registered' => $this->db->table('webauthn_credentials')
                ->where('tenant_id', (int) $utilisateur['tenant_id'])
                ->where('user_id', $userId)
                ->countAllResults() > 0,
            'passkey_enrollment_approved' => (bool) ($utilisateur['webauthn_enrollment_approved'] ?? false),
            'presence_du_jour' => $presenceDuJour,
            'pointages_du_jour' => $pointagesDuJour,
            'pointages' => $pointages,
            'pointages_rejetes' => $pointagesRejetes,
            'pointages_rejetes_pagination' => [
                'limit' => $rejetsLimit,
                'offset' => $rejetsOffset,
                'total' => $totalRejets,
            ],
            'statuts_journaliers' => $statuts,
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ];

        if (! empty($filtres['periode_debut']) && ! empty($filtres['periode_fin'])) {
            $resultat['evaluation'] = $this->construireEvaluation(
                (int) $employe['id'],
                (string) $filtres['periode_debut'],
                (string) $filtres['periode_fin']
            );
        }

        return $resultat;
    }

    /** @return array<string,mixed> */
    private function construireEvaluation(int $employeId, string $debut, string $fin): array
    {
        $totaux = [
            'retard_total_minutes' => 0,
            'jours_absence' => 0,
            'jours_presence' => 0,
            'heures_travaillees_minutes' => 0,
            'jours_anormaux' => 0,
            'pointages_anormaux' => 0,
        ];
        $jours = [];
        $anomalies = [];
        $date = new \DateTimeImmutable($debut);
        $dateFin = new \DateTimeImmutable($fin);
        for (; $date <= $dateFin; $date = $date->modify('+1 day')) {
            $dateTexte = $date->format('Y-m-d');
            $calcul = $this->calculPresenceService->interpreterJournee($employeId, $dateTexte);
            $totaux['retard_total_minutes'] += (int) $calcul['retard_total_minutes'];
            $totaux['heures_travaillees_minutes'] += (int) round((float) $calcul['heures_travaillees'] * 60);
            if ($calcul['statut'] === 'ABSENT') {
                $totaux['jours_absence']++;
            }
            if ($calcul['statut'] === 'PRESENT' || (int) round((float) $calcul['heures_travaillees'] * 60) > 0) {
                $totaux['jours_presence']++;
            }
            if ($calcul['statut'] === 'ANORMAL') {
                $totaux['jours_anormaux']++;
            }
            $retardJour = (int) $calcul['retard_total_minutes'];
            $jours[] = [
                'date' => $dateTexte,
                'retard_minutes' => $retardJour,
                'statut' => $calcul['statut'],
                'heures_travaillees_minutes' => (int) round((float) $calcul['heures_travaillees'] * 60),
            ];
            foreach ($calcul['analyse_plages'] as $plage) {
                if (! in_array($plage['statut'], ['ANORMAL', 'RETARD', 'MANQUANT'], true)) {
                    continue;
                }
                $anomalies[] = [
                    'date' => $dateTexte,
                    'type' => $plage['type'],
                    'statut' => $plage['statut'],
                    'attendu' => $plage['attendu'],
                    'reel' => $plage['reel'],
                    'ecart_min' => $plage['ecart_min'],
                ];
                $totaux['pointages_anormaux']++;
            }
        }

        return [
            'periode' => ['debut' => $debut, 'fin' => $fin],
            'totaux' => $totaux,
            'jours' => $jours,
            'anomalies' => $anomalies,
        ];
    }

    /** @return array<string, mixed> */
    public function getStatutJour(int $employeId, string $date): array
    {
        return $this->calculPresenceService->interpreterJournee($employeId, $date);
    }

    protected function libelleType(string $type): string
    {
        return match ($type) {
            'CHECK_IN' => 'Arrivée',
            'CHECK_OUT' => 'Départ',
            'BREAK_OUT', 'BREAK_START' => 'Début de pause',
            'BREAK_IN', 'BREAK_END' => 'Fin de pause',
            default => 'Inconnu',
        };
    }
}
