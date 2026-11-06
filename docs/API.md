# API AMS

Cette documentation décrit les routes déclarées dans `app/Config/Routes.php`. Base locale : `http://localhost:8080`. Sauf indication contraire, les données et réponses sont en JSON.

## Authentification

Le login ne nécessite pas de jeton :

```http
POST /api/auth/login
Content-Type: application/json

{"tenant_id":1,"email":"admin@example.test","password":"<mot-de-passe>"}
```

`tenant_id` peut être omis si l’email identifie un compte unique; si plusieurs tenants partagent l’email, il doit être précisé. Réponse réussie :

```json
{
  "success": true,
  "data": {
    "access_token": "<JWT>",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "tenant_id": 1,
      "email": "admin@example.test",
      "nom_complet": "Administrateur",
      "role": "admin",
      "is_super_admin": false
    },
    "expires_in": 3600
  },
  "message": "Connexion réussie."
}
```

Transmettez le jeton aux routes protégées avec `Authorization: Bearer <JWT>`. Le mot de passe et son hash ne sont pas inclus dans la réponse. Les routes de l’horloge ADMS (`/iclock/...`) sont destinées aux appareils et n’utilisent pas le filtre JWT; elles ont leur propre association par numéro de série.

## Format des réponses et erreurs

Les réponses JSON standards ont la forme `success`, `data`, et éventuellement `message`. Les téléchargements d’exports sont des réponses binaires. Codes habituels :

| Code | Signification |
|---|---|
| `200` | Lecture ou modification réussie |
| `201` | Ressource créée |
| `204` | Réponse à une requête CORS `OPTIONS` |
| `400` | Requête invalide |
| `401` | Jeton absent/invalide/expiré, compte inactif ou tenant inactif |
| `403` | Rôle ou périmètre interdit |
| `404` | Ressource absente ou hors du tenant autorisé |
| `409` | Conflit, par exemple email ou slug déjà utilisé |
| `422` | Validation métier invalide |
| `500` | Erreur interne |
| `502` | Échec de l’envoi email |
| `503` | Authentification JWT non configurée |

Exemple d’erreur :

```json
{"success":false,"data":null,"message":"Accès interdit à ce tenant."}
```

## Endpoints

### Profil et comptes

| Méthode | URL | Accès | Description |
|---|---|---|---|
| `GET` | `/api/me` | JWT | Profil du compte courant |
| `PUT` | `/api/me` | JWT | Met à jour `email`, `nom_complet`, `telephone` |
| `POST` | `/api/me/change-password` | JWT, compte LOCAL | Body `current_password`, `new_password` (8 caractères minimum) |
| `GET` | `/api/users` | Admin, RH ou super-admin du tenant | Liste filtrée : `search`, `role`, `actif=0|1`, `limit`, `offset` |
| `GET` | `/api/users/{id}` | Admin, RH ou super-admin du tenant | Détail utilisateur |
| `POST` | `/api/users` | Admin, RH ou super-admin du tenant | Crée un compte |
| `PUT` | `/api/users/{id}` | Admin, RH ou super-admin du tenant | Modifie les champs permis |
| `DELETE` | `/api/users/{id}` | Admin, RH ou super-admin du tenant | Désactive le compte |
| `POST` | `/api/users/{id}/reset-password` | Admin, RH ou super-admin du tenant | Réinitialise un compte LOCAL; sans mot de passe fourni, renvoie un temporaire une fois |

Création utilisateur (rôle parmi `admin`, `rh`, `superviseur`, `chef_service`, `directeur`, `commissaire`, `lecteur`) :

```json
{
  "email": "agent@example.test",
  "nom_complet": "Agent Test",
  "password": "<mot-de-passe>",
  "role": "lecteur",
  "employe_id": null,
  "telephone": null
}
```

