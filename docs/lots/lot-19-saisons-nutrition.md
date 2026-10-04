# Lot 19 — Saisons et nutrition

**Objectif** : manger de saison et équilibré sans calcul.

**Statut** : ✅ développé et vérifié en local (658 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 950 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), 17.1, 17.4, 17.5, 13.7 et règles **R18** et **R19**.

## Mise à jour depuis le lot 18

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
php artisan db:seed --class=SeasonSeeder    # mois de saison de départ
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 17.1 | **Saisons** (R18) | Mois de saison par ingrédient (36 fournis, tous modifiables), badge « De saison » sur la fiche et les cartes, filtre dans l'index, **bonus dans le remplissage automatique** | ✅ |
| 17.4 | **Nutrition** (R19) | Import de la table Ciqual de l'Anses (`php artisan bouffe:ciqual`), correspondance ingrédient → aliment vérifiable, valeurs par portion avec couverture, masquées sous 70 % | ✅ |
| 17.5 | **Équilibre de la semaine** | Jauges dans l'en-tête du planning : légumes, féculents, viande, poisson, sans viande ni poisson, sucré | ✅ |
| 13.7 | **Variantes** | « Version végétarienne », « sans lactose » : quelques ingrédients remplacés ou retirés, sans dupliquer la recette ; proposée d'elle-même quand un convive a la contrainte qu'elle lève | ✅ |
| 19.5 | Tests | +24 tests (saisons et ses cas limites, import Ciqual, couverture, densité, équilibre, variantes) — 658 au total | ✅ |

## R18 — ce qui rend une recette « de saison »

- Un ingrédient **sans mois renseignés n'est jamais hors saison** : c'est le bon réglage pour la
  farine, les pâtes ou la viande, et cela évite de transformer un calendrier indicatif en jugement
  permanent sur les recettes.
- Une recette est **de saison** si tous ses fruits et légumes non facultatifs le sont ce mois-là.
- Elle est **hors saison** seulement si son **ingrédient principal** (le plus lourd parmi les fruits
  et légumes) ne l'est pas. 30 g de fraises en décoration ne disqualifient pas une soupe de potiron.
- Le mois retenu est celui **du repas**, pas celui d'aujourd'hui : une recette planifiée en juin est
  jugée en juin.
- Le remplissage automatique (R15) ajoute +10 à une recette de saison et −8 à une recette hors
  saison : une préférence, pas une interdiction.

**Les valeurs de départ** couvrent 36 ingrédients (pleine saison dans la région). Elles viennent de
connaissances générales, **pas d'une source officielle**, et le disent à l'écran : elles se règlent
d'un clic par mois dans Paramètres → Saisons.

## R19 — nutrition : aucune donnée livrée, un import unique

Bouffe ne contient **aucune** valeur nutritionnelle. Elles viennent du fichier officiel de l'Anses,
téléchargé une fois :

1. télécharger la table sur `ciqual.anses.fr` (ou data.gouv.fr) ;
2. ouvrir le tableur et l'enregistrer au format **CSV** ;
3. `php artisan bouffe:ciqual C:\chemin\vers\ciqual.csv`

L'import est tolérant sur les intitulés de colonnes (ils changent d'une édition à l'autre) et
comprend les écritures de Ciqual : « traces » → 0, « < 0,5 » → 0,5, « - » → inconnu. Il rattache
ensuite automatiquement les ingrédients aux aliments, ce qui se vérifie et se corrige dans
Paramètres → Nutrition.

Le calcul par portion suit R19 à la lettre :

- masse → grammes ; **volume × densité** de l'ingrédient (1 par défaut, réglable) ; pièce × poids
  d'une pièce ; sinon la ligne **n'est pas comptée** ;
- la **couverture** dit quelle part du poids de la recette a pu être chiffrée ;
- en dessous de **70 %**, ou dès qu'une ligne n'est pas pesable, **rien n'est affiché** — avec la
  raison exacte. Mieux vaut pas de chiffre qu'un chiffre faux.

Tout est présenté comme indicatif. Aucune recommandation, aucun apport journalier, aucun jugement.

## 17.5 — l'équilibre de la semaine

Un panneau repliable dans l'en-tête du planning : six familles (légumes, féculents, viande, poisson,
sans viande ni poisson, sucré), avec un repère indicatif par semaine. Le classement se fait sur les
**trois ingrédients les plus lourds** de chaque recette, ce qui suffit pour dire « cette semaine,
zéro poisson et six repas avec viande ». Des jauges, pas des notes.

## 13.7 — les variantes

Une variante ne duplique pas la recette : elle dit seulement ce qui change. Sur la fiche, un
sélecteur « Recette d'origine / Version végétarienne » remplace les lignes concernées à leur place,
signalées « (variante) ». Les étapes, les temps et la photo restent ceux de la recette.

Quand une variante déclare la contrainte qu'elle lève (allergie, n'aime pas, régime) et qu'un
convive du repas a cette contrainte, elle est **proposée d'elle-même** sur la fiche ouverte depuis
le planning — et seulement dans ce cas, pour qu'une suggestion reste un signal et pas du bruit.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_01_100000_create_lot19_tables.php` | `season_months`, `ciqual_code`, `density`, `nutrition_foods`, `recipe_variants`, `recipe_variant_swaps` |
| `database/seeders/SeasonSeeder.php` | Les 36 saisons de départ |
| `app/Services/Seasons/SeasonCalendar.php` | R18 : état d'une recette, bonus du remplissage |
| `app/Services/Nutrition/CiqualImporter.php` | Import du fichier officiel et correspondance |
| `app/Services/Nutrition/NutritionCalculator.php` | R19 : grammes, valeurs par portion, couverture |
| `app/Services/Planning/WeekBalance.php` | Les six jauges de la semaine (17.5) |
| `app/Services/Recipes/VariantService.php` | Lignes d'une variante, suggestion automatique (13.7) |
| `app/Console/Commands/CiqualImportCommand.php` | `php artisan bouffe:ciqual` |
| `app/Livewire/Settings/Seasons.php`, `Nutrition.php`, `app/Livewire/Recipes/Variants.php` + vues | Les trois écrans |
| `app/Models/NutritionFood.php`, `RecipeVariant.php`, `RecipeVariantSwap.php` | Modèles |
| `tests/Feature/Seasons/SeasonTest.php`, `tests/Feature/Nutrition/NutritionTest.php`, `tests/Fixtures/ciqual-extrait.csv` | 24 tests |

Fichiers modifiés : `WeekFiller` (bonus de saison), `Recipes\Show` et sa vue (badge, nutrition, variantes), `Recipes\Index` et la carte (filtre et badge), `Planner\Week` et sa vue (équilibre), `Ingredient` (nouveaux champs), `Recipe` (relation variantes), navigation des paramètres, icônes.

## À vérifier sur ton poste

- Paramètres → **Saisons** : les 36 ingrédients datés, et le bloc « De saison en octobre ». Ajuste ce qui ne correspond pas à ce que tu trouves vraiment au marché.
- **Planning → Remplir la semaine** : les propositions doivent maintenant mentionner « de saison » dans leurs raisons.
- Paramètres → **Nutrition** : importe la vraie table Ciqual, puis ouvre une recette bien renseignée — soit les valeurs apparaissent, soit Bouffe explique précisément ce qui manque.
- Sur une recette, bouton **Variantes** : crée « Version végétarienne », remplace un ingrédient, et vérifie le basculement sur la fiche.
