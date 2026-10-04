# 02 — Fonctionnalités détaillées

Priorités : **[MVP]** = première version utilisable · **[V2]** = amélioration ultérieure.

---

## Module 0 — Accès & foyer

| # | Fonctionnalité | Priorité |
|---|---|---|
| 0.1 | Deux comptes (Pierre, compagne) avec connexion simple et « rester connecté » longue durée | MVP |
| 0.2 | Toutes les données (recettes, planning, listes) sont **partagées** entre les deux comptes | MVP |
| 0.3 | Traçabilité légère : « ajouté par », « modifié par » sur recettes et repas | MVP |
| 0.4 | Préférences par personne : aliments non aimés / à éviter (alerte lors de la planification) | V2 |

> Comme l'application est locale, la connexion peut être désactivée par un paramètre (`BOUFFE_AUTH=false`) : un utilisateur par défaut est alors utilisé.

---

## Module 1 — Référentiels (ingrédients, unités, rayons)

### 1.1 Unités de mesure [MVP]

- Liste pré-remplie : `g`, `kg`, `ml`, `cl`, `l`, `c. à café`, `c. à soupe`, `pièce`, `tranche`, `gousse`, `botte`, `pincée`, `sachet`, `boîte`, `pot`.
- Chaque unité a un **type** : `masse`, `volume`, `pièce`, `autre`.
- Les unités de masse et de volume ont un **facteur vers l'unité de base** (g ou ml) : `kg = 1000`, `cl = 10`, `c. à soupe = 15`, `c. à café = 5`.
- Deux unités du même type sont **convertibles automatiquement** entre elles.

### 1.2 Rayons [MVP]

- Liste pré-remplie : Fruits & légumes, Boucherie / Volaille, Poissonnerie, Crèmerie / Produits laitiers, Fromages, Boulangerie, Épicerie salée, Épicerie sucrée, Conserves, Surgelés, Boissons, Condiments & épices, Hygiène / Maison, Divers.
- **Ordre personnalisable par glisser-déposer** pour coller au parcours du magasin habituel → la liste de courses suit cet ordre.

### 1.3 Ingrédients [MVP]

- Champs : nom, nom au pluriel (optionnel), rayon, unité par défaut.
- **Poids moyen d'une pièce** (optionnel) : ex. 1 oignon ≈ 150 g → permet d'additionner « 1 oignon » et « 200 g d'oignon ».
- Indicateur **« produit de base »** (sel, poivre, huile, farine…) : exclu par défaut de la liste de courses, mais proposé dans une section « À vérifier dans le placard ».
- Recherche instantanée, **création à la volée** depuis l'écran de saisie d'une recette (pas besoin de quitter la recette).
- Détection de doublons à la création (« Tomate » vs « tomates »).
- Fusion de deux ingrédients en doublon (les recettes sont réaffectées). [V2]
- Mois de saison (fruits & légumes) → badge « de saison » sur les recettes. [V2]
- Prix indicatif au kg / à la pièce → estimation du coût de la semaine. [V2]

---

## Module 2 — Recettes

### 2.1 Fiche recette [MVP]

| Champ | Détail |
|---|---|
| Titre | obligatoire, unique |
| Description courte | texte libre |
| Photo | upload JPG/PNG/WebP, redimensionnée automatiquement (vignette + grande taille) |
| Nombre de portions de base | obligatoire, défaut 2 |
| Temps de préparation / cuisson / repos | en minutes, total calculé |
| Difficulté | facile / moyen / difficile |
| Catégories (tags) | ex. Plat, Entrée, Dessert, Petit-déjeuner, Végétarien, Rapide, Batch cooking, Été, Hiver… (tags libres gérables) |
| Ingrédients | voir 2.2 |
| Étapes | liste ordonnée (réordonnable par glisser-déposer) |
| Source | URL ou texte libre (« livre de mamie ») |
| Notes personnelles | texte libre |
| Favori | ♥ |

### 2.2 Ingrédients d'une recette [MVP]

- Lignes dynamiques : **quantité** (optionnelle, ex. « sel : à convenance »), **unité**, **ingrédient** (autocomplétion), **précision** (« émincé », « à température ambiante »), **optionnel** (case à cocher).
- **Groupes d'ingrédients** optionnels : « Pâte », « Garniture », « Sauce ».
- Réordonnancement par glisser-déposer.
- Saisie de fractions acceptée (`1/2`, `1,5`, `0.5`).

### 2.3 Consultation [MVP]

- Liste des recettes en **cartes** (photo, titre, temps total, tags, favori).
- **Recherche** plein texte (titre, ingrédient) et **filtres** : tags, temps max, favoris, difficulté, « contient l'ingrédient X ».
- Tri : alphabétique, récemment ajoutées, **moins cuisinées récemment**, les mieux notées.
- Vue détail avec **ajusteur de portions** : les quantités sont recalculées en direct (arrondis intelligents, voir règles R3).
- **Mode cuisine** : grand affichage, étapes cochables, écran maintenu allumé (Wake Lock API). [V2]
- Statistiques : nombre de fois planifiée, dernière date de réalisation. [MVP]

