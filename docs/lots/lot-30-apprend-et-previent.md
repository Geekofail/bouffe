# Lot 30 — Bouffe apprend et prévient

**Objectif** : moins d'erreurs sans retour possible, des réglages qui s'ajustent à la maison, des
prix justes.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **926 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+38).
- **40 vérifications dans le navigateur** passent (+5) : deux nouveaux écrans sur iPhone et sur
  ordinateur, et un parcours « vider la semaine, puis Annuler ».
- **Une migration** : trois tables (`undo_tokens`, `activity_events`, `learned_suggestions`) et une
  colonne (`ingredient_prices.is_promo`). Rien n'est modifié dans les données existantes.

Spécification : [08-version-4.md](../08-version-4.md), module 30, règles R32, R35 et R36.

## Mise à jour depuis le lot 29

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

La commande applique la migration. Les tests dans le navigateur restent facultatifs sur ton PC.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 30.1 | **Annuler partout** (R32) | Après chacun de ces gestes, un message propose **Annuler** pendant 10 secondes (une barre montre le temps qui reste) : vider la semaine ; retirer, déplacer ou copier un repas (copie simple ou en remplaçant la semaine) ; retirer ou supprimer un article de la liste ; mettre la liste à jour depuis le planning ; supprimer une liste ; supprimer un relevé de prix. L'annulation remet **exactement** l'état d'avant, y compris ce qui était lié (restes, réactions, rappels, sources des articles, lien d'un article du stock vers son repas, prix de référence). Si quelqu'un a modifié ces éléments entre-temps, elle est refusée et le dit. Seule la personne qui a fait le geste peut l'annuler | ✅ |
| 30.2 | **Journal du foyer** | **Paramètres › Journal** (`/foyer/journal`) : « Monique a coché 12 articles dans « Courses de la semaine » », « Pierre a planifié 3 repas », « a ajouté une envie », « a rangé 5 articles », « a jeté… », « a annulé… ». Les gestes d'une même personne sont regroupés tant qu'ils se suivent à moins de 30 minutes. Par jour, filtrable par personne et par type. 30 jours, puis effacé. Visible des membres du foyer seulement | ✅ |
| 30.3 | **Aide contextuelle** | Une bulle « Le saviez-vous ? » la première fois qu'on ouvre le planning (glisser-déposer, balayage sur téléphone), le stock (mode présence), une liste de courses (deux magasins), les prix (promotions) et la revue du stock. **Compris** la retire pour de bon. **Paramètres › Affichage** : les réafficher toutes, ou n'en afficher aucune | ✅ |
| 30.4 | **Stock minimum appris** (R36) | Sur la page du stock, bloc « Bouffe a remarqué » : « Vous achetez « Lait demi-écrémé » chaque semaine : garder au moins 1 l ? ». Proposé après au moins 4 achats rangés dans le stock en 8 semaines, pour un produit sans minimum. La quantité proposée est celle achetée d'habitude | ✅ |
| 30.5 | **Durées apprises** (R36) | « « Crème liquide » : 3 fois sur 4, à la poubelle moins de 5 jours après ouverture. Passer la durée après ouverture de 5 à 3 jours ? ». Même chose pour la **conservation** d'un produit jeté avant sa durée sans avoir été ouvert. Au moins 4 produits finis ou jetés en 8 semaines, dont la moitié au moins jetés trop tôt | ✅ |
| 30.6 | **Notifications étendues** | Cinq nouveaux types, chacun activable par personne : **invitation d'un foyer relié** et **réponse à mon invitation** (activés par défaut, Q48), **surplus proposé**, **avis reçu**, **hausse de prix marquée**. **Ne pas déranger** : chacun choisit ses heures (22 h – 7 h par défaut) ou le désactive ; ce qui tombe pendant attend le matin | ✅ |
| 30.7 | **Promotions reconnues** (R35) | Une ligne de ticket avec remise est notée **promo** automatiquement ; un prix noté à la main peut l'être (case « En promotion »). Un prix en promotion ne devient pas le prix de référence (coût des recettes), n'entre ni dans les prix courants du comparateur, ni dans l'indice du panier, ni dans les hausses marquées. Le comparateur l'affiche à part : « Meilleur prix vu : 6,00 € / kg chez Lidl le 10 oct. **promo** » | ✅ |
| 30.8 | **Inventaire par ancienneté** | `/stock/revue` : les articles qui n'ont pas bougé depuis 2 mois (ni ajout, ni ouverture, ni mouvement, ni vérification), du plus ancien au plus récent, **un par un** : Toujours là · Fini · Jeté · Plus tard. Filtrable par emplacement. Annoncé sur la page du stock à partir de 3 articles, et toujours accessible par **Revue** | ✅ |
| — | Tests | +38 tests Pest (926) ; 5 vérifications de plus dans le navigateur | ✅ |

## Règles appliquées

**R32 — Annuler**

