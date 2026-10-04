# 08 — Version 4 : analyse et propositions

Ce document prépare la **version 4** de Bouffe. Il fait suite à [07-version-3.md](07-version-3.md)
(modules 22 à 27, règles R23 à R31, lots 21 à 27, tous livrés : 855 tests sous SQLite, MariaDB et
MySQL 8).

Cette fois, il ne part pas d'idées notées par Pierre mais d'une demande ouverte : **améliorations
graphiques**, **amélioration de fonctionnalités existantes** et **nouvelles fonctionnalités**. Les
propositions viennent de trois sources :

1. un **tour de l'application** dans le navigateur, sur ordinateur (1280 px) et sur iPhone
   (390 px), écran par écran (§1.1) ;
2. les **idées notées mais jamais réalisées** dans les documents 05 à 07, et les **limites** écrites
   dans le détail des lots (§1.2) ;
3. l'**état technique** du code (§1.3).

Il contient :

1. le **bilan** ;
2. les **principes** de la V4 ;
3. neuf **modules** (28 à 36), classés en trois familles : ergonomie et apparence, fonctionnalités
   existantes améliorées, nouveautés ;
4. les **règles de gestion** R32 à R37 ;
5. les **ajouts au modèle de données**, les **écrans** et les **choix techniques** ;
6. le **découpage en lots** et les **questions ouvertes** Q43 à Q51.

À partir de la V4, **un module = un lot, avec le même numéro** (le module 28 est livré par le lot 28),
pour ne plus avoir à traduire l'un en l'autre.

Marqueurs (inchangés) : **Priorité** ★★★ essentiel · ★★ utile · ★ bonus — **Taille** S · M · L —
**Origine** 🔁 amélioration de l'existant · 📌 idée de Pierre (notée dans un document précédent) ·
✨ nouvelle proposition — **🎨** apparence — **🌐** nécessite Internet · **💶** service payant à
l'usage (quelques centimes).

---

## 1. Bilan

### 1.1 Ce que montre le tour de l'application

Le tour a été fait avec les données d'essai de la base de développement, sur douze écrans : accueil,
recettes, fiche, planning, liste de courses, stock, budget, paramètres, mode cuisine, « Que
cuisiner ? », réceptions, tickets. Aucun débordement horizontal ni erreur de politique de contenu.
Les problèmes relevés sont d'**ergonomie** et d'**apparence**, surtout sur téléphone.

**Défauts à corriger (téléphone surtout)**

| # | Écran | Constat |
|---|---|---|
| E1 | **Planning** (téléphone) | Le bandeau « 7 produits à consommer d'ici dimanche » est écrasé par ses deux liens : le texte s'affiche **un mot par ligne** sur toute la hauteur de l'écran |
| E2 | **Planning** (téléphone) | La barre d'outils devient **dix icônes sans texte** sur deux lignes (liste, imprimer, remplir, semaines types, en avance, mes repas, liste, copier, vider, invités) : impossible de deviner laquelle fait quoi |
| E3 | **Accueil** (téléphone) | « C'était mangé ? » : les boutons **Mangé** / **Pas mangé** prennent toute la largeur, le nom du plat est coupé (« Salade gr… », « Curry de … ») et la date tient sur trois lignes |
| E4 | **Recettes** (téléphone) | Le premier écran n'est fait que de **filtres** (quatre listes déroulantes, une case, treize étiquettes) : la première recette n'apparaît qu'après deux écrans de défilement |
| E5 | **Recettes** | Sur une carte, l'étiquette « À tester » **chevauche** « De saison » |
| E6 | **Stock** (téléphone) | Trois des quatre boutons d'en-tête sont des icônes sans texte ; l'icône « statistiques » n'a pas de cadre, elle ressemble à une décoration |
| E7 | **Accueil** (ordinateur) | Quatre gros boutons rouges **Mangé** à la suite en haut de page, puis des boutons rouges dans presque chaque bloc : aucune action ne ressort, l'œil ne sait pas où aller |

**Apparence : ce qui date ou manque de soin**

