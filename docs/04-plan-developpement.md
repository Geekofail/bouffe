# 04 — Plan de développement

## 1. Arborescence cible (projet Laravel)

```
Bouffe/src/
├── app/
│   ├── Enums/                 UnitType, Difficulty, MealType, ListStatus, ItemOrigin
│   ├── Models/                Recipe, Ingredient, Unit, Aisle, Tag, MealSlot,
│   │                          PlannedMeal, ShoppingList, ShoppingListItem, …
│   ├── Services/
│   │   ├── QuantityScaler.php       R1 — mise à l'échelle des portions
│   │   ├── UnitConverter.php        conversions masse/volume/pièce
│   │   ├── QuantityFormatter.php    R3 — arrondis et affichage FR
│   │   ├── ShoppingListGenerator.php R2/R4/R5 — agrégation + provenance
│   │   ├── ShoppingListSynchronizer.php  4.3 — régénération
│   │   └── MealSuggester.php        suggestions / remplissage
│   ├── Livewire/
│   │   ├── Dashboard.php
│   │   ├── Recipes/ (Index, Show, Form)
│   │   ├── Planner/ (Week, MealPicker)
│   │   ├── Shopping/ (Show, Generate, History)
│   │   └── Settings/ (Ingredients, Units, Aisles, Tags, Slots, Recurring)
│   └── Console/Commands/BackupCommand.php
├── database/ (migrations, seeders, factories)
├── resources/ (views Blade, css/app.css Tailwind, js/app.js)
├── lang/fr/
├── routes/web.php
└── tests/ (Unit : services ; Feature : écrans Livewire)
```

## 2. Découpage en lots

Chaque lot se termine par une version **utilisable** et des tests verts.

| Lot | Contenu | Livrable |
|---|---|---|
| **0 — Socle** ✅ ([détail](lots/lot-0-socle.md)) | Création du projet Laravel 13, Livewire 4, Tailwind 4, Pest ; base `bouffe` ; VirtualHost `bouffe.local` ; layout (menu, responsive) ; locale FR ; authentification simple 2 comptes | Page d'accueil vide accessible sur `http://bouffe.local` |
| **1 — Référentiels** ✅ ([détail](lots/lot-1-referentiels.md)) | Migrations + seeders : unités, rayons (ordre par glisser-déposer), tags, créneaux, ingrédients (CRUD, recherche, doublons) ; services `UnitConverter` + `QuantityFormatter` testés | Écrans Paramètres fonctionnels |
| **2 — Recettes** ✅ ([détail](lots/lot-2-recettes.md)) | CRUD recette complet (ingrédients dynamiques, groupes, étapes, photo, tags), liste en cartes, recherche/filtres, fiche avec ajusteur de portions, duplication, archivage, notes par personne ; `QuantityScaler` testé | Carnet de recettes utilisable |
| **3 — Planning** ✅ ([détail](lots/lot-3-planning.md)) | Grille semaine, ajout recette/restes/texte libre, portions, glisser-déposer, copier une semaine, assistant restes, « cuisiné ✓ », historique, suggestions simples | Planning utilisable |
| **4 — Liste de courses** ✅ ([détail](lots/lot-4-liste-de-courses.md)) | `ShoppingListGenerator` (TDD sur les règles R1–R5), écran liste mobile, articles manuels et récurrents, placard, provenance, cocher + polling, régénération, impression, copie texte | **MVP complet** |
| **5 — Finitions MVP** ✅ ([détail](lots/lot-5-finitions.md)) | Tableau de bord, sauvegarde (`bouffe:backup` + bouton), accès téléphone sur le réseau local, recette d'exemples, polissage UX | Version 1.0 |
| **6 — Convives & invités** ✅ ([détail](lots/lot-6-convives-invites.md)) | Voir [05-evolutions-convives-stock.md](05-evolutions-convives-stock.md) : convives par repas, carnet d'invités, contraintes alimentaires, restes recalculés | Repas avec invités |
| **7 — Stock : saisie et consultation** ✅ ([détail](lots/lot-7-stock.md)) | Emplacements, lots, écran Stock, ranger les courses, consommer / jeter | Stock tenu à jour |
| **8 — Péremption & alertes** ✅ ([détail](lots/lot-8-peremption-alertes.md)) | Date effective, niveaux, badge, tableau de bord | Alertes péremption |
| **9 — Stock ↔ planning & courses** ✅ ([détail](lots/lot-9-stock-planning-courses.md)) | Déduction au « mangé », liste de courses qui déduit le stock, stock minimum, inventaire | Stock quasi automatique |
| **10 — Suggestions depuis le stock** ✅ ([détail](lots/lot-10-suggestions.md)) | Écran « Que cuisiner ? », score anti-gaspi | Suggestions |
| **11 à 20 — Version 2** (lots 11 ✅ [détail](lots/lot-11-confort.md), 12 ✅ [détail](lots/lot-12-cuisiner.md) 13 ✅ [détail](lots/lot-13-carnet.md) 14 ✅ [détail](lots/lot-14-planning.md), 15 ✅ [détail](lots/lot-15-foyer.md) 16 ✅ [détail](lots/lot-16-en-ligne-hors-ligne.md) 17 ✅ [détail](lots/lot-17-magasins-budget.md) 18 ✅ [détail](lots/lot-18-stock-sans-saisie.md) 19 ✅ [détail](lots/lot-19-saisons-nutrition.md) et 20 ✅ [détail](lots/lot-20-rappels-receptions.md)) | Voir [06-version-2.md](06-version-2.md) : confort et recherche, mode cuisine, import de recettes, planning intelligent, foyer, en ligne / hors-ligne, magasins et budget, scan, saisons et nutrition, rappels et réceptions | V2 |
| **21 à 27 — Version 3** (lot 21 ✅ [détail](lots/lot-21-stock-toujours-juste.md) ; lot 22 ✅ [détail](lots/lot-22-depenses-budget.md) ; lot 23 ✅ [détail](lots/lot-23-tickets-de-caisse.md) ; lot 24 ✅ [détail](lots/lot-24-plusieurs-foyers.md) ; lot 25 ✅ [détail](lots/lot-25-en-ligne-ovh.md), guide [10-mise-en-ligne-ovh.md](10-mise-en-ligne-ovh.md) ; lot 26 ✅ [détail](lots/lot-26-entre-foyers.md) ; lot 27 ✅ [détail](lots/lot-27-prix-magasins.md)) | Voir [07-version-3.md](07-version-3.md) : stock toujours juste, dépenses et budget réel, tickets de caisse lus automatiquement, plusieurs foyers, mise en ligne chez OVH (mutualisé), partage entre foyers, prix et magasins — **V3 terminée** | V3 |
| **28 à 36 — Version 4** (lot 28 ✅ [détail](lots/lot-28-confort-telephone.md) ; lot 29 ✅ [détail](lots/lot-29-nouvelle-apparence.md) ; lot 30 ✅ [détail](lots/lot-30-apprend-et-previent.md) ; lot 31 ✅ [détail](lots/lot-31-recettes-enrichies.md) ; lot 32 ✅ [détail](lots/lot-32-portions-justes.md) ; lot 33 ✅ [détail](lots/lot-33-assistant-culinaire.md) ; lot 35 ✅ [détail](lots/lot-35-cuisine-et-bilan.md) ; lot 34 ✅ [détail](lots/lot-34-sejours.md) ; lot 36 ✅ [détail](lots/lot-36-socle-technique.md) — version 4 terminée) | Voir [08-version-4.md](08-version-4.md) : confort sur téléphone, nouvelle apparence, apprentissages et notifications, recettes enrichies, portions selon l'appétit, assistant culinaire, séjours, écran de cuisine, socle technique (Git, tests navigateur) | V4 |
| **37 à 42 — Version 5** (lot 37 ✅ [détail](lots/lot-37-bon-ecran-bon-moment.md) ; lot 41 ✅ [détail](lots/lot-41-cuisiner-a-plusieurs.md) ; lot 38 ✅ [détail](lots/lot-38-raccourcis-voix-partage.md) ; lot 39 ✅ [détail](lots/lot-39-enfants-ecole-cantine.md) ; lot 40 ✅ [détail](lots/lot-40-recettes-adaptables.md) ; lot 42 ✅ [détail](lots/lot-42-ensemble.md) — V5 terminée) | Voir [12-version-5.md](12-version-5.md) : le bon écran au bon moment, raccourcis Siri et partage, enfants et cantine, recettes qui s'adaptent, cuisiner à plusieurs hors ligne, séjours à plusieurs foyers — ordre proposé 37 → 41 → 38 → 39 → 40 → 42 | V5 |

