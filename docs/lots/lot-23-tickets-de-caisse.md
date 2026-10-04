# Lot 23 — Tickets de caisse

**Objectif** : une photo du ticket remplit la dépense, les prix et le stock.

**Statut** : ✅ développé et vérifié en local (771 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 px en mode clair et iPhone 390 px en mode sombre, sans débordement horizontal) — ⏳ à valider sur ton poste, **avec de vrais tickets**.

Spécification : [07-version-3.md](../07-version-3.md), module 24, règles **R27** et **R28**, complément **C3**.

## Mise à jour depuis le lot 22

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Ensuite, il faut choisir un service de lecture dans **Paramètres → Tickets de caisse**. Sans clé,
tout fonctionne quand même : les tickets se saisissent à la main sur le même écran.

**Réglages par défaut appliqués** (questions sans réponse) :
- Q32 : **Mistral**, Azure branché en second choix.
- Q33 : photos gardées **12 mois**, puis supprimées automatiquement.

### Mistral (choix par défaut)

1. Sur <https://console.mistral.ai>, crée un compte puis une clé dans **API Keys**. Vérifie dans la
   console que ton offre permet l'OCR (Document AI) et, si besoin, active la facturation.
2. Dans Bouffe : **Paramètres → Tickets de caisse** → Mistral → colle la clé → Enregistrer.

Coût indiqué par Mistral en septembre 2026 : 5 $ pour 1 000 pages avec extraction, soit environ
0,5 centime par ticket. Pour 10 à 30 tickets par mois, cela fait quelques centimes.

### Azure (gratuit jusqu'à 500 pages par mois)

1. Sur le portail Azure, crée une ressource **Document Intelligence** au niveau tarifaire **F0**
   (gratuit).
2. Dans **Clés et point de terminaison**, copie le point de terminaison et la **clé 1**.
3. Dans Bouffe : **Paramètres → Tickets de caisse** → Azure → colle les deux → Enregistrer.

Les clés sont **chiffrées** en base avec la clé de l'application et ne sont jamais réaffichées. Tu
peux aussi les mettre dans `.env` (`MISTRAL_API_KEY`, `AZURE_DI_ENDPOINT`, `AZURE_DI_KEY`).

### Premier essai conseillé

Avant de t'en servir au quotidien, essaie trois ou quatre vrais tickets (Cactus, Delhaize, Aldi…) :

```powershell
php artisan bouffe:ticket C:\Users\Pierre\Downloads\ticket-cactus.jpg
```

