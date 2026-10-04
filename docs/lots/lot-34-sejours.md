# Lot 34 — Séjours et grandes tablées

**Objectif** : les vacances à plusieurs, courses et comptes compris.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **992 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+12).
- **72 vérifications dans le navigateur** passent (+12) : la liste des séjours et les cinq onglets
  d'un séjour, sur iPhone et sur ordinateur.
- **Une migration** : cinq tables (`stays`, `stay_participants`, `stay_meals`, `stay_payments`,
  `stay_packed_items`) et une colonne `shopping_lists.stay_id`. Rien n'est modifié dans les données
  existantes.

C'est le dernier lot de la version 4 : l'ordre prévu (28 → 29 → 30 → 32 → 31 → 36 → 33 → 35 → 34)
est terminé.

Spécification : [08-version-4.md](../08-version-4.md), module 34 et règle R37.

## Mise à jour depuis le lot 35

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-34-sejours
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 34 — séjours"
git push -u origin lot-34-sejours
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 34.1 | **Un séjour** | Menu **Plus › Séjours** (`/sejours`) : nom, dates (31 jours au plus), lieu, notes. À la création, les personnes à table d'habitude sont ajoutées. Onglet **Participants** : le foyer, des **invités du carnet** (avec leur appétit et leurs contraintes), un **foyer relié** (ses comptes ; leurs contraintes s'ils ont choisi de les partager, 26.6), ou **une autre personne** (prénom et appétit). Pour chacun : appétit, **groupe** (qui paie avec qui) et jours d'arrivée et de départ. Onglet **Repas** : jour par jour et créneau par créneau, recettes du carnet ou texte libre (« Restaurant »), portions calculées d'après les présents du jour (R33), modifiables plat par plat ; alertes d'allergies et de régimes d'après les présents. **Rien n'apparaît dans le planning de la maison** ; un bandeau y signale le séjour | ✅ |
| 34.2 | **Courses du séjour** | Onglet **Courses** : **Préparer la liste**, calculée sur les repas du séjour (sous-recettes comprises), **sans le stock de la maison**, sauf ce qu'on emporte. Elle s'ouvre, se coche et passe en mode magasin comme une liste ordinaire ; **Mettre à jour** la recalcule d'après les repas du séjour. Elle n'apparaît pas parmi les listes en cours de la maison (l'ajout rapide ne s'y perd pas) et ne propose pas de « ranger dans le stock ». Si un foyer relié vient, elle lui est ouverte (liste groupée, 26.8) | ✅ |
| 34.3 | **Frais partagés** | Onglet **Frais** : chaque dépense (qui a payé, quoi, combien, quand) — courses, location, essence… Partage **par appétit** ou **par personne**, au prorata des jours de présence ; part, avance et solde de chaque groupe ; **le moins de remboursements possible**, montants au centime, l'arrondi restant à celui qui a le plus avancé (R37). **Remboursé** se coche à la main : Bouffe ne fait aucun paiement. Rien ne va dans le budget de la maison | ✅ |
| 34.4 | **Emporter de la maison** | Onglet **À emporter** : choisir dans le stock ce qui part, et en quelle quantité ; la liste du séjour en tient compte (« besoin 625 g · emporté de la maison 500 g »). **C'est parti** retire ces articles du stock ; au retour, on indique ce qui revient et c'est **remis dans le stock** (dans l'article d'origine s'il y est encore, sinon dans un nouvel article au même endroit) | ✅ |
| — | Tests | +12 tests Pest ; +12 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Où vit le séjour** : chez le foyer qui l'organise. Un foyer relié qui participe ne voit pas la
  fiche du séjour ; il voit la liste de courses (liste groupée) et peut y ajouter ses articles. Les
  frais se notent chez l'organisateur.
- **Groupes** : la part des frais est portée par le groupe (une famille, un couple, une personne
  seule). Le foyer, un invité (son groupe du carnet, sinon son nom) et un foyer relié (son nom) ont
  un groupe par défaut, modifiable. Changer le groupe d'une personne ne change pas les dépenses déjà
  notées au nom de l'ancien groupe.
- **Part de chacun (R37)** : le poids d'une personne est son appétit (ou 1 « par personne »), multiplié
  par ses jours de présence. Un groupe qui a payé sans venir apparaît avec une part nulle.
- **Remboursements** : d'abord les dettes qui s'annulent exactement deux à deux, puis le plus gros
  débiteur rembourse le plus gros créancier ; au plus « nombre de groupes − 1 » virements. Seul un
  remboursement proposé peut être coché ; il compte ensuite dans les soldes.
- **Portions** : la somme des parts des présents, arrondie à la demi-portion supérieure, comme au
  lot 32. Un repas dont on fixe les portions ne suit plus les arrivées et départs.
- **Planning** : les créneaux sont ceux de la maison (déjeuner, dîner…). Changer les dates du séjour
  retire les repas qui tombent en dehors.
- **À emporter** : un article sans quantité (« en stock ») part en entier et revient s'il est coché.
  Les mouvements de stock gardent la raison « séjour » : les statistiques du lot 35 ne les comptent
  pas comme des plats finis à la maison. Tant que le retour n'est pas indiqué, le séjour ne peut pas
  être supprimé.
- **Participants** : 40 au plus par séjour.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_13_100000_create_stays.php` | Tables du lot |
| `app/Models/Stay.php`, `StayParticipant.php`, `StayMeal.php`, `StayPayment.php`, `StayPackedItem.php` | Modèles |
| `app/Services/Stays/StayService.php` | Séjour, participants, portions, contraintes, planning |
| `app/Services/Stays/StayShopping.php` | Liste de courses du séjour |
| `app/Services/Stays/StayPacking.php` | À emporter : départ et retour |
| `app/Services/Stays/StayCosts.php` | Frais partagés et remboursements (R37) |
| `app/Livewire/Stays/Index.php`, `Show.php`, `Concerns/*` + vues `views/livewire/stays/` | Écrans |
| `tests/Feature/Stays/StaysTest.php` | 12 tests |

Fichiers modifiés :

- `ShoppingList` (colonne `stay_id`, les listes en cours excluent celles des séjours),
  `SyncsWithPlanning` (une liste de séjour se recalcule sur ses repas), liste de courses (retour au
  séjour, pas de rangement dans le stock) ;
- `LinkedEaters` et `LinkedEater` (membres d'un foyer relié hors réception) ;
- planning (bandeau du séjour), `Navigation` (Séjours dans le menu Plus), `routes/web.php` ;
- `PlanningStats` (mouvements « séjour » exclus), `HouseholdData` (suppression d'un foyer) ;
- `BrowserDemoSeeder` (un séjour inventé), `tests/Browser/pages.spec.js` (six pages de plus) ;
- un test existant ajusté : le classement des modèles propres à un foyer (35).

## À vérifier sur ton poste

- **Plus › Séjours › Nouveau séjour**, puis ajouter un invité du carnet et une autre personne dans un
  autre groupe ; mettre une date d'arrivée à quelqu'un.
- **Repas** : prévoir deux dîners, regarder les portions changer avec les présents.
- **À emporter** : choisir un article du stock, puis **Courses › Préparer la liste** : la quantité à
  acheter en tient compte.
- **Frais** : noter deux dépenses de groupes différents, passer de « par appétit » à « par personne »,
  cocher un remboursement.
- Le **planning** de la semaine du séjour affiche le bandeau, sans les repas du séjour.

Les captures du lot ont été faites avec un séjour **inventé** (participants, repas, dépenses).
