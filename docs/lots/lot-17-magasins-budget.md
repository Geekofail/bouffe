# Lot 17 — Magasins et budget

**Objectif** : liste dans l'ordre du magasin, coût de la semaine connu.

**Statut** : ✅ développé et vérifié en local (609 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 950 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), 15.3, 15.6, 15.7, 15.8, 17.2, 17.3 et règle **R17**.

## Mise à jour depuis le lot 16

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

(ou, à l'ancienne : `php artisan migrate` puis `php artisan optimize:clear`, et `php artisan test` pour les 609 tests)

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 15.3 | **Magasins** | Paramètres → Magasins : plusieurs magasins, chacun avec **son ordre de rayons** (glisser-déposer) et ses rayons masqués. La liste de courses se range dans l'ordre du magasin choisi ; un article peut être réservé à un magasin (« seulement au marché ») | ✅ |
| 15.6 | **Articles fréquents** | Sous le champ d'ajout : « Souvent acheté : Yaourt nature 2× · Pâtes 2× ». Ingrédients cochés au moins deux fois ces deux derniers mois et absents de la liste ; un clic les ajoute | ✅ |
| 15.7 | **Prix** | Champ « Prix payé » dans la fiche d'un article. Le prix est gardé sur l'article (budget) **et** relevé dans l'historique de l'ingrédient, ce qui met à jour son prix de référence | ✅ |
| 17.2 | **Coût des recettes** (R17) | Coût par portion sur la fiche recette et sur les cartes de l'index ; coût de la semaine dans l'en-tête du planning ; filtre « ≤ 2 / 3 / 5 € par portion » | ✅ |
| 17.3 | **Budget** | Paramètres → Budget : budget mensuel facultatif, dépenses du mois, projection de fin de mois, graphique des 6 derniers mois | ✅ |
| 15.8 | **Liste « quand je passe »** | Bloc sur la page Courses : piles, ampoule, cadeau… versés tout seuls dans la prochaine liste créée | ✅ |
| 17.6 | Tests | +25 tests (conversion des prix, coûts et prix manquants, ordre par magasin, « ailleurs », articles fréquents, quand je passe, budget) — 609 au total | ✅ |

## Règle R17 — comment un prix devient un coût

1. **Un prix est relevé tel qu'on l'a payé** : « 2,49 € les 500 g », « 3,60 € les 12 œufs ».
2. Il est ramené à l'**unité de base** de sa famille : €/g, €/ml, ou €/pièce pour ce qui se compte.
   C'est ce qui permet de comparer un prix saisi au kilo et un prix saisi aux 500 g.
3. Ce prix devient le **prix de référence** de l'ingrédient — sauf s'il a été **fixé à la main**
   (Paramètres → Ingrédients) : dans ce cas, aucun passage en caisse ne l'écrase.
4. Le coût d'une recette = Σ (quantité mise à l'échelle, convertie, × prix de référence).
5. **Ce qu'on ne sait pas chiffrer est annoncé, jamais deviné** :
   - un produit de base sans quantité (« sel, poivre ») est **ignoré** ;
   - un ingrédient avec une quantité mais sans prix est compté **manquant** ;
   - dès qu'il en manque un, le total s'affiche « ≥ 6,40 € · 3 prix manquants ».

Le coût d'une liste additionne les prix **payés** quand ils existent (ils valent mieux qu'une estimation) et les estimations pour le reste. Les articles retirés et ceux couverts par le stock ne comptent pas : ils ne seront pas payés.

## Ce que ça change à l'usage

### En magasin

La liste suit le parcours du magasin choisi, réglé une fois pour toutes dans Paramètres → Magasins. Un rayon qu'on ne trouve pas chez Cactus peut être masqué : ses articles ne disparaissent pas, ils passent dans un bloc **Ailleurs** en bas de liste — sinon on les oublierait. Même chose pour un article marqué « seulement au marché ».

### Le prix, quand on veut

Rien n'oblige à saisir des prix. Chaque prix saisi rend les estimations un peu plus justes, et le budget un peu plus vrai. Un seul geste sert aux trois écrans (article, recette, budget).

### Le budget

Le suivi ne compte **que** les prix saisis : c'est un ordre de grandeur, pas une comptabilité, et la page le dit. Budget vide = pas de suivi, juste le total affiché.

## Réglages ajoutés

Rien dans `.env` : tout se règle dans l'application.

| Réglage | Où |
|---|---|
| Magasins et ordre des rayons | Paramètres → Magasins |
| Budget mensuel | Paramètres → Budget |
| Prix de référence d'un ingrédient | Paramètres → Ingrédients → modifier |

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_09_29_100000_create_lot17_tables.php` | `stores`, `store_aisles`, `ingredient_prices`, `standing_items`, colonnes de prix |
| `app/Models/Store.php`, `StoreAisle.php`, `IngredientPrice.php`, `StandingItem.php` | Modèles |
| `app/Services/Shopping/StoreLayout.php` | Ordre des rayons par magasin (15.3) |
| `app/Services/Pricing/PriceBook.php` | Relevés, prix de référence, conversions (R17) |
| `app/Services/Pricing/CostCalculator.php` + `Cost.php` | Coût d'une recette, d'une liste, d'une semaine |
| `app/Services/Pricing/Budget.php` | Dépenses du mois, historique, projection (17.3) |
| `app/Livewire/Settings/Stores.php` + vue | Paramètres → Magasins |
| `app/Livewire/Settings/BudgetSettings.php` + vue | Paramètres → Budget |
| `tests/Feature/Pricing/PriceBookTest.php`, `tests/Feature/Shopping/StoreTest.php` | 25 tests |

Fichiers modifiés : `ShoppingListManager` (ordre du magasin, articles fréquents, « quand je passe »), `Shopping\Show` et sa vue (magasin, prix payé, coût, suggestions), `Shopping\Index` et sa vue (quand je passe), `Recipes\Index` (filtre prix) et la carte de recette, `Recipes\Show` (coût), `Planner\Week` (coût de la semaine), `IngredientForm` et la page Ingrédients (prix de référence), `Settings` (clés `budget.monthly`), navigation des paramètres, icônes.

## À vérifier sur ton poste

- Paramètres → **Magasins** : crée « Cactus » et range les rayons dans l'ordre de ton parcours réel, puis ouvre une liste et choisis ce magasin en haut : l'ordre doit suivre.
- Sur une liste, ouvre un article et saisis un **prix payé** : le message doit confirmer le relevé, et Paramètres → Budget doit compter la dépense.
- Fiche d'une recette dont les ingrédients ont des prix : le **coût par portion** apparaît, avec « ≥ » tant qu'il manque des prix.
- Page Courses : note quelque chose dans **Quand je passe**, puis crée une nouvelle liste — l'article doit s'y trouver.
