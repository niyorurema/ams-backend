<?php

namespace App\Controllers\Api;

use App\Models\TenantModel;
use App\Services\AttendanceRuleVersionService;
use App\Services\ParametragePointageService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DOMDocument;
use DOMElement;
use DOMNode;
use DateTimeZone;
use Throwable;

/**
 * Consultation et personnalisation du tenant courant.
 */
class TenantConfigController extends BaseApiController
{
    private const POLICES_AUTORISEES = ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat'];
    private const TAILLES_AUTORISEES = ['small', 'normal', 'large', 'xlarge'];
    private const TAILLE_MAX_LOGO = 2 * 1024 * 1024;

    /** Retourne la configuration visuelle du tenant courant. */
    public function show(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $tenant = (new TenantModel())->find($tenantId);
        if ($tenant === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        return $this->successResponse($this->configurationPublique($tenant));
    }

    /** Met à jour les options du tenant; les RH ne peuvent modifier que les jours travaillés. */
    public function update(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        $input = $this->jsonInput();
        $admin = (bool) $claims->is_super_admin || (string) $claims->role === 'admin';
        $rhModifieUniquementLesJours = (string) $claims->role === 'rh'
            && count($input) === 1
            && array_key_exists('jours_travailles', $input);
        if (! $admin && ! $rhModifieUniquementLesJours) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        $model = new TenantModel();
        $tenant = $tenantId === null ? null : $model->find($tenantId);
        if ($tenant === null) {
            return $tenantId === null
                ? $this->authorizationError()
                : $this->errorResponse('Tenant introuvable.', 404);
        }

        $data = [];
        foreach (['name', 'slogan', 'primary_color', 'secondary_color', 'sidebar_color', 'topbar_color', 'font_family', 'font_size', 'logo_url', 'timezone'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }
        if (array_key_exists('jours_travailles', $input)) {
            $jours = $input['jours_travailles'];
            if (! is_array($jours) || $jours === [] || count($jours) > 7) {
                return $this->errorResponse('Les jours travaillés doivent contenir un ou plusieurs jours entre 1 et 7.', 422);
            }
            foreach ($jours as $jour) {
                if ((! is_int($jour) && ! (is_string($jour) && ctype_digit($jour)))
                    || (int) $jour < 1
                    || (int) $jour > 7
                ) {
                    return $this->errorResponse('Les jours travaillés doivent contenir uniquement des valeurs entre 1 et 7.', 422);
                }
            }
            $jours = array_values(array_unique(array_map('intval', $jours)));
            sort($jours);
            $data['jours_travailles'] = implode(',', $jours);
        }
        if ($data === []) {
            return $this->errorResponse('Aucun champ de configuration fourni.', 400);
        }
        if (! $this->configurationValide($data)) {
            return $this->errorResponse('Valeur de configuration invalide.', 422);
        }

        if (array_keys($input) === ['jours_travailles']) {
            $misAJour = Database::connect()->table('tenants')
                ->where('id', $tenantId)
                ->where('deleted_at', null)
                ->update([
                    'jours_travailles' => $data['jours_travailles'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if (! $misAJour) {
                log_message('error', 'Impossible de mettre à jour les jours travaillés du tenant #{id}.', [
                    'id' => $tenantId,
                ]);

                return $this->errorResponse('Impossible d’enregistrer les jours travaillés.', 500);
            }

            return $this->successResponse(
                $this->configurationPublique($model->find($tenantId) ?? []),
                'Jours travaillés enregistrés.'
            );
        }

        $data += [
            // Le validateur is_unique utilise cette valeur pour ignorer le tenant en cours.
            'id' => $tenantId,
            'name' => $tenant['name'],
            'slug' => $tenant['slug'],
            'timezone' => $tenant['timezone'],
            'jours_travailles' => $tenant['jours_travailles'] ?? '1,2,3,4,5',
            'is_active' => $tenant['is_active'],
            'contact_email' => $tenant['contact_email'],
            'logo_url' => $tenant['logo_url'],
            'notes' => $tenant['notes'],
        ];
        if (! $model->update($tenantId, $data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }
        if (array_key_exists('logo_url', $data) && $data['logo_url'] === null) {
            $this->supprimerAncienLogo($tenant['logo_url']);
        }

        return $this->successResponse($this->configurationPublique($model->find($tenantId) ?? []), 'Configuration enregistrée.');
    }

    /** Retourne les paramètres de pointage et leurs valeurs configurables pour le tenant courant. */
    public function pointageParametres(): ResponseInterface
    {
        if (! $this->canReadAttendanceSettings()) {
            return $this->claims() === null
                ? $this->errorResponse('Jeton invalide ou expiré.', 401)
                : $this->errorResponse('Droits de consultation requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $horaireId = $this->request->getGet('horaire_id');
        if ($horaireId !== null
            && (filter_var($horaireId, FILTER_VALIDATE_INT) === false || (int) $horaireId < 1)
        ) {
            return $this->errorResponse('Identifiant d’horaire invalide.', 400);
        }

        return $this->successResponse($this->configurationPointage(
            $tenantId,
            $horaireId === null ? null : (int) $horaireId
        ));
    }

    /** Met à jour un ensemble de paramètres de pointage déjà déclarés par le service. */
    public function mettreAJourPointageParametres(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits de gestion requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $champsAutorises = ['parametres', 'creneaux', 'actif_regles_nouvelles', 'horaire_id', 'date_effet'];
        if ($input === [] || array_diff(array_keys($input), $champsAutorises) !== []) {
            return $this->errorResponse('Configuration de pointage invalide ou vide.', 400);
        }
        $parametres = $input['parametres'] ?? [];
        $creneaux = $input['creneaux'] ?? null;
        $modifierOptIn = array_key_exists('actif_regles_nouvelles', $input);
        if (! is_array($parametres) || ($parametres === [] && ! $modifierOptIn && ! array_key_exists('creneaux', $input))) {
            return $this->errorResponse('Les paramètres doivent être un objet non vide.', 400);
        }
        if (array_key_exists('creneaux', $input) && ! is_array($creneaux)) {
            return $this->errorResponse('Les créneaux doivent être transmis sous forme de liste.', 422);
        }
        if ($modifierOptIn && ! in_array($input['actif_regles_nouvelles'], [0, 1, '0', '1', false, true], true)) {
            return $this->errorResponse('La valeur d’activation doit être booléenne.', 422);
        }

        if (array_key_exists('date_effet', $input) || array_key_exists('horaire_id', $input)) {
            $horaireId = $input['horaire_id'] ?? null;
            if ($horaireId !== null && (filter_var($horaireId, FILTER_VALIDATE_INT) === false || (int) $horaireId < 1)) {
                return $this->errorResponse('Identifiant d’horaire invalide.', 422);
            }
            $dateEffet = $input['date_effet'] ?? date('Y-m-d');
            if (! is_string($dateEffet) || ! $this->datePointageValide($dateEffet)) {
                return $this->errorResponse('Date d’effet invalide.', 422);
            }
            if ($parametres === [] || ! is_array($creneaux)) {
                return $this->errorResponse('Les paramètres et les créneaux doivent être transmis ensemble.', 422);
            }
            foreach ($creneaux as $creneau) {
                if (! is_array($creneau)) {
                    return $this->errorResponse('Chaque créneau doit être un objet.', 422);
                }
            }
            try {
                $configurationActuelle = $this->configurationPointage(
                    $tenantId,
                    $horaireId === null ? null : (int) $horaireId
                );
                (new AttendanceRuleVersionService(Database::connect()))->enregistrer(
                    $tenantId,
                    $horaireId === null ? null : (int) $horaireId,
                    $dateEffet,
                    $modifierOptIn
                        ? in_array($input['actif_regles_nouvelles'], [1, '1', true], true)
                        : (bool) $configurationActuelle['actif_regles_nouvelles'],
                    $parametres,
                    $creneaux
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
                return $this->errorResponse($exception->getMessage(), 422);
            } catch (Throwable $exception) {
                log_message('error', 'Échec de création d’une version des règles de pointage: {message}', [
                    'message' => $exception->getMessage(),
                ]);

                return $this->errorResponse('Impossible de créer la version des règles de pointage.', 500);
            }

            return $this->successResponse($this->configurationPointage(
                $tenantId,
                $horaireId === null ? null : (int) $horaireId
            ), 'Version des règles de pointage enregistrée.');
        }

        $db = Database::connect();
        $service = new ParametragePointageService($db);
        $configuration = $service->getConfiguration($tenantId);
        foreach ($parametres as $cle => $valeur) {
            if (! is_string($cle) || ! array_key_exists($cle, $configuration)) {
                return $this->errorResponse('Clé de paramètre inconnue.', 422);
            }
            if (is_array($valeur) || is_object($valeur) || $valeur === null) {
                return $this->errorResponse('Les valeurs de paramètre doivent être scalaires.', 422);
            }
        }
        $valeursParametres = array_map(
            static fn (array $parametre): mixed => $parametre['valeur'],
            $configuration
        );
        foreach ($parametres as $cle => $valeur) {
            $valeursParametres[$cle] = $valeur;
        }
        if ((int) $valeursParametres['duree_pause_minimum_minutes']
            > (int) $valeursParametres['duree_pause_maximum_minutes']
        ) {
            return $this->errorResponse('La durée minimale de pause ne peut pas dépasser sa durée maximale.', 422);
        }
        if (array_key_exists('creneaux', $input)) {
            foreach ($creneaux as $creneau) {
                if (! is_array($creneau)) {
                    return $this->errorResponse('Chaque créneau doit être un objet.', 422);
                }
            }
        }
        $tenantEtat = $db->table('tenants')
            ->select('actif_regles_nouvelles')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        $reglesFinalementActives = $modifierOptIn
            ? in_array($input['actif_regles_nouvelles'], [1, '1', true], true)
            : (int) ($tenantEtat['actif_regles_nouvelles'] ?? 0) === 1;
        if ($reglesFinalementActives) {
            $creneauxFinaux = array_key_exists('creneaux', $input) ? $creneaux : $service->getCreneaux($tenantId);
            try {
                $configurationFinale = (new AttendanceRuleVersionService($db))->preparer(
                    $tenantId,
                    null,
                    true,
                    array_diff_key($valeursParametres, ['marge_pointage_futur_minutes' => true]),
                    $creneauxFinaux
                );
                if (! $this->creneauxPauseComplets($configurationFinale['creneaux'])
                    && $configurationFinale['pointages_pause_observes']
                ) {
                    return $this->errorResponse(
                        'Des pointages de pause ont été observés récemment. Configurez les deux créneaux de pause avant l’activation.',
                        422
                    );
                }
            } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
                return $this->errorResponse($exception->getMessage(), 422);
            }
        }

        $db->transBegin();
        try {
            foreach ($parametres as $cle => $valeur) {
                $service->set($tenantId, $cle, $valeur, $configuration[$cle]['type']);
            }
            if (array_key_exists('creneaux', $input)) {
                $service->remplacerCreneaux($tenantId, $creneaux);
            }
            if ($modifierOptIn) {
                $db->table('tenants')
                    ->where('id', $tenantId)
                    ->where('deleted_at', null)
                    ->update(['actif_regles_nouvelles' => (int) $input['actif_regles_nouvelles']]);
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('La transaction de configuration de pointage a échoué.');
            }
            $db->transCommit();
        } catch (\InvalidArgumentException|\UnexpectedValueException $exception) {
            $db->transRollback();

            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Échec de mise à jour des paramètres de pointage: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Impossible d’enregistrer la configuration de pointage.', 500);
        }

        return $this->successResponse($this->configurationPointage($tenantId), 'Configuration de pointage enregistrée.');
    }

    /** @return array<string, mixed> */
    private function configurationPointage(int $tenantId, ?int $horaireId = null): array
    {
        $db = Database::connect();
        $tenant = $db->table('tenants')
            ->select('actif_regles_nouvelles')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            throw new \RuntimeException('Tenant introuvable lors du chargement des paramètres de pointage.');
        }

        $service = new ParametragePointageService($db);
        $versions = new AttendanceRuleVersionService($db);
        $version = $versions->getApplicable($tenantId, date('Y-m-d'), $horaireId);
        $parametres = $service->getConfiguration($tenantId);
        if ($version !== null) {
            foreach ($version['parametres'] as $cle => $valeur) {
                if (isset($parametres[$cle])) {
                    $parametres[$cle]['valeur'] = $valeur;
                }
            }
        }

        return [
            'actif_regles_nouvelles' => $version['actif'] ?? (bool) $tenant['actif_regles_nouvelles'],
            'parametres' => $parametres,
            'creneaux' => $version['creneaux'] ?? $service->getCreneaux($tenantId),
            'horaires' => $versions->getHoraires($tenantId),
            'horaire_id' => $horaireId,
            'date_effet' => $version['date_effet'] ?? date('Y-m-d'),
            'versions' => $versions->getHistorique($tenantId, $horaireId),
        ];
    }

    private function datePointageValide(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @param array<int, array<string, mixed>> $creneaux */
    private function creneauxPauseComplets(array $creneaux): bool
    {
        $types = array_column($creneaux, 'type_creneau');

        return in_array('DEBUT_PAUSE', $types, true) && in_array('FIN_PAUSE', $types, true);
    }

    /** Téléverse et valide le logo du tenant courant. */
    public function uploadLogo(): ResponseInterface
    {
        if (! $this->estAdministrateur()) {
            return $this->claims() === null
                ? $this->errorResponse('Jeton invalide ou expiré.', 401)
                : $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        $model = new TenantModel();
        $tenant = $tenantId === null ? null : $model->find($tenantId);
        if ($tenant === null) {
            return $tenantId === null
                ? $this->authorizationError()
                : $this->errorResponse('Tenant introuvable.', 404);
        }

        $fichier = $this->request->getFile('logo');
        if ($fichier === null || ! $fichier->isValid()) {
            return $this->errorResponse('Fichier logo manquant ou invalide.', 400);
        }
        if ($fichier->getSize() > self::TAILLE_MAX_LOGO) {
            return $this->errorResponse('Le logo ne doit pas dépasser 2 Mo.', 413);
        }

        $extension = strtolower($fichier->getClientExtension());
        $mime = strtolower((string) $fichier->getMimeType());
        $extensionsMime = [
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'svg' => ['image/svg+xml', 'text/xml', 'application/xml'],
        ];
        if (! isset($extensionsMime[$extension]) || ! in_array($mime, $extensionsMime[$extension], true)) {
            return $this->errorResponse('Format de logo invalide (PNG, JPG ou SVG requis).', 415);
        }

        $repertoire = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tenant-logos';
        if (! is_dir($repertoire) && ! mkdir($repertoire, 0750, true) && ! is_dir($repertoire)) {
            log_message('error', 'Impossible de créer le répertoire des logos de tenant.');
            return $this->errorResponse('Impossible d’enregistrer le logo.', 500);
        }

        $nomFichier = 'tenant_' . $tenantId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
        $chemin = $repertoire . DIRECTORY_SEPARATOR . $nomFichier;

        try {
            if ($extension === 'svg') {
                $svgSecurise = $this->nettoyerSvg((string) file_get_contents($fichier->getTempName()));
                if (file_put_contents($chemin, $svgSecurise, LOCK_EX) === false) {
                    throw new \RuntimeException('Impossible d’écrire le logo SVG.');
                }
                chmod($chemin, 0640);
            } elseif (! $fichier->move($repertoire, $nomFichier)) {
                throw new \RuntimeException('Impossible de déplacer le fichier logo.');
            }

            $ancienLogo = $tenant['logo_url'];
            if (! $model->update($tenantId, ['logo_url' => 'uploads/tenant-logos/' . $nomFichier])) {
                throw new \RuntimeException(implode(' ', $model->errors()));
            }

            $this->supprimerAncienLogo($ancienLogo);

            return $this->successResponse(
                $this->configurationPublique($model->find($tenantId) ?? []),
                'Logo mis à jour.'
            );
        } catch (\InvalidArgumentException $e) {
            if (is_file($chemin)) {
                unlink($chemin);
            }
            return $this->errorResponse('Le fichier SVG est invalide ou contient du contenu non autorisé.', 415);
        } catch (Throwable $e) {
            if (is_file($chemin)) {
                unlink($chemin);
            }
            log_message('error', 'Téléversement du logo impossible : {error}', ['error' => $e->getMessage()]);

            return $this->errorResponse('Impossible d’enregistrer le logo.', 500);
        }
    }

    private function estAdministrateur(): bool
    {
        $claims = $this->claims();

        return $claims !== null
            && ((bool) $claims->is_super_admin || (string) $claims->role === 'admin');
    }

    /** @param array<string, mixed> $donnees */
    private function configurationValide(array $donnees): bool
    {
        if (array_key_exists('logo_url', $donnees) && $donnees['logo_url'] !== null) {
            return false;
        }
        if (isset($donnees['name']) && (! is_string($donnees['name']) || trim($donnees['name']) === '' || mb_strlen($donnees['name']) > 150)) {
            return false;
        }
        if (array_key_exists('slogan', $donnees) && $donnees['slogan'] !== null
            && (! is_string($donnees['slogan']) || mb_strlen($donnees['slogan']) > 250)) {
            return false;
        }
        foreach (['primary_color', 'secondary_color', 'sidebar_color', 'topbar_color'] as $couleur) {
            if (isset($donnees[$couleur]) && (! is_string($donnees[$couleur]) || preg_match('/^#[0-9a-fA-F]{6}$/', $donnees[$couleur]) !== 1)) {
                return false;
            }
        }
        if (isset($donnees['timezone'])
            && (! is_string($donnees['timezone'])
                || ! in_array($donnees['timezone'], DateTimeZone::listIdentifiers(), true))
        ) {
            return false;
        }
        if (isset($donnees['font_family']) && ! in_array($donnees['font_family'], self::POLICES_AUTORISEES, true)) {
            return false;
        }
        if (isset($donnees['font_size']) && ! in_array($donnees['font_size'], self::TAILLES_AUTORISEES, true)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $tenant
     *  @return array<string, mixed>
     */
    private function configurationPublique(array $tenant): array
    {
        return [
            'id' => (int) $tenant['id'],
            'name' => $tenant['name'],
            'slug' => $tenant['slug'],
            'logo_url' => $tenant['logo_url'] ?: null,
            'slogan' => $tenant['slogan'] ?: '',
            'primary_color' => $tenant['primary_color'] ?: '#1f2937',
            'secondary_color' => $tenant['secondary_color'] ?: '#f3f4f6',
            'sidebar_color' => $tenant['sidebar_color'] ?: '#ffffff',
            'topbar_color' => $tenant['topbar_color'] ?: '#ffffff',
            'font_family' => $tenant['font_family'] ?: 'Inter',
            'font_size' => $tenant['font_size'] ?: 'normal',
            'timezone' => $tenant['timezone'] ?: 'Africa/Bujumbura',
            'jours_travailles' => \App\Services\JourTravailleService::normaliserJours($tenant['jours_travailles'] ?? null),
        ];
    }

    private function supprimerAncienLogo(?string $logoUrl): void
    {
        if ($logoUrl === null || ! str_starts_with($logoUrl, 'uploads/tenant-logos/')) {
            return;
        }

        $nom = basename($logoUrl);
        if ($nom !== '' && $nom !== '.' && $nom !== '..') {
            $chemin = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tenant-logos' . DIRECTORY_SEPARATOR . $nom;
            if (is_file($chemin)) {
                unlink($chemin);
            }
        }
    }

    private function nettoyerSvg(string $contenu): string
    {
        if (! class_exists(DOMDocument::class)) {
            throw new \RuntimeException('L’extension PHP DOM est nécessaire pour valider les logos SVG.');
        }

        $dom = new DOMDocument();
        $erreursPrecedentes = libxml_use_internal_errors(true);
        try {
            $charge = $dom->loadXML($contenu, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            if (! $charge || ! $dom->documentElement instanceof DOMElement
                || strtolower($dom->documentElement->localName) !== 'svg') {
                throw new \InvalidArgumentException('Le document SVG est invalide.');
            }
            $this->nettoyerNoeudSvg($dom->documentElement);
            $svg = $dom->saveXML($dom->documentElement);
            if ($svg === false) {
                throw new \RuntimeException('Impossible de sérialiser le logo SVG.');
            }

            return $svg;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($erreursPrecedentes);
        }
    }

    private function nettoyerNoeudSvg(DOMNode $noeud): void
    {
        $elementsAutorises = [
            'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
            'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'title', 'desc',
        ];
        $attributsAutorises = [
            'xmlns', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2',
            'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule',
            'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'stroke-opacity', 'opacity', 'transform', 'id', 'class', 'offset', 'stop-color',
            'stop-opacity', 'clip-path', 'mask', 'preserveAspectRatio', 'xmlns:xlink',
        ];

        if (! $noeud instanceof DOMElement) {
            return;
        }
        if (! in_array($noeud->localName, $elementsAutorises, true)) {
            $noeud->parentNode?->removeChild($noeud);
            return;
        }

        for ($index = $noeud->attributes->length - 1; $index >= 0; $index--) {
            $attribut = $noeud->attributes->item($index);
            if ($attribut === null) {
                continue;
            }
            $nom = $attribut->nodeName;
            $valeur = trim($attribut->nodeValue ?? '');
            $referenceLocale = preg_match('/^url\(\s*#[A-Za-z0-9_.:-]+\s*\)$/', $valeur) === 1;
            if (! in_array($nom, $attributsAutorises, true)
                || preg_match('/(?:javascript|data|https?:|\/\/)/i', $valeur) === 1
                || (str_contains(strtolower($nom), 'href') && ! str_starts_with($valeur, '#'))
                || (str_contains($valeur, 'url(') && ! $referenceLocale)) {
                $noeud->removeAttributeNode($attribut);
            }
        }

        for ($enfant = $noeud->firstChild; $enfant !== null;) {
            $suivant = $enfant->nextSibling;
            if ($enfant instanceof DOMElement) {
                $this->nettoyerNoeudSvg($enfant);
            } elseif (! in_array($enfant->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                $noeud->removeChild($enfant);
            }
            $enfant = $suivant;
        }
    }
}
