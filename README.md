# Bouffe — Gestion des repas de la semaine

Application web locale (WampServer) pour planifier les repas de la semaine à deux,
gérer un carnet de recettes et générer automatiquement la liste de courses.

## Sommaire de l'analyse

| Document | Contenu |
|---|---|
| [docs/01-choix-techniques.md](docs/01-choix-techniques.md) | Stack retenue, justification, alternatives écartées, prérequis Wamp |
| [docs/02-fonctionnalites.md](docs/02-fonctionnalites.md) | Fonctionnalités détaillées par module, règles de gestion |
| [docs/03-modele-donnees.md](docs/03-modele-donnees.md) | Schéma de base de données (MariaDB), diagramme, contraintes |
| [docs/04-plan-developpement.md](docs/04-plan-developpement.md) | Arborescence, découpage en lots, questions ouvertes |
| [docs/05-evolutions-convives-stock.md](docs/05-evolutions-convives-stock.md) | Évolutions : convives & invités, stock, suggestions, péremption (lots 6 à 10) |
| [docs/06-version-2.md](docs/06-version-2.md) | **Version 2** : bilan de la V1, nouveaux modules 12 à 21, règles R13 à R22, lots 11 à 20, questions Q16 à Q27 |
| [docs/07-version-3.md](docs/07-version-3.md) | **Version 3** : stock toujours juste, dépenses, tickets de caisse, plusieurs foyers, mise en ligne, partage entre foyers (lots 21 à 27) |
| [docs/08-version-4.md](docs/08-version-4.md) | **Version 4** : confort sur téléphone, apparence, portions, assistant, séjours, écran de cuisine (lots 28 à 36) |
| [docs/09-acces-distant.md](docs/09-acces-distant.md) | Accès à Bouffe depuis l'extérieur |
| [docs/10-mise-en-ligne-ovh.md](docs/10-mise-en-ligne-ovh.md) | Mise en ligne chez OVH (hébergement mutualisé) |
| [docs/12-version-5.md](docs/12-version-5.md) | **Version 5** (proposition) : bilan de la V4, modules 37 à 42, règles R38 à R45, questions Q52 à Q60 |
| [docs/INSTALL.md](docs/INSTALL.md) | **Installation sous WampServer** |
| [docs/11-git-et-tests.md](docs/11-git-et-tests.md) | **Git**, tests automatiques sur GitHub et mise en ligne depuis Git (lot 36) |
| [docs/lots/](docs/lots/) | Avancement détaillé de chaque lot |

## Synthèse en 30 secondes

- **Back-end** : PHP 8.4 + **Laravel 13** (Eloquent ORM, migrations, validation, tests).
- **Interface** : Blade + **Livewire 4** (écrans réactifs sans API REST ni SPA) + **Alpine.js** (fourni avec Livewire) + `wire:sort` (Livewire 4) pour le glisser-déposer.
- **CSS** : **Tailwind CSS 4** compilé par Vite (assets précompilés fournis : Node.js facultatif).
- **Base** : **MariaDB** (fournie par Wamp) — compatible MySQL 8.
- **Hébergement** : VirtualHost Wamp `http://bouffe.local` pointant sur `Bouffe/src/public`.
- **Modules** : Ingrédients & rayons → Recettes → Planning hebdo → Liste de courses (agrégée, convertie, triée par rayon, cochable sur téléphone).
- **Version 1.0** (lot 5) : sauvegardes automatiques et restauration, accès depuis les téléphones de la maison.

## Organisation du dossier

```
Bouffe/
├── README.md        ← ce fichier
├── .github/         ← tests automatiques sur GitHub (lot 36, depuis outils/github-actions)
├── docs/            ← analyse, choix, installation, suivi des lots
│   └── lots/
└── src/             ← projet Laravel
```