## 3. Démarche de développement

1. Les **services métier** (échelle, conversion, agrégation) sont écrits en premier avec leurs tests unitaires : c'est le cœur de valeur de l'application et la partie la plus sujette aux erreurs.
2. Les composants Livewire restent fins : validation + appel aux services + rendu.
3. Git : un commit par fonctionnalité, une branche par lot.
4. À la fin de chaque lot : revue ensemble, ajustements avant de passer au suivant.

## 4. Questions ouvertes (à valider avant le lot 0)

| # | Question | Proposition par défaut |
|---|---|---|
| Q1 | Connexion avec 2 comptes, ou aucune authentification (appli locale) ? | 2 comptes, session longue durée -> dans le futur il est possible que l'application soit hébergée sur un serveur web chez OVH |
| Q2 | Accès depuis les téléphones sur le Wi-Fi de la maison (liste de courses en magasin = hors Wi-Fi, donc il faudrait la PWA hors-ligne ou une copie texte) ? | Oui en Wi-Fi + copie texte/impression ; PWA hors-ligne en V2 |
| Q3 | Créneaux à gérer : déjeuner + dîner seulement, ou aussi petit-déjeuner / goûter ? | Déjeuner + Dîner actifs, les autres activables |
| Q4 | Semaine du lundi au dimanche, et jour habituel des courses ? | Lundi → dimanche ; génération sur plage personnalisable |
| Q5 | Versions installées dans Wamp (PHP, MariaDB/MySQL), Composer et Node déjà présents ? | À vérifier au lot 0 |
| Q6 | Photos des recettes nécessaires dès le MVP ? | Oui (optionnelles) |
| Q7 | Import de recettes depuis des sites web : important dès le début ? | V2 |

> Questions Q8 à Q15 (convives, stock, péremption) : voir [05-evolutions-convives-stock.md](05-evolutions-convives-stock.md#questions-ouvertes).
