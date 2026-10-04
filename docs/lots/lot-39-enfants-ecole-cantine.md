# Lot 39 — Enfants, école et cantine

**Objectif** : chaque personne du foyer existe, qu'elle ait un compte ou non. La cantine du midi
compte dans le planning, et les enfants peuvent choisir un repas et cuisiner.

**Statut** : ✅ développé et vérifié en local. ⏳ À valider sur ton poste.

- **1 041 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+16). Le test de reprise des
  données tourne sous SQLite. La migration a aussi été appliquée, annulée puis réappliquée sur la
  base de développement MariaDB, qui contient des données des lots précédents.
- **98 vérifications dans le navigateur** passent (+8) :
  - la page Foyer et la fenêtre d'une personne ;
  - la page Cantine, avec un jour « pas de cantine » ;
  - un choix préparé par un adulte puis fait sur l'écran de cuisine ;
  - une étape « avec un adulte ».
- **Une migration**, qui **transforme des données** :
  - le réglage « À table d'habitude » (lot 32) devient la table `household_people` ;
  - les goûts et allergies des comptes (`household_restrictions`) passent sur leur personne
    (`person_restrictions`) ;
  - les gamelles passent de `for_user_id` à `for_person_id` ;
  - nouvelles tables : `canteen_meals` et `child_choices` ;
  - nouvelles colonnes : `meal_occasions.absent_person_ids`, `stay_participants.person_id`,
    `recipes.kid_friendly`, `recipe_steps.adult_help`.

  Les portions restent identiques : la table enregistrée est reprise telle quelle.
  `bouffe:deploy` fait une sauvegarde juste avant.

Ordre prévu (Q52) : 37 ✅ → 41 ✅ → 38 ✅ → **39** → 40 → 42.

Spécification : [12-version-5.md](../12-version-5.md), module 39 et règles R40 à R42.

## Mise à jour depuis le lot 38

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-39-enfants-ecole-cantine
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande sauvegarde la base puis applique la migration. Vérifie ensuite la page
**Paramètres › Foyer** : tes personnes à table doivent y être, avec leur appétit. Les goûts et
allergies notés jusqu'ici pour un compte sont désormais sur sa personne. Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 39 — enfants, école et cantine"
git push -u origin lot-39-enfants-ecole-cantine
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 39.1 | **Les personnes du foyer** | **Paramètres › Foyer › Les personnes du foyer** : une fiche par personne, avec ou sans compte. Chaque fiche porte un prénom, un appétit, une **couleur** (8 teintes lisibles en clair comme en sombre) et un compte rattaché éventuel. Elle peut aussi porter des **goûts et allergies** (comme un invité), des **jours de cantine** et le nom de l'école, ainsi que la case « montrer ses goûts aux foyers reliés ». Une case « **À table d'habitude** » décide si la personne compte dans les portions. L'ordre des fiches se change avec les flèches. Les allergies d'un enfant déclenchent les **mêmes alertes** que celles d'un invité : planning, choix d'un repas, « Remplir la semaine », réceptions. La fenêtre **Convives** permet de marquer absente une personne sans compte. Une **gamelle** peut être pour n'importe qui (« Gamelle de Léo »). En **séjour**, une personne garde ses allergies. | ✅ |
| 39.2 | **La cantine du midi** | **Planning › Plus › Cantine** (`/planning/cantine`) montre la semaine de chaque enfant. Le menu se tape jour par jour, se **colle** depuis le site ou l'application de l'école (chaque jour nommé, « Lundi : … », remplit son midi) ou se **prend en photo** (lue par le service des tickets, lot 23). « **Pas de cantine** » s'applique à un jour ou à toute la semaine (vacances, malade). Les jours de cantine, l'enfant n'est **pas compté au déjeuner** à la maison : portions, alertes et courses le retirent. Le planning affiche « Cantine · Léo et Emma — poisson pané, purée » dans la case du midi, et l'écran de cuisine aussi. L'**équilibre de la semaine** compte les midis de cantine dont le menu est connu (« 29 repas, dont 9 à la cantine »). Les **idées du soir** écartent le plat servi le midi et évitent la même viande ou le même poisson. | ✅ |
| 39.3 | **Le choix des enfants** | **Planning › Plus › Le choix des enfants** : l'adulte choisit le jour, le repas et qui choisit, puis deux ou trois recettes. Le bouton « Proposer 3 idées » utilise le même calcul que « Autre idée ». Chaque recette est **vérifiée pour tous ceux qui mangent ce jour-là** (personnes présentes et invités) ; une recette qui pose problème à quelqu'un est refusée. L'enfant choisit ensuite sur l'**écran de cuisine** : un bandeau « Léo : à toi de choisir le dîner de dimanche ! » ouvre trois grandes images. Un toucher, puis « Oui ! », et c'est noté. Le choix devient une **envie** (« Choix de Léo pour le dîner de dimanche »). Si l'adulte l'a permis, il **remplace le plat prévu** pour ce repas, ou s'ajoute si la case est vide. | ✅ |
| 39.4 | **Ils cuisinent** | Case « **Facile avec un enfant** » dans la fiche d'une recette, et filtre « Avec un enfant » dans la liste. En **mode cuisine**, chaque étape d'une telle recette est signalée « **Avec un adulte · four** » (couteau, plaque de cuisson, friture, eau bouillante, mixeur) ou « L'enfant peut le faire ». Le repérage se fait d'après les mots de l'étape (« préchauffer le four », « couper », « faire revenir dans une poêle »…). Un compte complet le **corrige** d'un toucher, et la correction survit à une modification de la recette. La lecture à voix haute (lot 38) dit « Avec un adulte » avant l'étape. | ✅ |
| — | Tests | +16 tests Pest, dont la reprise des données ; +8 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Personnes enregistrées au premier besoin.** Tant que rien n'est noté, le foyer garde la table
  par défaut du lot 32. La table est enregistrée telle quelle dès qu'on en a besoin : ouverture de
  Paramètres › Foyer, premier goût noté, première gamelle. Les portions ne bougent pas.
