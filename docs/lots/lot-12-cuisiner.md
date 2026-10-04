# Lot 12 — Cuisiner avec Bouffe

**Objectif** : cuisiner avec le téléphone posé sur le plan de travail, et pouvoir imprimer une fiche ou le menu de la semaine.

**Statut** : ✅ développé et vérifié en local (425 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 900 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 13 (13.4, 13.5, 13.6, 13.9) et 14.11.

## Mise à jour depuis le lot 11

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # notes de cuisine
php artisan view:clear
php artisan test         # 425 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 12.1 | **Mode cuisine** (13.4) | Plein écran sans menu, gros texte, une étape à la fois ; barre de progression cliquable ; flèches ← → du clavier ; portions modifiables en cours de route ; bouton **Cuisiner** sur la fiche recette et sur l'accueil (repas du jour) | ✅ |
| 12.2 | **Mise en place** | Premier écran : ingrédients mis à l'échelle, cochables, avec le numéro des étapes qui les utilisent ; rappel des dernières notes de cuisine | ✅ |
| 12.3 | **Étapes** | Étape en grand, « faite » cochable, aperçu de l'étape suivante, rappel des ingrédients cités dans l'étape (correspondance sur le nom, insensible aux accents et au pluriel) | ✅ |
| 12.4 | **Minuteurs** (13.5) | Durées détectées dans le texte des étapes (« cuire 25 min », « 1 h 30 », « laisser reposer une demi-heure », « tremper une nuit ») → bouton ⏱ ; plusieurs minuteurs en parallèle, gardés d'une étape à l'autre et après un rechargement ; sonnerie + vibration à la fin | ✅ |
| 12.5 | **Écran maintenu allumé** | API Screen Wake Lock, reprise quand on revient sur l'onglet ; en HTTP (Wi-Fi maison) le navigateur la refuse : la barre du bas l'indique et le reste fonctionne | ✅ |
| 12.6 | **Fin de cuisson** | « Marquer comme mangé » (ouvre la fenêtre de stock du lot 9) et **note de cuisine** ; retour à la recette ou à l'accueil | ✅ |
| 12.7 | **Notes de cuisine** (13.6) | Datées, signées, avec les portions cuisinées ; les 5 dernières en haut de la fiche recette (encadré ambre), les 3 dernières à la mise en place ; chacun peut supprimer les siennes | ✅ |
| 12.8 | **Impression d'une fiche** (13.9) | `/recettes/{recette}/imprimer` : A4, ingrédients à gauche, étapes à droite, options portions / photo / notes, bouton **Imprimer** | ✅ |
| 12.9 | **Impression du menu** (14.11) | `/planning/imprimer?semaine=` : tableau jours × créneaux avec portions et convives, option **liste de courses** (cases à cocher, par rayon) sur une page suivante | ✅ |
| 12.10 | Tests | +21 tests (détection des durées, mode cuisine et navigation, portions, repas planifié et « mangé », notes, recette archivée, impressions) — 425 au total | ✅ |

## Règles

- **Détection des durées** : la durée n'est prise que si l'étape parle de cuisson ou d'attente (cuire, mijoter, four, reposer, tremper, mariner, réfrigérateur…). « Servir avec 20 g de parmesan » ne donne donc pas de minuteur. Formats reconnus : `25 min`, `45 minutes`, `1 h 30`, `1h30`, `2 heures`, `30 s`, « une demi-heure », « un quart d'heure », « une nuit » (12 h). Maximum 24 h.
- **Ingrédients d'une étape** : le nom normalisé de l'ingrédient (sans accents ni pluriel) doit apparaître dans le texte de l'étape.
- **Minuteurs** : enregistrés dans le navigateur (par recette), donc propres à l'appareil ; ils continuent même si l'on change d'étape ou recharge la page, mais pas si l'on ferme l'onglet.
- **Note de cuisine** : 500 caractères, liée au repas planifié quand on vient du planning ; elle n'est pas modifiable (on en ajoute une nouvelle).

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/recettes/{recette}/cuisiner?portions=&repas=&etape=` | `recipes.cook` | `Recipes\Cook` |
| `/recettes/{recette}/imprimer?portions=&photo=&notes=` | `recipes.print` | `PrintController@recipe` |
| `/planning/imprimer?semaine=&courses=1` | `planner.print` | `PrintController@menu` |

## Choix faits pendant le lot

- **Durées recalculées à l'affichage** plutôt qu'enregistrées en base (le document 06 prévoyait une colonne `recipe_steps.timer_minutes`) : modifier le texte d'une étape met le minuteur à jour tout seul, et il n'y a rien à migrer. Une durée « à la main » reste possible plus tard.
- **Pages d'impression séparées** (et non l'impression de la page courante) : mise en page A4 propre, options dans l'adresse, et la fiche peut s'imprimer pour un autre nombre de portions que celui affiché à l'écran.
- **Sonnerie des minuteurs** : trois bips générés par le navigateur, sans fichier audio.
- **Sur iPhone en HTTP**, l'écran n'est pas maintenu allumé (le navigateur refuse l'API sans HTTPS). Le lot 16 (accès sécurisé) réglera ce point ; en attendant, la barre du bas le dit clairement.

## Prochain lot

**Lot 13 — Remplir le carnet** : import d'une recette depuis une URL, collage de texte, saisie rapide des ingrédients, export / import JSON.
