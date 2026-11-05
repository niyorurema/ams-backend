# Déploiement AMS

## État des artefacts

Le backend est une application CodeIgniter 4 dans `ams`; le frontend Next.js est dans le dépôt frère `ams-frontend`. Des workflows GitHub Actions de validation backend/frontend sont fournis; aucun déploiement automatique ni fichier Docker n’est livré. Les commandes ci-dessous sont des recommandations à adapter, pas une procédure déjà validée sur un VPS.

## Préparer le serveur

1. Utilisez un serveur Linux supporté et un utilisateur système non privilégié pour exécuter PHP.
2. Installez PHP 8.2+ (8.4 recommandé), Composer, Node.js 20+, MySQL 8, Apache ou Nginx, et les extensions PHP nécessaires.
3. Configurez un nom DNS et un certificat TLS valide avant d’exposer le service.
4. Limitez les ports MySQL, LDAP, SMTP et GPIO au réseau privé; n’exposez que HTTPS côté client, et le port ADMS seulement si les appareils doivent joindre le serveur.
5. Utilisez des comptes techniques dédiés et des secrets différents par environnement.

## Configuration MySQL

Créez une base InnoDB et un utilisateur dédié avec les privilèges nécessaires aux migrations et à l’application. Ne réutilisez pas `root` en production.

```sql
CREATE DATABASE ams CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ams_app'@'10.%' IDENTIFIED BY '<secret-fort>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON ams.* TO 'ams_app'@'10.%';
FLUSH PRIVILEGES;
```

Restreignez le host du compte et les privilèges aux besoins réels après les migrations. Configurez la connexion dans `.env`; ne versionnez pas ce fichier.

