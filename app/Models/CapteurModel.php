<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle CapteurModel.
 *
 * Représente chaque périphérique ZKTeco/Nymbiar configuré pour une instance
 * multi-tenant. Le service ZKTecoService utilisera ce modèle pour récupérer les
 * paramètres réseau et les états de synchronisation.
 */
class CapteurModel extends Model
{
    protected $table            = 'capteurs';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'nom',
        'numero_serie',
        'adresse_ip',
        'port',
        'type_reseau',
        'com_key',
        'firmware',
        'platform',
        'statut',
        'derniere_communication',
        'source_derniere_communication',
        'frequence_adms_secondes',
        'frequence_polling_secondes',
        'dernier_scan',
        'derniere_sync_users',
        'derniere_sync_attendance',
        'dernier_pointage_sync',
        'dernier_pointage_index',
        'timeout_secondes',
        'derniere_erreur',
        'notes',
    ];

    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'nom'       => 'required|max_length[100]',
        'adresse_ip'=> 'required|valid_ip',
        'port'      => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[65535]',
        'type_reseau' => 'required|in_list[LAN,NETBIRD]',
        'statut'    => 'required|in_list[ACTIF,INACTIF]',
        'timeout_secondes' => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[300]',
    ];

    protected $validationMessages = [
        'adresse_ip' => [
            'valid_ip' => 'L’adresse IP du capteur est invalide.',
        ],
        'type_reseau' => [
            'in_list' => 'Le type réseau doit être LAN ou NETBIRD.',
        ],
    ];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id', 'id');
    }

    public function actifs()
    {
        return $this->where('statut', 'ACTIF');
    }
}
