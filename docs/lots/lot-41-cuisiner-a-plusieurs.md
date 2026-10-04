# Lot 41 — Cuisiner à plusieurs, même sans réseau

**Objectif** : en cuisine, que deux téléphones et la tablette travaillent ensemble, et qu'on ait
ses recettes là où il n'y a pas de réseau.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **1 014 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+9).
- **84 vérifications dans le navigateur** passent (+6) : un minuteur lancé sur l'écran de cuisine
  apparaît sur une autre page et s'y arrête ; sans réseau, il reste dans l'appareil ; les recettes
  sans réseau se gardent dans l'appareil ; « Cuisiner le repas » se répartit.
- **Une migration** : deux tables, `kitchen_timers` et `meal_tasks`. Rien n'est modifié dans les
  données existantes.

Ordre prévu (Q52) : 37 ✅ → **41** → 38 → 39 → 40 → 42.

Spécification : [12-version-5.md](../12-version-5.md), module 41 et §7.4.

## Mise à jour depuis le lot 37

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-41-cuisiner-a-plusieurs
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. Le service web (`public/sw.js`) change : le téléphone le reprend
tout seul à la prochaine ouverture. Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 41 — cuisiner à plusieurs, même sans réseau"
git push -u origin lot-41-cuisiner-a-plusieurs
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 41.1 | **Minuteurs partagés** | Un minuteur lancé n'importe où — étape du mode cuisine, « Cuisiner le repas », minuteurs rapides de l'écran de cuisine — est enregistré dans Bouffe. Il apparaît sur **tous les appareils ouverts** du foyer (« lancé par Pierre ») : dans les pages de cuisine, et partout ailleurs dans une **pastille** en haut de l'écran (le plus proche, « +1 » s'il y en a d'autres ; un toucher montre la liste). On l'**arrête de n'importe quel appareil**. La page qui le voit finir sonne et vibre à la seconde ; si **aucune page ouverte** ne l'a vu sonner, une **notification** part vers la personne qui l'a lancé (ou vers tout le foyer si elle n'a pas de téléphone abonné), **même pendant les heures calmes**. Réglable : Paramètres › Notifications › « Minuteur terminé » | ✅ |
| 41.2 | **Cuisiner sans réseau** | **Planning › Plus › Recettes sans réseau** (et l'onglet Repas d'un séjour) : la liste de la semaine, avec un lien par recette **aux portions prévues**. **Garder sur cet appareil** enregistre la liste et chaque recette dans le téléphone. Sans réseau, elles s'ouvrent comme d'habitude : ingrédients à cocher, étapes, boutons **Minuteur**. Ces minuteurs sont partagés s'il y a du réseau, sinon ils restent **sur cet appareil** et sonnent quand même. La page « Pas de réseau » mène à ces recettes | ✅ |
| 41.3 | **Qui fait quoi ce soir** | Dans **Cuisiner le repas** (31.3) : un **« Qui ? »** par plat (« Pierre : curry ; Monique : crêpes » — c'est le « qui cuisine » du planning, 14.7), et chaque étape peut être **confiée** à quelqu'un d'autre. **Voir mes étapes seulement** ne garde que les siennes. Les étapes cochées **faites** se voient sur l'autre téléphone (« faite par Pierre », relu toutes les 10 secondes). L'**écran de cuisine** montre qui fait chaque plat et propose « Cuisiner tout le repas · qui fait quoi » | ✅ |
| — | Tests | +9 tests Pest ; +6 vérifications dans le navigateur (dont le test de l'écran de cuisine, réécrit) | ✅ |

## Choix et limites

- **Pas de serveur de messages en direct** (impossible sur le mutualisé OVH) : chaque page relit les
  minuteurs toutes les **5 secondes** quand un minuteur tourne ou qu'une page de cuisine est ouverte,
  toutes les **30 secondes** sinon, et seulement si la page est visible. Arrêté sur un téléphone, un
  minuteur disparaît des autres écrans en quelques secondes.
- **Le temps est compté sur chaque appareil**, à l'heure du serveur (l'écart d'horloge est corrigé à
  chaque lecture) : tous affichent la même chose à la seconde près.
- **Notification de fin** : elle passe par la tâche planifiée (toutes les 5 à 15 minutes), donc
  **jusqu'à quelques minutes après** la fin ; au-delà de 30 minutes, plus rien (trop tard pour
  servir). Elle ne part pas si une page ouverte et visible l'a déjà fait sonner. Elle ignore les
  heures calmes, parce qu'on vient de la lancer soi-même.
