# Lot 43 — Nutrition et catalogue Ciqual

**Objectif** : savoir si une recette est équilibrée sans rien saisir. La table Ciqual 2025 de
l'Anses est livrée avec Bouffe. Un catalogue d'environ 1 100 ingrédients s'appuie dessus. Chaque
recette reçoit un **Nutri-Score estimé** (A à E) et des **repères par portion**. La semaine en tient
compte.

C'est un lot **hors V5**, demandé avant de mettre les données du PC en ligne. Il répond à trois
choix :

- environ 1 200 ingrédients, des produits de base jusqu'aux produits transformés courants ;
- le Nutri-Score estimé **et** les repères par portion ;
- la prise en compte dans l'équilibre de la semaine et dans « Remplir la semaine ».

**Statut** : ✅ développé et vérifié en local. ⏳ À valider sur ton poste.

- **1 082 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+16).
- **118 vérifications dans le navigateur** passent (+8) : quatre nouvelles, sur téléphone (sombre)
  et sur ordinateur (clair) :
  - la fiche recette, avec sa lettre, ses repères et « Pourquoi » ;
  - la liste des recettes filtrée sur « A ou B » ;
  - l'équilibre de la semaine ;
  - Paramètres › Nutrition.
- **Une migration**, qui **ajoute** des colonnes et une table :
  - `nutrition_foods.food_subgroup` ;
  - `ingredients.ciqual_source` ;
  - `recipes.nutri_score`, `recipes.nutrition` et `recipes.nutrition_at` ;
  - la table `reference_versions`.
- **Deux fichiers de données** dans `src/database/data` :
  - `ciqual-2025.csv` (1,4 Mo) : ton fichier `TableCiqual-2025_FR_2025_11_03.csv`, recopié tel quel ;
  - `catalogue-ciqual.csv` : 1 123 ingrédients.

  `bouffe:deploy` les applique tout seul, une fois, en quelques secondes.

## Mise à jour depuis le lot 42

Les correctifs de la CI (`openssl.cnf`, `phpunit.xml`) et `outils/ovh/installer.sh` sont peut-être
encore à envoyer. Si c'est le cas, envoie-les d'abord sur `main` :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git add src/resources/openssl/openssl.cnf src/phpunit.xml outils/ovh/installer.sh docs/13-premiere-mise-en-ligne.md
git commit -m "CI : clés OpenSSL sous PHP 8.3, maintenance en mémoire pendant les tests ; script OVH"
git push
git switch -c lot-43-nutrition
```

Une fois les fichiers du lot déposés, ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande fait quatre choses :

1. elle sauvegarde la base ;
2. elle applique la migration ;
3. elle importe la table Ciqual (3 484 aliments) et le catalogue ;
4. elle recalcule toutes les recettes.

Elle affiche une ligne « Données nutritionnelles (Ciqual) et catalogue » avec le nombre
d'ingrédients ajoutés et de recettes recalculées. Les fichiers construits de l'interface
(`src/public/build`) sont livrés avec le lot.

Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 43 — nutrition et catalogue Ciqual"
git push -u origin lot-43-nutrition
```

