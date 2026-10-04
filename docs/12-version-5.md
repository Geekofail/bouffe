# 12 — Version 5 : analyse et propositions

Ce document prépare la **version 5** de Bouffe. Il fait suite à [08-version-4.md](08-version-4.md)
(modules 28 à 36, règles R32 à R37, lots 28 à 36, tous livrés : 992 tests sous SQLite, MariaDB et
MySQL 8, 72 vérifications dans le navigateur).

La demande est ouverte, avec deux priorités choisies par Pierre : **l'usage quotidien** (moins de
gestes au jour le jour) et **les nouvelles fonctions**. La fiabilité et la technique ne sont pas au
programme, sauf quand une nouveauté en a besoin. Les propositions viennent de quatre sources :

1. un **tour de l'application sur iPhone**, écran par écran, avec les gestes de tous les jours (§1.1) ;
2. les **limites écrites** dans le détail des lots 28 à 36 (§1.2) ;
3. ce que font **les applications du même genre** en 2026 (§1.3) ;
4. le **contexte luxembourgeois** : cantines scolaires, lutte contre le gaspillage (§1.4).

Il contient :

1. le **bilan** ;
2. les **principes** de la V5 ;
3. six **modules** (37 à 42) : trois pour le quotidien, trois pour les nouveautés ;
4. les **règles de gestion** R38 à R44 ;
5. les **ajouts au modèle de données**, les **écrans** et les **choix techniques** ;
6. le **découpage en lots** et les **questions ouvertes** Q52 à Q60.

Comme en V4, **un module = un lot, avec le même numéro**.

Marqueurs (inchangés) : **Priorité** ★★★ essentiel · ★★ utile · ★ bonus — **Taille** S · M · L —
**Origine** 🔁 amélioration de l'existant · 📌 limite notée dans un lot · ✨ nouvelle proposition —
**🌐** nécessite Internet · **💶** service payant à l'usage (quelques centimes) — **📱** dépend du
téléphone (iPhone ou Android).

---

## 1. Bilan

### 1.1 Ce que montre le tour de l'application (iPhone)

Le tour a été fait sur iPhone (390 px) avec les données d'essai de la base de développement : accueil,
planning, listes de courses, stock, fiche recette, liste des recettes, « Que cuisiner ? ». Les
écrans sont propres et lisibles depuis les lots 28 et 29 ; ce qui reste, ce sont des **gestes en
trop** et des **informations dans le mauvais ordre** pour le moment où l'on s'en sert.

