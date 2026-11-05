<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

final class CapteurPresenceService
{
    public const SOURCE_ADMS = 'ADMS';
    public const SOURCE_SERVER_POLL = 'SERVER_POLL';
    public const DEFAULT_POLL_INTERVAL_SECONDS = 60;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(int $capteurId, string $source, int $intervalSeconds): void
    {
        if (! in_array($source, [self::SOURCE_ADMS, self::SOURCE_SERVER_POLL], true)) {
            throw new \InvalidArgumentException('Source de communication du capteur invalide.');
        }
        if ($intervalSeconds < 1 || $intervalSeconds > 86400) {
            throw new \InvalidArgumentException('La fréquence de communication doit être comprise entre 1 et 86400 secondes.');
        }
        $frequencyField = $source === self::SOURCE_ADMS
            ? 'frequence_adms_secondes'
            : 'frequence_polling_secondes';
        if (! $this->db->table('capteurs')->where('id', $capteurId)->update([
            'derniere_communication' => date('Y-m-d H:i:s'),
            'source_derniere_communication' => $source,
            $frequencyField => $intervalSeconds,
        ])) {
            throw new RuntimeException('Impossible d’enregistrer la dernière communication du capteur.');
        }
    }

    public function configurePollingInterval(int $capteurId, int $intervalSeconds): void
    {
        if ($intervalSeconds < 1 || $intervalSeconds > 86400) {
            throw new \InvalidArgumentException('La fréquence du scanner doit être comprise entre 1 et 86400 secondes.');
        }
        $current = $this->db->table('capteurs')
            ->select('frequence_polling_secondes')
            ->where('id', $capteurId)
            ->get()
            ->getRowArray();
        if ($current === null) {
            throw new RuntimeException('Capteur introuvable lors de la configuration du scanner.');
        }
        if ((int) ($current['frequence_polling_secondes'] ?? 0) === $intervalSeconds) {
            return;
        }
        if (! $this->db->table('capteurs')->where('id', $capteurId)->update(['frequence_polling_secondes' => $intervalSeconds])) {
            throw new RuntimeException('Impossible d’enregistrer la fréquence du scanner du capteur.');
        }
    }

    /** @param array<string, mixed> $capteur */
    public static function status(array $capteur, ?DateTimeImmutable $now = null): string
    {
        if (($capteur['statut'] ?? null) !== 'ACTIF') {
            return 'INACTIVE';
        }

        $lastCommunication = $capteur['derniere_communication'] ?? null;
        $frequencyField = match ($capteur['source_derniere_communication'] ?? null) {
            self::SOURCE_ADMS => 'frequence_adms_secondes',
            self::SOURCE_SERVER_POLL => 'frequence_polling_secondes',
            default => null,
        };
        $interval = $frequencyField === null ? 0 : (int) ($capteur[$frequencyField] ?? 0);
        if (! is_string($lastCommunication) || $lastCommunication === '' || $interval < 1) {
            return 'UNKNOWN';
        }

        $lastTimestamp = strtotime($lastCommunication);
        if ($lastTimestamp === false) {
            return 'UNKNOWN';
        }

        $now ??= new DateTimeImmutable();

        return $now->getTimestamp() - $lastTimestamp <= $interval * 3 ? 'ONLINE' : 'OFFLINE';
    }
}
