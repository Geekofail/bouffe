# 06 — Version 2 : analyse et spécifications

Ce document prépare la **version 2** de Bouffe. Il complète [02-fonctionnalites.md](02-fonctionnalites.md)
(modules 0 à 7, règles R1 à R6) et [05-evolutions-convives-stock.md](05-evolutions-convives-stock.md)
(modules 8 à 11, règles R7 à R12).

Il contient :

1. le **bilan de la version 1** (lots 0 à 10) et les points de friction constatés ;
2. les **principes** de la V2 ;
3. dix nouveaux **modules** (12 à 21), qui reprennent les idées notées « V2 » ou « Plus tard » et en ajoutent de nouvelles ;
4. les **règles de gestion** R13 à R22 ;
5. les **ajouts au modèle de données**, les **écrans** et les **choix techniques** (hébergement, hors-ligne, services externes) ;
6. le **découpage en lots** 11 à 20 et les **questions ouvertes** Q16 à Q27.

Marqueurs :

- **Priorité** : ★★★ essentiel (gain quotidien) · ★★ utile · ★ bonus.
- **Taille** : S ≈ ½ lot 4 · M ≈ lot 3 · L ≈ lot 4.
- **Origine** : 🔁 amélioration d'une fonctionnalité existante · 📌 idée déjà notée (doc 02 ou 05) · ✨ nouvelle proposition.
- **🌐** : nécessite Internet · **🔒** : nécessite HTTPS (donc un hébergement ou un accès sécurisé, voir §7).

---

## 1. Bilan de la version 1

### 1.1 Ce que fait l'application aujourd'hui

