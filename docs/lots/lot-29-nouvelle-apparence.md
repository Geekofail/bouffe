# Lot 29 — Nouvelle apparence

**Objectif** : une application plus chaleureuse et plus lisible, d'après la maquette validée le
27 septembre 2026.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **888 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+21).
- **35 vérifications dans le navigateur** passent, **contraste des couleurs compris** (il n'était pas
  vérifié au lot 28).
- Aucune migration. La taille du texte est rangée dans les préférences de chaque personne
  (`users.preferences`, clé `text_size`).

Spécification : [08-version-4.md](../08-version-4.md), module 29. Maquette :
« Bouffe — nouvelle apparence (maquette lot 29) ».

## Mise à jour depuis le lot 28

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

C'est tout. Les polices sont déjà dans `public/build` : pas besoin de `npm install` pour utiliser
l'application (seulement pour relancer les tests dans le navigateur).

Si tu veux faire le ménage : les anciens fichiers `app-*.css` et `app-*.js` de
`public/build/assets` qui ne sont plus cités dans `public/build/manifest.json` peuvent être supprimés.
Ils ne gênent pas.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 29.1 | **Police et hiérarchie** | **Figtree** pour le texte, **Fraunces** pour les titres (choix validé). Les deux sont servies par Bouffe lui-même (aucun appel à Google, la politique de contenu reste stricte). Titres de page plus mesurés sur téléphone ; les titres de section (162) passent en Fraunces | ✅ |
| 29.2 | **Couleurs aux rôles distincts** | **Tomate** pour agir, **framboise** pour alerter, **basilic** pour « réussi », safran pour surveiller ; fond crème, encre brun foncé. Des couleurs douces par **type de plat** : entrée (vert), plat (jaune), dessert (mauve), accompagnement (bleu). Mode sombre revu avec les mêmes rôles : les boutons y sont clairs, à texte foncé | ✅ |
| 29.3 | **Illustrations au lieu des lettres** | 9 dessins originaux : soupe, salade, tarte, pâtes, mijoté, crêpes, gratin, gâteau, et une assiette par défaut. Choisis d'après le titre (« Velouté… » → soupe, « pâte brisée » → tarte), sinon les catégories ; teintés selon la place du plat dans le repas, sinon les catégories. La vraie photo les remplace dès qu'il y en a une (Q45). Présents sur les cartes, la liste, le planning, l'accueil, le choix d'un repas, « Que cuisiner ? » et la fiche recette | ✅ |
| 29.4 | **Cartes de recettes** | Image plus basse (3:2), deux par ligne sur téléphone, quatre sur grand écran ; deux étiquettes au plus ; temps, difficulté et prix sur une ligne. **Vue liste** compacte au choix (bouton en haut à droite), retenue dans l'adresse (`?affichage=liste`) | ✅ |
| 29.5 | **Planning redessiné** | Vignette de la recette dans chaque case, étiquette colorée par type de plat, cases vides en pointillés avec un « + », aujourd'hui en tomate pâle sans bloc plein | ✅ |
| 29.6 | **Transitions** | Fondu court (180 ms) d'une page à l'autre ; l'en-tête et la barre du bas restent en place. Rien sur les navigateurs qui ne connaissent pas l'API View Transitions, rien si le téléphone demande de réduire les animations | ✅ |
| 29.7 | **Écrans vides accueillants** | Illustration, titre et premiers pas : carnet vide (« Nouvelle recette », « Importer une recette »), tickets (« Photographier un ticket »), listes de courses (« Générer la liste de courses »), réceptions (« Ouvrir le planning ») | ✅ |
| 29.8 | **Taille du texte** | **Paramètres › Affichage** : Normal ou Grand (+12,5 %), par personne. Tout suit, y compris les cases de la liste de courses, le mode magasin et le mode cuisine | ✅ |
| — | **Barre du bas** (maquette) | Le **« + »** est au centre de la barre, il ne recouvre plus le contenu ; **Plus** (toutes les sections) passe en haut à droite, avec son point d'alerte | ✅ |
| — | Tests | +21 tests Pest (888) ; contraste vérifié dans le navigateur | ✅ |

