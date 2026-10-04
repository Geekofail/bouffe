# Lot 26 — Entre foyers

**Objectif** : recettes, planning et repas en commun entre proches.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **838 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0.
- Migration jouée, annulée puis rejouée sur la base de développement.
- Parcours complet dans le navigateur, entre « Pierre et Monique » et « Léo et Clara » :
  - relier les deux foyers ;
  - copier une recette ;
  - écrire dans le planning de l'autre ;
  - répondre à une invitation et apporter un plat ;
  - réserver un surplus ;
  - imprimer un carnet familial.
- Vérifié à 1280 px en mode clair et sur iPhone 390 px en mode sombre : aucune erreur de politique
  de contenu, aucun débordement.

Spécification : [07-version-3.md](../07-version-3.md), module 26, règle **R31**, idée **C4**.

## Mise à jour depuis le lot 25

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Rien ne change tant que tu ne relies pas ton foyer à un autre :

- les recettes restent **privées** (Q36) ;
- le planning reste fermé ;
- tes contraintes alimentaires ne sont pas partagées.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| — | **Foyers reliés** | Page **Proches** (menu du foyer en haut à droite, « Plus » sur téléphone). Un responsable crée un lien à usage unique, valable 7 jours. Un responsable de l'autre foyer l'ouvre et accepte. Chacun règle ce qu'il ouvre à l'autre. Le lien se défait à tout moment. | ✅ |
| 26.1 | **Recettes partagées** | Réglage « Visible par » dans la recette : **privée** (par défaut), **foyers reliés**, **toute l'installation**. Ou « tout notre carnet » pour un foyer relié. Onglet **Recettes des proches** dans Recettes, avec le foyer d'origine et un filtre par foyer. | ✅ |
| 26.2 | **Planifier ou copier** | Une recette d'un proche se planifie telle quelle : elle reste chez lui, mais ses ingrédients vont dans notre liste. Elle se cuisine et s'imprime aussi. **Copier dans notre carnet** en fait une copie modifiable (R31). | ✅ |
| 26.3 | **Avis entre foyers** | Nos étoiles et commentaires sur une recette d'un proche. Son foyer les voit dans le bloc **Avis des proches** et dans le fil. | ✅ |
| 26.4 | **Planning partagé** | Fermé, **lecture** ou **lecture et écriture**, réglé par chaque foyer. En écriture : ajouter une recette de *leur* carnet ou un texte libre (« Ajouté par Pierre »), et retirer un repas. | ✅ |
| 26.5 | **Repas commun** | Une réception invite un foyer relié. Il répond (vient ou non, combien de personnes, un mot) et choisit ce qu'il apporte. Ses plats vont dans **son** planning, **sa** liste de courses et **ses** rappels. Chez celui qui reçoit : « Foyers invités », « Apporté par les proches », et les convives comptés. | ✅ |
| 26.6 | **Contraintes tenues par la personne** | Case dans **Mon compte** : « Partager mes contraintes avec les foyers reliés qui m'invitent ». Une fois cochée, les allergies et régimes notés dans son foyer déclenchent les alertes chez celui qui reçoit. Sans cette case : « contraintes non partagées ». | ✅ |
| 26.7 | **Surplus à donner** | Annonce (depuis un article du stock ou non), date limite, réservation en un geste. À la remise, l'article est retiré du stock, consommé « Donné à… », sans compter comme gaspillage. | ✅ |
| 26.8 | **Liste de courses groupée** | Une liste « ouverte aux proches » (menu ⋯ de la liste). Ils y ajoutent leurs articles. Celui qui fait les courses saisit le prix payé ; chacun voit ce qu'il doit rembourser. Ces articles ne vont ni dans notre stock ni dans nos dépenses. | ✅ |
| 26.9 | **Carnet familial** | Choix des recettes (les nôtres et celles des proches), titre, puis impression : couverture, sommaire, une recette par page, photo, auteur. « Enregistrer en PDF » depuis le navigateur. | ✅ |
| 26.10 | **Fil des proches** | Bloc « Chez les proches » sur l'accueil, calculé sur 14 jours : recette partagée, avis reçu, surplus proposé, invitation à un repas. Masquable dans Paramètres → Affichage. | ✅ |
| C4 | **Agenda (ICS)** | **Mon compte → Agenda du téléphone** : adresse d'abonnement personnelle (iPhone, Android, Outlook). Un événement par repas, de 2 semaines en arrière à 2 mois devant. Le planning d'un foyer relié ouvert en lecture a aussi son adresse (« Dans mon agenda » sur son planning). | ✅ |
| — | Tests | +16 tests — 838 au total | ✅ |

## R31 — copie d'une recette partagée

- La copie reprend :
  - titre, description, portions, temps et difficulté ;
  - ingrédients et étapes ;
  - la **photo**, copiée comme fichier ;
  - les **sous-recettes**, copiées aussi, ou reprises si elles l'ont déjà été.
- Les catégories sont reprises seulement quand une catégorie du même nom existe chez nous.
- Si le titre existe déjà chez nous : « Quiche lorraine (Pierre et Monique) ».
- La copie garde son **origine** (foyer, recette, date) et une empreinte du contenu de l'original.
- Si l'original change, un bandeau « L'original a changé » propose **Voir les différences**. Pour
  chaque partie (titre et temps, ingrédients, étapes), les deux versions sont côte à côte. On reprend
  une partie avec **Reprendre l'original**, ou on garde tout avec **Garder notre version**. Rien
  n'est jamais remplacé sans clic.