| # | Constat |
|---|---|
| A1 | **Recettes sans photo** (la majorité) : une grande lettre pâle sur fond rose (« T », « P », « G »…). Sur la page Recettes, le carnet ressemble à un abécédaire ; sur le planning, les cases n'ont aucune image |
| A2 | **Typographie** : police système (DejaVu, Segoe ou San Francisco selon l'appareil). Les titres sont très gras et très grands sur téléphone (« Planning » occupe un tiers de la largeur) |
| A3 | **Planning** (ordinateur) : les cases vides font la hauteur de la plus haute case de la ligne, avec un grand cadre en pointillés « + Ajouter » ; une semaine peu remplie paraît vide et étirée |
| A4 | **Couleur de marque partout** : le rouge-orangé sert à la fois aux boutons principaux, aux liens, aux alertes « dépassée », aux badges de notification et au bouton +. Il ne distingue plus rien |
| A5 | **Pas de transitions** : changer de page ou d'onglet est instantané mais sec ; ouvrir un repas du planning ne donne aucun repère visuel |
| A6 | **Écrans vides** (aucune réception, aucun ticket) : une icône grise et une phrase, sans aide pour commencer |

### 1.2 Idées notées jamais réalisées, et limites connues

**Idées de la V2 (document 06) restées sans lot**

| # | Idée | Où elle en est |
|---|---|---|
| 12.5 | **Annuler partout** (10 s) | « Annuler » existe pour certains gestes (repas mangé, stock, fusion d'ingrédients), pas pour vider une semaine, retirer un article, déplacer un repas |
| 12.8 | **Aide contextuelle** | Absente (une seule « Astuce » fixe sous le planning) |
| 12.9 | **Taille du texte** Normal / Grand | Absente |
| 12.10 | **Journal d'activité du foyer** | Absent (seul existe le journal des connexions du lot 25) |
| 13.11 | **Collections** (« Noël », « Recettes de mamie ») | Absentes : seules les catégories existent |
| 13.12 | **Plusieurs photos** (étapes, « notre version ») | Une seule photo par recette |
| 14.12 | **Statistiques de planning** | Absentes (le stock a les siennes) |
| 15.9 | **Partage amélioré** (lien en lecture seule) | Copie en texte seulement |
| 16.6 | **Stock minimum appris** | Le minimum se règle à la main |
| 16.7 | **Durée après ouverture apprise** | Le lot 18 affiche « tenu 10 jours en moyenne » au rangement, sans proposer de corriger le réglage |
| 16.8 | **Inventaire rapide** (« rien n'a bougé depuis 2 mois ») | Le lot 21 propose 3 à 5 vérifications ciblées ; pas d'inventaire par ancienneté |

**Idée de la V3 (document 07)** : C6 **langues** (allemand, anglais) — laissée de côté (Q42 : « non
pour l'instant »). Elle reste hors de cette V4, sauf avis contraire.

**Limites écrites dans le détail des lots**

| Lot | Limite | Piste |
|---|---|---|
| 26 | Aucune notification pour une invitation, un surplus ou un avis d'un foyer relié | Notifications réglables (30.6) |
| 26 | Dans le planning d'un proche ouvert en écriture, on ajoute et on retire, on ne déplace pas | Laissé tel quel |
| 26 | Le carnet familial en PDF passe par l'impression du navigateur | Laissé tel quel |
| 27 | Une **promotion** compte comme un prix ordinaire : sa fin ressemble à une hausse | Promotions reconnues (30.7, R35) |
| 27 | Pas de notification pour une hausse de prix | Notifications réglables (30.6) |
| 23 | Les 20 tickets réels du jeu de tests restent à constituer | À faire au fil de l'eau (`php artisan bouffe:ticket`) |

### 1.3 État technique

- **855 tests** automatiques, sur trois bases. Mais **aucun test ne tourne dans un vrai navigateur** :
  les défauts E1 à E6 ne peuvent être vus que par les vérifications manuelles de fin de lot.
- **Pas de Git** : le document 07 (§7.4) le disait indispensable avant la mise en ligne ; les lots 25
  à 27 ont été livrés par copie de fichiers. Aucun historique, aucun retour arrière possible autre
  que les sauvegardes.
- Quelques fichiers sont devenus **gros** et plus risqués à modifier : planning (composant 676
  lignes, vue 620 lignes), fiche recette (582 et 613), stock (625), `ShoppingListManager` (746).
- Le **style du code** n'est pas vérifié automatiquement (l'outil Pint est installé mais le code ne
  le respecte pas).

---

## 2. Principes directeurs de la V4

1. **Le téléphone d'abord.** C'est là que Bouffe sert le plus (courses, cuisine, « c'était
   mangé ? »). Chaque écran est dessiné à 390 px avant de l'être à 1280 px.
2. **Une action principale par écran.** Une seule couleur pleine pour ce qu'on fait le plus souvent
   ici ; le reste en boutons discrets ou dans un menu « ⋯ » avec des libellés.
3. **Rien de nouveau n'est plus difficile que l'ancien.** Chaque nouveauté se retrouve là où on
   l'attend, ou reste cachée tant qu'on ne s'en sert pas (comme les foyers reliés au lot 26).
4. **Ce que Bouffe apprend, il le propose ; il ne l'applique pas seul** (suite du principe 2 de la
   V3). Un minimum de stock appris, une durée après ouverture, une proposition de l'assistant :
   toujours une proposition, qu'on accepte d'un geste ou qu'on écarte.
5. **Chiffres honnêtes, images honnêtes.** Pas de photo inventée d'un plat qu'on n'a pas cuisiné, pas
   de chiffre sans sa base (principe 3 de la V3, étendu aux images).
6. **Le mutualisé OVH reste la cible** (principe 4 de la V3), et **un service externe n'est jamais
   indispensable** (principe 5).

---

## 3. Vue d'ensemble

| Module | Famille | Objectif | Priorité | Taille | Dépend de |
|---|---|---|---|---|---|
| **28 — Confort sur téléphone** | Ergonomie 🎨 | Corriger E1 à E7, alléger les écrans chargés, tester dans un vrai navigateur | ★★★ | M | — |
| **29 — Nouvelle apparence** | Apparence 🎨 | Une identité plus chaleureuse et plus lisible : police, couleurs, illustrations, planning et recettes redessinés | ★★ | L | 28 |
| **30 — Bouffe apprend et prévient** | Existant 🔁 | Annuler partout, journal du foyer, aide, apprentissages du stock, notifications réglables, promotions | ★★ | M | — |
| **31 — Recettes enrichies** | Existant 🔁 | Collections, plusieurs photos, cuisiner un repas complet, partager une recette | ★★ | M | — |
| **32 — Portions justes** | Nouveau ✨ | Appétit de chacun (enfant, adolescent, grand appétit) ; gamelles du midi | ★★ | S | — |
| **33 — Assistant culinaire** 🌐 💶 | Nouveau ✨ | Transformer une recette, « que faire avec… », compléter un import : toujours en brouillon à relire | ★★ | M | — |
| **34 — Séjours et grandes tablées** | Nouveau ✨ | Vacances ou week-end à plusieurs : dates, personnes, menus, courses et frais partagés | ★ | M | 26, 32 |
| **35 — Écran de cuisine et bilan** | Nouveau ✨ | Tablette posée en cuisine ; statistiques et « l'année en cuisine » | ★ | S | 29 |
| **36 — Socle technique** | Technique | Git, tests automatiques à chaque modification, gros fichiers découpés | ★★ | M | 28 |

---

## Module 28 — Confort sur téléphone 🎨

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 28.1 ✅ | **Défauts corrigés** | E1 : le texte du bandeau passe au-dessus, ses liens en dessous. E3 : sur téléphone, nom du plat en entier puis boutons sur leur propre ligne. E5 : étiquettes des cartes empilées, jamais superposées. E6 : chaque bouton d'en-tête garde un libellé court | ★★★ | S | 🔁 |
| 28.2 ✅ | **Menu « Actions » du planning** | Sur téléphone : trois boutons visibles (**Liste de courses**, **Remplir**, **⋯ Plus**) ; « Plus » ouvre une feuille avec les huit autres actions **libellées** et regroupées (Semaine : copier, vider, semaines types · Préparer : en avance, imprimer · Vues : mes repas, liste, invités) | ★★★ | S | 🔁 |
| 28.3 ✅ | **Planning jour par jour** sur téléphone | En plus de la grille : un jour à la fois, balayage gauche / droite, les cases vides réduites à une ligne « + Déjeuner » ; la grille reste disponible (bouton) | ★★ | M | ✨ |
| 28.4 ✅ | **Filtres en feuille** | Recettes et stock sur téléphone : une barre de recherche + un bouton **Filtres (3)** qui ouvre une feuille ; les filtres actifs restent visibles en pastilles effaçables | ★★★ | S | 🔁 |
| 28.5 ✅ | **Accueil apaisé** | « C'était mangé ? » : une ligne par repas avec un bouton ✓ et un menu (pas mangé, autre chose) ; « Tout marquer mangé » (déjà là) mis en avant ; les boutons pleins réservés à l'action principale de chaque bloc (principe 2) | ★★ | S | 🔁 |
| 28.6 ✅ | **Zones de pouce** | Actions fréquentes (cocher un article, « Mangé », minuteur) atteignables d'une main : 44 px minimum, en bas d'écran quand c'est possible | ★★ | S | ✨ |
| 28.7 ✅ | **Tests dans un vrai navigateur** | Chaque écran principal ouvert automatiquement à 390 px (sombre) et 1280 px (clair) : aucune erreur JavaScript, aucun débordement, aucun défaut d'accessibilité grave ; captures comparées d'un lot à l'autre (§7.2) | ★★★ | M | ✨ |

> **Réalisé au lot 28** ([détail](lots/lot-28-confort-telephone.md)). Écarts : sur téléphone, le
> planning garde **deux** boutons visibles (**Courses**, **Remplir**) plus **Plus**, et la bande des
> jours se termine par **Tout** (toute la semaine) ; la vue « jour par jour » s'applique sous 768 px
> de large, c'est-à-dire aux téléphones ; entre 768 et 1280 px, le menu **Plus** remplace aussi la
> rangée d'icônes. L'accueil garde « Tout marquer mangé », les boutons ✓ /
> ✕ n'affichent leur libellé qu'à partir de 640 px. Les tests dans le navigateur sont écrits avec
> **Playwright** directement (`npm run test:browser`) et non avec l'extension navigateur de Pest,
> impossible à installer ici ; Safari n'est pas testé (Chrome qui imite un iPhone) ; les captures
> sont jointes au rapport mais pas comparées d'un lot à l'autre (elles changent avec la date du
> jour) ; la cible tactile vérifiée est celle du WCAG 2.2 (24 px ou assez d'espace autour), 44 px
> pour les boutons ajoutés par le lot.

---

## Module 29 — Nouvelle apparence 🎨

L'objectif n'est pas de tout changer : l'organisation des écrans reste la même, Pierre et Monique
doivent tout retrouver. Ce qui change, c'est **l'allure** : plus chaleureuse, plus lisible, plus
« cuisine ». Une **maquette** de trois écrans (accueil, planning, recettes) est présentée et validée
avant d'écrire le code (Q44).

> **Maquette présentée et validée le 27 septembre 2026** : palette par rôle (tomate pour agir,
> framboise pour alerter, basilic pour réussi, safran pour surveiller, fond crème), titres en
> Fraunces et texte en Figtree, illustrations originales, accueil, planning et recettes sur téléphone
> et ordinateur, accueil en mode sombre. Deux propositions en plus du document, retenues : le bouton
> « + » au centre de la barre du bas (il ne recouvre plus le contenu) et « Plus » en haut à droite.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 29.1 ✅ | **Police et hiérarchie** | Une police lisible hébergée par Bouffe lui-même (aucun appel à Google : la politique de contenu reste stricte), titres moins massifs sur téléphone, chiffres alignés dans les tableaux | ★★ | S | ✨ |
| 29.2 ✅ | **Couleurs aux rôles distincts** | La couleur de marque pour **agir** ; un rouge distinct pour **alerter** (dépassé, allergie) ; un vert pour **réussi** ; des couleurs douces par **type de plat** (entrée, plat, dessert, accompagnement) ; mode sombre revu avec les mêmes rôles | ★★ | S | ✨ |
| 29.3 ✅ | **Illustrations au lieu des lettres** | Une vingtaine d'illustrations **originales**, simples et cohérentes (soupe, gratin, salade, tarte, pâtes, curry, gâteau, crêpes…), choisies d'après les catégories et le titre ; remplacées par la vraie photo dès qu'il y en a une (Q45) | ★★ | M | ✨ |
| 29.4 ✅ | **Cartes de recettes** | Photo ou illustration plus basse, temps et prix sur une ligne, étiquettes limitées à deux ; **vue liste** compacte au choix (utile à 50 recettes et plus) | ★★ | S | 🔁 |
| 29.5 ✅ | **Planning redessiné** | Vignette de la recette dans chaque case, couleur par type de plat, cases vides discrètes (« + » au survol ou au toucher), hauteur ajustée au contenu ; aujourd'hui mis en avant sans bloc plein | ★★ | M | 🔁 |
| 29.6 ✅ | **Transitions** | Passage doux d'une page à l'autre et à l'ouverture d'un repas ou d'une carte (API View Transitions, via Livewire) ; sans effet sur les navigateurs qui ne la connaissent pas ; désactivées si le téléphone demande « réduire les animations » | ★ | S | ✨ |
| 29.7 ✅ | **Écrans vides accueillants** | Illustration et deux ou trois premiers pas (« Photographier un ticket », « Importer une recette depuis un site ») | ★ | S | ✨ |
| 29.8 ✅ | **Taille du texte** | Normal / Grand, par personne (idée 12.9) ; « Grand » agrandit aussi les cases à cocher de la liste et le mode cuisine | ★★ | S | 📌 |

> **Réalisé au lot 29** ([détail](lots/lot-29-nouvelle-apparence.md)). Écarts : **9 dessins** au lieu
> d'une vingtaine (soupe, salade, tarte, pâtes, mijoté, crêpes, gratin, gâteau, et une assiette pour
> le reste), choisis d'après le titre puis les catégories ; les transitions ne concernent que le
> passage d'une page à l'autre (pas d'animation propre à l'ouverture d'un repas) ; dans le planning,
> le « + » des cases vides reste visible, en pointillés discrets, pour le toucher. Le contraste des
> couleurs est désormais vérifié par les tests dans le navigateur (WCAG AA), en clair comme en
> sombre ; en sombre, les boutons tomate sont clairs à texte foncé. Polices servies par Bouffe
> (sous-ensemble latin).

---

## Module 30 — Bouffe apprend et prévient 🔁

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 30.1 ✅ | **Annuler partout** | Tout geste qui retire ou remplace (vider une semaine, retirer un article, déplacer un repas, supprimer un relevé, régénérer une liste) affiche « Annuler » pendant 10 secondes (R32) | ★★ | M | 📌 (12.5) |
| 30.2 ✅ | **Journal du foyer** | « Monique a coché 12 articles », « Pierre a planifié 3 repas », « Léo a ajouté une envie » ; 30 jours, filtrable par personne ; visible des membres du foyer seulement | ★ | S | 📌 (12.10) |
| 30.3 ✅ | **Aide contextuelle** | Une bulle « Le saviez-vous ? » la première fois qu'on ouvre un écran riche (glisser-déposer du planning, mode présence du stock, répartir la liste entre magasins) ; « Ne plus afficher » ; toutes réactivables dans Paramètres | ★ | S | 📌 (12.8) |
| 30.4 ✅ | **Stock minimum appris** | « Vous achetez du lait chaque semaine : garder au moins 1 l ? » d'après les achats et les retraits (R36) | ★★ | S | 📌 (16.6) |
| 30.5 ✅ | **Durée après ouverture apprise** | « La crème liquide ouverte est jetée 3 fois sur 4 avant 5 jours : passer la durée de 5 à 3 jours ? » (R36) ; même chose pour les durées de conservation au réfrigérateur | ★★ | S | 📌 (16.7) |
| 30.6 ✅ | **Notifications réglables, étendues** | Nouveaux types, chacun activable par personne : invitation d'un foyer relié, réponse à mon invitation, surplus proposé, avis reçu, hausse de prix marquée ; « ne pas déranger » la nuit | ★★ | S | 🔁 (lots 26, 27) |
| 30.7 ✅ | **Promotions reconnues** | Une ligne de ticket avec remise, ou un prix noté « en promo », est marquée **promo** : visible dans le comparateur, exclue des hausses marquées et de l'indice du panier ; « meilleur prix vu » affiché à part (R35) | ★★ | S | 🔁 (lot 27) |
| 30.8 ✅ | **Inventaire par ancienneté** | « 12 articles n'ont pas bougé depuis 2 mois » : les passer en revue un par un (toujours là / fini / jeté) ; aussi par emplacement | ★ | S | 📌 (16.8) |

> **Réalisé au lot 30** ([détail](lots/lot-30-apprend-et-previent.md)). Écarts : « Annuler » couvre
> vider la semaine, retirer / déplacer / copier un repas, retirer ou supprimer un article, mettre la
> liste à jour, supprimer une liste, supprimer un relevé de prix (pas encore les semaines types ni
> le remplissage automatique) ; le serveur accepte l'annulation 2 minutes, l'écran la propose
> 10 secondes, et seule la personne qui a fait le geste peut l'annuler. Le journal ne note que les
> gestes d'une personne connectée, regroupés par tranches de 30 minutes. Les durées apprises ne
> proposent que des baisses. Le « meilleur prix vu » s'affiche pour un produit qui a aussi un prix
> courant. « Réglage modifié depuis moins de 30 jours » s'applique à tout l'ingrédient.

---

## Module 31 — Recettes enrichies 🔁

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 31.1 ✅ | **Collections** | Regroupements libres en plus des catégories : « Noël », « Recettes de mamie », « Quand Léo vient » ; une recette dans plusieurs collections ; une collection partageable avec les foyers reliés et imprimable en carnet (26.9) | ★★ | S | 📌 (13.11) |
| 31.2 ✅ | **Plusieurs photos** | Photo principale + photos d'étapes (affichées dans le mode cuisine à la bonne étape) + « notre version » prise après le repas (proposée à « Mangé ») | ★★ | M | 📌 (13.12) |
| 31.3 ✅ | **Cuisiner un repas complet** | Depuis un repas à plusieurs plats (entrée, plat, dessert) : un seul mode cuisine qui **entrelace** les étapes selon les temps de préparation, cuisson et repos, pour que tout soit prêt à l'heure (reprend le rétroplanning des réceptions, lot 20) ; minuteurs nommés par plat | ★★ | M | ✨ |
| 31.4 ✅ | **Partager une recette** | Lien en lecture seule, valable 30 jours, révocable, pour quelqu'un qui n'a pas Bouffe ; page propre, imprimable, sans notes de cuisine ni prix (idée 15.9 étendue aux recettes) | ★ | S | 📌 (15.9) |
| 31.5 ✅ | **Historique d'une recette** | Qui a modifié quoi et quand ; revenir à une version précédente | ★ | S | ✨ |

> **Réalisé au lot 31** ([détail](lots/lot-31-recettes-enrichies.md)). Écarts : la photo principale
> reste `recipes.photo_path` ; `recipe_photos` garde les photos d'étapes (par **numéro** d'étape, pas
> `recipe_step_id`, car les étapes sont réécrites à chaque modification) et « notre version ». Une
> collection partagée ne montre aux proches que les recettes qu'ils peuvent déjà lire (on propose
> d'ouvrir les autres). Repas complet : entrée servie à l'heure du repas, plat +15 min, fromage +35,
> dessert +45 (les écarts des réceptions pour une réception). L'historique commence à la première
> modification après la mise à jour et ne suit que « Modifier » et les retours arrière.

---

## Module 32 — Portions justes ✨

Aujourd'hui, chaque personne compte pour **une portion**. Or Léo n'a pas l'appétit d'un enfant de
quatre ans, et inversement.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 32.1 ✅ | **Appétit de chacun** | Pour chaque membre du foyer et chaque invité : **petit** (enfant jusqu'à 6 ans), **moyen** (6–12 ans), **normal**, **grand** ; le nombre de portions d'un repas devient la somme des appétits, arrondie (R33). Les quantités de la liste de courses et les restes suivent | ★★ | S | ✨ |
| 32.2 ✅ | **Affichage clair** | « 4 personnes · 3,5 portions » sur le planning ; la fiche recette propose « pour 3,5 portions » arrondi à la demi-portion | ★★ | S | ✨ |
| 32.3 ✅ | **Gamelles du midi** | « Midi au travail » : placer un reste en gamelle pour une personne précise (« gamelle de Pierre, mardi ») ; la liste de ce qu'il faut préparer la veille ; étiquette imprimable (réutilise celles du congélateur, lot 18) | ★★ | S | ✨ |

> **Réalisé au lot 32** ([détail](lots/lot-32-portions-justes.md)). Écarts : l'appétit des membres
> n'est pas une colonne `users.appetite` mais un réglage du foyer (« À table d'habitude »,
> `settings.table.people`), pour compter un enfant sans compte et laisser une personne manger
> différemment dans deux foyers ; tant qu'il n'est pas réglé, chacun compte pour une portion comme
> avant. Les portions passent à la demi-portion partout. Une gamelle est pour une personne qui a un
> compte ; elle se place dans le créneau du midi existant (pas de créneau « Midi au travail » à part)
> et compte la portion de la personne. Dans une case du planning, la pastille garde le nombre de
> personnes, le détail « 5 personnes · 5,5 portions » est en infobulle.

---

## Module 33 — Assistant culinaire 🌐 💶 ✨

Bouffe dispose déjà d'une clé Mistral pour lire les tickets (lot 23). Le même service peut aider à
**écrire** et **adapter** des recettes. Tout ce qu'il produit est un **brouillon** que l'on relit
avant de l'enregistrer (R34) ; sans clé ou sans réseau, les écrans restent les mêmes, sans le bouton.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 33.1 ✅ | **Transformer une recette** | « Version végétarienne », « sans lactose », « pour 2 dans un petit four », « plus rapide » : une **variante** (lot 19) proposée, ingrédients et étapes modifiés surlignés, à accepter, corriger ou jeter | ★★ | M | ✨ |
| 33.2 ✅ | **Que faire avec…** | « Il me reste des courgettes, de la feta et du riz » : trois idées, d'abord **dans notre carnet** (recherche actuelle), puis, si on le demande, des idées nouvelles à importer comme brouillon | ★★ | S | ✨ |
| 33.3 ✅ | **Compléter un import** | Après un import par adresse, texte ou photo (lots 13 et 23) : temps manquants, catégories, type de plat et saisons proposés, à cocher | ★ | S | ✨ |
| 33.4 ✅ | **Une question sur une étape** | Dans le mode cuisine : « Par quoi remplacer la crème ? », « Comment savoir si c'est cuit ? » ; réponse courte, jamais enregistrée dans la recette | ★ | S | ✨ |
| 33.5 ✅ | **Coût maîtrisé** | Compteur mensuel et plafond réglable, comme pour les tickets (24.7) ; estimation : moins d'un centime par demande (§7.4) | ★★ | S | ✨ |

> **Réalisé au lot 33** ([détail](lots/lot-33-assistant-culinaire.md)). Écarts : une proposition qui
> **ajoute** des ingrédients (ou ne change que les étapes) ne peut pas devenir une variante — elle
> devient une nouvelle recette, relue dans l'écran d'import ; les saisons ne sont pas proposées à
> l'import, Bouffe les calcule déjà (R18) ; le plafond est commun à l'installation, en euros, et
> seul l'administrateur le règle ; la recherche « Que faire avec… » dans le carnet marche même
> sans assistant.

---

## Module 34 — Séjours et grandes tablées ✨

Pour une semaine au chalet avec des amis, un week-end chez les parents, un camp scout : des
dates, des gens (de plusieurs foyers ou non), des repas, des courses, et une question à la fin :
« qui doit combien ? ».

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 34.1 ✅ | **Un séjour** | Nom, dates, lieu, participants (membres, invités, foyers reliés), avec leurs appétits (32.1) et contraintes (partagées selon 26.6) ; son propre planning, qui n'encombre pas celui de la maison | ★ | M | ✨ |
| 34.2 ✅ | **Courses du séjour** | Une liste groupée (26.8) calculée sur le séjour, chacun coche et saisit ce qu'il a payé ; sans stock (on n'est pas chez soi) sauf ce qu'on emporte de la maison | ★ | S | ✨ |
| 34.3 ✅ | **Frais partagés** | Total, part de chacun (par personne ou par appétit), qui a avancé quoi, et le **minimum de remboursements** pour tout équilibrer (R37) ; sans paiement dans Bouffe | ★ | S | ✨ |
| 34.4 ✅ | **Emporter de la maison** | « À emporter » : choisir dans son stock ce qui part au séjour ; retiré du stock au départ, le reste remis au retour | ★ | S | ✨ |

> **Réalisé au lot 34** ([détail](lots/lot-34-sejours.md)). Écarts : le séjour vit chez le foyer qui
> l'organise ; un foyer relié qui vient voit sa liste de courses (liste groupée), pas sa fiche. La
> part des frais est portée par un « groupe » (ceux qui paient ensemble) et suit aussi les jours de
> présence de chacun. Les participants d'un foyer relié sont ses comptes (appétit « normal » par
> défaut, modifiable) ; un enfant sans compte s'ajoute comme « autre personne ».

