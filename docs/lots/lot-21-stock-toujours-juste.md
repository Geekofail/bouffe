# Lot 21 — Stock toujours juste

**Objectif** : le stock suit les repas sans qu'on y pense.

**Statut** : ✅ développé et vérifié en local (720 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [07-version-3.md](../07-version-3.md), module 22, règles **R23** et **R24**.

## Mise à jour depuis le lot 20

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Rien d'autre : la tâche planifiée du lot 20 (`bouffe:reminders`) fait aussi, désormais, la clôture
automatique des repas et les consommations régulières.

**Changement de réglage** (question Q30, proposition par défaut appliquée) : sur ton installation,
le retrait du stock au repas mangé passe en **Automatique**, avec un bouton « Annuler » dans le
message qui s'affiche. Si tu avais déjà choisi un mode dans Paramètres → Alertes stock, il est
conservé. Une installation neuve reste en « Demander ».

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 22.1 | **Clôture des repas passés** (R23) | « C'était mangé ? » dans la cloche et en haut de l'accueil : **Mangé** (retrait du stock) ou **Pas mangé** (rien n'est retiré, « Replacer » dans la prochaine case libre) ; « Pas mangé » aussi depuis le planning ; option « mangé d'office après N jours » ; rattrapage « Tout marquer mangé, sans toucher au stock » | ✅ |
| 22.2 | **Retrait jamais perdu** | Une fenêtre de retrait fermée sans réponse (ou une page quittée) laisse le repas dans « Stock à régulariser » ; « Ne rien retirer » est un vrai choix ; en mode automatique, message avec **Annuler** | ✅ |
| 22.3 | **Stock réservé** (R24) | Les repas des 14 prochains jours réservent leur part : « 6 œufs réservés » sur la page Stock, détail et « disponible » dans la fiche ; conflits signalés sur le planning ; la liste de courses ne compte plus le stock promis aux repas d'avant elle | ✅ |
| 22.4 | **Consommations hors repas** | Bouton + → « J'ai utilisé… » (« 2 œufs et 20 cl de lait ») ; consommations régulières (« 25 cl de lait par jour ») dans Paramètres → Alertes stock | ✅ |
| 22.5 | **Écarts signalés** | Bandeau « N articles à vérifier » sur la page Stock : date dépassée depuis plus de 7 jours, fond de paquet, produit frais immobile depuis 3 semaines ; un geste par article ; pas reproposé avant 2 semaines | ✅ |
| 22.6 | **Retrait au fil du mode cuisine** | Option : cocher un ingrédient dans le mode cuisine ouvert depuis le planning le retire aussitôt ; « mangé » ne le retire pas une seconde fois | ✅ |
| — | Tests | +20 tests — 720 au total | ✅ |

## Ce qui change au quotidien

**Le lendemain matin**, les repas de la veille non cochés apparaissent en haut de l'accueil et dans
la cloche. Un geste : *Mangé* ou *Pas mangé*. En mode automatique, *Mangé* retire aussitôt le stock
et un message « 5 articles retirés — **Annuler** » reste affiché 8 secondes.

> **Première ouverture après la mise à jour** : tous les repas des 14 derniers jours non cochés
> vont apparaître. Si ton stock est déjà à jour, le lien « Tout marquer mangé, sans toucher au
> stock » (sous la liste de l'accueil) les clôture d'un coup sans rien retirer.

**Sur la page Stock**, un article réservé porte une pastille bleue « 3 œufs réservés » ; sa fiche
dit pour quels repas et ce qui reste vraiment disponible. Sur le planning, une petite icône orange
sur un repas signale que son stock est déjà pris par un repas plus proche : sa fiche dit combien
il manquera, et la liste de courses en tient compte.

## R23 — clôture des repas passés

- Concerne les repas « recette » et « restes » d'hier à il y a 14 jours, ni mangés ni « pas fait ».
  Avant 7 h du matin, la veille n'est pas encore demandée.
- **Pas mangé** : rien n'est retiré, le repas reste visible (grisé, « pas fait ») à sa date.
  « Replacer » le recopie dans la prochaine case libre du même créneau (7 jours au plus).
- **Mangé d'office après N jours** (Paramètres → Alertes stock, désactivé par défaut) : la tâche
  planifiée marque le repas mangé et applique le retrait sans fenêtre. Ces repas restent listés
  trois jours dans la cloche avec « Pas mangé, en fait », qui remet le stock.

*Écart assumé avec le document 07* : pas de colonne `status` sur les repas — `cooked_at` (mangé)
existait déjà, il suffisait d'ajouter `skipped_at` (pas fait) pour éviter deux informations à tenir
en cohérence.

## R24 — stock réservé

- Les repas « recette » des 14 prochains jours, ni mangés, ni « pas faits », ni cuisinés à l'avance,
  réservent ce que le retrait R9 prendrait, sous-recettes comprises.
- Ordre : date, puis ordre des créneaux, puis ordre dans la case. Le premier repas se sert d'abord,
  dans l'article qui périme le premier.
- Un article dont la date sera dépassée le jour du repas ne lui est pas réservé.
- **Conflit** : le stock aurait suffi pour ce repas seul, mais des repas plus proches l'ont pris.
  Un simple manque (pas assez en stock de toute façon) n'est pas un conflit : c'est le rôle de la
  liste de courses.
- Rien n'est enregistré : tout se recalcule, déplacer ou supprimer un repas suffit.

La page « Que cuisiner ? » réservait déjà le stock des 7 prochains jours depuis le lot 10 : elle
est inchangée.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_03_100000_create_lot21_tables.php` | `planned_meals.skipped_at` / `closed_automatically` / `stock_state`, `stock_items.checked_at`, `stock_usage_rules`, passage en retrait automatique (Q30) |
| `app/Services/Planning/MealClosing.php` | R23 : repas à clôturer, pas fait, replacer, clôture automatique |
| `app/Services/Stock/StockReservations.php` | R24 : réservations, conflits, stock promis avant une liste |
| `app/Services/Stock/StockUsage.php` | « J'ai utilisé… », consommations régulières |
| `app/Services/Stock/StockDiscrepancies.php` | Articles à vérifier |
| `app/Livewire/Concerns/ClosesMeals.php` | Réponses communes à la cloche et à l'accueil |
| `app/Models/StockUsageRule.php` | Consommation régulière |
| `tests/Feature/Stock/StockAlwaysRightTest.php` | 20 tests |

Fichiers modifiés : `MealStockService` (retrait par défaut, ingrédient par ingrédient, jamais deux
fois), fenêtre de retrait (désormais **une seule pour toute l'application**, état en attente,
Annuler), messages éphémères (bouton d'action), cloche, accueil, planning (pas fait, conflits),
page Stock (réservations, vérification), mode cuisine, bouton +, Paramètres → Alertes stock,
liste de courses (R8 + R24), tâche planifiée.

## À vérifier sur ton poste

- `bouffe:deploy`, puis l'accueil : les repas passés non cochés doivent apparaître.
- Marque un repas **Mangé** : le message « Stock : … — Annuler » doit apparaître ; essaie Annuler.
- Page **Stock** : pastilles « réservés » et, s'il y a lieu, le bandeau « à vérifier ».
- Bouton **+** → **J'ai utilisé…** → « 2 œufs ».
- Paramètres → **Alertes stock** : ajoute « 25 cl de lait » par jour si c'est ta consommation.
