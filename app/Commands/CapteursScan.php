<?php

namespace App\Commands;

use App\Models\CapteurModel;
use App\Services\SystemJobService;
use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Lance un scan ponctuel ou périodique des pointages d’un ou plusieurs capteurs.
 */
class CapteursScan extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:scan';
    protected $description = 'Scanne les nouveaux pointages d’un ou de tous les capteurs.';
    protected $usage = 'capteurs:scan {capteur_id} [--all] [--interval=60]';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur pour un scan ponctuel.',
    ];
    protected $options = [
        '--all' => 'Scanne tous les capteurs actifs.',
        '--interval' => 'Répète les scans en continu, intervalle en secondes (défaut : 60).',
    ];

    public function run(array $params): int
    {
        $allSensors = $this->hasOption('all');
        $intervalRequested = $this->hasOption('interval');
        $rawInterval = $this->getOptionValue('interval');
        $interval = null;

        if ($intervalRequested) {
            $interval = $rawInterval === null ? 60 : filter_var($rawInterval, FILTER_VALIDATE_INT);
            if ($interval === false || $interval < 1 || $interval > 86400) {
                CLI::error('Option --interval invalide. La valeur doit être comprise entre 1 et 86400 secondes.');

                return EXIT_ERROR;
            }
        }

        $capteurId = $params[0] ?? null;
        if ($allSensors && $capteurId !== null) {
            CLI::error('Ne fournissez pas capteur_id avec --all.');

            return EXIT_ERROR;
        }
        if (! $allSensors && ($capteurId === null
            || filter_var($capteurId, FILTER_VALIDATE_INT) === false
            || (int) $capteurId < 1
        )) {
            CLI::error('Identifiant invalide. Usage : php spark capteurs:scan {capteur_id} ou php spark capteurs:scan --all');

            return EXIT_ERROR;
        }

        $capteurIds = $allSensors
            ? array_map('intval', array_column(
                (new CapteurModel())->where('statut', 'ACTIF')->findAll(),
                'id'
            ))
            : [(int) $capteurId];

        if ($capteurIds === []) {
            CLI::error('Aucun capteur actif à scanner.');

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write($interval === null
            ? 'Scan ponctuel des pointages par checkpoint.'
            : sprintf('Surveillance continue : scan toutes les %d secondes. Arrêt avec Ctrl+C.', $interval), 'green');

        $jobs = new SystemJobService();
        $runId = $jobs->start($this->name, $allSensors ? null : (int) $capteurId);
        do {
            $cycleStartedAt = date('Y-m-d H:i:s');
            CLI::write(sprintf('Cycle de scan : %s', $cycleStartedAt), 'blue');
            $cycleErrors = 0;
            $cycleSummary = ['capteurs' => [], 'erreurs' => []];

            foreach ($capteurIds as $currentSensorId) {
                try {
                    $result = (new ZKTecoService())->syncPointages(
                        $currentSensorId,
                        pollIntervalSeconds: $interval ?? 60
                    );
                    $stats = $result['stats'];
                    $cycleSummary['capteurs'][$currentSensorId] = [
                        'status' => $result['status'],
                        'stats' => $stats,
                    ];
                    if ($result['status'] !== 'SUCCESS') {
                        $cycleErrors++;
                        $cycleSummary['erreurs'][$currentSensorId] = 'Le capteur a retourné le statut ' . $result['status'] . '.';
                    }
                    CLI::write(sprintf(
                        'Capteur #%d tenant #%d : lus=%d, insérés=%d, doublons=%d, invalides=%d, checkpoint=%s',
                        $currentSensorId,
                        $result['tenant_id'],
                        $stats['lus'],
                        $stats['inseres'],
                        $stats['doublons'],
                        $stats['invalides'],
                        $stats['dernier_pointage_sync'] ?? 'aucun'
                    ));
                } catch (Throwable $e) {
                    $cycleErrors++;
                    $cycleSummary['erreurs'][$currentSensorId] = $e->getMessage();
                    CLI::error(sprintf('Capteur #%d : %s', $currentSensorId, $e->getMessage()));
                }
            }

            if ($interval === null) {
                $jobs->finish(
                    $runId,
                    $cycleErrors === 0 ? 'SUCCESS' : 'ERROR',
                    $cycleSummary,
                    $cycleErrors === 0 ? null : sprintf('%d capteur(s) en erreur.', $cycleErrors)
                );

                return $cycleErrors === 0 ? EXIT_SUCCESS : EXIT_ERROR;
            }

            $jobs->heartbeat(
                $runId,
                $cycleSummary,
                $cycleErrors === 0 ? null : sprintf('%d capteur(s) en erreur lors du dernier cycle.', $cycleErrors)
            );
            CLI::write(sprintf('Cycle terminé; prochain scan dans %d secondes.', $interval));
            sleep($interval);
        } while (true);
    }

    private function hasOption(string $name): bool
    {
        if (CLI::getOption($name) !== null) {
            return true;
        }

        foreach (CLI::getOptions() as $option => $value) {
            if ($option === $name || str_starts_with($option, $name . '=')) {
                return true;
            }
        }

        return false;
    }

    private function getOptionValue(string $name): ?string
    {
        $value = CLI::getOption($name);
        if (is_string($value)) {
            return $value;
        }

        foreach (CLI::getOptions() as $option => $optionValue) {
            if (preg_match('/^' . preg_quote($name, '/') . '=(.*)$/', $option, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}