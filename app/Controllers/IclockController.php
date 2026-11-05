<?php

namespace App\Controllers;

use App\Models\CapteurModel;
use App\Models\EmployeModel;
use App\Models\PointageModel;
use App\Services\PointageEmployeResolver;
use App\Services\PointageReclassificationService;
use App\Services\RealtimeService;
use App\Services\AuditContext;
use App\Services\CapteurPresenceService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Reçoit les requêtes HTTP des capteurs ZKTeco en mode ADMS.
 */
class IclockController extends Controller
{
    private const ADMS_TRANSMISSION_INTERVAL_MINUTES = 1;

    /**
     * Répond aux demandes de configuration et reçoit les données poussées.
     */
    public function cdata(): ResponseInterface
    {
        $serialNumber = trim((string) $this->request->getGet('SN'));
        $options = (string) $this->request->getGet('options');
        $table = strtoupper(trim((string) $this->request->getGet('table')));
        $sourceIp = $this->request->getIPAddress();

        if ($serialNumber === '') {
            return $this->textResponse('Missing SN', 400);
        }

        $accessCheck = $this->verifierAccesCapteur($serialNumber);
        if ($accessCheck !== null) {
            return $accessCheck;
        }

        log_message('info', 'ADMS cdata SN={serialNumber} method={method} source_ip={sourceIp} options={options} table={table}', [
            'serialNumber' => $serialNumber,
            'method' => $this->request->getMethod(),
            'sourceIp' => $sourceIp,
            'options' => $options,
            'table' => $table,
        ]);

        if (strtoupper($this->request->getMethod()) === 'POST') {
            return $this->recevoirDonnees($serialNumber, $table);
        }

        return $this->envoyerConfig($serialNumber);
    }

    /**
     * Le capteur interroge le serveur pour obtenir une commande en attente.
     */
    public function getrequest(): ResponseInterface
    {
        $serialNumber = trim((string) $this->request->getGet('SN'));
        if ($serialNumber !== '') {
            $accessCheck = $this->verifierAccesCapteur($serialNumber);
            if ($accessCheck !== null) return $accessCheck;
        }
        log_message('info', 'ADMS getrequest SN={serialNumber} source_ip={sourceIp}', [
            'serialNumber' => $serialNumber,
            'sourceIp' => $this->request->getIPAddress(),
        ]);

        return $this->textResponse('OK');
    }

    /**
     * Accuse réception des commandes exécutées par le capteur.
     */
    public function devicecmd(): ResponseInterface
    {
        $serialNumber = trim((string) $this->request->getGet('SN'));
        if ($serialNumber !== '') {
            $accessCheck = $this->verifierAccesCapteur($serialNumber);
            if ($accessCheck !== null) return $accessCheck;
        }
        $bodyLength = strlen($this->request->getBody());

        log_message('info', 'ADMS devicecmd SN={serialNumber} source_ip={sourceIp} body_bytes={bodyLength}', [
            'serialNumber' => $serialNumber,
            'sourceIp' => $this->request->getIPAddress(),
            'bodyLength' => $bodyLength,
        ]);

        return $this->textResponse('OK');
    }

