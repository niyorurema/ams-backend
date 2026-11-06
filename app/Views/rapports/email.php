<!DOCTYPE html>
<html lang="<?= esc($langue ?? 'fr') ?>">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #202927;">
    <h1><?= esc($rapport['titre'] ?? 'Rapport de présence') ?></h1>
    <p><?= esc($rapport['destinataire']['nom'] ?? '') ?></p>
    <table style="border-collapse: collapse; width: 100%;" cellpadding="8">
        <thead>
            <tr style="background: #dDEBE8;">
                <th align="left">Indicateur</th>
                <th align="right">Valeur</th>
            </tr>
        </thead>
        <tbody>
            <?php if (isset($rapport['periode'])): ?>
                <tr>
                    <td style="border-bottom: 1px solid #d5ddda;">Journées-employés</td>
                    <td align="right" style="border-bottom: 1px solid #d5ddda;"><?= esc((string) ($rapport['stats']['jours_employes'] ?? 0)) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ([
                'Total employés' => 'total_employes',
                'Présents' => 'presents',
                'Absents' => 'absents',
                'Jours fériés' => 'feries',
                'Congés confirmés' => 'conges',
                'Empêchements validés' => 'empechements',
                'Absences autorisées' => 'absences_autorisees',
                'Absences partielles' => 'absences_partielles',
                'Pointages pendant une absence' => 'conflits_absence_pointage',
                'Jours de repos' => 'repos',
                'Normaux' => 'normaux',
                'Retards' => 'retards',
                'Anormaux' => 'anormaux',
                'Heures totales' => 'heures_totales',
                'Taux de présence (%)' => 'taux_presence',
            ] as $libelle => $cle): ?>
                <tr>
                    <td style="border-bottom: 1px solid #d5ddda;"><?= esc($libelle) ?></td>
                    <td align="right" style="border-bottom: 1px solid #d5ddda;"><?= esc((string) ($rapport['stats'][$cle] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>Le rapport détaillé est joint à ce message.</p>
</body>
</html>
