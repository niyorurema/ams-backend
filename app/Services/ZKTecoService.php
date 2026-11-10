<?php

namespace App\Services;

use App\Libraries\ZKTecoPullClient;
use App\Models\CapteurModel;
use App\Services\CapteurPresenceService;
use App\Models\EmployeModel;
use App\Models\PointageModel;
use App\Models\SyncLogModel;
use App\Services\PointageEmployeResolver;
use Exception;
use Jmrashed\Zkteco\Lib\ZKTeco;
use Generator;
use Throwable;

/**
 * Service ZKTecoService.
 *
 * Encapsule toute la communication avec les capteurs ZKTeco/Nymbiar.
 * Le but est de centraliser la logique réseau, la lecture des données du
 * périphérique, la normalisation des résultats, ainsi que la journalisation des
 * opérations de synchronisation.
 */
class ZKTecoService
{
    protected CapteurModel $capteurModel;
    protected EmployeModel $employeModel;
    protected PointageModel $pointageModel;
    protected SyncLogModel $syncLogModel;

    public function __construct(
        ?CapteurModel $capteurModel = null,
        ?EmployeModel $employeModel = null,
        ?PointageModel $pointageModel = null,
        ?SyncLogModel $syncLogModel = null
    ) {
        $this->capteurModel = $capteurModel ?? new CapteurModel();
        $this->employeModel = $employeModel ?? new EmployeModel();
        $this->pointageModel = $pointageModel ?? new PointageModel();
        $this->syncLogModel = $syncLogModel ?? new SyncLogModel();
    }

    /**
     * Établit la connexion réseau au capteur.
     *
     * @param int $capteurId Identifiant du capteur enregistré.
     * @return ZKTeco Instance connectée.
     * @throws Exception Si le capteur n’existe pas ou si la connexion échoue.
     */
    public function connect(int $capteurId): ZKTeco
    {
        $capteur = $this->getCapteurConfig($capteurId);

        $zk = new ZKTeco($capteur['adresse_ip'], (int) $capteur['port']);

        if (! $zk->connect()) {
            throw new Exception(sprintf(
                'Échec de connexion au capteur "%s" (%s:%d).',
                $capteur['nom'],
                $capteur['adresse_ip'],
                (int) $capteur['port']
            ));
        }

        return $zk;
    }