---

## Module 35 — Écran de cuisine et bilan ✨

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 35.1 ✅ | **Mode « écran de cuisine »** | Pour une tablette posée en cuisine : menu du jour et du lendemain, minuteurs en cours, rappels, liste de courses en cours ; très lisible de loin, écran maintenu allumé, mise à jour seule toutes les minutes, sombre le soir | ★ | S | ✨ |
| 35.2 ✅ | **Statistiques de planning** | Recettes les plus cuisinées, part des repas planifiés réellement mangés, repas végétariens par semaine, part de recettes de saison, restes effectivement finis (idée 14.12) | ★ | S | 📌 (14.12) |
| 35.3 ✅ | **L'année en cuisine** | En décembre : une page récapitulative à partager (« 312 repas, 64 recettes différentes, la quiche 14 fois, 23 € jetés de moins que l'an dernier ») ; uniquement des chiffres réels, avec leur base | ★ | S | ✨ |

> **Réalisé au lot 35** ([détail](lots/lot-35-cuisine-et-bilan.md)). Écarts : les minuteurs de
> l'écran de cuisine sont ceux de la tablette elle-même (lancés en mode cuisine sur la tablette, ou
> minuteurs rapides), pas ceux d'un téléphone ; l'année en cuisine se partage par un texte (menu de
> partage du téléphone) et s'imprime, sans lien public ; l'écran de cuisine permet aussi d'ajouter un
> article à la liste de courses. Aucune table ajoutée : tout est calculé à partir du planning et du stock.

