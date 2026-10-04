# Lot 5 — Finitions MVP (version 1.0)

**Objectif** : protéger les données, utiliser Bouffe sur les téléphones de la maison et finir le parcours quotidien.

**Statut** : ✅ développé et vérifié en local (289 tests passés sous SQLite **et** MariaDB 10.11 ; sauvegarde puis restauration réelles sur MariaDB ; parcours navigateur ordinateur 1280 px et iPhone 390 px, perte de connexion et session expirée simulées) — ⏳ à valider sur ton poste.

## Mise à jour depuis le lot 4

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan view:clear
php artisan config:clear
php artisan test         # 289 tests
php artisan bouffe:backup
```

Aucune migration. Vérifier que l'extension **zip** est active (Wamp → PHP → Extensions PHP, et `php -m` en ligne de commande).
Pour les téléphones : **Paramètres → Accès téléphone**, puis [INSTALL.md § 6](../INSTALL.md).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 5.1 | Export SQL en PHP | `SqlDumper` : structure + données de toutes les tables, sans `mysqldump` (absent du PATH sous Wamp) ; données temporaires (sessions, cache) exclues | ✅ |
| 5.2 | Archive de sauvegarde | `BackupManager` : zip avec `database.sql`, photos des recettes et `manifest.json` (date, version, base, dernière migration, nombre de lignes) | ✅ |
| 5.3 | Restauration | `php artisan bouffe:restore` : choix dans la liste, confirmation, sauvegarde « Avant restauration » de l'état actuel, remplacement de la base et des photos, migrations rejouées si la sauvegarde est plus ancienne que le code. Refus d'une sauvegarde d'un autre type de base | ✅ |
| 5.4 | Commande de sauvegarde | `php artisan bouffe:backup` (`--auto` pour le Planificateur de tâches Windows) | ✅ |
| 5.5 | Sauvegarde automatique | Si la dernière a plus de 7 jours, créée après l'affichage d'une page (vérifiée au plus une fois par heure) ; les 10 dernières automatiques sont gardées, les manuelles jamais supprimées | ✅ |
| 5.6 | Écran Sauvegardes | Paramètres → Sauvegardes : état, « Sauvegarder maintenant », liste (type, taille, contenu), téléchargement, suppression avec confirmation, aide à la restauration | ✅ |
| 5.7 | Dossier configurable | `BOUFFE_BACKUP_PATH` : un dossier OneDrive ou une clé USB garde une copie hors du PC | ✅ |
| 5.8 | Accès téléphone | Paramètres → Accès téléphone : adresse du PC détectée, **QR code**, bloc VirtualHost pré-rempli à copier, pare-feu, réservation d'adresse, confirmation quand la page est déjà ouverte depuis le réseau | ✅ |
| 5.9 | Écran d'accueil du téléphone | Manifeste web, icônes 192/512 px et Apple : Bouffe s'ajoute comme une application (plein écran, icône tomate) | ✅ |
| 5.10 | Connexion perdue | Bandeau « Connexion à Bouffe perdue » quand le PC n'est plus joignable (hors Wi-Fi, PC en veille), disparaît au retour | ✅ |
| 5.11 | Session expirée | Téléphone resté longtemps en veille : rechargement silencieux au lieu de la question en anglais de Livewire | ✅ |
| 5.12 | Tableau de bord | Raccourci **Nouvelle recette**, section **« Ça fait longtemps… »** (3 recettes oubliées), rappel si la sauvegarde automatique est en retard, cartes de modules masquées sur téléphone (doublon de la barre d'onglets) | ✅ |
| 5.13 | Listes anciennes | Listes « en cours » dont la période est finie depuis plus de 30 jours passées automatiquement en « terminée » (règle 4.4) | ✅ |
| 5.14 | Paramètres | Deux nouvelles cartes et onglets, version affichée en pied de page (Bouffe 1.0) | ✅ |
| 5.15 | Recettes d'exemple | Déjà livrées au lot 2 (8 recettes) | ✅ |
| 5.16 | Tests | +35 tests (découpage SQL, sauvegarde/restauration avec textes piégés et photos, rotation, commandes, écrans, accès téléphone) — 289 au total | ✅ |

## Choix faits pendant le lot

- **Export en PHP plutôt que `mysqldump`** : aucun chemin à configurer sous Wamp, et le même code est testé automatiquement. Les textes contenant `;`, apostrophes, retours à la ligne ou émojis sont vérifiés par un test de restauration.
- **Restauration en ligne de commande uniquement** : c'est une opération qui écrase tout ; un bouton dans l'interface serait trop facile à cliquer par erreur, surtout depuis un téléphone.
- **Sauvegarde automatique sans tâche planifiée** : Wamp n'a pas de cron. La sauvegarde se déclenche en utilisant l'application ; le Planificateur de tâches Windows reste possible (`--auto`).
- **Tests de sauvegarde dans une suite séparée** (`tests/Backup`) : la restauration supprime et recrée les tables, ce qui est incompatible avec les transactions utilisées par les autres tests.
- **QR code chargé à la demande** : la bibliothèque (`qrcode`, 9 Ko compressés) n'est téléchargée que sur la page Accès téléphone.
- Pas de mode hors-ligne : il nécessite HTTPS (service worker), prévu avec la PWA en V2. Le bandeau de connexion perdue évite au moins de croire qu'un article coché hors Wi-Fi a été enregistré.

## Recommandation

Une fois l'accès téléphone ouvert, passer `APP_DEBUG=false` dans `.env` (puis `php artisan config:clear`) : en cas d'erreur, les détails techniques ne sont plus affichés aux appareils du réseau, ils restent dans `storage/logs`.

## Prochain lot

**Lot 6 — Convives & invités** (voir [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md)) : convives par repas, carnet d'invités et contraintes alimentaires, portions et restes recalculés.
