<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Lit et gère les paramètres métier de pointage d'un tenant.
 */
class ParametragePointageService
{
    private const DEFAUTS_SYSTEME = [
        'ecart_max_association_minutes' => 360,
        'marge_pointage_futur_minutes' => 5,
        'seuil_heures_sup_minutes' => 0,
        'mode_calcul_heures_sup' => 'ARRONDI',
        'pas_arrondi_heures_sup_minutes' => 15,
        'duree_pause_minimum_minutes' => 15,
        'duree_pause_maximum_minutes' => 130,
    ];

    /** @var array<int, array<string, array<string, mixed>|null>> */
    private array $cache = [];
    private ?BaseConnection $db = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function get(int $tenantId, string $cle, mixed $defaut = null): mixed
    {
        if ($tenantId < 1 || $cle === '') {
            throw new \InvalidArgumentException('Tenant ou clé de paramètre invalide.');
        }

        $parametre = $this->chargerParametre($tenantId, $cle);
        if ($parametre !== null) {
            return $this->caster((string) $parametre['valeur'], (string) $parametre['type']);
        }
        if ($defaut !== null) {
            return $defaut;
        }

        return self::DEFAUTS_SYSTEME[$cle] ?? null;
    }

    /**
     * Enregistre un paramètre après validation de son type.
     */
    public function set(int $tenantId, string $cle, mixed $valeur, string $type): bool
    {
        if ($tenantId < 1 || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/', $cle)) {
            throw new \InvalidArgumentException('Tenant ou clé de paramètre invalide.');
        }
        $type = strtoupper($type);
        if (! in_array($type, ['INT', 'BOOL', 'STRING', 'TIME', 'DECIMAL'], true)) {
            throw new \InvalidArgumentException('Type de paramètre invalide.');
        }

        $texte = is_bool($valeur) ? ($valeur ? '1' : '0') : (string) $valeur;
        $valeurNormalisee = $this->caster($texte, $type);
        if ($cle === 'pas_arrondi_heures_sup_minutes'
            && ($type !== 'INT' || $valeurNormalisee < 1)
        ) {
            throw new \InvalidArgumentException('Le pas d’arrondi doit être un entier supérieur à zéro.');
        }
        if ($cle === 'mode_calcul_heures_sup'
            && ($type !== 'STRING' || ! in_array($valeurNormalisee, ['ARRONDI', 'EXACT'], true))
        ) {
            throw new \InvalidArgumentException('Le mode d’heures supplémentaires doit être ARRONDI ou EXACT.');
        }
        if (in_array($cle, [
            'ecart_max_association_minutes',
            'marge_pointage_futur_minutes',
            'seuil_heures_sup_minutes',
            'duree_pause_minimum_minutes',
            'duree_pause_maximum_minutes',
        ], true) && ($type !== 'INT' || $valeurNormalisee < 0)) {
            throw new \InvalidArgumentException('Ce paramètre doit être un entier positif ou nul.');
        }
        if ($cle === 'marge_pointage_futur_minutes' && $valeurNormalisee > 60) {
            throw new \InvalidArgumentException('La marge de date future doit être comprise entre 0 et 60 minutes.');
        }
        $existant = $this->chargerParametre($tenantId, $cle);
        $horodatage = date('Y-m-d H:i:s');
        if ($existant === null) {
            $this->db->table('tenant_parametres_pointage')->insert([
                'tenant_id' => $tenantId,
                'cle' => $cle,
                'valeur' => $texte,
                'type' => $type,
                'description' => $this->descriptionDepuisCle($cle),
                'modifiable_par' => 'RH',
                'created_at' => $horodatage,
            ]);
        } else {
            $this->db->table('tenant_parametres_pointage')
                ->where('id', (int) $existant['id'])
                ->where('tenant_id', $tenantId)
                ->update([
                    'valeur' => $texte,
                    'type' => $type,
                    'updated_at' => $horodatage,
                ]);
        }

        if (! $this->transactionReussie()) {
            throw new RuntimeException('Impossible d’enregistrer le paramètre de pointage.');
        }
        $this->invaliderCache($tenantId, $cle);

        return true;
    }