---

## Module 36 — Socle technique

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 36.1 ✅ | **Git** | Dépôt privé (GitHub, gratuit) ; chaque lot = une branche et un historique lisible ; retour arrière possible fichier par fichier (Q49) | ★★ | S | 🔁 (§7.4 du doc 07) |
| 36.2 ✅ | **Tests à chaque envoi** | Suite SQLite + tests navigateur à chaque envoi ; MariaDB et MySQL 8 chaque nuit et avant une mise en ligne (§7.3) | ★★ | S | ✨ |
| 36.3 ✅ | **Mise en ligne depuis Git** | `bouffe:deploy` sait récupérer la version validée sur OVH (`git pull`) au lieu d'une copie par SFTP ; la copie reste possible | ★ | S | 🔁 |
| 36.4 ✅ | **Gros fichiers découpés** | Planning, fiche recette, stock, gestion des listes : découpés en composants et services plus petits, sans changer ce que l'on voit ; le style du code vérifié automatiquement (Pint) | ★★ | M | ✨ |

> **Réalisé au lot 36** ([détail](lots/lot-36-socle-technique.md), mode d'emploi :
> [11-git-et-tests.md](11-git-et-tests.md)). Écarts : le dépôt couvre tout le dossier `Bouffe` (code et
> documentation) ; les fichiers construits (`public/build`) y sont gardés, faute de Node chez OVH.
> Le découpage range les méthodes des composants dans des traits et les vues dans des fichiers
> inclus, sans changer les noms des composants. Pint suit le style Laravel sauf
> `fully_qualified_strict_types`. `bouffe:deploy --git` n'accepte que l'avance rapide. Le compte
> GitHub, le premier envoi et la clé de déploiement OVH restent à faire (Q49).

