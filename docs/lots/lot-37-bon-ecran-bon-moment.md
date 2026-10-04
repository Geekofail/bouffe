# Lot 37 — Le bon écran au bon moment

**Objectif** : moins de gestes au jour le jour. Le soir, une question et une réponse ; le planning et
la fiche recette dans l'ordre où on s'en sert.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **1 005 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+13).
- **78 vérifications dans le navigateur** passent (+6) : la page « Ce soir », « Autre idée » dans une
  case, et sur iPhone les infos de la semaine repliées et la fiche recette.
- **Aucune migration** : les réglages du rendez-vous du soir sont des préférences de chacun
  (`users.preferences`).

Premier lot de la version 5 ; ordre prévu : 37 → 41 → 38 → 39 → 40 → 42 (Q52).

Spécification : [12-version-5.md](../12-version-5.md), module 37 et règle R38.

## Mise à jour depuis le lot 34

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-37-bon-ecran
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Il n'y a pas de migration ; la commande vide les caches et vérifie l'installation. Le service
worker (`public/sw.js`) change aussi : sur l'iPhone, Bouffe le reprend tout seul à la prochaine
ouverture. Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 37 — le bon écran au bon moment"
git push -u origin lot-37-bon-ecran
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 37.1 | **Le rendez-vous du soir** | Une notification par jour et par personne, à **20 h 30** par défaut (1 h 30 après l'heure du dîner), réglable par demi-heure de 17 h à 23 h 30 dans **Paramètres › Notifications**. Elle regroupe ce qui reste à faire : « **Chili con carne : c'était mangé ?** », « + 1 autre repas à clôturer », « Pour demain : faire tremper — houmous », « 1 gamelle à préparer ». Elle ouvre la page **Ce soir** (`/ce-soir`) : les repas du jour et d'hier à clôturer (**Mangé** · **Pas fait** · **Tout comme prévu**), les jours d'avant repliés, les **restes à placer**, ce qu'il faut **préparer pour demain** (**C'est fait**), les **gamelles** avec leurs étiquettes, et le menu de demain. Les rappels « à préparer » du soir (14.6) y sont **regroupés** au lieu d'arriver un par un. Sur iPhone (iOS 16.4 et plus), une **pastille** sur l'icône compte les repas à clôturer (R38) | ✅ |
| 37.2 | **« Hier comme prévu »** | Sur l'accueil, dès que deux repas d'hier restent à clôturer : un bouton qui les marque mangés **avec** le retrait du stock (et les restes rangés au réfrigérateur), comme la clôture automatique. **Annuler** pendant 10 secondes remet tout : repas, stock, mouvements, recette « à tester » (R32) | ✅ |
| 37.3 | **Planning : aujourd'hui d'abord** | Le planning s'ouvrait déjà sur le jour en cours (lot 28). Sur téléphone, l'astuce, « qui cuisine », l'équilibre, les règles et le stock à consommer se replient en **une ligne** : « **4 infos sur la semaine** · astuce, équilibre, règles, 7 produits à consommer », qui s'ouvre d'un toucher. Sur ordinateur, rien ne change | ✅ |
| 37.4 | **« Autre idée » dans une case** | **Case vide** : en haut de l'onglet Recette, « **Idées pour cette case** » propose trois plats, avec leurs raisons (« de saison », « faisable avec le stock », « a plu ») et leurs mises en garde ; **Autre idée** en tire trois autres. **Plat déjà prévu** : dans son détail, **Autre idée** propose trois plats pour le **remplacer** — mêmes portions, même commentaire, même cuisinier ; **Annuler** remet l'ancien. Le calcul est celui de « Remplir la semaine » : règles de la semaine, saison, stock, envies, avis, convives | ✅ |
| 37.5 | **Fiche recette en cuisine** | Sur téléphone : photo, titre, puis les temps **en une ligne** (« Prép. 15 min · Cuisson 35 min · Facile · ≥ 0,37 € / portion ») et trois boutons : **Cuisiner**, **Planifier** et **Plus** (envie, imprimer, modifier, dupliquer, variantes, collections, partager, assistant, historique, archiver, supprimer). Les ingrédients arrivent un écran plus haut ; l'ajusteur de portions reste en haut des ingrédients. Sur ordinateur, rien ne change | ✅ |
| — | Tests | +13 tests Pest ; +6 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Réglage (Q53)** : activé par défaut pour les comptes complets, désactivé pour les comptes en
  consultation. Il se règle dans **Paramètres › Notifications**, à côté des autres notifications de
  chacun, plutôt que dans Mon compte. Si l'heure tombe dans les heures calmes de la personne, la page
  le signale : le rendez-vous ne part pas.
- **Quand il part (R38)** : à partir de l'heure choisie et pendant 3 heures (jamais après minuit),
  au passage de la tâche planifiée — donc à quelques minutes près. Une seule fois par jour et par
  personne.