    /** @return array<string, mixed> */
    public function getAll(int $tenantId): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }
        if (! $this->tableDisponible()) {
            return [];
        }

        $rows = $this->db->table('tenant_parametres_pointage')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->orderBy('cle', 'ASC')
            ->get()
            ->getResultArray();
        $resultat = [];
        foreach ($rows as $row) {
            $resultat[(string) $row['cle']] = $this->caster((string) $row['valeur'], (string) $row['type']);
        }

        return $resultat;
    }

    /**
     * Retourne les paramètres configurables avec leur métadonnée et leurs valeurs de repli.
     *
     * @return array<string, array{valeur:mixed,type:string,description:string}>
     */
    public function getConfiguration(int $tenantId): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }

        $configuration = [];
        if ($this->tableDisponible()) {
            $rows = $this->db->table('tenant_parametres_pointage')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->orderBy('cle', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $cle = (string) $row['cle'];
                $configuration[$cle] = [
                    'valeur' => $this->caster((string) $row['valeur'], (string) $row['type']),
                    'type' => (string) $row['type'],
                    'description' => (string) ($row['description'] ?? $this->descriptionDepuisCle($cle)),
                ];
            }
        }

        foreach (self::DEFAUTS_SYSTEME as $cle => $valeur) {
            $configuration[$cle] ??= [
                'valeur' => $valeur,
                'type' => $this->typeDepuisCle($cle),
                'description' => $this->descriptionDepuisCle($cle),
            ];
        }
        ksort($configuration);

        return $configuration;
    }

    /** @return array<int, array<string, mixed>> */
    public function getCreneaux(int $tenantId): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }
        if (! $this->db->tableExists('tenant_creneaux_pointage')) {
            throw new RuntimeException('La table des créneaux de pointage n’est pas disponible.');
        }

        return $this->db->table('tenant_creneaux_pointage')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('ordre_affichage', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Remplace la configuration active des créneaux sans supprimer l'historique.
     *
     * @param array<int, array<string, mixed>> $creneaux
     * @return array<int, array<string, mixed>>
     */
    public function remplacerCreneaux(int $tenantId, array $creneaux): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }
        if (! $this->db->tableExists('tenant_creneaux_pointage')) {
            throw new RuntimeException('La table des créneaux de pointage n’est pas disponible.');
        }

        $normalises = [];
        foreach ($creneaux as $creneau) {
            if (! is_array($creneau)
                || ! isset($creneau['type_creneau'], $creneau['mode'])
                || ! in_array($creneau['type_creneau'], ['ARRIVEE', 'DEBUT_PAUSE', 'FIN_PAUSE', 'DEPART'], true)
                || ! in_array($creneau['mode'], ['LIMITE_TARDIVE', 'LIMITE_PRECOCE', 'PLAGE'], true)
                || isset($normalises[$creneau['type_creneau']])
            ) {
                throw new \InvalidArgumentException('Liste de créneaux invalide ou contenant un type en double.');
            }

            $heureCible = $this->normaliserHeureCreneau($creneau['heure_cible'] ?? null);
            $plageDebut = $this->normaliserHeureCreneau($creneau['plage_debut'] ?? null);
            $plageFin = $this->normaliserHeureCreneau($creneau['plage_fin'] ?? null);
            if (($creneau['mode'] === 'PLAGE' && ($plageDebut === null || $plageFin === null || $plageDebut >= $plageFin))
                || ($creneau['mode'] !== 'PLAGE' && $heureCible === null)
            ) {
                throw new \InvalidArgumentException('Les heures du créneau sont incompatibles avec son mode.');
            }

            $tolerance = $creneau['tolerance_minutes'] ?? null;
            if ($tolerance !== null && (filter_var($tolerance, FILTER_VALIDATE_INT) === false || (int) $tolerance < 0)) {
                throw new \InvalidArgumentException('La tolérance doit être un entier positif ou nul.');
            }
            $ordre = $creneau['ordre_affichage'] ?? 0;
            if (filter_var($ordre, FILTER_VALIDATE_INT) === false || (int) $ordre < 0) {
                throw new \InvalidArgumentException('L’ordre d’affichage doit être un entier positif ou nul.');
            }
            $notes = $creneau['notes'] ?? null;
            if ($notes !== null && ! is_string($notes)) {
                throw new \InvalidArgumentException('Les notes du créneau doivent être du texte ou null.');
            }

            $normalises[$creneau['type_creneau']] = [
                'type_creneau' => $creneau['type_creneau'],
                'mode' => $creneau['mode'],
                'heure_cible' => $heureCible,
                'tolerance_minutes' => $tolerance === null ? null : (int) $tolerance,
                'plage_debut' => $plageDebut,
                'plage_fin' => $plageFin,
                'ordre_affichage' => (int) $ordre,
                'notes' => $notes,
            ];
        }

        $this->db->transBegin();
        try {
            $existant = $this->db->table('tenant_creneaux_pointage')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            foreach ($existant as $row) {
                $this->db->table('tenant_creneaux_pointage')
                    ->where('id', (int) $row['id'])
                    ->where('tenant_id', $tenantId)
                    ->update(['actif' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            foreach ($normalises as $type => $donnees) {
                $ligne = null;
                foreach ($existant as $row) {
                    if ($row['type_creneau'] === $type) {
                        $ligne = $row;
                        break;
                    }
                }
                $donnees += ['tenant_id' => $tenantId, 'actif' => 1, 'updated_at' => date('Y-m-d H:i:s')];
                if ($ligne === null) {
                    $donnees['created_at'] = date('Y-m-d H:i:s');
                    $this->db->table('tenant_creneaux_pointage')->insert($donnees);
                } else {
                    $this->db->table('tenant_creneaux_pointage')
                        ->where('id', (int) $ligne['id'])
                        ->where('tenant_id', $tenantId)
                        ->update($donnees);
                }
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('La transaction de créneaux de pointage a échoué.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        return $this->getCreneaux($tenantId);
    }

    public function supprimer(int $tenantId, string $cle): bool
    {
        if ($tenantId < 1 || $cle === '') {
            throw new \InvalidArgumentException('Tenant ou clé de paramètre invalide.');
        }
        if (! $this->tableDisponible()) {
            return false;
        }

        $this->db->table('tenant_parametres_pointage')
            ->where('tenant_id', $tenantId)
            ->where('cle', $cle)
            ->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->invaliderCache($tenantId, $cle);

        if (! $this->transactionReussie()) {
            throw new RuntimeException('Impossible de supprimer le paramètre de pointage.');
        }

        return $this->db->affectedRows() > 0;
    }

    /**
     * Insère les valeurs initiales manquantes sans écraser celles déjà définies.
     */
    public function initialiserParametresParDefaut(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }
        if (! $this->tableDisponible()) {
            log_message('notice', 'Paramètres de pointage non initialisés pour le tenant #{tenant}: migration absente.', [
                'tenant' => $tenantId,
            ]);

            return;
        }

        foreach (self::DEFAUTS_SYSTEME as $cle => $valeur) {
            if ($this->chargerParametre($tenantId, $cle) !== null) {
                continue;
            }

            $this->insererParametreParDefaut([
                'tenant_id' => $tenantId,
                'cle' => $cle,
                'valeur' => (string) $valeur,
                'type' => $this->typeDepuisCle($cle),
                'description' => $this->descriptionDepuisCle($cle),
                'modifiable_par' => 'RH',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (! $this->transactionReussie()) {
                throw new RuntimeException('Impossible d’initialiser les paramètres de pointage du tenant.');
            }
            $this->invaliderCache($tenantId, $cle);
        }
    }

    /** @return array<string, mixed>|null */
    protected function chargerParametre(int $tenantId, string $cle): ?array
    {
        if (array_key_exists($cle, $this->cache[$tenantId] ?? [])) {
            return $this->cache[$tenantId][$cle];
        }
        if (! $this->tableDisponible()) {
            return $this->cache[$tenantId][$cle] = null;
        }

        $row = $this->db->table('tenant_parametres_pointage')
            ->where('tenant_id', $tenantId)
            ->where('cle', $cle)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $this->cache[$tenantId][$cle] = is_array($row) ? $row : null;
    }

    protected function tableDisponible(): bool
    {
        return $this->db->tableExists('tenant_parametres_pointage');
    }

    protected function transactionReussie(): bool
    {
        return $this->db === null || $this->db->transStatus() !== false;
    }

    /** @param array<string, mixed> $parametre */
    protected function insererParametreParDefaut(array $parametre): void
    {
        $this->db->table('tenant_parametres_pointage')->insert($parametre);
    }

    protected function caster(string $valeur, string $type): mixed
    {
        return match (strtoupper($type)) {
            'INT' => filter_var($valeur, FILTER_VALIDATE_INT) !== false
                ? (int) $valeur
                : throw new \UnexpectedValueException('Valeur entière de paramètre invalide.'),
            'BOOL' => match (strtolower($valeur)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw new \UnexpectedValueException('Valeur booléenne de paramètre invalide.'),
            },
            'DECIMAL' => is_numeric($valeur)
                ? (float) $valeur
                : throw new \UnexpectedValueException('Valeur décimale de paramètre invalide.'),
            'TIME' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $valeur) === 1
                ? $valeur
                : throw new \UnexpectedValueException('Valeur horaire de paramètre invalide.'),
            'STRING' => $valeur,
            default => throw new \InvalidArgumentException('Type de paramètre invalide.'),
        };
    }

    private function typeDepuisCle(string $cle): string
    {
        return in_array($cle, ['mode_calcul_heures_sup'], true) ? 'STRING' : 'INT';
    }

    private function descriptionDepuisCle(string $cle): string
    {
        return match ($cle) {
            'ecart_max_association_minutes' => 'Écart maximal autorisé pour associer un pointage à un créneau.',
            'marge_pointage_futur_minutes' => 'Marge autorisée pour les dates de pointage légèrement futures, en minutes.',
            'seuil_heures_sup_minutes' => 'Minutes au-delà de l’heure de fin avant de compter des heures supplémentaires.',
            'mode_calcul_heures_sup' => 'Mode de calcul des heures supplémentaires : ARRONDI ou EXACT.',
            'pas_arrondi_heures_sup_minutes' => 'Pas configurable, en minutes, pour l’arrondi des heures supplémentaires.',
            'duree_pause_minimum_minutes' => 'Durée minimale configurée d’une pause.',
            'duree_pause_maximum_minutes' => 'Durée maximale configurée d’une pause.',
            default => '',
        };
    }

    private function normaliserHeureCreneau(mixed $heure): ?string
    {
        if ($heure === null || $heure === '') {
            return null;
        }
        if (! is_string($heure)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $heure) !== 1
        ) {
            throw new \InvalidArgumentException('Format d’heure invalide.');
        }

        return strlen($heure) === 5 ? $heure . ':00' : $heure;
    }

    private function invaliderCache(int $tenantId, string $cle): void
    {
        unset($this->cache[$tenantId][$cle]);
    }
}
