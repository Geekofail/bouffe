# Lot 4 — Liste de courses

**Objectif** : transformer le planning en liste de courses utilisable en magasin, à deux, depuis le téléphone.

**Statut** : ✅ développé et vérifié en local (254 tests passés sous SQLite **et** MariaDB 10.11, parcours complet dans un navigateur : ordinateur 1440/1280 px, tablette 1024/768 px, mobile 390 px, impression, synchronisation entre deux sessions) — ⏳ à valider sur ton poste.

**MVP complet** : recettes → planning → liste de courses.

## Mise à jour depuis le lot 3

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # tables shopping_lists, shopping_list_items, shopping_list_item_sources, recurring_items
php artisan view:clear
php artisan test         # 254 tests
```

Aucune nouvelle donnée de départ : les articles récurrents se saisissent dans **Paramètres → Articles récurrents**.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 4.1 | Modèle de données | 4 tables ; enums `ListStatus` (en cours, terminée) et `ItemOrigin` (planning, placard, manuel, récurrent) | ✅ |
| 4.2 | Calcul `ShoppingListGenerator` | Règles R1, R2, R4, R5 testées : mise à l'échelle, conversion et addition par ingrédient, exclusions, provenance. Ne touche pas la base | ✅ |
| 4.3 | Gestion `ShoppingListManager` | Création, régénération avec compte rendu, articles manuels, cocher, quantités modifiées, retrait, regroupement par rayon, export texte | ✅ |
| 4.4 | Générer une liste | Depuis **Courses** ou le bouton **Liste de courses** du planning : période (raccourcis cette semaine / semaine prochaine / 7 prochains jours), repas passés inclus ou non, **aperçu des repas** avec possibilité d'en décocher, nom proposé « Courses du 14 sept. au 20 sept. » | ✅ |
| 4.5 | Écran liste (mobile d'abord) | Barre de progression collante, articles groupés **dans l'ordre des rayons du magasin**, grandes cases à cocher, articles cochés en bas de leur rayon, option « Masquer les cochés » (conservée dans l'URL) | ✅ |
| 4.6 | À vérifier dans le placard | Les ingrédients « produit de base » (sel, huile, farine…) sont dans une section à part | ✅ |
| 4.7 | Ajout rapide | « 2 baguettes », « 1 kg de pommes de terre », « Lessive » : le texte est gardé tel quel, l'ingrédient est reconnu pour ranger l'article dans son rayon (sinon Divers) ; suggestions d'ingrédients pendant la saisie | ✅ |
| 4.8 | Détail d'un article | Modifier quantité, unité et rayon ; **provenance** (recette, jour, créneau, quantité) ; « Coché par Monique il y a 5 minutes » ; retirer (« j'en ai déjà ») ou supprimer | ✅ |
| 4.9 | Articles retirés | Section repliable, bouton **Remettre** ; ils restent retirés après une régénération | ✅ |
| 4.10 | Régénération | Après une modification du planning : recalcul, puis message « Ajouté : … / Modifié : 400 g → 600 g / Retiré : … ». Les quantités modifiées à la main et les articles manuels sont conservés ; un article coché dont la quantité augmente est décoché | ✅ |
| 4.11 | Articles récurrents | Paramètres → Articles récurrents (libellé, ingrédient, rayon, actif) ; ajoutés automatiquement à chaque nouvelle liste, sauf si l'ingrédient y est déjà | ✅ |
| 4.12 | Courses à deux | Rafraîchissement automatique toutes les 10 s quand la page est visible : ce que coche l'un apparaît chez l'autre | ✅ |
| 4.13 | Copie texte | Fenêtre avec la liste en texte brut (rayons en majuscules) et bouton **Copier**, pour l'envoyer par message | ✅ |
| 4.14 | Impression | Mise en page dédiée : menu, boutons et barres masqués, petites cases à cocher au crayon | ✅ |
| 4.15 | Historique | Page Courses : listes en cours (avec progression) et terminées ; **Terminer** / **Rouvrir**, **Tout décocher**, supprimer | ✅ |
| 4.16 | Tableau de bord | Carte de la liste en cours (x / y articles) ou lien **Générer la liste** | ✅ |
| 4.17 | Tests | +37 tests (calcul, gestion, écrans) — 254 au total, exécutés aussi sur MariaDB | ✅ |

## Règles de calcul implémentées

- **R1 — Échelle** : quantité de la recette × portions planifiées ÷ portions de la recette.
- **R2 — Addition par ingrédient** :
  - masses additionnées en grammes ; volumes en millilitres, sauf si une seule unité non métrique est utilisée (« 3 c. à soupe ») ;
  - les pièces sont converties en grammes grâce au **poids moyen** de l'ingrédient pour être additionnées aux masses ;
  - si l'unité habituelle de l'ingrédient est la pièce, le résultat est affiché en pièces (« 3 oignons » plutôt que « 450 g ») ;
  - unités impossibles à convertir juxtaposées : « 200 g + 1 boîte » ;
  - « à convenance » seulement si aucune recette ne précise de quantité.
- **Arrondi** au supérieur, adapté aux courses (1,2 kg ; 3 œufs).
- **R4 — Exclusions** : restes et repas libres ignorés ; repas déjà passés exclus sauf case cochée ; repas décochés dans l'aperçu mémorisés pour les régénérations.
- **R5 — Provenance** : chaque contribution est copiée (titre, date, créneau) et reste lisible même si le repas est supprimé du planning.
- Un ingrédient **facultatif** dans toutes les recettes concernées est signalé « (facultatif) ».

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/courses?generer=AAAA-MM-JJ` | `shopping.index` | `Shopping\Index` |
| `/courses/{id}?masquer=1` | `shopping.show` | `Shopping\Show` |
| `/parametres/articles-recurrents` | `settings.recurring` | `Settings\RecurringItems` |

## Choix faits pendant le lot

- **Calcul et enregistrement séparés** : `ShoppingListGenerator` produit des lignes en mémoire (facile à tester), `ShoppingListManager` les compare à la liste enregistrée. C'est ce qui permet la régénération sans perdre ce qui a été coché, retiré ou corrigé à la main.
- **Libellés et provenance copiés** dans la liste : une liste terminée reste lisible même si une recette ou un ingrédient change ensuite.
- **Polling Livewire** (10 s, uniquement onglet visible) plutôt que WebSockets : suffisant à deux, aucune installation supplémentaire sur Wamp.
- **Copie texte** : l'API presse-papiers peut être refusée en `http://` sur téléphone ; un mécanisme de secours est prévu, et le texte reste sélectionnable à la main.
- Hors Wi-Fi (en magasin), la liste n'est pas accessible en direct : imprimer ou copier le texte avant de partir. Le mode hors-ligne (PWA) est prévu en V2.

## Prochain lot

**Lot 5 — Finitions MVP** : sauvegarde de la base (`bouffe:backup` + bouton), accès depuis les téléphones sur le réseau local, polissage de l'interface.
