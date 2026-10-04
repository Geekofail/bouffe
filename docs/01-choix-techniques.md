# 01 — Choix techniques

## 1. Contraintes de départ

- Exécution **locale** sous **WampServer** (Apache + PHP + MySQL/MariaDB, Windows).
- **2 utilisateurs** (Pierre et sa compagne), volume de données faible (quelques centaines de recettes au maximum).
- Stack imposée : PHP, HTML/CSS, JS éventuel, MySQL ou MariaDB.
- Besoin d'interactivité : planning en grille, ajout d'ingrédients dynamiques dans une recette, liste de courses à cocher.
- Maintenable par un développeur habitué à C# / .NET (typage, ORM, migrations, séparation des couches).

## 2. Stack retenue

| Couche | Choix | Version cible |
|---|---|---|
| Langage | PHP | 8.4 (8.3 minimum) |
| Framework PHP | **Laravel** | 13.x |
| Composants réactifs | **Livewire** | 4.x |
| JS léger | **Alpine.js** (inclus dans Livewire) | 3.x |
| Glisser-déposer | **`wire:sort`** (natif Livewire 4) | — |
| CSS | **Tailwind CSS** | 4.x |
| Build des assets | **Vite** (via Node.js LTS) | — |
| Base de données | **MariaDB** (Wamp) | 10.11+ / 11.x |
| Tests | **Pest** (sur PHPUnit) | 4.x |
| Gestionnaire de dépendances | Composer / npm | — |

### Pourquoi Laravel

- **Eloquent + migrations** : l'équivalent direct d'Entity Framework Core (modèles, relations, migrations versionnées, seeders). Le schéma de la base est dans le code, reproductible.
- **Validation, formulaires, upload de fichiers (photos de recettes), pagination, localisation FR** : tout est natif.
- **Écosystème et documentation** très larges, stable dans le temps (maintenance à long terme d'un outil perso).
- Tourne sans difficulté sous Wamp (Apache + `mod_rewrite`).
- `php artisan` fournit des commandes de maintenance (sauvegarde, génération, seed de données de départ).

### Pourquoi Livewire (plutôt que Vue/React)

- Pas besoin d'écrire une API REST **et** une SPA : les composants sont en PHP + Blade, l'état est synchronisé avec le serveur automatiquement. Pour une appli locale à 2 utilisateurs, la latence réseau est nulle : l'expérience est fluide.
- Modèle mental proche de **WPF/MVVM** : une classe PHP avec des propriétés publiques liées à la vue (`wire:model` ≈ `{Binding}`), des méthodes appelées par la vue (`wire:click` ≈ `Command`).
- Alpine.js couvre les micro-interactions purement côté client (menus, modales) ; le glisser-déposer utilise la directive native `wire:sort` de Livewire 4.

### Pourquoi Tailwind CSS

- Mise en page responsive rapide (la liste de courses doit être confortable sur téléphone).
- Aucune feuille de style « maison » à maintenir. Le CSS final est purgé et léger.
- Node.js n'est requis **que sur le poste de développement** pour compiler (`npm run build`) ; en exécution, Wamp sert des fichiers statiques.

### Pourquoi MariaDB

- Installée et activée par défaut dans Wamp, entièrement compatible avec le driver MySQL de Laravel.
- Encodage `utf8mb4` / collation `utf8mb4_unicode_ci` pour les accents et la recherche insensible à la casse (« crème » = « Creme »).

## 3. Alternatives étudiées et écartées

| Option | Raison de l'écarter |
|---|---|
| PHP « natif » sans framework | Tout serait à réécrire (routage, sécurité CSRF, validation, migrations). Dette technique rapide. |
| Symfony | Excellent mais plus verbeux et plus lourd à configurer pour un projet de cette taille. |
| Slim / Lumen | Micro-frameworks : pas d'ORM ni de moteur de vues intégrés, on reconstruit Laravel à la main. |
| Laravel + Vue/React (Inertia) | Double compétence PHP + JS framework, build JS plus complexe, sans bénéfice réel pour 2 utilisateurs en local. |
| Bootstrap | Viable, mais Tailwind donne plus de liberté sur l'ergonomie mobile de la liste de courses. |
| SQLite | Plus simple, mais MariaDB est demandée et déjà disponible dans Wamp (et consultable via phpMyAdmin). |

## 4. Prérequis sur le poste

1. **WampServer 3.3+** avec :
   - PHP **8.4** sélectionné (menu Wamp → PHP → Version) pour Apache **et** en ligne de commande (`PATH`).
   - Extensions PHP actives : `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `gd` (redimensionnement des photos), `curl`.
   - Module Apache `rewrite_module` actif.
   - MariaDB active (port 3306 par défaut, ou 3307 selon configuration Wamp).
2. **Composer** 2.x.
3. **Node.js LTS** (22 ou 24) + npm — facultatif : les CSS/JS compilés sont fournis dans `public/build`.
4. **Git** (recommandé pour versionner le projet).

## 5. Configuration d'hébergement

- Projet Laravel dans `C:\wamp64\www\Bouffe\src` (procédure complète : [INSTALL.md](INSTALL.md)).
- **VirtualHost** Wamp : `bouffe.local` → `C:\wamp64\www\Bouffe\src\public` (seul le dossier `public` est exposé ; `.env` et le code restent inaccessibles depuis le navigateur).
- Entrée dans `C:\Windows\System32\drivers\etc\hosts` : `127.0.0.1 bouffe.local` (ajoutée automatiquement par l'outil « Add a Virtual Host » de Wamp).
- **Accès depuis le téléphone (optionnel)** : autoriser le réseau local dans le VirtualHost (`Require local` → `Require ip 192.168.`) et ouvrir le port 80 dans le pare-feu Windows pour le profil « Privé ». Accès via `http://<IP-du-PC>`.

## 6. Conventions de code

- **Code en anglais** (tables, modèles, classes, méthodes) pour rester dans les conventions Laravel (pluriels, clés étrangères automatiques).
- **Interface 100 % en français** (fichiers de langue `lang/fr`, `APP_LOCALE=fr`, dates et nombres au format français : `1,5 kg`, `lundi 21 septembre`).
- Quantités stockées en `DECIMAL(10,3)` — jamais en `FLOAT` (évite les erreurs d'arrondi lors des additions de la liste de courses).
- Logique métier (mise à l'échelle des portions, conversion d'unités, agrégation de la liste) isolée dans des **classes de service** testées unitairement (`app/Services`), et non dans les composants Livewire.
- Enums PHP natifs pour les valeurs fixes (type d'unité, difficulté, statut de liste).
