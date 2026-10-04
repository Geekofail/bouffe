# Lot 42 — Ensemble

**Objectif** : organiser à plusieurs foyers. Un séjour se prépare à deux foyers, chacun dit ce qu'il
apporte à une réception ou à un séjour, et deux personnes se partagent les rayons en magasin.

**Statut** : ✅ développé et vérifié en local. ⏳ À valider sur ton poste.

- **1 066 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+13). La migration a aussi été
  appliquée, annulée puis réappliquée sur la base de développement MariaDB, qui contient des
  données des lots précédents.
- **110 vérifications dans le navigateur** passent (+6) :
  - l'onglet « Qui apporte quoi » d'un séjour, avec son lien ;
  - la page du lien, ouverte sans compte, où l'on ajoute ce qu'on apporte ;
  - le mode magasin à deux : « On se partage ? », puis « Vos rayons » en premier.
- **Une migration**, qui **ajoute** des tables et des colonnes :
  - nouvelles tables : `stay_households`, `contributions`, `contribution_links` et
    `shopping_list_aisle_owners` ;
  - nouvelles colonnes `household_id` sur `stay_meals`, `stay_payments` et `stay_packed_items`.
    Elles notent le foyer qui a prévu le plat, noté la dépense ou emporté l'article ; vides pour
    tout ce qui existe déjà, c'est-à-dire le foyer qui organise.

  `bouffe:deploy` fait une sauvegarde juste avant.

Ordre prévu (Q52) : 37 ✅ → 41 ✅ → 38 ✅ → 39 ✅ → 40 ✅ → **42**. C'est le dernier lot de la V5.

Spécification : [12-version-5.md](../12-version-5.md), module 42 et règle R45.

## Mise à jour depuis le lot 40

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-42-ensemble
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande sauvegarde la base et applique la migration. Les fichiers construits de l'interface
(`src/public/build`) sont livrés avec le lot : le mode magasin et quelques styles ont changé. Quand
le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 42 — ensemble"
git push -u origin lot-42-ensemble
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 42.1 | **Séjour co-organisé** | Dans un séjour, onglet **Participants**, section « **Organiser à plusieurs** » : le foyer qui organise invite un foyer **relié**. L'invitation apparaît dans **Séjours** et dans le fil des proches de l'accueil : « Accepter » ou « Non merci ». Le foyer qui accepte voit la fiche : dates, lieu et notes (adresse, boîte à clés). Il y fait quatre choses. Il ajoute **ses** participants : ses personnes, ses invités, d'autres personnes. Il prévoit des repas avec **ses** recettes, dans les créneaux du séjour. Il note **ses** dépenses. Il choisit ce qu'il emporte de **son** stock, avec son propre « C'est parti » et son propre retour. Il prépare et met à jour la liste de courses du séjour, l'ouvre comme liste groupée et y coche en **mode magasin**. Chacun ne change que ce qu'il a ajouté ; l'organisateur peut tout changer, et lui seul modifie les dates, le lieu, le partage des frais ou supprime le séjour. Il peut aussi **retirer** un foyer : ses dépenses restent dans les comptes, marquées « qui ne fait plus partie du séjour » (R45). Le foyer qui co-organise peut aussi **quitter** le séjour. | ✅ |
| 42.2 | **Qui apporte quoi** | Une liste « à apporter » pour une **réception** (sur sa page) ou un **séjour** (onglet « Qui apporte quoi »). Le foyer qui reçoit écrit ce qu'il faut (« Vin rouge »), avec ou sans nom (« Pain — Julie »), ou choisit un **plat déjà prévu** (« Tarte tatin »). Chacun s'inscrit, de trois façons. Un foyer relié invité à la réception le fait depuis la page du repas commun : « Nous l'apportons ». Un foyer qui co-organise le séjour le fait depuis l'onglet. Et ceux qui n'ont pas Bouffe utilisent un **lien** : ils s'inscrivent avec leur prénom sur une page publique (`/apporter/{jeton}`). Les **doublons** sont signalés « en double ? » (même ingrédient, ou même nom). Ce qui est apporté **sort de la liste de courses** : un plat prévu n'y compte plus ; dans un séjour, un ingrédient apporté (« Crème liquide — Clara ») est marqué « Apporté par Clara ». | ✅ |
| 42.3 | **Courses à deux en magasin** | En mode magasin, le bouton **« On se partage ? »** ouvre la liste des rayons, avec un bouton par personne (« Moi », « Monique »…). Chacun prend des rayons. L'écran montre alors « **Vos rayons** » d'abord, puis les rayons libres, puis « **Rayons de Monique** », repliés et marqués de son prénom. Pendant le partage, la liste est relue toutes les 6 secondes : ce que l'autre coche apparaît, avec « pris par Monique ». « Arrêter le partage » remet tous les rayons en commun. Pour la liste d'un séjour co-organisé, les comptes des deux foyers peuvent se partager les rayons. | ✅ |
| — | Tests | +13 tests Pest ; +6 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Où vit le séjour.** Il reste rangé chez le foyer qui organise, avec ses créneaux, ses portions
  (les appétits de ses réglages), son équipement « comme à la maison » et sa liste de courses. Le
  foyer qui co-organise y accède par l'adresse du séjour.