**Ensuite seulement**, la mise en ligne : la sauvegarde que tu reprendras sur OVH contient alors
le catalogue, les associations et les lettres déjà calculées (document 13, étape 4).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 43.1 | **Table Ciqual livrée** | Le fichier de l'Anses fait partie du dépôt et s'importe tout seul au déploiement, à `db:seed` et par `php artisan bouffe:ciqual` (sans argument : le fichier livré ; avec un chemin : une autre édition). Chaque fichier n'est appliqué qu'une fois par version (empreinte notée dans `reference_versions`). **Correction** : l'import lisait l'énergie dans la colonne en **kJ** et les fibres dans une colonne d'énergie ; il prend maintenant les kcal du règlement UE et les « Fibres alimentaires ». Le sous-groupe Ciqual (« légumes », « fruits »…) est gardé. | ✅ |
| 43.2 | **Catalogue de 1 123 ingrédients** | Les 154 ingrédients de départ, chacun avec un aliment choisi à la main, et 969 ingrédients tirés de Ciqual. Chacun a un nom court, un pluriel quand il le faut, un rayon (fruits et légumes, boucherie, poissonnerie, crèmerie, fromages, épicerie, conserves, surgelés, boissons…) et une unité. Il y a les produits de base, les conserves, les surgelés, les sauces, les pains, les biscuits et la charcuterie. Ne sont pas repris : les plats préparés, les produits cuits, les aliments pour bébés et les spécialités prélevées aux Antilles. Un ingrédient qui existe déjà garde son nom, son rayon, son unité et ses réglages : seul son aliment est posé, et sa densité ou le poids d'une pièce s'ils manquent. | ✅ |
| 43.3 | **Correspondance plus juste** | Un ingrédient créé (formulaire de recette, import…) reçoit tout de suite son aliment : d'abord le catalogue (« Tomates cerises » → tomate cerise crue), puis le nom de l'aliment, **mot entier** (« Poire » ne donne plus « Poireau »). Un choix fait dans Paramètres › Nutrition est marqué « choisi à la main » et n'est plus jamais remplacé. « Détacher » est aussi un choix. | ✅ |
| 43.4 | **Calcul plus complet** | Une **pincée** compte 0,4 g. Un **brin** d'une herbe vendue en botte compte 1 g. Un **sachet, un pot, une boîte** se pèsent avec le poids d'une pièce de l'ingrédient (ajouté pour les boîtes et pots du catalogue). Une ligne **sans quantité** (« sel, à convenance ») n'est pas comptée mais n'empêche plus le calcul ; elle est citée : « Non compté (sans quantité) : sel ». | ✅ |
| 43.5 | **Nutri-Score estimé** | L'algorithme du Nutri-Score (version 2024, aliments généraux) appliqué à 100 g de recette. Points à limiter : énergie, sucres, graisses saturées, sel. Points favorables : protéines, fibres, part de fruits, légumes et légumineuses. Lettre de A à E. « Pourquoi « B » ? » montre chaque ligne, sa valeur et ses points. | ✅ |
| 43.6 | **Repères par portion** | Une portion comparée à un **repas type**, c'est-à-dire un tiers des apports de référence d'un adulte : 667 kcal, 2 g de sel, 6,7 g de graisses saturées, 10 g de fibres, 16,7 g de protéines. Une barre « % d'un repas type » par ligne. Sept mentions possibles : « Léger » (moins de 400 kcal), « Copieux » (plus de 900 kcal), « Riche en sel » (plus de 2 g), « Riche en graisses saturées » (plus de 6,7 g), « Sucré » (plus de 30 g de sucres), « Bonne source de fibres » (6 g ou plus) et « Riche en protéines » (20 g ou plus). | ✅ |
| 43.7 | **Fiche et liste des recettes** | Sur la fiche : la pastille « Nutri-Score estimé » à côté des temps, et la section « Nutrition » ouverte. Sur la liste : la lettre sur chaque carte et chaque ligne, le filtre « Nutri-Score A ou B » ou « C ou mieux », et le tri « Meilleur Nutri-Score estimé ». | ✅ |
| 43.8 | **La semaine** | « Équilibre de la semaine » ajoute trois choses : le Nutri-Score moyen des repas chiffrés, la répartition des lettres, et l'énergie, le sel et les fibres par repas, comparés au repas type. « **Remplir la semaine** » donne un petit avantage aux recettes A (+4) et B (+2) et un léger frein aux recettes D (−2) et E (−4). L'effet est **doublé** quand la semaine penche déjà vers D ou pire, avec la raison « équilibre de la semaine ». Les idées pour une case suivent la même règle. | ✅ |
| 43.9 | **Paramètres › Nutrition** | La source et la licence : « Source : Anses, Table de composition nutritionnelle des aliments Ciqual 2025. Licence ouverte Etalab ». L'origine de chaque association : catalogue, automatique, choisi à la main. Une explication du Nutri-Score estimé et des repères. | ✅ |
| — | Tests | +16 tests Pest, dont un extrait réel de la table 2025 ; +8 vérifications dans le navigateur (4 sur téléphone, 4 sur ordinateur) | ✅ |