    /**
     * Récupère la liste des utilisateurs du capteur.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array Tableau normalisé des utilisateurs.
     */
    public function getUsers(int $capteurId): array
    {
        $start = microtime(true);
        $capteur = $this->getCapteurConfig($capteurId);

        try {
            $zk = $this->connect($capteurId);
            $users = $zk->getUser();

            if ($users === false || $users === null) {
                $users = [];
            }

            $normalized = [];
            foreach ($users as $user) {
                if (! is_array($user)) {
                    continue;
                }

                $uid = (int) ($user['uid'] ?? 0);
                $userid = (string) ($user['userid'] ?? ($uid > 0 ? (string) $uid : ''));
                $name = $this->normalizeUtf8String((string) ($user['name'] ?? ''));

                if ($uid <= 0 && $userid === '') {
                    continue;
                }

                $normalized[] = [
                    'uid' => $uid,
                    'userid' => $userid,
                    'name' => $name,
                    'role' => (int) ($user['role'] ?? 0),
                    'password' => (string) ($user['password'] ?? ''),
                    'cardno' => (string) ($user['cardno'] ?? '0'),
                ];
            }

            $this->logSync(
                $capteur['tenant_id'],
                $capteurId,
                'SYNC_USERS',
                'SUCCESS',
                sprintf('Lecture de %d utilisateurs', count($normalized)),
                ['count' => count($normalized)],
                $this->elapsedMs($start)
            );

            return $normalized;
        } catch (Throwable $e) {
            $this->logSync(
                $capteur['tenant_id'],
                $capteurId,
                'SYNC_USERS',
                'ERROR',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()],
                $this->elapsedMs($start)
            );

            throw $e;
        } finally {
            if (isset($zk) && $zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (Throwable $e) {
                    // On ne remonte pas une erreur de déconnexion si elle n’est pas critique.
                }
            }
        }
    }

    /**
     * Récupère les pointages depuis le capteur.
     *
     * La bibliothèque ZKTeco peut retourner 0 pointage selon la configuration du
     * dispositif ou la lecture UDP. On essaie donc une stratégie progressive avec
     * les méthodes disponibles : collecte, limite, puis plage de dates.
     *
     * @param int $capteurId Identifiant du capteur.
     * @param ?string $startDate Date de début optionnelle.
     * @param ?string $endDate Date de fin optionnelle.
     * @return array Tableau normalisé des pointages.
     */
    public function getAttendance(int $capteurId, ?string $startDate = null, ?string $endDate = null): array
    {
        $start = microtime(true);
        $attendance = [];

        try {
            foreach ($this->getAttendanceBatches($capteurId, $startDate, $endDate) as $batch) {
                foreach ($batch as $record) {
                    $attendance[] = $record;
                }
            }

            $capteur = $this->getCapteurConfig($capteurId);
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'SYNC_ATTENDANCE',
                'SUCCESS',
                sprintf('Lecture de %d pointages', count($attendance)),
                ['count' => count($attendance)],
                $this->elapsedMs($start)
            );

            return $attendance;
        } catch (Throwable $e) {
            $capteur = $this->getCapteurConfig($capteurId);
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'SYNC_ATTENDANCE',
                'ERROR',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()],
                $this->elapsedMs($start)
            );

            $this->capteurModel->update($capteurId, ['derniere_erreur' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Produit les pointages normalisés sans conserver l’historique complet en mémoire.
     *
     * @return Generator<int, list<array{uid:int,state:string,timestamp:string,raw:array}>>
     */
    public function getAttendanceBatches(
        int $capteurId,
        ?string $startDate = null,
        ?string $endDate = null,
        int $batchSize = 1000,
        int $startIndex = 0,
        ?callable $onPrepared = null,
        int $pollIntervalSeconds = CapteurPresenceService::DEFAULT_POLL_INTERVAL_SECONDS
    ): Generator {
        $capteur = $this->getCapteurConfig($capteurId);
        $client = new ZKTecoPullClient(
            (string) $capteur['adresse_ip'],
            (int) $capteur['port'],
            true,
            (int) ($capteur['timeout_secondes'] ?? 60),
            (int) ($capteur['com_key'] ?? 0)
        );

        $filterStart = $startDate === null ? null : strtotime($startDate);
        $filterEndDate = $endDate ?? ($startDate !== null ? date('Y-m-d H:i:s') : null);
        $filterEnd = $filterEndDate === null ? null : strtotime($filterEndDate);
        if (($startDate !== null && $filterStart === false) || ($filterEndDate !== null && $filterEnd === false)) {
            throw new \InvalidArgumentException('Format de date invalide pour le filtre des pointages.');
        }

        try {
            $client->connect();
            (new CapteurPresenceService())->record(
                $capteurId,
                CapteurPresenceService::SOURCE_SERVER_POLL,
                $pollIntervalSeconds
            );
            $userIdToUid = $this->loadUserIdToUid((int) $capteur['tenant_id']);

            foreach ($client->getAttendanceBatches($batchSize, $userIdToUid, $startIndex, $onPrepared) as $batch) {
                $normalizedBatch = [];

                foreach ($batch as $record) {
                    $badgeId = (int) ($record['uid'] ?? 0);
                    $verificationStatus = (int) ($record['state'] ?? 0);
                    $punch = (int) ($record['punch'] ?? 0);
                    $timestamp = $this->normalizeDatetime((string) ($record['timestamp'] ?? ''));

                    if ($badgeId <= 0 || $timestamp === null) {
                        continue;
                    }

                    $timestampValue = strtotime($timestamp);
                    if (($filterStart !== null && $timestampValue < $filterStart)
                        || ($filterEnd !== null && $timestampValue > $filterEnd)
                    ) {
                        continue;
                    }

                    $normalizedBatch[] = [
                        'uid' => $badgeId,
                        'state' => (string) $punch,
                        'timestamp' => $timestamp,
                        'raw' => [
                            'user_id' => $record['user_id'] ?? (string) $badgeId,
                            'verification_status' => $verificationStatus,
                            'punch' => $punch,
                            'timestamp' => $timestamp,
                        ],
                    ];
                }

                if ($normalizedBatch !== []) {
                    yield $normalizedBatch;
                }
            }
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable $e) {
                log_message('warning', 'Déconnexion TCP du capteur #{capteurId} échouée : {error}', [
                    'capteurId' => $capteurId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Construit le mapping USERID capteur vers le badge enregistré pour ce tenant.
     *
     * @return array<string,int>
     */
    private function loadUserIdToUid(int $tenantId): array
    {
        $mapping = [];
        $employees = $this->employeModel
            ->select('badge_id, user_id_capteur')
            ->where('tenant_id', $tenantId)
            ->findAll();

        foreach ($employees as $employee) {
            $userId = trim((string) ($employee['user_id_capteur'] ?? ''));
            if ($userId !== '') {
                $mapping[$userId] = (int) $employee['badge_id'];
            }
        }

        return $mapping;
    }

    /**
     * Récupère les informations techniques du capteur.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array Données techniques normalisées.
     */
    public function getDeviceInfo(int $capteurId): array
    {
        $capteur = $this->getCapteurConfig($capteurId);

        try {
            $zk = $this->connect($capteurId);

            $deviceInfo = [
                'serial_number' => $zk->serialNumber(),
                'device_name' => $zk->deviceName(),
                'platform' => $zk->platform(),
                'firmware' => $zk->fmVersion(),
                'version' => $zk->version(),
                'os_version' => $zk->osVersion(),
            ];

            $this->capteurModel->update($capteurId, [
                'numero_serie' => $deviceInfo['serial_number'] ?? null,
                'firmware' => $deviceInfo['firmware'] ?? null,
                'platform' => $deviceInfo['platform'] ?? null,
                'dernier_scan' => date('Y-m-d H:i:s'),
                'derniere_erreur' => null,
                'derniere_communication' => date('Y-m-d H:i:s'),
                'source_derniere_communication' => CapteurPresenceService::SOURCE_SERVER_POLL,
                'frequence_polling_secondes' => CapteurPresenceService::DEFAULT_POLL_INTERVAL_SECONDS,
            ]);

            return $deviceInfo;
        } catch (Throwable $e) {
            $this->capteurModel->update($capteurId, [
                'derniere_erreur' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if (isset($zk) && $zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (Throwable $e) {
                    // Ignoré.
                }
            }
        }
    }

    /**
     * Vérifie la connexion au capteur sans lever un état INACTIF décidé par un administrateur.
     *
     * @return array<string, mixed>
     */
    public function testConnection(int $capteurId): array
    {
        $capteur = $this->getCapteurConfig($capteurId);
        $start = microtime(true);

        try {
            $info = $this->getDeviceInfo($capteurId);
            $update = [
                'dernier_scan' => date('Y-m-d H:i:s'),
                'derniere_erreur' => null,
            ];
            if ($this->capteurModel->update($capteurId, $update) === false) {
                throw new \RuntimeException('Impossible d’enregistrer le résultat du test du capteur.');
            }

            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'PING',
                'SUCCESS',
                'Test de connexion réussi.',
                ['device_info' => $info],
                $this->elapsedMs($start)
            );

            return $info;
        } catch (Throwable $e) {
            if ($this->capteurModel->update($capteurId, ['derniere_erreur' => $e->getMessage()]) === false) {
                log_message('error', 'Impossible d’enregistrer l’erreur du test du capteur #{id}.', ['id' => $capteurId]);
            }
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'PING',
                'ERROR',
                $e->getMessage(),
                [],
                $this->elapsedMs($start)
            );

            throw $e;
        }
    }

    /** Envoie la commande de redémarrage au capteur joignable. */
    public function restartDevice(int $capteurId): void
    {
        $capteur = $this->getCapteurConfig($capteurId);
        $start = microtime(true);
        $zk = null;

        try {
            $zk = $this->connect($capteurId);
            if (! $zk->restart()) {
                throw new \RuntimeException('Le capteur a refusé la commande de redémarrage.');
            }
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'OTHER',
                'SUCCESS',
                'Commande de redémarrage envoyée.',
                [],
                $this->elapsedMs($start)
            );
        } catch (Throwable $e) {
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'OTHER',
                'ERROR',
                $e->getMessage(),
                [],
                $this->elapsedMs($start)
            );
            throw $e;
        } finally {
            if ($zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (Throwable $e) {
                    log_message('warning', 'Déconnexion du capteur #{id} après redémarrage impossible : {error}', [
                        'id' => $capteurId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** Réactive le périphérique et confirme son état dans la base. */
    public function enableDevice(int $capteurId): void
    {
        $capteur = $this->getCapteurConfig($capteurId);
        $start = microtime(true);
        $zk = null;

        try {
            $zk = $this->connect($capteurId);
            if (! $zk->enableDevice()) {
                throw new \RuntimeException('Le capteur a refusé la commande d’activation.');
            }
            if ($this->capteurModel->update($capteurId, [
                'statut' => 'ACTIF',
                'dernier_scan' => date('Y-m-d H:i:s'),
                'derniere_erreur' => null,
            ]) === false) {
                throw new \RuntimeException('Impossible d’enregistrer le nouvel état du capteur.');
            }
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'OTHER',
                'SUCCESS',
                'Capteur activé.',
                [],
                $this->elapsedMs($start)
            );
        } catch (Throwable $e) {
            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'OTHER',
                'ERROR',
                $e->getMessage(),
                [],
                $this->elapsedMs($start)
            );
            throw $e;
        } finally {
            if ($zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (Throwable $e) {
                    log_message('warning', 'Déconnexion du capteur #{id} après activation impossible : {error}', [
                        'id' => $capteurId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Synchronise les utilisateurs d’un capteur vers la base d’application.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array Résultat de la synchronisation.
     */
    public function syncEmployes(int $capteurId): array
    {
        $capteur = $this->getCapteurConfig($capteurId);
        $start = microtime(true);

        try {
            $users = $this->getUsers($capteurId);
            $userCount = count($users);
            log_message('info', 'syncEmployes : {count} employés lus depuis le capteur #{capteurId}', [
                'count' => $userCount,
                'capteurId' => $capteurId,
            ]);

            $stats = ['crees' => 0, 'maj' => 0, 'ignores' => 0, 'erreurs' => 0];
            $erreursDetails = [];

            foreach ($users as $user) {
                if (! is_array($user)) {
                    $stats['ignores']++;
                    log_message('warning', 'syncEmployes : entrée ignorée, format utilisateur invalide.');
                    continue;
                }

                $uid = $user['uid'] ?? 'N/A';
                $userid = trim((string) ($user['userid'] ?? ''));
                $name = trim((string) ($user['name'] ?? ''));

                try {
                    log_message('debug', 'syncEmployes traitement uid={uid}, userid={userid}, name={name}', [
                        'uid' => (string) $uid,
                        'userid' => $userid,
                        'name' => $name,
                    ]);

                    $badgeId = (int) ($user['uid'] ?? 0);
                    if ($badgeId <= 0 && ctype_digit($userid)) {
                        $badgeId = (int) $userid;
                    }

                    if ($badgeId <= 0) {
                        $stats['ignores']++;
                        log_message('warning', 'syncEmployes uid={uid} ignoré : badge_id invalide.', [
                            'uid' => (string) $uid,
                        ]);
                        continue;
                    }

                    [$nom, $prenom] = $this->splitNomPrenom($name);
                    $matricule = $userid !== '' ? $userid : (string) $badgeId;
                    $nameMissing = $name === '';

                    if ($nameMissing) {
                        $nom = 'Employé ' . $badgeId;
                        $prenom = 'Non renseigné';
                    } elseif ($prenom === '') {
                        $prenom = 'Non renseigné';
                    }

                    $data = [
                        'tenant_id' => (int) $capteur['tenant_id'],
                        'matricule' => $matricule,
                        'badge_id' => $badgeId,
                        'user_id_capteur' => $userid !== '' ? $userid : null,
                        'nom' => $nom,
                        'prenom' => $prenom,
                        'role_capteur' => (int) ($user['role'] ?? 0),
                        'actif' => 1,
                        'empreinte_enrolee' => 0,
                        'derniere_sync' => date('Y-m-d H:i:s'),
                        'notes' => $nameMissing
                            ? sprintf('Synchronisé depuis le capteur; nom absent (userid=%s).', $userid ?: 'N/A')
                            : 'Synchronisé depuis le capteur',
                    ];

                    log_message('debug', 'syncEmployes data uid={uid} : {data}', [
                        'uid' => (string) $uid,
                        'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);

                    $existing = $this->employeModel
                        ->where('tenant_id', (int) $capteur['tenant_id'])
                        ->where('badge_id', $badgeId)
                        ->first();

                    if ($existing) {
                        $result = $this->employeModel->update((int) $existing['id'], $data);
                        if ($result === false) {
                            throw new \RuntimeException(sprintf(
                                'Update refusé : validation=%s; base=%s',
                                json_encode($this->employeModel->errors(), JSON_UNESCAPED_UNICODE),
                                json_encode($this->employeModel->db->error(), JSON_UNESCAPED_UNICODE)
                            ));
                        }

                        $stats['maj']++;
                        log_message('debug', 'syncEmployes uid={uid} mis à jour (employe_id={employeId}).', [
                            'uid' => (string) $uid,
                            'employeId' => (string) $existing['id'],
                        ]);
                    } else {
                        $result = $this->employeModel->insert($data);
                        if ($result === false) {
                            throw new \RuntimeException(sprintf(
                                'Insert refusé : validation=%s; base=%s',
                                json_encode($this->employeModel->errors(), JSON_UNESCAPED_UNICODE),
                                json_encode($this->employeModel->db->error(), JSON_UNESCAPED_UNICODE)
                            ));
                        }

                        $stats['crees']++;
                        log_message('debug', 'syncEmployes uid={uid} créé (employe_id={employeId}).', [
                            'uid' => (string) $uid,
                            'employeId' => (string) $result,
                        ]);
                    }
                } catch (Throwable $e) {
                    $stats['erreurs']++;
                    $erreursDetails[] = [
                        'uid' => (string) $uid,
                        'userid' => $userid,
                        'erreur' => $e->getMessage(),
                    ];
                    log_message('error', 'syncEmployes erreur uid={uid}, userid={userid} : {error}', [
                        'uid' => (string) $uid,
                        'userid' => $userid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $count = $stats['crees'] + $stats['maj'];
            $status = $stats['erreurs'] === 0
                ? 'SUCCESS'
                : ($count > 0 ? 'PARTIAL' : 'ERROR');
            $summary = sprintf(
                'Employés lus=%d, créés=%d, mis à jour=%d, ignorés=%d, erreurs=%d',
                $userCount,
                $stats['crees'],
                $stats['maj'],
                $stats['ignores'],
                $stats['erreurs']
            );

            log_message('info', 'syncEmployes terminé : {stats}', [
                'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            ]);
            if ($erreursDetails !== []) {
                log_message('error', 'syncEmployes premières erreurs : {errors}', [
                    'errors' => json_encode(array_slice($erreursDetails, 0, 5), JSON_UNESCAPED_UNICODE),
                ]);
            }

            $this->capteurModel->update($capteurId, [
                'derniere_sync_users' => date('Y-m-d H:i:s'),
                'derniere_erreur' => $stats['erreurs'] > 0
                    ? sprintf('%d erreur(s) pendant la synchronisation des employés.', $stats['erreurs'])
                    : null,
            ]);

            $this->logSync(
                (int) $capteur['tenant_id'],
                $capteurId,
                'SYNC_USERS',
                $status,
                $summary,
                ['users_lus' => $userCount, ...$stats, 'erreurs_details' => array_slice($erreursDetails, 0, 5)],
                $this->elapsedMs($start)
            );

            return [
                'capteur_id' => $capteurId,
                'tenant_id' => (int) $capteur['tenant_id'],
                'count' => $count,
                'stats' => $stats,
                'erreurs_details' => $erreursDetails,
                'status' => $status,
            ];
        } catch (Throwable $e) {
            $this->capteurModel->update($capteurId, [
                'derniere_erreur' => $e->getMessage(),
            ]);

            $this->logSync(
                $capteur['tenant_id'],
                $capteurId,
                'SYNC_USERS',
                'ERROR',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()],
                $this->elapsedMs($start)
            );

            throw $e;
        }
    }

    /**
     * Synchronise les pointages d’un capteur vers la base locale.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array Résultat de synchronisation.
     */
    public function syncPointages(
        int $capteurId,
        int $batchSize = 500,
        ?int $limit = null,
        int $pollIntervalSeconds = CapteurPresenceService::DEFAULT_POLL_INTERVAL_SECONDS
    ): array {
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new \InvalidArgumentException('La taille de lot doit être comprise entre 1 et 5000.');
        }
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('La limite doit être supérieure à zéro.');
        }
        if ($pollIntervalSeconds < 1 || $pollIntervalSeconds > 86400) {
            throw new \InvalidArgumentException('La fréquence du scanner doit être comprise entre 1 et 86400 secondes.');
        }

        $capteur = $this->getCapteurConfig($capteurId);
        (new CapteurPresenceService())->configurePollingInterval($capteurId, $pollIntervalSeconds);
        $start = microtime(true);
        $checkpoint = $capteur['dernier_pointage_sync'] ?? null;
        $checkpointCandidate = $checkpoint;
        $dernierIndex = isset($capteur['dernier_pointage_index'])
            ? (int) $capteur['dernier_pointage_index']
            : null;
        $indexRecouvrement = 100;
        $indexDepart = $checkpoint === null || $dernierIndex === null
            ? 0
            : max(0, $dernierIndex - $indexRecouvrement);
        $nombrePointagesCapteur = null;

        try {
            $stats = ['lus' => 0, 'inseres' => 0, 'doublons' => 0, 'invalides' => 0, 'dates_futures' => 0, 'lots' => 0];
            $margeFutureSeconds = (int) (new ParametragePointageService($this->pointageModel->db))
                ->get((int) $capteur['tenant_id'], 'marge_pointage_futur_minutes') * 60;

            foreach ($this->getAttendanceBatches(
                $capteurId,
                null,
                null,
                $batchSize,
                $indexDepart,
                static function (int $recordCount) use (&$nombrePointagesCapteur): void {
                    $nombrePointagesCapteur = $recordCount;
                },
                $pollIntervalSeconds
            ) as $batch) {
                if ($limit !== null) {
                    $remaining = $limit - $stats['lus'];
                    if ($remaining <= 0) {
                        break;
                    }
                    $batch = array_slice($batch, 0, $remaining);
                }

                $stats['lus'] += count($batch);
                $stats['lots']++;
                $records = [];
                $rejectedRecords = [];
                $badgeIds = [];
                $matricules = [];

                foreach ($batch as $record) {
                    $badgeId = (int) ($record['uid'] ?? 0);
                    $timestamp = $this->normalizeDatetime($record['timestamp'] ?? null);

                    if ($badgeId <= 0 || $timestamp === null) {
                        $stats['invalides']++;
                        continue;
                    }

                    $record['uid'] = $badgeId;
                    $record['timestamp'] = $timestamp;
                    $badgeIds[] = $badgeId;
                    $matricule = trim((string) ($record['raw']['user_id'] ?? ''));
                    if ($matricule !== '') {
                        $matricules[] = $matricule;
                    }

                    if (PointageTimestampValidator::isTooFarInFuture($timestamp, null, $margeFutureSeconds)) {
                        $stats['invalides']++;
                        $stats['dates_futures']++;
                        $rejectedRecords[] = $record;
                        continue;
                    }
                    if (! PointageTimestampValidator::isValid($timestamp, null, $margeFutureSeconds)) {
                        $stats['invalides']++;
                        continue;
                    }

                    $records[] = $record;
                    if ($checkpointCandidate === null || $timestamp > $checkpointCandidate) {
                        $checkpointCandidate = $timestamp;
                    }
                }

                if ($records === [] && $rejectedRecords === []) {
                    continue;
                }

                $badgeIds = array_values(array_unique($badgeIds));
                $matricules = array_values(array_unique($matricules));
                $employeesQuery = $this->employeModel
                    ->select('id, tenant_id, badge_id, matricule, user_id_capteur')
                    ->where('tenant_id', (int) $capteur['tenant_id']);
                $employeesQuery->groupStart();
                if ($matricules !== []) {
                    $employeesQuery->whereIn('matricule', $matricules);
                    $employeesQuery->orWhereIn('user_id_capteur', $matricules);
                }
                if ($badgeIds !== []) {
                    if ($matricules !== []) {
                        $employeesQuery->orWhereIn('badge_id', $badgeIds);
                    } else {
                        $employeesQuery->whereIn('badge_id', $badgeIds);
                    }
                }
                $employees = $employeesQuery->groupEnd()->findAll();
                $employeesByMatricule = [];
                $employeesByBadge = [];
                foreach ($employees as $employee) {
                    $userIdCapteur = trim((string) $employee['user_id_capteur']);
                    if ($userIdCapteur !== '') {
                        $employeesByMatricule[$userIdCapteur] = $employee;
                    }
                    $employeesByBadge[(string) (int) $employee['badge_id']] = $employee;
                }
                foreach ($employees as $employee) {
                    $matricule = trim((string) $employee['matricule']);
                    if ($matricule !== '') {
                        $employeesByMatricule[$matricule] = $employee;
                    }
                }
                $employeeResolver = new PointageEmployeResolver();
                $rejectedPointageService = new RejectedPointageService($this->pointageModel->db);
                foreach ($rejectedRecords as $record) {
                    $badgeId = (int) $record['uid'];
                    $userIdCapteur = trim((string) ($record['raw']['user_id'] ?? ''));
                    $rejectedPointageService->enregistrer(
                        (int) $capteur['tenant_id'],
                        $capteurId,
                        $badgeId,
                        $userIdCapteur,
                        (string) $record['timestamp'],
                        'ZKTECO',
                        $this->mapAttendanceState((string) ($record['state'] ?? 'UNKNOWN')),
                        $employees
                    );
                }

                if ($records === []) {
                    continue;
                }

                $timestamps = array_column($records, 'timestamp');
                $existingRows = $this->pointageModel
                    ->select('badge_id, date_pointage')
                    ->where('tenant_id', (int) $capteur['tenant_id'])
                    ->where('capteur_id', $capteurId)
                    ->whereIn('badge_id', $badgeIds)
                    ->where('date_pointage >=', min($timestamps))
                    ->where('date_pointage <=', max($timestamps))
                    ->findAll();
                $existingKeys = [];
                foreach ($existingRows as $existing) {
                    $existingKeys[(int) $existing['badge_id'] . '|' . $existing['date_pointage']] = true;
                }

                $rowsToInsert = [];
                $clesNouveaux = [];
                foreach ($records as $record) {
                    $badgeId = (int) $record['uid'];
                    $timestamp = $record['timestamp'];
                    $dedupeKey = $badgeId . '|' . $timestamp;

                    if (isset($existingKeys[$dedupeKey])) {
                        $stats['doublons']++;
                        continue;
                    }
                    $existingKeys[$dedupeKey] = true;

                    $rawData = $record['raw'] ?? $record;
                    $matricule = trim((string) ($rawData['user_id'] ?? ''));
                    if ($matricule === '') {
                        $matricule = (string) $badgeId;
                    }
                    $employe = $employeeResolver->resoudre(
                        $matricule,
                        $badgeId,
                        $employeesByMatricule,
                        $employeesByBadge
                    );
                    $rawData['user_id'] = $matricule;
                    $rawData['badge_id'] = $badgeId;
                    $rawData['matricule'] = $employe['matricule'] ?? null;
                    $rawData['user_id_capteur'] = $employe['user_id_capteur'] ?? null;

                    $rowsToInsert[] = [
                        'tenant_id' => (int) $capteur['tenant_id'],
                        'employe_id' => $employe !== null ? (int) $employe['id'] : null,
                        'badge_id' => $badgeId,
                        'capteur_id' => $capteurId,
                        'type_evenement' => $this->mapAttendanceState((string) ($record['state'] ?? 'UNKNOWN')),
                        'date_pointage' => $timestamp,
                        'source' => 'CAPTEUR',
                        'statut' => 'VALIDE',
                        'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'notes' => 'Synchronisé depuis le capteur',
                    ];
                    $clesNouveaux[$dedupeKey] = true;
                }

                $joursImpactes = array_values(array_unique(array_map(
                    static fn (array $record): string => substr((string) $record['timestamp'], 0, 10),
                    $records
                )));
                if ($rowsToInsert === []) {
                    $reclassification = new PointageReclassificationService($this->pointageModel->db);
                    foreach ($joursImpactes as $jour) {
                        $reclassification->reclassifierJournee((int) $capteur['tenant_id'], $jour, true);
                    }
                    continue;
                }

                $this->pointageModel->db->transBegin();
                try {
                    $inserted = $this->pointageModel->insertBatch($rowsToInsert, null, $batchSize);
                    if ($inserted === false) {
                        throw new \RuntimeException(sprintf(
                            'Validation du lot %d refusée : %s',
                            $stats['lots'],
                            json_encode($this->pointageModel->errors(), JSON_UNESCAPED_UNICODE)
                        ));
                    }
                    if ($this->pointageModel->db->transStatus() === false) {
                        throw new \RuntimeException(sprintf('Insertion du lot %d échouée en base.', $stats['lots']));
                    }

                    $this->pointageModel->db->transCommit();
                    $stats['inseres'] += (int) $inserted;
                } catch (Throwable $e) {
                    $this->pointageModel->db->transRollback();
                    throw $e;
                }

                $reclassification = new PointageReclassificationService($this->pointageModel->db);
                foreach ($joursImpactes as $jour) {
                    $reclassification->reclassifierJournee((int) $capteur['tenant_id'], $jour, true);
                }

                $nouveauxPointages = $this->pointageModel->db->table('pointages p')
                    ->select('p.id, p.tenant_id, p.employe_id, p.badge_id, p.capteur_id, p.date_pointage, p.type_evenement, p.statut_calcul, p.raw_data, e.matricule AS employe_matricule, CONCAT_WS(\' \', e.nom, e.prenom) AS employe_nom')
                    ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
                    ->where('p.tenant_id', (int) $capteur['tenant_id'])
                    ->where('p.capteur_id', $capteurId)
                    ->whereIn('p.badge_id', array_values(array_unique(array_column($rowsToInsert, 'badge_id'))))
                    ->where('p.date_pointage >=', min(array_column($rowsToInsert, 'date_pointage')))
                    ->where('p.date_pointage <=', max(array_column($rowsToInsert, 'date_pointage')))
                    ->get()
                    ->getResultArray();
                foreach ($nouveauxPointages as $nouveauPointage) {
                    $cle = (int) $nouveauPointage['badge_id'] . '|' . $nouveauPointage['date_pointage'];
                    if (! isset($clesNouveaux[$cle])) {
                        continue;
                    }
                    log_message('info', '[Pointage] Inséré id={id} tenant_id={tenantId} employe_id={employeeId}', [
                        'id' => (int) $nouveauPointage['id'],
                        'tenantId' => (int) $nouveauPointage['tenant_id'],
                        'employeeId' => $nouveauPointage['employe_id'] === null
                            ? 'null'
                            : (int) $nouveauPointage['employe_id'],
                    ]);
                    try {
                        $streamId = (new RealtimeService())->publierPointage($nouveauPointage);
                        log_message('info', '[Redis] Pointage publié id={pointageId} stream_id={streamId}', [
                            'pointageId' => (int) $nouveauPointage['id'],
                            'streamId' => $streamId,
                        ]);
                    } catch (Throwable $exception) {
                        log_message('error', 'Publication temps réel du pointage #{id} impossible : {message}', [
                            'id' => (int) $nouveauPointage['id'],
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }

                log_message('info', 'Sync pointages capteur #{capteurId} lot={batch} lus={read} insérés={inserted}', [
                    'capteurId' => $capteurId,
                    'batch' => $stats['lots'],
                    'read' => $stats['lus'],
                    'inserted' => $stats['inseres'],
                ]);

                if ($limit !== null && $stats['lus'] >= $limit) {
                    break;
                }
            }

            $summary = sprintf(
                'Pointages lus=%d, insérés=%d, doublons=%d, invalides=%d, dates futures ignorées=%d, lots=%d',
                $stats['lus'],
                $stats['inseres'],
                $stats['doublons'],
                $stats['invalides'],
                $stats['dates_futures'],
                $stats['lots']
            );

            $capteurUpdate = [
                'derniere_sync_attendance' => date('Y-m-d H:i:s'),
                'derniere_erreur' => null,
                'derniere_communication' => date('Y-m-d H:i:s'),
                'source_derniere_communication' => CapteurPresenceService::SOURCE_SERVER_POLL,
            ];
            if ($limit === null && $nombrePointagesCapteur !== null) {
                $capteurUpdate['dernier_pointage_index'] = $nombrePointagesCapteur;
            }
            $checkpointAdvanced = $limit === null
                && $checkpointCandidate !== null
                && ($checkpoint === null || $checkpointCandidate > $checkpoint);

            if ($limit === null && $checkpointCandidate !== null) {
                $capteurUpdate['dernier_pointage_sync'] = $checkpointCandidate;
            }
            if ($this->capteurModel->update($capteurId, $capteurUpdate) === false) {
                throw new \RuntimeException('Impossible de mettre à jour l’état de synchronisation du capteur.');
            }

            $stats['checkpoint_avance'] = $checkpointAdvanced ? 1 : 0;
            $stats['dernier_pointage_sync'] = $limit === null ? $checkpointCandidate : $checkpoint;
            $summary .= sprintf(
                ', checkpoint=%s, index=%s/%s',
                $stats['dernier_pointage_sync'] ?? 'inchangé',
                $limit === null && $nombrePointagesCapteur !== null ? $nombrePointagesCapteur : 'inchangé',
                $nombrePointagesCapteur ?? 'inconnu'
            );

            $this->logSync(
                $capteur['tenant_id'],
                $capteurId,
                'SYNC_ATTENDANCE',
                'SUCCESS',
                $summary,
                $stats,
                $this->elapsedMs($start)
            );

            return [
                'capteur_id' => $capteurId,
                'tenant_id' => (int) $capteur['tenant_id'],
                'count' => $stats['inseres'],
                'stats' => $stats,
                'status' => 'SUCCESS',
            ];
        } catch (Throwable $e) {
            $this->capteurModel->update($capteurId, [
                'derniere_erreur' => $e->getMessage(),
            ]);

            $this->logSync(
                $capteur['tenant_id'],
                $capteurId,
                'SYNC_ATTENDANCE',
                'ERROR',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()],
                $this->elapsedMs($start)
            );

            throw $e;
        }
    }

    /**
     * Retourne la configuration d’un capteur.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array Données du capteur.
     * @throws Exception Si le capteur est introuvable.
     */
    protected function getCapteurConfig(int $capteurId): array
    {
        $capteur = $this->capteurModel->find($capteurId);

        if (! is_array($capteur)) {
            throw new Exception(sprintf('Capteur #%d introuvable.', $capteurId));
        }

        return $capteur;
    }

    /**
     * Génère un log de synchronisation.
     *
     * @param int $tenantId Identifiant du tenant.
     * @param int $capteurId Identifiant du capteur.
     * @param string $typeOperation Type d’opération.
     * @param string $statut Statut de l’opération.
     * @param string $message Message humain.
     * @param array $details Détails techniques.
     * @param int $dureeMs Durée de traitement en millisecondes.
     */
    protected function logSync(
        int $tenantId,
        int $capteurId,
        string $typeOperation,
        string $statut,
        string $message,
        array $details = [],
        int $dureeMs = 0
    ): void {
        $this->syncLogModel->insert([
            'tenant_id' => $tenantId,
            'capteur_id' => $capteurId,
            'type_operation' => $typeOperation,
            'statut' => $statut,
            'message' => $message,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'duree_ms' => $dureeMs,
        ]);
    }

    /**
     * Convertit une chaîne en UTF-8 si nécessaire.
     */
    protected function normalizeUtf8String(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $detectedEncoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            $sourceEncoding = $detectedEncoding ?: 'ISO-8859-1';
            $value = mb_convert_encoding($value, 'UTF-8', $sourceEncoding);
        }

        if (preg_match('/(?:Ã[\x80-\xBF]|Â[\x80-\xBF]|â€)/u', $value) === 1) {
            $repaired = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');

            if (mb_check_encoding($repaired, 'UTF-8')
                && preg_match('/(?:Ã[\x80-\xBF]|Â[\x80-\xBF]|â€)/u', $repaired) !== 1
            ) {
                $value = $repaired;
            }
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    }

    /**
     * Sépare le nom et le prénom à partir d’un nom complet.
     *
     * @return array<int, string>
     */
    protected function splitNomPrenom(string $fullName): array
    {
        $fullName = $this->normalizeUtf8String($fullName);

        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', trim($fullName));
        if ($parts === false || count($parts) === 0) {
            return [$fullName, ''];
        }

        if (count($parts) === 1) {
            return [(string) reset($parts), ''];
        }

        $prenom = array_shift($parts);
        $nom = implode(' ', $parts);

        return [$nom, $prenom];
    }

    /**
     * Convertit un état de pointage ZKTeco en type métier applicatif.
     */
    protected function mapAttendanceState(string $state): string
    {
        $value = strtoupper(trim($state));

        $mapping = [
            'CHECKIN' => 'CHECK_IN',
            'CHECK_IN' => 'CHECK_IN',
            'CHECKOUT' => 'CHECK_OUT',
            'CHECK_OUT' => 'CHECK_OUT',
            'IN' => 'CHECK_IN',
            'OUT' => 'CHECK_OUT',
            'BREAK_IN' => 'BREAK_IN',
            'BREAK_OUT' => 'BREAK_OUT',
            '0' => 'CHECK_IN',
            '1' => 'CHECK_OUT',
            '2' => 'BREAK_IN',
            '3' => 'BREAK_OUT',
        ];

        return $mapping[$value] ?? 'UNKNOWN';
    }

    /**
     * Normalise une date/heure ZKTeco en datetime SQL.
     */
    protected function normalizeDatetime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Retourne le temps écoulé en millisecondes.
     */
    protected function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
