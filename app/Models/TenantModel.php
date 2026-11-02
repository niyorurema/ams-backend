<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle TenantModel.
 *
 * Représente le tenant / client auquel appartiennent les utilisateurs,
 * capteurs, employés et pointages. Le modèle multi-tenant est appliqué dès la
 * phase 1 avec `tenant_id` sur toutes les tables de données métier.
 */
class TenantModel extends Model
{
    protected $table            = 'tenants';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';
    protected $afterInsert      = ['initialiserParametresPointage'];

    protected $allowedFields = [
        'name',
        'slug',
        'database_name',
        'timezone',
        'jours_travailles',
        'is_active',
        'contact_email',
        'logo_url',
        'slogan',
        'primary_color',
        'secondary_color',
        'sidebar_color',
        'topbar_color',
        'font_family',
        'font_size',
        'notes',
    ];

    protected $validationRules = [
        'id'            => 'permit_empty|integer',
        'name'          => 'required|max_length[150]',
        'slug'          => 'required|max_length[100]|is_unique[tenants.slug,id,{id}]',
        'timezone'      => 'required|max_length[64]',
        'database_name' => 'permit_empty|max_length[64]',
        'is_active'     => 'required|in_list[0,1]',
    ];

    protected $validationMessages = [
        'name' => [
            'required' => 'Le nom du tenant est obligatoire.',
        ],
        'slug' => [
            'required' => 'Le slug du tenant est obligatoire.',
            'is_unique' => 'Ce slug est déjà utilisé par un autre tenant.',
        ],
        'timezone' => [
            'required' => 'Le fuseau horaire est obligatoire.',
        ],
    ];

    /**
     * Initialise les paramètres par défaut lors de la création d'un tenant.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function initialiserParametresPointage(array $data): array
    {
        $tenantId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($tenantId !== false && $tenantId > 0) {
            $this->creerServiceParametragePointage()
                ->initialiserParametresParDefaut((int) $tenantId);
        }

        return $data;
    }

    protected function creerServiceParametragePointage(): \App\Services\ParametragePointageService
    {
        return new \App\Services\ParametragePointageService();
    }

    public function users()
    {
        return $this->hasMany(UserModel::class, 'tenant_id', 'id');
    }

    public function capteurs()
    {
        return $this->hasMany(CapteurModel::class, 'tenant_id', 'id');
    }

    public function employes()
    {
        return $this->hasMany(EmployeModel::class, 'tenant_id', 'id');
    }

    public function pointages()
    {
        return $this->hasMany(PointageModel::class, 'tenant_id', 'id');
    }
}