---

## 4. Règles de gestion

### R32 — Annuler

- Tout geste qui **retire, vide, remplace ou déplace** affiche « Annuler » pendant **10 secondes**,
  sur l'écran où il a été fait.
- Annuler remet **exactement** l'état d'avant, y compris les effets sur le stock et la liste de
  courses. Si l'un des éléments a changé entre-temps (quelqu'un d'autre l'a modifié), l'annulation
  est refusée et le dit.
- Les gestes qui ont déjà leur propre retour arrière (repas mangé, retrait du stock) gardent le
  leur.

### R33 — Portions selon l'appétit

- Appétit en **parts** : petit 0,5 · moyen 0,75 · normal 1 · grand 1,5 (valeurs par défaut, Q47).
- Portions d'un repas = somme des parts des présents (membres et invités), **arrondie à la
  demi-portion supérieure**.
- Une portion saisie à la main sur un repas l'emporte toujours (comme aujourd'hui).
- Les restes se comptent en portions « normales ».

### R34 — Assistant

- Rien de ce que propose l'assistant n'est **enregistré sans relecture** : c'est un brouillon, marqué
  « proposé par l'assistant » jusqu'à ce qu'on l'accepte.
- Ce qui est envoyé au service : la recette concernée, ou la liste d'ingrédients tapée ; **jamais**
  le nom d'une personne, ses contraintes nominatives, le stock ou les dépenses. Pour « sans
  lactose », seule la contrainte est envoyée.