`employe_id` doit appartenir au même tenant. Le super-admin n’est pas attribuable par cette API. Les RH ne peuvent pas administrer les comptes admin; un utilisateur ne peut pas changer son propre rôle ni se désactiver.

### Administration globale des tenants

Toutes les routes suivantes exigent un JWT dont l’utilisateur actif possède `is_super_admin = 1`.

| Méthode | URL | Description |
|---|---|---|
| `GET` | `/api/admin/tenants` | Liste filtrée par `search`, `actif=0|1`, `limit`, `offset` |
| `GET` | `/api/admin/tenants/{id}` | Détail et compteurs employés/utilisateurs |
| `POST` | `/api/admin/tenants` | Crée un tenant et son premier administrateur, dans une transaction |
| `PUT` | `/api/admin/tenants/{id}` | Met à jour `name`, `slug`, `contact_email`, `timezone`, `is_active` |
| `DELETE` | `/api/admin/tenants/{id}` | Désactivation logique (`is_active=0`, `deleted_at`) |

Exemple de création :

```json
{
  "name": "Organisation Exemple",
  "slug": "organisation-exemple",
  "contact_email": "contact@example.test",
  "timezone": "Africa/Bujumbura",
  "admin_nom_complet": "Admin Exemple",
  "admin_email": "admin@example.test",
  "admin_password": "<8-caracteres-minimum>"
}
```

Le slug peut être omis et sera dérivé du nom. Le mot de passe admin peut également être omis : la réponse contient alors `temporary_password`, à communiquer de manière sécurisée. Cette valeur n’est pas journalisée. Le tenant de la session courante ne peut pas être désactivé.

### Personnalisation du tenant courant

| Méthode | URL | Accès | Description |
|---|---|---|---|
| `GET` | `/api/admin/tenant` | JWT | Lecture configuration visuelle |
| `PUT` | `/api/admin/tenant` | Admin du tenant ou super-admin | Met à jour nom/visuel |
| `POST` | `/api/admin/tenant/logo` | Admin du tenant ou super-admin | Téléverse le logo |

### Employés, unités, horaires et appareils

| Méthode | URL | Accès / paramètres |
|---|---|---|
| `GET` | `/api/employes` | JWT; `search`, `unite_id`, `actif`, dates de création/synchro, `limit`, `offset` |
| `POST` | `/api/employes` | Admin/RH; matricule, badge_id, nom et prénom requis |
| `GET` | `/api/employes/{id}` | JWT et périmètre tenant |
| `PUT`, `PATCH` | `/api/employes/{id}` | Admin/RH |
| `DELETE` | `/api/employes/{id}` | Admin/RH |
| `POST` | `/api/employes/{id}/desactiver` | Désactivation |
| `POST` | `/api/employes/{id}/reactiver` | Réactivation |
| `GET` | `/api/employes/{id}/capteurs` | Affectations aux capteurs |
| `POST` | `/api/employes/{id}/capteurs` | Ajouter une affectation |
| `DELETE` | `/api/employes/{id}/capteurs/{capteurId}` | Retirer une affectation |
| `POST` | `/api/employes/{id}/deplacer` | Déplacer entre capteurs |
| `GET` | `/api/employes/{id}/presence` | Présence calculée |
| `GET` | `/api/employes/{id}/horaire` | Horaire effectif |
| `GET` | `/api/unites` | Arbre organisationnel du tenant |
| `GET` | `/api/unites/{id}/descendantes` | Descendants (`recursif`) |
| `GET` | `/api/unites/{id}/employes` | Employés de l’unité |
| `GET` | `/api/horaires` | Horaires disponibles |
| `POST` | `/api/horaires/valider` | Valider des plages |
| `POST` | `/api/horaires/defaut` | Créer horaire par défaut |
| `GET` | `/api/capteurs` | Capteurs du tenant |
| `GET` | `/api/capteurs/{id}` | Détail public du capteur (sans secret de communication) |
| `POST` | `/api/capteurs` | Admin; créer capteur |
| `PUT`, `PATCH` | `/api/capteurs/{id}` | Admin; modifier capteur |
| `DELETE` | `/api/capteurs/{id}` | Admin; suppression logique |
| `GET` | `/api/tenants/{tenantId}/capteurs` | JWT; super-admin peut cibler un autre tenant |
| `GET` | `/api/capteurs/{id}/info` | Tester/lire les informations matérielles |
| `POST` | `/api/capteurs/{id}/sync-users` | Synchroniser utilisateurs matériels |
| `POST` | `/api/capteurs/{id}/sync-attendance` | Synchroniser pointages |
| `POST` | `/api/capteurs/{id}/sync-affectations` | Synchroniser affectations |

