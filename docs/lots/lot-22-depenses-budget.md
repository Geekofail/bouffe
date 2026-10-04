# Lot 22 — Dépenses et budget réel

**Objectif** : le vrai budget, restaurant compris, face à ce qu'on s'était fixé.

**Statut** : ✅ développé et vérifié en local (748 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 px en mode clair et iPhone 390 px en mode sombre, sans débordement horizontal) — ⏳ à valider sur ton poste.

Spécification : [07-version-3.md](../07-version-3.md), module 23, règles **R25** et **R26**, compléments **C5** et **C7**.

## Mise à jour depuis le lot 21

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La migration crée les six postes et reprend ton budget mensuel du lot 17 : il devient celui du
poste **Courses alimentaires**, sans rien changer d'autre. La tâche planifiée du lot 20
(`bouffe:reminders`) compte aussi, désormais, les dépenses récurrentes le jour de leur échéance.

**Réglages par défaut appliqués** (questions sans réponse) :
- Q31 : les six postes du 23.2, **sans montant**, période = **mois civil**.
- Q34 : « qui a payé » **désactivé** (activable dans Paramètres → Budget).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 23.1 | **Saisie d'une dépense** | Bouton + → **Une dépense**, ou « Dépense » sur la page Budget : montant, date, poste, magasin ou lieu. Le poste est proposé d'après la dernière dépense au même endroit ; « Payé par » si le réglage est actif | ✅ |
| 23.2 | **Postes** | Courses alimentaires, Droguerie et maison, Restaurant, À emporter / livraison, Midi au travail, Boissons ; renommer, recolorer, classer, ajouter, archiver (sauf les courses) | ✅ |
| 23.3 | **Ticket mixte** | « Répartir entre plusieurs postes » : la somme doit tomber juste au centime | ✅ |
| 23.4 | **Restaurant lié au planning** | Sur un repas libre passé du planning : « Enregistrer la dépense » (poste Restaurant, lieu, date et convives pré-remplis ; une seconde fois : « Dépense : 64 € — modifier ») ; à l'inverse, une dépense restaurant / à emporter / midi peut **ajouter le repas au planning** | ✅ |
| 23.5 | **Tableau de bord** | Page **Budget** : par poste, dépensé / budget, repère du **rythme**, projection de fin de période (R25), reste par semaine ; « Plus de 80 % », « Au rythme actuel, dépassement », « Dépassé » (icône + texte, pas seulement la couleur) ; périodes précédentes ; liste des dépenses filtrable | ✅ |
| 23.6 | **Analyses** | Sur 3, 6 ou 12 mois : total par période (barres), tableau par poste, magasins et lieux (passages, panier moyen), coût d'un repas par personne à la maison et dehors, planning prévu face aux courses réelles | ✅ |
| 23.7 | **Période budgétaire** | Mois civil, ou « du 25 au 24 » ; un budget modifié ne s'applique qu'à partir de la période en cours | ✅ |
| 23.8 | **Qui a payé** | Facultatif : total par personne et « Pierre a avancé 46 € de plus sur la période » | ✅ |
| 23.9 | **Export** | Bouton **CSV** de la liste : une ligne par poste (ticket mixte compris), séparateur « ; », virgule décimale — s'ouvre directement dans Excel | ✅ |
| C5 | **Gaspillage en euros** | Valeur estimée de ce qui a été jeté sur la période (tableau de bord et analyses), avec le nombre de produits sans prix | ✅ |
| C7 | **Dépenses récurrentes** | Paramètres → Budget : cantine, panier bio, abonnement — chaque mois ou chaque semaine, pause, suppression | ✅ |
| — | Tests | +28 tests — 748 au total | ✅ |

## R25 — rythme et projection

- **Rythme** = budget × jours écoulés ÷ jours de la période : c'est le petit trait noir sur la barre.
- **Projection** = dépensé + moyenne des 3 périodes précédentes × part de la période restante.
  Sans 3 périodes d'historique : dépensé ÷ jours écoulés × jours de la période, marqué « ≈ » et
  « Projection fragile ».
- Alertes : 80 % du budget dépensé ; projection au-delà du budget + 5 % ; budget dépassé.
- Une dépense datée dans le futur est refusée.

## R26 — pas de double compte

- Le poste **Courses alimentaires** additionne les dépenses saisies **et** les prix cochés en
  magasin (lot 17) des listes **sans ticket**.
- Saisir un ticket propose la liste cochée le jour même ou la veille dans ce magasin : une fois
  rattachée, ses prix cochés ne comptent plus — le ticket fait foi. Les prix restent utilisés pour
  estimer le coût des recettes.
- Même lieu, même jour, même montant : « Une dépense identique existe déjà… Enregistrer quand
  même ? ».

## Des chiffres honnêtes

- **Coût d'un repas à la maison** = courses alimentaires ÷ convives des repas marqués mangés. Il
  n'est affiché que si ces repas couvrent au moins la moitié des jours de la période pour tout le
  foyer ; sinon « Trop peu de repas marqués mangés », plutôt qu'un chiffre absurde.
- **Planning prévu / courses réelles** : l'écart n'est calculé que si tous les ingrédients
  planifiés ont un prix ; sinon « au moins 23,91 € » et le nombre d'ingrédients sans prix.
- **Gaspillage** : estimé avec les prix de référence ; un produit sans prix ne compte pas.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_04_100000_create_lot22_tables.php` | Postes, budgets datés, dépenses, répartitions, récurrentes ; reprise du budget du lot 17 |
| `app/Models/BudgetCategory.php`, `BudgetAmount.php`, `Expense.php`, `ExpenseSplit.php`, `RecurringExpense.php` | Modèles |
| `app/Services/Budget/BudgetTracker.php` | Périodes, budgets, saisie, R25, R26, qui a payé, gaspillage |
| `app/Services/Budget/BudgetAnalysis.php` | Analyses (23.6) |
| `app/Services/Budget/RecurringExpenses.php` | Dépenses récurrentes (C7) |
| `app/Livewire/Budget/Index.php`, `Analyses.php`, `ExpenseForm.php` + vues | Pages Budget, Analyses, fenêtre de saisie (commune à toute l'application) |
| `app/Http/Controllers/ExpenseExportController.php` | Export CSV |
| `tests/Feature/Budget/BudgetTest.php` | 28 tests |

Fichiers modifiés : `Pricing\Budget` (le budget du lot 17 lit désormais le poste Courses
alimentaires), Paramètres → Budget (refait), navigation (Budget dans la barre du haut et dans
« Plus » sur téléphone), bouton +, planning (repas libre), tâche planifiée, routes.

## À vérifier sur ton poste

- `bouffe:deploy`, puis **Paramètres → Budget** : ton budget du lot 17 est sur « Courses
  alimentaires » ; fixe ceux des autres postes si tu le souhaites.
- Bouton **+** → **Une dépense** → « 23,90 » à la boulangerie.
- Page **Budget** : les cartes par poste, le trait du rythme, puis **Analyses**.
- Un repas libre d'hier au planning (« Restaurant ») → **Enregistrer la dépense**.
- Le bouton **CSV** de la liste s'ouvre dans Excel avec les accents.
