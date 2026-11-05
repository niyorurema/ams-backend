<?php

namespace App\Commands;

use App\Models\CapteurModel;
use App\Services\SystemJobService;
use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Scan en continu tous les capteurs actifs.
 */
class CapteursScanAll extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:scan-all';
    protected $description = 'Scanne tous les capteurs actifs et synchronise les nouveaux pointages par checkpoint.';
    protected $usage = 'php spark capteurs:scan-all [--interval=5]';
    protected $options = [
        '--interval' => 'Intervalle de polling en secondes (défaut : 5).',
    ];

    public function run(array $params): int
    {
        $interval = 5;
        $rawInterval = CLI::getOption('interval');
        if ($rawInterval !== null) {
            $interval = filter_var($rawInterval, FILTER_VALIDATE_INT);
            if ($interval === false || $interval < 1 || $interval > 86400) {
                CLI::error('Option --interval invalide. La valeur doit être un entier entre 1 et 86400.');

                return EXIT_ERROR;
            }
        }

        $capteurs = (new CapteurModel())->where('statut', 'ACTIF')->findAll();
        if ($capteurs === []) {
            CLI::error('Aucun capteur actif enregistré.');

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write(sprintf('Surveillance continue activée : polling toutes les %d secondes.', $interval), 'green');

        $jobs = new SystemJobService();
        $runId = $jobs->start($this->name);
        while (true) {
            $cycleSummary = ['capteurs' => [], 'erreurs' => []];
            foreach ($capteurs as $capteur) {
                try {
                    $result = (new ZKTecoService())->syncPointages(
                        (int) $capteur['id'],
                        pollIntervalSeconds: $interval
                    );
                    $sensorStatus = $result['status'] ?? 'UNKNOWN';
                    $cycleSummary['capteurs'][$capteur['id']] = [
                        'status' => $sensorStatus,
                        'stats' => $result['stats'] ?? [],
                    ];
                    if ($sensorStatus !== 'SUCCESS') {
                        $cycleSummary['erreurs'][$capteur['id']] = 'Le capteur a retourné le statut ' . $sensorStatus . '.';
                    }
                    CLI::write(sprintf(
                        'Capteur #%d : %s | lus=%d | insérés=%d | doublons=%d',
                        $capteur['id'],
                        $result['status'] ?? 'UNKNOWN',
                        $result['stats']['lus'] ?? 0,
                        $result['stats']['inseres'] ?? 0,
                        $result['stats']['doublons'] ?? 0
                    ));
                } catch (Throwable $e) {
                    $cycleSummary['erreurs'][$capteur['id']] = $e->getMessage();
                    CLI::write(sprintf('Capteur #%d : erreur -> %s', $capteur['id'], $e->getMessage()), 'red');
                }
            }

            $jobs->heartbeat(
                $runId,
                $cycleSummary,
                $cycleSummary['erreurs'] === []
                    ? null
                    : sprintf('%d capteur(s) en erreur lors du dernier cycle.', count($cycleSummary['erreurs']))
            );
            sleep($interval);
        }
    }
}
