es# Préparer l’intégration directe de l’ERP des congés avec AMS

## Objectif et principes

L’intégration future doit synchroniser les demandes de congé ERP vers AMS pour les prévisions, les rapports et les calculs de présence. L’ERP reste l’unique autorité pour créer, approuver, refuser ou annuler un congé ERP. AMS consomme les changements et ne les réécrit jamais dans le système source.

La connexion directe ne doit être activée qu’après accord du propriétaire de l’ERP, validation du contrat technique et essais en environnement de test. Tant que ces conditions ne sont pas réunies, l’import Excel/CSV existant reste le mécanisme de référence et de secours.

## État actuel d’AMS

- L’import manuel rapproche les demandes par matricule et associe les absences à un tenant et à un employé AMS.
- Les lignes sont dédoublonnées par source, identifiant de demande et date de début; les changements de statut sont historisés.
- Un fichier incomplet n’annule pas une demande. Seul un statut d’annulation explicite du système source annule le congé importé.
- Le lot de fichier conserve son nom, son empreinte SHA-256 et ses compteurs. Les tâches système d’AMS offrent déjà un suivi d’exécution, et la synchronisation GPIO fournit un exemple de commande planifiée avec verrou et mode simulation.
- AMS expose désormais un contrat JSON normalisé et un service d’ingestion réutilisant l’upsert idempotent de l’import fichier. Le service direct est désactivé par défaut; aucun transport, secret, curseur persistant ou planificateur ERP n’est encore configuré.

## Ordre de préférence pour le transport

1. **API officielle en lecture seule**, si le fournisseur la propose : API versionnée, authentification de service, filtres incrémentaux, pagination et changements d’état explicites. Une API avec notifications/webhooks peut accélérer la détection, mais doit être complétée par une réconciliation périodique.
2. **Vue ou schéma d’intégration en lecture seule**, approuvé et maintenu par l’équipe ERP, si aucune API exploitable n’existe. N’interroger que des vues documentées, jamais les tables internes non contractuelles. Utiliser un compte SQL dédié sans privilège d’écriture et une liaison chiffrée.
3. **Échange de fichiers automatisé** (SFTP ou dépôt sécurisé), si les deux options précédentes sont indisponibles. L’ERP doit produire un export complet ou un export différentiel clairement identifié; AMS doit vérifier l’intégrité, l’origine, la date et le schéma du fichier.

Éviter l’accès direct en écriture à la base ERP, le partage de comptes humains, l’automatisation du navigateur et le couplage aux tables internes non documentées. Ne pas exposer l’ERP à Internet pour contourner les règles réseau; privilégier une liaison privée autorisée par les responsables sécurité des deux systèmes.

## Contrat de données à obtenir de l’équipe ERP

Avant le développement du connecteur, demander et faire valider :

- documentation du mécanisme supporté, environnement de test, propriétaire opérationnel et procédure de support;
- identifiant stable et immuable de la demande, matricule stable de l’employé, dates/heures et fuseau horaire;
- signification et cycle de vie exacts des statuts, notamment approbations intermédiaires, approbation finale, refus, retrait, annulation et modification après approbation;
- distinction entre congé complet, demi-journée et autres fractions, ainsi que l’unité et la convention des heures;
- possibilité de récupérer les demandes modifiées ou annulées depuis un curseur fiable (`updated_at`, numéro de séquence ou jeton opaque), y compris les suppressions logiques;
- pagination, limites de débit, fenêtres de rétention, ordre des résultats, stabilité des curseurs et comportement en cas de reprise;
- politiques de confidentialité : champs nécessaires, motifs médicaux ou autres champs à exclure, durée de conservation et habilitations;
- SLA, fenêtre de maintenance, rotation des secrets/certificats, seuils d’alerte et contact en cas d’incident.

Ne pas déduire une annulation de l’absence d’une demande dans une réponse incrémentale. Une synchronisation complète ne peut annuler les lignes absentes qu’après confirmation explicite que l’export est complet, cohérent et couvre le périmètre concerné; la règle la plus sûre reste de consommer un événement ou statut d’annulation explicite.

## Architecture cible dans AMS

Séparer le transport de l’application métier :

1. Un connecteur ERP récupère les pages/événements et gère authentification, temporisation, pagination, reprise et erreurs réseau.
2. Un adaptateur transforme le schéma ERP en un format normalisé AMS. Il valide les dates, les statuts, les heures et les identifiants, et ne conserve pas les champs ERP inutiles.
3. Le service `AbsenceErpSyncService` valide les enregistrements normalisés, rapproche les employés, applique le mapping de statut propre au tenant et réutilise le même upsert/historique que l’import fichier. Il est désactivé par défaut et n’effectue aucun appel réseau.
4. À la réception du contrat ERP, ajouter le connecteur et sa commande CLI hors requête web, avec `--dry-run`, verrou anti-concurrence, journal d’exécution, curseur persistant et compteurs lus/créés/modifiés/annulés/ignorés/en erreur.
5. Ajouter à ce moment la configuration d’intégration (URL/serveur, mode d’authentification, délais, fréquence, secret chiffré), puis exposer seulement les paramètres nécessaires dans l’interface d’administration sans jamais renvoyer le secret en clair.