- **Alertes = les personnes à table.** Les alertes viennent des personnes « à table d'habitude »
  présentes, plus les invités.
  - La migration crée une personne « pas à table » pour un compte qui avait une contrainte ou une
    gamelle sans être dans la table. Ses goûts sont gardés, mais ne déclenchent plus d'alerte
    chaque jour. Coche « À table d'habitude » si elle mange vraiment à la maison.
  - Une personne qui vient de temps en temps s'ajoute comme invité.
- **« Qui cuisine »** (14.7) reste un **compte**. Ce sont les comptes qui reçoivent les
  notifications et qui voient « Mes étapes » (lot 41).
- **Un compte qui quitte le foyer** : sa personne reste (goûts, gamelles), sans compte rattaché.
- **Cantine.**
  - **Le déjeuner** est repéré par le nom du créneau (« Déjeuner », « Midi »). Sans un tel créneau,
    la cantine ne retire personne et la page le signale.
  - **Le calendrier scolaire luxembourgeois n'est pas connu** : les vacances se marquent avec
    « Pas de cantine cette semaine ».
  - Un jour sans menu reste « cantine » sans détail : rien n'est déduit (R41).
  - Les **familles** d'un menu sont devinées d'après ses mots (poisson pané → poisson, purée →
    féculents). Elles se lisent en petit à côté de chaque jour.
  - Pas d'import automatique depuis Restopolis ou une maison relais. Q55 est appliquée avec sa
    proposition par défaut : saisie, collage et photo. Montre-moi un menu publié pour aller plus
    loin.
  - **Photo** : il faut le service de lecture des tickets (Paramètres › Tickets de caisse) ; elle
    compte dans son plafond. Une seule lecture sert à tous les enfants cochés, et seule l'image est
    envoyée.
- **Le soir après la cantine** :
  - un plat dont le nom rappelle le menu du midi perd 30 points (« déjà servi à la cantine ce
    midi ») ;
  - une même viande ou un même poisson en perd 12 (« poisson à la cantine ce midi »).

  Ce sont des avertissements, pas des interdictions.
- **Le choix des enfants.**
  - Les recettes avec une allergie, un régime ou un « n'aime pas » de quelqu'un à table sont
    refusées (R42).
  - Un seul choix en attente par repas : en préparer un autre remplace le précédent.
  - Un plat déjà cuisiné n'est jamais remplacé.
  - Q56 est appliquée avec sa proposition par défaut : sur l'écran de cuisine, trois propositions.
- **Données ajoutées hors du §5** : la table `child_choices` (le choix des enfants) et les colonnes
  `recipes.kid_friendly` et `recipe_steps.adult_help` (39.4).