## Choix et limites

- **« Estimé », toujours.**
  - La lettre est un calcul de Bouffe avec l'algorithme officiel, pas le Nutri-Score d'un produit.
    Le logo officiel n'est jamais affiché : une pastille de couleur et le mot « estimé ».
  - Simplifications assumées :
    - les ingrédients sont pesés **crus**, sans tenir compte de l'eau perdue ou absorbée à la
      cuisson : les pâtes sèches pèsent plus lourd qu'une fois cuites ;
    - pas de règle particulière pour la viande rouge, les fromages ou les matières grasses
      ajoutées ;
    - les fruits, légumes et légumineuses sont reconnus par le sous-groupe Ciqual ; pommes de
      terre, herbes et fruits à coque n'en font pas partie.
- **Règle R19 mise à jour.** Les valeurs restent **indicatives**. Elles restent masquées en dessous de
  70 % du poids rattaché, ou quand une ligne ne se pèse pas. Ce qui change :
  - une lettre et des repères **qualifient** désormais la recette, par rapport à un repas type ;
  - ce ne sont toujours pas des conseils médicaux, et les besoins varient d'une personne à l'autre.

  Le texte de R19 est complété dans le document 06.
- **Le calcul est mis de côté** dans la recette, pour filtrer et équilibrer sans tout recalculer. Il
  est refait à plusieurs moments :
  - quand on enregistre la recette ;
  - quand on ouvre sa fiche, s'il est périmé ;
  - par les tâches régulières (étape « Nutrition des recettes », 200 recettes par passage) ;
  - pour toutes les recettes quand la table ou le catalogue changent.

  Une recette est marquée périmée dans trois cas :
  - une de ses lignes change ;
  - une de ses sous-recettes change ;
  - un de ses ingrédients change d'aliment ou de densité.
- **Le catalogue ne recrée pas ce que tu as supprimé.** Un ingrédient livré par une version du
  catalogue puis supprimé ne revient pas avec la version suivante : seuls les noms nouveaux sont
  ajoutés.
- **Associations d'avant ce lot.** Celles qui existaient sont marquées « automatique » par la
  migration, puis remplacées par celles du catalogue quand l'ingrédient y figure. Si tu avais
  choisi un aliment à la main avant ce lot, vérifie-le dans Paramètres › Nutrition › Rattachés.
  - Si la table avait été importée avec l'ancien import, l'énergie était en kJ. Elle est
    réimportée correctement au déploiement.
- **Écart** : le Nutri-Score ne tient pas compte du nombre de portions. Il se calcule pour 100 g,
  pour les portions de base de la recette.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_11_24_100000_create_lot43_nutrition.php` | Colonnes et table du lot |
| `database/data/ciqual-2025.csv` | Table Ciqual 2025 (Anses, licence ouverte Etalab) |
| `database/data/catalogue-ciqual.csv` | Catalogue : code ; nom ; pluriel ; rayon ; unité ; poids d'une pièce ; densité ; produit de base ; aliment Ciqual |
| `outils/ciqual/catalogue.py`, `ingredients-historiques.json` | Génère le catalogue depuis la table : choix à la main, ajouts, renommages, retraits |
| `app/Services/Nutrition/ReferenceData.php` | Applique la table et le catalogue une fois par version |
| `app/Services/Nutrition/CiqualCatalogue.php` | Lit le catalogue, rattache et ajoute les ingrédients |
| `app/Services/Nutrition/NutriScore.php` | Nutri-Score estimé, détail des points |
| `app/Services/Nutrition/MealBenchmarks.php` | Repères par portion (repas type) |
| `app/Services/Nutrition/RecipeNutrition.php` | Calcul mis de côté dans la recette, recalcul des recettes périmées |
| `resources/views/components/nutri-score.blade.php` | La pastille « Nutri-Score estimé » |
| `tests/Feature/Nutrition/CatalogueNutriScoreTest.php`, `tests/Fixtures/ciqual-2025-extrait.csv` | 14 tests, extrait réel de la table |
| `tests/Browser/nutrition.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- **Nutrition** :
  - `CiqualImporter` : colonnes kcal et fibres, sous-groupe, correspondance par mot entier et par le catalogue ;
  - `NutritionCalculator` : pincée, brin, sachet, pot ou boîte, lignes sans quantité, valeurs pour
    100 g, part de fruits et légumes ;
  - `NutritionFood` : attribution et sous-groupes « fruits et légumes ».
