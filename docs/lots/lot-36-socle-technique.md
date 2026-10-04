# Lot 36 — Socle technique

**Objectif** : un historique, des tests à chaque modification, un code plus facile à faire évoluer.

**Statut** : ✅ préparé et vérifié en local — ⏳ à toi de créer le compte GitHub et le dépôt (Q49),
en suivant [11-git-et-tests.md](../11-git-et-tests.md).

- **957 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+8).
- **50 vérifications dans le navigateur** passent, inchangées.
- Les pages découpées (planning, fiche recette, stock, listes de courses, accueil, collections) ont
  été **comparées avant et après** : le HTML produit est identique.
- Les deux workflows GitHub ont été rejoués ici sur une copie fraîche du dépôt (installation depuis
  zéro, Pint, tests, construction, navigateur) : tout passe. Ils ne tourneront vraiment qu'une fois le
  dépôt créé sur GitHub.
- **Aucune migration.**

Spécification : [08-version-4.md](../08-version-4.md), module 36 et §7.3.

## Mise à jour depuis le lot 31

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Ensuite, quand tu veux : créer le dépôt ([11-git-et-tests.md](../11-git-et-tests.md), §1). Ce n'est
pas nécessaire pour que Bouffe fonctionne.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 36.1 | **Git** | Tout est prêt pour un dépôt privé à la racine `C:\wamp64\www\Bouffe` (code et documentation) : `.gitignore` et `.gitattributes` à la racine (fins de ligne LF quel que soit le réglage de Windows), `src/.gitignore` complété. Ne partent **pas** dans le dépôt : `.env`, `vendor`, `node_modules`, `vendor.zip`, photos, sauvegardes, journaux, bases SQLite. Un test vérifie à chaque envoi qu'aucune clé ni mot de passe n'est dans les fichiers suivis. La marche à suivre (création, une branche par lot, retour arrière fichier par fichier) est dans le document 11 | ✅ (reste : créer le compte, Q49) |
| 36.2 | **Tests à chaque envoi** | `tests.yml` (déposé dans `outils/github-actions/`, à placer dans `.github/workflows/`) : à chaque envoi, le style du code (Pint), tous les tests sous SQLite en PHP 8.3 (la version minimum), la construction des fichiers et les tests dans le navigateur. `.github/workflows/toutes-les-bases.yml` : MariaDB 10.11 et MySQL 8.0 en PHP 8.4, chaque nuit, à chaque étiquette `mise-en-ligne-…` et à la demande | ✅ |
| 36.3 | **Mise en ligne depuis Git** | `php artisan bouffe:deploy --git` : vérifie qu'aucun fichier n'a été modifié sur le serveur, affiche les nouvelles versions, sauvegarde, maintenance, récupère `main` par **avance rapide seulement**, relance `composer install` si `composer.lock` a changé, puis la suite habituelle. `--branche=` pour une autre branche. Sans `--git`, la copie par SFTP reste possible | ✅ |
| 36.4 | **Gros fichiers découpés** | Planning, fiche recette, stock et `ShoppingListManager` découpés en parties nommées (voir ci-dessous), sans rien changer à l'écran. Style du code vérifié par **Pint** (`src/pint.json`) ; `composer lint` corrige, `composer lint:test` vérifie | ✅ |
| — | Tests | +8 tests Pest (957) : mise en ligne depuis Git sur de vrais petits dépôts (avance rapide, déjà à jour, fichier modifié sur le serveur, historiques divergents, pas de dépôt), absence de secrets | ✅ |

## Choix et limites

- **Le dépôt est à la racine `Bouffe`** (code et documentation ensemble), pas dans `src` : les
  workflows GitHub doivent être à la racine, et la documentation suit ainsi les versions du code.
- **Les fichiers construits (`src/public/build`) sont dans le dépôt** : l'hébergement mutualisé OVH
  n'a pas Node pour les reconstruire. GitHub les reconstruit de son côté pour les tests.
- **Découpage** : les composants Livewire gardent leur nom et leurs actions (rien ne change pour les
  pages ni pour les tests) ; leurs méthodes sont rangées dans des traits (`Concerns/`) par thème, et
  leurs vues dans des fichiers inclus. `ShoppingListManager` garde toutes ses méthodes publiques, pour
  la même raison.
- **Pint** suit le style Laravel, sauf une règle écartée (`fully_qualified_strict_types`) : le code
  écrit parfois `\App\…` en ligne pour une classe utilisée une seule fois, et la convertir aurait
  touché 210 fichiers sans rien apporter. 88 fichiers ont été remis en forme (espaces, `use` inutiles,
  ordre des `use`), sans changement de comportement.
- **Tests sur GitHub** : PHP 8.3 à chaque envoi (la version minimum, voir INSTALL.md), PHP 8.4 la nuit (celle d'OVH et recommandée pour Wamp).
- **Mise en ligne depuis Git** : refus de toute fusion sur le serveur. Si l'historique a divergé (un
  correctif fait directement chez OVH), la commande s'arrête et le site rouvre ; il faut alors
  reporter le correctif sur le PC.
- **Ce que je ne peux pas faire depuis ici** : créer le compte GitHub, le premier `commit` et le
  premier `push` sont à faire sur ton PC (le document 11 donne les commandes). Je ne peux pas non
  plus écrire dans le dossier `.github` (il est protégé) : les deux workflows sont déposés dans
  `outils/github-actions/`, à déplacer une fois (document 11, §1.2). Ensuite, à chaque lot,
  je te rappellerai les commandes de la branche.

## Fichiers

| Fichier | Rôle |
|---|---|
| `.gitignore`, `.gitattributes` (racine) | Ce qui part dans le dépôt, fins de ligne |
| `outils/github-actions/tests.yml` → `.github/workflows/` | Tests à chaque envoi |
| `outils/github-actions/toutes-les-bases.yml` → `.github/workflows/` | MariaDB et MySQL, la nuit et avant une mise en ligne |
| `src/pint.json` | Règles du style du code |
| `src/app/Services/System/GitUpdater.php` | Git pour `bouffe:deploy --git` |
| `src/app/Livewire/Planner/Concerns/*` | Planning : détail d'un repas, propositions, semaine entière, données |
| `src/app/Livewire/Recipes/Concerns/*` | Fiche recette : notes et planification, entre foyers, données |
| `src/app/Livewire/Stock/Concerns/*` | Stock : ajout, article, données |
| `src/app/Services/Shopping/Concerns/*` | Listes : mise à jour depuis le planning, affichage |
| `src/resources/views/livewire/planner/week/*`, `recipes/show/*`, `stock/index/*` | Vues découpées |
| `src/tests/Feature/System/GitDeployTest.php`, `RepositoryHygieneTest.php` | 8 tests |
| `docs/11-git-et-tests.md` | Mode d'emploi |

Fichiers modifiés : `DeployCommand` (option `--git`), `composer.json` (`composer lint`,
`composer lint:test`), `src/.gitignore`, les quatre gros fichiers découpés, le document 10 (mise en
ligne), et 88 fichiers remis en forme par Pint.

## À vérifier sur ton poste

- `php artisan bouffe:deploy` : tout doit être comme avant.
- Planning, fiche recette, stock, liste de courses : rien ne doit avoir changé.
- Quand tu es prêt : le document 11, §1 (compte GitHub, premier envoi), puis l'onglet **Actions**
  pour voir les premiers tests passer.