- **Correction d'une étape** : elle suit le **numéro** de l'étape. Si on insère une étape avant
  elle, la correction glisse d'un cran ; le lot 40 donne aux étapes un identifiant stable.
- **Foyers reliés** (R40) :
  - un enfant sans compte n'est montré aux autres foyers que si un adulte a coché « montrer ses
    goûts » ;
  - en **séjour**, seuls les **comptes** d'un foyer relié s'ajoutent d'un coup ; un enfant de ce
    foyer s'ajoute par son prénom.
- Aucune date de naissance n'est demandée (R40) : l'appétit suffit.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_11_03_100000_create_lot39_people.php` | Tables et colonnes du lot, reprise des données |
| `app/Models/HouseholdPerson.php`, `PersonRestriction.php`, `CanteenMeal.php`, `ChildChoice.php` | Modèles (`HouseholdRestriction` disparaît) |
| `app/Services/People/HouseholdPeople.php` | Personnes : enregistrer la table, ajouter, modifier, retirer, ordonner |
| `app/Services/People/CanteenCalendar.php` | Cantine : jours, absents du midi, menus, collage, photo |
| `app/Services/People/ChildChoices.php` | Le choix des enfants |
| `app/Services/Recipes/AdultSteps.php` | Étapes « avec un adulte » |
| `app/Livewire/Planner/Canteen.php`, `ChildChoices.php` + vues | Pages Cantine et Le choix des enfants |
| `app/Livewire/Kitchen/Choice.php` + vue | L'écran du choix (`/cuisine/choix`) |
| `resources/views/livewire/planner/week/canteen.blade.php` | La cantine dans une case du planning |
| `tests/Feature/People/FamilyTest.php` | 16 tests |
| `tests/Browser/family.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- **Portions et convives** : `Appetites` (les personnes remplacent le réglage),
  `OccasionService` (absents sans compte, cantine), `HouseholdService` (goûts des personnes),
  fenêtre Convives.
- **Gamelles** : `WeekPlanner`, `Lunchboxes`, sélecteur, détail d'un repas, `EveningRendezvous`,
  accueil, écran de cuisine, raccourcis.
- **Idées et équilibre** : `WeekFiller` (cantine du midi), `WeekBalance` (familles d'un menu,
  midis de cantine).
- **Foyers reliés et séjours** : `LinkedEaters`, `StayService` ; `HouseholdManager` (départ d'un
  membre).
- **Données** : `IngredientMerger` (goûts des personnes), `HouseholdData` (suppression d'un foyer),
  `OcrService` (lecture d'un document quelconque).
- **Écrans** :
  - Paramètres › Foyer (personnes, fenêtre d'une personne, « Comptes ») ;
  - planning (menu Plus, cases du midi, équilibre) et écran de cuisine ;
  - fiche, modification et liste des recettes ;
  - mode cuisine.
- **Divers** : icônes `school`, `hand`, `smile` ; `routes/web.php` ; `BrowserDemoSeeder` (Léo, sa
  cantine et un choix, inventés).
- **Tests existants ajustés** (gamelles par personne, page Foyer, contraintes) : `PortionsTest`,
  `HouseholdTest`, `HouseholdsTest` (42 modèles propres à un foyer), `LinkedTest`, `StaysTest`,
  `DailyRhythmTest`.

## À vérifier sur ton poste

- **Après `bouffe:deploy`** : Paramètres › Foyer. Tes personnes y sont avec leur appétit, et les
  goûts déjà notés sont sur la bonne personne. Le planning affiche les mêmes portions qu'avant.
- **Un enfant** : Ajouter une personne, sans compte. Coche ses jours de cantine et ajoute une
  allergie. Le lundi midi, il disparaît des portions ; une recette qui contient son allergène est
  signalée au dîner.
- **Cantine** : Planning › Plus › Cantine. Colle le menu de la semaine tel que l'école le publie,
  puis regarde la case du midi et l'équilibre de la semaine. Si tu peux, envoie-moi un menu réel
  (Q55).
- **Choix** : Planning › Plus › Le choix des enfants › Proposer 3 idées › Préparer le choix. Sur la
  tablette, le bandeau de l'écran de cuisine ouvre le choix.
- **Ils cuisinent** : coche « Facile avec un enfant » sur des crêpes, puis lance le mode cuisine.

Les captures du lot ont été faites avec les données d'essai de la base de développement. Léo et
Emma, leur cantine, leurs menus, leurs allergies et le choix du dimanche sont **inventés**.