### Pointages et rapports

| Méthode | URL | Description |
|---|---|---|
| `GET` | `/api/pointages` | Liste selon les filtres de dates, employé, capteur, événement et statut |
| `POST` | `/api/pointages` | Crée un pointage manuel pour un autre employé; réservé à `admin`, `rh` et super-administrateur; body `employe_id`, `date_pointage`, `type_evenement`, `capteur_id?`, `raison?` |
| `POST` | `/api/me/pointage` | Crée le pointage de l’employé associé au compte authentifié à l’heure du serveur, après validation passkey; body `type_evenement`, `challenge_id`, `credential` |
| `POST` | `/api/pointages/{id}/recalculer` | Recalcule à titre consultatif le type/statut du pointage avec le contexte horaire; aucune écriture |
| `GET` | `/api/pointages/stats` | Statistiques de présence |
| `GET` | `/api/pointages/export` | Export CSV par défaut; `format=excel` pour XLSX. Respecte les filtres de dates, employé, capteur, type et statut |
| `GET` | `/api/realtime/pointages` | Flux SSE du tenant, JWT Bearer dans l’en-tête et reprise via `Last-Event-ID` |
| `GET` | `/api/me/historique` | Historique personnel |
| `GET` | `/api/rapports/employes-visibles` | Employés visibles pour l’utilisateur |
| `GET` | `/api/rapports/quotidien?date=AAAA-MM-JJ` | Rapport quotidien JSON |

Le flux SSE nécessite une instance Redis configurée dans `.env` (`REDIS_HOST`, `REDIS_PORT`, `REDIS_DATABASE`, et éventuellement `REDIS_PASSWORD`). Il est borné à environ 22 secondes par connexion; le client se reconnecte automatiquement. La diffusion intervient après l’insertion et le recalcul du pointage par le scanner capteurs.

Un pointage manuel pour autrui est limité au tenant du JWT et réservé à `admin`, `rh` et super-administrateur. Ces rôles ne peuvent pas utiliser cette route pour leur propre dossier : ils doivent passer par `/api/me/pointage`, qui exige une passkey et détermine l’employé, l’heure et le prochain type attendu côté serveur. Les autres rôles ne peuvent pas créer de pointage par cette route. Les types acceptés sont `CHECK_IN`, `CHECK_OUT`, `BREAK_IN` et `BREAK_OUT`. Le statut est recalculé pour la journée dans la même transaction que la création. Redis est une diffusion auxiliaire; son indisponibilité n’annule pas le pointage enregistré.
| `GET` | `/api/rapports/quotidien/export?date=AAAA-MM-JJ&format=PDF` | Export `PDF` ou `EXCEL` |
| `POST` | `/api/rapports/quotidien/envoyer` | Envoi du rapport; body `date`, `format`, `langue`, `user_id` facultatif |
| `GET` | `/api/rapports/planifies` | Lister les planifications du tenant |
| `POST` | `/api/rapports/planifies` | Créer une planification |
| `PUT` | `/api/rapports/planifies/{id}` | Modifier une planification |
| `DELETE` | `/api/rapports/planifies/{id}` | Supprimer logiquement une planification |

### Congés ERP et empêchements

