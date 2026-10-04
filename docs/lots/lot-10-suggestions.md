# Lot 10 — Suggestions depuis le stock

**Objectif** : trouver des idées de repas qui vident le frigo, en priorité ce qui périme bientôt.

**Statut** : ✅ développé et vérifié en local (387 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur ordinateur 1280 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md), module 10 (10.1 à 10.4) et règle R10.

## Mise à jour depuis le lot 9

Pas de migration.

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan view:clear
php artisan test         # 387 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 10.1 | Service `RecipeSuggester` (R10) | Stock utilisable (DLC dépassée exclue, congelés comptés), quantités mises à l'échelle, conversions d'unités ; statut par ingrédient : en stock · à vérifier · partiel · manquant · produit de base ; couverture, score et groupes | ✅ |
| 10.2 | Écran **Que cuisiner ?** (`/que-cuisiner`) | Portions, temps max, catégories, **Doit utiliser** (ingrédients en stock, ceux qui périment en premier, « J-3 »), **Ignorer le planning** ; groupes **Faisable tout de suite** et **Presque**, lien « Voir les autres recettes » | ✅ |
| 10.3 | Cartes | Photo, titre, « 5/7 ingrédients » avec barre, temps, note, **♻ utilise 2 produits à consommer vite**, « Manque : feta 150 g, olives 60 g », « Mangée ces deux dernières semaines » | ✅ |
| 10.4 | Actions | **Planifier** (date, créneau, portions ; pré-remplis et retour au planning si l'on vient d'une case) ; **Ajouter les manquants** à la liste en cours (créée pour 7 jours s'il n'y en a pas), quantité manquante, note « Pour « Salade grecque » », pas de doublon | ✅ |
| 10.5 | Réservations du planning | Le stock promis aux repas « recette » des 7 prochains jours (non mangés) n'est pas proposé ; mention « Le stock prévu pour 3 repas planifiés… n'est pas compté » | ✅ |
| 10.6 | Planning : onglet **Avec mon stock** | Dans « Ajouter un repas » : recettes faisables puis presque pour les portions de la case, un clic planifie ; lien « Plus d'options » vers l'écran complet | ✅ |
| 10.7 | Fiche recette | Badge par ingrédient **✓ en stock**, **◐ manque 200 ml**, **? à vérifier**, **✗ manquant** ; résumé « Stock : 5/7 ingrédients » et **Ajouter les manquants aux courses** ; suit l'ajusteur de portions ; rien tant que le stock n'est pas utilisé | ✅ |
| 10.8 | Accueil | Dans « À consommer rapidement » : encadré **♻ À utiliser rapidement** (3 produits, « 4 recettes possibles » → Que cuisiner ? pré-rempli) ; bouton **Recette…** par produit | ✅ |
| 10.9 | Autres accès | Bandeau du planning **Idées de recettes**, bouton **Que cuisiner ?** sur la page Stock, **Avec mon stock** sur la page Recettes | ✅ |
| 10.10 | Tests | +15 tests (groupes, portions, base épuisée, DLC dépassée, score, réservations, filtres, page, planifier, manquants, onglet du planning, fiche recette, accueil) — 387 au total | ✅ |

## Règles (R10)

- **Lignes comptées** : ingrédients non facultatifs. Ingrédient non suivi, ou **produit de base jamais mis en stock** : réputé disponible. Produit de base suivi et épuisé : manquant.
- **Couverture** = disponibles / comptés, une ligne partielle vaut ½ ; « à vérifier » (quantité inconnue) compte comme disponible.
- **Score** = 100 × couverture + anti-gaspi (+15 par produit utilisé à J+2 max, +8 à J+5, +20 avec un « doit utiliser », plafond 40) − 20 si mangée ces 14 derniers jours + 5 si favorite + 5 si note moyenne ≥ 4.
- **Presque** = 1 ou 2 manques **et au moins la moitié des ingrédients disponibles** (une tartine « 0/2 » n'est pas « presque » : précision ajoutée à la spécification).

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/que-cuisiner?portions=&duree=&categories[]=&utiliser[]=&tout-le-stock=1&date=&creneau=` | `suggestions` | `Stock\Suggestions` |
| `/recettes/{recette}?portions=4` | `recipes.show` | `Recipes\Show` (portions pré-réglées) |

## Choix faits pendant le lot

- **Calcul à la volée, rien en base** : le stock est lu une fois par page puis chaque recette y est confrontée (rapide pour quelques centaines de recettes).
- **Congelés comptés comme disponibles**, sans bonus anti-gaspi.
- **Fiche recette sans réservations** : on voit le stock tel qu'il est ; l'écran Que cuisiner ?, lui, les applique par défaut.
- **Articles « manquants » ajoutés comme articles manuels** : ils ne sont pas supprimés par la régénération de la liste ; si la recette est ensuite planifiée et la liste régénérée, l'ingrédient peut apparaître deux fois (à supprimer à la main).

## Prochain lot

**Lot 11 — V2** (au choix) : import de recettes depuis une URL, mode cuisine, remplissage automatique de la semaine (dont « depuis le stock »), semaines types, saisons, coût, PWA et notifications, code-barres, statistiques anti-gaspillage.
