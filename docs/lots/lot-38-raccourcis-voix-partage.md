# Lot 38 — Raccourcis, voix et partage

**Objectif** : le chemin le plus court entre une idée et Bouffe. « Dis Siri, ajouter aux
courses », une recette envoyée depuis Safari, les étapes lues à voix haute pendant qu'on a les
mains dans la farine.

**Statut** : ✅ développé et vérifié en local. ⏳ À valider sur ton poste et sur l'iPhone.

- **1 025 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+11).
- **90 vérifications dans le navigateur** passent (+6) :
  - la page Raccourcis : créer le jeton, le voir une fois, le révoquer ;
  - « À trier » : un texte et une photo gardés, puis jetés ;
  - le mode cuisine mains libres : un toucher passe à l'étape suivante.
- **Une migration** : deux tables, `api_tokens` et `inbox_items`. Les données existantes ne
  changent pas.

Ordre prévu (Q52) : 37 ✅ → 41 ✅ → **38** → 39 → 40 → 42.

Spécification : [12-version-5.md](../12-version-5.md), module 38 et R39.

## Mise à jour depuis le lot 41

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-38-raccourcis-voix-partage
```

Ensuite, une fois les fichiers déposés, ferme ton éditeur et lance :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. Le fichier `public/manifest.webmanifest` change (cible de
partage Android). Un téléphone Android doit **réinstaller** Bouffe sur l'écran d'accueil pour que
Bouffe apparaisse dans son menu Partager. Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 38 — raccourcis, voix et partage"
git push -u origin lot-38-raccourcis-voix-partage
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 38.1 | **« Dis Siri, ajouter aux courses »** | Page **Mon compte › Raccourcis et Siri**. On y crée un **jeton personnel**, montré **une seule fois** ; il se renouvelle ou se révoque. La page donne le mode d'emploi pas à pas de trois raccourcis à créer dans l'application Raccourcis. **Ajouter aux courses** : on dicte « deux baguettes et du beurre », ce qui fait deux articles ; Siri répond « Ajouté aux courses : 2 baguettes et beurre. ». **On mange quoi** : Siri lit le menu du jour (à partir de 15 h, seulement celui du soir) puis celui du lendemain, restes compris. **Ajouter au stock** (comptes complets). Les nombres dits en toutes lettres sont compris, ainsi que « une douzaine » ou « un litre de ». Le jeton ne permet que ces gestes : 60 demandes par heure au plus, chaque usage noté au journal du foyer (« … par un raccourci ») | ✅ |
| 38.2 | **Envoyer une recette à Bouffe** | Sur iPhone, un quatrième raccourci, **Envoyer à Bouffe**, s'affiche dans le menu **Partager** de Safari, Instagram, Messages ou Photos. Il accepte une adresse, un texte ou une photo. Sur Android, Bouffe installé sur l'écran d'accueil apparaît directement dans le menu Partager (cible de partage du manifeste). Une adresse est **lue tout de suite** : titre et recette sont gardés. Une même adresse déjà en attente n'est pas reprise | ✅ |
| 38.3 | **« À trier »** | **Recettes › À trier** (bouton et menu « Plus », avec le nombre en attente) reçoit les adresses lues ou en échec, les textes et les **photos de pages de livre**. Ces photos sont lues par le service des tickets (lot 23) au passage de la tâche planifiée, trois à la fois. On peut aussi y coller une adresse ou un texte, ou prendre une photo. Chaque élément propose : **Relire et garder**, qui ouvre la relecture d'import avec l'ébauche, la recette entrant dans le carnet avec l'étiquette « à tester » ; **Réessayer** ou **Lire maintenant** ; **Coller le texte** si le site refuse ; **Jeter**. Au-delà de **10** éléments, un rappel discret apparaît sur la liste des recettes. Les éléments triés sont oubliés au bout de 30 jours | ✅ |
| 38.4 | **Mode cuisine mains libres** | En bas du mode cuisine, le bouton **« Lire à voix haute »** (puis « Mains libres · j'écoute ») active la lecture. La mise en place et chaque étape sont lues par le téléphone, avec la voix française du navigateur et sans service externe. **Un toucher n'importe où** passe à l'étape suivante. Un minuteur qui sonne est annoncé (« Cuisson des pâtes : terminé. »). Là où le navigateur reconnaît la parole, dire « suivant », « précédent », « répète » ou « minuteur » suffit. Le choix est gardé le temps de la session | ✅ |
| — | Tests | +11 tests Pest ; +6 vérifications dans le navigateur (3 scénarios, iPhone et ordinateur) | ✅ |

## Choix et limites

- **Pas de fichiers `.shortcut` à télécharger.** Apple n'accepte que des raccourcis signés, partagés
  par lien iCloud. La page Raccourcis donne donc, pour chacun, le nom à lui donner (c'est ce qu'on
  dit à Siri) et les actions dans l'ordre. Comptez deux minutes par raccourci. Le nom des actions
  peut légèrement varier selon la version d'iOS.
- **Https obligatoire hors de la maison.** Siri appelle Bouffe depuis l'iPhone, où qu'il soit :
  sans l'adresse en https (document 09, accès depuis l'extérieur), les raccourcis ne marchent que
  sur le Wi-Fi de la maison. La page le rappelle tant que Bouffe est ouvert en `http://`.