## Ce que le contrôle du contraste a corrigé

En l'activant, 21 vérifications échouaient. Toutes sont corrigées :

| Où | Avant | Après |
|---|---|---|
| Boutons principaux, mode sombre | Texte blanc sur tomate claire (2,3:1) | Texte foncé (6,9:1) |
| Textes gris clair (dates, aides, « Rien de prévu »…) | Gris trop pâle (3:1) | Gris plus soutenu (5:1 en clair, 6,4:1 en sombre). Les icônes gardent le gris clair |
| « Que cuisiner ? » : jours restants | Orange vif (3,6:1) | Orange foncé |
| Notes (★) sur les cartes | Ambre vif | Ambre foncé |
| Image des cartes de recette | Lien sans nom pour un lecteur d'écran | Caché aux lecteurs d'écran et au clavier : le titre, juste dessous, est le vrai lien |

## Choix et limites

- **9 dessins, pas une vingtaine.** Le document 08 parlait d'une vingtaine. J'ai préféré 9 dessins
  soignés et cohérents qui couvrent la grande majorité des titres ; les autres plats prennent
  l'assiette. Ajouter un dessin est simple (un cas dans `dish-illustration.blade.php` et ses mots dans
  `DishIllustration.php`).
- **Ce ne sont pas des photos** : toutes les quiches ont le même dessin. C'est voulu (Q45) ; la
  photo reste la meilleure image.
- **Transitions** : seulement d'une page à l'autre. L'ouverture d'un repas ou d'une carte n'a pas
  d'animation propre.
- **Polices** : sous-ensemble latin seulement (français, anglais, allemand, luxembourgeois). Un nom
  en écriture non latine s'affiche avec la police du système.
- **Safari** n'est toujours pas testé automatiquement (voir lot 28) : le rendu des polices et des
  transitions est à regarder sur ton iPhone.
- Les **courriels** (résumé de la semaine, avis) prennent la nouvelle tomate ; leur mise en page ne
  change pas.

## Fichiers

| Fichier | Rôle |
|---|---|
| `app/Support/DishIllustration.php` | Choix du dessin et de la teinte d'un plat |
| `resources/views/components/dish-illustration.blade.php` | Les 9 dessins |
| `resources/views/components/empty-state.blade.php` | Écrans vides : illustration, titre, premiers pas |
| `tests/Feature/Comfort/NewLookTest.php` | 21 tests |

Fichiers modifiés :

- `resources/css/app.css` : polices, palette, couleurs des types de plats, mode sombre, taille
  « Grand », transitions ;
- mise en page (`layouts/app`, `layouts/cook`, mode magasin, `partials/head`) : en-tête, barre du
  bas, taille du texte, couleur de la barre du navigateur ;
- recettes (composant et vue : cartes ou liste), carte de recette, planning et vignette de repas,
  accueil, choix d'un repas, « Que cuisiner ? », fiche recette ;
- Paramètres › Affichage (taille du texte) ;
- une soixantaine de vues : titres en Fraunces, gris lisibles ;
- `public/manifest.webmanifest`, deux modèles de courriel : nouvelle couleur ;
- `package.json` : `@fontsource/figtree`, `@fontsource/fraunces` ;
- `tests/Browser` : contraste vérifié, couleurs mesurées affichées en cas d'échec.

## À vérifier sur ton poste

- Sur ton iPhone, en mode sombre puis clair : l'accueil, les recettes, le planning. Les titres
  doivent être en Fraunces (à empattements), le reste en Figtree.
- Le « + » au centre de la barre du bas, et **Plus** en haut à droite.
- **Paramètres › Affichage › Taille du texte** : Grand, puis le mode cuisine et une liste de courses.
- **Recettes** : le bouton liste / cartes en haut à droite.