Dans **Absences → Paramètres**, les administrateurs/RH voient le contrat JSON de version 1 et peuvent télécharger le modèle Excel de l’import manuel. Les dates du modèle sont formatées `YYYY-MM-DD`. Les deux représentations restent séparées : le contrat API utilise des champs normalisés (`request_id`, `employee_number`, etc.), tandis que le modèle manuel conserve les en-têtes ERP actuellement acceptés.

La même page permet de gérer les mappings de statut par tenant. Un mapping associe le couple exact `code + libellé source` à `PENDING`, `APPROVED`, `REJECTED` ou `CANCELLED`. Les couples OBR historiques restent comme valeurs par défaut pour préserver l’import existant; un mapping tenant correspondant les surcharge uniquement dans ce tenant. Les nouveaux statuts inconnus apparaissent comme erreurs d’import jusqu’à ce qu’un RH/administrateur configure leur correspondance. `MANAGER_APPROVED` ne peut pas être choisi car ce statut relève du circuit AMS, pas d’une décision ERP. Les mappings appliqués aux absences déjà importées ne réécrivent pas rétroactivement ces demandes; une prochaine importation mettra à jour les statuts selon le mapping courant.

Le connecteur doit conserver une frontière nette entre les données du fournisseur et le domaine AMS. L’identifiant source doit être qualifié par le fournisseur/tenant afin d’éviter les collisions. Le rapprochement employé doit rester explicite et strict : matricule exact selon une normalisation approuvée, avec mise en quarantaine des correspondances absentes ou ambiguës — jamais de rapprochement approximatif automatique.

## Idempotence, reprise et cohérence

- Une même demande ou un même événement peut être reçu plusieurs fois sans créer de doublon ni ajouter plusieurs fois les mêmes effets.
- Le curseur n’avance qu’après validation et persistance réussie des changements couverts. Une erreur partielle doit permettre de rejouer sans perte.
- Traiter chaque page dans une transaction AMS; si la cohérence entre données et curseur ne peut pas être garantie dans la même transaction, stocker un état de reprise explicite et rendre le traitement rejouable.
- Enregistrer l’identifiant d’événement ou le curseur traité, l’heure du dernier succès et le résultat de la dernière réconciliation. Ne jamais journaliser un jeton, un mot de passe, un motif sensible ou une réponse brute contenant des données personnelles.
- Prévoir une réconciliation périodique limitée à une fenêtre glissante pour détecter les changements tardifs, même si les notifications sont utilisées.
- Ne jamais remplacer les décisions AMS sur les empêchements AMS avec les données ERP; les deux sources et leurs circuits de validation restent séparés.

## Sécurité et exploitation

- Réseau privé, TLS avec vérification du certificat, règles pare-feu minimales et compte de service dédié en lecture seule.
- Secret dans le stockage chiffré d’intégrations AMS ou dans le coffre de secrets institutionnel; droits de rotation et de révocation documentés.
- Connecteur activable/désactivable par un administrateur autorisé, désactivé par défaut; aucun appel ERP pendant un rendu de page utilisateur.
- Délai de connexion et de lecture borné, reprise avec attente exponentielle et limite de tentatives; respecter les quotas ERP.
- Verrou empêchant deux synchronisations simultanées; journaliser les compteurs, le curseur et un identifiant d’exécution sans exposer de secrets.
- Alertes sur échec, retard de synchronisation, curseur bloqué, volume anormal et trop grand nombre de lignes non rapprochées.
- Le mode simulation doit montrer les changements prévus sans écrire d’absence ni avancer le curseur.

## Déploiement progressif et critères d’acceptation

1. Réunion avec le propriétaire ERP et l’équipe réseau/sécurité; obtenir le contrat de données, l’API supportée et un environnement de test. Comparer les noms/statuts du contrat publié dans AMS avec la réponse réelle.
2. Comparer plusieurs exports ERP représentatifs avec les demandes de l’ERP, y compris modifications, annulations, périodes multi-jours et demi-journées.
3. Implémenter l’adaptateur et les tests de mapping sans connexion de production; tester données malformées, doublons, pagination, expiration des identifiants et erreurs réseau.
4. Faire tourner le connecteur en simulation, puis en lecture seule parallèle à l’import manuel; comparer les écarts avec les RH.
5. Activer progressivement la synchronisation en production avec surveillance et procédure de retour à l’import fichier.
6. Garder l’import manuel disponible jusqu’à validation de plusieurs cycles opérationnels et d’un plan de reprise/restauration.

Le passage en production exige notamment : aucune écriture vers ERP; reprises sans perte ni doublon; annulations ERP propagées explicitement; données d’employés non résolues mises en quarantaine; calculs de présence et prévisions cohérents avec les RH; journal d’audit exploitable; secrets absents des logs; et retour à l’import fichier testé.

## Décisions à obtenir avant de coder

1. Le fournisseur autorise-t-il une API, des vues SQL en lecture seule ou seulement des exports?
2. Quel mécanisme d’authentification et quelle connectivité réseau sont officiellement supportés?
3. Quel champ est l’identifiant immuable d’une demande et quel champ permet un export incrémental fiable?
4. Quels statuts finaux et codes d’annulation font foi, et les annulations restent-elles consultables?
5. Les colonnes `Time In` / `Time Out` représentent-elles bien la plage de congé pour les demi-journées?
6. Quelle fréquence de synchronisation est attendue et qui reçoit les alertes opérationnelles?

Sans ces réponses, AMS doit rester sur l’import contrôlé par fichier; choisir arbitrairement une table, un statut ou une fréquence risquerait de créer des erreurs de paie ou de présence.
