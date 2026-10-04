# Lot 40 — Recettes qui s'adaptent

**Objectif** : une recette s'adapte à ce qu'on a et à qui mange. Elle propose des remplacements,
garde les notes du foyer sur ses étapes et tient compte de l'équipement de la cuisine. Elle
signale aussi les allergènes des produits du stock.

**Statut** : ✅ développé et vérifié en local. ⏳ À valider sur ton poste.

- **1 053 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+12). La migration a aussi été
  appliquée, annulée puis réappliquée sur la base de développement MariaDB, qui contient des
  données des lots précédents.
- **104 vérifications dans le navigateur** passent (+6) :
  - la page Remplacements, avec un ajout ;
  - la page Équipement : sans four, les recettes au four passent dans « Pas faisables ici » ;
  - en mode cuisine, « Pas de lait ? » avec « Je l'utilise », puis une note sur une étape.
- **Une migration**, qui **ajoute** des tables et des colonnes :
  - nouvelles tables : `ingredient_substitutions` et `recipe_step_notes` ;
  - nouvelles colonnes : `recipe_steps.uid` (rempli pour toutes les étapes existantes),
    `recipe_photos.step_uid` (déduit du numéro d'étape), `recipes.equipment`, `stays.equipment`,
    `products.allergens`, `products.traces` et `products.allergens_checked_at` ;
  - elle installe la **liste commune** de remplacements (Q58) et ajoute au catalogue les six
    ingrédients qu'elle utilise.

  `bouffe:deploy` fait une sauvegarde juste avant.

Ordre prévu (Q52) : 37 ✅ → 41 ✅ → 38 ✅ → 39 ✅ → **40** → 42.

Spécification : [12-version-5.md](../12-version-5.md), module 40 et règles R43 et R44.

## Mise à jour depuis le lot 39

Si ce n'est pas déjà fait, supprime le modèle retiré au lot 39 :

```powershell
cd C:\wamp64\www\Bouffe
git rm src/app/Models/HouseholdRestriction.php
```

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-40-recettes-adaptables
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande sauvegarde la base, applique la migration et installe les remplacements communs.
Vérifie ensuite **Paramètres › Remplacements** : la liste commune doit y être. Quand le lot te
convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 40 — recettes qui s'adaptent"
git push -u origin lot-40-recettes-adaptables
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 40.1 | **Remplacements** | **Paramètres › Remplacements** (`/parametres/remplacements`) : la liste commune (une quarantaine, par exemple « crème liquide → crème de soja, même quantité » ou « beurre → huile d'olive, × 0,8 ») et ceux du foyer. On en ajoute un (ingrédient, remplaçant, rapport, note) ; on **masque** un remplacement commun ou on **retire** un remplacement du foyer. Une **variante** (lot 33) et un échange accepté depuis l'**assistant** deviennent des remplacements **propres à la recette**. Ils sont affichés à trois endroits. En **mode cuisine**, un bloc « Pas de crème liquide ? » montre d'abord ce qui est en stock, avec la quantité ; le reste est replié. « **Je l'utilise** » l'écrit dans la note de cuisine, sans toucher à la recette (R43). Dans « **Que cuisiner ?** », un ingrédient manquant remplacé par un produit en stock rend la recette faisable (« avec crème de soja à la place »). Sur la **liste de courses**, une ligne affiche « ou : crème de soja, en stock ». Un remplacement qui heurte l'**allergie** de quelqu'un, ou ce qu'il **n'aime pas**, n'est jamais proposé. Pour une allergie, tout le groupe compte : une allergie au lait écarte aussi le beurre. | ✅ |
| 40.2 | **Notes d'étape** | Chaque étape a désormais un **identifiant stable**. En mode cuisine, « Ajouter une note à cette étape » (« notre four chauffe fort : 170 °C »). La note est montrée en mode cuisine et sur la fiche de la recette, sous l'étape. Insérer, déplacer ou supprimer une étape, ou restaurer une ancienne version, ne décale plus ni les notes ni les **photos d'étape** : elles suivent leur étape. | ✅ |
| 40.3 | **Équipement de la cuisine** | **Paramètres › Équipement** (`/parametres/equipement`) : four, micro-ondes, plaques, robot, mixeur plongeant, cuiseur vapeur, friteuse à air, multicuiseur, barbecue (Q57). La page liste les recettes « **Pas faisables ici** ». Une recette **demande** un équipement d'après les mots de ses étapes (« enfourner », « préchauffer le four », « au micro-ondes »…) ; la fiche de modification permet de corriger. Un **séjour** a son propre équipement (« pas de four au chalet »), sinon c'est celui de la maison. Les recettes impossibles sont écartées par « Remplir la semaine », « Autre idée » et la recherche du planning d'un séjour. Le choix d'un repas avertit : « Demande : four (pas dans notre cuisine) ». | ✅ |
| 40.4 | **Allergènes des produits** | Au **scan** d'un produit, ses allergènes et traces d'Open Food Facts sont gardés et affichés : « contient : lait, gluten · traces possibles : fruits à coque ». Sans information, le produit affiche « allergènes inconnus » et un bouton pour vérifier. Les produits déjà en stock sont complétés par la tâche de fond (« Allergènes des produits », 5 par passage). Un produit qui heurte quelqu'un du foyer est **signalé** sur l'écran du scan et dans la liste du stock (badge « allergène »). Au moment de **prévoir** une recette, l'alerte « « Pâte feuilletée — … » en stock : peut contenir des traces de fruits à coque — allergie de … (d'après Open Food Facts) » s'ajoute aux alertes des convives (planning, choix d'un repas, réceptions). | ✅ |
| — | Tests | +12 tests Pest ; +6 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Remplacements communs.**
  - La liste commune n'appartient à aucun foyer (`household_id` vide) : un foyer la **masque**
    entrée par entrée (réglage `substitutions.hidden`) plutôt que de la supprimer. « Remettre »
    l'annule.
  - Elle ne contient que des ingrédients du catalogue. Six y sont ajoutés s'ils manquent : crème de
    soja, boisson végétale, margarine, fécule de maïs, farine sans gluten et graines de lin moulues.
  - Ordre de proposition : propres à la recette, puis du foyer, puis communs.