- **Quand il ne part pas** : rien à faire ; la personne notée **absente** du repas (convives de la
  case) ou le repas est la **gamelle de quelqu'un d'autre** ; la personne participe à un **séjour**
  ce jour-là (lot 34).
- **Rappels regroupés** : un rappel dû à partir de 16 h (par exemple « tremper » la veille à 18 h)
  attend le rendez-vous. Si le rendez-vous n'a pas pu partir (ordinateur éteint pendant toute la
  fenêtre, heures calmes), le rappel repart **seul**, comme avant. Sans le rendez-vous, rien ne
  change.
- **« Comme prévu »** : le stock est retiré selon la proposition par défaut, sans fenêtre (comme la
  clôture automatique du lot 21). Si le foyer a choisi « ne jamais retirer », les repas sont marqués
  mangés sans toucher au stock. « Tout marquer mangé, sans toucher au stock » reste là pour le
  rattrapage.
- **Page « Ce soir »** : « Tout comme prévu » ne concerne que les repas **d'aujourd'hui et d'hier** ;
  les jours d'avant sont repliés (« 18 repas des jours précédents »). Les repas du jour y apparaissent
  dès le matin : c'est à toi de dire s'ils sont mangés.
- **Pastille de l'icône** : posée par la notification, mise à jour quand on ouvre l'accueil ou la
  page « Ce soir ». Elle n'existe que pour Bouffe ajouté à l'écran d'accueil (iOS 16.4 et plus) ou
  installé sur ordinateur.
- **Idées pour une case** : un plat principal n'est pas pris parmi les recettes classées
  « Dessert », « Entrée », « Apéritif », « Accompagnement », « Petit-déjeuner », « Sauce »,
  « Goûter », « Boisson » ou « Fromage » (sauf si elles sont aussi « Plat »), ni parmi les
  **sous-recettes** (pâte brisée) ; pour un dessert, seulement ce qui est classé « Dessert ». Avec un
  petit carnet, Bouffe propose en dernier recours une recette mangée il y a peu ou déjà au menu de
  la semaine, **en le disant**. « Remplir la semaine » n'a pas changé.
- **Remplacer un plat** : impossible une fois mangé ou cuisiné à l'avance. Les restes déjà placés
  suivent le nouveau plat ; une envie placée sur l'ancien redevient une envie à planifier.

## Fichiers

| Fichier | Rôle |
|---|---|
| `app/Services/Planning/EveningRendezvous.php` | Rendez-vous du soir : réglage, contenu, message, regroupement des rappels (R38) |
| `app/Livewire/Evening.php` + `views/livewire/evening.blade.php`, `evening/meal-row.blade.php` | Page « Ce soir » |
| `app/Livewire/Concerns/PlacesLeftovers.php` | « Placer les restes », partagé par l'accueil et « Ce soir » |
| `tests/Feature/Planning/DailyRhythmTest.php` | 13 tests |
| `tests/Browser/evening.spec.js` | 6 vérifications dans le navigateur |

Fichiers modifiés :

- `NotificationDispatcher` (message du soir, rappels regroupés, pastille), `public/sw.js` (pastille
  de l'icône), `resources/js/app.js` ;
- `MealClosing` (« comme prévu » et ce qu'« Annuler » doit remettre), `ClosesMeals` (« Hier comme
  prévu »), accueil, cloche (lien vers « Ce soir ») ;
- `WeekFiller` (idées pour une case), `WeekPlanner` (remplacer un plat), `MealPicker`, détail d'un
  repas du planning, bandeaux du planning ;
- en-tête de la fiche recette ; `Settings\Notifications` ; `routes/web.php` ;
- un test existant ajusté : un rappel du soir part seul quand le rendez-vous est désactivé.

## À vérifier sur ton poste

- **Paramètres › Notifications** : le rendez-vous du soir est coché, à 20 h 30. Pour essayer tout
  de suite, mets une heure juste passée, puis lance `php artisan bouffe:reminders` (ou attends la
  tâche planifiée) : la notification arrive sur l'iPhone et ouvre « Ce soir ».
- **Ce soir** : clôturer un repas, cocher « C'est fait » sur une préparation, placer des restes.
- **Accueil**, le lendemain d'un jour avec deux repas : **Hier comme prévu**, puis **Annuler**.
- **Planning** sur l'iPhone : la ligne « infos sur la semaine », puis une case vide (**Idées pour
  cette case**, **Autre idée**) et un plat prévu (**Autre idée** › remplacer › **Annuler**).
- **Une recette** sur l'iPhone : les temps en une ligne, **Cuisiner**, **Planifier**, **Plus**.

Les captures du lot ont été faites avec les données d'essai de la base de développement (repas,
recettes et stock **inventés**).