- **Le jeton** est gardé sous forme d'empreinte (SHA-256) : même la base ne permet pas de le
  retrouver. Il remplace le mot de passe dans l'en-tête `Authorization` du raccourci. Un nouveau
  jeton désactive l'ancien. Un compte en lecture seule n'a ni « Ajouter au stock » ni « À trier ».
- **Dictée** : Bouffe découpe la phrase sur « et », les virgules et « puis ». Il reconnaît les
  nombres courants, « une douzaine » et les unités courantes, et rattache chaque article au
  bon ingrédient comme une saisie normale. Un article non reconnu est ajouté tel quel.
- **« Garder » et « À tester » ne font qu'un** : une recette importée entre déjà dans le carnet
  avec l'étiquette « à tester » (lot 33) jusqu'à ce qu'elle soit cuisinée. Les recettes d'un
  proche (foyers liés, lot 34) ne passent pas par « À trier » : elles restent où elles étaient.
- **Photos** : elles ne sont lues que si le service de lecture des tickets (lot 23) est configuré.
  Sinon elles restent « en attente » ; « Lire maintenant » donne l'erreur, et « Jeter » les
  efface. La photo est effacée du serveur dès qu'elle est lue, gardée ou jetée.
- **Images des sites** : la vignette d'une adresse reçue n'est pas affichée, car la politique de
  contenu bloque les images d'autres sites. Elle sert à l'import.
- **Reconnaissance de la parole** : Safari et Chrome la proposent, Firefox non (le toucher reste).
  Sur iPhone, la première fois, Safari demande l'accès au micro. L'écoute s'arrête quand on quitte
  le mode cuisine ou qu'on désactive le bouton. Rien n'est envoyé à Bouffe ni à l'assistant
  (R34) : c'est le téléphone qui reconnaît.
- **Réglages** : tout est sur la page Mon compte › Raccourcis et Siri (pas de nouveau réglage dans
  Paramètres).
- **Q54** appliquée : les raccourcis iPhone d'abord, plus la cible de partage Android.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_27_100000_create_api_tokens_and_inbox_items.php` | Tables du lot |
| `app/Models/ApiToken.php`, `InboxItem.php` | Modèles |
| `app/Services/Shortcuts/ShortcutTokens.php` | Jeton : créer, révoquer, retrouver |
| `app/Services/Shortcuts/DictationParser.php` | « deux baguettes et du beurre » → articles |
| `app/Services/Shortcuts/ShortcutActions.php` | Courses, stock, menu, « À trier » : les phrases que Siri lit |
| `app/Http/Middleware/AuthenticateShortcutToken.php` | Jeton, limite de 60 par heure, foyer |
| `app/Http/Controllers/ShortcutController.php` | `/api/raccourcis/courses`, `/stock`, `/menu`, `/a-trier` (réponses en texte) |
| `app/Http/Controllers/ShareTargetController.php` | `/partager` (cible de partage Android) |
| `app/Services/Recipes/RecipeInbox.php` | « À trier » : recevoir, lire, ébauche, garder, jeter, oublier |
| `app/Livewire/Recipes/Inbox.php` + vue | Page « À trier » |
| `app/Livewire/Account/Shortcuts.php` + vue | Page Raccourcis et Siri |
| `tests/Feature/Shortcuts/ShortcutsTest.php` | 11 tests |
| `tests/Browser/shortcuts.spec.js` | Vérifications dans le navigateur |

Fichiers modifiés :

- `resources/js/app.js` (mode mains libres ; le minuteur qui sonne prévient la page) ;
- mode cuisine (`recipes/cook.blade.php` : textes à lire, bouton) ;
- import de recette (`Import`, `RecipeImporter::fetchRecipeData` : l'ébauche d'« À trier ») ;
- liste des recettes (bouton « À trier », rappel), Mon compte (carte « Raccourcis et Siri ») ;
- `NotificationDispatcher` (lecture des photos en attente, oubli à 30 jours) ;
- `HouseholdData` (suppression d'un foyer, photos) ;
- `public/manifest.webmanifest`, `routes/web.php` ;
- test du classement des modèles propres à un foyer (39).

## À vérifier sur ton poste

- **Jeton** : Mon compte › Raccourcis et Siri › Créer mon jeton. Recopie la ligne « Bearer … »
  (bouton Copier).
- **Ajouter aux courses** : crée le raccourci en suivant la page, puis « Dis Siri, ajouter aux
  courses », « deux baguettes et du beurre ». Les deux articles doivent être dans la liste, et la
  ligne correspondante dans le journal du foyer.
- **On mange quoi** : « Dis Siri, on mange quoi ».
- **Envoyer à Bouffe** : dans Safari, sur une page de recette, Partager › Envoyer à Bouffe. Elle
  apparaît dans Recettes › À trier ; « Relire et garder » ouvre la relecture.
- **Photo** : À trier › Photo d'une page, puis `php artisan bouffe:reminders` (ou la tâche
  planifiée), si le service des tickets est configuré.
- **Mains libres** : sur l'iPhone, mode cuisine › Lire à voix haute. Touche l'écran pour avancer,
  dis « suivant ».

Les captures du lot ont été faites avec les données d'essai de la base de développement (recettes
et éléments « À trier » **inventés**).