| Méthode | URL | Description |
|---|---|---|
| `GET` | `/api/absences` | Historique paginé, filtrable par dates, statut, source, matricule et numéro de demande |
| `GET` | `/api/absences/forecast?date_debut=AAAA-MM-JJ&date_fin=AAAA-MM-JJ` | Prévision des congés ERP `APPROVED` sur une période future de 1 à 90 jours; périmètre filtré selon les employés visibles par le JWT |
| `GET` | `/api/absences/erp-contract` | Contrat JSON normalisé attendu par le futur connecteur ERP; réservé aux RH et administrateurs; intégration directe désactivée |
| `GET` | `/api/absences/import/template` | Téléchargement du modèle Excel de l’import manuel; dates formatées `YYYY-MM-DD`; réservé aux RH et administrateurs |
| `GET` | `/api/absences/status-mappings` | Lire les mappings de statut propres au tenant, avec valeurs OBR par défaut |
| `POST` | `/api/absences/status-mappings` | Ajouter/remplacer le mapping tenant; body `source_code`, `source_label`, `absence_status` (`PENDING`, `APPROVED`, `REJECTED` ou `CANCELLED`) |
| `DELETE` | `/api/absences/status-mappings/{id}` | Supprimer une surcharge ou un mapping propre au tenant; une valeur OBR par défaut correspondante redevient applicable |
| `GET`, `PUT` | `/api/absences/settings` | Lire/configurer le circuit (`RH_ONLY`, `SUPERVISOR_ONLY` ou `MANAGER_THEN_RH`); seuls les superviseurs du périmètre peuvent décider en mode `SUPERVISOR_ONLY` |
| `POST` | `/api/absences/import/preview` | Analyser un fichier ERP sans écrire en base; multipart `fichier` |
| `POST` | `/api/absences/import/commit` | Importer les lignes valides; multipart `fichier` et `ignorer_erreurs` |

Le contrat API v1 affiche les champs attendus et les contraintes de données; l’ingestion interne `AbsenceErpSyncService` est désactivée par défaut et ne réalise aucun accès réseau. Le mapping de statut est propre au tenant et associe exactement un code et un libellé source à un statut AMS; les couples OBR historiques sont disponibles par défaut. L’import confirme uniquement le statut final selon le mapping. Les périodes complètes multi-jours et les demi-journées à date unique avec heures valides sont prises en charge. Une ligne absente d’un fichier n’annule aucune demande; une annulation n’est enregistrée que si un statut d’annulation explicite est importé. La prévision est en lecture seule et n’autorise pas AMS à valider un congé dans l’ERP.

Exemples d’export et de lecture :

```powershell
$headers = @{ Authorization = "Bearer $token" }
Invoke-RestMethod "$baseUrl/api/rapports/quotidien?date=2026-10-04" -Headers $headers
Invoke-WebRequest "$baseUrl/api/rapports/quotidien/export?date=2026-10-04&format=PDF" `
  -Headers $headers -OutFile '.\rapport.pdf'
```

### LDAP et protocole ADMS

| Méthode | URL | Description |
|---|---|---|
| `POST` | `/api/auth/ldap/test` | Tester LDAP pour les administrateurs |
| `POST` | `/api/auth/ldap/sync-users` | Synchroniser les comptes LDAP |
| `GET`, `POST` | `/iclock/cdata` | Réception/configuration ADMS |
| `GET` | `/iclock/getrequest` | Requêtes ADMS du terminal |
| `POST` | `/iclock/devicecmd` | Réponse du terminal aux commandes |

## Exemple curl

```bash
curl -sS http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"tenant_id":1,"email":"admin@example.test","password":"<secret>"}'

curl -sS 'http://localhost:8080/api/employes?limit=20&offset=0' \
  -H 'Authorization: Bearer <JWT>'
```

Remplacez les valeurs fictives et ne collez jamais de jeton réel dans un ticket ou un journal partagé.
