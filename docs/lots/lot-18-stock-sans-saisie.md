# Lot 18 — Stock sans saisie

**Objectif** : le stock se remplit en scannant, et ce qui dort au congélateur ne s'oublie plus.

**Statut** : ✅ développé et vérifié en local (634 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 950 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 16 (16.1 à 16.5).

## Mise à jour depuis le lot 17

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 16.1 | **Scan de code-barres** | Stock → Scanner : la caméra lit le code (décodage dans le navigateur, l'image ne part jamais), le produit est cherché d'abord dans la maison puis chez Open Food Facts, associé **une seule fois** à un ingrédient, puis ajouté au stock. Saisie du code au clavier en secours | ✅ |
| 16.2 | **Rangement assisté** | La date proposée au rangement des courses vient de la durée **réellement observée** chez vous, pas d'une estimation ; l'emplacement habituel est repris | ✅ |
| 16.3 | **Historique et anti-gaspillage** | Stock → Historique : mouvements filtrables, part consommée avant la date, jetés par mois avec coût estimé (prix du lot 17), produits les plus jetés, tendance | ✅ |
| 16.4 | **Congélateur organisé** | Stock → Congélateur : plats maison triés du plus ancien au plus récent, portions d'avance, signalement à 3 et 6 mois, sortie et fin en un clic | ✅ |
| 16.5 | **Étiquettes** | Impression d'étiquettes (nom, portions, date, QR code) pour les boîtes ; scanner le QR ouvre la fiche de l'article | ✅ |
| 18.6 | Tests | +25 tests (Open Food Facts simulé, produit connu hors réseau, durées apprises, gaspillage, congélateur, étiquettes) — 634 au total | ✅ |

## 16.1 — Comment un code-barres devient un article de stock

1. **La maison d'abord.** Un code déjà scanné est connu : nom, ingrédient associé, contenu du paquet.
   Aucun réseau, aucune question — c'est le cas courant, et c'est celui qui doit être rapide.
2. **Open Food Facts ensuite**, seulement pour un code jamais vu. La réponse est mémorisée
   définitivement. La requête s'identifie (`User-Agent: Bouffe/1.0 …`) comme leur documentation
   le demande, et la limite de 15 requêtes par minute est respectée de fait, puisqu'on n'interroge
   qu'une fois par produit.
3. **Sinon, vous le nommez.** Un produit inconnu partout (le sirop du voisin) est décrit à la main
   et devient connu comme les autres.

L'ingrédient est **proposé** d'après le nom (« Lait demi-écrémé UHT » → « Lait demi-écrémé »), jamais imposé : la proposition est confirmée une fois, et plus jamais.

**Ce que la caméra demande** : une connexion sécurisée (`https://` ou `localhost`). Sur le Wi-Fi de la maison en `http://`, le navigateur refuse la caméra — la page le dit et propose la saisie du code au clavier, qui fait exactement la même chose. Avec le tunnel privé du lot 16 ([09-acces-distant.md](../09-acces-distant.md)), la caméra fonctionne.

La bibliothèque de décodage (ZXing, 477 Ko) n'est chargée qu'au moment où vous activez la caméra : les autres pages n'en portent pas le poids.

## 16.2 — Les durées apprises

Le réglage « conservation : 7 jours » d'un ingrédient est une estimation de départ. Bouffe regarde désormais ce qui s'est vraiment passé : combien de jours ce produit tient chez vous avant d'être fini.

- seuls les articles **consommés** comptent (un produit jeté n'apprend pas une durée de vie) ;
- il faut au moins **3 observations**, sinon le réglage d'origine est gardé ;
- c'est la **médiane** qui est retenue, pas la moyenne : un paquet oublié trois mois au fond du placard ne fausse pas la proposition.

Au rangement des courses, la ligne affiche alors « D'après vos achats précédents : tenu 10 jours en moyenne. » Et quand la durée observée s'écarte nettement du réglage, la page Historique propose de corriger le réglage en un clic.

## 16.3 — Anti-gaspillage

Le but n'est pas de culpabiliser mais de voir ce qui part à la poubelle **régulièrement**. La page donne : la part consommée avant la date, le nombre de jetés par mois, le coût estimé (à partir des prix relevés au lot 17 — sans prix, on compte les articles sans inventer d'euros), les produits les plus jetés, et la tendance des trois derniers mois.

## 16.4 / 16.5 — Congélateur et étiquettes

Un congélateur se gère par l'ancienneté. La page trie les plats maison du plus ancien au plus récent, affiche les portions d'avance, et signale sans dramatiser : « plus de 3 mois : pensez à le planifier », « plus de 6 mois : à manger en priorité ».

Les étiquettes s'impriment deux par ligne : nom, portions, date de congélation, date limite éventuelle, et un QR code qui ouvre directement la fiche de l'article dans Bouffe — utile quand le feutre a pâli.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_09_30_100000_create_lot18_tables.php` | Table `products`, colonnes `stock_items.servings` et `product_id` |
| `app/Models/Product.php` | Un code-barres connu de la maison |
| `app/Services/Stock/ProductLookup.php` | Recherche locale puis Open Food Facts, mémorisation, proposition d'ingrédient |
| `app/Services/Stock/ShelfLifeLearner.php` | Durées réellement observées, emplacement habituel (16.2) |
| `app/Services/Stock/WasteStats.php` | Statistiques anti-gaspillage (16.3) |
| `app/Services/Stock/FreezerBoard.php` | Le congélateur trié par ancienneté (16.4) |
| `app/Livewire/Stock/Scan.php`, `Freezer.php`, `History.php` + vues | Les trois écrans |
| `resources/views/print/labels.blade.php` | Étiquettes imprimables avec QR code (16.5) |
| `resources/js/app.js` (ajout) | `barcodeScanner()` : caméra et décodage ZXing |
| `tests/Feature/Stock/ScanTest.php`, `FreezerAndWasteTest.php` | 25 tests |

Fichiers modifiés : `PutAwayService` (dates et emplacements appris), `PrintController` (étiquettes), `Stock\Index` (ouverture d'un article par son adresse, boutons Scanner / Congélateur / Historique), `StockItem` et `StockMovement` (relations), `package.json` (`@zxing/browser`).

## À vérifier sur ton poste

- Stock → **Scanner** : saisis un code-barres d'un produit de ton placard. S'il est connu d'Open Food Facts, vérifie l'ingrédient proposé, puis ajoute-le. Rescanne le même code : plus aucune question ne doit être posée.
- Stock → **Congélateur** : coche un plat, clique sur **Étiquettes**, et vérifie que le QR code imprimé ouvre bien la fiche de l'article quand tu le scannes avec le téléphone.
- Stock → **Historique** : les mouvements des lots précédents doivent tous être là, filtrables par type.
- Au **rangement des courses**, une fois quelques produits consommés, la mention « D'après vos achats précédents » doit apparaître sous les lignes concernées.
