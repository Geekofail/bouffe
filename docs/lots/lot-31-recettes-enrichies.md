# Lot 31 — Recettes enrichies

**Objectif** : un carnet mieux rangé, plus illustré, et des repas complets sans stress.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **949 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+11).
- **50 vérifications dans le navigateur** passent (+6) : les collections (liste et une collection) et
  un parcours « cuisiner le repas complet depuis l'accueil, puis l'historique d'une recette », sur
  iPhone et sur ordinateur.
- **Une migration** : cinq tables (`collections`, `collection_recipe`, `recipe_photos`,
  `recipe_share_links`, `recipe_revisions`). Rien n'est modifié dans les données existantes.

Spécification : [08-version-4.md](../08-version-4.md), module 31.

## Mise à jour depuis le lot 32

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 31.1 | **Collections** | **Recettes › Collections** (`/recettes/collections`) : des regroupements libres, en plus des catégories (« Noël », « Recettes de mamie »). Une recette peut être dans plusieurs collections. Sur la fiche recette, **Plus › Collections…** coche les collections ou en crée une ; la fiche affiche « Dans : Noël · Recettes de mamie ». Dans une collection : ajouter une recette par recherche, monter / descendre, retirer, renommer, supprimer (les recettes restent). **Imprimer en carnet** reprend le carnet familial (26.9), dans l'ordre de la collection. **Partager avec les proches** : la collection apparaît chez les foyers reliés, avec les recettes qu'ils peuvent déjà lire ; si elle contient des recettes privées, Bouffe le dit et propose de les ouvrir en même temps | ✅ |
| 31.2 | **Plusieurs photos** | Section **Photos** de la fiche : « notre version » et des photos d'**étapes** (choix de l'étape, légende). Une photo d'étape s'affiche sous l'étape sur la fiche et dans le **mode cuisine**, au bon moment. **Photo principale** : une photo devient celle de la recette (copie). Quand un repas est marqué **mangé**, son détail dans le planning propose « Une photo de notre version ? » ; la photo garde la date du repas | ✅ |
| 31.3 | **Cuisiner un repas complet** | Dès qu'une case du planning a deux plats ou plus (entrée, plat, dessert, restes) : **Cuisiner le repas complet** (détail d'un repas, accueil, page d'une réception). Un seul écran de cuisine avec **toutes les étapes, entrelacées** et datées (« 18:05 Gratin, étape 2/3 »), pour que chaque plat soit prêt quand il est servi ; l'étape à faire est mise en avant ; **minuteurs nommés par plat** ; mise en place des ingrédients de chaque plat ; l'heure du repas se change et tout se recalcule ; **Tout marquer mangé** à la fin | ✅ |
| 31.4 | **Partager une recette** | **Plus › Partager un lien…** : un lien en lecture seule pour quelqu'un qui n'a pas Bouffe, **valable 30 jours**, **révocable**. La page (`/partage/recette/…`) montre la photo principale, les ingrédients (portions réglables) et les étapes, imprimable ; **ni** notes de cuisine, **ni** prix, **ni** nom du foyer ou de l'auteur. La liste des liens actifs indique combien de fois chacun a été ouvert. Un lien expiré ou révoqué affiche « Ce lien n'est plus valable » | ✅ |
| 31.5 | **Historique d'une recette** | **Plus › Historique** : chaque enregistrement depuis « Modifier » garde une version complète (textes, temps, catégories, ingrédients, étapes, sous-recettes), avec qui et quand, et un résumé (« Titre, 1 modifié, étapes »). **Voir** une version ; **Revenir à cette version** (la version actuelle reste dans l'historique) | ✅ |
| — | Tests | +11 tests Pest (949) ; 6 vérifications de plus dans le navigateur | ✅ |

## Choix et limites

- **Photo principale** : elle reste dans `recipes.photo_path` (cartes, fiche, impression, copies de
  recettes) ; la nouvelle table ne garde que les photos d'étapes et « notre version ». Une photo
  d'étape retient le **numéro** de l'étape, pas une clé vers l'étape (les étapes sont réécrites à
  chaque modification) : si tu insères une étape avant, la photo reste sur le même numéro.