- Plafond mensuel réglable ; au-delà, les boutons de l'assistant disparaissent jusqu'au mois suivant.

### R35 — Prix en promotion

- Un prix est **en promotion** s'il vient d'une ligne de ticket avec remise, ou s'il a été noté
  « promo ».
- Le comparateur l'affiche avec son étiquette ; le **prix courant** d'un magasin est le dernier prix
  **hors promotion**.
- L'indice du panier et les hausses marquées ignorent les prix en promotion.
- Le coût des recettes utilise le prix de référence habituel (R17), hors promotion.

### R36 — Apprentissages

- Une proposition n'est faite qu'après **au moins 4 observations sur 8 semaines** (4 achats de lait,
  4 produits ouverts puis jetés…).
- Elle n'est **jamais appliquée seule** : « Appliquer » ou « Non merci ».
- « Non merci » la fait taire **90 jours** pour ce produit.
- Un réglage saisi à la main depuis moins de 30 jours n'est pas remis en question.

### R37 — Frais d'un séjour

- La part de chacun se calcule **par personne** ou **par appétit** (au choix du séjour).
- Les remboursements proposés sont les moins nombreux possible ; les montants sont arrondis au
  centime, l'arrondi restant va à celui qui a le plus avancé.
- Bouffe n'effectue aucun paiement : on coche « remboursé » à la main.

---

## 5. Modèle de données (ajouts)

