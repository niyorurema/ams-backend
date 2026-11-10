<?php

namespace App\Services;

/**
 * Résout l’employé d’un pointage en privilégiant son matricule capteur.
 */
class PointageEmployeResolver
{
    /**
     * @param array<string, array<string, mixed>> $employesParMatricule
     * @param array<string, array<string, mixed>> $employesParBadge
     * @return array<string, mixed>|null
     */
    public function resoudre(
        mixed $matricule,
        mixed $badgeId,
        array $employesParMatricule,
        array $employesParBadge
    ): ?array {
        $matriculeNormalise = $this->normaliser($matricule);
        if ($matriculeNormalise !== null && isset($employesParMatricule[$matriculeNormalise])) {
            return $employesParMatricule[$matriculeNormalise];
        }

        $badgeNormalise = $this->normaliser($badgeId);

        return $badgeNormalise !== null ? ($employesParBadge[$badgeNormalise] ?? null) : null;
    }

    /**
     * Résout un employé uniquement si la correspondance est unique dans le tenant.
     *
     * @param array<int, array<string, mixed>> $employes
     * @return array<string, mixed>|null
     */
    public function resoudreUnique(mixed $matricule, mixed $badgeId, int $tenantId, array $employes): ?array
    {
        $matriculeNormalise = $this->normaliser($matricule);
        $candidatsMatricule = [];
        if ($matriculeNormalise !== null) {
            foreach ($employes as $employe) {
                if ((int) ($employe['tenant_id'] ?? 0) !== $tenantId) {
                    continue;
                }
                if ($matriculeNormalise === $this->normaliser($employe['matricule'] ?? null)
                    || $matriculeNormalise === $this->normaliser($employe['user_id_capteur'] ?? null)
                ) {
                    $candidatsMatricule[(int) $employe['id']] = $employe;
                }
            }
        }

        if ($candidatsMatricule !== []) {
            return count($candidatsMatricule) === 1 ? reset($candidatsMatricule) : null;
        }

        $badgeNormalise = $this->normaliser($badgeId);
        $candidatsBadge = [];
        if ($badgeNormalise !== null) {
            foreach ($employes as $employe) {
                if ((int) ($employe['tenant_id'] ?? 0) === $tenantId
                    && $badgeNormalise === $this->normaliser($employe['badge_id'] ?? null)
                ) {
                    $candidatsBadge[(int) $employe['id']] = $employe;
                }
            }
        }

        return count($candidatsBadge) === 1 ? reset($candidatsBadge) : null;
    }

    private function normaliser(mixed $identifiant): ?string
    {
        if (! is_string($identifiant) && ! is_int($identifiant)) {
            return null;
        }

        $identifiant = trim((string) $identifiant);

        return $identifiant === '' ? null : $identifiant;
    }
}