- **Dupliquer une recette** ou la copier depuis un proche ne reprend que la photo principale.
- **Collections partagées** : partager une collection n'ouvre pas ses recettes privées (R31) ; les
  proches voient la collection sans elles, sauf si tu choisis « Partager et ouvrir les recettes ».
  Une collection d'un proche se consulte, elle ne se modifie pas.
- **Repas complet** : les heures sont indicatives. Une étape qui cite une durée (« cuire 25 min »)
  dure ce temps-là ; le reste du temps de la recette se partage entre les autres étapes (3 min au
  moins). Pour un repas ordinaire, l'entrée est servie à l'heure du repas, le plat 15 min après, le
  fromage à 35, le dessert à 45 ; pour une réception, les écarts du rétroplanning (lot 20). Restes et
  plats cuisinés à l'avance : « Réchauffer », 20 min avant d'être servis.
- **Lien de partage** : seule l'empreinte du jeton est enregistrée, l'adresse n'est donc montrée
  qu'une fois, à sa création. 10 liens actifs au plus par recette. La page n'est accessible que si
  Bouffe l'est depuis l'extérieur (voir [09-acces-distant.md](../09-acces-distant.md)) ; sur le réseau
  de la maison seulement, le lien ne marche que chez toi. Les moteurs de recherche sont priés de
  l'ignorer.
- **Historique** : il commence à la première modification faite après la mise à jour (la version
  d'avant devient la « version d'origine »). Seules les modifications faites par « Modifier » (et les
  retours arrière) créent une version ; les photos, le favori et la visibilité n'en font pas partie.
  50 versions gardées par recette, plus la version d'origine. Si la recette a changé autrement (en
  reprenant l'original d'un proche, par exemple), la page le signale.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_11_100000_create_lot31_recipes.php` | Tables du lot |
| `app/Models/RecipeCollection.php`, `RecipePhoto.php`, `RecipeShareLink.php`, `RecipeRevision.php` | Modèles |
| `app/Services/Recipes/Collections.php` | Collections, partage avec les proches, carnet |
| `app/Services/Recipes/RecipePhotos.php` | Photos d'étapes et « notre version » |
| `app/Services/Recipes/RecipeShares.php`, `app/Http/Controllers/SharedRecipeController.php` | Liens de partage et page publique |
| `app/Services/Recipes/RecipeRevisions.php` | Versions, résumé des changements, retour arrière |
| `app/Services/Planning/MealCookPlan.php` | Étapes entrelacées d'un repas complet |
| `app/Livewire/Recipes/Collections.php`, `CollectionShow.php`, `RecipeCollections.php`, `Photos.php`, `ShareLinks.php`, `History.php` + vues | Écrans |
| `app/Livewire/Planner/CookMeal.php` + vue | Cuisiner le repas complet |
| `resources/views/share/recipe.blade.php`, `share/expired.blade.php` | Page publique d'une recette partagée |
| `tests/Feature/Recipes/RichRecipesTest.php` | 11 tests |

Fichiers modifiés :

- fiche recette (menu **Plus**, « Dans : … », photos d'étapes, section Photos), mode cuisine
  (photo de l'étape), modification (version enregistrée), liste des recettes (bouton Collections) ;
- planning (« Cuisiner le repas complet », « Une photo de notre version ? »), accueil, réception ;
- `RecipePhotoController` (photos supplémentaires), `routes/web.php`, `Recipe` (relations, mot
  réservé « collections ») ;
- `HouseholdData` (fichiers et suppression d'un foyer), `DataExporter` (collections dans l'export) ;
- `BrowserDemoSeeder` (données d'essai inventées : une collection, un repas à deux plats) ;
- un test existant ajusté : le classement des modèles propres à un foyer.

## À vérifier sur ton poste

- **Recettes › Collections** : créer « Noël », y ranger deux recettes depuis leur fiche (**Plus ›
  Collections…**), les ordonner, **Imprimer en carnet**.
- **Une fiche recette** : ajouter une photo d'étape, puis ouvrir **Cuisiner** et aller à cette étape.
- **Planning** : mettre une entrée et un plat le même soir, ouvrir l'un des deux, **Cuisiner le repas
  complet**.
- **Plus › Partager un lien…** : ouvrir le lien dans une fenêtre de navigation privée, puis le
  révoquer.
- **Modifier** une recette, puis **Plus › Historique** : voir la version d'origine et y revenir.