| # | Écran | Constat |
|---|---|---|
| Q1 | **Accueil** | « C'était mangé ? » arrive en premier, et c'est bien. Mais la clôture reste une **corvée à part** : il faut ouvrir l'application pour la faire, et quand on l'oublie trois jours, les repas s'accumulent dans la cloche (« 16 autres dans la cloche »). Rien ne la propose au moment où on vient de manger |
| Q2 | **Planning** (téléphone) | Avant d'arriver aux repas du jour, il faut faire défiler l'astuce, l'équilibre de la semaine, les règles, le bandeau du stock et les idées : **environ un écran et demi**. C'est l'écran qu'on ouvre le plus souvent pour une seule question : « on mange quoi ce soir ? » |
| Q3 | **Fiche recette** (téléphone) | **Dix boutons** (Cuisiner, Planifier, Envie, Imprimer, Modifier, Dupliquer, Variantes, Plus, Archiver, Supprimer) avant la liste des ingrédients. En cuisine, ce sont les ingrédients et les étapes qu'on cherche |
| Q4 | **Ajouter aux courses** | Il faut sortir le téléphone, ouvrir Bouffe, toucher +, taper. Quand on a les mains prises (« il n'y a plus de beurre ») ou qu'on est dans une autre application, l'idée se perd |
| Q5 | **Importer une recette** | Une recette trouvée dans Safari : copier l'adresse, ouvrir Bouffe, Recettes › Importer, coller. Quatre gestes, et rien pour la mettre de côté « à regarder plus tard » |
| Q6 | **Planifier une case** | Le bouton « Proposer autre chose » existe dans **Remplir la semaine**, pas dans une case seule du planning : pour changer une seule idée, il faut chercher dans le carnet |

### 1.2 Limites écrites dans les lots 28 à 36 qui touchent le quotidien

| Lot | Limite | Piste |
|---|---|---|
| 32 | Une **gamelle** ne peut être que pour une personne qui a un compte (pour Léo, il faut un commentaire) | Personnes du foyer (39.1) |
| 35 | Les **minuteurs** restent dans un appareil : lancé sur le téléphone, il n'apparaît pas sur la tablette ; il n'y a pas d'alerte si la page est fermée | Minuteurs partagés (41.1) |
| 34 | Un **foyer relié** qui participe à un séjour ne voit pas sa fiche ; les frais se notent chez l'organisateur | Séjour co-organisé (42.1) |
| 31 | Une **photo d'étape** reste sur son numéro si on insère une étape avant | Notes et photos d'étapes attachées (40.2) |
| 33 | Un ingrédient de **remplacement** proposé par l'assistant n'est pas retenu pour la suite | Remplacements (40.1) |
| 30 | « **Annuler** » ne couvre pas les semaines types, le remplissage automatique ni les réceptions | Laissé tel quel (hors priorités) |
| 16 | Le **mode magasin** fonctionne hors ligne ; le **mode cuisine** non. Au chalet sans réseau, on n'a plus ses recettes | Cuisiner hors ligne (41.2) |

Toujours hors programme, sauf avis contraire : les **langues** (C6, Q42), l'**application native**
et la **commande directe chez un drive** (document 06 §8 : pas d'interface publique).

### 1.3 Ce que font les applications du même genre (2026)

| Application | Ce qui ressort | Dans Bouffe |
|---|---|---|
| **Mealie** (libre, auto-hébergée, versions 3.19 à 3.28) | **Remplacements d'ingrédients** propres à une recette ou communs ; **notes rattachées à une étape** ; bouton « proposer autre chose » dans le planning ; import unifié par l'IA ; images glissées dans les étapes | Assistant et import : oui. Remplacements, notes d'étape, « autre chose » dans une case : non (40.1, 40.2, 37.4) |
| **Paprika**, **Plan to Eat**, **Mealime** | Capture de recettes depuis le navigateur, calendrier, liste de courses consolidée | Oui, sauf la capture depuis le navigateur du téléphone (38.2) |
| **KitchenPal**, **Eatvora**, **Samsung Food** | Scan de codes-barres, alertes de péremption, partage familial, « mode sauvetage » des produits qui périment | Oui (lots 7 à 18, 30) |
| **Cooklist** | Prix en direct chez les enseignes, synchronisation des cartes de fidélité | Pas d'équivalent au Luxembourg sans interface publique (laissé de côté) |

Ce qui manque à Bouffe par rapport à ces applications est donc moins une grande fonction qu'une
série de **petits raccourcis** (capture, voix, remplacements) et le travail **à plusieurs en même
temps** (minuteurs, séjours).

### 1.4 Contexte luxembourgeois

- **Cantines scolaires** : **Restopolis**, agence du ministère de l'Éducation nationale, exploite les
  restaurants des lycées ; son site a une rubrique « Menus & réservations » et une application iOS et
  Android. Les maisons relais (enseignement fondamental) publient leurs menus chacune à leur manière.
  Rien n'indique un format exploitable par un programme : la piste réaliste est de **saisir ou
  photographier** le menu de la semaine (39.2).
- **Gaspillage** : la campagne gouvernementale **Antigaspi** (antigaspi.lu) diffuse des règles de
  conservation et des idées de restes ; Bouffe en applique déjà l'esprit (stock, péremption, restes,
  statistiques).

---

## 2. Principes directeurs de la V5

1. **Bouffe vient à toi, au bon moment.** Plutôt qu'un écran de plus, une question posée quand c'est
   le moment (« c'était bon, le chili ? »), une réponse donnée là où l'on est (Siri, la tablette).
2. **Le premier écran répond à la question du moment.** Le soir : ce qu'on mange ; en cuisine : les
   ingrédients ; en magasin : ce qui reste à prendre. Le reste se replie.
3. **À plusieurs, en même temps.** Deux téléphones, une tablette, un foyer relié : ce que l'un lance
   ou coche, les autres le voient.
4. **Les principes précédents restent** : une action principale par écran (V4-2), ce que Bouffe
   apprend il le propose (V4-4), pas de chiffre sans sa base (V4-5), le mutualisé OVH reste la
   cible et aucun service externe n'est indispensable (V3-4, V3-5).
5. **Les enfants ont leur place**, sans compte ni données inutiles : un prénom, un appétit, une
   cantine, des goûts.

---

## 3. Vue d'ensemble

| Module | Famille | En une phrase | Priorité | Taille | Dépend de |
|---|---|---|---|---|---|
| **37 — Le bon écran au bon moment** ✅ | Quotidien 🔁 | Le soir, une question et une réponse ; le planning et la fiche recette dans l'ordre où on s'en sert | ★★★ | M | — |
| **38 — Raccourcis, voix et partage** ✅ 📱 | Quotidien ✨ | « Dis Siri, ajoute du beurre » ; une recette envoyée depuis Safari ; les étapes lues à voix haute | ★★ | M | — |
| **39 — Enfants, école et cantine** ✅ | Nouveau ✨ | Chaque personne du foyer existe, compte ou pas ; la cantine du midi compte dans le planning ; les enfants choisissent | ★★ | M | — |
| **40 — Recettes qui s'adaptent** ✅ | Nouveau ✨ | Remplacements, notes d'étape, équipement de la cuisine, allergènes des produits | ★★ | M | — |
| **41 — Cuisiner à plusieurs, même sans réseau** ✅ | Quotidien 📌 | Minuteurs partagés entre appareils ; recettes de la semaine disponibles hors ligne | ★★ | M | 35 |
| **42 — Ensemble** ✅ | Nouveau ✨ | Séjours co-organisés, « qui apporte quoi », courses à deux en magasin | ★ | M | 34 |

---

## Module 37 — Le bon écran au bon moment 🔁

L'usage le plus fréquent de Bouffe tient en trois moments : **avant le repas** (on mange quoi, il
faut sortir quoi du congélateur), **en cuisinant** (la recette), **après** (c'était mangé, il reste
quoi). Ce module fait venir chaque information à son moment.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 37.1 ✅ | **Le rendez-vous du soir** | Une notification par jour, à une heure réglable (par défaut 1 h 30 après l'heure du dîner) : « Chili con carne : c'était mangé ? » Elle ouvre une petite page qui clôt le repas d'un geste (mangé · pas fait · restes à placer), et rappelle **ce qu'il faut préparer pour demain** (décongeler, faire tremper, gamelles). Remplace l'accumulation dans la cloche ; les rappels de la veille (14.6) y sont regroupés au lieu d'arriver séparément (R38) | ★★★ | S | ✨ (Q1) |
| 37.2 ✅ | **« Hier comme prévu »** | Sur l'accueil, s'il reste des repas à clôturer : un seul bouton qui marque mangés tous les repas de la veille **avec** le stock (aujourd'hui, le bouton groupé laisse le stock de côté), avec « Annuler » (R32) | ★★ | S | 🔁 |
| 37.3 ✅ | **Planning : aujourd'hui d'abord** | Sur téléphone, le planning s'ouvre sur le jour en cours ; astuce, équilibre, règles, stock et idées se replient en une ligne « 4 infos sur la semaine » qui s'ouvre d'un toucher | ★★★ | S | 🔁 (Q2) |
| 37.4 ✅ | **« Autre idée » dans une case** | Dans le détail d'une case vide ou d'un repas : trois propositions qui respectent les règles de la semaine, la saison, le stock et les invités (le même calcul que « Remplir la semaine »), et un bouton « Autre idée » pour en tirer trois autres | ★★ | S | 🔁 (Q6) |
| 37.5 ✅ | **Fiche recette en cuisine** | Sur téléphone : photo, temps, puis **ingrédients et étapes**. Les dix boutons deviennent « Cuisiner », « Planifier » et un menu « Plus » ; l'ajusteur de portions reste en haut des ingrédients | ★★ | S | 🔁 (Q3) |

> **Réalisé au lot 37** ([détail](lots/lot-37-bon-ecran-bon-moment.md)). Écarts : le rendez-vous se
> règle dans **Paramètres › Notifications** (avec les autres choix de chacun) plutôt que dans Mon
> compte ; il ne part pas non plus pendant un **séjour**. Sur la page « Ce soir », « Tout comme
> prévu » ne concerne que les repas d'aujourd'hui et d'hier, les jours d'avant restant repliés ; le
> même geste est proposé sur l'accueil (« Hier comme prévu ») dès deux repas de la veille. Le planning
> s'ouvrait déjà sur le jour en cours depuis le lot 28 : seul le repli des infos est nouveau. Les
> idées d'une case écartent desserts, entrées, accompagnements et sous-recettes pour un plat
> principal, et proposent en dernier recours une recette récente ou déjà au menu, en le disant.

---

## Module 38 — Raccourcis, voix et partage ✨ 📱

Une application web installée sur l'écran d'accueil ne peut pas avoir de widget, ni de raccourcis
d'icône sur iPhone. En revanche, l'application **Raccourcis** d'Apple sait appeler une adresse web,
et Siri sait lancer un raccourci. C'est le chemin le plus court entre une idée et Bouffe.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 38.1 ✅ | **« Dis Siri, ajoute… à Bouffe »** | Mon compte › **Raccourcis** : un jeton personnel et trois raccourcis prêts à installer, avec mode d'emploi : **Ajouter aux courses** (dicté : « deux baguettes et du beurre » → deux articles), **On mange quoi ce soir ?** (Siri lit le menu du jour), **Ajouter au stock**. Le jeton ne permet que ces gestes, se révoque à tout moment (R39) | ★★★ | M | ✨ (Q4) |
| 38.2 ✅ | **Envoyer une recette à Bouffe** | Depuis Safari (ou Instagram, un message…) : bouton **Partager › Envoyer à Bouffe** (un raccourci de plus, sur iPhone ; sur Android, Bouffe apparaît directement dans le menu de partage). L'adresse arrive dans « **À trier** » : Bouffe tente l'import tout de suite, on relit plus tard | ★★ | S | ✨ (Q5) |
| 38.3 ✅ | **« À trier »** | Une boîte de recettes reçues : liens importés ou en échec, photos de pages de livre (lues par le service des tickets, lot 23), recettes d'un proche. Chacune : **Garder** (relecture d'import), **À tester** (dans le carnet avec l'étiquette), **Jeter**. Un rappel discret si la boîte dépasse 10 éléments | ★★ | S | ✨ |
| 38.4 ✅ | **Mode cuisine mains libres** | Bouton « Lire à voix haute » : l'étape est lue par le téléphone, puis la suivante quand on touche n'importe où sur l'écran ; un minuteur qui sonne est annoncé (« cuisson des pâtes : terminé »). Là où le navigateur reconnaît la parole, dire « suivant », « répète », « minuteur » suffit ; ailleurs, le bouton reste | ★ | S | ✨ |

> **Réalisé au lot 38** ([détail](lots/lot-38-raccourcis-voix-partage.md)). Écarts : la page
> **Mon compte › Raccourcis et Siri** donne le mode d'emploi pas à pas de chaque raccourci plutôt
> qu'un fichier à installer, car Apple n'accepte que des raccourcis signés. Un quatrième raccourci,
> « Envoyer à Bouffe », sert au partage depuis Safari. Les raccourcis demandent l'adresse en https
> hors de la maison. Dans « À trier », « Garder » et « À tester » ne font qu'un : une recette
> importée entre déjà dans le carnet avec l'étiquette « à tester ». Les recettes d'un proche n'y
> passent pas. Les photos sont lues par la tâche planifiée, si le service des tickets est
> configuré. La reconnaissance de la parole n'existe que là où le navigateur la propose (Safari,
> Chrome) ; le toucher suffit ailleurs.

---

## Module 39 — Enfants, école et cantine ✨

Aujourd'hui, une personne sans compte (un enfant) n'existe que comme une ligne « à table
d'habitude » (lot 32) : elle a un appétit, mais pas de gamelle, pas de goûts, pas de cantine. Ce
module lui donne une place.

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 39.1 ✅ | **Les personnes du foyer** | Paramètres › Foyer : chaque personne (compte ou pas) a un prénom, un appétit, une couleur, et peut avoir des **goûts** (« n'aime pas » et allergies, comme un invité) et une **cantine**. La gamelle (32.3), les allergies (18.1) et « qui cuisine » (14.7) s'appuient sur elles ; un compte peut être rattaché à une personne (R40) | ★★★ | M | 📌 (lot 32) |
| 39.2 ✅ | **La cantine du midi** | Pour une personne qui mange à la cantine : ses jours de cantine, et le **menu de la semaine**, saisi, collé depuis le site ou l'application de l'école, ou **photographié** (lecture par le service des tickets, 🌐 💶). Le planning affiche « midi : cantine — poisson pané » ; les idées du soir évitent le même plat et la même famille le même jour ; l'équilibre de la semaine en tient compte (R41) | ★★ | M | ✨ (§1.4) |
| 39.3 ✅ | **Le choix des enfants** | Sur l'écran de cuisine (lot 35) ou le téléphone d'un parent, un écran simple aux grandes images : « Choisis ton dîner de mercredi » parmi trois recettes **préparées par un adulte** (compatibles avec tout le monde). Le choix devient une envie (lot 18) ou s'inscrit au planning si l'adulte l'a permis (R42) | ★ | S | ✨ |
| 39.4 ✅ | **Ils cuisinent** | Une recette peut être marquée « facile avec un enfant » ; en mode cuisine, les étapes qui demandent un adulte (four, couteau, plaque, friture) sont signalées d'une icône, d'après des mots repérés dans le texte et corrigeables | ★ | S | ✨ |

> **Réalisé au lot 39** ([détail](lots/lot-39-enfants-ecole-cantine.md)). Écarts :
> - Les personnes sont enregistrées au premier besoin, à partir de la table du lot 32 reprise telle
>   quelle. Une case « à table d'habitude » décide qui compte dans les portions et les alertes.
> - « Qui cuisine » reste un compte (notifications, « Mes étapes »).
> - Les jours de cantine, l'enfant n'est pas compté au déjeuner. Le déjeuner est repéré par le nom
>   du créneau, et les vacances se marquent avec « Pas de cantine » (pas de calendrier scolaire).
> - Le choix des enfants se prépare dans Planning › Plus › Le choix des enfants et se fait sur
>   l'écran de cuisine (`/cuisine/choix`). Il ajoute la table `child_choices`, absente du §5.
> - « Facile avec un enfant » ajoute `recipes.kid_friendly`, et la correction d'une étape ajoute
>   `recipe_steps.adult_help`.
> - En séjour, les personnes de notre foyer gardent leurs allergies (`stay_participants.person_id`).

---

## Module 40 — Recettes qui s'adaptent ✨

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 40.1 ✅ | **Remplacements** | Pour un ingrédient : des remplacements, **communs** (« crème liquide → crème de soja, même quantité ») ou **propres à une recette**. Livré avec une courte liste de départ ; ceux acceptés depuis l'assistant ou une variante y entrent. Affichés en mode cuisine (« pas de crème ? »), dans « Que cuisiner ? » (une recette devient faisable avec ce qu'on a) et sur la liste de courses (« ou : crème de soja, en stock »). Jamais un remplacement qui contient l'allergène d'une personne présente (R43) | ★★ | M | 📌 (lot 33) |
| 40.2 ✅ | **Notes d'étape** | Sur une étape : une note du foyer (« notre four chauffe fort : 170 °C ») montrée en mode cuisine, gardée quand la recette est modifiée. Notes et photos d'étapes suivent désormais l'**étape** et non son numéro : insérer une étape ne les décale plus | ★★ | S | 📌 (lot 31) |
| 40.3 ✅ | **Équipement de la cuisine** | Paramètres : l'équipement de la maison (four, micro-ondes, robot, cuiseur vapeur, friteuse à air, multicuiseur…). Une recette indique ce qu'elle demande (proposé d'après le texte des étapes). Un **séjour** a son propre équipement (« pas de four au chalet ») : son planning et « Autre idée » écartent les recettes impossibles | ★ | S | ✨ |
| 40.4 ✅ | **Allergènes des produits** | Au scan d'un produit (lot 18, Open Food Facts) : ses **allergènes** et **traces** sont gardés. Un produit du stock qui contient l'allergène d'une personne du foyer ou d'un invité prévu est signalé, sur la fiche du produit et au moment de le prévoir dans un repas (R44) | ★★ | S | 🔁 🌐 |

> **Réalisé au lot 40** ([détail](lots/lot-40-recettes-adaptables.md)). Écarts :
> - La liste commune n'appartient à aucun foyer : un foyer masque une entrée (réglage
>   `substitutions.hidden`) plutôt que de la supprimer. Elle ajoute six ingrédients au catalogue.
> - Un remplacement est aussi écarté pour un « n'aime pas ». Une allergie vaut pour tout le groupe
>   d'allergènes (lait demi-écrémé → beurre, crème…).
> - La quantité remplacée n'est affichée que pour un poids ou un volume. « Que cuisiner ? » n'utilise
>   que les remplacements en stock ; la liste de courses, seulement les communs et ceux du foyer.
> - Les tâches réparties (lot 41) et la correction « avec un adulte » (lot 39) restent sur le numéro
>   d'étape.
> - Tant que l'équipement de la maison n'est pas enregistré, tout est disponible. Un séjour sans
>   équipement noté suit la maison. Le choix d'un repas avertit d'un équipement manquant.
> - Les allergènes des produits déjà en stock sont complétés par la tâche de fond, 5 par passage.
>   Un badge « allergène » s'affiche dans la liste du stock.

---

## Module 41 — Cuisiner à plusieurs, même sans réseau 📌

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 41.1 ✅ | **Minuteurs partagés** | Un minuteur lancé n'importe où (mode cuisine, écran de cuisine, Siri) est enregistré dans Bouffe : il apparaît sur tous les appareils du foyer ouverts, et une **notification** part à la fin si personne n'a la page sous les yeux. On l'arrête de n'importe quel appareil | ★★★ | M | 📌 (lot 35) |
| 41.2 ✅ | **Cuisiner hors ligne** | « Garder hors ligne » : les recettes de la semaine (ou d'un séjour) sont enregistrées dans le téléphone, dans une version lisible sans réseau (ingrédients aux bonnes portions, étapes, minuteurs locaux). Pour le chalet sans Wi-Fi ou la cave sans réseau | ★★ | S | 📌 |
| 41.3 ✅ | **Qui fait quoi ce soir** | Pour un repas complet (31.3) : les tâches du rétroplanning se répartissent entre deux personnes (« Pierre : gratin ; Monique : dessert »), chacun voit les siennes sur son téléphone, l'écran de cuisine montre les deux | ★ | S | ✨ |

> **Réalisé au lot 41** ([détail](lots/lot-41-cuisiner-a-plusieurs.md)). Écarts : les minuteurs
> partagés s'affichent aussi en dehors de la cuisine, dans une pastille en haut de chaque page ; la
> notification de fin va à la personne qui a lancé le minuteur (à tout le foyer si elle n'a pas de
> téléphone abonné) et passe outre les heures calmes ; un minuteur lancé sans réseau reste sur
> l'appareil. « Qui fait quoi » répartit d'abord les **plats** (le « qui cuisine » du planning, 14.7),
> puis, si besoin, une étape ; les étapes cochées sont partagées entre appareils. Siri viendra au
> lot 38.

---

## Module 42 — Ensemble ✨

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 42.1 ✅ | **Séjour co-organisé** | Un foyer relié invité à un séjour peut **accepter** : il voit alors la fiche, ajoute des repas et des dépenses, gère ses propres participants. Chacun ne voit des autres que ce qu'il voyait déjà (R31, R45) | ★★ | M | 📌 (lot 34) |
| 42.2 ✅ | **Qui apporte quoi** | Pour une réception ou un séjour : une liste « à apporter » (dessert, vin, pain, salade) où chaque participant, de Bouffe ou par un lien sans compte (comme le partage de recette, 31.4), inscrit ce qu'il apporte. Les doublons sont visibles ; ce qui est apporté sort de la liste de courses | ★★ | S | ✨ |
| 42.3 ✅ | **Courses à deux en magasin** | En mode magasin, « On se partage ? » : chacun prend des rayons (« Pierre : frais et boucherie ; Monique : épicerie »), ne voit que les siens en premier, et voit en direct ce que l'autre a coché | ★ | S | ✨ |

> **Réalisé au lot 42** ([détail](lots/lot-42-ensemble.md)). Écarts :
> - Le séjour reste chez l'organisateur, avec ses créneaux, ses portions et sa liste. Un foyer qui
>   co-organise ajoute ses participants, ses plats (de son carnet), ses dépenses et ce qu'il emporte
>   de son stock (son propre départ et retour). Seul l'organisateur change les dates, le partage des
>   frais, ou supprime le séjour.
> - Une recette de l'autre foyer ne montre que son titre. Les alertes qui concernent les convives
>   de l'autre foyer sont montrées sans nom (« allergie de quelqu'un de « Les Martin » »).
> - Le foyer qui co-organise coche la liste du séjour en mode magasin ; la page détaillée de la
>   liste reste à l'organisateur.
> - Qui apporte quoi : un lien recopiable par évènement, qui expire 3 jours après. Pour une
>   réception, un plat apporté sort de la liste de la maison à sa prochaine mise à jour ; dans un
>   séjour, un ingrédient apporté aussi, tout de suite.
> - Courses à deux : relecture toutes les 6 secondes pendant le partage ; le partage des rayons
>   demande du réseau.

---

## 4. Règles de gestion

### R38 — Rendez-vous du soir

- Au plus **une notification de suivi par jour et par personne**, à l'heure réglée ; elle respecte
  les heures calmes (lot 20). Elle regroupe : la clôture du dernier repas, les préparations du
  lendemain, les gamelles à préparer.
- Elle ne part pas si tout est déjà fait, ni si la personne était absente du repas (portions,
  séjour).
- Toucher la notification ouvre la page du rendez-vous ; aucune action n'est faite sans un geste.

### R39 — Jeton des raccourcis

- Un **jeton personnel** par personne, créé à la demande, montré une seule fois, révocable (comme
  le lien d'agenda du lot 26).
- Il ne permet que : ajouter un article aux courses ou au stock, lire le menu du jour et du
  lendemain, déposer une adresse dans « À trier ». **Jamais** de suppression, ni de lecture des
  dépenses ou des données des proches.
- 60 demandes par heure au plus ; chaque usage est noté au journal du foyer.

### R40 — Personnes du foyer

- Une personne existe dans **un** foyer ; un compte peut lui être rattaché (un compte qui appartient
  à deux foyers a une personne dans chacun).
- Ses goûts et allergies sont visibles des membres du foyer ; ils ne sont partagés avec un foyer
  relié que si elle (ou, pour un enfant, un adulte du foyer) l'a choisi (26.6).
- Aucune date de naissance n'est demandée : l'appétit suffit.

### R41 — Cantine

- Un repas de cantine compte pour l'**équilibre** et pour éviter les répétitions, **pas** pour les
  courses ni le stock.
- Un menu photographié est lu comme un ticket (service et plafond du lot 23) ; seule la photo est
  envoyée, sans nom.
- Un jour sans menu saisi est « cantine » sans détail : rien n'est déduit.

### R42 — Le choix des enfants

- Les propositions sont faites par un adulte, et déjà vérifiées pour les allergies de **tous** ceux
  qui mangent ce jour-là.
- Le choix d'un enfant est une **envie** ; il ne remplace un repas déjà prévu que si un adulte l'a
  permis pour cette semaine.

### R43 — Remplacements

- Un remplacement **ne modifie jamais** la recette : il est proposé, et noté dans la note de
  cuisine si on l'utilise.
- Un remplacement qui contient l'allergène d'une personne présente (ou ses traces, s'il s'agit d'une
  allergie) n'est **jamais** proposé.
- La quantité suit un rapport (1 pour 1 par défaut), arrondi comme les portions.

### R44 — Allergènes des produits

- Les allergènes viennent d'**Open Food Facts** ; ils sont affichés comme **indicatifs** (« d'après
  Open Food Facts ») et ne remplacent pas la lecture de l'étiquette.
- « Contient » et « peut contenir des traces » sont distingués ; seule une allergie déclenche une
  alerte pour les traces.
- Un produit sans information n'est pas présenté comme sûr : « allergènes inconnus ».

### R45 — Séjour co-organisé

- Le séjour reste rangé chez l'organisateur ; un foyer qui a accepté devient **co-organisateur** :
  repas, courses, dépenses, ses propres participants.
- Les frais (R37) sont visibles de tous les foyers participants ; le stock « à emporter » (34.4) de
  chacun reste le sien.
- L'organisateur peut retirer un foyer ; ses dépenses restent dans les comptes, marquées.

---

## 5. Modèle de données (ajouts)

| Table / colonne | Contenu | Module |
|---|---|---|
| `household_people` (household_id, user_id NULL, name, appetite, color, at_table, canteen_days JSON, canteen_name, share_tastes, position) — remplace le réglage `table.people` (lot 32), repris à l'identique par la migration | Personnes du foyer | 39.1 ✅ |
| `person_restrictions` (household_id, person_id, type, ingredient_id, tag_id, note) — reprend `household_restrictions` (lot 15) | Goûts et allergies d'une personne | 39.1 ✅ |
| `meal_occasions.absent_person_ids` ; `stay_participants.person_id` | Absents sans compte ; allergies en séjour | 39.1 ✅ |
| `planned_meals.for_person_id` (remplace `for_user_id`) | Gamelle de n'importe qui | 39.1 ✅ |
| `canteen_meals` (household_id, person_id, date, status, label, families JSON, source) | Menu de la cantine | 39.2 ✅ |
| `child_choices` (household_id, person_id, date, meal_slot_id, recipe_ids JSON, allow_plan, chosen_recipe_id, chosen_at, wish_id, planned_meal_id) | Le choix des enfants | 39.3 ✅ |
| `recipes.kid_friendly` ; `recipe_steps.adult_help` | « Facile avec un enfant », étapes corrigées | 39.4 ✅ |
| `api_tokens` (household_id, user_id, token_hash, hint, last_used_at, uses, revoked_at) | Raccourcis (R39) | 38.1 ✅ |
| `inbox_items` (household_id, user_id, kind, via, url, text, photo_path, title, payload, status, error, recipe_id) | « À trier » | 38.2, 38.3 ✅ |
| `ingredient_substitutions` (household_id NULL, ingredient_id, substitute_id, ratio, recipe_id NULL, note) — `household_id` vide = liste commune | Remplacements | 40.1 ✅ |
| `recipe_steps.uid` (identifiant stable) ; `recipe_step_notes` (recipe_id, step_uid, note, user_id) ; `recipe_photos.step_uid` | Notes et photos qui suivent l'étape | 40.2 ✅ |
| `recipes.equipment` (JSON) ; réglage `kitchen.equipment` ; `stays.equipment` (JSON) | Équipement | 40.3 ✅ |
| `products.allergens` (JSON), `products.traces` (JSON), `products.allergens_checked_at` | Allergènes des produits | 40.4 ✅ |
| `kitchen_timers` (household_id, user_id, label, duration, ends_at, source, rang_at, stopped_at, notified_at) | Minuteurs partagés | 41.1 ✅ |
| `meal_tasks` (household_id, planned_meal_id, step_number, user_id, done_at, done_by) | Qui fait quoi | 41.3 ✅ |
| `stay_households` (stay_id, household_id, status, departed_at, returned_at) ; `household_id` sur `stay_meals`, `stay_payments`, `stay_packed_items` | Séjour co-organisé | 42.1 ✅ |
| `contributions` (household_id, meal_occasion_id ou stay_id, label, ingredient_id, planned_meal_id, stay_meal_id, by_label, by_household_id NULL, token_hash NULL) ; `contribution_links` (le lien sans compte) | Qui apporte quoi | 42.2 ✅ |
| `shopping_list_aisle_owners` (shopping_list_id, aisle_id, user_id) | Courses à deux | 42.3 ✅ |

Deux tables existantes sont **transformées** (personnes et contraintes, 39.1) : la migration reprend
les données à l'identique et le lot commence par là. Toutes les autres tables **ajoutent** ; chaque
table propre à un foyer porte `household_id` et passe par l'isolation R29.

---

## 6. Écrans et routes prévus

| Route | Écran | Lot |
|---|---|---|
| `/ce-soir` | Rendez-vous du soir (clôture, préparations du lendemain) | 37 ✅ |
| `/compte/raccourcis` | Jeton et raccourcis à installer | 38 ✅ |
| `/api/raccourcis/…` | Adresses appelées par les raccourcis (jeton personnel) | 38 ✅ |
| `/recettes/a-trier` | « À trier » | 38 ✅ |
| `/partager` | Cible de partage Android (vers « À trier ») | 38 ✅ |
| `/parametres/foyer` (existant) | Personnes du foyer, goûts, cantine | 39 ✅ |
| `/planning/cantine` | Menus de cantine de la semaine | 39 ✅ |
| `/planning/choix-des-enfants` | Préparer le choix des enfants | 39 ✅ |
| `/cuisine/choix` (prévu : `/choix`) | Le choix des enfants | 39 ✅ |
| `/parametres/remplacements` | Remplacements communs et du foyer | 40 ✅ |
| `/parametres/equipement` | Équipement de la cuisine | 40 ✅ |
| `/apporter/{jeton}` | « Qui apporte quoi » sans compte | 42 ✅ |

---

## 7. Choix techniques

### 7.1 Raccourcis et partage sur iPhone

- Sur iPhone, une application web installée n'a **ni widget ni raccourcis d'icône**, et ne peut pas
  s'inscrire comme cible du menu de partage (Web Share Target) ; Android, lui, le permet.
- L'application **Raccourcis** d'Apple sait appeler une adresse web (action « Obtenir le contenu de
  l'URL », en GET ou en POST avec un corps JSON et des en-têtes) et Siri sait lancer un raccourci par
  son nom. Un raccourci peut aussi apparaître dans le menu **Partager** de Safari.
- Bouffe fournit donc des raccourcis prêts à installer (fichiers `.shortcut` signés depuis l'iPhone
  de Pierre, ou recette pas à pas dans la page « Raccourcis ») et des adresses simples, protégées par
  le jeton personnel (R39). Sur Android, le manifeste déclare Bouffe comme cible de partage.
- Les raccourcis demandent que Bouffe soit **joignable depuis l'extérieur** (document 09) pour
  fonctionner hors de la maison.

### 7.2 Voix

- **Lecture à voix haute** : l'API de synthèse vocale du navigateur est disponible sur Safari (iPhone
  et Mac) comme sur Chrome, sans service externe ; Safari a ses particularités (choix des voix,
  premier déclenchement par un geste), à vérifier sur l'iPhone.
- **Reconnaissance de la parole** (« suivant ») : prise en charge **partielle** seulement, sur Safari
  depuis iOS 14.5 et sur Chrome ; absente de Firefox. Elle reste donc un plus : le bouton à l'écran
  fait toujours la même chose.
- La **dictée du clavier** de l'iPhone marche déjà dans tous les champs de Bouffe ; rien à ajouter
  pour taper à la voix.

### 7.3 Notifications

- Les notifications web marchent sur iPhone depuis iOS 16.4 pour une application ajoutée à l'écran
  d'accueil, **y compris dans l'Union européenne** : Apple a renoncé en mars 2024 à retirer ces
  applications dans l'UE (iOS 17.4). C'est ce qu'utilisent déjà les lots 20 et 30.
- Le **badge** (pastille chiffrée sur l'icône) est aussi disponible depuis iOS 16.4 : il pourra
  compter les repas à clôturer (37.1).
- La notification du soir passe par la tâche planifiée existante (toutes les 5 à 15 minutes chez
  OVH, document 10) : l'heure d'envoi est donc précise à quelques minutes près.

### 7.4 Minuteurs partagés et hors ligne

- Les minuteurs partagés (41.1) sont enregistrés en base ; chaque page ouverte les relit toutes les
  quelques secondes (comme la liste de courses du lot 16) et compte le temps elle-même. La
  notification de fin passe par la tâche planifiée : elle peut arriver **jusqu'à quelques minutes
  après** la fin réelle ; la sonnerie de la page ouverte, elle, est à la seconde. Pas de serveur de
  messages en direct (non disponible sur le mutualisé OVH).
- Le hors ligne (41.2) étend le service web du lot 16 : les pages d'impression des recettes, déjà
  autonomes, sont gardées à la demande ; les minuteurs y fonctionnent localement.

### 7.5 Open Food Facts et cantines

- Bouffe interroge déjà Open Food Facts au scan (lot 18, `ProductLookup`). Les champs d'allergènes et
  de traces s'ajoutent à la même demande ; aucun compte n'est nécessaire pour lire.
- Restopolis et les maisons relais : pas d'interface publique connue pour leurs menus. Saisie, collage
  et photo (lue par Mistral comme les tickets) ; rien d'automatique tant que Pierre n'a pas vu le
  format réellement publié (Q55).

---

## 8. Découpage en lots

| Lot | Contenu | Livrable | Taille | Dépend de |
|---|---|---|---|---|
| **37 — Le bon écran au bon moment** ✅ ([détail](lots/lot-37-bon-ecran-bon-moment.md)) | 37.1 à 37.5 (R38) | Moins de gestes chaque soir ; le planning et les recettes dans le bon ordre | M | — |
| **38 — Raccourcis, voix et partage** ✅ ([détail](lots/lot-38-raccourcis-voix-partage.md)) | 38.1 à 38.4 (R39) | Siri, partage depuis Safari, « À trier », lecture à voix haute | M | 09 (accès extérieur) |
| **39 — Enfants, école et cantine** ✅ ([détail](lots/lot-39-enfants-ecole-cantine.md)) | 39.1 à 39.4 (R40 à R42) | Chaque personne existe ; la cantine compte ; les enfants choisissent | M | — |
| **40 — Recettes qui s'adaptent** ✅ ([détail](lots/lot-40-recettes-adaptables.md)) | 40.1 à 40.4 (R43, R44) | Remplacements, notes d'étape, équipement, allergènes | M | 39 (allergies par personne) |
| **41 — Cuisiner à plusieurs, même sans réseau** ✅ ([détail](lots/lot-41-cuisiner-a-plusieurs.md)) | 41.1 à 41.3 | Minuteurs partagés, recettes hors ligne, tâches réparties | M | 35 |
| **42 — Ensemble** ✅ ([détail](lots/lot-42-ensemble.md)) | 42.1 à 42.3 (R45) | Séjours à plusieurs foyers, « qui apporte quoi », courses à deux | M | 34, 39 |

**Ordre proposé** : 37 → 41 → 38 → 39 → 40 → 42.

- Le lot 37 améliore ce qui sert **tous les jours**, sans rien de nouveau à apprendre.
- Le lot 41 vient ensuite : les minuteurs partagés corrigent la limite la plus gênante en cuisine.
- Le lot 38 dépend de l'accès depuis l'extérieur (document 09) pour être vraiment utile ; il peut
  être avancé si c'est déjà le cas.
- Le lot 39 transforme les personnes du foyer : il passe avant 40 et 42, qui s'appuient dessus.
- Le lot 42 en dernier, comme les séjours en V4.

---

## 9. Questions ouvertes

Comme pour les documents précédents : réponds directement, les propositions par défaut seront
appliquées sinon.

| # | Question | Proposition par défaut |
|---|---|---|
| Q52 | Ordre des lots (§8) | 37 → 41 → 38 → 39 → 40 → 42 — *appliqué au lot 37* |
| Q53 | Rendez-vous du soir (37.1) : à quelle heure, et pour qui ? | 1 h 30 après l'heure du dîner, pour chaque compte complet ; réglable, désactivable — *appliqué au lot 37 (20 h 30, dans Paramètres › Notifications)* |
| Q54 | iPhone, Android, ou les deux, dans le foyer ? (raccourcis 38.1 et 38.2) | iPhone d'abord (raccourcis Siri) ; la cible de partage Android en plus, elle ne coûte presque rien — *appliqué au lot 38* |
| Q55 | Cantine (39.2) : qui mange à la cantine, et où (lycée avec Restopolis, maison relais) ? Peux-tu me montrer à quoi ressemble le menu publié ? | Saisie à la main et photo ; un import automatique seulement si le format s'y prête — *appliqué au lot 39 (saisie, collage par jour, photo) ; l'import attend un menu réel* |
| Q56 | Le choix des enfants (39.3) : utile chez vous ? Sur la tablette de cuisine ou sur un téléphone ? | Oui, sur l'écran de cuisine, trois propositions par semaine — *appliqué au lot 39 (aussi sur un téléphone, par `/cuisine/choix`)* |
| Q57 | Équipement (40.3) : quelle liste proposer ? | Four, micro-ondes, plaques, robot, mixeur plongeant, cuiseur vapeur, friteuse à air, multicuiseur, barbecue ; modifiable — *appliqué au lot 40* |
| Q58 | Remplacements (40.1) : une liste de départ fournie, ou commencer vide ? | Une courte liste de départ (une trentaine de remplacements courants : lait, crème, beurre, œuf, farine…), modifiable — *appliqué au lot 40 (une quarantaine)* |
| Q59 | Séjours co-organisés et « qui apporte quoi » (module 42) : utile ? | Oui, en dernier — *appliqué au lot 42* |
| Q60 | Langues (C6) : toujours non ? | Toujours non |

---

## Sources

- Mealie — [notes de version](https://github.com/mealie-recipes/mealie/releases), résumées sur
  [Releasebot](https://releasebot.io/updates/mealie-recipes) (versions 3.19 à 3.28 : remplacements
  d'ingrédients, notes d'étape, « proposer autre chose » dans le planning, import par l'IA).
- Comparatifs 2026 — [Pantry Persona, « The Best Meal-Planning Tools in 2026 »](https://www.pantrypersona.com/blog/best-meal-planning-tools-2026) ;
  [Kitchendary, « 12 Best Meal Planning Apps for 2026 »](https://kitchendary.app/guides/best-meal-planning-apps).
- Applications web sur iPhone — [MagicBell, « PWA iOS Limitations and Safari Support [2026] »](https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide)
  (badge et notifications depuis iOS 16.4, pas de widget ni de raccourcis d'icône) ;
  [9to5Mac, « iOS 17.4 won't remove Home Screen web apps in the EU after all »](https://9to5mac.com/2024/03/01/apple-home-screen-web-apps-ios-17-eu/) ;
  [web.dev, « OS Integration »](https://web.dev/learn/pwa/os-integration/) (cible de partage).
- Raccourcis Apple — [« Demander une première API dans Raccourcis »](https://support.apple.com/fr-fr/guide/shortcuts-mac/apd58d46713f/mac)
  (action « Obtenir le contenu de l'URL », méthode, en-têtes, corps JSON).
- Voix — [Can I use : Speech Recognition API](https://caniuse.com/speech-recognition) (prise en charge
  partielle, Safari iOS 14.5+) ; [Can I use : Speech Synthesis API](https://caniuse.com/speech-synthesis) ;
  [« The State of Speech Synthesis in Safari »](https://weboutloud.io/bulletin/speech_synthesis_in_safari/).
- Open Food Facts — [tutoriel de l'API](https://openfoodfacts.github.io/openfoodfacts-server/api/tutorial-off-api/).
- Luxembourg — [Restopolis](https://restopolis.lu/en/) (agence du ministère de l'Éducation nationale,
  rubrique « Menus & réservations », application iOS et Android) ; [Antigaspi](https://antigaspi.lu/en/)
  et la [campagne « huit règles d'or »](https://gouvernement.lu/fr/actualites/toutes_actualites/communiques/2022/12-decembre/13-campagne-antigaspi.html).