- Le serveur accepte l'annulation pendant 2 minutes (réseau lent), l'écran la propose 10 secondes.
- Une annulation ne sert qu'une fois.
- « Ces éléments ont été modifiés entre-temps » : l'annulation compare l'état actuel à celui juste
  après le geste. Au moindre écart (Monique a changé les portions d'un repas de la case), rien n'est
  touché.
- Les gestes qui avaient déjà leur propre « Annuler » le gardent : repas mangé, retrait du stock
  (y compris depuis la revue par ancienneté), fusion d'ingrédients.

**R35 — Promotions** : voir 30.7. Supprimer un relevé ramène le prix de référence au dernier prix
**hors promotion**.

**R36 — Apprentissages**

- Au moins 4 observations sur 8 semaines.
- Jamais appliqué seul : **Appliquer** ou **Non merci**.
- « Non merci » fait taire la proposition 90 jours, pour ce produit et ce réglage.
- Un produit dont un réglage a été modifié depuis moins de 30 jours n'est pas remis en question.
- Trois propositions au plus à la fois.

## Choix et limites

- **Annuler : une liste fermée de gestes.** Les semaines types appliquées, le remplissage
  automatique et les réceptions n'ont pas d'« Annuler » dans ce lot. Le moteur est générique : en
  ajouter un est court.
- **L'annulation lit les liens entre tables dans la base elle-même** : ce qu'une suppression emporte
  en cascade, ce qu'elle remet à vide. Une table ajoutée plus tard est donc couverte sans y penser.
- **Journal** : les tâches planifiées (clôture automatique des repas, consommations régulières)
  n'y apparaissent pas, seuls les gestes d'une personne connectée.
- **« Réglage modifié depuis moins de 30 jours »** : Bouffe ne sait pas quel réglage d'un ingrédient
  a été touché, seulement que l'un d'eux l'a été. Il se tait donc sur tout l'ingrédient pendant
  30 jours. Appliquer une proposition compte aussi.
- **Durées apprises : seulement à la baisse.** Un produit consommé bien après sa durée reste le
  domaine de la proposition du lot 18 (page **Historique**), qui l'allonge.
- **Promotions** : le « meilleur prix vu » s'affiche pour un produit qui a au moins un prix courant
  dans un magasin. Un produit vu **uniquement** en promotion n'apparaît que dans son historique.
- **Dates des prix** : elles sont maintenant enregistrées sans heure. Sous MariaDB rien ne change ;
  sous SQLite, un prix relevé le jour même était ignoré par l'indice du panier jusqu'au lendemain.
- **Notifications** : les messages « proches » et « prix » portent sur les dernières 24 heures. Ils
  partent au passage suivant de la tâche planifiée, jamais pendant les heures calmes de la personne.

## Fichiers

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_09_100000_create_lot30_tables.php` | Tables et colonne du lot |
| `app/Services/Undo/*` | Annuler : `UndoManager`, `UndoRecorder`, `Snapshotter`, `SchemaGraph`, `UndoRefused` |
| `app/Livewire/Concerns/OffersUndo.php`, `app/Livewire/Layout/Undo.php` | Message « Annuler » et annulation depuis n'importe quel écran |
| `app/Services/Activity/ActivityLog.php`, `ActivityWatcher.php` | Journal du foyer |
| `app/Livewire/Journal.php` + vue | Page du journal |
| `app/Services/Stock/Learnings.php` | Propositions apprises |
| `app/Services/Stock/StockReview.php`, `app/Livewire/Stock/Review.php` + vue | Revue par ancienneté |
| `resources/views/components/hint.blade.php`, `app/Http/Controllers/HintController.php` | Aide contextuelle |
| `app/Models/UndoToken.php`, `ActivityEvent.php`, `LearnedSuggestion.php` | Modèles |
| `tests/Feature/Learning/*` | 38 tests (annuler, apprentissages et revue, journal, aide, promotions, notifications) |
| `tests/Browser/undo.spec.js` | Parcours « vider puis Annuler » dans le navigateur |

Fichiers modifiés :

- planning (composant et service : cases, semaine), liste de courses, listes, prix ;
- stock (bloc « Bouffe a remarqué », bandeau et lien **Revue**) ;
- `PriceBook`, `PriceComparison`, `PersonalInflation`, `ReceiptService`, modèle `IngredientPrice` ;
- `NotificationDispatcher`, Paramètres › Notifications, Paramètres › Affichage ;
- `components/flash` (durée choisie, barre du temps restant), `app.css` ;
- `BrowserDemoSeeder` (données d'essai inventées : journal, promotion, réserves anciennes) ;
- `HouseholdData` (suppression d'un foyer : les nouvelles tables) ;
- deux tests existants ajustés : le ticket avec remise (R35) et le décompte des modèles propres à un
  foyer.

## À vérifier sur ton poste

- **Planning** : vider la semaine, puis **Annuler** avant la fin de la barre. Pareil en déplaçant un
  repas d'un jour à l'autre.
- **Liste de courses** : retirer un article coché, puis **Annuler** : il revient, coché.
- **Paramètres › Journal** : tes gestes de la journée.
- **Stock** : **Revue**. Le bloc « Bouffe a remarqué » n'apparaîtra qu'après quelques semaines
  d'achats rangés dans le stock.
- **Paramètres › Notifications** : les nouveaux types et « Ne pas déranger la nuit ».
- **Prix** : noter un prix « En promotion » et regarder le comparateur.
