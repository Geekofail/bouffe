# Lot 6 — Convives & invités

**Objectif** : planifier des repas à plus de deux (amis, famille, absence d'un membre du foyer), avec des portions et des restes justes, et être prévenu des allergies et des goûts des invités.

**Statut** : ✅ développé et vérifié en local (320 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur ordinateur 1440 px, tablette 1024 / 768 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md), module 8 et règle R7.

## Mise à jour depuis le lot 5

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # tables guests, guest_restrictions, meal_occasions, meal_occasion_guest (+ colonne servings de la provenance)
php artisan view:clear
php artisan test         # 320 tests
```

Aucune donnée de départ. Réglage facultatif dans `.env` : `BOUFFE_CHILD_PORTION=0.5` (part de portion d'un enfant).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 6.1 | Modèle de données | Invités, contraintes alimentaires, convives d'une case (date × créneau) et invités présents ; enum `RestrictionType` (allergie, n'aime pas, régime) | ✅ |
| 6.2 | Règle R7 — `OccasionService` | Convives = présents du foyer + adultes + enfants × 0,5 (arrondi supérieur) ; sans saisie : foyer complet ; enregistrement supprimé quand on revient à la situation par défaut | ✅ |
| 6.3 | Planning selon les convives | Portions proposées (sélecteur, repas libre, restes), calcul des restes et contrôle des portions minimales avec les convives du repas au lieu de la taille du foyer | ✅ |
| 6.4 | Fenêtre « Convives » | Bouton 👥 dans chaque case : présents du foyer (« Monique absente ce midi »), invités du carnet, **tout un groupe d'un coup**, création d'un invité à la volée, adultes et enfants sans nom, occasion, note, total en direct, contraintes des invités, plats déjà prévus avec leurs alertes | ✅ |
| 6.5 | Adapter les portions | Après un changement de convives : « Adapter les portions du plat (2 → 6) ? ». Les portions prévues en plus pour les restes sont gardées (4 pour 2 → 8 pour 6) ; un plat dont des restes sont déjà placés n'est pas réduit en dessous du nécessaire | ✅ |
| 6.6 | Affichage dans le planning | Badge « 👥 6 · Anniversaire de Julie » dans la case, « 1 repas avec invités » dans l'en-tête, ⚠ rouge (allergie) ou orange sur les plats en conflit, convives et alertes dans le détail d'un repas | ✅ |
| 6.7 | Carnet d'invités | Menu **Invités** (ordinateur) ou bouton **Invités** du planning (téléphone) : invités par groupe, recherche, archivés ; fenêtre de création / modification avec contraintes | ✅ |
| 6.8 | Contraintes alimentaires | Allergie / intolérance et « n'aime pas » sur un ingrédient (créé dans le rayon Divers s'il n'existe pas), régime = catégorie de recette requise (ex. Végétarien) ; une allergie prime sur « n'aime pas » pour le même ingrédient | ✅ |
| 6.9 | Sélecteur de recette | Bandeau des convives, portions pré-remplies, alertes sur chaque recette, filtre **« Compatibles avec les convives »**, **« Déjà servi à Paul le 1 juin 2026 »** et tri « Jamais servies à ces invités d'abord » | ✅ |
| 6.10 | Fiche recette depuis un repas | Lien « Voir la recette » : portions du repas, convives et ingrédients à risque surlignés (rouge / orange) | ✅ |
| 6.11 | Fiche invité | Contraintes, notes, **repas partagés** (date, créneau, occasion, plats), archiver ; suppression seulement s'il n'a jamais été reçu | ✅ |
| 6.12 | Liste de courses pour ce repas | Depuis la fenêtre Convives : génération limitée au jour, seuls les plats du repas cochés, nom « Courses — Anniversaire de Julie » ; la provenance indique les portions (« sam. 19 dîner, 6 portions ») | ✅ |
| 6.13 | Accueil | Convives et occasion affichés à côté du créneau dans « Au menu aujourd'hui » | ✅ |
| 6.14 | Protections | Ingrédient utilisé dans une contrainte, catégorie utilisée comme régime, créneau avec des convives : suppression refusée avec un message | ✅ |
| 6.15 | Tests | +31 tests (règle R7, compatibilité, « déjà servi », carnet, écrans) — 320 au total | ✅ |

## Règles implémentées

- **Convives** = membres du foyer présents + invités adultes (nommés ou non) + invités enfants × `BOUFFE_CHILD_PORTION`, arrondi à l'entier supérieur. Exemple : 2 + Julie + Paul + 1 enfant = 4,5 → **5**.
- Tout le monde absent sans invité : 0 convive, 1 portion proposée au minimum.
- **Restes** = portions cuisinées − min(portions, convives du repas) − restes déjà placés. Lasagnes pour 8 à 6 convives → 2 portions.
- Modifier les convives ne change jamais les portions sans confirmation.
- **Alertes** : jamais bloquantes (Q10). Allergie = rouge ; « n'aime pas » et régime non respecté = orange ; ingrédient facultatif signalé « (facultatif) ».
- **Déjà servi** : repas « recette » passés, dans une case où l'invité était convive.
- « Vider la semaine » et « Copier la semaine » ne touchent pas aux convives : on peut inviter avant de choisir le menu, et des invités ne se recopient pas d'une semaine à l'autre.

> ⚠ Les alertes ne portent que sur les ingrédients saisis dans les recettes : un allergène caché dans un produit transformé (pesto, bouillon, sauce) n'est pas détecté. Le rappel est affiché dès qu'un invité a une allergie.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/invites?q=&archives=1` | `guests.index` | `Guests\Index` (+ `Guests\GuestEditor`) |
| `/invites/{id}` | `guests.show` | `Guests\Show` |
| *(fenêtre du planning)* | — | `Planner\OccasionEditor` |
| `/recettes/{recette}?repas={id}` | `recipes.show` | contraintes du repas |
| `/courses?generer=AAAA-MM-JJ&repas=12,13` | `shopping.index` | liste pour un repas |

## Choix faits pendant le lot

- **Groupes = simple champ « groupe » de l'invité** (Famille, Amis…) plutôt qu'une table dédiée : « Tout « Amis » » ajoute tous les invités actifs du groupe. Suffisant à l'usage, sans écran de gestion supplémentaire.
- **Facteur enfant dans `.env`** et non dans une table de réglages : aucun écran ne l'utilise encore ; la table `settings` prévue sera créée quand les réglages du stock (lots 8-9) auront besoin d'un écran.
- **Convives rattachés à la case, pas au plat** : l'entrée, le plat et le dessert d'un même repas partagent les mêmes convives ; supprimer un plat ne supprime pas les invités.
- **Ingrédient inconnu créé automatiquement** dans une contrainte (ex. « Crevette ») pour ne pas bloquer la saisie d'une allergie ; le message l'indique, et on peut ensuite le ranger dans le bon rayon.
- **Portions adaptées par écart** (`portions − anciens convives + nouveaux`) pour ne pas perdre les restes prévus.

## Prochain lot

**Lot 7 — Stock : saisie et consultation** : emplacements, articles en stock et dates, écran Stock, rangement des courses, consommer / jeter / ouvrir / congeler.
