# Lot 11 — Confort au quotidien

**Objectif** : l'application se parcourt en quelques gestes, sur ordinateur comme sur téléphone.

**Statut** : ✅ développé et vérifié en local (404 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1440 / 1280 / 1024 / 768 px et iPhone 390 px, en thème clair et sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 12 (12.1 à 12.4, 12.6, 12.7), 15.5 et règle R22.

## Mise à jour depuis le lot 10

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # alias et fusions d'ingrédients, quantités ajoutées à la main, préférences d'affichage
php artisan view:clear
php artisan test         # 404 tests
```

Optionnel dans `.env` : `BOUFFE_BACKUP_BEFORE_MERGE=true` (sauvegarde avant chaque fusion d'ingrédients, activée par défaut).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 11.1 | **Recherche globale** (12.1) | `Ctrl+K`, `/` ou la loupe de l'en-tête : pages et réglages, recettes, articles en stock (badge de date), liste de courses en cours, ingrédients (y compris leurs autres noms), invités ; flèches ↑ ↓ et Entrée ; actions directes « Que cuisiner ? », « Voir au stock », « Recettes » | ✅ |
| 11.2 | **Bouton « + »** (12.2) | Bouton rond sur téléphone (« + » dans l'en-tête sur ordinateur) : **au stock** (« 6 œufs », « restes de lasagnes » ; nom inconnu → créer l'ingrédient ou plat préparé), **aux courses** (liste en cours, créée si besoin), **un repas** aujourd'hui / demain (ouvre directement la case du planning), **une recette** | ✅ |
| 11.3 | **Accueil « Aujourd'hui »** (12.3) | Blocs repliables (état mémorisé sur l'appareil) : Au menu (portions, convives, « Mangé ? », « Rien de prévu — planifier » ouvre la case), **Restes à finir** (portions à placer → « Placer demain » dans la première case libre ; plats préparés en stock), À consommer rapidement, Courses, Cette semaine (7 jours avec pastilles), Ça fait longtemps ; deux colonnes sur ordinateur | ✅ |
| 11.4 | **Mode sombre** (12.4) | Automatique, clair ou sombre — choisi par appareil (bouton de l'en-tête, menu « Plus », Paramètres → Affichage) ; appliqué avant l'affichage (pas de flash) ; toutes les pages existantes couvertes | ✅ |
| 11.5 | **Fusion d'ingrédients** (12.6, R22) | Paramètres → Ingrédients → **Doublons** : doublons probables (un seul mot proche : « Tomatte » / « Tomate »), « Garder… », « Pas un doublon », fusion manuelle ; fenêtre de confirmation avec l'impact ; sauvegarde avant ; **Annuler** la dernière fusion | ✅ |
| 11.6 | **Autres noms (alias)** | Dans la fiche d'un ingrédient : ajouter / retirer des noms ; reconnus par la recherche, l'ajout rapide, le stock, la saisie des recettes, les articles manuels et les invités ; un alias ne peut pas devenir un nouvel ingrédient | ✅ |
| 11.7 | **Fusion des articles de courses** (15.5) | À la mise à jour de la liste, un article ajouté à la main pour un ingrédient aussi calculé est intégré : « 450 g — dont 200 g ajouté à la main » ; unités non convertibles, article déjà coché ou quantité modifiée à la main : laissés séparés | ✅ |
| 11.8 | **Barre du bas personnalisable** (12.7) | 4 raccourcis au choix (Paramètres → Affichage, par compte) + **Plus** : autres pages, thème, personnaliser, déconnexion | ✅ |
| 11.9 | **Paramètres → Affichage** | Thème, barre du bas, ordre et affichage des blocs de l'accueil | ✅ |
| 11.10 | Tests | +17 tests (recherche, ajout rapide, planning ouvert sur une case, barre du bas, accueil personnalisé, restes, fusion et annulation, alias, doublons, sauvegarde avant fusion, fusion d'articles de courses) — 404 au total | ✅ |

## Règles

- **Doublon probable** : même nombre de mots, un seul mot différent d'une lettre (deux si le mot fait 9 lettres ou plus), dans le même rayon ; ou mêmes lettres sans les espaces. « Tomate » / « Tomate cerise » n'est pas un doublon.
- **Fusion A → B** : recettes, stock, mouvements, articles de listes, articles récurrents, contraintes d'invités et alias de A passent sur B ; les réglages de stock de B sont gardés ; les listes en cours sont recalculées (une seule ligne, quantités additionnées).
- **Annulation** : seulement la dernière fusion ; les lignes rattachées à B depuis la fusion restent sur B.
- **Articles ajoutés à la main** : la quantité est convertie dans l'unité de l'article calculé et conservée à part (`added_quantity`) pour ne pas être comptée deux fois aux mises à jour suivantes ; si l'article était « Déjà en stock », seule la quantité ajoutée reste à acheter.
- **Préférences** : barre du bas et accueil par compte (Pierre et Monique peuvent différer) ; thème et blocs repliés par appareil.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/parametres/affichage` | `settings.display` | `Settings\Display` |
| `/parametres/ingredients/doublons?source=` | `settings.ingredient-duplicates` | `Settings\IngredientDuplicates` |
| `/planning?ajouter=AAAA-MM-JJ&creneau=` | `planner.week` | ouvre la fenêtre « Ajouter un repas » |
| *(toutes les pages)* | — | `Layout\GlobalSearch`, `Layout\QuickAdd` |

## Choix faits pendant le lot

- **Mode sombre sans réécrire les écrans** : les nuances de couleurs sont réassignées en mode sombre (gris inversés, teintes pâles assombries) plutôt que d'ajouter des variantes sur chaque élément ; les nouvelles pages en profitent automatiquement.
- **Envies** : prévues dans le bouton « + » par le document 06, elles arrivent avec leur écran au lot 14.
- **Libellés du menu** sur ordinateur affichés à partir de 1280 px (icônes seules en dessous), pour laisser la place à la recherche, au « + » et au thème.
- **Blocs repliables** mémorisés sur l'appareil (et non sur le compte) : on peut replier « Idées » sur le téléphone et le garder ouvert sur l'ordinateur.

## Prochain lot

**Lot 12 — Cuisiner avec Bouffe** : mode cuisine, minuteurs, notes de cuisine, impression d'une fiche et du menu de la semaine.