| Table / colonne | Contenu | Module |
|---|---|---|
| `users.preferences` (existant) : `text_size`, `hints_seen`, `notifications.*` | Taille du texte, aide vue, nouveaux types de notification | 29.8, 30.3, 30.6 |
| `activity_events` (household_id, user_id, type, subject_type, subject_id, summary, created_at) | Journal du foyer ; purge après 30 jours | 30.2 |
| `undo_tokens` (household_id, user_id, action, payload JSON, expires_at) | Annulation des gestes (R32) | 30.1 |
| `learned_suggestions` (household_id, ingredient_id, kind, proposed JSON, status, silenced_until) | Minimum et durées appris (R36) | 30.4, 30.5 |
| `ingredient_prices.is_promo` (booléen) | Prix en promotion (R35) | 30.7 |
| `collections` (household_id, name, description, visibility) + `collection_recipe` (collection_id, recipe_id, position) | Collections | 31.1 ✅ |
| `recipe_photos` (recipe_id, step_number NULL, kind, path, caption, position, planned_meal_id) | Plusieurs photos | 31.2 ✅ |
| `recipe_share_links` (recipe_id, token_hash, expires_at, revoked_at, views) | Lien de partage | 31.4 ✅ |
| `recipe_revisions` (recipe_id, user_id, action, summary, snapshot JSON, created_at) | Historique d'une recette | 31.5 ✅ |
| `guests.appetite` (petit · moyen · normal · grand) ; pour les membres, réglage du foyer `table.people` au lieu de `users.appetite` (lot 32) | Appétit (R33) | 32.1 ✅ |
| `planned_meals.for_user_id` + `is_lunchbox` | Gamelle d'une personne | 32.3 ✅ |
| `assistant_usages` (household_id, user_id, kind, tokens_in, tokens_out, cost_estimate, succeeded, created_at) | Suivi du coût | 33.5 ✅ |
| `stays` (household_id, name, starts_on, ends_on, place, split_mode, notes, departed_at, returned_at) + `stay_participants` + `stay_meals` + `stay_payments` + `stay_packed_items` ; `shopping_lists.stay_id` | Séjours et frais | 34 ✅ |

Aucune table existante n'est transformée en profondeur : la V4 **ajoute**. Chaque table propre à un
foyer porte `household_id` et passe par l'isolation R29 ; le test générique d'isolation les couvre.

---

## 6. Écrans et routes prévus

| Route | Écran | Lot |
|---|---|---|
| `/planning` (téléphone) | Vue jour par jour + menu « Plus » | 28 ✅ |
| `/parametres/affichage` (existant) | Taille du texte, aide contextuelle, transitions | 29, 30 |
| `/foyer/journal` | Journal du foyer | 30 ✅ |
| `/stock/revue` | Inventaire par ancienneté | 30 ✅ |
| `/planning/gamelles` | Gamelles : à préparer la veille, étiquettes | 32 ✅ |
| `/recettes/collections` · `/recettes/collections/{collection}` | Collections | 31 ✅ |
| `/planning/repas/{date}/{creneau}/cuisiner` | Cuisiner un repas complet | 31 ✅ |
| `/partage/recette/{jeton}` | Recette partagée (sans compte) | 31 ✅ |
| `/recettes/{recette}/historique` | Versions d'une recette | 31 ✅ |
| `/sejours` · `/sejours/{sejour}` | Séjours : planning, courses, frais | 34 ✅ |
| `/cuisine` | Écran de cuisine | 35 ✅ |
| `/planning/statistiques` · `/planning/annee/{annee}` | Statistiques et « l'année en cuisine » | 35 ✅ |

---

## 7. Choix techniques

### 7.1 Apparence sans dépendance externe

- **Police** : une police libre (licence SIL Open Font, par exemple Inter ou Nunito Sans), fichiers
  `woff2` servis par Bouffe. La politique de contenu du lot 25 n'autorise que le site lui-même :
  pas de Google Fonts.
- **Illustrations** : dessins originaux en SVG, dans le même trait que les icônes actuelles
  (Heroicons), colorés par la palette. Pas d'images de banques d'images, pas d'images générées
  présentées comme des photos de plats (principe 5).
- **Transitions** : `wire:transition` de Livewire 4 s'appuie sur l'API View Transitions du
  navigateur (Chrome et Edge 111+, Safari 18+, Firefox 144+ en partie) ; sans elle, les éléments
  apparaissent simplement, sans animation. Le modificateur `.navigate` anime aussi le passage d'une
  page à l'autre.
- **Mode sombre** : le système actuel (palette remappée, sans variantes `dark:`) est conservé et
  étendu aux nouveaux rôles de couleur.

### 7.2 Tests dans un vrai navigateur

Pest 4, déjà la base des tests, sait piloter un navigateur (Playwright) : ouvrir une page, simuler
un iPhone, basculer en mode sombre, vérifier qu'il n'y a pas d'erreur JavaScript
(`assertNoJavascriptErrors`, `assertNoSmoke`) et comparer une capture à la précédente
(`assertScreenshotMatches`). Les vérifications faites à la main en fin de lot depuis le lot 11
deviennent des tests permanents. Il faut ajouter l'extension `pestphp/pest-plugin-browser` et
Playwright (Node) ; sur ton PC, ces tests restent facultatifs et la suite habituelle ne change pas.

### 7.3 Git et tests automatiques

- Dépôt **privé** sur GitHub (compte gratuit) ; Bouffe n'a aucun secret dans ses fichiers suivis
  (`.env` est exclu).
- GitHub Actions : 2 000 minutes par mois gratuites pour un dépôt privé. Sur la machine de
  développement, la suite prend environ 2 min 30 s sous SQLite, 6 à 8 min sous MariaDB et 9 min 30 s
  sous MySQL 8 : SQLite à chaque envoi,
  les deux autres bases une fois par nuit et avant chaque mise en ligne, soit moins de 700 minutes
  par mois.
- Le travail avec Claude continue de la même façon ; en plus, chaque lot devient une branche qu'on
  fusionne après validation.

### 7.4 Assistant : service et coût

| Service | Principe | Coût indicatif (septembre 2026) | Remarques |
|---|---|---|---|
| **Mistral Small** (proposition) | Même compte et même clé que la lecture des tickets | 0,15 $ par million de jetons envoyés, 0,60 $ par million reçus (modèle `mistral-small-2603`, relevé de nouveau le 28 septembre 2026 au lot 33 : prix inchangé) | Une transformation de recette ≈ 2 000 jetons envoyés, 1 500 reçus ≈ **0,1 centime** ; 100 demandes par mois ≈ 10 centimes |
| Mistral Medium | Plus capable, plus cher | Environ 1,50 $ et 7,50 $ par million | Réservé aux cas où Small se montre insuffisant |

Comme pour les tickets : une interface commune (`CookingAssistant`) derrière laquelle un autre
service pourrait être branché ; les tests n'appellent jamais le réseau (réponses enregistrées).

---

## 8. Découpage en lots