La commande affiche ce qui a été lu, ligne par ligne, et si le compte tombe juste. Elle ne garde
rien, sauf avec `--garder`. Chaque essai compte comme une lecture.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 24.1 | **Photo ou PDF** | « Prendre une photo » (appareil photo du téléphone) ou « Choisir un fichier » (PDF d'un ticket dématérialisé) ; rotation et recadrage dans le navigateur ; réduction à 2 400 px ; plusieurs photos pour un ticket long, réunies en un PDF à l'envoi | ✅ |
| 24.2 | **Lecture** | Mistral (schéma JSON fourni par Bouffe) ou Azure (modèle « reçu ») ; magasin, date, total, lignes ; contrôle « le compte est bon » (R27) | ✅ |
| 24.3 | **Relecture** | Une carte par ligne : ingrédient reconnu et **Appris** / **Reconnu** / **À vérifier** / **Non reconnu** ; genre (article, hors stock, remise, consigne…) ; nombre × contenu du paquet ou poids ; « Au stock » ; poste de budget ; lignes douteuses encadrées ; tout s'enregistre au fil de l'eau | ✅ |
| 24.4 | **Valider** | Dépense répartie par poste (courses, droguerie…) ; prix relevés par magasin ; stock rempli (emplacements et dates appris) ; articles de la liste cochés et marqués rangés | ✅ |
| 24.5 | **Apprentissage** | Chaque ticket validé retient ses libellés par magasin : la fois suivante, « Appris » d'office ; liste et suppression dans les Paramètres | ✅ |
| 24.6 | **Imprévus et oubliés** | « 3 achetés hors liste », « 2 articles de la liste non achetés — les garder pour la prochaine fois ? » → « Quand je passe » ou retirés | ✅ |
| 24.7 | **Suivi des coûts** | Lectures du mois et coût estimé (page Tickets et Paramètres, six derniers mois) ; plafond réglable, 50 par défaut, au-delà saisie à la main | ✅ |
| 24.8 | **Conservation** | Photos sur le disque privé, visibles seulement une fois connecté ; supprimées après 12 mois par la tâche planifiée ; les numéros de carte bancaire sont effacés de tout ce qui est enregistré | ✅ |
| C3 | **Recette depuis une photo** | Importer une recette → **Photo d'une page** : page de livre, fiche manuscrite → relecture habituelle de l'import | ✅ |
| — | Tests | +23 tests — 771 au total | ✅ |

## Au quotidien

1. Bouton **+** → **Un ticket** (ou page Budget → **Ticket**).
2. Photo, recadrage si besoin, **Garder cette photo**, puis **Lire le ticket** (10 à 30 secondes).
3. Relecture : les lignes en orange sont à vérifier. Un ingrédient se choisit en tapant son nom.
4. **Valider le ticket**.

Si une dépense identique a déjà été saisie à la main, Bouffe propose de la **remplacer** par le
ticket, pour ne pas la compter deux fois (R26).

## R27 — lecture

- Total, moyens de paiement et rendu monnaie sont écartés ; les lignes de TVA sont gardées mais
  ne comptent pas (elles sont déjà dans les prix).
- Remises, bons d'achat et consignes sont reconnus au libellé, même si le service se trompe de
  genre ; une remise qui suit directement un article lui est rattachée.
- « 2 × 1,29 » donne 2 pièces ; « 0,532 kg × 3,99 €/kg » donne 532 g.
- Somme des lignes = total à 0,05 € près, sinon « le ticket semble incomplet ou mal lu » : on
  corrige, on prend la somme comme total, ou on valide quand même. L'écart éventuel revient aux
  courses : le total payé fait foi.

## R28 — libellé → ingrédient

1. Libellé appris dans ce magasin, puis ailleurs.
2. Nom ou alias d'un ingrédient contenu mot pour mot dans le libellé (« BEURRE DOUX 250G » → Beurre).
3. Produit scanné au lot 18 portant le même nom.
4. Nom lisible proposé par le service (« lait demi-écrémé ») → **À vérifier**.
5. Sinon **Non reconnu**. Lessive, papier toilette, liquide vaisselle… passent d'office en
   « Hors stock », poste Droguerie.

Le contenu du paquet est lu dans le libellé (« 1L », « 4X125G ») et se corrige à la relecture.

## Ce qui est envoyé, ce qui est gardé

- Seule la photo (ou le PDF) du ticket part chez le service, jamais d'autre donnée du foyer.
- Les photos restent dans `storage/app/private/receipts`, jamais accessibles sans connexion. Elles
  ne sont **pas** dans les sauvegardes : ce sont des pièces temporaires.
- Un numéro de carte, même partiel (« ****1234 »), est remplacé par « [carte] » avant tout
  enregistrement.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_05_100000_create_lot23_tables.php` | Tickets, lignes, libellés appris, lectures, `expenses.receipt_id` |
| `app/Models/Receipt.php`, `ReceiptLine.php`, `ReceiptLabelMapping.php`, `OcrReading.php` | Modèles |
| `app/Services/Receipts/Readers/MistralReader.php`, `AzureReader.php`, `ReceiptReader.php` | Services de lecture derrière une même interface |
| `app/Services/Receipts/OcrService.php` | Service choisi, plafond, suivi des coûts |
| `app/Services/Receipts/ReceiptInterpreter.php` | R27 |
| `app/Services/Receipts/ReceiptMatcher.php` | R28 et apprentissage |
| `app/Services/Receipts/ReceiptService.php` | De la photo à la validation |
| `app/Services/Receipts/ReceiptFiles.php`, `JpegPdf.php`, `CardScrubber.php` | Photos, PDF de plusieurs photos, numéros de carte |
| `app/Livewire/Receipts/Index.php`, `Capture.php`, `Show.php` + vues | Pages Tickets, Nouveau ticket, relecture |
| `app/Livewire/Settings/ReceiptSettings.php` + vue | Paramètres → Tickets de caisse |
| `app/Http/Controllers/ReceiptPhotoController.php` | Photos servies aux seuls connectés |
| `app/Console/Commands/ReceiptTestCommand.php` | `php artisan bouffe:ticket` |
| `tests/Feature/Receipts/ReceiptTest.php` | 23 tests (réponses de service **simulées**, aucun appel réseau) |

Fichiers modifiés : réglages (clés chiffrées), budget (dépense d'un ticket), import de recettes
(onglet photo), bouton +, page Budget, fenêtre de dépense, navigation (« Tickets » dans « Plus »),
tâche planifiée (suppression des vieilles photos), `app.js` (rotation et recadrage), icônes.

## À vérifier sur ton poste

- `bouffe:deploy`, puis **Paramètres → Tickets de caisse** : service et clé.
- `php artisan bouffe:ticket` sur deux ou trois vrais tickets : dis-moi ce qui est mal lu, j'ajusterai
  les règles (c'est le jeu de tests réels prévu au §7.4, que je ne pouvais pas constituer sans toi).
- Sur le téléphone : **+ → Un ticket → Prendre une photo**, relecture, **Valider** ; puis la page
  Stock et le budget du mois.
- Un second ticket du même magasin : les lignes doivent apparaître **Appris**.
