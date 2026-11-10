<?php

namespace App\Services;

use App\Models\EmployeModel;
use Config\Database;
use Predis\Client;
use RuntimeException;
use Throwable;

/**
 * Publie et lit les événements temps réel stockés dans Redis Streams.
 */
final class RealtimeService
{
    private Client $redis;

    public function __construct(?Client $redis = null)
    {
        $this->redis = $redis ?? new Client([
            'scheme' => 'tcp',
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD') ?: null,
            'database' => (int) env('REDIS_DATABASE', 0),
            'read_write_timeout' => 10,
            'timeout' => 2,
        ]);
    }

    /**
     * Ajoute un pointage au flux de son tenant.
     *
     * @return string Identifiant Redis Stream de l'événement.
     */
    public function publierPointage(array $pointage): string
    {
        $tenantId = (int) ($pointage['tenant_id'] ?? 0);
        if ($tenantId < 1 || (int) ($pointage['id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('Tenant ou pointage invalide pour la publication temps réel.');
        }

        $margeFutureSeconds = (int) (new ParametragePointageService())->get(
            $tenantId,
            'marge_pointage_futur_minutes'
        ) * 60;
        $datePointage = (string) ($pointage['date_pointage'] ?? '');
        if (! PointageTimestampValidator::isValid($datePointage, null, $margeFutureSeconds)) {
            $rawData = $this->decoderRawData($pointage['raw_data'] ?? null, (int) $pointage['id']);
            $dateRaw = is_array($rawData) ? (string) ($rawData['timestamp'] ?? '') : '';
            if (! PointageTimestampValidator::isValid($dateRaw, null, $margeFutureSeconds)) {
                throw new \InvalidArgumentException('Date sentinelle, invalide ou trop éloignée dans le futur pour la publication temps réel.');
            }
            $datePointage = $dateRaw;
        }

        $employeId = isset($pointage['employe_id']) ? (int) $pointage['employe_id'] : null;
        $employeNom = trim((string) ($pointage['employe_nom'] ?? ''));
        $employeMatricule = isset($pointage['employe_matricule'])
            ? trim((string) $pointage['employe_matricule'])
            : '';
        if ($employeId === null || $employeId < 1) {
            [$employeId, $employeNom, $employeMatricule] = $this->resoudreEmploye($pointage, $tenantId);
        }

        $event = [
            'type' => 'nouveau_pointage',
            'data' => [
                'id' => (int) $pointage['id'],
                'employe_id' => $employeId,
                'employe_nom' => $employeNom !== '' ? $employeNom : null,
                'employe_matricule' => $employeMatricule !== '' ? $employeMatricule : null,
                'badge_id' => isset($pointage['badge_id']) ? (int) $pointage['badge_id'] : null,
                'date_pointage' => $datePointage,
                'type_evenement' => (string) $pointage['type_evenement'],
                'statut_calcul' => (string) ($pointage['statut_calcul'] ?? 'UNKNOWN'),
                'capteur_id' => isset($pointage['capteur_id']) ? (int) $pointage['capteur_id'] : null,
            ],
            'timestamp' => date(DATE_ATOM),
        ];

        return $this->ajouterAuFlux($this->fluxPointages($tenantId), $event);
    }

    /**
     * Résout un pointage orphelin d’abord par les identifiants capteur/matricule, puis par badge.
     *
     * @return array{0:int|null,1:string,2:string}
     */
    private function resoudreEmploye(array $pointage, int $tenantId): array
    {
        $rawData = $this->decoderRawData($pointage['raw_data'] ?? null, (int) ($pointage['id'] ?? 0)) ?? [];

        $identifiants = array_values(array_unique(array_filter([
            trim((string) ($pointage['employe_matricule'] ?? $rawData['matricule'] ?? '')),
            trim((string) ($rawData['user_id'] ?? '')),
            trim((string) ($rawData['user_id_capteur'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));

        foreach ($identifiants as $identifiant) {
            $employe = (new EmployeModel())
                ->select('id, matricule, nom, prenom')
                ->where('tenant_id', $tenantId)
                ->groupStart()
                ->where('matricule', $identifiant)
                ->orWhere('user_id_capteur', $identifiant)
                ->groupEnd()
                ->first();
            if ($employe !== null) {
                return [
                    (int) $employe['id'],
                    trim((string) $employe['nom'] . ' ' . (string) $employe['prenom']),
                    trim((string) $employe['matricule']),
                ];
            }
        }

        $badgeId = (int) ($pointage['badge_id'] ?? 0);
        if ($badgeId > 0) {
            $employe = (new EmployeModel())
                ->select('id, matricule, nom, prenom')
                ->where('tenant_id', $tenantId)
                ->where('badge_id', $badgeId)
                ->first();
            if ($employe !== null) {
                return [
                    (int) $employe['id'],
                    trim((string) $employe['nom'] . ' ' . (string) $employe['prenom']),
                    trim((string) $employe['matricule']),
                ];
            }
        }

        $fallbackMatricule = $identifiants[0] ?? '';

        return [null, '', $fallbackMatricule];
    }

    /**
     * Complète les anciens événements Redis avec les données actuelles du pointage.
     *
     * @param array<string,mixed> $evenement
     * @return array<string,mixed>
     */
    public function enrichirEvenementPointage(array $evenement, int $tenantId): array
    {
        if (($evenement['type'] ?? null) !== 'nouveau_pointage') {
            return $evenement;
        }
        $donnees = $evenement['data'] ?? null;
        $pointageId = is_array($donnees) ? (int) ($donnees['id'] ?? 0) : 0;
        if ($tenantId < 1 || $pointageId < 1) {
            return $evenement;
        }

        $nomCourant = trim((string) ($donnees['employe_nom'] ?? ''));
        $matriculePresent = array_key_exists('employe_matricule', $donnees);
        $badgePresent = array_key_exists('badge_id', $donnees);
        $dateCourante = (string) ($donnees['date_pointage'] ?? '');
        $margeFutureSeconds = (int) (new ParametragePointageService())->get(
            $tenantId,
            'marge_pointage_futur_minutes'
        ) * 60;
        $dateCouranteValide = PointageTimestampValidator::isValid($dateCourante, null, $margeFutureSeconds);
        if ($nomCourant !== '' && $matriculePresent && $badgePresent && $dateCouranteValide) {
            return $evenement;
        }

        $pointage = Database::connect()->table('pointages p')
            ->select('p.id, p.tenant_id, p.employe_id, p.badge_id, p.capteur_id, p.date_pointage, p.type_evenement, p.statut_calcul, p.raw_data, e.matricule AS employe_matricule, CONCAT_WS(\' \', e.nom, e.prenom) AS employe_nom')
            ->join('employes e', 'e.id = p.employe_id AND e.tenant_id = p.tenant_id AND e.deleted_at IS NULL', 'left')
            ->where('p.id', $pointageId)
            ->where('p.tenant_id', $tenantId)
            ->where('p.deleted_at', null)
            ->get()
            ->getRowArray();
        if ($pointage === null) {
            return $evenement;
        }

        $employeId = isset($pointage['employe_id']) ? (int) $pointage['employe_id'] : null;
        $employeNom = trim((string) ($pointage['employe_nom'] ?? ''));
        $employeMatricule = trim((string) ($pointage['employe_matricule'] ?? ''));
        if ($employeId === null || $employeId < 1) {
            [$employeId, $employeNom, $employeMatricule] = $this->resoudreEmploye($pointage, $tenantId);
        }

        $rawData = $this->decoderRawData($pointage['raw_data'] ?? null, $pointageId);
        $rawTimestamp = is_array($rawData) ? (string) ($rawData['timestamp'] ?? '') : '';
        $datePointage = (string) $pointage['date_pointage'];
        if (PointageTimestampValidator::isValid($rawTimestamp, null, $margeFutureSeconds)) {
            $datePointage = $rawTimestamp;
        }
        $matriculeBrut = is_array($rawData)
            ? ($rawData['matricule'] ?? $rawData['user_id'] ?? null)
            : null;

        $evenement['data'] = [
            ...$donnees,
            'employe_id' => $employeId,
            'employe_nom' => $employeNom !== '' ? $employeNom : null,
            'employe_matricule' => $employeMatricule !== ''
                ? $employeMatricule
                : (is_scalar($matriculeBrut) ? (string) $matriculeBrut : null),
            'badge_id' => (int) $pointage['badge_id'],
            'date_pointage' => $datePointage,
            'type_evenement' => (string) $pointage['type_evenement'],
            'statut_calcul' => (string) ($pointage['statut_calcul'] ?? 'UNKNOWN'),
            'capteur_id' => isset($pointage['capteur_id']) ? (int) $pointage['capteur_id'] : null,
        ];

        return $evenement;
    }

    private function decoderRawData(mixed $value, int $pointageId): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            log_message('warning', 'raw_data JSON invalide pour le pointage #{id} lors de l’enrichissement SSE.', [
                'id' => $pointageId,
            ]);
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Publie un événement de fin de recalcul.
     *
     * @return string Identifiant Redis Stream de l'événement.
     */
    public function publierRecalcul(array $resultat): string
    {
        $tenantId = (int) ($resultat['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide pour la publication du recalcul.');
        }

        return $this->ajouterAuFlux('ams:tenant:' . $tenantId . ':recalcul', [
            'type' => 'recalcul_termine',
            'data' => $resultat,
            'timestamp' => date(DATE_ATOM),
        ]);
    }

    /**
     * Lit les prochains événements après le curseur fourni.
     *
     * @return list<array{id:string,payload:array<string,mixed>}>
     */
    public function lirePointagesDepuis(int $tenantId, string $curseur, int $blocageMs = 1000): array
    {
        if ($tenantId < 1 || ! preg_match('/^(?:\\$|\\d+-\\d+)$/', $curseur)) {
            throw new \InvalidArgumentException('Tenant ou curseur Redis invalide.');
        }

        $reponse = $this->redis->executeRaw([
            'XREAD',
            'BLOCK',
            (string) min(5000, max(1000, $blocageMs)),
            'COUNT',
            '50',
            'STREAMS',
            $this->fluxPointages($tenantId),
            $curseur,
        ]);
        if (! is_array($reponse)) {
            return [];
        }

        $resultats = [];
        foreach ($reponse as $flux) {
            foreach (($flux[1] ?? []) as $entree) {
                $champs = $entree[1] ?? [];
                $donneesJson = null;
                for ($index = 0; $index < count($champs); $index += 2) {
                    if (($champs[$index] ?? null) === 'data') {
                        $donneesJson = $champs[$index + 1] ?? null;
                        break;
                    }
                }
                if (! is_string($donneesJson)) {
                    throw new RuntimeException('Événement Redis incomplet dans le flux des pointages.');
                }

                try {
                    $payload = json_decode($donneesJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $exception) {
                    throw new RuntimeException('Événement Redis invalide dans le flux des pointages.', 0, $exception);
                }
                if (! is_array($payload)) {
                    throw new RuntimeException('Format d’événement Redis invalide.');
                }

                $resultats[] = ['id' => (string) $entree[0], 'payload' => $payload];
            }
        }

        return $resultats;
    }

    private function ajouterAuFlux(string $flux, array $event): string
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $identifiant = $this->redis->executeRaw([
            'XADD',
            $flux,
            'MAXLEN',
            '~',
            '2000',
            '*',
            'data',
            $json,
        ]);
        if (! is_string($identifiant) || $identifiant === '') {
            throw new RuntimeException('Redis n’a pas confirmé l’ajout de l’événement temps réel.');
        }

        log_message('info', '[Redis] XADD stream={stream} id={id}', [
            'stream' => $flux,
            'id' => $identifiant,
        ]);

        return $identifiant;
    }

    private function fluxPointages(int $tenantId): string
    {
        return 'ams:tenant:' . $tenantId . ':pointages';
    }
}