- **Commandes** : `bouffe:ciqual` (fichier livré par défaut) et `bouffe:deploy` (étape « Données
  nutritionnelles »).
- **Seeders** : `DatabaseSeeder` et `BrowserDemoSeeder` appliquent la table et le catalogue.
- **Modèles** :
  - `Ingredient` : un ingrédient créé reçoit son aliment ;
  - `Recipe` et `RecipeIngredient` : le calcul est marqué périmé.
- **Recettes** :
  - fiche (en-tête et section Nutrition) ;
  - liste (filtre, tri, pastilles) ;
  - `RecipeForm` : recalcul à l'enregistrement.
- **Planning** : `WeekBalance` (Nutri-Score moyen, repères), `WeekFiller` (bonus), bandeau de la
  semaine.
- **Paramètres › Nutrition** : source, origine des associations, choix à la main conservés.
- **Divers** :
  - `TaskRunner` : étape « Nutrition des recettes » ;
  - `config/bouffe.php` : `reference_data` ;
  - `phpunit.xml` : `BOUFFE_REFERENCE_DATA=false`, car les tests utilisent de petits extraits.
- **Tests existants ajustés** : `NutritionTest`. « Sel à convenance » n'empêche plus le calcul ;
  une boîte sans poids, si.

## À vérifier sur ton poste

- **Après `bouffe:deploy`** : Paramètres › Nutrition affiche environ 3 484 aliments et tous les
  ingrédients rattachés. Le filtre « Sans aliment » ne devrait montrer que tes ingrédients
  inconnus du catalogue.
- **Une recette** : ouvre-en une avec des quantités en grammes. Regarde la pastille, puis la
  section Nutrition et « Pourquoi ».
  - Si une recette reste « données insuffisantes », c'est souvent une ligne en boîte, en sachet ou
    en pièce sans poids. Le message dit laquelle.
- **Tes associations** : dans Paramètres › Nutrition › Rattachés, parcours tes ingrédients
  habituels. Corrige ce qui ne va pas : le choix sera conservé.
- **Recettes** : filtre « Nutri-Score A ou B », puis tri « Meilleur Nutri-Score estimé ».
- **Semaine** : ouvre « Équilibre de la semaine », puis « Remplir la semaine ». Une recette A ou B
  porte la raison « Nutri-Score A » ou, si la semaine penche, « équilibre de la semaine ».

Les captures du lot ont été faites avec la base de développement, après `bouffe:deploy` :

- les **recettes, la semaine, le stock et les comptes** sont des données d'essai **inventées** ;
- les **valeurs nutritionnelles** sont **réelles** : ce sont celles de la table Ciqual 2025.

Ce déploiement a aussi servi d'essai sur une base qui contenait les données des lots précédents. Il
a ajouté 963 ingrédients et rattaché les 163 qui existaient : 1 126 sur 1 126. Il a aussi
recalculé les 15 recettes. Un second déploiement, avec un catalogue modifié, n'a ajouté que ce qui
manquait.
