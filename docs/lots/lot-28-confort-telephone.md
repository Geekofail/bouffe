# Lot 28 — Confort sur téléphone

**Objectif** : plus aucun écran pénible sur téléphone, et des écrans vérifiés automatiquement dans un
vrai navigateur.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste. Premier lot de la
version 4.

- **867 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+12).
- **35 vérifications dans le navigateur** passent (nouveau) :
  - 14 écrans et une fiche recette avec sa liste de courses, sur iPhone en mode sombre et sur
    ordinateur 1280 px en mode clair ;
  - 4 parcours propres au téléphone.
- Aucune migration.

Spécification : [08-version-4.md](../08-version-4.md), module 28.

## Mise à jour depuis le lot 27

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

C'est tout pour l'application. Les tests dans le navigateur sont **facultatifs** sur ton PC (voir plus
bas).

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 28.1 | **Défauts corrigés** | Voir le tableau suivant | ✅ |
| 28.2 | **Menu « Plus » du planning** | Sur téléphone et tablette : **Courses**, **Remplir** et **Plus**. **Plus** ouvre une feuille depuis le bas de l'écran, avec chaque action libellée et regroupée : « Cette semaine » (copier, imprimer, semaines types, cuisiner en avance), « Affichage » (mes repas en avant, vue liste, carnet d'invités), puis **Vider la semaine** à part, en rouge. Sur grand écran, toutes les actions restent visibles, avec leur libellé | ✅ |
| 28.3 | **Planning jour par jour** | Sur téléphone, un seul jour à la fois. Une bande des jours reste en haut en défilant (Lu 21, Ma 22… avec un point par repas) et se termine par **Tout** pour voir la semaine. Un **balayage** gauche / droite passe au jour voisin ; au-delà du dimanche, à la semaine suivante. Le jour choisi reste affiché après un ajout ou un déplacement. Aujourd'hui est montré à l'ouverture | ✅ |
| 28.4 | **Filtres repliés** | Recettes : sur téléphone, une recherche et un bouton **Filtres (n)** qui déplie temps, saison, prix, difficulté, tri et catégories ; le panneau reste ouvert tant qu'on choisit. Stock : **Filtres** ouvre une feuille, le filtre actif s'affiche en pastille qu'on retire d'un geste | ✅ |
| 28.5 | **Accueil apaisé** | « C'était mangé ? » : le nom du plat garde la place, date courte (« Sam. 26 sept. »), boutons ✓ et ✕ compacts (libellés à partir de 640 px). Les grands boutons rouges en série ont disparu | ✅ |
| 28.6 | **Cibles tactiles** | Nouveaux boutons, menus et bande des jours à 44 px de haut. Boutons « Convives » du planning et étiquettes de « Que cuisiner ? » agrandis. Tout le reste est vérifié automatiquement (règle WCAG 2.2 : 24 px, ou assez d'espace autour) | ✅ |
| 28.7 | **Tests dans le navigateur** | `npm run test:browser` (voir plus bas) | ✅ |
| — | Tests | +12 tests Pest (867) et 35 vérifications dans le navigateur | ✅ |

**Défauts corrigés (28.1)**

| # | Avant | Après |
|---|---|---|
| E1 | Planning : le bandeau « produits à consommer » s'affichait un mot par ligne | Le texte prend la largeur, les liens passent dessous. Même correction pour les propositions de restes et de portions |
| E2 | Planning : dix icônes sans texte | Menu **Plus** libellé (28.2) |
| E3 | Accueil : noms de plats coupés, date sur trois lignes | 28.5 |
| E4 | Recettes : deux écrans de filtres avant la première recette | Filtres repliés (28.4) |
| E5 | Carte de recette : « À tester » par-dessus « De saison » | Étiquettes rangées côte à côte dans un seul coin |
| E6 | Stock : boutons d'en-tête sans texte | Chaque bouton garde son libellé ; sur téléphone, Congélateur, Historique et Plat préparé passent dans **Plus** |
| — | Étiquettes coupées au milieu (« 24 / nov. ») | Une étiquette ne se coupe plus sur deux lignes |
| — | Libellés de boutons masqués sur téléphone, donc absents pour un lecteur d'écran (20 boutons) | Masqués à l'écran seulement, toujours lus par un lecteur d'écran |
| — | Barre de progression de la liste sans nom | « Articles cochés » |

## Tests dans le navigateur (28.7)

Ce que je faisais à la main en fin de lot devient un test permanent.

- **Écrans vérifiés** : accueil, recettes, planning, courses, stock, « Que cuisiner ? », budget,
  prix, réceptions, tickets, invités, paramètres, compte, proches, plus une fiche recette et une liste.
- **Pour chaque écran, sur iPhone (sombre) et sur ordinateur (clair)** :
  - la page s'ouvre ;
  - aucune erreur JavaScript ni refus de la politique de sécurité ;
  - aucun débordement horizontal ;
  - aucun défaut d'accessibilité « grave » ou « critique » (axe-core, WCAG 2 A et AA) ;
  - sur téléphone, des cibles tactiles suffisantes.
- **Parcours propres au téléphone** :
  - le planning montre un jour à la fois et le garde après une action ;
  - le balayage change de jour ;
  - les filtres des recettes se replient ;
  - les filtres du stock sont dans une feuille.
- **Données** : une base SQLite à part, `database/browser.sqlite`, recréée à chaque lancement avec des
  données d'essai inventées (compte « Camille », une semaine de repas, une liste, du stock, deux
  magasins). La commande `bouffe:browser-db` **refuse** de travailler sur une autre base, et s'arrête
  si la configuration est en cache : ta vraie base ne peut pas être effacée par erreur.