- **Ce que chacun voit de l'autre (R45, R29)** :
  - les **titres** des plats de l'autre foyer, mais pas la recette elle-même : elle ne s'ouvre et ne
    se garde hors ligne (lot 41) que chez son foyer ;
  - pour les participants de l'autre foyer : les goûts de ceux qui les partagent avec les foyers
    reliés (comptes qui l'ont accepté, enfants dont un adulte l'a coché). Jamais la fiche d'un
    invité de l'autre foyer : « Contraintes non partagées ».
- **Alertes d'un plat.** Chaque foyer vérifie le plat pour **ses** convives, avec tout ce qu'il sait
  d'eux. L'autre foyer voit l'alerte sans le nom : « Contient : crème liquide — allergie de
  quelqu'un de « Les Martin » ». On sait qu'il y a un problème et avec quel ingrédient, pas qui.
  - Les régimes (végétarien…) sont des catégories propres à chaque foyer : ils sont comparés par
    leur nom.
  - Les alertes « produit du stock » (lot 40) ne concernent que le foyer qui a le produit.
- **Participants.** Un foyer qui co-organise gère les siens, y compris ceux que l'organisateur
  avait ajoutés pour lui (comptes d'un foyer relié, lot 34). S'il est retiré ou s'il quitte le
  séjour, ses participants, ses plats et ses dépenses restent, et l'organisateur les gère.
- **Courses.** Le foyer qui co-organise ouvre la liste comme une **liste groupée** (lot 26). Il la
  coche en mode magasin ; la page détaillée de la liste reste réservée à l'organisateur.
- **Qui apporte quoi.**
  - **Le lien** :
    - un seul lien par évènement, recopiable : le jeton est gardé chiffré ;
    - « Retirer le lien » le coupe, et un nouveau lien remplace l'ancien ;
    - il expire 3 jours après l'évènement ; l'évènement passé, la liste ne change plus ;
    - la page montre le nom et la date de l'évènement et la liste avec les prénoms, jamais le menu,
      les allergies, les participants, l'adresse ou le nom du foyer ;
    - elle n'est pas indexée par les moteurs de recherche.
  - **Se raviser par le lien** : une clé gardée dans le navigateur permet de retirer ce qu'on a
    pris, et seulement cela. Une ligne ajoutée par le lien disparaît ; une ligne demandée par
    l'hôte redevient libre.
  - **Réception** : un plat apporté sort de la liste de la maison à sa **prochaine mise à jour**
    (bouton « Mettre à jour »). Un ingrédient apporté n'y change rien, car la liste de la maison
    sert aussi le reste de la semaine. Dans un **séjour**, la liste suit tout de suite.
  - Les plats qu'un foyer relié apporte depuis **son** planning (repas commun, lot 26) restent
    affichés à part (« Apporté par les proches »).
  - 60 lignes au plus par évènement.
- **Courses à deux.**
  - Le partage des rayons demande du réseau. Cocher, lui, marche toujours sans réseau (R20).
  - « En direct » veut dire une relecture toutes les 6 secondes, pas un serveur de messages : le
    mutualisé OVH n'en a pas (§7.4).
- **Écarts avec le §5** :
  - `contributions` et `contribution_links` désignent la réception ou le séjour par deux colonnes
    reliées (`meal_occasion_id`, `stay_id`) plutôt que `subject_type`/`subject_id` : leur
    suppression suit celle de l'évènement.
  - `contributions.token_hash` est la clé du navigateur qui s'est inscrit par le lien ; le jeton du
    lien est dans `contribution_links`.
  - Colonnes ajoutées : `stay_households.departed_at` et `returned_at` (« à emporter » de chaque
    foyer), et `household_id` sur `stay_meals`, `stay_payments` et `stay_packed_items`.
- **Q59** est appliquée avec sa proposition par défaut.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_11_17_100000_create_lot42_together.php` | Tables et colonnes du lot |
| `app/Models/StayHousehold.php`, `Contribution.php`, `ContributionLink.php`, `ShoppingListAisleOwner.php` | Modèles |
| `app/Services/Stays/StayCoorganizers.php` | Inviter, accepter, retirer, quitter ; qui gère quoi |
| `app/Services/Together/Contributions.php` | Qui apporte quoi, le lien, les effets sur les courses |
| `app/Services/Shopping/AisleSplit.php` | Courses à deux : qui prend quel rayon |
| `app/Livewire/Contributions/Board.php` + vue | La liste « à apporter » (réception, séjour, repas commun) |
| `app/Http/Controllers/BringController.php`, `resources/views/share/bring.blade.php` | La page du lien, sans compte |
| `resources/views/livewire/stays/show/apporter.blade.php` | Onglet « Qui apporte quoi » d'un séjour |
| `tests/Feature/Together/TogetherTest.php` | 13 tests |
| `tests/Browser/ensemble.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- **Séjours** :
  - `StayService` : participants et alertes de chaque foyer, créneaux de l'organisateur ;
  - `StayCosts`, `StayPacking` (à emporter par foyer), `StayShopping` (plats et ingrédients
    apportés) ;
  - pages `Stays/Index` et `Stays/Show`, leurs quatre concerns et leurs vues.
- **Modèles** :
  - `Stay` : adresse ouverte au foyer qui co-organise, liste et foyers invités ;
  - `StayMeal` : recette et créneau lus sans le filtre du foyer ;
  - `StayPayment` et `StayPackedItem`.
- **Courses** :
  - `ShoppingListGenerator` : un plat apporté n'est plus acheté ;
  - `OfflineSync` : rayons et « pris par » dans la photo de la liste ;
  - `StoreModeController` et la page du mode magasin ;
  - `resources/js/app.js`.
- **Réceptions et proches** :
  - page d'une réception et page du repas commun, qui affichent « Qui apporte quoi » ;
  - `LinkedFeed` : l'invitation à co-organiser ;
  - `RecipeRemoval` : un plat de séjour garde son nom quand sa recette disparaît.
- **Divers** :
  - `ProductAllergens` : alertes « stock » marquées ;
  - `OfflineRecipesController` ;
  - `HouseholdData` (suppression d'un foyer) ;
  - `AppServiceProvider`, `routes/web.php`.
- **Tests existants ajustés** :
  - `HouseholdsTest` : 45 modèles propres à un foyer ;
  - `pages.spec.js` : l'onglet « Qui apporte quoi ».

## À vérifier sur ton poste

- **Séjour à deux foyers** : dans un séjour, onglet Participants › « Organiser à plusieurs ».
  Invite un foyer relié, puis connecte-toi avec un compte de ce foyer : Séjours › Accepter. Ajoute
  ses personnes et un repas de son carnet, note une dépense. Reviens à l'organisateur : le repas
  porte « prévu par … » et la dépense « noté par … ».
- **Qui apporte quoi** : sur une réception à venir, écris « Dessert » et « Vin », puis « Créer le
  lien ». Ouvre le lien dans une fenêtre privée et inscris-toi avec un prénom.
- **Plat apporté** : choisis « Ou un plat déjà prévu », fais-le prendre par quelqu'un, puis mets à
  jour la liste de courses de la semaine. Ses ingrédients n'y sont plus.
- **Courses à deux** : en mode magasin, sur deux téléphones, « On se partage ? ». Coche sur l'un et
  regarde l'autre.

Les captures du lot ont été faites avec les données d'essai de la base de développement. Ces
éléments sont **inventés** :

- le foyer « Léo et Clara » qui co-organise le chalet, et sa dépense ;
- Julie, Tante Lucie, Sam et Marc ;
- le « Dîner des voisins » ;
- le partage des rayons.
