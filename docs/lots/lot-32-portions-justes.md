# Lot 32 — Portions justes

**Objectif** : les bonnes quantités pour chacun (Léo n'a pas l'appétit d'un enfant de quatre ans),
et les gamelles du midi.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **938 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+12).
- **44 vérifications dans le navigateur** passent (+4) : Paramètres › Foyer et la page des gamelles,
  sur iPhone et sur ordinateur.
- **Une migration** : les portions passent à la demi-portion (`planned_meals.servings` et les
  portions notées dans les courses, le stock et les notes de cuisine), et trois colonnes
  (`planned_meals.for_user_id`, `planned_meals.is_lunchbox`, `guests.appetite`). Les valeurs
  existantes sont gardées telles quelles (4 reste 4).

Spécification : [08-version-4.md](../08-version-4.md), module 32, règle R33.

## Mise à jour depuis le lot 30

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. **Rien ne change tant que tu n'as pas réglé « À table
d'habitude »** : chaque personne compte pour une portion, comme avant.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 32.1 | **Appétit de chacun** (R33) | **Paramètres › Foyer › À table d'habitude** : la liste des personnes qui mangent à la maison, avec leur appétit (**petit** 0,5 · **moyen** 0,75 · **normal** 1 · **grand** 1,5). Une ligne peut être reliée à un compte du foyer (elle n'est alors pas comptée quand la personne est notée absente dans la fenêtre Convives) ou non (un enfant sans compte). Les parts se règlent dans « Régler les parts de chaque appétit » (de 0,25 à 3). Chaque **invité** a aussi un appétit : « Selon l'âge » par défaut (petit pour un enfant, normal sinon), ou choisi. Les portions d'un repas = somme des parts des présents, **arrondie à la demi-portion supérieure**. La liste de courses, les restes, le stock et les suggestions suivent | ✅ |
| 32.2 | **Affichage clair** | En tête du planning : « À table : 3 personnes · 3,5 portions » (lien vers le réglage). Détail d'un repas, sélecteur de repas, fiche recette ouverte depuis un repas, accueil, menu imprimé : « 4 personnes · 3,5 portions ». Les portions se saisissent par demi-portion partout (0,5 à 50). La fiche recette propose **Pour le foyer : 3,5 portions**. Dans une case du planning, la pastille des convives garde le nombre de personnes ; le détail est dans l'infobulle et lu par les lecteurs d'écran | ✅ |
| 32.3 | **Gamelles du midi** | Placer des restes **pour une personne** : dans le sélecteur de repas, onglet Restes, « Pour qui ? » → « Gamelle de Pierre ». Ou, sur un repas de restes déjà placé, « Emporté en gamelle ? ». Une gamelle compte la **portion de la personne** (1,5 pour un grand appétit). Sur le planning : « Gamelle de Pierre » avec son icône. **L'accueil**, la veille au soir : « Gamelles à préparer ce soir ». **Planning › Gamelles** (`/planning/gamelles`) : ce qu'il faut préparer chaque veille (cases à cocher sur papier) et une **étiquette** par gamelle, les mêmes que celles du congélateur (lot 18) ; leur QR code ouvre le repas dans le planning | ✅ |
| — | Tests | +12 tests Pest (938) ; 4 vérifications de plus dans le navigateur | ✅ |

## Règle appliquée

**R33 — Portions selon l'appétit** (valeurs par défaut de Q47, modifiables)

- Exemple : Pierre (grand, 1,5) + Monique (normal, 1) + Lina (petit, 0,5) + Hugo (moyen, 0,75)
  = 3,75 → **4 portions**. Pierre absent au déjeuner : 2,25 → **2,5 portions**.
- Une portion saisie à la main sur un repas l'emporte toujours.
- Les restes se comptent en portions « normales » ; un reste d'une demi-portion ne déclenche plus la
  proposition « Placer les restes ».
- Adultes et enfants ajoutés sans nom dans la fenêtre Convives : normal et petit.

## Choix et limites

- **L'appétit des membres est un réglage du foyer, pas une colonne `users.appetite`** (écart avec
  le modèle de données de la spécification). Deux raisons : un enfant sans compte doit pouvoir être
  compté, et une personne qui fait partie de deux foyers peut y manger différemment. Le réglage est
  enregistré dans `settings` (`table.people`), foyer par foyer. Le nombre « Personnes à table » des
  lots précédents en découle et n'est plus saisi à part.
- **Les parts du « petit » reprennent `BOUFFE_CHILD_PORTION`** tant qu'on ne les a pas changées :
  si tu avais réglé 0,5 dans le `.env`, rien ne bouge.
- **Une gamelle est pour une personne qui a un compte** (`for_user_id`). Pour la gamelle de Léo sans
  compte, place des restes normalement et note « Léo » en commentaire.
- **« Midi au travail »** n'est pas un créneau à part : la gamelle se place dans le créneau du midi
  existant, à côté du repas de la maison s'il y en a un. Elle ne change pas le nombre de convives de
  la case.
- **Portions à la demi-portion** : une ancienne valeur entière reste valable ; une saisie « 2,4 »
  devient 2,5.
- **Étiquettes du congélateur** : elles utilisent maintenant le même composant que celles des
  gamelles (`x-box-label`), sans changement visible.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_10_100000_create_lot32_portions.php` | Demi-portions et colonnes du lot |
| `app/Services/Planning/Appetites.php` | Appétits, parts, personnes à table, arrondi R33, écriture « 3,5 portions » |
| `app/Services/Planning/Lunchboxes.php` | Gamelles : par date, par veille, origine des restes |
| `resources/views/print/lunchboxes.blade.php` | Page « Gamelles du midi » (liste de la veille et étiquettes) |
| `resources/views/components/box-label.blade.php`, `box-labels.blade.php` | Étiquette commune congélateur / gamelles |
| `tests/Feature/Planning/PortionsTest.php` | 12 tests (R33, réglages, invités, affichage, courses, gamelles) |

Fichiers modifiés :

- `OccasionService` (portions et personnes, `summary()`), `WeekPlanner` (demi-portions, gamelles),
  `GuestManager` et modèle `Guest` (appétit), modèle `PlannedMeal` (gamelle, libellé) ;
- tous les calculs qui prennent des portions : `QuantityScaler`, coût et nutrition des recettes,
  suggestions, stock des repas, cuisine en avance, remplissage automatique, congélateur,
  réceptions, repas partagés, dépenses ;
- Paramètres › Foyer (le champ « Personnes à table » devient « À table d'habitude »), fenêtre
  invité, planning (en-tête, détail d'un repas, case), sélecteur de repas, fenêtre Convives,
  accueil, fiche recette, mode cuisine, impressions, listes de courses ;
- `PrintController` (page des gamelles), `routes/web.php` (`planner.lunchboxes`), icône `lunchbox` ;
- `BrowserDemoSeeder` (données d'essai inventées : Camille et Léo à table, une gamelle) ;
- les tests existants qui attendaient des portions entières (4 → 4.0) ou « 4 convives » (→
  « 4 personnes »).

## À vérifier sur ton poste

- **Paramètres › Foyer** : régler « À table d'habitude » (par exemple ajouter Léo en « Moyen »),
  puis regarder l'en-tête du planning.
- **Planning** : ouvrir une case, la portion proposée suit les appétits ; la liste de courses suit.
- **Invités** : choisir l'appétit d'un invité.
- **Gamelles** : placer des restes pour toi un midi de la semaine, puis la veille regarder l'accueil
  et **Plus › Gamelles et étiquettes**.
