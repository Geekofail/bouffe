# Lot 24 — Plusieurs foyers : fondations

**Objectif** : l'installation accueille d'autres foyers, chacun chez soi.

**Statut** : ✅ développé et vérifié en local (791 tests passés sous SQLite **et** MariaDB 10.11 ; migration jouée, annulée puis rejouée sur une copie de la base de développement ; parcours navigateur 1280 px en mode clair et iPhone 390 px en mode sombre, avec un second foyer créé, une invitation acceptée et aucune donnée visible d'un foyer à l'autre) — ⏳ à valider sur ton poste.

Spécification : [07-version-3.md](../07-version-3.md), module 25, règles **R29** et **R30**.

## Mise à jour depuis le lot 23

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

`bouffe:deploy` fait une sauvegarde avant de toucher à la base : c'est important ici, cette
migration touche presque toutes les tables.

**Ce que fait la migration** :

- toutes les données actuelles deviennent le foyer n° 1, nommé d'après les deux premiers comptes
  (« Pierre et Monique »), sans perte ;
- les comptes « complets » deviennent **responsables du foyer** ; les comptes « consultation et
  courses » gardent ce rôle ;
- le premier compte (Pierre) devient **administrateur de l'installation**.

Tant qu'il n'y a qu'un foyer, rien ne change au quotidien.

**Réglages par défaut appliqués** (questions sans réponse) :

- Q35 : catalogue d'ingrédients commun ; réglages de stock et de prix propres à chaque foyer.
- Q37 : moins de 10 foyers ; un même compte peut appartenir à plusieurs foyers.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 25.1 | **Foyers** | Recettes, planning, stock, listes, magasins, budget, tickets, invités et réglages appartiennent à un foyer. Un nouveau foyer reçoit les créneaux, emplacements, catégories et postes de budget de départ | ✅ |
| 25.2 | **Invitation** | Lien à usage unique, valable 7 jours, éventuellement réservé à une adresse. La personne crée son compte, ou rejoint avec son compte existant. Seul le condensé du lien est gardé | ✅ |
| 25.3 | **Rôles par foyer** | **Responsable** (tout, plus inviter, retirer, exporter, supprimer), **Complet**, **Consultation et courses**. Un foyer garde toujours au moins un responsable | ✅ |
| 25.4 | **Plusieurs foyers** | Sélecteur dans la barre du haut (icône « personnes ») et dans « Plus » sur téléphone | ✅ |
| 25.5 | **Isolation stricte** | Écrans, adresses, recherche, export, tâche planifiée : seul le foyer actif. Un test vérifie que chaque modèle est classé | ✅ |
| 25.6 | **Catalogue commun** | Ingrédients, unités, rayons, saisons, nutrition communs. Mode de stock, conservation, emplacement, rayon, produit de base, stock minimum et prix de référence **par foyer** | ✅ |
| 25.7 | **Administration** | Pour Pierre : foyers, membres, responsables, recettes, place des fichiers, dernier passage ; créer un foyer et son lien de responsable ; désactiver ; sans accès au contenu | ✅ |
| 25.8 | **Données du foyer** | Export (JSON + photos du foyer seulement), quitter un foyer, retirer un membre, suppression après 30 jours (annulable) | ✅ |
| — | Tests | +20 tests — 791 au total | ✅ |

## Où trouver quoi

- **Paramètres → Foyer** :
  - nom du foyer et nombre de personnes à table (désormais par foyer) ;
  - membres et rôles ;
  - « Inviter quelqu'un » ;
  - « Créer un compte » ;
  - export ;
  - « Supprimer le foyer ».
- **Administration** : menu du foyer en haut à droite, ou « Plus » sur téléphone. Réservé à Pierre.
- **Invitation** : le lien s'affiche une seule fois. Copie-le ou envoie-le avec le bouton « E-mail ».
  Bouffe n'envoie pas l'e-mail lui-même.

## R29 — isolation

