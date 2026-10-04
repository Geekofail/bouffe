# Lot 14 — Planning intelligent

**Objectif** : une semaine planifiée en une minute, sans oublier de décongeler.

**Statut** : ✅ développé et vérifié en local (541 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1440 / 950 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 14 (14.1, 14.2, 14.3, 14.4, 14.5, 14.6, 14.9), module 19 (19.1) et règles R15, R16, R21.

## Mise à jour depuis le lot 13

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # semaines types, envies, rappels
php artisan config:clear
php artisan view:clear
php artisan test         # 541 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 14.1 | **Remplir la semaine** (R15) | `/planning/remplir` : une proposition par case vide, avec la raison du choix ; chaque case peut être relancée 🔀, verrouillée 🔒 ou retirée ✕ ; « Relancer tout » garde les cases verrouillées ; rien n'est enregistré avant « Valider » | ✅ |
| 14.2 | **… depuis le stock** | Option « Utiliser le stock en priorité » : le score R10 entre dans le calcul et le stock est **déduit au fur et à mesure** des cases remplies | ✅ |
| 14.3 | **Semaines types** (R16) | `/planning/semaines-types` : enregistrer la semaine affichée comme modèle, puis l'appliquer ailleurs en remplissant les vides ou en remplaçant ; compte rendu de ce qui n'a pas pu être placé | ✅ |
| 14.4 | **Règles de la semaine** | Paramètres → Planning : temps maximum par créneau (avec « du lundi au jeudi seulement »), quotas de catégories (au moins / au plus), « éviter la même catégorie deux jours de suite ». Bilan affiché en haut du planning | ✅ |
| 14.5 | **Envies** | Boîte « À planifier bientôt » sur le planning et l'accueil ; bouton **Envie** sur la fiche recette ; onglet **Envies** dans le choix d'un repas pour la placer sur une case précise ; le remplissage les place en priorité | ✅ |
| 14.6 | **Préparation anticipée** (R21) | Rappels calculés sans aucune saisie : sortir du congélateur, faire tremper, mariner, préparer la veille ; icône ⏰ sur la case du planning, encadré sur l'accueil, « c'est fait » / « ignorer » | ✅ |
| 19.1 | **Centre de notifications** | Cloche du menu : rappels des trois prochains jours + produits à consommer, en un seul endroit. Pastille rouge quand un rappel est en retard | ✅ |
| 14.9 | **Vue liste** | Bouton **Liste / Grille** sur le planning : jours empilés, plus confortable sur téléphone ; le choix reste dans l'adresse (`?vue=liste`) | ✅ |
| 14.10 | Tests | +23 tests (remplissage, semaines types, règles, envies, rappels, cloche, vue liste) — 541 au total | ✅ |

## Règles

### R15 — Remplissage automatique

Pour chaque case vide, dans l'ordre des jours puis des créneaux :

- **Écartées** : recettes archivées ; déjà prévues dans la semaine (y compris par une proposition précédente) ; mangées dans les 14 derniers jours ; dangereuses pour un invité de la case (allergie) ; plus longues que le temps maximum du créneau.
- **Score** : `+30` envie · `+ jours depuis la dernière fois` (plafonné à 60, une recette jamais cuisinée vaut 60) · `+0,4 × score R10` si le stock est utilisé · `+10` favorite · `+5` note ≥ 4 · `+15` catégorie encore attendue dans la semaine · `−15` même catégorie que la veille · `−25` si un maximum de catégorie serait dépassé.
- **Tirage** : pondéré parmi les 5 meilleures (5, 4, 3, 2, 1) — la semaine proposée n'est jamais exactement la même. 🔀 relance une case en écartant la proposition précédente.
- **Portions** : convives de la case. Une recette prévue pour **au moins deux convives de plus** est cuisinée en entier et les restes occupent la première case libre du lendemain.
- Le stock est déduit après chaque case : deux recettes ne se disputent pas le même bocal.

### R16 — Semaines types

- Le modèle retient, par jour de la semaine et créneau : la recette, les restes (vers une autre case du modèle) ou le texte libre.
- **Portions recalculées** d'après les convives de la semaine d'arrivée, augmentées de ce que les restes du modèle réclament — sinon les restes ne tiendraient pas.
- Recette archivée depuis, case déjà occupée, restes sans repas d'origine : signalés dans le compte rendu, la case reste vide.
- Les invités de la semaine d'arrivée sont vérifiés : les conflits sont listés sans bloquer.

### R21 — Préparation anticipée

| Déclencheur | Rappel | Quand |
|---|---|---|
| Un ingrédient (non facultatif) dont tout le stock est au congélateur | « Sortir *blanc de poulet* du congélateur » | la veille à l'heure réglée (18 h par défaut) |
| Temps de repos ≥ 6 h | « Préparer *pâte à pizza* » | heure du repas (19 h) − repos − préparation |
| Étape contenant tremper, mariner, macérer, décongeler, « la veille » | « Faire tremper — *houmous maison* » | la veille à l'heure réglée |

Les rappels sont **recalculés** à chaque affichage du planning ou de l'accueil : déplacer un repas décale le rappel, le supprimer l'efface, le marquer « mangé » aussi. « C'est fait » et « ignoré » sont conservés au recalcul.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/planning?vue=liste` | `planner.week` | `Planner\Week` (vue liste 14.9) |
| `/planning/remplir?semaine=` | `planner.fill` | `Planner\FillWeek` |
| `/planning/semaines-types?semaine=` | `planner.templates` | `Planner\Templates` |
| `/parametres/planning` | `settings.planning` | `Settings\Planning` |

## Modèle de données

| Table | Contenu |
|---|---|
| `week_templates` (name unique, notes, created_by) | Semaines types |
| `week_template_meals` (weekday 1–7, meal_slot_id, position, type, recipe_id, free_text, leftover_weekday, leftover_meal_slot_id) | Repas d'un modèle |
| `wishes` (user_id, recipe_id, text, planned_meal_id, planned_at) | Envies « à planifier bientôt » |
| `reminders` (planned_meal_id, key, type, title, detail, due_at, status, handled_at) | Rappels calculés ; `key` unique par repas pour le recalcul |

Réglages dans `settings` : `planning.rules` (temps par créneau, quotas, « éviter la répétition ») et `planning.reminder_hour`.

## Services

| Classe | Rôle |
|---|---|
| `Services\Planning\WeekFiller` | R15 — `propose()`, `apply()` ; ne touche à rien avant validation |
| `Services\Planning\WeekTemplateManager` | R16 — `saveFromWeek()`, `apply()` |
| `Services\Planning\PlanningRules` | 14.4 — lecture, écriture et bilan des règles |
| `Services\Planning\PrepReminderPlanner` | R21 — `sync()`, `syncMeal()`, `due()`, `forWeek()` |

`RecipeSuggester::consume()` a été extrait de `reserve()` pour que le remplissage puisse déduire le stock case après case.

## Choix faits pendant le lot

- **Le remplissage propose, il n'écrit pas.** Tant que « Valider » n'est pas cliqué, rien n'existe en base : on peut relancer autant qu'on veut sans polluer le planning.
- **Bonus non prévu au document 06** : `+15` quand la recette porte une catégorie dont le minimum de la semaine n'est pas atteint. Sans lui, un minimum (« végétarien au moins 2× ») n'aurait été qu'un constat après coup.
- **Restes** : le document parlait d'« une recette qui laisse ≥ 2 portions » sans dire quand cela arrive. Retenu : une recette prévue pour au moins deux convives de plus est cuisinée en entier. C'est aussi ce qui rend la semaine proposée réaliste (un gratin pour 6 ne se divise pas).
- **Le bonus « de saison » (R18) n'est pas appliqué** : les saisons arrivent au lot 19.
- **Pas d'heure sur les créneaux** en base : les rappels de repos partent d'une heure de repas de 19 h, réglable par `BOUFFE_PLANNING_MEAL_HOUR`.
- **Les rappels sont recalculés, pas planifiés** : aucune tâche de fond n'est nécessaire (les notifications poussées arrivent au lot 20).
- **`$slots` est réservé par Livewire 4** (slots de composants) : les propriétés ont été nommées `mealSlots` et `slotRules`.

## Prochain lot

**Lot 15 — Foyer** : préférences de chacun, qui cuisine, envies partagées, réactions 👍 / 👎, membres et rôles.