| Domaine | Fonctionnalités en place | Lots |
|---|---|---|
| Référentiels | Unités et conversions, rayons ordonnés, catégories, créneaux, ~150 ingrédients avec réglages de stock | 1, 7 |
| Recettes | Fiche complète (photo, groupes d'ingrédients, étapes, catégories), portions ajustables, notes et commentaires par personne, favoris, duplication, archivage, disponibilité dans le stock | 2, 10 |
| Planning | Grille semaine, glisser-déposer, copie de semaine, restes, repas libres, « mangé », convives et invités (contraintes, « déjà servi »), suggestions « ça fait longtemps » | 3, 6 |
| Courses | Génération depuis le planning (R1 à R5), articles manuels et récurrents, produits de base, cocher à deux en direct, régénération, impression, copie texte, déduction du stock (R8), stock minimum | 4, 9 |
| Stock | Emplacements, ajout rapide, ranger les courses, consommer / jeter / ouvrir / congeler, mode présence, péremption et alertes, retrait au « mangé », restes, inventaire | 7 à 9 |
| Suggestions | « Que cuisiner ? » (R10), onglet « Avec mon stock », « À utiliser rapidement » | 10 |
| Maintenance | Sauvegarde et restauration, sauvegarde automatique, accès téléphone sur le Wi-Fi, icône d'écran d'accueil | 5 |

### 1.2 Points de friction constatés

Relevés pendant le développement et les parcours de test (ordinateur, tablette, iPhone) :

| # | Constat | Conséquence | Réponse V2 |
|---|---|---|---|
| F1 | **Saisir une recette est long** : chaque ingrédient demande quantité, unité, ingrédient | On hésite à ajouter de nouvelles recettes ; le carnet s'appauvrit | Import depuis une URL, collage de texte (module 13) |
| F2 | **La liste de courses exige le Wi-Fi de la maison** | En magasin, il faut la copie texte ou l'impression : on perd le cochage partagé | Hors-ligne, hébergement (modules 15 et 20) |
| F3 | **La fiche recette n'est pas faite pour cuisiner** : l'écran se met en veille, texte petit, pas de minuteur | On repasse sur papier ou on déverrouille sans cesse | Mode cuisine (module 13) |
| F4 | **Planifier une semaine = beaucoup de clics** (ouvrir chaque case, chercher, valider) | Planning souvent incomplet | Remplissage automatique, semaines types, envies (module 14) |
| F5 | **Le stock repose sur la saisie** | Stock vite faux, donc suggestions et déductions moins fiables | Code-barres, rangement assisté, dates apprises (module 16) |
| F6 | **Doublons** : ingrédients proches (« Tomate » / « Tomates cerises » mal choisi), articles manuels + générés pour un même ingrédient | Liste de courses moins lisible, stock éclaté | Fusion d'ingrédients, fusion d'articles (modules 12 et 15) |
| F7 | **Navigation** : 6 entrées principales, beaucoup de pages, pas de recherche générale | Trouver « la crème au frigo » ou « la recette de Monique » demande plusieurs écrans | Recherche globale, bouton « + » d'ajout rapide (module 12) |
| F8 | **Informations dispersées** : alertes stock, restes, convives, courses sont sur des écrans différents | On rate des informations (restes à placer, produit à décongeler) | Accueil « Aujourd'hui » repensé, rappels (modules 12 et 19) |
| F9 | **Aucune vision coût / équilibre / saison** | Difficile de varier ou de tenir un budget | Module 17 |
| F10 | **Sauvegardes sur le même disque que la base** | Une panne du PC fait tout perdre | Sauvegarde externe (module 20) |
| F11 | **Deux personnes, peu d'échanges** dans l'appli : « qui cuisine ? », « j'ai envie de… » passent par d'autres canaux | Planning fait par une seule personne | Envies, qui cuisine, préférences (module 18) |

---

## 2. Principes directeurs de la V2

1. **Moins de saisie** : chaque ajout de données doit pouvoir se faire en une action (coller, scanner, accepter une proposition).
2. **Téléphone d'abord** là où on s'en sert debout : en cuisine, devant le frigo, en magasin.
3. **Proposer, jamais imposer** : les automatismes (remplissage, fusion, rappels) montrent un aperçu et se valident ou s'annulent.
4. **Fonctionne sans Internet** à la maison ; les fonctions qui en ont besoin (import, code-barres) se désactivent proprement quand il n'y en a pas.
5. **Hébergement optionnel** : tout ce qui ne demande pas HTTPS reste disponible en local sous Wamp (voir §7).
6. **Même démarche** que la V1 : services testés d'abord, SQLite + MariaDB, parcours navigateur, document par lot.

---

## 3. Vue d'ensemble

| Module | Besoin | Priorité | Lots |
|---|---|---|---|
| **12 — Confort et navigation** | Trouver et ajouter vite, écran d'accueil plus utile, mode sombre | ★★★ | 11 |
| **13 — Recettes : saisie et cuisine** | Remplir le carnet sans effort, cuisiner avec le téléphone | ★★★ | 12, 13 |
| **14 — Planning intelligent** ✅ ([détail](lots/lot-14-planning.md)) | Une semaine planifiée en une minute, préparation anticipée | ★★★ | 14 |
| **15 — Courses en magasin** | Liste utilisable partout, rangée comme le magasin, avec un coût | ★★★ / ★★ | 16, 17 |
| **16 — Stock sans saisie** | Code-barres, rangement assisté, statistiques anti-gaspillage | ★★ | 18 |
| **17 — Saisons, nutrition et budget** | Manger de saison, équilibré, dans le budget | ★★ | 17, 19 |
| **18 — Foyer** ✅ ([détail](lots/lot-15-foyer.md)) | Préférences de chacun, envies, qui cuisine | ★★ | 15 |
| **19 — Rappels et notifications** | Être prévenu au bon moment (décongeler, restes, liste prête) | ★★ | 14, 20 |
| **20 — Hébergement, hors-ligne et sécurité** | Accès hors de la maison, PWA, sauvegardes externes | ★★★ si hébergement choisi | 16 |
| **21 — Réceptions** | Organiser un repas de fête (menu, rétroplanning, impression) | ★ | 20 |

---

## Module 12 — Confort et navigation

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 12.1 | **Recherche globale** | Champ en haut (raccourci `Ctrl+K` / `/`) : recettes, ingrédients, articles en stock, invités, listes ; résultats groupés, navigation au clavier, actions directes (« Planifier », « Voir au stock ») | ★★★ | S | ✨ |
| 12.2 | **Bouton « + » d'ajout rapide** | Sur téléphone, bouton flottant : ajouter au stock, à la liste de courses, une recette, un repas aujourd'hui / demain, une envie | ★★★ | S | ✨ |
| 12.3 | **Accueil « Aujourd'hui »** repensé | Une seule colonne chronologique : ce qu'on mange (avec « Cuisiner » → mode cuisine), ce qu'il faut préparer (décongeler, faire tremper), restes à finir, produits à consommer, courses en cours ; cartes repliables, ordre réglable | ★★★ | M | 🔁 |
| 12.4 | **Mode sombre** | Automatique (suivant le téléphone) ou forcé ; très utile le soir en cuisine | ★★ | S | 📌 |
| 12.5 | **Annuler partout** | Toute suppression ou action groupée affiche « Annuler » pendant 10 s (planning vidé, article retiré, repas déplacé, liste régénérée) | ★★ | M | 🔁 |
| 12.6 | **Fusion d'ingrédients en double** | « Tomates » → « Tomate » : recettes, stock, listes, contraintes et prix réaffectés ; l'ancien nom devient un **alias** reconnu partout (R22) ; détection des doublons probables (noms proches) | ★★★ | M | 📌 |
| 12.7 | **Barre du bas du téléphone** personnalisable | Choisir les 4 raccourcis (ex. Aujourd'hui, Planning, Courses, Stock) ; le reste dans « Plus » | ★★ | S | 🔁 |
| 12.8 | **Aide contextuelle** | Petites bulles « Le saviez-vous ? » la première fois (glisser-déposer, mode présence, doit utiliser…), désactivables | ★ | S | ✨ |
| 12.9 | **Taille du texte** | Réglage Normal / Grand (utile pour la cuisine et la liste en magasin) | ★ | S | ✨ |
| 12.10 | **Journal d'activité du foyer** | « Monique a coché 12 articles », « Pierre a planifié 3 repas » ; filtrable, sur 30 jours | ★ | S | ✨ |

**12.1 — Recherche globale** : recherche insensible aux accents et au pluriel (réutilise `NameNormalizer` et les alias de 12.6), 5 résultats maximum par groupe, lien « Tout voir ». Tapé dans la recherche, « crème » donne : *Stock* Crème liquide (100 ml, J-3) · *Recettes* Poulet à la crème · *Ingrédients* Crème fraîche épaisse · *Courses* Crème liquide (liste en cours).

**12.3 — Accueil** : le tableau de bord actuel juxtapose des cartes indépendantes. La V2 le transforme en **fil de la journée** :

```
Aujourd'hui · jeudi 17 septembre
─────────────────────────────────
🍽  Dîner : Poulet à la crème · 4 convives          [Cuisiner] [Mangé ✓]
⏰  À préparer : sortir le poulet du congélateur (pour vendredi)   [Fait]
♻  Restes de chili (2 portions, J-3)                 [Planifier demain]
⚠  2 produits à consommer : crème liquide, yaourt   [Que cuisiner ?]
🛒  Courses du 16 au 22 sept. : 3 / 16                [Ouvrir]
📅  Cette semaine : 8 / 14 repas                      [Compléter]
```

---

## Module 13 — Recettes : saisie et cuisine

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 13.1 ✅ | **Import depuis une URL** 🌐 | Coller le lien d'une recette (Marmiton, 750g, Cuisine AZ, blogs…) → lecture des données `schema.org/Recipe` → écran de relecture → enregistrement (R13) | ★★★ | L | 📌 |
| 13.2 ✅ | **Collage de texte** | Coller une recette copiée d'un mail, d'un PDF ou d'un message : titre, portions, ingrédients et étapes détectés (R14), même écran de relecture | ★★★ | M | ✨ |
| 13.3 ✅ | **Saisie rapide des ingrédients** | Dans le formulaire : une zone « une ligne par ingrédient » (« 200 g de farine », « 3 œufs ») transformée en lignes structurées, modifiables ensuite | ★★★ | S | 🔁 |
| 13.4 | **Mode cuisine** 🔒 (écran allumé) | Plein écran, grand texte, une étape à la fois (balayage ou gros boutons), ingrédients de l'étape rappelés, cases à cocher, écran maintenu allumé, portions choisies conservées | ★★★ | M | 📌 |
| 13.5 | **Minuteurs** | Durées détectées dans les étapes (« cuire 25 min ») → bouton ⏱ ; plusieurs minuteurs en parallèle, nommés, sonnerie + vibration ; ils continuent si on change d'étape | ★★★ | S | ✨ |
| 13.6 | **Notes de cuisine datées** | Après un repas « mangé » : « Trop salé », « +10 min de cuisson » ; affichées en haut de la fiche la prochaine fois | ★★ | S | ✨ |
| 13.7 ✅ | **Variantes** | Une recette peut avoir des variantes (« végétarienne », « sans lactose ») qui remplacent certains ingrédients ; proposées automatiquement quand un convive a une contrainte | ★★ | M | ✨ |
| 13.8 ✅ | **Sous-recettes** | Une recette peut en utiliser une autre (pâte brisée, sauce béchamel) : ingrédients agrégés pour la liste de courses, lien dans les étapes | ★★ | M | ✨ |
| 13.9 | **Impression d'une fiche** | Mise en page A4 propre (1 page si possible), avec ou sans photo, portions choisies | ★★ | S | 📌 |
| 13.10 ✅ | **Export / import JSON** | Une recette ou tout le carnet ; partage avec la famille, reprise dans une autre installation | ★★ | S | 📌 |
| 13.11 | **Collections** | Regroupements libres (« Noël », « Recettes de mamie », « À tester ») en plus des catégories | ★ | S | ✨ |
| 13.12 | **Plusieurs photos** | Photos d'étapes, photo « notre version » | ★ | S | ✨ |
| 13.13 ✅ | **Recettes « à tester »** | Statut : importée mais jamais cuisinée ; filtre dédié ; suggestion « testez une nouvelle recette cette semaine » | ★★ | S | ✨ |

### 13.1 et 13.2 — Écran de relecture d'un import

```
Importer une recette                                        [Annuler] [Enregistrer]
────────────────────────────────────────────────────────────────────────────────────
Titre      [Gratin dauphinois                    ]   Source : marmiton.org/…
Portions   [6]   Préparation [20 min]   Cuisson [1 h 15]   Photo : ✓ (importée)
Catégories [Accompagnement ×] [Hiver ×]  (proposées d'après le titre et les ingrédients)

Ingrédients (8)                       reconnu comme
 ✓ 1,5 kg de pommes de terre          Pomme de terre · 1,5 kg
 ✓ 50 cl de crème liquide             Crème liquide · 50 cl
 ? 2 gousses d'ail rose de Lautrec    Ail · 2 gousses        [changer]
 ✗ 1 pincée de noix de muscade        (inconnu)  [créer « Noix de muscade »] [choisir…]
Étapes (5)  …
```

- ✓ ingrédient et unité reconnus avec certitude · ? correspondance probable à vérifier · ✗ inconnu : créer ou associer.
- Les associations choisies sont mémorisées comme **alias** (« ail rose de Lautrec » → Ail) pour les imports suivants.
- Doublon : si l'URL ou un titre très proche existe déjà, proposition « Mettre à jour la recette existante » ou « Créer une copie ».

### 13.4 — Mode cuisine

- Accès : bouton **Cuisiner** sur la fiche, sur l'accueil et dans la vue d'un repas du planning (portions du repas reprises).
- Écran **« Avant de commencer »** : liste des ingrédients à cocher (mise en place), minuteurs détectés, disponibilité dans le stock.
- Pendant : étape courante en grand, étapes précédente / suivante visibles en petit ; ingrédients mentionnés dans l'étape avec quantités mises à l'échelle.
- Fin : « Marquer comme mangé » (déduction du stock du lot 9) et « Ajouter une note de cuisine » (13.6).
- **Écran allumé** : API Screen Wake Lock, disponible seulement en HTTPS 🔒. En HTTP (Wi-Fi local), un message conseille d'allonger la mise en veille ; le reste du mode cuisine fonctionne.

---

## Module 14 — Planning intelligent

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 14.1 ✅ | **Remplir la semaine** | Bouton « Remplir les cases vides » : aperçu des propositions (R15), chaque case peut être relancée 🎲, verrouillée 🔒 ou vidée, puis « Valider » | ★★★ | M | 📌 |
| 14.2 ✅ | **… depuis le stock** | Option du remplissage : privilégier les recettes faisables et anti-gaspillage (score R10) | ★★★ | S | 📌 (10.5) |
| 14.3 ✅ | **Semaines types** | Enregistrer une semaine comme modèle (« Semaine rapide », « Semaine d'hiver ») ; appliquer : remplir les vides ou remplacer ; portions recalculées selon les convives (R16) | ★★ | M | 📌 |
| 14.4 ✅ | **Règles de la semaine** | Réglages simples utilisés par le remplissage et signalés sur le planning : temps max en semaine (ex. 30 min le soir du lundi au jeudi), poisson 1×, végétarien 2×, pas deux fois la même catégorie de suite | ★★ | M | 📌 (équilibre) |
| 14.5 ✅ | **Envies et idées** | Boîte « À planifier bientôt » : une recette ou un texte (« raclette », « tester le curry de Julie ») ajouté par l'un ou l'autre ; glisser une envie sur une case ; le remplissage les place en priorité | ★★★ | S | ✨ |
| 14.6 ✅ | **Préparation anticipée** | Rappels calculés (R21) : décongeler la veille, faire tremper, mariner, pâte à préparer la veille ; affichés sur le planning (icône ⏰), l'accueil et en notification | ★★★ | M | ✨ |
| 14.7 ✅ | **Qui cuisine ?** | Attribuer un repas à Pierre, Monique ou « ensemble » ; filtre « mes repas » ; compteur de la semaine | ★★ | S | ✨ |
| 14.8 ✅ | **Batch cooking** | Sélectionner plusieurs repas → session « Cuisiner en avance » : ingrédients regroupés, étapes ordonnées par recette, plats ajoutés au stock (réfrigérateur ou congélateur) et liés aux repas | ★★ | L | ✨ |
| 14.9 ✅ | **Vue liste sur téléphone** | Alternative à la grille : jours empilés, balayage d'une semaine à l'autre ; vue mois compacte pour anticiper | ★★ | S | 🔁 |
| 14.10 | **Export calendrier (ICS)** | Abonnement au menu dans Google Agenda / Outlook / iPhone (lien secret) | ★ | S | ✨ |
| 14.11 | **Impression du menu** | Semaine sur une page A4 (à afficher sur le frigo), avec ou sans liste de courses au verso | ★★ | S | 📌 |
| 14.12 | **Statistiques de planning** | Recettes les plus mangées, catégories par mois, taux de repas planifiés / mangés | ★ | S | ✨ |

### 14.1 — Aperçu du remplissage

```
Remplir la semaine du 21 septembre              [Relancer tout 🎲]   [Annuler] [Valider 9 repas]
Options : ☑ Utiliser le stock en priorité   ☑ Placer les envies   ☐ Laisser les midis vides
─────────────────────────────────────────────────────────────────────────────────────────────
Lun  Dîner   Omelette aux champignons   ♻ 2 produits · 20 min      🎲  🔒  ✕
Mar  Dîner   Raclette  (envie de Monique)                           🎲  🔒  ✕
Mer  Dîner   Poulet à la crème          ⚠ Julie : lactose           🎲  🔒  ✕
…
```

---

## Module 15 — Courses en magasin

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 15.1 ✅ | **Liste hors-ligne** 🔒 | La liste en cours reste consultable et cochable sans réseau ; les coches sont envoyées au retour du réseau (R20) ; indicateur « hors-ligne · 3 modifications en attente » | ★★★ | L | 📌 (PWA) |
| 15.2 ⛔ | **Solution de secours sans hébergement** | « Emporter la liste » : fichier HTML autonome (cochable, fonctionne en mode avion) enregistré sur le téléphone ; les coches ne remontent pas | ★★ | S | ✨ |
| 15.3 ✅ | **Magasins** | Plusieurs magasins (Cactus, Delhaize, marché…) avec **leur ordre des rayons** ; la liste s'affiche dans l'ordre du magasin choisi ; articles « seulement au marché » | ★★ | M | ✨ |
| 15.4 ✅ | **Mode magasin** | Grand texte, article coché qui descend, écran allumé 🔒, regroupement par rayon repliable, « tout ce rayon est fait » | ★★★ | S | 🔁 |
| 15.5 | **Fusion des articles** | À la régénération, un article manuel du même ingrédient qu'un article généré est fusionné (quantités additionnées si compatibles) ; indication « dont 200 g ajoutés à la main » | ★★★ | S | 🔁 (F6) |
| 15.6 ✅ | **Articles fréquents** | Suggestions en haut de l'ajout manuel : produits achetés souvent et absents de la liste (« Lait — acheté 6 fois ces 2 derniers mois ») | ★★ | S | ✨ |
| 15.7 ✅ | **Prix et coût** | Prix saisi facultativement en cochant (ou au rangement) ; prix de référence par ingrédient ; coût estimé de la liste et de la semaine (R17) | ★★ | M | 📌 |
| 15.8 ✅ | **Liste « quand je passe »** | Liste permanente hors semaine (piles, bougies, cadeau) qui s'ajoute à la prochaine liste générée | ★ | S | ✨ |
| 15.9 | **Partage amélioré** | Envoi par WhatsApp / SMS avec rayons et cases ☐ ; lien de consultation temporaire en lecture seule 🔒 | ★ | S | 🔁 |
| 15.10 | **Commande en ligne** (étude) | Export de la liste au format attendu par un drive (si le magasin le permet) | ★ | — | ✨ |

---

## Module 16 — Stock sans saisie

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 16.1 ✅ | **Scan de code-barres** 🔒🌐 | Caméra du téléphone → produit reconnu (base locale des produits déjà scannés, puis Open Food Facts) → ingrédient associé une fois pour toutes, quantité et date proposées → ajouté au stock | ★★ | L | 📌 |
| 16.2 ✅ | **Rangement assisté** | Au rangement des courses : date proposée d'après les achats précédents du même produit (durée réelle observée), emplacement mémorisé | ★★ | S | 🔁 |
| 16.3 ✅ | **Historique et statistiques anti-gaspillage** | Mouvements filtrables ; tableau : produits jetés par mois (nombre, estimation en €), les plus jetés, part consommée avant la date, évolution | ★★ | M | 📌 (9.12) |
| 16.4 ✅ | **Congélateur organisé** | Vue « plats maison » : date, portions, âge ; suggestion de planifier les plus anciens ; rappel « décongeler » (14.6) | ★★ | S | 🔁 |
| 16.5 ✅ | **Étiquettes** | Impression d'étiquettes (nom, date, portions, QR code) pour les boîtes du congélateur ; scanner le QR code ouvre l'article | ★ | S | ✨ |
| 16.6 | **Stock minimum appris** | Proposition de minimum d'après la consommation (« vous achetez du lait toutes les semaines : minimum 1 l ? ») | ★ | S | ✨ |
| 16.7 | **Péremption après ouverture apprise** | Si un produit ouvert est souvent jeté avant sa durée, proposer de réduire la durée | ★ | S | ✨ |
| 16.8 | **Inventaire rapide** | Inventaire par rayon de liste ou « tout ce qui n'a pas bougé depuis 2 mois » | ★ | S | 🔁 |

> **Réalisé au lot 18** ([détail](lots/lot-18-stock-sans-saisie.md)). Précisions apportées à
> l'implémentation : l'ingrédient est **proposé** d'après le nom du produit et confirmé une seule
> fois ; la durée de conservation apprise (16.2) exige 3 observations et retient la **médiane**,
> et n'apprend que sur les articles consommés, jamais sur ceux qui ont été jetés.

**16.1 — Scan** : la détection de codes-barres intégrée au navigateur (`BarcodeDetector`) n'existe pas dans Safari sur iPhone ; une bibliothèque JavaScript de décodage (type ZXing, en WebAssembly) sert donc de solution commune. L'accès à la caméra exige HTTPS 🔒. Open Food Facts est gratuit (données sous licence ODbL), limite la lecture de produits à **15 requêtes par minute** par adresse IP et demande un en-tête `User-Agent` identifiant l'application : les produits déjà connus sont mémorisés localement pour ne plus l'interroger.

---

## Module 17 — Saisons, nutrition et budget

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 17.1 ✅ | **Saisons** | Mois de saison des fruits et légumes (valeurs fournies pour les ~40 ingrédients concernés, modifiables) ; badge 🌱 « de saison » sur les recettes ; filtre ; bonus dans le remplissage (R18) | ★★ | S | 📌 |
| 17.2 ✅ | **Coût des recettes** | Coût estimé par portion (R17), affiché sur la fiche et le planning ; filtre « moins de 3 € par portion » | ★★ | S | 📌 |
| 17.3 ✅ | **Budget** | Budget mensuel facultatif ; suivi des dépenses saisies (15.7) ; graphique mois par mois | ★ | M | ✨ |
| 17.4 ✅ | **Valeurs nutritionnelles indicatives** | Énergie, protéines, glucides, lipides, fibres, sel par portion, calculés depuis la table **Ciqual 2025** de l'Anses (R19) ; mention « indicatif, couverture 85 % » | ★ | L | 📌 |
| 17.5 ✅ | **Équilibre de la semaine** | Jauges simples : légumes, féculents, protéines animales / végétales, poisson, sucré ; basées sur les catégories et, si disponibles, sur la nutrition | ★ | M | 📌 |

**17.4 — Ciqual** : l'Anses a publié fin 2025 une nouvelle table Ciqual (3 484 aliments, 74 constituants). Les données sont téléchargeables (formats Excel et XML pour les versions précédentes publiées sur data.gouv.fr) : un import unique (`php artisan bouffe:ciqual`) suffit, sans appel Internet ensuite. La correspondance ingrédient → aliment Ciqual est proposée automatiquement puis vérifiée pour les ~150 ingrédients de départ.

---

## Module 18 — Foyer

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 18.1 ✅ | **Préférences des membres du foyer** | Pour Pierre et Monique : allergies, régimes, « n'aime pas » (mêmes types que les invités du lot 6) ; alertes identiques lors de la planification | ★★ | S | 📌 (0.4) |
| 18.2 ✅ | **Envies** | Voir 14.5 ; notification à l'autre personne (« Monique a une envie : lasagnes ») | ★★★ | S | ✨ |
| 18.3 ✅ | **Qui cuisine** | Voir 14.7 | ★★ | S | ✨ |
| 18.4 ✅ | **Réactions sur un repas** | Après « mangé » : 👍 / 👎 rapide par personne (alimente le remplissage ; *lot 15 : gardé distinct de la note sur 5, voir le détail du lot*) | ★★ | S | 🔁 |
| 18.5 ✅ | **Membres supplémentaires** | Ajouter un compte (enfant, colocataire) avec un rôle : complet ou « consultation + liste de courses » — *garde-fou de confort, pas une barrière de sécurité (lot 15)* | ★ | S | ✨ |

---

## Module 19 — Rappels et notifications

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 19.1 ✅ | **Centre de notifications** dans l'application | Cloche : rappels de préparation, produits à consommer, envies, liste prête, restes à planifier ; marquer lu ; réglage par type — *lot 14 : rappels et produits à consommer ; liste prête et restes à planifier au lot 20* | ★★ | S | ✨ |
| 19.2 ✅ | **Notifications sur le téléphone** 🔒 | Web Push : « Ce soir : poulet à la crème — sortir la crème », « 3 produits périment demain » ; heure réglable ; sur iPhone, seulement après ajout de l'application à l'écran d'accueil (iOS 16.4 ou plus récent) | ★★ | M | 📌 |
| 19.3 ✅ | **Récapitulatif par e-mail** | Dimanche soir : menu de la semaine, liste de courses, produits à consommer (nécessite un serveur d'envoi) | ★ | S | ✨ |
| 19.4 ✅ | **Tâche planifiée** | Commande `bouffe:reminders` (planificateur Windows en local, cron chez l'hébergeur) qui calcule et envoie les rappels | ★★ | S | ✨ |

---

## Module 20 — Hébergement, hors-ligne et sécurité

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 20.1 | **Accès sécurisé hors de la maison** | Une des options du §7 : hébergement OVH, VPS ou tunnel privé ; HTTPS obligatoire | ★★★ | M | 📌 (Q1) |
| 20.2 | **Application installable (PWA)** 🔒 | Service worker, écran de démarrage, icône ; pages principales mises en cache | ★★★ | M | 📌 |
| 20.3 | **Hors-ligne** 🔒 | Liste de courses (15.1), recettes consultées récemment et mode cuisine, planning de la semaine en lecture | ★★ | L | ✨ |
| 20.4 | **Sauvegarde externe** | Copie automatique de chaque sauvegarde vers un second emplacement : dossier OneDrive / disque externe en local, stockage objet chez l'hébergeur ; rotation et vérification | ★★★ | S | 🔁 (F10) |
| 20.5 | **Sécurité de connexion** | Limitation des tentatives, verrouillage temporaire, sessions actives visibles et révocables, double authentification facultative (application TOTP) dès que l'application est en ligne | ★★★ si en ligne | S | ✨ |
| 20.6 | **Mise à jour simplifiée** | Script `deploy` (sauvegarde → mise à jour des fichiers → migrations → vidage des caches → vérification) et page « À propos » avec numéro de version et journal des changements | ★★ | S | ✨ |
| 20.7 | **Journal des erreurs** | Page Paramètres → Diagnostic : dernières erreurs, espace disque, date de dernière sauvegarde, versions PHP / base | ★ | S | ✨ |
| 20.8 | **Données personnelles** | Export complet (JSON + photos) et suppression de compte, utiles si l'application est hébergée | ★ | S | ✨ |

---

## Module 21 — Réceptions

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 21.1 ✅ | **Menu de réception** | Pour un repas avec invités : entrée, plat, dessert, apéritif (plusieurs recettes dans une même case), portions par plat | ★ | M | 📌 (8.7) |
| 21.2 ✅ | **Rétroplanning** | À partir de l'heure du repas : quoi faire J-2, J-1, le matin, 1 h avant (d'après temps de repos, cuisson, préparation anticipée R21) | ★ | M | 📌 |
| 21.3 ✅ | **Impression / partage du menu** | Carte de menu à imprimer ou envoyer aux invités | ★ | S | 📌 |
| 21.4 ✅ | **Souvenirs** | Photo et note du repas, visibles sur la fiche invité (« dîner du 12 octobre ») | ★ | S | ✨ |

---

## 4. Règles de gestion

### R13 — Import d'une recette depuis une URL

1. Téléchargement de la page côté serveur (délai maximum 10 s, taille maximum 5 Mo, redirections limitées, adresses du réseau local refusées pour éviter qu'une URL fasse interroger des machines de la maison).
2. Recherche d'un objet `Recipe` dans les blocs JSON-LD (y compris dans un `@graph`), sinon dans les microdonnées ; aucune donnée structurée → message « Recette non reconnue : utilisez le collage de texte » (13.2).
3. Correspondances :

| schema.org | Bouffe |
|---|---|
| `name` | titre |
| `recipeYield` (« 6 », « 6 personnes », [6, « 6 parts »]) | portions (premier nombre ; 4 par défaut) |
| `prepTime`, `cookTime`, `totalTime` (durées ISO 8601, ex. `PT1H15M`) | préparation, cuisson ; repos = total − préparation − cuisson si positif |
| `recipeIngredient[]` | lignes analysées par R14 |
| `recipeInstructions` (texte, `HowToStep`, `HowToSection`) | étapes ; une section devient un titre de groupe |
| `image` (texte, liste ou `ImageObject`) | photo (plus grande image, redimensionnée comme au lot 2) |
| `recipeCategory`, `recipeCuisine`, `keywords` | catégories proposées si elles existent déjà |
| `url` ou adresse saisie | source |

4. Rien n'est enregistré sans passer par l'écran de relecture.

> **Réalisé au lot 13** ([détail](lots/lot-13-carnet.md)). La photo n'est téléchargée qu'à l'enregistrement, et seulement si la case est cochée ; les sections d'étapes (`HowToSection`) sont conservées en base dans la nouvelle colonne `recipe_steps.group_name`.

### R14 — Analyse d'une ligne d'ingrédient

Entrée : texte libre (« 1,5 kg de pommes de terre à chair ferme, épluchées »). Sortie : quantité, unité, ingrédient, préparation, facultatif, **indice de confiance**.

1. Nettoyage : puces, fractions Unicode (½ → 1/2), plages « 2 à 3 » → borne haute, « une » / « un » → 1.
2. Quantité en tête (réutilise `QuantityParser`, y compris « 1 500 »).
3. Unité : codes, libellés, pluriels et abréviations des unités (« c. à s. », « cs », « cuillère à soupe »), puis « de » / « d' » facultatif.
4. Préparation : ce qui suit la première virgule ou une parenthèse (« épluchées », « (environ 3) »).
5. Facultatif : « facultatif », « optionnel », « selon le goût », « pour servir ».
6. Ingrédient : recherche exacte (nom, pluriel, alias), puis en retirant les adjectifs connus (« frais », « bio », « rose de Lautrec »), puis similarité (distance de Levenshtein normalisée ≥ 0,85) → confiance **haute / moyenne / aucune**.
7. Unité incompatible avec l'ingrédient (« 2 boîtes » de tomates sans conversion) : unité conservée, ligne signalée « ? ».

Jeu de tests : 200 lignes réelles issues de sites de recettes français, avec un objectif de 90 % d'ingrédients reconnus avec confiance haute ou moyenne.

> **Réalisé au lot 13** ([détail](lots/lot-13-carnet.md)) avec deux écarts : la ressemblance est mesurée par `similar_text` (≥ 88 %) plutôt que par une distance de Levenshtein normalisée, et le jeu de tests compte 15 lignes réelles pour l'instant — il s'allongera avec les vraies recettes importées. Ajout non prévu : les préparations en fin de ligne (« parmesan râpé », « poivre du moulin ») sont détachées du nom de l'ingrédient.

### R15 — Remplissage automatique d'une semaine

Pour chaque case vide non verrouillée, dans l'ordre des jours :

- **Exclusions** : recettes archivées ; déjà présente dans la semaine ; mangée dans les 14 derniers jours ; incompatible (danger) avec un convive ou un membre du foyer ; temps total supérieur à la limite du créneau (14.4).
- **Score** :
  - `+ 30` si c'est une envie (14.5), puis envies retirées une fois placées ;
  - `+ jours depuis la dernière fois` (plafonné à 60) — « ça fait longtemps » ;
  - `+ 0,4 × score R10` si « utiliser le stock » est coché (le stock réservé par les cases déjà remplies est déduit au fur et à mesure) ;
  - `+ 10` favorite, `+ 5` note moyenne ≥ 4, `+ 5` de saison (R18) ;
  - `− 15` si la même catégorie principale est déjà la veille ;
  - `− 25` si une règle de la semaine serait dépassée (ex. 2e poisson).
- **Tirage** : pondéré parmi les 5 meilleurs scores, pour éviter de toujours proposer la même semaine ; 🎲 relance une case en excluant la proposition précédente.
- **Portions** : convives du créneau (R7). Restes : si une recette laisse ≥ 2 portions, proposition de placer les restes dans une case vide du lendemain (au lieu d'une recette).

> **Réalisé au lot 14** ([détail](lots/lot-14-planning.md)). Deux précisions : une recette prévue pour au moins deux convives de plus est cuisinée en entier (c'est ce qui crée les restes), et un bonus `+15` privilégie une catégorie dont le minimum de la semaine n'est pas atteint. Le bonus « de saison » attend le lot 19.

### R16 — Application d'une semaine type

- Une semaine type enregistre, par jour de la semaine et créneau : recette, restes (« restes du lundi soir ») ou texte libre.
- Application : **remplir les vides** (défaut) ou **remplacer** (avec aperçu des repas supprimés et « Annuler »).
- Portions = convives de la case cible (pas celles du modèle) ; contraintes des invités de la semaine cible vérifiées et signalées.
- Recette archivée depuis : case laissée vide et signalée.

> **Réalisé au lot 14** ([détail](lots/lot-14-planning.md)). Les portions de la case d'arrivée sont augmentées de ce que les restes du modèle réclament, sans quoi les restes enregistrés dans le modèle ne tiendraient pas.

### R17 — Prix et coût ✅ (lot 17)

- **Prix de référence** d'un ingrédient : prix par unité de base (€/kg, €/l, €/pièce), saisi à la main ou **dernier prix saisi en magasin** ramené à l'unité de base (conversion R3) ; on garde l'historique par magasin.
- **Coût d'une recette** = Σ (quantité mise à l'échelle convertie en unité de base × prix de référence) ; produits de base comptés au prorata s'ils ont un prix, sinon ignorés.
- Ingrédients sans prix : coût affiché « ≥ 6,40 € (3 prix manquants) ».
- Coût de la semaine = Σ coûts des repas « recette » ; coût d'une liste = Σ (quantité à acheter × prix de référence).

**Réalisé au lot 17** ([détail](lots/lot-17-magasins-budget.md)). Précisions apportées à l'implémentation :
un prix **fixé à la main** n'est jamais écrasé par un relevé de caisse ; un produit de base **sans
quantité** est ignoré plutôt que compté manquant ; et le coût d'une liste retient le **prix payé**
quand il existe, l'estimation seulement à défaut.

### R18 — Saisons ✅ (lot 19)

- Chaque fruit ou légume a une liste de mois de saison (vide = toute l'année ou non concerné).
- Une recette est **de saison** ce mois-ci si tous ses fruits et légumes frais non facultatifs sont de saison ; **hors saison** si au moins un ingrédient principal (quantité la plus élevée en poids parmi les fruits et légumes) ne l'est pas.
- Seul le mois de la date du repas compte (une recette planifiée en octobre est évaluée en octobre).

### R19 — Valeurs nutritionnelles ✅ (lot 19)

- Correspondance ingrédient → code aliment Ciqual (une seule par ingrédient, modifiable).
- Quantité convertie en grammes : unités de masse ; volume × densité (1 par défaut, modifiable par ingrédient) ; pièces × poids d'une pièce (déjà présent depuis le lot 1) ; sinon ligne non comptée.
- Valeurs par portion = Σ (grammes × valeur pour 100 g / 100) / portions de la recette.
- **Couverture** = part du poids total des ingrédients comptés ; en dessous de 70 %, les valeurs sont masquées (« données insuffisantes »).
- Toujours présenté comme indicatif ; aucune recommandation médicale.

**Réalisé au lot 19** ([détail](lots/lot-19-saisons-nutrition.md)). Précisions apportées à
l'implémentation : une seule ligne impossible à peser suffit à masquer les valeurs (et pas
seulement une couverture faible) ; l'import est tolérant aux intitulés de colonnes, qui changent
d'une édition de la table à l'autre ; et pour R18, un ingrédient **secondaire** hors saison ne
rend pas la recette hors saison — seul l'ingrédient principal compte.

### R20 — Liste de courses hors-ligne ✅ (lot 16)

- À l'ouverture en ligne, la liste en cours est copiée sur le téléphone (articles, rayons, ordre du magasin, état).
- Hors-ligne, chaque action (cocher, décocher, ajouter un article simple, modifier une quantité) est enregistrée localement avec un horodatage et un identifiant unique.
- Au retour du réseau, les actions sont rejouées dans l'ordre ; **conflit** sur un même article : la dernière action (horodatage) l'emporte ; article supprimé entre-temps sur le serveur : action ignorée et signalée ; liste régénérée entre-temps : les coches sont reportées sur les articles du même ingrédient.
- La régénération et la suppression d'une liste sont indisponibles hors-ligne.

**Réalisé au lot 16** ([détail](lots/lot-16-en-ligne-hors-ligne.md)) : table `offline_operations`,
service `OfflineSync`, page « Mode magasin » hors Livewire. Précision apportée à l'implémentation :
un geste du magasin n'écrase jamais une modification faite **plus récemment** sur un autre appareil,
et chaque geste ignoré ou reporté est expliqué en clair à l'écran.

### R21 — Préparation anticipée

Rappels calculés pour chaque repas planifié (non mangé), sans saisie :

| Déclencheur | Rappel | Quand |
|---|---|---|
| Un ingrédient du repas n'est disponible **qu'au congélateur** (stock) | « Sortir *blanc de poulet* du congélateur » | veille, 18 h (réglable) |
| Temps de repos ≥ 6 h, ou étape marquée « la veille » | « Préparer *pâte à pizza* (repos 12 h) » | heure du repas − repos − préparation |
| Étape contenant « tremper », « mariner », « la veille » (liste de mots réglable) | « *Pois chiches* : mettre à tremper » | veille |
| Repas « restes » d'un plat congelé | « Décongeler *restes de chili* » | veille |
| Réception (module 21) | Rétroplanning | selon R21 et temps de la recette |

Un rappel peut être marqué **fait** ou **ignoré** ; il disparaît si le repas est déplacé ou supprimé (recalculé).

> **Réalisé au lot 14** ([détail](lots/lot-14-planning.md)), **complété au lot 20** ([détail](lots/lot-20-rappels-receptions.md)) : ligne « réception » (rétroplanning), « Décongeler » pour un plat cuisiné à l'avance rangé au congélateur et pour les restes congelés d'un repas (le plat rangé porte désormais le repas d'origine). Faute d'heure sur les créneaux, l'heure du repas est supposée à 19 h (réglable) ; pour une réception, c'est l'heure saisie, décalée selon la place du plat dans le menu.

### R22 — Fusion d'ingrédients et alias

- Fusion A → B : toutes les références à A (lignes de recette, articles en stock, mouvements, articles de listes, articles récurrents, contraintes d'invités, prix, codes-barres) sont réaffectées à B dans une transaction ; les quantités ne sont pas modifiées.
- Réglages de stock : ceux de B sont conservés ; A est supprimé et son nom (et pluriel) devient un **alias** de B.
- Les alias sont utilisés par la recherche, l'ajout rapide, l'import (R14) et les articles manuels.
- Une sauvegarde automatique est créée avant chaque fusion ; « Annuler » est proposé tant qu'aucune autre modification n'a eu lieu.
- **Doublons probables** proposés : même nom normalisé sans pluriel, ou similarité ≥ 0,9, dans le même rayon.

---

## 5. Modèle de données (ajouts)

| Table / colonne | Contenu | Module |
|---|---|---|
| `ingredient_aliases` (id, ingredient_id, name, search_name) | Anciens noms, variantes d'import | 12.6, 13 |
| `recipes.status` (`active`, `to_test`) + `recipes.source_url` unique | Recettes à tester, doublons d'import | 13 |
| `recipe_steps.timer_minutes`, `recipe_steps.day_before` | Minuteur détecté / modifiable, préparation la veille | 13.5, R21 |
| `recipe_cook_notes` (recipe_id, user_id, planned_meal_id, note, created_at) | Notes de cuisine datées | 13.6 |
| `recipe_variants` + `recipe_variant_swaps` (ligne remplacée → ingrédient, quantité) | Variantes | 13.7 |
| `recipe_ingredients.sub_recipe_id` | Sous-recette | 13.8 |
| `collections` + `collection_recipe` | Collections | 13.11 |
| `recipe_photos` (recipe_id, path, position) | Photos multiples | 13.12 |
| `week_templates` (name) + `week_template_meals` (weekday, meal_slot_id, type, recipe_id, free_text, leftover_ref) | Semaines types | 14.3 |
| `planning_rules` (dans `settings`) | Temps max par créneau et jours, quotas par catégorie | 14.4 |
| `wishes` (id, user_id, recipe_id NULL, text NULL, planned_meal_id NULL, created_at) | Envies | 14.5 |
| `reminders` (planned_meal_id, type, due_at, status, payload) | Rappels calculés et leur état | 14.6, R21 |
| `planned_meals.cook_user_id` | Qui cuisine | 14.7 |
| `planned_meals.menu_group` (ou `meal_courses`) | Plusieurs plats dans une case | 21.1 |
| `stores` (name, sort_order) + `store_aisles` (store_id, aisle_id, position, hidden) | Magasins et ordre des rayons | 15.3 |
| `shopping_lists.store_id` | Magasin de la liste | 15.3 |
| `ingredient_prices` (ingredient_id, store_id NULL, price, unit_id, quantity, observed_on, source) | Historique des prix | 15.7, R17 |
| `ingredients.reference_price`, `ingredients.reference_price_unit_id` | Prix de référence | R17 |
| `offline_operations` (uuid, user_id, list_item_id, action, value, happened_at, applied_at) | Actions rejouées (anti-doublon par uuid) | R20 |
| `products` (barcode, ingredient_id, label, brand, quantity, unit_id, off_payload JSON, last_seen_at) | Produits scannés | 16.1 |
| `ingredients.season_months` (JSON, ex. `[6,7,8,9]`) | Saisons | 17.1 |
| `nutrition_foods` (ciqual_code, name, energy_kcal, proteins, carbs, sugars, fat, saturated_fat, fibres, salt) | Extrait Ciqual | 17.4 |
| `ingredients.ciqual_code`, `ingredients.density` | Correspondance nutrition | R19 |
| `household_restrictions` (user_id, type, ingredient_id / tag_id, note) — ou table commune avec `guest_restrictions` | Préférences du foyer | 18.1 |
| `meal_reactions` (planned_meal_id, user_id, value) | 👍 / 👎 | 18.4 |
| `notifications` (table standard Laravel) + `push_subscriptions` | Centre de notifications, Web Push | 19 |
| `users.role`, `users.two_factor_secret`, `users.preferences` (JSON : thème, taille du texte, raccourcis) | Rôles, 2FA, préférences d'affichage | 12, 18.5, 20.5 |
| `activity_log` (user_id, action, subject_type, subject_id, created_at) | Journal d'activité | 12.10 |

Règles d'intégrité (complètent R6 et R12) : une recette utilisée comme sous-recette ne peut pas être supprimée (archivage) ; une sous-recette ne peut pas s'utiliser elle-même, même indirectement ; supprimer un magasin rattache ses listes à « sans magasin ».

---

## 6. Écrans et routes prévus

| URL | Écran | Lot |
|---|---|---|
| *(fenêtre)* `Ctrl+K` | Recherche globale | 11 |
| `/` | Accueil « Aujourd'hui » | 11 |
| `/parametres/ingredients/doublons` | Doublons et fusion | 11 |
| `/recettes/{recette}/cuisiner` | Mode cuisine | 12 |
| `/recettes/{recette}/imprimer` | Impression d'une fiche | 12 |
| `/recettes/importer` | Import URL / collage de texte + relecture | 13 |
| `/recettes/export`, `/recettes/import-json` | Export / import JSON | 13 |
| *(fenêtre)* planning → **Remplir la semaine** | Aperçu du remplissage | 14 |
| `/planning/modeles` | Semaines types | 14 |
| `/planning/imprimer?semaine=` | Menu à imprimer | 14 |
| `/envies` *(panneau latéral du planning sur ordinateur)* | Envies | 14 |
| `/parametres/foyer` | Membres, préférences, rôles | 15 |
| `/courses/{liste}/magasin` | Mode magasin (hors-ligne) | 16 |
| `/parametres/securite`, `/parametres/diagnostic` | Sessions, 2FA, diagnostic | 16 |
| `/parametres/magasins` | Magasins et ordre des rayons | 17 |
| `/budget` | Coûts et dépenses | 17 |
| `/stock/scanner` | Scan de code-barres | 18 |
| `/stock/historique`, `/stock/statistiques` | Historique et anti-gaspillage | 18 |
| `/parametres/nutrition` | Correspondances Ciqual | 19 |
| `/notifications` | Centre de notifications | 14 |
| `/receptions/{repas}` | Menu et rétroplanning d'une réception | 20 |

Services envisagés : `GlobalSearch`, `IngredientMerger` (R22), `RecipeImporter` (R13) et `IngredientLineParser` (R14), `CookModeTimerExtractor`, `WeekFiller` (R15), `WeekTemplateApplier` (R16), `PrepReminderPlanner` (R21), `PriceBook` (R17), `SeasonCalendar` (R18), `NutritionCalculator` (R19), `OfflineSyncService` (R20), `ProductLookup` (Open Food Facts), `WasteStatistics`.

---

## 7. Choix techniques

### 7.1 Hébergement et HTTPS : la décision structurante

Plusieurs fonctionnalités exigent un **contexte sécurisé (HTTPS)** : écran maintenu allumé (13.4, 15.4), caméra pour le scan (16.1), service worker, hors-ligne et installation (15.1, 20.2), notifications push (19.2). Sur le Wi-Fi de la maison en `http://192.168…`, le navigateur les refuse.

| Option | Principe | Avantages | Inconvénients | Coût indicatif |
|---|---|---|---|---|
| **A — Rester en local** | Wamp comme aujourd'hui | Rien à changer, données à la maison | Pas de 🔒 ; secours : copie de liste HTML (15.2), impression | 0 € |
| **B — Tunnel privé** (ex. Tailscale) | Le PC reste le serveur ; les téléphones de Pierre et Monique y accèdent via un réseau privé chiffré, avec un certificat HTTPS automatique (nom en `.ts.net`) | HTTPS réel, accès hors de la maison, données à la maison, pas de migration | PC allumé nécessaire, application à installer sur chaque téléphone ; le nom de la machine apparaît dans des journaux publics de certificats | Offre personnelle gratuite (conditions à vérifier au lot 16) |
| **C — Hébergement mutualisé OVH** (offre Pro ou supérieure, avec SSH) | Application et base chez OVH, certificat Let's Encrypt | Toujours disponible, sauvegardes de l'hébergeur, cron | Migration, sécurité à renforcer (20.5), photos et données en ligne ; offre Perso sans SSH peu adaptée à Laravel | ~10 €/mois hors promotion (renouvellement de l'offre Pro, 2026) |
| **D — VPS** | Serveur virtuel (OVH ou autre) administré soi-même | Maîtrise totale, Docker possible | Administration système (mises à jour, pare-feu, sauvegardes) | Quelques €/mois |

**Proposition** : B pour démarrer la V2 (aucune migration, débloque tous les 🔒), C si l'application doit rester disponible PC éteint. L'application est déjà prête pour les deux : configuration par `.env`, sauvegarde / restauration, pas de dépendance à Windows.

### 7.2 Hors-ligne

- Livewire a besoin du serveur à chaque interaction : la page **mode magasin** (15.1) sera donc une page à part, en JavaScript léger (Alpine.js déjà présent), qui lit la liste en JSON, la garde dans IndexedDB et envoie les actions à une petite API (`/api/listes/{id}/operations`) protégée par la session.
- Sur iPhone, les données locales d'une application web peuvent être effacées si elle n'est pas ouverte pendant plusieurs jours et la synchronisation en arrière-plan n'existe pas : la liste est donc rechargée à chaque ouverture en ligne, et les actions en attente sont envoyées dès que la page est au premier plan avec du réseau.

### 7.3 Services externes

| Service | Usage | Conditions | Si indisponible |
|---|---|---|---|
| Sites de recettes | Import (13.1) | Lecture de pages publiques pour usage personnel ; respect d'un délai entre requêtes | Collage de texte |
| Open Food Facts | Scan (16.1) | Gratuit, licence ODbL, 15 lectures de produit par minute par IP, `User-Agent` obligatoire | Saisie manuelle ; produits déjà scannés reconnus localement |
| Ciqual 2025 (Anses) | Nutrition (17.4) | Données publiques téléchargées une fois | — (import local) |
| Web Push (navigateurs) | Notifications (19.2) | Clés VAPID ; iPhone : application ajoutée à l'écran d'accueil, iOS 16.4+ | Centre de notifications dans l'application |
| Serveur d'e-mails | Récapitulatif (19.3) | SMTP de l'hébergeur ou d'un fournisseur | Désactivé |

### 7.4 Qualité

- Nouveaux jeux de tests : lignes d'ingrédients réelles (R14), pages de recettes enregistrées localement (R13, sans appel réseau dans les tests), scénarios hors-ligne (R20) testés avec Playwright en mode avion simulé.
- **Git** recommandé avant la V2 (toujours pas utilisé aujourd'hui) : branches par lot, retour arrière facile si une migration pose problème — surtout si l'application est déployée ailleurs.

---

## 8. Découpage en lots

| Lot | Contenu | Livrable | Taille | Dépend de |
|---|---|---|---|---|
| **11 — Confort au quotidien** ✅ ([détail](lots/lot-11-confort.md)) | 12.1 recherche globale, 12.2 bouton +, 12.3 accueil « Aujourd'hui », 12.4 mode sombre, 12.6 fusion d'ingrédients et alias (R22), 15.5 fusion des articles, 12.7 barre du bas | L'application se parcourt en quelques gestes | M | — |
| **12 — Cuisiner avec Bouffe** ✅ ([détail](lots/lot-12-cuisiner.md)) | 13.4 mode cuisine, 13.5 minuteurs, 13.6 notes de cuisine, 13.9 impression d'une fiche, 14.11 impression du menu | On cuisine avec le téléphone posé sur le plan de travail | M | — (écran allumé complet après 16) |
| **13 — Remplir le carnet** ✅ ([détail](lots/lot-13-carnet.md)) | 13.1 import URL (R13), 13.2 collage (R14), 13.3 saisie rapide, 13.13 à tester, 13.10 export / import JSON, sections d'étapes | Une recette trouvée en ligne est au carnet en 1 minute | L | 11 (alias) |
| **14 — Planning intelligent** ✅ ([détail](lots/lot-14-planning.md)) | 14.1 et 14.2 remplir la semaine (R15), 14.3 semaines types (R16), 14.4 règles, 14.5 envies, 14.6 préparation anticipée (R21) + 19.1 centre de notifications, 14.9 vue liste | Une semaine planifiée en une minute, sans oublier de décongeler | L | 11 |
| **15 — Foyer** ✅ ([détail](lots/lot-15-foyer.md)) | 18.1 préférences, 14.7 / 18.3 qui cuisine, 18.2 envies partagées, 18.4 réactions, 18.5 membres et rôles | Planning à deux, préférences respectées | S | 14 |
| **16 — En ligne et hors-ligne** ✅ ([détail](lots/lot-16-en-ligne-hors-ligne.md)) | 20.1 accès sécurisé (option B — tunnel Tailscale, voir [09-acces-distant.md](09-acces-distant.md)), 20.2 PWA, 15.1 / 20.3 liste hors-ligne (R20), 15.4 mode magasin, 20.4 sauvegarde externe, 20.5 sécurité, 20.6 mise à jour, 20.7 diagnostic, 20.8 export des données | La liste de courses fonctionne en magasin, même sans réseau | L | 15 |
| **17 — Magasins et budget** ✅ ([détail](lots/lot-17-magasins-budget.md)) | 15.3 magasins et ordre des rayons, 15.6 articles fréquents, 15.7 prix, 17.2 coût des recettes (R17), 17.3 budget, 15.8 liste « quand je passe » | Liste dans l'ordre du magasin, coût de la semaine connu | M | 16 |
| **18 — Stock sans saisie** ✅ ([détail](lots/lot-18-stock-sans-saisie.md)) | 16.1 scan (Open Food Facts), 16.2 rangement assisté, 16.3 statistiques anti-gaspillage, 16.4 congélateur, 16.5 étiquettes | Le stock se remplit en scannant | L | 17 |
| **19 — Saisons et nutrition** ✅ ([détail](lots/lot-19-saisons-nutrition.md)) | 17.1 saisons (R18) + bonus dans le remplissage, 17.4 nutrition Ciqual (R19), 17.5 équilibre, 13.7 variantes | Manger de saison et équilibré sans calcul | M | 18 |
| **20 — Rappels et réceptions** ✅ ([détail](lots/lot-20-rappels-receptions.md)) | 19.2 notifications push, 19.3 récapitulatif e-mail, 19.4 tâche planifiée, module 21 réceptions, 14.8 batch cooking, 13.8 sous-recettes | Être prévenu au bon moment ; recevoir sereinement | L | 14, 16 |

Taille : S ≈ lot 4 divisé par deux, M ≈ lot 3, L ≈ lot 4 (comme dans le doc 05).

Petites améliorations sans lot attitré (12.5 annuler partout, 12.8 aide contextuelle, 12.9 taille du texte, 12.10 journal d'activité, 14.10 export ICS, 14.12 statistiques, 15.9 partage, 16.6 à 16.8, 13.11 et 13.12) : glissées dans les lots 11 à 20 quand elles touchent les mêmes écrans.

**Ordre proposé** : 11 → 12 → 13 → 14 → 16 → 15 → 17 → 18 → 19 → 20. Les lots 11 à 14 répondent aux frictions F1, F3, F4, F6, F7 et F8 sans aucun prérequis d'hébergement ; le lot 16 est avancé avant 15 dès que la question Q16 est tranchée, car il débloque le magasin, le scan et les notifications.

Idées **écartées** pour l'instant (coût élevé pour deux utilisateurs) : lecture des tickets de caisse par photo (OCR peu fiable sur les tickets luxembourgeois et français), commande directe chez un drive (pas d'API publique), application native iOS / Android, mode multi-foyers.

---

## 9. Questions ouvertes

Comme pour les documents 04 et 05 : réponds directement dans la colonne de droite, les propositions par défaut seront appliquées sinon.

| # | Question | Proposition par défaut |
|---|---|---|
| Q16 | Accès hors de la maison et HTTPS : rester en local (A), tunnel privé (B), OVH mutualisé (C) ou VPS (D) ? (§7.1) | B pour commencer, C plus tard si besoin |
| Q17 | L'ordre des lots te convient-il, ou une fonctionnalité doit-elle passer devant (ex. hors-ligne en magasin, import de recettes) ? | 11 → 12 → 13 → 14 → 16 → 15 → 17 → 18 → 19 → 20 |
| Q18 | Sites de recettes utilisés le plus souvent (pour constituer le jeu de tests de l'import) ? | Marmiton, 750g, Cuisine AZ, Journal des Femmes, Ricardo, blogs WordPress |
| Q19 | Remplissage automatique : laisser les midis vides en semaine (travail) ? | Oui, midis du lundi au vendredi non remplis |
| Q20 | Règles de la semaine par défaut : temps max du soir en semaine, poisson, végétarien ? | 45 min du lundi au jeudi ; au moins 1 poisson et 2 végétariens, simples indications |
| Q21 | Heure des rappels « à préparer la veille » ? | 18 h |
| Q22 | Magasins habituels (ordre des rayons à préparer) ? | Un seul magasin « Supermarché » créé, les autres à ajouter |
| Q23 | Saisie des prix : en cochant l'article en magasin, ou seulement au rangement des courses ? | Au rangement (moins de gestes en magasin) |
| Q24 | Valeurs nutritionnelles : utiles, ou à laisser de côté ? | Lot 19, affichage discret et désactivable |
| Q25 | Notifications push : lesquelles t'intéressent (préparation la veille, péremption, envies, liste prête) ? | Préparation la veille et péremption uniquement |
| Q26 | Autres comptes à prévoir (enfants, famille) ? | Non, rôles prévus mais pas d'autre compte |
| Q27 | Commencer à utiliser Git avant la V2 ? | Oui, dépôt local (sans hébergement du code) |

---

## Sources

- Open Food Facts — [documentation de l'API](https://openfoodfacts.github.io/openfoodfacts-server/api/) (limites de requêtes, `User-Agent`, licence).
- Anses — [nouvelle version de la table Ciqual](https://www.anses.fr/en/content/new-enhanced-and-more-representative-version-ciqual-table) (novembre 2025) ; [Ciqual 2020 sur data.gouv.fr](https://www.data.gouv.fr/datasets/table-de-composition-nutritionnelle-des-aliments-ciqual-2020) (formats, remplacée par la version sur recherche.data.gouv.fr).
- MDN — [Screen Wake Lock API](https://developer.mozilla.org/en-US/docs/Web/API/Screen_Wake_Lock_API) (contexte sécurisé) ; [BarcodeDetector](https://developer.mozilla.org/en-US/docs/Web/API/BarcodeDetector) et [caniuse](https://caniuse.com/mdn-api_barcodedetector) (absent de Safari).
- Web Push sur iPhone — [MagicBell, limites des PWA sur iOS](https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide) ; maintien des applications web d'écran d'accueil dans l'UE : [TechCrunch, mars 2024](https://techcrunch.com/2024/03/01/apple-reverses-decision-about-blocking-web-apps-on-iphones-in-the-eu/).
- Tailscale — [certificats HTTPS](https://tailscale.com/kb/1153/enabling-https), [Tailscale Serve](https://tailscale.com/docs/features/tailscale-serve).
- OVHcloud — [comparatif Perso / Pro 2026](https://ecohebergeur.com/guides/ovh-hebergement-perso-vs-pro/) (SSH réservé à Pro et au-delà, prix de renouvellement).