- **Quantité.** Le rapport (1 pour 1 par défaut) s'applique et s'arrondit comme les portions. Elle
  n'est affichée que pour un poids ou un volume : « 4 graines de lin » pour 4 œufs n'aurait pas de
  sens, la note du remplacement l'explique (« par œuf : 10 g de graines et 3 cuillères à soupe d'eau »).
- **« Que cuisiner ? »** n'utilise un remplacement que s'il est **en stock**, et ne le retire pas
  du stock disponible pour les autres recettes proposées.
- **Liste de courses** : seuls les remplacements communs et ceux du foyer, en stock, sont proposés.
  Une même ligne peut servir plusieurs recettes, donc ceux propres à une recette n'y figurent pas.
- **Qui compte pour les remplacements** :
  - en mode cuisine, les personnes à table pour ce repas, invités compris, si le repas est
    planifié ;
  - sinon, les personnes à table d'habitude.
- **Étapes.**
  - Les notes et les photos suivent l'identifiant de l'étape. Si l'étape est supprimée, sa photo
    reste dans la recette sans étape.
  - Les **tâches réparties** (« Mes étapes », lot 41) et la correction « avec un adulte » (lot 39)
    restent sur le **numéro** d'étape.
- **Équipement.**
  - Tant que l'équipement de la maison n'est pas enregistré, **tout est disponible** : rien ne
    change pour un foyer qui ne remplit pas la page.
  - Le repérage d'une recette se fait d'après des mots, comme au lot 39. Il se corrige dans la fiche
    de modification, et la correction l'emporte.
  - Un séjour sans équipement noté suit celui de la maison.