## Déployer le backend

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php spark migrate
```

La migration `2026-10-26-000003_CreateAttendanceRuleVersionsTable` ajoute l’historique immuable des règles par horaire et date d’effet. Vérifiez son application avec `php spark migrate:status` avant d’utiliser **Paramètres → Pointages**. Elle ne remplace pas les règles historiques; les tenants sans version applicable conservent le calcul de repli.

Pour activer un nouveau profil, sélectionnez d’abord un horaire, configurez ses créneaux et sa date d’effet, puis comparez les résultats sur une période d’essai (31 jours maximum) avant de confirmer un recalcul. La comparaison ne persiste pas de projection. Les anciennes dates restent liées à la version applicable à leur date d’effet; ne modifiez pas les snapshots directement en base.

Définissez au minimum :

- `CI_ENVIRONMENT = production`;
- `app.baseURL` sur l’URL HTTPS publique de l’API;
- paramètres `database.default.*`;
- `JWT_SECRET` (secret aléatoire privé d’au moins 32 caractères) et `JWT_TTL`;
- paramètres LDAP, SMTP et GPIO dans l’interface AMS, sous Paramètres; les valeurs héritées de `.env` servent seulement à préremplir la configuration jusqu’à son enregistrement dans l’application.

Rendez `writable/` accessible en écriture au processus PHP; le reste du code devrait être en lecture seule. Sauvegardez séparément `writable/keys/integration-settings.key`, qui protège les secrets d’intégration chiffrés en base. La racine du virtual host doit pointer uniquement vers `backend/public`, pas vers la racine du dépôt. Désactivez l’affichage des erreurs en production et centralisez les logs avec des permissions restreintes.

### Suivi des traitements et relances contrôlées

Après le déploiement de la migration, le panneau **Paramètres → Intégrations** (super-administrateur uniquement) affiche les dernières exécutions des rapports J-1, synchronisations GPIO et traitements des capteurs, avec leur état, leur dernier signal de vie et leurs erreurs. Les traitements continus `capteurs:scan` et `capteurs:scan-all` mettent à jour ce signal à chaque cycle; une exécution sans signal depuis 15 minutes est marquée obsolète.

L’état de connexion affiché pour les capteurs est calculé à partir de communications réussies, indépendamment de leur activation administrative. Les requêtes ADMS valides sont suivies selon l’intervalle envoyé dans la configuration ADMS (`TransInterval=1`, soit une minute); les capteurs interrogés par AMS utilisent l’intervalle de `capteurs:scan-all --interval`. Un capteur actif est indiqué hors ligne après trois intervalles sans communication réussie, et reste inconnu tant qu’aucune communication n’a été observée. La page actualise cet état toutes les 15 secondes.

Pour traiter les relances mises en file par le panneau, planifiez sur le serveur un lancement fréquent du worker, par exemple chaque minute avec cron :

```cron
* * * * * cd /srv/ams && /usr/bin/php spark system:jobs:process-retries >> /var/log/ams-job-retries.log 2>&1
```

Le worker traite au maximum 20 demandes par lancement. Il n’est pas déclenché par l’interface, qui ne fait que mettre une demande en file; surveillez son code de sortie et ses journaux. Une relance J-1 reprend les paramètres de l’exécution source : dates d’exécution/rapport, tenant, configuration, format, langue, éligibilité, destinataires et unités. Les rapports déjà archivés sont ignorés. Les résultats de relance sont `SUCCESS`, `PARTIAL` ou `ERROR`; une relance partielle n’est pas présentée comme un succès complet. Une relance de rapport peut envoyer de vrais courriels aux destinataires configurés. Ne testez pas le worker en production sans examiner d’abord la file et les conséquences; aucun test d’envoi SMTP n’est effectué par cette procédure.

Le panneau signale les exécutions échouées/partielles, les signaux de vie périmés, l’absence de lancement des rapports J-1 pendant plus de 26 heures lorsqu’une configuration est active, ainsi qu’une file de relance bloquée pendant plus de cinq minutes. Il n’existe pas de seuil générique GPIO car sa fréquence relève du planificateur de chaque serveur; configurez une alerte d’exploitation adaptée à sa fréquence réelle. L’historique applicatif est conservé 90 jours par défaut; planifiez une purge mensuelle :

```cron
17 3 1 * * cd /srv/ams && /usr/bin/php spark system:jobs:prune-history 90 >> /var/log/ams-job-retention.log 2>&1
```

Avant une rétention plus courte, vérifiez les exigences d’audit et de conservation de l’organisation.

La CI backend exécute les tests SQLite ainsi qu’un test de concurrence de la file sur MySQL 8/InnoDB. Le test MySQL utilise une base dédiée `ams_test`; ne fournissez jamais une base d’application ou de production comme cible de test.

## Apache / Nginx et HTTPS

Configurez le routage des URI vers `public/index.php` selon le guide officiel CodeIgniter et activez les règles de réécriture nécessaires. N’autorisez pas l’accès HTTP direct aux fichiers `.env`, `app/`, `vendor/`, `writable/` ou aux sauvegardes.

- Nginx : virtual host TLS, `root .../backend/public`, PHP-FPM, limite de taille d’upload alignée sur l’API.
- Apache : document root sur `backend/public`, `mod_rewrite` et PHP-FPM recommandés.
- HTTPS : certificat géré et renouvelé automatiquement; redirection HTTP vers HTTPS; protocoles TLS actuels.
- CORS : n’autorisez que les origines frontend connues. Les règles doivent rester cohérentes avec les cookies/jetons et les en-têtes Authorization.

Les fichiers de configuration du dépôt doivent être vérifiés avant leur emploi sur une distribution précise; aucun virtual host prêt à l’emploi n’est fourni.

## Déployer le frontend

Dans `ams-frontend`, configurez `NEXT_PUBLIC_API_URL` avec l’URL HTTPS de l’API avant le build :

```bash
npm ci
npm run build
npm run start
```

Placez Next.js derrière un reverse proxy TLS, ou utilisez l’adaptateur de déploiement choisi par l’infrastructure. Les variables `NEXT_PUBLIC_*` sont embarquées dans le bundle client; n’y mettez aucun secret. Vérifiez que les deux dépôts sont déployés sur des versions compatibles.

## Docker (à construire, non livré)

Avant d’ajouter Docker en production, créer et relire des images séparées backend/frontend, utiliser des images de base maintenues et non-root, injecter les secrets via le gestionnaire de secrets de l’hôte, persister `writable/` si nécessaire et placer MySQL sur un volume sauvegardé. Ne copiez ni `.env`, ni fichiers de données dans l’image.

Exemple d’orchestration conceptuel uniquement :

```text
reverse-proxy (TLS) -> frontend
reverse-proxy (TLS/API) -> backend PHP-FPM ou Apache
backend -> MySQL privé
```

Ne lancez pas de stack avec secrets fictifs en production. Documenter les health checks, limites de ressources, migrations contrôlées et stratégie de rollback dans les fichiers Docker qui seront effectivement ajoutés.

## Terminaux, LDAP et réseau

- Les terminaux ZKTeco doivent joindre une adresse serveur stable; en mode push, le chemin `/iclock` et le port configuré doivent être accessibles depuis leur réseau.
- Pour les réseaux distants, utilisez un VPN approuvé (par exemple NetBird) plutôt que d’exposer MySQL ou les appareils à Internet.
- Validez LDAP, SMTP et GPIO depuis le réseau réel avant d’activer les traitements planifiés.
- `php spark serve` est un serveur de développement et ne doit pas être utilisé comme service de production.

## Sauvegarde et restauration

Les scripts `scripts/backup-ams.sh` et `scripts/verify-ams-backup.sh` créent une sauvegarde MySQL chiffrée et vérifient sa restauration dans une nouvelle base isolée. Si l’archive appariée existe, la vérification déchiffre aussi uploads et clé d’intégration vers un dossier de staging privé et contrôle les chemins/types avant extraction. Aucun de ces scripts ne supprime de base. Prérequis : `mysqldump`, `mysql`, `gpg`, `gzip`, `tar`, `sha256sum`, une clé publique GPG de sauvegarde et deux profils `mysql_config_editor` distincts : `ams-backup` en lecture seule sur AMS, `ams-restore` limité aux bases `ams_restore_verify_*`.

Exemple de séparation des privilèges MySQL (adaptez les hôtes et secrets) :

```sql
CREATE USER 'ams_backup'@'10.%' IDENTIFIED BY '<secret-sauvegarde>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON ams.* TO 'ams_backup'@'10.%';
CREATE USER 'ams_restore'@'10.%' IDENTIFIED BY '<secret-restauration>';
GRANT ALL PRIVILEGES ON `ams_restore_verify\_%`.* TO 'ams_restore'@'10.%';
```

Le compte de restauration n’a aucun accès à la base active AMS; ses privilèges, y compris ceux nécessaires à la restauration du dump, sont limités au préfixe des bases temporaires. Le script ne supprime aucune base. Testez le motif de privilèges sur la version MySQL déployée et vérifiez avec `SHOW GRANTS` qu’il ne correspond qu’aux bases de vérification.

```bash
gpg --import /chemin-securise/cle-publique-sauvegarde.asc
mysql_config_editor set --login-path=ams-backup --host=127.0.0.1 --user=ams_backup --password
mysql_config_editor set --login-path=ams-restore --host=127.0.0.1 --user=ams_restore --password
export AMS_BACKUP_GPG_RECIPIENT='empreinte-GPG-de-l-equipe-de-reprise'
sudo -u ams bash ./scripts/backup-and-verify-ams.sh ams /srv/backups/ams
```

Le mot de passe de chaque profil MySQL est demandé interactivement par `mysql_config_editor`, jamais passé en argument. La sauvegarde est chiffrée, accompagnée d’un SHA-256 et publiée de manière atomique; ses fichiers sont créés avec des permissions restrictives. Le script inclut `writable/uploads` ainsi que `writable/keys/integration-settings.key` lorsqu’ils existent et refuse les liens symboliques. `backup-and-verify-ams.sh` enchaîne sauvegarde et restauration/vérification dans une base et un répertoire isolés. Exécutez cette commande quotidiennement via le planificateur de l’hôte; stockez les sauvegardes sur un support indépendant et la clé privée GPG hors du serveur AMS. Surveillez le code de sortie, le nom de la base restaurée, le dossier d’assets et l’âge de la dernière sauvegarde. Ne supprimez les bases de vérification qu’après inspection et approbation explicite; appliquez la rétention dans le stockage des sauvegardes selon la politique institutionnelle.

### Déploiement et retour arrière

1. Sauvegardez la base et les fichiers avant une migration de production; vérifiez au minimum le checksum.
2. Déployez une release versionnée dans un nouveau répertoire sans écraser celle qui sert le trafic. Gardez la release précédente.
3. Exécutez les migrations prévues puis les contrôles de santé avant la bascule. Privilégiez les migrations additives compatibles avec les deux versions.
4. En cas d’échec applicatif, rebasculez le code vers la release précédente et vérifiez les parcours critiques. Ne lancez pas automatiquement de migration descendante : elle peut être destructive ou irréversible.
5. Si la base doit être restaurée, faites-le d’abord sur une instance isolée et obtenez une validation métier avant toute bascule. Consignez la décision, l’heure et le point de restauration.

## Checklist de mise en production

- [ ] Secrets et origines CORS relus; aucune valeur de développement en production.
- [ ] TLS, DNS et firewall vérifiés depuis les réseaux autorisés.
- [ ] Migration exécutée après sauvegarde et état `migrate:status` contrôlé.
- [ ] Super-admin initial créé/révélé selon une procédure sécurisée.
- [ ] Comptes de test, logs sensibles et données d’exemple absents.
- [ ] Login, droits cross-tenant, désactivation compte/tenant et sauvegarde/restauration vérifiés.
- [ ] LDAP, SMTP, GPIO et terminaux testés uniquement depuis le réseau autorisé.
- [ ] Logs, supervision et alertes opérationnels.
