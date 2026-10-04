# Lot 7 — Stock : saisie et consultation

**Objectif** : savoir ce qu'il y a au frigo, au congélateur et au placard, avec un minimum de saisie.

**Statut** : ✅ développé et vérifié en local (347 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur ordinateur 1280 / 1024 / 768 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md), modules 9 (9.1 à 9.7) et 11.1, règle R11.

## Mise à jour depuis le lot 6

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # tables storage_locations, stock_items, stock_movements + réglages de conservation des ingrédients
php artisan view:clear
php artisan test         # 347 tests
```

La migration crée les 5 emplacements et **règle automatiquement les ingrédients existants** (mode de suivi, emplacement, durées) d'après leur rayon. Les ingrédients déjà réglés à la main ne sont pas modifiés.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 7.1 | Modèle de données | Emplacements, articles en stock, mouvements (historique jamais modifié), réglages de stock des ingrédients, `stocked_at` sur les articles de liste ; enums `StockMode`, `ExpiryType`, `LocationType`, `MovementType`, `ExpiryLevel` | ✅ |
| 7.2 | Valeurs de départ | `StockDefaults` : Réfrigérateur, Congélateur, Placard, Cave / cellier, Corbeille à fruits ; profil par rayon (frais → quantité et date, épicerie et produits de base → présence, hygiène → non suivi) et ~50 exceptions (oignons au placard, tomates dans la corbeille, crème 3 jours après ouverture…). Appliqué aussi à tout nouvel ingrédient | ✅ |
| 7.3 | Fiche ingrédient | Section « Stock et conservation » : suivi, emplacement habituel, conservation et type de date, après ouverture, congélation, stock minimum (utilisé au lot 9) ; valeurs proposées quand on choisit le rayon | ✅ |
| 7.4 | Écran Stock | Menu **Stock** (dans la barre du bas sur téléphone) : onglets par emplacement avec compteurs, recherche, filtres **À consommer bientôt**, **Date dépassée**, **Ouverts** ; articles groupés par ingrédient, les plus urgents en premier, badges de date (J-3, Demain, Dépassée…), « ouvert », « congelé » | ✅ |
| 7.5 | Ajout rapide | « 2 kg de pommes de terre », « 6 œufs », « 1/2 botte de persil » : quantité, unité et ingrédient reconnus, emplacement et date proposés, raccourcis +3 j / +1 sem. / +1 mois / pas de date, DLC / DDM / estimée ; ingrédient inconnu créé dans le rayon Divers | ✅ |
| 7.6 | Plats préparés | Restes, batch cooking : libellé libre, 3 jours au réfrigérateur par défaut | ✅ |
| 7.7 | Actions sur un article | **Terminé**, **Jeté** (périmé, abîmé, oublié, autre), **Il en reste ¾ · ½ · ¼**, **Ouvert** (si l'ingrédient a une durée après ouverture), **Congeler / Décongeler**, **Déplacer**, correction de la quantité, de la date et de la note | ✅ |
| 7.8 | Annuler | Après chaque action, bandeau « Annuler » (12 s) ; la dernière action sur un article reste annulable 15 minutes | ✅ |
| 7.9 | Mode présence | Sel, pâtes, épices : bouton **En stock** → **Plus rien**, proposition « Ajouter à la liste de courses en cours ? », section **Plus en stock** pour les remettre d'un clic | ✅ |
| 7.10 | Ranger les courses | Bouton **Ranger** sur une liste terminée ou entièrement cochée (et dans le menu ⋯) : écran unique avec quantités achetées (arrondies à l'achat), emplacement, date et type par ligne, **Tout valider** ; les articles sans ingrédient (lessive) sont ignorés par défaut ; rien n'est proposé deux fois | ✅ |
| 7.11 | Emplacements | Paramètres → Emplacements : ajout, renommage, type, ordre par glisser-déposer ; suppression refusée s'il contient des articles | ✅ |
| 7.12 | Dates (R11) | `ExpiryCalculator` : date effective (ouverture, congélation, décongélation, plat préparé) et niveau d'alerte, toujours recalculés | ✅ |
| 7.13 | Protections | Ingrédient présent dans le stock ou son historique : suppression refusée | ✅ |
| 7.14 | Tests | +27 tests (valeurs de départ, dates, ajout rapide, opérations et annulation, rangement, écrans) — 347 au total | ✅ |

## Règles implémentées

- **Modes de suivi** : *quantité* (frais : quantité, date, plusieurs articles par ingrédient) · *présence* (un seul article « en stock », sans quantité ni date estimée) · *non suivi* (refusé dans le stock).
- **Date effective** = min(date imprimée, date d'ouverture + conservation après ouverture). Congelé : date de congélation + durée de congélation (type DDM). Décongelé : +1 jour (type DLC), rangé au réfrigérateur. Plat préparé sans date : +3 jours.
- **Badges** :
  - DLC : dépassée (rouge), aujourd'hui / demain (rouge), ≤ 3 jours (orange) ;
  - DDM : ≤ 7 jours (orange), dépassée → « À vérifier » (jaune) ;
  - date estimée (légumes en vrac, plats maison) : ≤ 3 jours (orange), dépassée → « À vérifier ».
- **« Il en reste ½ »** se calcule sur la quantité de départ du paquet ; sans quantité connue, la note « reste ½ » est ajoutée.
- **Quantité achetée** au rangement : quantité de la liste arrondie à l'unité supérieure pour les pièces (2,4 blancs de poulet → 3), exacte pour g / ml ; modifiable (paquet de 250 g au lieu de 30 g).
- **Mouvements** : entrée, consommé, jeté (avec raison), quantité corrigée, déplacé, ouvert, congelé, décongelé, annulation. Jamais modifiés ; un article terminé est masqué (pas supprimé), ce qui permet l'annulation et les statistiques anti-gaspillage futures.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/stock?emplacement=&q=&filtre=` | `stock.index` | `Stock\Index` |
| `/courses/{id}/ranger` | `shopping.put-away` | `Stock\PutAway` |
| `/parametres/emplacements` | `settings.locations` | `Settings\StorageLocations` |

## Choix faits pendant le lot

- **Réglages de conservation pré-remplis** plutôt qu'à saisir : c'est la condition pour que le stock reste tenu. Les durées sont des ordres de grandeur prudents, modifiables ingrédient par ingrédient.
- **Article terminé masqué, pas supprimé** (colonne `finished_at`) et **état avant action mémorisé dans le mouvement** (`snapshot`) : l'annulation est fiable sans jamais modifier l'historique.
- **Date effective et badges dès ce lot** (prévus au lot 8) : indispensables pour lire l'écran Stock. Le lot 8 ajoute les alertes hors de cet écran (menu, accueil, planning) et le réglage des seuils.
- **Pas de date estimée en mode présence** : un paquet de pâtes « périmé dans un an » générerait des alertes sans intérêt.
- **Barre du bas sur téléphone** : Accueil · Recettes · Planning · **Stock** · Courses ; Paramètres passe dans l'en-tête (icône ⚙).
- Correction au passage : quelques teintes vertes (`herb-200`, `-300`, `-800`, `-900`) utilisées depuis les lots 5-6 n'existaient pas dans le thème et s'affichaient sans couleur.

## Prochain lot

**Lot 8 — Péremption & alertes** : badge du nombre de produits urgents dans le menu, carte « À consommer rapidement » sur l'accueil, bandeau dans le planning, réglage des seuils.
