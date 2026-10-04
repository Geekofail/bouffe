# Lot 9 — Stock ↔ planning & courses

**Objectif** : le stock se met à jour presque seul, et on n'achète plus ce qu'on a déjà.

**Statut** : ✅ développé et vérifié en local (372 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur ordinateur 1280 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md), module 9 (9.8 à 9.11) et règles R7, R8, R9.

## Mise à jour depuis le lot 8

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # colonnes stock des listes de courses + unité « portion »
php artisan view:clear
php artisan test         # 372 tests
```

Optionnel dans `.env` : `BOUFFE_STOCK_DEDUCTION=ask` (valeur par défaut tant que rien n'est enregistré dans Paramètres → Alertes stock).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 9.1 | Retrait au « mangé » (R9) | Cocher « mangé » (accueil ou planning) ouvre **Mettre à jour le stock** : une ligne par ingrédient en stock, les articles qui périment le plus tôt d'abord, « −6 pièces (terminé) · −2 pièces (reste 4 pièces) » ; décochable ligne par ligne ; **Ne rien retirer** | ✅ |
| 9.2 | Cas particuliers | Quantité inconnue ou unité non comparable : « quantité inconnue : marquer terminé ? » (non coché) ; mode présence : « Il n'en reste plus » (non coché) ; pas assez en stock : « pas assez en stock » ; produits congelés ignorés ; ingrédient absent du stock : pas de ligne | ✅ |
| 9.3 | Restes au frigo | Portions non mangées et non planifiées (R7) : « Ranger « Restes de lasagnes » au réfrigérateur », nombre de portions modifiable, DLC à J+3, plat préparé lié au repas | ✅ |
| 9.4 | Repas « restes » | Le plat préparé correspondant est retiré du stock (portions mangées, ou terminé) | ✅ |
| 9.5 | Décocher « mangé » | **Remettre dans le stock ?** : chaque article revient à son état d'avant, restes rangés retirés ; un article modifié depuis n'est pas touché (message) ; sans limite de délai | ✅ |
| 9.6 | Réglage | Paramètres → Alertes stock → **Repas mangé** : Demander (défaut) · Automatique (sans fenêtre, décocher remet le stock) · Jamais | ✅ |
| 9.7 | Liste de courses (R8) | Case **Déduire ce qui est déjà en stock** (cochée) ; article couvert → section repliable **Déjà en stock** avec **Acheter quand même** ; partiel → quantité réduite « Besoin 600 g − en stock 200 g » ; quantité inconnue → « En stock : … — vérifier » ; produit qui périme avant le repas → « périme le 18/09, prévu le 20/09 — non compté » ; produit de base épuisé → dans son rayon | ✅ |
| 9.8 | Régénération | Recalcule avec le stock du moment ; les quantités modifiées à la main ne sont pas touchées | ✅ |
| 9.9 | Stock minimum | Réglage sur l'ingrédient (Paramètres → Ingrédients) ; sous le seuil → article **Stock bas** ajouté à la liste générée (quantité manquante) ; page Stock : filtre **Sous le minimum (n)** + **Ajouter à la liste en cours** | ✅ |
| 9.10 | Inventaire | Page Stock, un emplacement choisi → **Faire l'inventaire** (« dernier inventaire il y a 2 mois ») ; par article **✓ Toujours là** · **Quantité** · **✗ Plus là** ; **Terminer** enregistre la date | ✅ |
| 9.11 | Tests | +17 tests (proposition, retrait et annulation, article modifié depuis, restes, fenêtre et réglages, courses R8, minimum, inventaire, milliers dans les quantités) — 372 au total | ✅ |

## Règles

- **Ordre de retrait (R9)** : date effective la plus proche d'abord ; congelés exclus.
- **Article terminé** : quantité restante nulle, moins d'une pièce, ou au plus 2 % de la quantité de départ.
- **Restes proposés** = portions − min(portions, convives) ; plus rien proposé une fois les restes rangés.
- **R8** : un ingrédient jamais mis en stock n'est pas concerné (liste inchangée) ; seuls les articles encore bons à la date du repas comptent.
- **Stock minimum** : une quantité inconnue en stock ne déclenche rien (on ne sait pas) ; en mode présence, tout minimum signifie « doit être en stock ».

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/stock/inventaire/{emplacement}` | `stock.inventory` | `Stock\Inventory` |
| `/stock?filtre=minimum` | `stock.index` | `Stock\Index` |
| *(accueil, planning)* | — | `Stock\MealStockDialog` |

## Choix faits pendant le lot

- **La fenêtre recalcule la proposition au moment de valider** : seules les cases cochées viennent du navigateur, pas les quantités (plus sûr, et à jour si le stock a bougé).
- **Annulation sans délai pour les repas** : contrairement au bouton « Annuler » de la page Stock (15 min), décocher « mangé » le lendemain remet encore le stock.
- **Pas de ligne « manquant »** dans la fenêtre : si un ingrédient n'est pas en stock, il n'y a rien à retirer.
- **Unité « portion »** ajoutée pour les restes (visible dans Paramètres → Unités).
- **Correction** : une quantité affichée avec séparateur de milliers (« 1 500 ») est maintenant acceptée à la saisie (fenêtre d'un article du stock, inventaire).

## Prochain lot

**Lot 10 — Suggestions depuis le stock** : écran « Que cuisiner ? », score anti-gaspillage, disponibilité sur la fiche recette.
