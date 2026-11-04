<?php

namespace App\Controllers\Api;

use App\Models\EmployeModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Gestion tenant-scoped des utilisateurs et du profil connecté.
 */
class UsersController extends BaseApiController
{
    private const ROLES = [
        'admin',
        'rh',
        'superviseur',
        'chef_service',
        'directeur',
        'commissaire',
        'lecteur',
    ];

    public function index(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $model = new UserModel();
        $model->where('tenant_id', $tenantId);

        $search = trim((string) ($this->request->getGet('search') ?? ''));
        if ($search !== '') {
            $employeeIds = array_column(
                Database::connect()->table('employes')
                    ->select('id')
                    ->where('tenant_id', $tenantId)
                    ->where('actif', 1)
                    ->where('deleted_at', null)
                    ->groupStart()
                        ->like('nom', $search)
                        ->orLike('prenom', $search)
                        ->orLike('matricule', $search)
                    ->groupEnd()
                    ->get()
                    ->getResultArray(),
                'id'
            );

            $model->groupStart()
                ->like('email', $search)
                ->orLike('nom_complet', $search);
            if ($employeeIds !== []) {
                $model->orWhereIn('employe_id', $employeeIds);
            }
            $model->groupEnd();
        }

        $withoutEmail = $this->request->getGet('sans_email');
        if ($withoutEmail !== null && $withoutEmail !== '') {
            if ((string) $withoutEmail !== '1') {
                return $this->errorResponse('Le filtre sans adresse e-mail doit valoir 1.', 422);
            }
            $model->groupStart()
                ->where('email', null)
                ->orWhere("TRIM(email) = ''", null, false)
                ->groupEnd();
        }

        $role = trim((string) ($this->request->getGet('role') ?? ''));
        if ($role !== '') {
            if (! in_array($role, self::ROLES, true)) {
                return $this->errorResponse('Le rôle demandé est invalide.', 422);
            }
            $model->where('role', $role);
        }

        $active = $this->request->getGet('actif');
        if ($active !== null && $active !== '') {
            if (! in_array((string) $active, ['0', '1'], true)) {
                return $this->errorResponse('Le filtre actif doit valoir 0 ou 1.', 422);
            }
            $model->join('employes e', 'e.id = users.employe_id AND e.tenant_id = users.tenant_id', 'left');
            if ((string) $active === '1') {
                $model->where('users.is_active', 1)
                    ->groupStart()
                        ->where('e.id', null)
                        ->orGroupStart()
                            ->where('e.actif', 1)
                            ->where('e.deleted_at', null)
                        ->groupEnd()
                    ->groupEnd();
            } else {
                $model->groupStart()
                    ->where('users.is_active', 0)
                    ->orGroupStart()
                        ->where('e.id IS NOT NULL', null, false)
                        ->groupStart()
                            ->where('e.actif', 0)
                            ->orWhere('e.deleted_at IS NOT NULL', null, false)
                        ->groupEnd()
                    ->groupEnd();
            }
        }

        $limit = $this->boundedInteger($this->request->getGet('limit'), 10, 1, 100);
        $offset = $this->boundedInteger($this->request->getGet('offset'), 0, 0, 1000000);
        $total = $model->countAllResults(false);
        $items = $model->orderBy('created_at', 'DESC')->findAll($limit, $offset);

        return $this->successResponse([
            'items' => $this->enrichUsers($items, $tenantId),
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $user = (new UserModel())->where('tenant_id', $tenantId)->find($id);
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }

        return $this->successResponse($this->enrichUsers([$user], $tenantId, true)[0]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $input = $this->jsonInput();
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        $validation = $this->validateUserInput($input, true);
        if ($validation !== null) {
            return $validation;
        }

        $email = strtolower(trim((string) $input['email']));
        if ($this->emailExists($tenantId, $email)) {
            return $this->errorResponse('Cet email existe déjà dans ce tenant.', 409);
        }
        if (! $this->roleMayBeAssigned((string) $input['role'])) {
            return $this->errorResponse('Vous ne pouvez pas attribuer ce rôle.', 403);
        }
        $isSuperAdmin = isset($input['is_super_admin']) && filter_var($input['is_super_admin'], FILTER_VALIDATE_BOOLEAN);
        if ($isSuperAdmin && (string) $input['role'] !== 'admin') {
            return $this->errorResponse('Un super-administrateur doit avoir le rôle administrateur.', 422);
        }
        if ($isSuperAdmin && ! $this->isSuperAdmin()) {
            return $this->errorResponse('Seul un super-administrateur peut attribuer ce statut.', 403);
        }
        if (! $this->validateEmploye($input['employe_id'] ?? null, $tenantId)) {
            return $this->errorResponse('L’employé sélectionné est introuvable dans ce tenant.', 422);
        }

        $model = new UserModel();
        $model->skipValidation(true);
        $id = $model->insert([
            'tenant_id' => $tenantId,
            'employe_id' => $this->nullablePositiveInteger($input['employe_id'] ?? null),
            'email' => $email,
            'password_hash' => password_hash((string) $input['password'], PASSWORD_DEFAULT),
            'nom_complet' => trim((string) $input['nom_complet']),
            'telephone' => $this->nullableText($input['telephone'] ?? null),
            'role' => (string) $input['role'],
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
            'is_active' => 1,
            'auth_source' => 'LOCAL',
        ]);
        if ($id === false) {
            log_message('error', 'Création utilisateur impossible : {errors}', ['errors' => json_encode($model->errors())]);
            return $this->errorResponse('Impossible de créer cet utilisateur.', 500);
        }

        $user = $model->find((int) $id);
        return $this->successResponse($this->enrichUsers([$user], $tenantId)[0], 'Utilisateur créé.', 201);
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $model = new UserModel();
        $user = $model->where('tenant_id', $tenantId)->find($id);
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        if ((string) ($this->claims()?->role ?? '') === 'rh'
            && (string) $user['role'] === 'admin'
        ) {
            return $this->errorResponse('Les ressources humaines ne peuvent pas modifier un administrateur.', 403);
        }
        if ((int) $user['is_super_admin'] === 1 && ! $this->isSuperAdmin()) {
            return $this->errorResponse('Vous ne pouvez pas modifier un super-administrateur.', 403);
        }

        $input = $this->jsonInput();
        $allowed = ['email', 'nom_complet', 'telephone', 'role', 'employe_id', 'is_active', 'webauthn_enrollment_approved', 'is_super_admin'];
        $data = array_intersect_key($input, array_flip($allowed));
        if ($data === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }
        $validation = $this->validateUserInput($data, false);
        if ($validation !== null) {
            return $validation;
        }
        if (array_key_exists('is_super_admin', $data)) {
            $isSuperAdmin = filter_var($data['is_super_admin'], FILTER_VALIDATE_BOOLEAN);
            if (! $this->isSuperAdmin()) {
                return $this->errorResponse('Seul un super-administrateur peut modifier ce statut.', 403);
            }
            if ($isSuperAdmin && array_key_exists('role', $data) && (string) $data['role'] !== 'admin') {
                return $this->errorResponse('Un super-administrateur doit avoir le rôle administrateur.', 422);
            }
            if ($isSuperAdmin && (int) $user['id'] === (int) $this->claims()->sub) {
                return $this->errorResponse('Vous ne pouvez pas vous auto-attribuer le statut de super-administrateur.', 400);
            }
            $data['is_super_admin'] = $isSuperAdmin ? 1 : 0;
        }
        if (array_key_exists('webauthn_enrollment_approved', $data)) {
            if (! $this->isSuperAdmin() && (string) ($this->claims()?->role ?? '') !== 'admin') {
                return $this->errorResponse('Seul un administrateur peut autoriser un enrôlement passkey.', 403);
            }
            if (! in_array((string) $data['webauthn_enrollment_approved'], ['0', '1'], true)
                && ! in_array($data['webauthn_enrollment_approved'], [false, true, 0, 1], true)
            ) {
                return $this->errorResponse('Valeur d’autorisation passkey invalide.', 422);
            }
        }

        $claims = $this->claims();
        if ((int) $claims->sub === $id
            && (isset($data['role']) || isset($data['is_super_admin']) || (isset($data['is_active']) && (int) $data['is_active'] === 0))
        ) {
            return $this->errorResponse('Vous ne pouvez pas modifier votre propre rôle, votre statut de super-admin ni désactiver votre compte.', 400);
        }
        if (isset($data['role']) && ! $this->roleMayBeAssigned((string) $data['role'])) {
            return $this->errorResponse('Vous ne pouvez pas attribuer ce rôle.', 403);
        }
        if (array_key_exists('email', $data)) {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '' && ! $this->emailMayBeEmpty($user)) {
                return $this->errorResponse('Une adresse email est requise pour ce compte.', 422);
            }
            $data['email'] = $email === '' ? null : $email;
            if ($data['email'] !== null && $this->emailExists($tenantId, $data['email'], $id)) {
                return $this->errorResponse('Cet email existe déjà dans ce tenant.', 409);
            }
        }
        if (array_key_exists('employe_id', $data)
            && ! $this->validateEmploye($data['employe_id'], $tenantId)
        ) {
            return $this->errorResponse('L’employé sélectionné est introuvable dans ce tenant.', 422);
        }

        $updates = [];
        if (array_key_exists('email', $data)) $updates['email'] = $data['email'];
        if (array_key_exists('nom_complet', $data)) $updates['nom_complet'] = trim((string) $data['nom_complet']);
        if (array_key_exists('telephone', $data)) $updates['telephone'] = $this->nullableText($data['telephone']);
        if (array_key_exists('role', $data)) $updates['role'] = $data['role'];
        if (array_key_exists('employe_id', $data)) $updates['employe_id'] = $this->nullablePositiveInteger($data['employe_id']);
        if (array_key_exists('is_active', $data)) $updates['is_active'] = (int) $data['is_active'];
        if (array_key_exists('webauthn_enrollment_approved', $data)) {
            $updates['webauthn_enrollment_approved'] = (int) $data['webauthn_enrollment_approved'];
        }
        if (array_key_exists('is_super_admin', $data)) {
            $updates['is_super_admin'] = (int) $data['is_super_admin'];
        }

        $model->skipValidation(true);
        if (! $model->update($id, $updates)) {
            return $this->errorResponse('Impossible de mettre à jour cet utilisateur.', 500);
        }

        return $this->successResponse($this->enrichUsers([$model->find($id)], $tenantId)[0], 'Utilisateur mis à jour.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        $claims = $this->claims();
        if ((int) $claims->sub === $id) {
            return $this->errorResponse('Vous ne pouvez pas désactiver votre propre compte.', 400);
        }

        $model = new UserModel();
        $user = $model->where('tenant_id', $tenantId)->find($id);
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        if ((string) ($this->claims()?->role ?? '') === 'rh'
            && (string) $user['role'] === 'admin'
        ) {
            return $this->errorResponse('Les ressources humaines ne peuvent pas désactiver un administrateur.', 403);
        }
        if ((int) $user['is_super_admin'] === 1 && ! $this->isSuperAdmin()) {
            return $this->errorResponse('Vous ne pouvez pas désactiver un super-administrateur.', 403);
        }

        $model->skipValidation(true);
        if (! $model->update($id, ['is_active' => 0])) {
            return $this->errorResponse('Impossible de désactiver cet utilisateur.', 500);
        }
        return $this->successResponse(['id' => $id, 'is_active' => 0], 'Utilisateur désactivé.');
    }

    public function resetPassword(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        $model = new UserModel();
        $user = $model->where('tenant_id', $tenantId)->find($id);
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        if ((string) ($this->claims()?->role ?? '') === 'rh'
            && (string) $user['role'] === 'admin'
        ) {
            return $this->errorResponse('Les ressources humaines ne peuvent pas réinitialiser le mot de passe d’un administrateur.', 403);
        }
        if ((int) $user['is_super_admin'] === 1 && ! $this->isSuperAdmin()) {
            return $this->errorResponse('Vous ne pouvez pas réinitialiser un super-administrateur.', 403);
        }
        if (strtoupper((string) ($user['auth_source'] ?? 'LOCAL')) !== 'LOCAL') {
            return $this->errorResponse('Le mot de passe de ce compte est géré par LDAP.', 422);
        }

        $input = $this->jsonInput();
        $password = trim((string) ($input['password'] ?? ''));
        if ($password !== '' && strlen($password) < 8) {
            return $this->errorResponse('Le mot de passe doit contenir au moins 8 caractères.', 422);
        }
        $temporaryPassword = $password === ''
            ? rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'AZ'), '=')
            : null;
        $newPassword = $temporaryPassword ?? $password;

        $model->skipValidation(true);
        if (! $model->update($id, ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)])) {
            return $this->errorResponse('Impossible de réinitialiser le mot de passe.', 500);
        }

        return $this->successResponse([
            'message' => 'Mot de passe réinitialisé.',
            'temp_password' => $temporaryPassword,
        ]);
    }

    public function me(): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        $tenantId = (int) $user['tenant_id'];
        $timezone = (new \App\Services\TenantTimezoneService())->forTenant($tenantId);
        $profile = $this->enrichUsers([$user], $tenantId)[0];
        $profile['server_now'] = (new \DateTimeImmutable('now', $timezone))->format(\DateTimeInterface::ATOM);
        $profile['timezone'] = $timezone->getName();

        return $this->successResponse($profile);
    }

    public function updateMe(): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        $input = $this->jsonInput();
        $data = array_intersect_key($input, array_flip(['email', 'nom_complet', 'telephone']));
        if ($data === []) {
            return $this->errorResponse('Aucun champ de profil fourni.', 400);
        }
        $validation = $this->validateUserInput($data, false);
        if ($validation !== null) {
            return $validation;
        }

        $tenantId = (int) $user['tenant_id'];
        $updates = [];
        if (array_key_exists('email', $data)) {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '' && ! $this->emailMayBeEmpty($user)) {
                return $this->errorResponse('Une adresse email est requise pour ce compte.', 422);
            }
            if ($email !== '' && $this->emailExists($tenantId, $email, (int) $user['id'])) {
                return $this->errorResponse('Cet email existe déjà dans ce tenant.', 409);
            }
            $updates['email'] = $email === '' ? null : $email;
        }
        if (array_key_exists('nom_complet', $data)) $updates['nom_complet'] = trim((string) $data['nom_complet']);
        if (array_key_exists('telephone', $data)) $updates['telephone'] = $this->nullableText($data['telephone']);

        $model = new UserModel();
        $model->skipValidation(true);
        if (! $model->update((int) $user['id'], $updates)) {
            return $this->errorResponse('Impossible de mettre à jour le profil.', 500);
        }

        return $this->successResponse($this->enrichUsers([$model->find((int) $user['id'])], $tenantId, true)[0], 'Profil mis à jour.');
    }

    public function changeOwnPassword(): ResponseInterface
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }
        if (strtoupper((string) ($user['auth_source'] ?? 'LOCAL')) !== 'LOCAL') {
            return $this->errorResponse('Le mot de passe de ce compte est géré par LDAP.', 422);
        }

        $input = $this->jsonInput();
        $currentPassword = (string) ($input['current_password'] ?? '');
        $newPassword = (string) ($input['new_password'] ?? '');
        if (! password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            return $this->errorResponse('Le mot de passe actuel est incorrect.', 422);
        }
        if (strlen($newPassword) < 8) {
            return $this->errorResponse('Le nouveau mot de passe doit contenir au moins 8 caractères.', 422);
        }

        $model = new UserModel();
        $model->skipValidation(true);
        if (! $model->update((int) $user['id'], ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)])) {
            return $this->errorResponse('Impossible de changer le mot de passe.', 500);
        }

        return $this->successResponse(['changed' => true], 'Mot de passe modifié.');
    }

    private function validateUserInput(array $input, bool $creating): ?ResponseInterface
    {
        if (array_key_exists('email', $input)) {
            $email = is_string($input['email']) ? trim($input['email']) : '';
            $emailIsOptional = ! $creating
                && ($input['email'] === null || (is_string($input['email']) && $email === ''));
            if (! $emailIsOptional
                && (! is_string($input['email']) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)
            ) {
                return $this->errorResponse('Une adresse email valide est requise.', 422);
            }
        }
        if (array_key_exists('nom_complet', $input)
            && (! is_string($input['nom_complet']) || trim($input['nom_complet']) === ''
                || mb_strlen(trim($input['nom_complet'])) > 150)
        ) {
            return $this->errorResponse('Le nom complet est obligatoire et limité à 150 caractères.', 422);
        }
        if (array_key_exists('telephone', $input)
            && $input['telephone'] !== null
            && (! is_string($input['telephone']) || mb_strlen($input['telephone']) > 30)
        ) {
            return $this->errorResponse('Le téléphone est invalide.', 422);
        }
        if (array_key_exists('role', $input) && ! in_array($input['role'], self::ROLES, true)) {
            return $this->errorResponse('Le rôle sélectionné est invalide.', 422);
        }
        if (array_key_exists('is_active', $input)
            && ! in_array((string) $input['is_active'], ['0', '1'], true)
        ) {
            return $this->errorResponse('Le statut actif est invalide.', 422);
        }
        if (array_key_exists('is_super_admin', $input)
            && ! is_bool($input['is_super_admin'])
            && ! in_array($input['is_super_admin'], ['0', '1', 'true', 'false', 0, 1], true)
        ) {
            return $this->errorResponse('Le statut super-administrateur est invalide.', 422);
        }
        if (array_key_exists('employe_id', $input)
            && $input['employe_id'] !== null
            && $input['employe_id'] !== ''
            && (! filter_var($input['employe_id'], FILTER_VALIDATE_INT) || (int) $input['employe_id'] < 1)
        ) {
            return $this->errorResponse('L’identifiant de l’employé est invalide.', 422);
        }
        if ($creating && (! isset($input['email'], $input['nom_complet'], $input['role'], $input['password']))) {
            return $this->errorResponse('Email, nom, rôle et mot de passe sont obligatoires.', 422);
        }
        if (array_key_exists('password', $input)
            && (! is_string($input['password']) || strlen($input['password']) < 8)
        ) {
            return $this->errorResponse('Le mot de passe doit contenir au moins 8 caractères.', 422);
        }
        return null;
    }

    private function roleMayBeAssigned(string $role): bool
    {
        return $this->isSuperAdmin() || (string) ($this->claims()?->role ?? '') === 'admin' || $role !== 'admin';
    }

    private function emailExists(int $tenantId, string $email, ?int $exceptId = null): bool
    {
        $query = Database::connect()->table('users')
            ->where('tenant_id', $tenantId)
            ->where('email', $email);
        if ($exceptId !== null) {
            $query->where('id !=', $exceptId);
        }
        return $query->countAllResults() > 0;
    }

    private function emailMayBeEmpty(array $user): bool
    {
        return ($user['email'] ?? null) === null
            || (
                (string) ($user['role'] ?? '') === 'lecteur'
                && (int) ($user['employe_id'] ?? 0) > 0
                && strtoupper((string) ($user['auth_source'] ?? 'LOCAL')) === 'LDAP'
            );
    }

    private function validateEmploye(mixed $employeId, int $tenantId): bool
    {
        if ($employeId === null || $employeId === '') {
            return true;
        }
        $id = filter_var($employeId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            return false;
        }
        return (new EmployeModel())
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->find((int) $id) !== null;
    }

    private function enrichUsers(array $users, int $tenantId, bool $includeCurrentEmployeeDetails = false): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            array_column($users, 'employe_id')
        ))));
        $employees = [];
        if ($employeeIds !== []) {
            $employees = Database::connect()->table('employes e')
                ->select('e.id, e.nom, e.prenom, e.matricule, e.poste, e.unite_id, e.actif, u.nom AS unite_nom')
                ->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id AND u.deleted_at IS NULL', 'left')
                ->where('e.tenant_id', $tenantId)
                ->where('e.deleted_at', null)
                ->whereIn('e.id', $employeeIds)
                ->get()->getResultArray();
        }
        $byEmployeeId = [];
        foreach ($employees as $employee) {
            $byEmployeeId[(int) $employee['id']] = $employee;
        }

        $employeeDetailsById = [];
        if ($includeCurrentEmployeeDetails && $employeeIds !== []) {
            $employeeDetails = Database::connect()->table('employes e')
                ->select('e.id, e.unite_id, e.poste, e.est_responsable_unite, e.matricule, e.badge_id, e.user_id_capteur, e.nom, e.prenom, e.email, e.telephone, e.date_embauche, e.date_depart, e.genre, e.photo_url, e.empreinte_enrolee, e.nb_empreintes, e.role_capteur, e.actif, e.derniere_sync, e.created_at, e.updated_at, u.nom AS unite_nom')
                ->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id AND u.deleted_at IS NULL', 'left')
                ->where('e.tenant_id', $tenantId)
                ->where('e.deleted_at', null)
                ->whereIn('e.id', $employeeIds)
                ->get()->getResultArray();
            foreach ($employeeDetails as $employee) {
                $employee['id'] = (int) $employee['id'];
                $employee['unite_id'] = $employee['unite_id'] === null ? null : (int) $employee['unite_id'];
                $employee['badge_id'] = (int) $employee['badge_id'];
                $employee['est_responsable_unite'] = (bool) $employee['est_responsable_unite'];
                $employee['empreinte_enrolee'] = (bool) $employee['empreinte_enrolee'];
                $employee['nb_empreintes'] = (int) $employee['nb_empreintes'];
                $employee['role_capteur'] = (int) $employee['role_capteur'];
                $employee['actif'] = (bool) $employee['actif'];
                $employeeDetailsById[(int) $employee['id']] = $employee;
            }
        }

        return array_map(static function (array $user) use ($byEmployeeId, $employeeDetailsById, $includeCurrentEmployeeDetails): array {
            unset($user['password_hash'], $user['ldap_dn']);
            $user['id'] = (int) $user['id'];
            $user['tenant_id'] = (int) $user['tenant_id'];
            $employeeId = $user['employe_id'] === null ? null : (int) $user['employe_id'];
            $employee = $byEmployeeId[$employeeId ?? 0] ?? null;
            $effectiveActive = (bool) $user['is_active']
                && ($employeeId === null || ($employee !== null && (int) ($employee['actif'] ?? 0) === 1));
            $user['is_active'] = $effectiveActive;
            $user['is_super_admin'] = (bool) $user['is_super_admin'];
            $user['webauthn_enrollment_approved'] = (bool) $user['webauthn_enrollment_approved'];
            $user['employe_id'] = $user['employe_id'] === null ? null : (int) $user['employe_id'];
            $employee = $byEmployeeId[$user['employe_id'] ?? 0] ?? null;
            $user['employe_nom'] = $employee === null
                ? null
                : trim($employee['nom'] . ' ' . $employee['prenom']);
            $user['employe_matricule'] = $employee['matricule'] ?? null;
            $user['employe_poste'] = $employee['poste'] ?? null;
            $user['unite_nom'] = $employee['unite_nom'] ?? null;
            if ($includeCurrentEmployeeDetails) {
                $user['employe_details'] = $employeeDetailsById[$user['employe_id'] ?? 0] ?? null;
            }
            return $user;
        }, $users);
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            return $default;
        }
        return max($minimum, min($maximum, (int) $parsed));
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
