# 07 — Version 3 : analyse et spécifications

Ce document prépare la **version 3** de Bouffe. Il fait suite à [06-version-2.md](06-version-2.md)
(modules 12 à 21, règles R13 à R22, lots 11 à 20, tous livrés).

Il part de quatre idées notées par Pierre :

1. **stock** — « lorsqu'on utilise le stock pour un repas, le décrémenter si ce n'est pas déjà le cas » ;
2. **plusieurs foyers** — partager l'application avec d'autres foyers : recettes visibles si on le
   souhaite, planning géré chacun de son côté ou partagé, fonctionnalités utiles entre foyers ;
3. **budget réel** — saisir le montant de chaque ticket de caisse, comparer au budget défini,
   budget restaurant compris ;
4. **OCR** — remplir le stock à partir d'une photo du ticket de caisse ;

avec un objectif : **mettre l'application en ligne chez OVH**.

Trois réponses données avant la rédaction orientent tout le document :

| Question | Réponse | Conséquence |
|---|---|---|
| Offre OVH visée | **Hébergement mutualisé** | Rien à installer sur le serveur (pas de logiciel d'OCR local, pas de processus permanent) ; tâches planifiées limitées à **une par heure** ; il faut l'offre **Pro** pour l'accès SSH (§7.1) |
| Lecture des tickets | **Service externe accepté** | La photo part chez un fournisseur d'OCR / IA le temps de la lecture ; quelques centimes par mois ; saisie manuelle toujours possible |
| Multi-foyer | **Famille et amis** | Quelques foyers invités par Pierre sur son installation ; pas d'inscription publique, donc pas de CGU publiques, de modération ni de facturation |

Il contient :

1. le **bilan** des idées au regard de ce qui existe déjà ;
2. les **principes** de la V3 ;
3. six **modules** (22 à 27) ;
4. les **règles de gestion** R23 à R31 ;
5. les **ajouts au modèle de données**, les **écrans** et les **choix techniques** (multi-foyer,
   OCR, contraintes du mutualisé OVH) ;
6. le **découpage en lots** 21 à 27 et les **questions ouvertes** Q28 à Q42.

Marqueurs (inchangés) : **Priorité** ★★★ essentiel · ★★ utile · ★ bonus — **Taille** S · M · L —
**Origine** 🔁 amélioration de l'existant · 📌 idée de Pierre · ✨ nouvelle proposition —
**🌐** nécessite Internet · **💶** service payant à l'usage (quelques centimes).

---

## 1. Bilan : ce qui existe déjà

### 1.1 Idée 1 — le stock est-il déjà décrémenté ?

**Oui, en partie, depuis le lot 9 (règle R9).** Quand un repas est marqué **mangé** — depuis le
planning, l'accueil ou à la fin du mode cuisine — Bouffe calcule ce qu'il faut retirer du stock
et, selon le réglage Paramètres → Alertes stock → « Retrait au repas mangé » :

- **Demander** (par défaut) : une fenêtre propose les quantités, modifiables, puis retire ;
- **Automatique** : le retrait est fait sans fenêtre ;
- **Jamais**.

Décocher « mangé » remet le stock comme avant. Depuis le lot 20, les sous-recettes sont comprises
et un plat cuisiné à l'avance retire le plat préparé plutôt que ses ingrédients.

**Ce qui manque, et qui explique l'impression que le stock n'est pas décrémenté :**

| # | Trou | Effet |
|---|---|---|
| S1 | Un repas **jamais marqué « mangé »** ne retire rien | C'est le cas le plus fréquent : on cuisine, on mange, on oublie de cocher. Le stock gonfle |
| S2 | En mode **Demander**, fermer la fenêtre sans valider ne retire rien, sans le rappeler | Même effet que S1, en plus discret |
| S3 | Le stock **prévu pour un repas** n'est pas réservé | « Que cuisiner ? » et la liste de courses considèrent comme disponibles des œufs déjà promis à la quiche de jeudi |
| S4 | Ce qu'on consomme **hors repas** (goûter, café, dépannage) ne se retire qu'à la main, article par article | Le stock dérive lentement |
| S5 | Les écarts ne sont **jamais signalés** | Quand le stock dit « 6 œufs » et qu'il n'y en a plus, on ne le découvre qu'au moment de cuisiner |

Le module 22 comble ces trous.

### 1.2 Idée 3 — budget

Le lot 17 a posé un **budget mensuel unique**, alimenté par les prix saisis en cochant un article en
magasin. C'est un ordre de grandeur, présenté comme tel. Il ne connaît ni le montant réel des
tickets, ni ce qui n'est pas de l'alimentaire, ni le restaurant. Le module 23 en fait un vrai suivi.

### 1.3 Idées 2 et 4 — plusieurs foyers, tickets de caisse

Rien n'existe : Bouffe est aujourd'hui **un seul foyer par installation** (tous les réglages, recettes
et stocks sont communs à tous les comptes). C'est la transformation la plus profonde de la V3 :
elle touche presque toutes les tables (§5, §7.2), d'où un lot de fondations à part (lot 24).

### 1.4 Mise en ligne

Le lot 16 a préparé l'hébergement (configuration par `.env`, HTTPS forcé derrière un proxy,
sauvegarde / restauration, `bouffe:deploy`, diagnostic). Restent : les contraintes propres au
**mutualisé** (tâches planifiées, base MySQL au lieu de MariaDB, pas d'installation de logiciel),
la **double authentification** (20.5, prévue mais non réalisée) et la **migration des données**.

---

## 2. Principes directeurs de la V3

1. **Un foyer ne voit rien d'un autre par défaut.** Tout partage est un choix explicite, réversible,
   visible par les deux foyers. Les contraintes alimentaires (données de santé) ne sortent jamais
   d'un foyer sans l'accord de la personne concernée.
2. **Moins de saisie, pas moins de contrôle.** Ce que Bouffe déduit (retrait du stock, lecture d'un
   ticket) passe par un écran de relecture, puis devient automatique quand on lui fait confiance.
3. **Chiffres honnêtes.** Un budget ou une analyse dit toujours sur quoi il repose (« 14 tickets,
   dont 2 saisis à la main ») ; pas de chiffre inventé pour combler un trou.
4. **Le mutualisé comme cible.** Rien qui exige un processus permanent, un logiciel installé ou une
   tâche à la minute. Tout ce qui fonctionne chez OVH fonctionne aussi sous Wamp.
5. **Un service externe n'est jamais indispensable.** Sans clé d'OCR, sans réseau ou si le quota est
   atteint, on saisit à la main — comme pour Open Food Facts au lot 18.

---

## 3. Vue d'ensemble

| Module | Objectif | Priorité | Dépend de |
|---|---|---|---|
| **22 — Stock toujours juste** | Le stock suit les repas sans qu'on y pense | ★★★ | — |
| **23 — Dépenses et budget réel** | Savoir ce qu'on dépense vraiment, par poste, face au budget | ★★★ | — |
| **24 — Tickets de caisse lus automatiquement** | Une photo du ticket remplit la dépense, les prix et le stock | ★★ | 23 |
| **25 — Plusieurs foyers** | Accueillir famille et amis, chacun chez soi | ★★★ (avant d'inviter) | — |
| **26 — Entre foyers** | Partager recettes, planning, repas communs, surplus | ★★ | 25, 27 |
| **27 — Mise en ligne chez OVH** | Bouffe disponible partout, PC éteint, sécurisé | ★★★ | 25 conseillé |

---

## Module 22 — Stock toujours juste

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 22.1 ✅ | **Clôture des repas passés** | Chaque matin, les repas de la veille non marqués sont proposés dans la cloche et sur l'accueil : « Hier soir : chili — mangé ? » → **Mangé** (retrait du stock), **Pas mangé** (le repas passe en « non fait », rien n'est retiré, il est proposé de le replacer) ou **Mangé autre chose** ; réglage : demander (défaut) ou clôturer automatiquement comme mangé après N jours (R23) | ★★★ | M | 📌 🔁 |
| 22.2 ✅ | **Retrait jamais perdu** | Fermer la fenêtre de retrait sans valider laisse le repas « à régulariser » (pastille), au lieu de ne rien faire en silence ; en mode automatique, une notification « 5 articles retirés — Annuler » remplace la fenêtre | ★★★ | S | 🔁 |
| 22.3 ✅ | **Stock réservé** | Les repas planifiés réservent ce qu'ils utiliseront (R24) : le stock affiche « 6 œufs · 3 réservés (quiche jeudi) » ; « Que cuisiner ? » ne propose que le disponible ; la liste de courses tient compte des réservations ; un conflit (deux repas pour les mêmes œufs) est signalé sur le planning | ★★★ | M | 📌 |
| 22.4 ✅ | **Consommations hors repas** | Depuis le bouton +, « J'ai utilisé… » : « 2 œufs, un peu de lait » analysé comme la saisie rapide (R14) ; articles du quotidien réglables en « consommation régulière » (1 l de lait tous les 3 jours) pour un retrait estimé et discret | ★★ | S | ✨ |
| 22.5 ✅ | **Écarts signalés** | Quand une quantité devient incohérente (retrait supérieur au stock, article jamais retiré depuis longtemps, date dépassée de loin), Bouffe propose une vérification ciblée de 3 à 5 articles plutôt qu'un inventaire complet | ★★ | S | ✨ |
| 22.6 ✅ | **Retrait au fil du mode cuisine** | Option : cocher un ingrédient dans le mode cuisine le retire aussitôt (utile quand on cuisine « à peu près » la recette) ; « Terminé » ne retire que le reste | ★ | S | 🔁 |

---

## Module 23 — Dépenses et budget réel

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 23.1 ✅ | **Saisie d'une dépense** | En 3 champs depuis le bouton + : montant, magasin (ou restaurant), date — catégorie proposée d'après le lieu ; photo du ticket facultative ; « qui a payé » facultatif | ★★★ | S | 📌 |
| 23.2 ✅ | **Postes de budget** | Postes par défaut : **Courses alimentaires**, **Droguerie et maison**, **Restaurant**, **À emporter / livraison**, **Midi au travail**, **Boissons** ; chacun avec un budget mensuel (ou aucun) ; postes renommables, ajoutables, archivables | ★★★ | S | 📌 |
| 23.3 ✅ | **Ticket mixte** | Un ticket de supermarché peut être réparti entre postes (« 72 € dont 12 € de droguerie ») ; avec la lecture automatique (24), la répartition est proposée ligne à ligne | ★★ | S | ✨ |
| 23.4 ✅ | **Restaurant lié au planning** | Un repas libre « Restaurant » du planning propose d'enregistrer la dépense ; à l'inverse, une dépense restaurant peut remplir la case du planning ; coût par personne | ★★ | S | 📌 |
| 23.5 ✅ | **Tableau de bord du mois** | Par poste : dépensé / budget, **rythme** (où l'on devrait en être à cette date du mois), **projection** de fin de mois (R25), reste à dépenser par semaine ; alerte douce à 80 % et au dépassement projeté | ★★★ | M | 📌 |
| 23.6 ✅ | **Analyses** | 12 derniers mois par poste ; par magasin (fréquence, panier moyen) ; coût moyen d'un repas **maison** (courses ÷ repas mangés à la maison) vs **restaurant** par personne ; part du budget jetée (valeur du gaspillage, stats du lot 18 × prix) ; écart entre coût prévu du planning (R17) et dépense réelle | ★★ | M | 📌 ✨ |
| 23.7 ✅ | **Période budgétaire** | Mois civil par défaut, ou « du 25 au 24 » (jour de paie) ; changement de budget daté (l'historique reste juste) | ★★ | S | ✨ |
| 23.8 ✅ | **Qui a payé** | Facultatif : total payé par chaque membre sur la période et équilibre (« Monique a avancé 46 € de plus ce mois-ci ») — sans remboursement ni lien bancaire | ★ | S | ✨ |
| 23.9 ✅ | **Export** | Dépenses d'une période en CSV (tableur) | ★ | S | ✨ |

Le budget unique du lot 17 devient le poste « Courses alimentaires » ; les prix saisis en magasin
restent utilisés pour les **prix** (R17) mais ne comptent plus comme **dépenses** dès qu'un ticket
de la même course existe (pas de double compte, R26).

> **Réalisé au lot 22** ([détail](lots/lot-22-depenses-budget.md)). Écarts : la photo du ticket et
> la répartition proposée ligne à ligne arrivent avec la lecture des tickets (lot 23) ; la liste des
> dépenses, ses filtres et l'export sont sur `/budget` (pas de page `/budget/depenses` séparée) ;
> « Enregistrer la dépense » est proposé sur **tout** repas libre passé, pas seulement ceux nommés
> « Restaurant » ; le coût d'un repas à la maison n'est affiché que si assez de repas ont été
> marqués mangés, et l'écart planning / courses seulement si tous les ingrédients planifiés ont un
> prix — sinon le chiffre serait faux.

---

## Module 24 — Tickets de caisse lus automatiquement 🌐 💶

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 24.1 ✅ | **Photo ou PDF du ticket** | Depuis le téléphone (appareil photo) ou un fichier (ticket dématérialisé en PDF) ; recadrage et réduction avant envoi ; plusieurs photos pour un ticket long | ★★★ | M | 📌 |
| 24.2 ✅ | **Lecture** | Envoi au service choisi (§7.3) qui renvoie magasin, date, total, lignes (libellé, quantité, prix unitaire, montant), remises, consignes ; contrôle de cohérence (R27) | ★★★ | M | 📌 |
| 24.3 ✅ | **Écran de relecture** | Une ligne par article, rapprochée d'un ingrédient (R28) avec un indicateur de confiance ; corrections en un geste ; lignes non alimentaires marquées « hors stock » ; total vérifié en haut | ★★★ | M | 📌 |
| 24.4 ✅ | **Ce que le ticket remplit** | En un « Valider » : la **dépense** (23), répartie par poste ; les **prix** (R17, par magasin) ; le **stock** via le rangement assisté du lot 18 (emplacements et dates apprises) ; les articles de la **liste de courses** correspondants cochés | ★★★ | M | 📌 |
| 24.5 ✅ | **Apprentissage par magasin** | « LAIT DEMI ECR UHT 1L » chez Cactus = Lait demi-écrémé, 1 l : retenu une fois, reconnu ensuite sans service externe (R28) ; au bout de quelques tickets, la plupart des lignes sont reconnues d'office | ★★★ | S | ✨ |
| 24.6 ✅ | **Achats imprévus et oubliés** | Après validation : « 3 articles achetés hors liste », « 2 articles de la liste non achetés — les garder pour la prochaine fois ? » | ★★ | S | ✨ |
| 24.7 ✅ | **Suivi des coûts** | Nombre de lectures du mois et coût estimé dans Paramètres ; plafond mensuel réglable (défaut 50 lectures) au-delà duquel on passe en saisie manuelle | ★★ | S | ✨ |
| 24.8 ✅ | **Conservation** | Photo du ticket gardée 12 mois (réglable), puis supprimée ; les lignes et montants restent ; les derniers chiffres d'une carte bancaire éventuellement lus ne sont jamais enregistrés | ★★ | S | ✨ |

> **Réalisé au lot 23** ([détail](lots/lot-23-tickets-de-caisse.md)). Écarts : le recadrage se fait
> par quatre curseurs et une rotation (pas de détection automatique des bords) ; le stock est rempli
> directement à la validation, avec les emplacements et dates appris du lot 18, sans repasser par
> l'écran « Ranger les courses » ; un prix n'est relevé que si la quantité payée est connue (poids,
> contenu du paquet ou pièces) ; « garder pour la prochaine fois » place les articles dans « Quand je
> passe » (15.8) ; les tests utilisent des réponses **simulées** d'après la documentation des
> services — les 20 tickets réels du §7.4 restent à constituer avec `php artisan bouffe:ticket`.

---

## Module 25 — Plusieurs foyers

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 25.1 ✅ | **Foyers** | Un foyer = ses membres, recettes, planning, stock, listes, magasins, budget, invités et réglages ; les données actuelles deviennent le foyer « Pierre et Monique » sans perte | ★★★ | L | 📌 |
| 25.2 ✅ | **Invitation** | Pierre (administrateur de l'installation) crée un foyer et envoie un lien d'invitation à usage unique, valable 7 jours ; la personne invitée choisit son mot de passe ; elle peut ensuite inviter les membres de son propre foyer | ★★★ | S | 📌 |
| 25.3 ✅ | **Membres et rôles par foyer** | Les rôles du lot 15 (modification / consultation et courses) deviennent **par foyer**, plus « responsable du foyer » (inviter, retirer, supprimer le foyer) | ★★★ | S | 🔁 |
| 25.4 ✅ | **Plusieurs foyers pour une personne** | Un enfant parti de la maison, des grands-parents : un compte peut appartenir à plusieurs foyers et passer de l'un à l'autre (sélecteur dans le menu) | ★★ | S | ✨ |
| 25.5 ✅ | **Isolation stricte** | Chaque écran, recherche, export, sauvegarde et notification ne montre que le foyer actif ; vérifié par des tests systématiques sur chaque table (R29) | ★★★ | M | ✨ |
| 25.6 ✅ | **Catalogue commun** | Unités, ingrédients, saisons et nutrition sont **communs à l'installation** (une recette partagée se comprend chez tous) ; ce qui dépend de la maison — mode de stock, durée de conservation, emplacement, prix de référence, rayon — est **réglé par foyer** (R30) | ★★★ | M | ✨ |
| 25.7 ✅ | **Administration de l'installation** | Pour Pierre : liste des foyers, nombre de comptes, place occupée par les photos, dernier accès ; désactiver un foyer ; aucun accès au contenu des autres foyers depuis cet écran | ★★ | S | ✨ |
| 25.8 ✅ | **Données du foyer** | Export complet d'un foyer (JSON + photos), départ d'un membre, suppression d'un foyer (avec délai de 30 jours) | ★★ | S | 🔁 |

> **Réalisé au lot 24** ([détail](lots/lot-24-plusieurs-foyers.md)). Écarts : le lien d'invitation
> s'affiche pour être copié ou envoyé (bouton « E-mail » du téléphone) — Bouffe n'envoie pas d'e-mail
> lui-même ; un responsable peut aussi créer directement un compte avec mot de passe ; les
> contraintes alimentaires d'une personne sont notées **par foyer** (celles de Pierre chez lui ne se
> voient pas chez Léo) en attendant le partage consenti du lot 26 ; la sauvegarde et le diagnostic,
> qui concernent toute l'installation, sont réservés à l'administrateur.

---

## Module 26 — Entre foyers

Deux foyers **reliés** (l'un invite, l'autre accepte) peuvent partager, chacun réglant ce qu'il ouvre :

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 26.1 ✅ | **Recettes partagées** | Visibilité par recette : **privée** (défaut), **foyers reliés**, **toute l'installation** ; ou « tout mon carnet est visible par… » ; les recettes des autres apparaissent dans un onglet **Recettes des proches**, avec leur foyer d'origine | ★★★ | M | 📌 |
| 26.2 ✅ | **Planifier ou copier** | Une recette d'un proche se planifie telle quelle (elle reste chez lui) ou se **copie** dans son carnet pour la modifier ; la copie garde son origine et signale les mises à jour de l'original (R31) | ★★★ | M | 📌 |
| 26.3 ✅ | **Avis entre foyers** | Notes et commentaires sur une recette partagée, visibles par son foyer d'origine (« Mamie : ajouter une pincée de muscade ») | ★★ | S | ✨ |
| 26.4 ✅ | **Planning partagé** | Chaque foyer garde son planning ; il peut l'ouvrir **en lecture** (« qu'est-ce qu'on mange chez les parents dimanche ? ») ou **en écriture** à un foyer relié (parents qui gardent les enfants la semaine) | ★★ | M | 📌 |
| 26.5 ✅ | **Repas commun** | Une réception (module 21) peut inviter un **foyer entier** : chaque foyer choisit ce qu'il apporte (« qui apporte quoi »), les ingrédients vont dans **sa** liste de courses, le rétroplanning de chacun ne montre que ses plats | ★★★ | M | ✨ |
| 26.6 ✅ | **Contraintes tenues par la personne** | Quand un membre d'un foyer relié est invité, ses allergies et régimes viennent de **son** profil (tenu par lui, R29) au lieu d'une fiche invité recopiée — seulement s'il a accepté de les partager | ★★★ | S | ✨ |
| 26.7 ✅ | **Surplus à donner** | « J'ai 1 kg de courgettes / une part de lasagnes à donner avant jeudi » : annonce visible des foyers reliés, réservation en un geste, retrait du stock du donneur à la remise | ★★ | S | ✨ |
| 26.8 ✅ | **Liste de courses groupée** | Pour un drive ou un gros magasin : un foyer ajoute des articles pour un autre dans une liste commune, avec « pour qui » et le montant à se rembourser | ★ | M | ✨ |
| 26.9 ✅ | **Carnet familial** | Livre de recettes (PDF ou impression) réunissant des recettes de plusieurs foyers : sommaire, une recette par page, photos, nom de l'auteur — les recettes de grand-mère enfin rassemblées | ★★ | M | ✨ |
| 26.10 ✅ | **Fil des proches** | Quelques lignes discrètes sur l'accueil : nouvelle recette partagée, avis reçu, surplus proposé ; désactivable | ★ | S | ✨ |

> **Réalisé au lot 26** ([détail](lots/lot-26-entre-foyers.md)). Page **Proches** : liens à usage
> unique acceptés par un responsable de l'autre foyer, réglages par foyer (tout le carnet, planning
> fermé · lecture · écriture). Écarts : les invitations, surplus et avis ne déclenchent ni
> notification ni e-mail — ils apparaissent dans le fil de l'accueil, dans Proches et, pour les
> invitations, dans Réceptions ; en écriture, le planning d'un proche accepte l'ajout (recette de
> son carnet ou texte libre) et le retrait, pas le déplacement ; le carnet familial s'imprime (ou
> s'enregistre en PDF) depuis le navigateur ; la liste groupée calcule le remboursement d'après les
> prix saisis et signale les prix manquants. Une recette supprimée ne fait pas disparaître les repas
> des proches qui l'avaient planifiée : ils gardent son nom.

---

## Module 27 — Mise en ligne chez OVH (hébergement mutualisé)

| # | Fonctionnalité | Détail | Priorité | Taille | Origine |
|---|---|---|---|---|---|
| 27.1 ✅ | **Compatibilité MySQL** | Les bases du mutualisé OVH sont en MySQL ; la suite de tests tourne sur SQLite, MariaDB **et MySQL 8** ; correction des écarts éventuels (tris, JSON, index) | ★★★ | S | ✨ |
| 27.2 ✅ | **Installation guidée** | Guide pas à pas (doc 10) : offre Pro, dossier racine sur `public/`, PHP 8.4 via `.ovhconfig`, base, `.env`, `composer install` en SSH, certificat HTTPS, domaine | ★★★ | S | 📌 |
| 27.3 ✅ | **Tâches planifiées sur le mutualisé** | Le cron OVH ne tourne qu'**une fois par heure** : ajout d'une adresse protégée `/taches/{jeton}` appelée toutes les 5 minutes par un service externe gratuit (ex. cron-job.org), avec le cron OVH horaire en secours ; sans service externe, les rappels partent avec au plus une heure de retard | ★★★ | S | ✨ |
| 27.4 ✅ | **Double authentification** | Code à usage unique (application type Google Authenticator, Aegis…), codes de secours, obligatoire pour les responsables de foyer et l'administrateur (20.5 restant) | ★★★ | M | 📌 (20.5) |
| 27.5 ✅ | **Sessions et alertes** | Appareils connectés visibles et révocables ; e-mail « nouvelle connexion depuis… » ; journal des connexions ; limitation des tentatives étendue aux invitations et à la réinitialisation du mot de passe | ★★★ | S | 🔁 |
| 27.6 ✅ | **Mot de passe oublié** | Réinitialisation par e-mail (inutile en local, indispensable en ligne avec d'autres foyers) | ★★★ | S | ✨ |
| 27.7 ✅ | **En-têtes de sécurité** | Politique de contenu (CSP), HSTS, cookies sécurisés, protection des téléversements (type et taille vérifiés, photos ré-encodées — déjà le cas pour les recettes) | ★★ | S | ✨ |
| 27.8 ✅ | **Migration des données** | `bouffe:backup` sous Wamp → `bouffe:restore` chez OVH (MariaDB → MySQL, photos comprises), puis vérification par le diagnostic ; le PC reste une copie de secours | ★★★ | S | 🔁 |
| 27.9 ✅ | **Sauvegardes en ligne** | Sauvegarde quotidienne (tâche horaire) conservée 14 jours sur l'hébergement, et téléchargement hebdomadaire proposé (e-mail avec lien) pour une copie hors d'OVH | ★★★ | S | 🔁 |
| 27.10 ✅ | **Déploiement** | Code sur un dépôt Git privé (Q27) ; mise à jour en SSH : `git pull` puis `php artisan bouffe:deploy` (mode maintenance pendant la migration) ; la version locale Wamp devient l'environnement de test | ★★ | S | 🔁 |
| 27.11 ✅ | **Surveillance** | Adresse `/sante` (base, stockage, dernière tâche) surveillée par un service gratuit ; e-mail à l'administrateur sur erreur grave (limité à un par heure) | ★★ | S | ✨ |
| 27.12 ✅ | **Informations personnelles** | Page « Vos données » : ce qui est stocké, où (OVH, France), qui y a accès, services externes appelés (OCR, notifications), export et suppression — ce qu'attendent légitimement des proches à qui l'on ouvre l'application | ★★ | S | ✨ |

Les options B (tunnel) et le PC comme serveur (lots 16 à 20) restent utilisables pour le
développement ; la PWA, les notifications et le mode magasin fonctionnent tels quels en ligne.

> **Réalisé au lot 25** ([détail](lots/lot-25-en-ligne-ovh.md), guide :
> [10-mise-en-ligne-ovh.md](10-mise-en-ligne-ovh.md)). Suite de tests verte sous MySQL 8.0 (un seul
> écart corrigé : suppression avec sous-requête sur la même table dans la fusion d'ingrédients) et
> restauration MariaDB → MySQL vérifiée. Écarts et compléments : une page **Mon compte** réunit
> mot de passe, double authentification, appareils, journal et suppression du compte ; une page
> **Paramètres → Mise en ligne** (administrateur) montre l'adresse des tâches, la surveillance et
> l'état de la sécurité ; l'e-mail « sauvegarde de la semaine » contient un lien qui demande d'être
> connecté (pas de pièce jointe) ; la sauvegarde emporte désormais aussi les tickets de caisse. Les
> écrans de l'espace client OVH n'ont pas pu être vérifiés d'ici : le guide les marque « (à
> vérifier) ».

---

## Idées complémentaires (proposées, à placer selon l'intérêt)

| # | Idée | Pourquoi | Priorité | Taille | Rattachement |
|---|---|---|---|---|---|
| C1 ✅ | **Comparateur de prix entre magasins** | Les tickets lus alimentent les prix par magasin : « le beurre est 18 % moins cher chez X » ; la liste peut être répartie entre deux magasins | ★★ | M | après 24 |
| C2 ✅ | **Inflation personnelle** | Évolution du prix de **votre** panier habituel sur 12 mois, et alertes sur les hausses marquées | ★ | S | après 24 |
| C3 ✅ | **Recette depuis une photo** | Le même service lit une page de livre ou une fiche manuscrite et remplit l'écran de relecture de l'import (lot 13) — idéal pour le carnet familial (26.9) | ★★ | S | après 24 |
| C4 ✅ | **Agenda du planning (ICS)** | Adresse d'abonnement pour voir les repas dans l'agenda du téléphone (14.10, jamais réalisé) ; utile aussi entre foyers | ★★ | S | avec 26 |
| C5 ✅ | **Gaspillage en euros** | Valeur de ce qui a été jeté ce mois-ci (stats du lot 18 × prix) sur le tableau de bord du budget | ★★ | S | avec 23 |
| C6 | **Langues** | Interface en allemand et en anglais pour des proches non francophones (Luxembourg) : chaque texte de l'application à extraire | ★ | L | plus tard |
| C7 ✅ | **Dépense récurrente** | Cantine, panier bio hebdomadaire, abonnement : saisie une fois, comptée chaque mois | ★ | S | avec 23 |

> **C1 et C2 réalisés au lot 27** ([détail](lots/lot-27-prix-magasins.md)). Page **Prix et magasins**
> (`/prix`, depuis Budget) : magasins comparés au magasin des listes sur les produits relevés dans les
> deux (au moins 3), produit par produit du moins cher au plus cher ; sur une liste rangée par
> magasin, « Moins cher ailleurs » propose de réserver des articles à un autre magasin (économie
> estimée), puis « Chez Lidl » et « Tout reprendre ici ». Évolution : indice du panier (pondéré par la
> dépense, même produit dans le même magasin), produits suivis, hausses de 15 % ou plus d'un relevé au
> suivant, aussi en bloc masquable sur l'accueil. Écarts : pas d'alerte par notification (bloc de
> l'accueil et page seulement) ; les prix de plus de 6 mois ne servent plus à comparer ; l'indice
> n'est donné qu'à partir de 3 produits suivis depuis les 3 premiers mois.

---

## 4. Règles de gestion

### R23 — Clôture des repas passés ✅ (lot 21)

- Chaque jour à 7 h (tâche planifiée), les repas « recette » ou « restes » de la veille et d'avant,
  ni mangés ni marqués « non fait », sont **à clôturer**.
- Mode **Demander** (défaut) : ils apparaissent dans la cloche et sur l'accueil jusqu'à réponse.
  « Mangé » applique le retrait (R9) selon le réglage du foyer ; « Pas mangé » les passe en
  **non fait** (plus d'alerte, non comptés dans les statistiques « déjà servi »), avec une
  proposition de replacement dans les 7 jours.
- Mode **Automatique après N jours** (N = 2 par défaut) : sans réponse, le repas est marqué mangé
  et le retrait appliqué ; un résumé hebdomadaire liste ces clôtures, chacune annulable.
- Les repas plus anciens que 14 jours ne sont jamais clôturés automatiquement.

### R24 — Stock réservé ✅ (lot 21)

- Un repas planifié à venir (aujourd'hui compris, non mangé) **réserve** les quantités qu'il
  retirera (même calcul que R9, sous-recettes et variantes comprises).
- Les réservations sont attribuées **dans l'ordre des repas** : le premier repas se sert d'abord.
  Un repas dont les besoins dépassent le disponible est **en conflit** (icône sur le planning).
- Disponible = stock − réservations. « Que cuisiner ? » (R10) et la couverture de la liste de
  courses (R8) utilisent le disponible ; l'écran du stock montre les deux.
- Les réservations sont **calculées**, jamais enregistrées : déplacer ou supprimer un repas les
  met à jour sans rien à nettoyer.
- Mode présence (sel, pâtes) : pas de réservation.

> **Réalisé au lot 21** ([détail](lots/lot-21-stock-toujours-juste.md)). Écarts : « Mangé autre chose » n'a pas de bouton dédié (« Pas mangé » puis le planning suffisent) ; la page « Que cuisiner ? » garde sa propre réservation sur 7 jours (lot 10), les autres écrans utilisent 14 jours.

### R25 — Rythme et projection du budget ✅ (lot 22)

- **Rythme** d'un poste = budget × (jours écoulés ÷ jours de la période).
- **Projection** = dépensé + (moyenne des dépenses des 3 périodes précédentes sur les jours
  restants, ramenée au prorata) ; tant qu'il n'y a pas 3 périodes d'historique : dépensé ÷ jours
  écoulés × jours de la période, avec la mention « estimation fragile ».
- Alerte douce à 80 % du budget dépensé, et quand la projection dépasse le budget de plus de 5 %.
- Les dépenses datées dans le futur sont refusées ; une dépense modifiée recalcule sa période.

### R26 — Pas de double compte ✅ (lot 22)

- Une dépense ne vient que d'**une** source : saisie, ticket lu, ou repas « restaurant ».
- Les prix cochés en magasin (15.7) alimentent les prix (R17) mais ne sont comptés dans le budget
  que pour les courses **sans ticket** : dès qu'un ticket est rattaché à la même liste (même magasin,
  même jour ou lendemain), les prix cochés de cette liste sont retirés du calcul.
- Un ticket en double (même magasin, même date, même total) est signalé avant enregistrement.

> **Réalisé au lot 22.** Le ticket se rattache à la liste explicitement (liste proposée d'office :
> articles cochés le jour même ou la veille, même magasin ou sans magasin, sans ticket déjà
> rattaché) ; un doublon est signalé une fois, « Enregistrer quand même » le confirme.

### R27 — Lecture d'un ticket ✅ (lot 23)

- Le service renvoie une structure (§7.3) ; Bouffe vérifie que la somme des lignes moins les
  remises égale le total à 0,05 € près. Sinon : « Le ticket semble incomplet ou mal lu » et les
  lignes douteuses sont mises en évidence ; on peut toujours valider en corrigeant le total.
- Les lignes de **consigne**, **remise**, **bon d'achat**, **sac** et **TVA** sont reconnues et ne
  vont jamais au stock ; une remise est affectée à la ligne qui la précède quand c'est explicite.
- Les quantités « 2 × 1,29 » et les poids « 0,532 kg × 3,99 €/kg » donnent la quantité achetée
  (2 pièces, 532 g), utilisée pour le stock et pour le prix à l'unité (R17).
- Rien n'est enregistré avant « Valider ».

### R28 — Libellé de ticket → ingrédient ✅ (lot 23)

1. Libellé normalisé (majuscules, sans accents, sans nombres de fin) cherché dans les
   **correspondances apprises** du magasin, puis de tous les magasins ;
2. sinon, recherche sur les noms et alias d'ingrédients (R22) et sur les produits scannés (16.1) ;
3. sinon, proposition du service de lecture (qui renvoie un nom lisible : « lait demi-écrémé ») ;
4. sinon, « non reconnu » : choisir un ingrédient, créer un article « hors stock », ou ignorer.

Chaque choix confirmé à l'écran de relecture est **appris** (libellé, magasin, ingrédient, unité et
contenu du paquet : « 1 l », « 6 œufs »). Une correspondance corrigée trois fois est remplacée.

> **Réalisé au lot 23.** Écart : une correspondance corrigée est remplacée **dès la première
> correction** (attendre trois corrections laissait la même erreur revenir deux fois) ; le nombre de
> confirmations sert à départager les magasins. Une remise est rattachée à l'article qu'elle suit
> immédiatement ; sinon elle reste une ligne à part, comptée dans les courses.

### R29 — Isolation des foyers ✅ (lot 24)

- Toute donnée propre à un foyer porte son identifiant ; aucune requête ne lit une table de foyer
  sans filtre (portée globale, vérifiée par un test qui parcourt tous les modèles).
- Une donnée d'un autre foyer n'est lisible qu'à travers un **partage** explicite (recette,
  planning, repas commun, surplus) et seulement dans le périmètre de ce partage.
- Les **contraintes alimentaires** d'une personne ne sont communiquées à un autre foyer que si elle
  l'a accepté dans son profil, et seulement pour un repas où elle est invitée.
- Les sauvegardes et exports d'un foyer ne contiennent que ses données ; la sauvegarde complète
  de l'installation est réservée à l'administrateur.

### R30 — Catalogue commun, réglages par foyer ✅ (lot 24)

- Unités, ingrédients, alias, saisons, correspondance nutritionnelle : **communs**. Un ingrédient
  créé par un foyer est visible de tous (nom seulement) ; la fusion (R22) est réservée à
  l'administrateur quand l'ingrédient est utilisé par plusieurs foyers.
- Mode de stock, conservation, emplacement, rayon, produit de base, prix de référence, stock
  minimum : **par foyer**, avec les valeurs actuelles comme valeurs par défaut.

> **Réalisé au lot 24.** Un test parcourt tous les modèles : chacun est propre à un foyer (et porte
> `household_id`), enfant d'un autre, ou commun ; un modèle nouveau non classé fait échouer la suite.
> Les rayons et les unités sont communs (comme les ingrédients) ; l'ordre des rayons **par magasin**
> reste propre au foyer. Avec plusieurs foyers, le catalogue commun (noms, unités, saisons, nutrition,
> fusion, suppression) ne se modifie que par l'administrateur ; les réglages de stock et de prix
> restent libres dans chaque foyer.

### R31 — Copie d'une recette partagée ✅ (lot 26)

- La copie reprend titre, portions, temps, ingrédients (catalogue commun : rien à convertir),
  étapes, photo et sous-recettes (copiées aussi si elles ne sont pas visibles du foyer).
- Elle garde l'**origine** (foyer, recette, date) ; si l'original change, un bandeau propose de voir
  les différences et de les reprendre, sans jamais écraser les modifications locales.
- Un original supprimé ou dé-partagé ne supprime pas les copies.

> **Réalisé au lot 26.** Les différences se comparent en trois parties (titre et temps, ingrédients,
> étapes) ; chacune se reprend séparément. Les sous-recettes sont toujours copiées (ou reprises si
> déjà copiées), puisqu'une recette ne peut utiliser que des sous-recettes de son propre carnet ; les
> catégories sont reprises quand une catégorie du même nom existe.

---

## 5. Modèle de données (ajouts)

| Table / colonne | Contenu | Module |
|---|---|---|
| `planned_meals.skipped_at`, `closed_automatically`, `stock_state` (réalisé au lot 21 : `cooked_at` suffit pour « mangé », pas de colonne `status`) | Clôture des repas, retrait en attente | 22.1, 22.2, R23 |
| `stock_usage_rules` (ingredient, quantité, tous les N jours) ✅ + `stock_items.checked_at` (dernière vérification) | Consommations régulières, écarts | 22.4, 22.5 |
| `expenses` (household_id, spent_on, amount, place_type, store_id / label, paid_by, receipt_id, planned_meal_id, note, source) | Dépenses | 23 |
| `expense_splits` (expense_id, budget_category_id, amount) | Répartition d'un ticket mixte | 23.3 |
| `budget_categories` (household_id, name, color, sort_order, archived_at) + `budget_amounts` (category_id, amount, valid_from) | Postes et budgets datés | 23.2, 23.7 |
| `receipts` (household_id, photo_path, status, provider, raw_result JSON, total, store_id, purchased_at, read_at, pages, cost_estimate) | Tickets lus | 24 |
| `receipt_lines` (receipt_id, label, quantity, unit_price, amount, discount, kind, ingredient_id, unit_id, pack_quantity, confidence, stock_item_id, shopping_list_item_id) | Lignes | 24.3 |
| `receipt_label_mappings` (store_id NULL, normalized_label, ingredient_id, unit_id, pack_quantity, confirmations) | Apprentissage | R28 |
| `households` (name, created_by, disabled_at) + `household_user` (household_id, user_id, role) | Foyers et rôles | 25 |
| `household_id` sur ~35 tables : recettes, planning, stock, listes, magasins, invités, rappels, réglages… | Isolation | R29 |
| `household_ingredient_settings` (household_id, ingredient_id, stock_mode, shelf_life…, aisle_id, reference_price…) | Réglages par foyer | R30 |
| `invitations` (household_id, email, token_hash, role, expires_at, accepted_at) | Invitations | 25.2 |
| `household_links` (household_a, household_b, status, share_recipes, share_planning: none/read/write) | Foyers reliés | 26 |
| `recipes.visibility` (`private` · `linked` · `instance`) + `origin_recipe_id`, `origin_household_id`, `origin_synced_at` | Partage et copies | 26.1, R31 |
| `meal_occasion_households` (occasion_id, household_id, status) + `planned_meals.brought_by_household_id` | Repas commun | 26.5 |
| `users.share_restrictions` (booléen) + `user_restrictions` | Contraintes tenues par la personne | 26.6 |
| `surplus_offers` (household_id, stock_item_id / label, quantity, until, reserved_by_household_id, handed_at) | Surplus | 26.7 |
| `users.two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` ; `login_events` | Sécurité | 27.4, 27.5 |

Règles d'intégrité : un foyer a toujours au moins un responsable ; supprimer un foyer supprime
ses données après 30 jours, jamais les recettes déjà copiées par d'autres ; un ingrédient du
catalogue commun utilisé par un autre foyer ne peut pas être supprimé.

---

## 6. Écrans et routes prévus

| Route | Écran | Lot |
|---|---|---|
| `/` (bloc « À régulariser ») | Repas d'hier à clôturer | 21 |
| `/stock` | Colonnes « en stock » / « réservé » / « disponible » | 21 |
| `/budget` | Tableau de bord du mois, postes, projection | 22 |
| `/budget/depenses` | Liste, filtres, export | 22 |
| `/budget/analyses` | 12 mois, magasins, maison vs restaurant | 22 |
| `/tickets` · `/tickets/nouveau` | Tickets du mois ; photo → lecture → relecture | 23 ✅ |
| `/tickets/{ticket}` | Relecture, puis ticket validé, ses lignes, où elles sont allées | 23 ✅ |
| `/parametres/foyer` | Mon foyer, membres, rôles, invitations, export, suppression | 24 ✅ |
| `/invitation/{jeton}` | Accepter une invitation (avec ou sans compte) | 24 ✅ |
| `/administration` | Foyers de l'installation (administrateur) | 24 ✅ |
| `/proches` | Foyers reliés, ce que je partage avec chacun | 26 ✅ |
| `/recettes?source=proches` | Recettes des proches | 26 ✅ |
| `/proches/{foyer}/planning` | Planning partagé | 26 ✅ |
| `/surplus` | Surplus proposés et réservés | 26 ✅ |
| `/proches/repas/{id}` · `/proches/listes/{id}` · `/carnet-familial` · `/agenda/{jeton}.ics` | Repas commun (foyer invité), liste groupée, carnet familial, agenda | 26 ✅ |
| `/compte` (prévu `/parametres/securite`) | Double authentification, appareils connectés | 25 ✅ |
| `/taches/{jeton}` · `/sante` | Tâches planifiées externes, surveillance | 25 |
| `/prix` | Prix et magasins : comparateur (C1), évolution de nos prix (C2) | 27 ✅ |

---

## 7. Choix techniques

### 7.1 Hébergement mutualisé OVH : ce que cela implique

| Contrainte | Constat (documentation et offres OVH, septembre 2026) | Réponse |
|---|---|---|
| **Offre** | L'offre **Pro** (gamme Business) inclut **SSH**, Git, 10 bases de 2 Go, 250 Go ; 1,99 € HT/mois la première année en promotion, puis 9,99 € HT/mois | Offre Pro minimum : sans SSH, pas de `composer install` ni de `php artisan` |
| **PHP** | Versions 8.2 à 8.5 disponibles, choix par le fichier `.ovhconfig` | PHP 8.4 comme aujourd'hui |
| **Base** | MySQL (et non MariaDB) | Tests MySQL 8 ajoutés (27.1) |
| **Tâches planifiées** | **Une exécution par heure au plus**, durée maximale 60 minutes, minute non choisie, désactivée après 10 échecs | Adresse `/taches/{jeton}` appelée par un service externe (27.3) ; les tâches Bouffe durent quelques secondes |
| **Logiciels** | Aucun logiciel à installer (pas de Tesseract, pas de processus permanent) | OCR par service externe (§7.3), files d'attente en mode synchrone (déjà le cas) |
| **Dossier racine** | Réglable par site (multisite) | Pointé sur `src/public` |
| **HTTPS** | Certificat Let's Encrypt inclus | Débloque PWA, caméra, notifications sans tunnel |
| **E-mails** | Boîtes incluses dans l'offre | Récapitulatif (19.3), invitations, alertes via le SMTP de la boîte OVH |

Si les limites du mutualisé devenaient gênantes (tâches, performances, OCR local), le passage à un
VPS ne changerait rien au code : c'est le principe 4.

### 7.2 Multi-foyer : une base, une colonne

- **Une seule base**, colonne `household_id` sur chaque table propre à un foyer, portée globale
  Eloquent (`BelongsToHousehold`) remplie automatiquement à la création, foyer actif en session.
- Écarté : une base par foyer (le mutualisé est limité à 10 bases, et le partage entre foyers
  deviendrait des requêtes entre bases) ; une installation par foyer (plus de partage possible).
- Les réglages (`settings`) deviennent par foyer, sauf ceux de l'installation (clés VAPID, OCR,
  sécurité).
- Migration des données actuelles : création du foyer n° 1 et remplissage de `household_id`
  dans la même migration, testée sur une copie de la base réelle avant livraison.
- Chaque lot suivant ajoute ses tests d'isolation ; un test générique vérifie qu'aucun modèle de
  foyer n'est lisible depuis un autre foyer.

### 7.3 Lecture des tickets : services possibles

| Service | Principe | Coût indiqué (septembre 2026) | Remarques |
|---|---|---|---|
| **Mistral — Document AI** (proposition) | OCR + extraction dans un schéma JSON fourni par Bouffe (magasin, date, lignes…) | 5 $ / 1 000 pages (OCR seul : 4 $) | Entreprise française ; une clé API à créer ; qualité sur les tickets luxembourgeois à vérifier sur le jeu de tests (§7.4) |
| **Azure Document Intelligence — modèle « reçu »** | Modèle prêt à l'emploi pour les tickets (lignes, total, magasin) | **500 pages gratuites par mois** | Compte Azure nécessaire ; le niveau gratuit suffit largement à un foyer |
| **Google Cloud Vision** | Texte brut seulement | 1 000 images gratuites par mois, puis 1,50 $ / 1 000 | Bouffe devrait découper les lignes lui-même : moins fiable, écarté |

Volume attendu : 10 à 30 tickets par mois et par foyer, soit **quelques centimes par mois** avec
Mistral et **0 €** avec le niveau gratuit d'Azure. Les deux seront branchés derrière une même
interface (`ReceiptReader`), comme les sources de recettes du lot 13 ; les tests n'appellent
jamais le réseau (réponses enregistrées). Le choix est la question Q32.

Confidentialité : seule la photo recadrée du ticket est envoyée, jamais d'autre donnée du foyer ;
les conditions de conservation et d'utilisation des données de chaque fournisseur sont à relire au
moment du lot 23 et résumées dans la page « Vos données » (27.12).

### 7.4 Qualité

- Jeux de tests : 20 tickets réels anonymisés (Cactus, Delhaize, Lidl, Aldi, Auchan…) avec leur
  réponse enregistrée et le résultat attendu après relecture ; scénarios d'isolation pour chaque
  table ; suite complète sur SQLite, MariaDB et MySQL.
- **Git** devient indispensable (déploiement 27.10) : dépôt privé à créer avant la mise en ligne
  (le lot 25 prévoit aussi l'envoi par SFTP, voir [10-mise-en-ligne-ovh.md](10-mise-en-ligne-ovh.md) §11).

---

## 8. Découpage en lots

| Lot | Contenu | Livrable | Taille | Dépend de |
|---|---|---|---|---|
| **21 — Stock toujours juste** ✅ ([détail](lots/lot-21-stock-toujours-juste.md)) | 22.1 clôture des repas (R23), 22.2 retrait jamais perdu, 22.3 stock réservé (R24), 22.4 consommations hors repas, 22.5 écarts, 22.6 retrait au fil du mode cuisine | Le stock suit les repas sans qu'on y pense | M | — |
| **22 — Dépenses et budget réel** ✅ ([détail](lots/lot-22-depenses-budget.md)) | 23.1 à 23.9 (R25, R26), C5 gaspillage en euros, C7 dépenses récurrentes | Le vrai budget, restaurant compris, face à ce qu'on s'était fixé | M | — |
| **23 — Tickets de caisse** ✅ ([détail](lots/lot-23-tickets-de-caisse.md)) | 24.1 à 24.8 (R27, R28), C3 recette depuis une photo | Une photo du ticket remplit dépense, prix et stock | L | 22 |
| **24 — Plusieurs foyers : fondations** ✅ ([détail](lots/lot-24-plusieurs-foyers.md)) | 25.1 à 25.8 (R29, R30) | L'installation accueille d'autres foyers, chacun chez soi | L | — (après 21 à 23 pour ne migrer qu'une fois leurs tables) |
| **25 — En ligne chez OVH** ✅ ([détail](lots/lot-25-en-ligne-ovh.md)) | 27.1 à 27.12 | Bouffe en ligne, sécurisé, PC éteint | M | 24 |
| **26 — Entre foyers** ✅ ([détail](lots/lot-26-entre-foyers.md)) | 26.1 à 26.10 (R31), C4 agenda ICS | Recettes, planning et repas communs entre proches | L | 24, 25 |
| **27 — Prix et magasins** ✅ ([détail](lots/lot-27-prix-magasins.md)) | C1 comparateur, C2 inflation personnelle | Savoir où acheter moins cher | S | 23 |

**Ordre proposé** : 21 → 22 → 23 → 24 → 25 → 26 → 27.

- Les lots 21 à 23 servent tout de suite à la maison, sans rien changer à l'hébergement.
- Les fondations multi-foyer (24) viennent **avant** la mise en ligne (25) : on n'ouvre pas
  l'application à d'autres avant qu'elle sache les séparer, et on ne migre les données en ligne
  qu'une fois leur structure définitive.
- Variante si la mise en ligne presse : 21 → 24 → 25 → 22 → 23 → 26 (le budget et les tickets
  arrivent alors directement en production).

---

## 9. Questions ouvertes

Comme pour les documents précédents : réponds directement, les propositions par défaut seront
appliquées sinon.

| # | Question | Proposition par défaut |
|---|---|---|
| Q28 | Ordre des lots (§8) : priorité à la maison (21 → 23) ou à la mise en ligne ? | 21 → 22 → 23 → 24 → 25 → 26 → 27 |
| Q29 | Clôture des repas passés : toujours demander, ou marquer « mangé » automatiquement au bout de quelques jours ? | Demander ; automatique après 2 jours activable |
| Q30 | Faut-il passer le retrait du stock en **Automatique** par défaut (avec « Annuler ») ? | Oui, pour le foyer Pierre et Monique ; « Demander » pour les nouveaux foyers |
| Q31 | Postes de budget et montants de départ ; mois civil ou période à partir du jour de paie ? | Les six postes du 23.2 sans montant ; mois civil — *appliqué au lot 22* |
| Q32 | Service de lecture des tickets : Mistral (payant, quelques centimes) ou Azure (gratuit jusqu'à 500 pages / mois, compte Microsoft) ? | Mistral, Azure branché en second choix — *appliqué au lot 23* |
| Q33 | Garder les photos de tickets, et combien de temps ? | 12 mois, puis suppression automatique — *appliqué au lot 23* |
| Q34 | Suivre « qui a payé » entre Pierre et Monique ? | Non (réglage disponible) — *appliqué au lot 22* |
| Q35 | Catalogue d'ingrédients commun à tous les foyers (R30) ou propre à chacun ? | Commun, réglages de stock et de prix par foyer — *appliqué au lot 24* |
| Q36 | Visibilité par défaut d'une nouvelle recette ? | Privée — *appliqué au lot 26* |
| Q37 | Combien de foyers à prévoir, et un même compte dans plusieurs foyers (ex. Léo) ? | Moins de 10 foyers ; plusieurs foyers par compte autorisés — *appliqué au lot 24* |
| Q38 | Nom de domaine souhaité (existant ou à réserver) ? | À choisir ; un sous-domaine d'un domaine existant convient — *appliqué au lot 25 (guide rédigé pour un sous-domaine)* |
| Q39 | Offre OVH : l'offre **Pro** (SSH indispensable) te convient-elle ? | Oui, Pro — *appliqué au lot 25* |
| Q40 | Service externe pour lancer les tâches toutes les 5 minutes (ex. cron-job.org), ou accepter jusqu'à une heure de retard sur les rappels ? | Service externe, cron OVH horaire en secours — *appliqué au lot 25* |
| Q41 | Double authentification : obligatoire pour qui ? | Administrateur et responsables de foyer ; facultative pour les autres — *appliqué au lot 25* (en production ; facultative partout sur le PC) |
| Q42 | Langues : des proches ont-ils besoin d'une interface en allemand ou en anglais (C6) ? | Non pour l'instant |

---

## Sources

- OVHcloud — [tâches planifiées (cron) sur l'hébergement web](https://github.com/ovh/docs/blob/develop/pages/web_cloud/web_hosting/cron_tasks/guide.fr-fr.md) (une exécution par heure, 60 minutes maximum) ; [versions de PHP disponibles](https://docs.ovhcloud.com/en/guides/web-cloud/web-hosting/web-hosting-main-info) ; [offre Pro](https://www.ovhcloud.com/fr/web-hosting/business/) (SSH, Git, bases de données, prix) ; [le planificateur Laravel sur un mutualisé OVH](https://www.weblogin.fr/blog/92-mutualise-ovh-et-laravel-scheduler).
- [cron-job.org](https://cron-job.org/en/) — service gratuit d'appels planifiés.
- Mistral — [tarifs de l'API](https://mistral.ai/pricing/api/) (OCR et Document AI).
- Microsoft — [tarifs d'Azure Document Intelligence](https://azure.microsoft.com/en-in/pricing/details/document-intelligence/) (500 pages gratuites par mois).
- Google — [tarifs de Cloud Vision](https://cloud.google.com/vision/pricing).