| Lot | Contenu | Livrable | Taille | Dépend de |
|---|---|---|---|---|
| **28 — Confort sur téléphone** ✅ ([détail](lots/lot-28-confort-telephone.md)) | 28.1 à 28.7 | Plus aucun écran pénible sur téléphone ; les écrans vérifiés automatiquement | M | — |
| **29 — Nouvelle apparence** ✅ ([détail](lots/lot-29-nouvelle-apparence.md)) | Maquette validée, puis 29.1 à 29.8 | Une application plus chaleureuse et plus lisible | L | 28 |
| **30 — Bouffe apprend et prévient** ✅ ([détail](lots/lot-30-apprend-et-previent.md)) | 30.1 à 30.8 (R32, R35, R36) | Moins d'erreurs sans retour, des réglages qui s'ajustent, des prix justes | M | — |
| **31 — Recettes enrichies** ✅ ([détail](lots/lot-31-recettes-enrichies.md)) | 31.1 à 31.5 | Un carnet mieux rangé, plus illustré, et des repas complets sans stress | M | — |
| **32 — Portions justes** ✅ ([détail](lots/lot-32-portions-justes.md)) | 32.1 à 32.3 (R33) | Les bonnes quantités pour chacun ; les gamelles | S | — |
| **33 — Assistant culinaire** ✅ ([détail](lots/lot-33-assistant-culinaire.md)) | 33.1 à 33.5 (R34) | Adapter une recette en un clic, toujours relue | M | — |
| **34 — Séjours** ✅ ([détail](lots/lot-34-sejours.md)) | 34.1 à 34.4 (R37) | Les vacances à plusieurs, courses et comptes compris | M | 26, 32 |
| **35 — Écran de cuisine et bilan** ✅ ([détail](lots/lot-35-cuisine-et-bilan.md)) | 35.1 à 35.3 | La tablette de cuisine ; les chiffres de l'année | S | 29 |
| **36 — Socle technique** ✅ ([détail](lots/lot-36-socle-technique.md)) | 36.1 à 36.4 | Historique, tests à chaque modification, code plus facile à faire évoluer | M | 28 |

**Ordre proposé** : 28 → 29 → 30 → 32 → 31 → 36 → 33 → 35 → 34.

- Le lot 28 corrige ce qui gêne **tous les jours** et met en place les tests navigateur qui
  protégeront la refonte du lot 29.
- Le lot 29 vient juste après, pendant que les écrans sont encore frais ; il commence par une
  maquette à valider.
- Les lots 30 à 32 améliorent l'existant, sans nouvel écran majeur.
- Le lot 36 peut être avancé à tout moment : il ne dépend que de la création d'un compte GitHub (Q49).
- Les lots 33 à 35 sont des nouveautés à faire selon l'envie ; le 34 en dernier, car il s'appuie
  sur les foyers reliés et les appétits.

---

## 9. Questions ouvertes

Comme pour les documents précédents : réponds directement, les propositions par défaut seront
appliquées sinon.

| # | Question | Proposition par défaut |
|---|---|---|
| Q43 | Ordre des lots (§8) : commencer par le confort sur téléphone, ou par une nouveauté ? | 28 → 29 → 30 → 32 → 31 → 36 → 33 → 35 → 34 — *appliqué au lot 28* |
| Q44 | Nouvelle apparence : garder la tomate et la couleur orangée ? Maquette avant de coder ? | Garder le logo et la couleur de marque ; maquette de 3 écrans à valider avant le lot 29 — *appliqué au lot 29* (tomate gardée, couleur de marque ajustée en #C0401C) |
| Q45 | Recettes sans photo : illustrations dessinées (proposition) ou autre chose ? | Illustrations originales par type de plat ; jamais d'image générée présentée comme une photo — *appliqué au lot 29* |
| Q46 | Assistant culinaire (module 33) : le veux-tu, avec la même clé Mistral que les tickets ? | Oui, Mistral Small, plafond 1 € par mois — *appliqué au lot 33* (réglable dans Paramètres › Assistant) |
| Q47 | Appétits : les valeurs 0,5 / 0,75 / 1 / 1,5 te conviennent-elles ? | Oui, modifiables dans Paramètres — *appliqué au lot 32* (Paramètres › Foyer) |
| Q48 | Nouvelles notifications (30.6) : lesquelles activer par défaut ? | Invitation d'un foyer relié et réponse à mon invitation ; les autres désactivées — *appliqué au lot 30* |
| Q49 | Créer un dépôt Git privé sur GitHub (module 36) ? Il faut un compte GitHub à ton nom | Oui ; je prépare tout, tu crées le compte et le dépôt — *appliqué au lot 36* (tout est prêt ; compte et premier envoi : document 11) |
| Q50 | Avez-vous une tablette qui pourrait rester en cuisine (35.1) ? | Écran prévu pour une tablette de 10 pouces, utilisable aussi sur un téléphone posé — *appliqué au lot 35* |
| Q51 | Séjours (module 34) : utile pour vous (vacances à plusieurs, week-ends en famille) ? | Oui, mais en dernier — *appliqué au lot 34* |

---

## Sources

- Livewire 4 — [`wire:transition`](https://livewire.laravel.com/docs/4.x/wire-transition)
  (API View Transitions, navigateurs pris en charge, modificateur `.navigate`) ;
  [navigation `wire:navigate`](https://livewire.laravel.com/docs/4.x/navigate).
- Pest — [Pest v4 et les tests dans le navigateur](https://pestphp.com/docs/pest-v4-is-here-now-with-browser-testing)
  (Playwright, `assertNoJavascriptErrors`, `assertScreenshotMatches`, appareils, mode sombre).
- GitHub — [facturation de GitHub Actions](https://docs.github.com/billing/managing-billing-for-github-actions/about-billing-for-github-actions)
  (2 000 minutes par mois pour les dépôts privés avec le plan gratuit).
- Mistral — [page des tarifs](https://mistral.ai/pricing/) ; prix par modèle relevés sur
  [CostGoat](https://costgoat.com/pricing/mistral-api) (données du 26 septembre 2026, relevées de
  nouveau le 28 septembre 2026 au lot 33) et [OpenRouter](https://openrouter.ai/mistralai/mistral-small-2603) ;
  format des demandes : [documentation de l'API Mistral](https://docs.mistral.ai/) (sorties
  structurées `json_schema`).