- Un original supprimé ou refermé ne touche pas aux copies.

## Règles de sécurité (R29 étendue)

- Une recette d'un autre foyer s'ouvre par une adresse `…/recettes/proche-12`, et seulement dans ces
  cas :
  - elle est partagée avec nous ;
  - c'est une sous-recette d'une recette partagée ;
  - nous l'avons déjà planifiée.

  On ne peut ni la modifier, ni l'archiver, ni la supprimer, ni y ajouter de variante. Ses notes de
  cuisine restent chez son foyer.
- Écrire dans le planning d'un autre foyer demande que ce foyer l'ait ouvert **en écriture**.
- Seuls les responsables relient les foyers et règlent le partage. Seuls les comptes complets
  répondent aux invitations, apportent des plats et proposent des surplus.
- Une recette supprimée, ou un foyer supprimé après ses 30 jours :
  - les repas des autres qui l'avaient planifiée gardent son nom (« … (recette retirée par ses
    auteurs) ») ;
  - les copies restent.
- L'adresse de l'agenda contient un jeton personnel. Il est chiffré en base et se change dans
  **Mon compte**.

## Choix et limites

- **Aucune notification ni e-mail** pour une invitation, un surplus ou un avis. Ils apparaissent
  dans le fil de l'accueil, dans **Proches** et, pour les invitations, dans **Réceptions**. Des
  notifications pourront s'y ajouter si ça manque à l'usage.
- **Planning en écriture** : ajouter et retirer seulement. Déplacer un repas se fait dans son propre
  planning.
- **Liste groupée** : le montant à rembourser vient des prix saisis. Un article acheté sans prix est
  signalé (« prix à saisir »), jamais estimé.
- **Carnet familial** : c'est l'impression du navigateur qui fait le PDF. Bouffe ne fabrique pas de
  fichier PDF lui-même.
- **Invités et foyers** : les membres d'un foyer relié ne deviennent pas des « invités » de ton
  carnet. Ils restent rattachés à leur foyer.

## Où trouver quoi

- **Proches** : icône « personnes » en haut à droite → Proches. Sur téléphone : « Plus » →
  Proches. On y trouve :
  - les foyers reliés et le partage ;
  - les liens de rattachement ;
  - les repas en commun reçus ;
  - des onglets vers les recettes des proches, les surplus et le carnet familial.
- **Visible par** : dans le formulaire d'une recette, sous « Recette favorite / À tester ».
- **Inviter un foyer à une réception** : page de la réception, bloc « Foyers invités ».
- **Liste groupée** : menu ⋯ d'une liste de courses → « Ouvrir aux proches ».
- **Partager ses contraintes** et **agenda** : Mon compte.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_08_100000_create_lot26_between_households.php` | Liens, partages, visibilité et origine des recettes, avis par foyer, repas communs, consentement, agenda, surplus, liste groupée |
| `app/Models/HouseholdLink.php`, `HouseholdShare.php`, `MealOccasionHousehold.php`, `SurplusOffer.php` | Modèles |
| `app/Services/Linked/HouseholdLinks.php`, `SharedRecipes.php`, `RecipeCopier.php`, `RecipeRemoval.php` | Liens, visibilité, copie R31, suppression |
| `app/Services/Linked/SharedMeals.php`, `LinkedEaters.php`, `LinkedEater.php` | Repas commun, contraintes partagées |
| `app/Services/Linked/Surplus.php`, `GroupLists.php`, `FamilyBook.php`, `CalendarFeed.php`, `LinkedFeed.php` | Surplus, liste groupée, carnet, agenda, fil |
| `app/Livewire/Linked/*.php` + `resources/views/livewire/linked/*.blade.php` | Proches, accepter un lien, planning, repas commun, surplus, liste groupée, carnet |
| `app/Http/Controllers/CalendarController.php`, `resources/views/print/family-book.blade.php`, `components/linked-nav.blade.php` | Agenda ICS, impression du carnet, sous-navigation |
| `tests/Feature/Linked/LinkedTest.php` | 16 tests |

Fichiers modifiés :

- recettes : modèle, formulaire, liste, fiche, carte, impression ;
- modèles : repas planifié, sous-recette, note, compte, liste et article de courses ;
- réceptions (fiche et liste), liste de courses (fiche et article) ;
- mise en rayon, fusion d'articles, coût d'une liste ;
- convives et contraintes (`OccasionService`, `HouseholdService`) ;
- accueil, navigation, Mon compte, Vos données ;
- suppression d'un foyer ; routes.

## À vérifier sur ton poste

- Après `bouffe:deploy`, tout est comme avant.
- Crée un foyer d'essai depuis l'administration, puis un compte dedans (fenêtre privée).
- Depuis **Proches**, crée un lien et accepte-le avec le compte d'essai.
- Marque une recette « Foyers reliés », puis vérifie avec l'autre compte :
  - il la voit dans « Recettes des proches » ;
  - il peut la planifier et la copier ;
  - s'il modifie ta recette, le bandeau « L'original a changé » apparaît sur sa copie.
- Organise une réception et invite le foyer d'essai. Avec l'autre compte :
  - réponds « Nous venons » ;
  - apporte un plat ;
  - vérifie qu'il arrive dans sa liste de courses, et pas dans la tienne.
- Mon compte → Agenda du téléphone : abonne-toi depuis ton téléphone.
