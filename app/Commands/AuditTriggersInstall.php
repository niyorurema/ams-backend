<?php

namespace App\Commands;

use App\Services\AuditTriggerManager;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class AuditTriggersInstall extends BaseCommand
{
    protected $group = 'Database';
    protected $name = 'audit:install-triggers';
    protected $description = 'Installe ou actualise les déclencheurs d’audit des tables AMS.';

    public function run(array $params): int
    {
        try {
            $count = (new AuditTriggerManager())->install();
            CLI::write(sprintf('Déclencheurs d’audit installés : %d.', $count));

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            log_message('error', 'Installation des déclencheurs d’audit impossible : {error}', [
                'error' => $exception->getMessage(),
            ]);
            CLI::error('Impossible d’installer les déclencheurs d’audit : ' . $exception->getMessage());

            return EXIT_ERROR;
        }
    }
}
