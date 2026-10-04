# Lot 13 — Remplir le carnet

**Objectif** : une recette trouvée en ligne, reçue par mail ou recopiée d'un livre entre dans le carnet en une minute, sans ressaisir ligne à ligne.

**Statut** : ✅ développé et vérifié en local (495 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 900 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 13 (13.1, 13.2, 13.3, 13.10, 13.13) et règles R13, R14, R22.

## Mise à jour depuis le lot 12

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # recettes « à tester », sections d'étapes
php artisan view:clear
php artisan test         # 495 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 13.1 | **Import depuis une adresse** (R13) | Coller le lien d'une recette → lecture de la page côté serveur → données `schema.org/Recipe` (JSON-LD, `@graph`, microdonnées en repli) → écran de relecture. Photo de la page reprise si on le souhaite | ✅ |
| 13.2 | **Collage de texte** (R14) | Coller une recette copiée d'un mail, d'un PDF ou d'un message : titre, portions, temps, sections « Ingrédients » / « Préparation », sous-titres (« Pour la pâte ») ; sans titres de sections, les lignes sont réparties d'après leur allure | ✅ |
| 13.3 | **Saisie rapide** | Dans le formulaire de recette : bouton « Saisie rapide », une ligne par ingrédient, transformées en lignes structurées modifiables | ✅ |
| 13.4 | **Écran de relecture** | Titre, portions, temps, catégories, photo, ingrédients avec leur statut ✓ / ? / +, rayon à choisir pour les nouveaux ingrédients, étapes ; rien n'est enregistré avant « Enregistrer la recette » | ✅ |
| 13.5 | **Analyse d'une ligne** (R14) | `1,5 kg de pommes de terre à chair ferme, épluchées` → 1,5 · kg · Pomme de terre · « épluchées » ; fractions (½), plages (« 2 à 3 »), « une », abréviations (« c. à s. »), facultatif, préparations en fin de ligne (« râpé », « du moulin ») | ✅ |
| 13.6 | **Doublons** | Même adresse ou même titre déjà au carnet → bandeau « ouvrir la recette existante » / « créer quand même » | ✅ |
| 13.7 | **Alias mémorisés** (R22) | Si on corrige « ail rose de Lautrec » en « Ail », le nom d'origine devient un alias : le prochain import le reconnaît tout seul | ✅ |
| 13.8 | **Sections d'étapes** | Nouvelle colonne `recipe_steps.group_name` : « La pâte », « La garniture » ramenées par l'import, affichées sur la fiche, en mode cuisine et à l'impression, modifiables dans le formulaire | ✅ |
| 13.13 | **Recettes « à tester »** | Marque posée à l'import, retirée dès que la recette est marquée « mangé » ; badge sur la carte, encadré sur la fiche, filtre « À tester (n) » dans le carnet | ✅ |
| 13.10 | **Export / import JSON** | `/recettes/exporter` télécharge tout le carnet ; l'onglet « Fichier Bouffe » le réimporte ailleurs, en ignorant les recettes déjà présentes | ✅ |
| 13.11 | Tests | +70 tests (analyse de lignes, import URL, collage, écran d'import, saisie rapide, « à tester », export / import, photos) — 495 au total | ✅ |

## Règles

### R13 — Import depuis une adresse

- **Sécurité** : `http`/`https` seulement, adresses locales et privées refusées (« Seules les adresses publiques peuvent être importées »), délai 10 s, 5 Mo maximum, 3 redirections.
- **Correspondances** : `name` → titre · `recipeYield` → portions (premier nombre) · `prepTime` / `cookTime` → temps, `totalTime − prep − cook` → repos · `recipeIngredient[]` → lignes analysées par R14 · `recipeInstructions` (texte, `HowToStep`, `HowToSection`) → étapes, une section devenant un titre de groupe · `image` → photo · `recipeCategory` / `recipeCuisine` / `keywords` → catégories **déjà existantes** uniquement · `url` ou adresse saisie → source.
- **Photo** : téléchargée seulement à l'enregistrement, si la case est cochée ; une image injoignable, trop grosse (8 Mo) ou illisible est ignorée sans faire échouer l'import.
- **Aucune donnée structurée** : message qui renvoie vers « Coller du texte ».

### R14 — Analyse d'une ligne d'ingrédient

Ordre des étapes (l'ordre compte : `1,5 kg` doit être lu avant la virgule de préparation) :

1. nettoyage des puces et de la numérotation ;
2. « facultatif », « optionnel », « selon le goût », « pour servir » → ligne facultative ;
3. quantité en tête (`QuantityParser` : `1,5`, `1/2`, `½`, `1 500`, « 2 à 3 » → 3, « une » → 1) ;
4. unité (codes, libellés, pluriels, abréviations « c. à s. », « cuillères à café ») ;
5. préparation : parenthèses et ce qui suit la première virgule ;
6. préparation en fin de ligne (« râpé », « émincé », « en dés », « du moulin ») ;
7. article retiré (« de la », « du », « d' ») ;
8. ingrédient : nom exact ou alias → **haute** ; sans les adjectifs connus, ou ressemblance ≥ 88 % → **moyenne** ; sinon **aucune** (il sera créé, son rayon est demandé).

### Recettes « à tester »

- Posée automatiquement à tout import (adresse, texte, fichier JSON) ; case décochable dans l'écran de relecture.
- Retirée dès qu'un repas planifié portant cette recette est marqué « mangé » (y compris depuis le mode cuisine).

### Export / import JSON

- Format `bouffe-recettes`, version 1 : titre, description, portions, temps, difficulté, source, notes, favori, catégories, ingrédients (nom, quantité, unité, préparation, groupe, facultatif), étapes (section, texte). **Sans les photos** — c'est le rôle des sauvegardes du lot 5.
- À l'import : les unités sont retrouvées par code, les ingrédients et catégories inconnus sont créés (rayon « Divers » par défaut), une recette dont le titre existe déjà est ignorée et listée dans le compte rendu.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/recettes/importer?onglet=url\|texte\|json` | `recipes.import` | `Recipes\Import` |
| `/recettes/exporter` | `recipes.export` | `RecipeExportController` |
| `/recettes?a_tester=1` | `recipes.index` | filtre « À tester » |

## Modèle de données

| Table | Colonne | Notes |
|---|---|---|
| `recipes` | `is_to_test` BOOLEAN INDEX | recette jamais cuisinée (13.13) |
| `recipe_steps` | `group_name` VARCHAR(80) NULL | section d'étapes (« La pâte ») |

## Services

| Classe | Rôle |
|---|---|
| `Services\Recipes\IngredientLineParser` | R14 — analyse d'une ligne d'ingrédient ; `parse()` et `looksLikeIngredient()` |
| `Services\Recipes\RecipeImporter` | R13 — lecture d'une page, ébauche, téléchargement de la photo |
| `Services\Recipes\RecipeTextParser` | 13.2 — découpage d'un texte collé en titre / métadonnées / ingrédients / étapes |
| `Services\Recipes\RecipeArchive` | 13.10 — export et import JSON |

## Correctif : mémoire des photos

La suite de tests dépassait les 128 Mo par défaut de PHP CLI (`Allowed memory size exhausted` dans `RecipePhotoService`) : une image décompressée occupe 4 octets par pixel, soit 17 Mo pour la photo de 2400 × 1800 px d'un test, à quoi s'ajoutait le grand format.

- `phpunit.xml` fixe désormais `memory_limit` à 512 Mo pour les tests (le traitement d'images en demande plus que le défaut, quel que soit le `php.ini` du poste).
- `RecipePhotoService` libère l'original dès que le grand format existe et tire la vignette de ce grand format : deux images en mémoire au maximum. L'image pivotée par l'orientation EXIF ne laissait pas non plus l'ancienne derrière elle (fuite corrigée), et le fichier lu n'est plus gardé pendant tout le traitement.
- Avant traitement, la taille de l'image est comparée à ce que la mémoire permet : une photo trop grande donne un message clair (« Photo trop grande pour la mémoire disponible (24,5 Mpx, maximum 14,1 Mpx) : réduisez-la ou augmentez « memory_limit » dans php.ini ») au lieu d'une erreur fatale. Le plafond se règle au besoin : `BOUFFE_PHOTO_MAX_MEGAPIXELS=8` dans `.env` (0 ou absent = calculé d'après le `memory_limit` de PHP).
- Nouveau fichier `tests/Feature/Recipes/RecipePhotoServiceTest.php` : deux tailles produites, pas d'agrandissement d'une petite photo, refus propre quand la photo dépasse le plafond ou quand le fichier n'est pas une image, et contrôle du pic de mémoire. Le plafond y est fixé par la configuration et non par `memory_limit` : **un test ne doit jamais pouvoir faire tomber le processus**.

## Choix faits pendant le lot

- **« Presque » un ingrédient** : une correspondance approchante (« ail rose de Lautrec ») est proposée avec un « ? » plutôt que refusée ; c'est l'utilisateur qui tranche, et sa correction devient un alias.
- **Unité par défaut seulement s'il y a une quantité** : « Poivre du moulin » n'arrive pas avec « 0 pincée ».
- **Sections d'étapes en base** plutôt qu'un simple affichage : l'information vient des sites (`HowToSection`) et se perdait sinon à l'enregistrement.
- **Le format d'export ne contient pas les photos** : un fichier texte reste lisible, léger et diffusable ; pour tout reprendre (photos comprises) il y a les sauvegardes ZIP.
- **Aucun appel réseau dans les tests** : les pages de recettes sont des gabarits locaux servis par `Http::fake()`, y compris pour les cas d'erreur et le refus des adresses privées.
- **Le jeu de tests R14 compte 15 lignes réelles** et non 200 comme le prévoyait le document 06 : le principe est posé, la liste s'allongera avec les vraies recettes importées.

## Prochain lot

**Lot 14 — Planning intelligent** : remplir la semaine automatiquement (R15), semaines types (R16), règles de la semaine, envies.
