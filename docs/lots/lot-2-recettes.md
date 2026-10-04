# Lot 2 — Recettes

**Objectif** : le carnet de recettes complet, prêt à être utilisé par le planning (lot 3) et la liste de courses (lot 4).

**Statut** : ✅ développé et vérifié en local (190 tests, MariaDB 10.11, parcours complet dans un navigateur ordinateur + mobile) — ⏳ à valider sur ton poste.

## Mise à jour depuis le lot 1

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # 5 nouvelles tables
php artisan db:seed      # comptes Pierre et Monique + 8 recettes d'exemple (n'écrase rien)
php artisan view:clear
php artisan test         # 190 tests
```

Le terminal affiche le **mot de passe initial** de chaque compte créé. Pour le changer : `php artisan bouffe:user ptripodi@free.fr`.
Pour choisir soi-même ce mot de passe initial, renseigner `BOUFFE_SEED_PASSWORD=…` dans `.env` **avant** `db:seed`.

Photos de téléphone : augmenter `upload_max_filesize` (16M) et `post_max_size` (20M) dans Wamp → PHP → Réglages PHP.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 2.0 | Comptes du foyer | `UserSeeder` : Pierre (ptripodi@free.fr) et Monique (monique.van@hotmail.com), mot de passe initial aléatoire affiché ou `BOUFFE_SEED_PASSWORD` ; un compte existant n'est jamais modifié | ✅ |
| 2.1 | Modèle de données | `recipes`, `recipe_ingredients`, `recipe_steps`, `recipe_tag`, `recipe_ratings` ; enum `Difficulty` ; slug unique (`/recettes/gratin-dauphinois`) | ✅ |
| 2.2 | Service `QuantityScaler` | Règle R1 : quantités × portions voulues / portions de base | ✅ |
| 2.3 | Service `IngredientLineFormatter` | Phrases françaises : « 200 g de farine », « 2 c. à soupe d'huile d'olive », « 2 gousses d'ail », « 1 ½ oignon », « sel » (élision, h aspiré, pluriels) | ✅ |
| 2.4 | Photos | Envoi JPG/PNG/WebP (10 Mo), redimensionnement en 1600 px + vignette 640 px (orientation des photos de téléphone corrigée), stockage privé servi uniquement aux utilisateurs connectés, pas de lien symbolique à créer | ✅ |
| 2.5 | Formulaire recette | Titre, description, portions, temps (préparation / cuisson / repos), difficulté, catégories (puces), favori, photo, source, notes | ✅ |
| 2.6 | Lignes d'ingrédients | Quantité à la française (« 1,5 », « 1/2 »), unité, ingrédient avec **autocomplétion**, précision, facultatif, **groupes** (Pâte / Garniture), glisser-déposer, **unité par défaut proposée**, **création d'un nouvel ingrédient à la volée** avec choix du rayon | ✅ |
| 2.7 | Étapes | Liste numérotée, ajout / suppression, glisser-déposer | ✅ |
| 2.8 | Liste des recettes | Cartes (photo ou initiale, temps total, difficulté, note moyenne, catégories), favori en un clic, pagination | ✅ |
| 2.9 | Recherche et filtres | Titre **ou ingrédient** (sans accent ni pluriel : « boeuf », « chevres »), catégories cumulables, temps maximum, difficulté, favoris, archivées ; tris récentes / alphabétique / mieux notées / plus rapides ; filtres conservés dans l'URL | ✅ |
| 2.10 | Fiche recette | Temps, difficulté, catégories cliquables, ingrédients groupés, étapes, notes, source (lien) | ✅ |
| 2.11 | Ajusteur de portions | Boutons − / + : quantités recalculées et arrondies (« 500 g », « 1 oignon »), retour aux portions d'origine | ✅ |
| 2.12 | Avis du foyer | Chacun note de 1 à 5 étoiles + commentaire ; l'avis de l'autre est visible ; re-cliquer sur sa note l'efface | ✅ |
| 2.13 | Actions | Modifier, **dupliquer** (ingrédients, étapes, catégories, photo — sans les avis), **archiver / désarchiver**, supprimer | ✅ |
| 2.14 | Protections | Ingrédient ou unité utilisés dans une recette non supprimables ; supprimer une catégorie la retire des recettes | ✅ |
| 2.15 | Recettes d'exemple | 8 recettes (bolognaise, quiche, curry, velouté, salade grecque, gratin, chili, crêpes) — supprimables librement | ✅ |
| 2.16 | Tests | +54 tests (services, formulaire, photos, fiche, liste, protections, seeders) — 190 au total | ✅ |

## Routes ajoutées

| URL | Nom | Composant |
|---|---|---|
| `/recettes` | `recipes.index` | `Recipes\Index` |
| `/recettes/nouvelle` | `recipes.create` | `Recipes\Edit` (+ `Forms\RecipeForm`) |
| `/recettes/{slug}` | `recipes.show` | `Recipes\Show` |
| `/recettes/{slug}/modifier` | `recipes.edit` | `Recipes\Edit` |
| `/recettes/{id}/photo/{large\|thumb}` | `recipes.photo` | `RecipePhotoController` |

## Choix faits pendant le lot

- **Autocomplétion par `<datalist>` HTML** : aucune librairie JavaScript, fonctionne au clavier et sur mobile. Un nom inconnu déclenche la création de l'ingrédient (le rayon est demandé sur la ligne) : on ne quitte jamais la recette.
- **Lignes d'ingrédients remplacées à chaque enregistrement** : simple et fiable ; les listes de courses (lot 4) copieront leurs données au moment de la génération, donc aucune dépendance aux identifiants de lignes.
- **Photos hors du dossier `public`** : pas de `storage:link` sous Windows (droits administrateur), photos non accessibles sans connexion — utile pour un futur hébergement OVH.
- **Suppression d'une recette** : autorisée pour l'instant ; au lot 3, une recette déjà planifiée devra être archivée plutôt que supprimée.
- **Nouveau fichier `config/bouffe.php`** : réglages propres à l'application (mot de passe initial, portions par défaut, tailles des photos).

## Prochain lot

**Lot 3 — Planning** : grille de la semaine (Déjeuner / Dîner), ajout de recettes, restes et repas libres, portions, glisser-déposer, copie de semaine, assistant restes, « cuisiné ✓ », historique et suggestions.
