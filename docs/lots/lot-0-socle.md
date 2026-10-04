# Lot 0 — Socle technique

**Objectif** : une application Laravel installable sous Wamp, avec connexion, mise en page responsive et navigation vers les futurs modules.

**Statut** : ✅ terminé — installé sous Wamp, 23 tests verts.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 0.1 | Projet Laravel 13 | Squelette Laravel 13, `composer.json` (Livewire 4, Pest 4, plateforme PHP 8.3), `.env.example` pour Wamp/MariaDB, fuseau `Europe/Luxembourg`, suppression des fichiers inutiles du squelette | ✅ |
| 0.2 | Localisation française | `APP_LOCALE=fr`, traductions `lang/fr` (auth, validation, pagination, mots de passe), noms de champs en français, dates en français | ✅ |
| 0.3 | Front-end | Tailwind CSS 4 (couleurs « tomate » et « basilic », classes `btn`, `form-input`, `card`…), Livewire 4 + Alpine.js, **assets précompilés** dans `public/build` | ✅ |
| 0.4 | Mise en page | Layout principal : barre supérieure (navigation desktop, utilisateur, déconnexion) + **barre d'onglets en bas sur mobile** ; layout invité (connexion) ; navigation SPA `wire:navigate` ; notifications éphémères (`x-flash`) ; composants `x-icon`, `x-page-header`, `x-coming-soon`, logo et favicon | ✅ |
| 0.5 | Authentification | Écran de connexion Livewire, « rester connecté » (coché par défaut), limitation à 5 tentatives, déconnexion, redirections invité ↔ connecté, **pas d'inscription publique** | ✅ |
| 0.6 | Gestion des comptes | Commande `php artisan bouffe:user` (création / changement de mot de passe, interactive ou avec options) | ✅ |
| 0.7 | Pages des modules | Accueil (salutation, raccourcis vers les modules) + pages Recettes, Planning, Courses, Paramètres en attente de leur lot | ✅ |
| 0.8 | Pages d'erreur | 403, 404, 419 (session expirée), 429, 500, 503 en français | ✅ |
| 0.9 | Tests | Pest : connexion (succès, échec, champs requis, blocage, mémorisation, redirections, déconnexion), accès aux pages, locale FR, page 404, commande `bouffe:user` | ✅ |
| 0.10 | Documentation | `docs/INSTALL.md` (Wamp, PHP CLI, base, VirtualHost, accès téléphone, OVH) | ✅ |

## Routes

| URL | Nom | Composant | Accès |
|---|---|---|---|
| `/connexion` | `login` | `App\Livewire\Auth\Login` | invité |
| `/` | `dashboard` | `App\Livewire\Dashboard` | connecté |
| `/recettes` | `recipes.index` | `App\Livewire\Recipes\Index` | connecté |
| `/planning` | `planner.week` | `App\Livewire\Planner\Week` | connecté |
| `/courses` | `shopping.index` | `App\Livewire\Shopping\Index` | connecté |
| `/parametres` | `settings.index` | `App\Livewire\Settings\Index` | connecté |
| `POST /deconnexion` | `logout` | `LogoutController` | connecté |

URLs en français, noms de routes et code en anglais (conventions Laravel).

## Choix faits pendant le lot

- **Composants Livewire « classe »** (`app/Livewire/*.php` + `resources/views/livewire/*.blade.php`) plutôt que les composants mono-fichier par défaut de Livewire 4 : structure proche de la séparation ViewModel / Vue, meilleure prise en charge par l'IDE, pas d'emoji dans les noms de fichiers (Windows). Configuré dans `config/livewire.php` pour `php artisan make:livewire`.
- **Alpine.js chargé par Livewire** (et non recompilé avec Vite) : les mises à jour de Livewire via Composer n'imposent pas de recompiler le JS.
- **Polices système** (Segoe UI sous Windows) : aucun appel à un service de polices externe, fonctionne hors connexion.
- **Assets compilés versionnés** dans `public/build` : Node.js n'est pas nécessaire pour faire tourner l'application (ni sous Wamp, ni plus tard chez OVH).
- **File d'attente synchrone** (`QUEUE_CONNECTION=sync`) : pas de worker à lancer sous Wamp.
- **Aucun compte dans les seeders** : pas de mot de passe dans le code.

## Arborescence ajoutée / modifiée

```
src/
├── app/
│   ├── Console/Commands/ManageUserCommand.php
│   ├── Http/Controllers/Auth/LogoutController.php
│   └── Livewire/
│       ├── Auth/Login.php
│       ├── Dashboard.php
│       ├── Planner/Week.php
│       ├── Recipes/Index.php
│       ├── Settings/Index.php
│       └── Shopping/Index.php
├── bootstrap/app.php                 redirections invité / connecté
├── config/app.php, config/livewire.php
├── lang/fr/                          auth, pagination, passwords, validation
├── public/build/                     CSS/JS compilés
├── public/favicon.svg
├── resources/
│   ├── css/app.css                   thème Tailwind + classes de composants
│   └── views/
│       ├── components/               app-logo, coming-soon, flash, icon, page-header
│       ├── errors/                   layout + 403, 404, 419, 429, 500, 503
│       ├── layouts/                  app, guest
│       └── livewire/                 vues des composants
├── routes/web.php
└── tests/                            Pest.php, Feature/Auth/LoginTest.php, NavigationTest.php, ManageUserCommandTest.php
```

## Prochain lot

**Lot 1 — Référentiels** : unités, rayons (ordre par glisser-déposer), catégories, créneaux, ingrédients, et services `UnitConverter` / `QuantityFormatter` avec leurs tests.
