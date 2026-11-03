<?php

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

$paths = new Config\Paths();
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Boot.php';
CodeIgniter\Boot::bootSpark($paths);

use Config\Database;

$file = '/tmp/ams-matricules.txt';
$apply = in_array('--apply', $argv, true);
$confirmed = in_array('--yes', $argv, true);

if (! is_file($file)) {
    fwrite(STDERR, "Fichier introuvable : {$file}\n");
    exit(2);
}

$matricules = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $value = trim($line);
    if ($value !== '') {
        $matricules[] = $value;
    }
}
$matricules = array_values(array_unique($matricules));

if ($matricules === []) {
    fwrite(STDERR, "La liste des matricules est vide.\n");
    exit(2);
}

$db = Database::connect();
$db->transBegin();

$employees = $db->table('employes e')
    ->select('e.id, e.matricule, e.nom, e.prenom, e.tenant_id, e.actif, e.deleted_at')
    ->where('e.deleted_at', null)
    ->whereIn('e.matricule', $matricules)
    ->orderBy('e.matricule', 'ASC')
    ->get()
    ->getResultArray();

$missing = array_values(array_diff($matricules, array_map(static fn(array $row): string => trim((string) $row['matricule']), $employees)));
$activeEmployees = array_values(array_filter($employees, static fn(array $row): bool => (int) ($row['actif'] ?? 0) === 1));

$inactiveEmployees = array_values(array_filter($employees, static fn(array $row): bool => (int) ($row['actif'] ?? 0) !== 1));
$employeeIds = array_map(static fn(array $row): int => (int) $row['id'], $employees);
$activeUsers = [];
if ($employeeIds !== []) {
    $activeUsers = $db->table('users u')
        ->select('u.id, u.email, u.nom_complet, u.employe_id, u.is_active')
        ->join('employes e', 'e.id = u.employe_id AND e.tenant_id = u.tenant_id', 'inner')
        ->where('u.deleted_at', null)
        ->where('u.is_active', 1)
        ->whereIn('u.employe_id', $employeeIds)
        ->get()
        ->getResultArray();
}

echo "Matricules demandés : " . count($matricules) . PHP_EOL;
echo "Employés actifs trouvés : " . count($activeEmployees) . PHP_EOL;
echo "Employés déjà inactifs trouvés : " . count($inactiveEmployees) . PHP_EOL;
echo "Comptes actifs associés trouvés : " . count($activeUsers) . PHP_EOL;
echo "Matricules sans correspondance active : " . count($missing) . PHP_EOL;
if ($missing !== []) {
    echo "Introuvables : " . implode(', ', $missing) . PHP_EOL;
}

if (! $apply) {
    echo "APERÇU SEULEMENT — aucune modification effectuée. Utilisez --apply --yes après vérification.\n";
    $db->transRollback();
    exit(0);
}

if (! $confirmed) {
    echo "APPLICATION REFUSÉE — ajoutez explicitement --yes après l’aperçu.\n";
    $db->transRollback();
    exit(3);
}

if ($employeeIds !== []) {
    $db->table('employes')
        ->whereIn('id', $employeeIds)
        ->where('deleted_at', null)
        ->update(['actif' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

    $db->table('users')
        ->whereIn('employe_id', $employeeIds)
        ->where('deleted_at', null)
        ->where('is_active', 1)
        ->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
}

$db->transCommit();
echo "MODIFICATIONS VALIDÉES.\n";