- **Oubliés** : un minuteur fini et jamais arrêté disparaît des écrans au bout d'une heure ; il est
  effacé au bout d'un jour.
- Les minuteurs du lot 35, gardés recette par recette dans un navigateur, sont effacés à la première
  ouverture : ils ne servent plus.
- **Sans réseau** : le téléphone doit ouvrir Bouffe par son **adresse en https** (le service web
  n'existe pas sur `http://bouffe.local`), comme le mode magasin hors ligne du lot 16. Les recettes
  gardées sont celles du jour de l'enregistrement : un changement fait ensuite n'y est pas, sauf à
  les garder de nouveau. Les photos ne sont pas gardées.
- **Minuteur sans réseau** : il reste sur le téléphone et n'apparaît pas sur les autres appareils,
  même quand le réseau revient.
- **Qui fait quoi** : la liste « Qui ? » n'apparaît qu'à partir de **deux comptes** dans le foyer.
  Seul un compte complet répartit ; tout le monde peut cocher une étape. Une étape est repérée par
  son **numéro** dans la recette : si on insère une étape avant elle, la répartition et la coche
  glissent d'un cran (le lot 40 prévoit un identifiant stable pour les étapes).

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_20_100000_create_kitchen_timers_and_meal_tasks.php` | Tables du lot |
| `app/Models/KitchenTimer.php`, `MealTask.php` | Modèles |
| `app/Services/Kitchen/KitchenTimers.php` | Minuteurs : lancer, arrêter, « a sonné », à signaler |
| `app/Services/Kitchen/MealTasks.php` | Qui fait quoi, étapes faites |
| `app/Http/Controllers/KitchenTimerController.php` | `/minuteurs` (lu et lancé par les pages, en JSON) |
| `app/Http/Controllers/OfflineRecipesController.php` + `views/offline/`, `components/offline-page.blade.php` | Recettes sans réseau |
| `resources/views/components/timers.blade.php` | Liste de minuteurs commune aux pages de cuisine |
| `tests/Feature/Kitchen/CookTogetherTest.php` | 9 tests |
| `tests/Browser/together.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- `resources/js/app.js` (un seul magasin de minuteurs pour la page, pastille, pages sans réseau),
  `public/sw.js` (les recettes gardées survivent aux mises à jour), mise en page (pastille) ;
- mode cuisine, « Cuisiner le repas » (`CookMeal`, sa vue), écran de cuisine et son menu du jour,
  `MealCookPlan` ;
- `NotificationDispatcher` (fin de minuteur), planning (lien « Recettes sans réseau »), onglet Repas
  d'un séjour, page « Pas de réseau », `routes/web.php` ;
- `PlannedMeal` (étapes), `HouseholdData` (suppression d'un foyer), `BrowserDemoSeeder` (une
  deuxième personne inventée, Malik) ;
- deux tests existants ajustés : les étapes faites de « Cuisiner le repas » sont enregistrées ; le
  classement des modèles propres à un foyer (37).

## À vérifier sur ton poste

- **Minuteurs** : sur l'iPhone, lance un minuteur depuis une étape du mode cuisine ; sur le PC (ou
  la tablette), ouvre l'écran de cuisine : il y est, « lancé par Pierre ». Arrête-le du PC : il
  disparaît du téléphone. La pastille apparaît en haut des autres pages.
- **Notification** : lance un minuteur d'une minute, verrouille le téléphone et attends la tâche
  planifiée (ou lance `php artisan bouffe:reminders`).
- **Sans réseau** (adresse en https) : Planning › Plus › Recettes sans réseau › Garder sur cet
  appareil, puis mode avion : ouvre une recette depuis la page « Pas de réseau ».
- **Qui fait quoi** : un dîner à deux plats › Cuisiner tout le repas : « Qui ? » sur chaque plat,
  puis « Voir mes étapes seulement » sur le téléphone de Monique.

Les captures du lot ont été faites avec les données d'essai de la base de développement (repas,
recettes et minuteurs **inventés**).