### 2.4 Actions [MVP]

- Créer, modifier, **dupliquer** (variante), archiver (masquée des suggestions mais conservée dans l'historique), supprimer (uniquement si jamais planifiée, sinon archivage).
- « Ajouter au planning » directement depuis la fiche.
- **Notes par personne** (1 à 5 ★ + commentaire) : Pierre et sa compagne notent séparément. [MVP]
- **Import depuis une URL** (lecture des données structurées `schema.org/Recipe` présentes sur la plupart des sites de cuisine : Marmiton, 750g, etc.) avec écran de relecture avant enregistrement. [V2]
- Impression d'une fiche recette. [V2]
- Export / import JSON des recettes (sauvegarde, partage). [V2]

---

## Module 3 — Planning de la semaine

### 3.1 Vue hebdomadaire [MVP]

- Grille **7 jours × créneaux**. Créneaux par défaut : **Déjeuner**, **Dîner** (Petit-déjeuner et Goûter activables dans les paramètres).
- Premier jour de la semaine : **lundi**. Navigation semaine précédente / suivante / « aujourd'hui ».
- Sur téléphone : affichage en liste jour par jour au lieu de la grille.
- Le jour courant est mis en évidence.

### 3.2 Contenu d'une case (repas planifié) [MVP]

Un créneau peut contenir **un ou plusieurs éléments** (ex. plat + dessert) :

| Type | Exemple | Impact liste de courses |
|---|---|---|
| **Recette** | Lasagnes, 2 portions | Ingrédients ajoutés × (portions / portions de base) |
| **Restes** | « Restes de : Lasagnes (lundi soir) » | Aucun (déjà compté) |
| **Texte libre** | « Resto », « Chez les parents », « Pizza surgelée » | Aucun, ou un article libre si demandé |

- Nombre de **portions par élément** (défaut : 2, modifiable ; ex. 4 si on prévoit des restes).
- **Assistant restes** : quand une recette est planifiée avec plus de portions que de convives, proposer de placer automatiquement les restes sur un créneau suivant.
- Commentaire libre (« inviter Julie »).
- Case « cuisiné ✓ » pour alimenter l'historique.

### 3.3 Interactions [MVP]

- **Ajouter** : clic sur une case → modale de recherche de recette (mêmes filtres que le module 2) ou saisie libre.
- **Déplacer / échanger** deux repas par **glisser-déposer**.
- **Dupliquer** un repas sur une autre case.
- **Copier une semaine** entière vers une autre semaine.
- **Vider** une semaine.

### 3.4 Aide à la planification

- **Suggestions** : proposer des recettes non cuisinées depuis N semaines, filtrées par tags (ex. « rapide » en semaine). [MVP]
- Bouton **« Remplir au hasard »** les cases vides en respectant : pas deux fois la même recette dans la semaine, pas cuisinée dans les 14 derniers jours, recettes archivées exclues. [V2]
- **Semaines types** (modèles réutilisables). [V2]
- Alertes d'équilibre (trop de viande rouge, pas de légumes…) basées sur les tags. [V2]

### 3.5 Historique [MVP]

- Consultation des semaines passées (lecture seule par défaut, modifiables).
- « Qu'a-t-on mangé il y a un mois ? » ; fréquence de chaque recette.

---

## Module 4 — Liste de courses

### 4.1 Génération [MVP]

- Bouton **« Générer la liste de courses »** depuis le planning.
- Période choisie : par défaut la semaine affichée ; possible de choisir une plage de dates (ex. du samedi au vendredi suivant) ou de **cocher les repas à inclure**.
- Algorithme : voir règles R1 à R5.
- La liste est **enregistrée en base** (instantané) : on peut la modifier, la cocher, la consulter plus tard sans que les modifications du planning ne la changent silencieusement.

### 4.2 Affichage et utilisation [MVP]

- Articles **regroupés par rayon**, dans l'ordre du magasin (module 1.2).
- Chaque ligne : case à cocher, quantité + unité formatées (`1,2 kg`, `3 pièces`), nom, et au survol / clic la **provenance** (« Lasagnes lun. 400 g + Chili jeu. 300 g »).
- Section **« À vérifier dans le placard »** : produits de base nécessaires cette semaine.
- **Articles manuels** (lessive, papier toilette, café…) avec rayon, ajout rapide.
- **Articles récurrents** : liste d'articles ajoutés automatiquement à chaque nouvelle liste (lait, pain…). [MVP]
- Modifier la quantité ou **retirer** un article (« j'en ai déjà »).
- Cocher / décocher, articles cochés grisés et descendus en bas du rayon ; masquer les cochés.
- **Interface mobile** optimisée : grosses cases à cocher, utilisable d'une main en magasin (accès via le réseau local).
- **Rafraîchissement automatique** toutes les quelques secondes (polling Livewire) : si l'un coche un article, l'autre le voit.
- Impression / export PDF propre (feuille de style d'impression, sans librairie). [MVP]
- Copier la liste en texte brut (pour l'envoyer par message). [MVP]

### 4.3 Régénération [MVP]

Si le planning change après la génération : bouton **« Mettre à jour depuis le planning »** qui :

- recalcule les articles issus des recettes ;
- **conserve** les articles manuels, les articles retirés volontairement et l'état « coché » des articles dont la quantité n'a pas augmenté ;
- signale les différences (« +200 g de tomates »).

### 4.4 Historique [MVP]

- Listes précédentes consultables ; statut `en cours` / `terminée` ; archivage automatique après 30 jours.

---

## Module 5 — Tableau de bord [MVP]

- **Aujourd'hui** : repas du déjeuner et du dîner, lien direct vers la recette (et le mode cuisine en V2).
- **Cette semaine** : aperçu du planning, nombre de cases vides.
- **Liste de courses en cours** : progression (12 / 34 articles cochés).
- Raccourcis : nouvelle recette, planifier, générer la liste.
- Suggestions « ça fait longtemps qu'on n'a pas mangé… ».

---

## Module 6 — Paramètres & maintenance

| # | Fonctionnalité | Priorité |
|---|---|---|
| 6.1 | Gestion des créneaux (nom, ordre, actif) | MVP |
| 6.2 | Portions par défaut d'un repas (2) | MVP |
| 6.3 | Gestion des unités, rayons (ordre), tags | MVP |
| 6.4 | Articles récurrents de la liste de courses | MVP |
| 6.5 | **Sauvegarde** : commande `php artisan bouffe:backup` (dump SQL + photos zippés dans `storage/backups`) + bouton dans l'interface | MVP |
| 6.6 | Restauration depuis une sauvegarde | V2 |
| 6.7 | Données de démarrage (seeders) : unités, rayons, ~150 ingrédients courants, une dizaine de recettes exemples | MVP |

---

## Module 7 — Idées pour plus tard [V2+]

> Les fonctionnalités marquées **V2** dans ce document sont reprises, complétées et découpées en lots dans [06-version-2.md](06-version-2.md).

- **Garde-manger / stock**, **convives & invités**, **suggestions depuis le stock**, **péremption** : spécifiés dans [05-evolutions-convives-stock.md](05-evolutions-convives-stock.md) (modules 8 à 11, règles R7 à R12).
- **Valeurs nutritionnelles** approximatives (base Ciqual de l'ANSES).
- **Coût estimé** de la semaine.
- **PWA** (installable sur l'écran d'accueil du téléphone, liste consultable hors-ligne).
- Mode sombre.

---

## Règles de gestion

### R1 — Mise à l'échelle

`quantité_nécessaire = quantité_recette × (portions_planifiées / portions_de_base_recette)`

Les lignes **optionnelles** sont incluses dans la liste mais marquées « (optionnel) », décochables à la génération.

### R2 — Agrégation

Pour chaque ingrédient, sur l'ensemble des repas retenus :

1. Regrouper les quantités **par type d'unité** (masse, volume, pièce, autre).
2. Convertir masse → g et volume → ml puis additionner.
3. Si l'ingrédient a un **poids moyen par pièce**, convertir les pièces en grammes et fusionner avec la masse (affichage final dans l'unité par défaut de l'ingrédient).
4. Les unités non convertibles (`boîte`, `sachet`, `botte`…) sont additionnées entre elles uniquement si identiques.
5. Si un même ingrédient reste en plusieurs unités incompatibles, il apparaît sur **une ligne** avec les quantités juxtaposées : `Tomates : 3 pièces + 1 boîte`.
6. Les lignes sans quantité (« sel : à convenance ») donnent un article sans quantité.

### R3 — Formatage et arrondis

- g ≥ 1000 → affichage en kg ; ml ≥ 1000 → en l (et cl pour 100–999 ml si plus lisible).
- Arrondis d'affichage : g → au 5 g supérieur (au 1 g sous 20 g) ; ml → au 5 ml ; pièces → au ½ supérieur pour la recette, **à l'entier supérieur pour la liste de courses** (on n'achète pas ½ oignon) ; cuillères → au ¼.
- Séparateur décimal : virgule. Fractions affichées (`½`, `¼`, `¾`) pour les cuillères et pièces.
- Les calculs internes ne sont **jamais** arrondis ; seul l'affichage l'est.

### R4 — Exclusions

- Produits de base → section « À vérifier dans le placard ».
- Repas de type « restes » ou « texte libre » → aucun article.
- Repas passés (date < aujourd'hui) exclus par défaut quand la période commence avant aujourd'hui (option « inclure »).

### R5 — Provenance

Chaque article généré conserve la liste de ses contributions (repas, date, recette, quantité) pour l'affichage « provenance » et le calcul de différence lors de la régénération.

### R6 — Intégrité

- Une recette planifiée ne peut pas être supprimée (archivage à la place).
- Un ingrédient utilisé dans une recette ne peut pas être supprimé (fusion ou renommage à la place).
- Modifier une recette **met à jour** les plannings futurs, mais **pas** les listes de courses déjà générées (instantané, voir 4.3).