- **Allergènes.**
  - Ils sont **indicatifs** (R44) : les messages disent « d'après Open Food Facts ».
  - Les traces n'alertent que pour une allergie. Pour un « n'aime pas », seul « contient » est
    signalé, en avertissement.
  - Une allergie notée sur un ingrédient vaut pour son **groupe** (les 14 allergènes réglementaires)
    : « lait demi-écrémé » couvre le beurre et la crème. Quelques exceptions sont connues : lait de
    coco, crème de soja, noix de muscade, « sans gluten ».
  - Seul le **code-barres** part vers Open Food Facts. Aucune donnée personnelle n'est envoyée
    (R34).
- **Q57 et Q58** sont appliquées avec leur proposition par défaut.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_11_10_100000_create_lot40_adaptable_recipes.php` | Tables et colonnes du lot, identifiants des étapes existantes |
| `database/seeders/SubstitutionSeeder.php` | Liste commune de remplacements (Q58) |
| `app/Models/IngredientSubstitution.php`, `RecipeStepNote.php` | Modèles |
| `app/Services/Recipes/Substitutions.php` | Remplacements : proposer, ajouter, masquer, écarter les allergènes (R43) |
| `app/Services/Recipes/StepNotes.php` | Notes d'étape, photos qui suivent l'étape |
| `app/Services/Recipes/KitchenEquipment.php` | Équipement : repérage, maison, séjour |
| `app/Services/Stock/ProductAllergens.php` | Allergènes des produits (R44) |
| `app/Support/AllergenGroups.php` | Les 14 groupes d'allergènes et leurs mots |
| `app/Livewire/Settings/Substitutions.php`, `Equipment.php` + vues | Pages Remplacements et Équipement |
| `resources/views/livewire/recipes/cook-substitute.blade.php` | Un remplacement en mode cuisine |
| `tests/Feature/Recipes/AdaptableRecipesTest.php` | 12 tests |
| `tests/Browser/adapt.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- **Mode cuisine et recettes** :
  - `Cook` et sa vue (remplacements, notes d'étape) ;
  - fiche de la recette (ingrédients, étapes) ;
  - `RecipeForm` et sa vue (identifiants d'étape, équipement) ;
  - `RecipeRevisions`, `RecipePhotos`, `VariantService`, `RecipeAssistant`.
- **Idées et planning** : `RecipeSuggester` et la carte d'une suggestion, `WeekFiller`,
  `MealPicker`, `GuestCompatibility`, séjours (`StayService`, `Stays/Show`, `PlansStayMeals`).
- **Stock** : `ProductLookup` (allergènes), `Stock/Scan`, liste du stock ; `TaskRunner` (tâche de
  fond).
- **Courses** : `ShoppingItemPresenter` et la ligne de la liste.
- **Données** : modèles `Product`, `Recipe`, `RecipeStep`, `RecipePhoto`, `Stay` ;
  `HouseholdData` (suppression d'un foyer) ; `Settings` ; `AppServiceProvider`.
- **Divers** : menu des paramètres, `routes/web.php`, `DatabaseSeeder` et `BrowserDemoSeeder`.
- **Tests existants ajustés** :
  - `HouseholdsTest` : 43 modèles propres à un foyer ;
  - `FamilyTest` : le test de reprise des données annule la migration du lot 39 par son chemin.

## À vérifier sur ton poste

- **Après `bouffe:deploy`** : Paramètres › Remplacements. Masque un remplacement qui ne vous va
  pas, puis ajoute-en un à vous.
- **Mode cuisine** : ouvre une recette avec de la crème ou du lait. Le bloc « Pas de … ? » montre
  ce qui est en stock. « Je l'utilise » l'ajoute à la note de cuisine.
- **Une note d'étape** : ajoute-la en mode cuisine, puis insère une étape avant elle dans la fiche
  de modification. La note doit rester sur la bonne étape.
- **Équipement** : coche seulement ce que vous avez et regarde « Pas faisables ici ». Dans un
  séjour, décoche le four, puis cherche une recette.
- **Allergènes** : scanne un produit du placard. Si quelqu'un du foyer a une allergie, prévois une
  recette qui l'utilise.

Les captures du lot ont été faites avec les données d'essai de la base de développement. La note
sur les crêpes, la crème de soja et la boisson végétale en stock, et le produit « Pâte feuilletée
pur beurre — Marque d'essai » et ses traces sont **inventés**.
