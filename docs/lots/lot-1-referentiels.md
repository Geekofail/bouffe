# Lot 1 — Référentiels

**Objectif** : les listes de référence dont dépendent les recettes (lot 2) et la liste de courses (lot 4), plus les services de calcul des quantités.

**Statut** : ✅ développé et vérifié en local (MariaDB 10.11 en MyISAM par défaut, comme Wamp) — ⏳ à valider sur ton poste.

## Mise à jour depuis le lot 0

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate        # crée les 5 nouvelles tables
php artisan db:seed        # charge les données de départ (ré-exécutable sans risque)
php artisan view:clear
php artisan test           # 136 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 1.1 | Modèle de données | Migrations `units`, `aisles`, `tags`, `meal_slots`, `ingredients` ; modèles Eloquent ; enum `UnitType` ; trait `HasSortOrder` (tri manuel) ; palette de couleurs `Palette` | ✅ |
| 1.2 | Données de départ | 16 unités, 15 rayons (ordre générique), 16 catégories, 4 créneaux (Déjeuner + Dîner actifs), **154 ingrédients** avec rayon, unité, poids moyen, produits de base. Seeders ré-exécutables : n'ajoutent que ce qui manque, n'écrasent rien | ✅ |
| 1.3 | Service `QuantityParser` | Saisie « 2 », « 1,5 », « 1/2 », « 1 ½ »… → nombre | ✅ |
| 1.4 | Service `UnitConverter` | kg ↔ g, cl ↔ ml, c. à soupe → ml ; pièces ↔ grammes via le poids moyen de l'ingrédient ; refus des conversions absurdes (boîte → g) | ✅ |
| 1.5 | Service `QuantityFormatter` | Règle R3 : « 1,25 kg », « ½ c. à soupe », « 3 pièces » ; arrondi au plus proche (recette) ou au supérieur (liste de courses) | ✅ |
| 1.6 | `NameNormalizer` | Nom normalisé (casse, accents, pluriel, œ) pour la recherche et l'anti-doublon ; détection des noms proches (faute de frappe, mot en plus) | ✅ |
| 1.7 | Écran Ingrédients | Recherche instantanée (sans accent / pluriel), filtres rayon et produits de base, pagination, création / modification en fenêtre, **refus des doublons** (« Tomates » = « tomate »), **alerte ingrédient proche** pendant la saisie, bascule « placard » en un clic, suppression | ✅ |
| 1.8 | Écran Rayons | **Ordre par glisser-déposer**, couleur, renommage en ligne, nombre d'ingrédients (lien vers la liste filtrée), suppression refusée si utilisé | ✅ |
| 1.9 | Écran Unités | Ajout / modification (famille, équivalence en g ou ml, métrique), ordre par glisser-déposer, g / ml / pièce protégées (code, famille et facteur verrouillés), suppression refusée si utilisée | ✅ |
| 1.10 | Écran Catégories | Ajout, renommage, couleur, suppression ; refus des doublons à l'accent près | ✅ |
| 1.11 | Écran Créneaux | Activer / désactiver, ordre par glisser-déposer, renommage ; au moins un créneau actif obligatoire | ✅ |
| 1.12 | Page Paramètres | Vue d'ensemble avec compteurs + navigation par onglets entre les 5 sections | ✅ |
| 1.13 | Composants UI réutilisables | `x-modal`, `x-field`, `x-badge`, `x-color-picker`, `x-empty-state`, `x-settings-nav`, pagination française | ✅ |
| 1.14 | Tests | 80 tests unitaires (services) + 33 tests fonctionnels (écrans, seeders) — 136 au total avec le lot 0 | ✅ |

## Routes ajoutées

| URL | Nom | Composant |
|---|---|---|
| `/parametres` | `settings.index` | `Settings\Index` |
| `/parametres/ingredients` | `settings.ingredients` | `Settings\Ingredients` (+ `Forms\IngredientForm`) |
| `/parametres/rayons` | `settings.aisles` | `Settings\Aisles` |
| `/parametres/unites` | `settings.units` | `Settings\Units` (+ `Forms\UnitForm`) |
| `/parametres/categories` | `settings.tags` | `Settings\Tags` |
| `/parametres/creneaux` | `settings.slots` | `Settings\MealSlots` |

## Règles de gestion implémentées

- **Conversion pièces ↔ grammes** : possible si l'ingrédient a un poids moyen et que son unité par défaut n'est pas une *autre* unité de comptage (le poids d'une « tranche » de pain de mie ne vaut pas pour une « pièce »).
- **Arrondis d'affichage** :
  - g / ml : à l'unité sous 20, aux 5 au-delà ; passage en kg / l à partir de 1 000 ;
  - cuillères : au quart (¼, ½, ¾) ;
  - pièces, boîtes, sachets… : à la demie dans une recette, **à l'entier supérieur** dans la liste de courses ;
  - une quantité positive n'est jamais affichée à 0 ;
  - pluriel des unités à partir de 2 (« 1 ½ pièce », « 2 pièces »).
- **Doublons d'ingrédients** : deux noms qui ne diffèrent que par la casse, les accents ou un s/x final sont refusés ; les noms proches déclenchent seulement un avertissement.
- **Suppressions protégées** : rayon ou unité utilisés par un ingrédient, unités de référence, dernier créneau actif. *La protection « ingrédient utilisé dans une recette » arrivera au lot 2.*

## Choix faits pendant le lot

- **`wire:sort` au lieu de SortableJS** : Livewire 4 intègre nativement le glisser-déposer (souris et tactile). SortableJS a été retiré du projet (moins de JavaScript à maintenir).
- **Enums stockés en `VARCHAR`** plutôt qu'en `ENUM` SQL : ajouter une valeur ne nécessite pas de migration, la validation est faite par l'enum PHP.
- **Colonne `search_name`** (nom normalisé, unique) : recherche insensible aux accents identique sous MariaDB et SQLite (tests), et garantie d'unicité en base.
- **Couleurs stockées comme clés** (`green`, `red`…) et non en hexadécimal : les classes Tailwind correspondantes sont connues à la compilation.
- **Environnement de vérification** : le projet est maintenant testé de mon côté sur MariaDB 10.11 configuré en MyISAM par défaut (comme ton Wamp), en plus des tests SQLite, avec des captures d'écran automatiques ordinateur + mobile.

## Prochain lot

**Lot 2 — Recettes** : fiche recette complète (ingrédients dynamiques, groupes, étapes, photo, catégories), liste en cartes, recherche et filtres, ajusteur de portions, duplication, archivage, notes par personne.