Pour les lancer sur ton PC (facultatif) :

```powershell
cd C:\wamp64\www\Bouffe\src
npm install
npx playwright install chromium
npm run test:browser
```

## Choix et limites

- **Playwright directement, pas l'extension navigateur de Pest.** Le document 08 prévoyait
  l'extension de Pest ; elle n'a pas pu être installée depuis mon environnement. Le résultat est le
  même, mais ces tests se lancent par `npm run test:browser` et non avec `php artisan test`. Ta suite
  habituelle ne change pas.
- **Safari n'est pas testé** : c'est Chrome qui imite un iPhone (taille, écran tactile, mode sombre).
  Le rendu de Safari reste à regarder sur ton téléphone.
- **Captures non comparées** : elles sont jointes au rapport de chaque test pour les regarder, mais
  pas comparées d'un lot à l'autre. Elles changent chaque jour avec la date (« Aujourd'hui »), la
  comparaison échouerait sans raison.
- **Contraste des couleurs** : non vérifié par ces tests pour l'instant. Il le sera au lot 29, avec la
  nouvelle palette.
- **Vue jour par jour** : seulement sous 768 px de large. Sur tablette et ordinateur, la semaine
  entière reste affichée.

## Fichiers

| Fichier | Rôle |
|---|---|
| `resources/views/components/action-sheet.blade.php` | Menu « Plus » : feuille sur téléphone, menu déroulant ailleurs |
| `app/Console/Commands/BrowserDbCommand.php` | `bouffe:browser-db` : base d'essai, protégée |
| `database/seeders/BrowserDemoSeeder.php` | Données d'essai inventées |
| `playwright.config.js`, `tests/Browser/*.js` | Tests dans le navigateur |
| `tests/Feature/Comfort/PhoneComfortTest.php` | 12 tests |

Fichiers modifiés :

- planning : composant et vue (jour montré, balayage, menu, bandeaux) ;
- accueil, recettes (composant et vue), stock, « Que cuisiner ? » ;
- carte de recette, étiquette, barre de progression ;
- vingt vues où un libellé était masqué sur téléphone ;
- `app.css` (menus, jour par jour), `app.js` (balayage) ;
- `package.json` (Playwright, axe-core).

## À vérifier sur ton poste

- Sur ton téléphone :
  - **Planning** : la bande des jours, un balayage, puis **Plus** ;
  - **Accueil** : « C'était mangé ? » ;
  - **Recettes** : **Filtres**.
- Dans Safari en particulier, le balayage et la feuille « Plus » (ils n'ont été testés que dans
  Chrome).
- Sur l'ordinateur, le planning doit être comme avant, avec les libellés de toutes les actions.