- Chaque table d'un foyer porte `household_id`. Une portée globale filtre toute lecture sur le foyer
  actif et remplit la colonne à la création.
- Les rares requêtes écrites à la main (budget, compatibilité des invités, réactions) ont été
  filtrées elles aussi.
- Une adresse d'un autre foyer (`/recettes/12`) répond « page introuvable ».
- La tâche planifiée travaille foyer par foyer : rappels, clôture des repas, consommations,
  dépenses récurrentes, tickets et notifications.
- **Contraintes alimentaires** : notées par foyer. Celles de Pierre chez lui ne se voient pas s'il
  rejoint aussi le foyer de Léo. Le partage consenti arrive au lot 26.
- **Réservé à l'administrateur**, car cela concerne toute l'installation :
  - les sauvegardes ;
  - le diagnostic ;
  - le service de lecture des tickets (clé, plafond, conservation). Le plafond de lectures compte
    pour toute l'installation.

## R30 — catalogue commun, réglages par foyer

- Quand un foyer modifie un réglage d'ingrédient (mode de stock, prix…), il obtient sa propre copie
  de ces réglages. Le catalogue et les autres foyers ne changent pas.
- Tant qu'un foyer n'a rien modifié, il voit les valeurs actuelles, qui servent de valeurs par
  défaut. Les emplacements sont traduits : « Réfrigérateur » chez Pierre → « Réfrigérateur » chez Léo.
- Dès qu'il y a **plusieurs foyers**, le nom, l'unité, la saison, la nutrition, la fusion et la
  suppression d'un ingrédient ne se changent plus que par l'administrateur. De même pour les unités
  et les rayons.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_06_100000_create_lot24_households.php` | Foyers, membres, invitations, `household_id` partout, réglages par foyer |
| `app/Models/Household.php`, `HouseholdMember.php`, `Invitation.php`, `HouseholdIngredientSetting.php` | Modèles |
| `app/Models/Concerns/BelongsToHousehold.php`, `app/Models/Scopes/HouseholdScope.php` | Portée globale R29 |
| `app/Support/CurrentHousehold.php`, `HouseholdRule.php`, `Concerns/GuardsCatalog.php` | Foyer actif, unicité par foyer, catalogue protégé |
| `app/Services/Households/HouseholdManager.php`, `HouseholdData.php` | Foyers, membres, invitations, suppression |
| `app/Http/Middleware/EnsureHousehold.php`, `EnsureAdmin.php`, `EnsureHouseholdOwner.php` | Foyer actif, administration, responsables |
| `app/Http/Controllers/HouseholdSwitchController.php` | Changer de foyer |
| `app/Livewire/Households/AcceptInvitation.php`, `app/Livewire/Admin/Households.php` + vues | Invitation, administration |
| `resources/views/households/none.blade.php` | Compte sans foyer |
| `tests/Feature/Households/HouseholdsTest.php` | 20 tests |

Fichiers modifiés :

- 28 modèles (portée de foyer) et l'ingrédient (réglages par foyer) ;
- réglages (par foyer), compte (rôles par foyer) ;
- Paramètres → Foyer (refait) ;
- barre du haut (sélecteur), export, tâche planifiée ;
- validations d'unicité, pages du catalogue ;
- graines, fabrique de test, commande `bouffe:user` (options `--foyer` et `--admin`).

## À vérifier sur ton poste

- `bouffe:deploy`, puis l'accueil : tout doit être comme avant.
- Paramètres → Foyer : Monique et toi en « Responsable du foyer ».
- Administration : crée un foyer d'essai. Ouvre son lien dans une fenêtre de navigation privée et
  crée un compte : ce foyer doit être vide (aucune de tes recettes, aucun stock).
- Supprime ensuite ce foyer d'essai : Paramètres → Foyer → « Supprimer le foyer ». Il disparaîtra
  définitivement 30 jours plus tard ; tu peux aussi le désactiver tout de suite depuis
  l'administration.