    private function verifierAccesCapteur(string $serialNumber): ?ResponseInterface
    {
        $capteur = (new CapteurModel())->where('numero_serie', $serialNumber)->first();
        if (! $capteur) {
            return null;
        }

        if (filter_var(env('ADMS_ENFORCE_IP', false), FILTER_VALIDATE_BOOLEAN)) {
            $expectedIp = trim((string) ($capteur['adresse_ip'] ?? ''));
            if ($expectedIp !== '' && $this->request->getIPAddress() !== $expectedIp) {
                log_message('warning', 'ADMS IP refusée SN={serialNumber} attendu={expected} reçu={received}', [
                    'serialNumber' => $serialNumber,
                    'expected' => $expectedIp,
                    'received' => $this->request->getIPAddress(),
                ]);
                return $this->textResponse('Forbidden', 403);
            }
        }

        if (! filter_var(env('ADMS_REQUIRE_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->enregistrerCommunicationAdms((int) $capteur['id']);

            return null;
        }

        $secret = trim((string) ($capteur['com_key'] ?? ''));
        $timestamp = trim($this->request->getHeaderLine('X-AMS-Timestamp'));
        $signature = trim($this->request->getHeaderLine('X-AMS-Signature'));
        if ($secret === '' || $secret === '0' || $timestamp === '' || $signature === '') {
            log_message('warning', 'ADMS signature obligatoire manquante SN={serialNumber}', ['serialNumber' => $serialNumber]);
            return $this->textResponse('Forbidden', 403);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return $this->textResponse('Forbidden', 403);
        }

        $payload = $timestamp . '.' . $serialNumber . '.' . $this->request->getBody();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        if (! hash_equals($expectedSignature, strtolower($signature))) {
            log_message('warning', 'ADMS signature invalide SN={serialNumber}', ['serialNumber' => $serialNumber]);
            return $this->textResponse('Forbidden', 403);
        }

        $this->enregistrerCommunicationAdms((int) $capteur['id']);

        return null;
    }

    private function enregistrerCommunicationAdms(int $capteurId): void
    {
        (new CapteurPresenceService())->record(
            $capteurId,
            CapteurPresenceService::SOURCE_ADMS,
            self::ADMS_TRANSMISSION_INTERVAL_MINUTES * 60
        );
    }

    /**
     * Enregistre le contenu reçu pour un capteur connu.
     */
    private function recevoirDonnees(string $serialNumber, string $table): ResponseInterface
    {
        $body = $this->request->getBody();
        $capteur = (new CapteurModel())->where('numero_serie', $serialNumber)->first();

        if (! $capteur) {
            log_message('error', 'ADMS : capteur inconnu SN={serialNumber}', ['serialNumber' => $serialNumber]);

            return $this->textResponse('OK');
        }

        if ($capteur['statut'] !== 'ACTIF') {
            log_message('warning', 'ADMS : données ignorées pour le capteur inactif id={capteurId}', [
                'capteurId' => $capteur['id'],
            ]);

            return $this->textResponse('OK');
        }

        $metadata = AuditContext::requestMetadata($this->request);
        AuditContext::apply(
            null,
            (int) $capteur['tenant_id'],
            'DEVICE',
            (int) $capteur['id'],
            bin2hex(random_bytes(16)),
            $metadata['ip_address'],
            $metadata['user_agent']
        );

        try {
            switch ($table) {
                case 'ATTLOG':
                    $result = $this->traiterPointages($capteur, $body);
                    log_message('info', 'ADMS ATTLOG capteur={capteurId} reçus={received} insérés={inserted} doublons={duplicates} invalides={invalid}', [
                        'capteurId' => $capteur['id'],
                        'received' => $result['received'],
                        'inserted' => $result['inserted'],
                        'duplicates' => $result['duplicates'],
                        'invalid' => $result['invalid'],
                    ]);
                    break;

                case 'OPERLOG':
                case 'USERINFO':
                    log_message('info', 'ADMS {table} reçu capteur={capteurId} octets={bodyLength}', [
                        'table' => $table,
                        'capteurId' => $capteur['id'],
                        'bodyLength' => strlen($body),
                    ]);
                    break;

                default:
                    log_message('warning', 'ADMS table non gérée={table} capteur={capteurId}', [
                        'table' => $table,
                        'capteurId' => $capteur['id'],
                    ]);
            }
        } catch (Throwable $e) {
            log_message('error', 'ADMS traitement échoué SN={serialNumber} table={table} erreur={error}', [
                'serialNumber' => $serialNumber,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return $this->textResponse('ERROR', 500);
        }

        return $this->textResponse('OK');
    }

    /**
     * Enregistre les lignes tabulées ATTLOG et ignore les doublons exacts.
     *
     * @return array{received:int,inserted:int,duplicates:int,invalid:int}
     */
    private function traiterPointages(array $capteur, string $body): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($body));
        $parsedRecords = [];
        $invalid = 0;

        foreach ($lines ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = $this->parserLignePointage($line);
            if ($record === null) {
                $invalid++;
                continue;
            }

            $parsedRecords[] = $record;
        }

        if ($parsedRecords === []) {
            return [
                'received' => count($lines ?: []),
                'inserted' => 0,
                'duplicates' => 0,
                'invalid' => $invalid,
            ];
        }

        $badgeIds = array_values(array_unique(array_column($parsedRecords, 'badge_id')));
        $matricules = array_values(array_unique(array_column($parsedRecords, 'user_id')));
        $employeeQuery = (new EmployeModel())
            ->select('id, badge_id, matricule, user_id_capteur')
            ->where('tenant_id', (int) $capteur['tenant_id']);
        $employeeQuery->groupStart();
        if ($matricules !== []) {
            $employeeQuery->whereIn('matricule', $matricules);
            $employeeQuery->orWhereIn('user_id_capteur', $matricules);
        }
        if ($badgeIds !== []) {
            if ($matricules !== []) {
                $employeeQuery->orWhereIn('badge_id', $badgeIds);
            } else {
                $employeeQuery->whereIn('badge_id', $badgeIds);
            }
        }
        $employees = $employeeQuery->groupEnd()->findAll();
        $employeeByMatricule = [];
        $employeeByBadge = [];

        foreach ($employees as $employee) {
            $userIdCapteur = trim((string) $employee['user_id_capteur']);
            if ($userIdCapteur !== '') {
                $employeeByMatricule[$userIdCapteur] = $employee;
            }
            $employeeByBadge[(string) (int) $employee['badge_id']] = $employee;
        }
        foreach ($employees as $employee) {
            $matricule = trim((string) $employee['matricule']);
            if ($matricule !== '') {
                $employeeByMatricule[$matricule] = $employee;
            }
        }
        $employeeResolver = new PointageEmployeResolver();

        $pointageModel = new PointageModel();
        $pointageModel->db->transBegin();
        $inserted = 0;
        $duplicates = 0;
        $insertedPointageIds = [];

        foreach ($parsedRecords as $record) {
            $exists = $pointageModel
                ->where('tenant_id', (int) $capteur['tenant_id'])
                ->where('capteur_id', (int) $capteur['id'])
                ->where('badge_id', $record['badge_id'])
                ->where('date_pointage', $record['timestamp'])
                ->first();

            if ($exists) {
                $duplicates++;
                continue;
            }

            $employe = $employeeResolver->resoudre(
                $record['user_id'],
                $record['badge_id'],
                $employeeByMatricule,
                $employeeByBadge
            );
            $rawData = [
                ...$record['raw'],
                'user_id' => $record['user_id'],
                'badge_id' => $record['badge_id'],
                'matricule' => $employe['matricule'] ?? null,
                'user_id_capteur' => $employe['user_id_capteur'] ?? null,
                'verification_status' => $record['type'],
                'punch' => $record['state'],
                'timestamp' => $record['timestamp'],
            ];

            $insertedId = $pointageModel->insert([
                'tenant_id' => (int) $capteur['tenant_id'],
                'employe_id' => $employe !== null ? (int) $employe['id'] : null,
                'badge_id' => $record['badge_id'],
                'capteur_id' => (int) $capteur['id'],
                'type_evenement' => $this->mapperTypeEvenement($record['state']),
                'date_pointage' => $record['timestamp'],
                'source' => 'CAPTEUR',
                'statut' => 'VALIDE',
                'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            if ($insertedId === false) {
                $pointageModel->db->transRollback();
                throw new \RuntimeException('Échec de validation ou insertion d’un pointage ADMS.');
            }

            $inserted++;
            $insertedPointageIds[] = (int) $insertedId;
        }

        if ($pointageModel->db->transStatus() === false) {
            $pointageModel->db->transRollback();
            throw new \RuntimeException('Échec de la transaction d’enregistrement des pointages ADMS.');
        }

        $pointageModel->db->transCommit();

        $jours = array_values(array_unique(array_map(
            static fn (array $record): string => substr($record['timestamp'], 0, 10),
            $parsedRecords
        )));
        $reclassification = new PointageReclassificationService($pointageModel->db);
        foreach ($jours as $jour) {
            $reclassification->reclassifierJournee((int) $capteur['tenant_id'], $jour, true);
        }

        if ($insertedPointageIds !== []) {
            $nouveauxPointages = $pointageModel->db->table('pointages p')
                ->select('p.id, p.tenant_id, p.employe_id, p.badge_id, p.capteur_id, p.date_pointage, p.type_evenement, p.statut_calcul, p.raw_data, e.matricule AS employe_matricule, CONCAT_WS(\' \', e.nom, e.prenom) AS employe_nom')
                ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
                ->where('p.tenant_id', (int) $capteur['tenant_id'])
                ->whereIn('p.id', $insertedPointageIds)
                ->get()
                ->getResultArray();

            foreach ($nouveauxPointages as $nouveauPointage) {
                try {
                    (new RealtimeService())->publierPointage($nouveauPointage);
                } catch (Throwable $exception) {
                    log_message('error', 'Publication temps réel du pointage ADMS #{id} impossible : {message}', [
                        'id' => (int) $nouveauPointage['id'],
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($inserted > 0) {
            (new CapteurModel())->update((int) $capteur['id'], [
                'derniere_sync_attendance' => date('Y-m-d H:i:s'),
                'derniere_erreur' => null,
            ]);
        }

        return [
            'received' => count($lines ?: []),
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }

    /**
     * Décode une ligne ATTLOG tabulée sans découper le timestamp sur son espace.
     */
    private function parserLignePointage(string $line): ?array
    {
        if (str_contains($line, "\t")) {
            $fields = explode("\t", trim($line));
            if (count($fields) < 4) {
                return null;
            }

            $badgeValue = trim($fields[0]);
            $dateValue = trim($fields[1]);
            $stateValue = trim($fields[2]);
            $typeValue = trim($fields[3]);
        } elseif (preg_match('/^(\d+)\s+(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\s+(-?\d+)(?:\s+(-?\d+))?\s*$/', trim($line), $matches) === 1) {
            $badgeValue = $matches[1];
            $dateValue = $matches[2];
            $stateValue = $matches[3];
            $typeValue = $matches[4] ?? '0';
        } else {
            return null;
        }

        if (! ctype_digit($badgeValue) || (int) $badgeValue < 1 || ! preg_match('/^-?\d+$/', $stateValue)) {
            return null;
        }

        $timestamp = $this->normaliserDatePointage($dateValue);
        if ($timestamp === null) {
            return null;
        }

        return [
            'badge_id' => (int) $badgeValue,
            'user_id' => $badgeValue,
            'timestamp' => $timestamp,
            'state' => (int) $stateValue,
            'type' => preg_match('/^-?\d+$/', $typeValue) === 1 ? (int) $typeValue : 0,
            'raw' => [
                'user_id' => $badgeValue,
                'state' => (int) $stateValue,
                'type' => preg_match('/^-?\d+$/', $typeValue) === 1 ? (int) $typeValue : null,
                'line' => $line,
            ],
        ];
    }

    /**
     * Valide puis convertit une date ADMS au format datetime MySQL.
     */
    private function normaliserDatePointage(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        if ((int) $date->format('Y') < 2001) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Retourne la configuration de transfert demandée par le capteur.
     */
    private function envoyerConfig(string $serialNumber): ResponseInterface
    {
        $config = implode("\n", [
            'GET OPTION FROM: ' . $serialNumber,
            'Stamp=9999',
            'OpStamp=9999',
            'ATTLOGStamp=9999',
            'OPERLOGStamp=9999',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;23:59',
            'TransInterval=' . self::ADMS_TRANSMISSION_INTERVAL_MINUTES,
            'TransFlag=1111000000',
            'Realtime=1',
            'Encrypt=0',
            '',
        ]);

        return $this->textResponse($config);
    }

    /**
     * Convertit l’état ADMS en valeur autorisée par la table pointages.
     */
    private function mapperTypeEvenement(int $state): string
    {
        return match ($state) {
            0 => 'CHECK_IN',
            1 => 'CHECK_OUT',
            2 => 'BREAK_IN',
            3 => 'BREAK_OUT',
            default => 'UNKNOWN',
        };
    }

    /**
     * Construit une réponse texte simple attendue par le protocole ADMS.
     */
    private function textResponse(string $body, int $statusCode = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($statusCode)
            ->setContentType('text/plain', 'UTF-8')
            ->setBody($body);
    }
}