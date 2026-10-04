# Lot 27 — Prix et magasins

**Objectif** : savoir où acheter moins cher, et comment évoluent les prix de ce qu'on achète.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste. C'est le dernier lot de la
version 3.

- **855 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0.
- Aucune migration : tout est calculé à partir des prix déjà relevés.
- Vérifié dans le navigateur avec des **prix d'essai** saisis dans la base de développement (pas tes
  données) :
  - à 1280 px en mode clair ;
  - sur iPhone 390 px en mode sombre ;
  - aucune erreur de politique de contenu, aucun débordement.

Spécification : [07-version-3.md](../07-version-3.md), idées **C1** et **C2**.

## Mise à jour depuis le lot 26

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| C1 | **Comparer les magasins** | Page **Prix et magasins** (bouton **Prix** dans Budget et dans Analyses ; « Plus » → Prix sur téléphone). Chaque magasin est comparé au **magasin des listes de courses** : « Lidl — 5 % moins cher sur 6 produits en commun ». Puis, produit par produit, les derniers prix de chaque magasin du moins cher au plus cher, ramenés au kilo, au litre ou à la pièce. | ✅ |
| C1 | **Historique d'un produit** | Un clic sur un produit ouvre ses derniers relevés (date, magasin, prix payé, quantité, origine : ticket, liste ou noté). Un relevé faux se supprime, par exemple une virgule mal lue sur un ticket. | ✅ |
| C1 | **Noter un prix** | En rayon : produit, magasin, « 2,49 € pour 500 g », date. | ✅ |
| C1 | **Liste répartie entre deux magasins** | Sur une liste rangée par magasin, le bandeau **Moins cher ailleurs** indique, pour chaque autre magasin, les articles moins chers d'au moins 5 %, avec l'économie estimée. **Les acheter chez Lidl** les range dans un bloc **Chez Lidl** en bas de la liste. **Tout reprendre ici** les remet dans la liste. | ✅ |
| C2 | **Évolution de nos prix** | L'indice de **notre panier** mois par mois (100 au départ), avec le chiffre sur la période : « +3,2 % depuis octobre 2025, sur 14 produits suivis ». Puis chaque produit suivi : premier relevé, dernier relevé, évolution. | ✅ |
| C2 | **Hausses marquées** | Un produit dont le dernier prix dépasse d'au moins 15 % le précédent, dans le même magasin, depuis moins de 60 jours. Il est affiché en tête de l'onglet Évolution et dans le bloc **Hausses de prix** de l'accueil (30 derniers jours, masquable dans Paramètres → Affichage). | ✅ |
| — | Tests | +17 tests — 855 au total | ✅ |

## Comment les chiffres sont faits

**Comparer.** On compare le **dernier** prix relevé de chaque produit dans chaque magasin.

- Seuls les prix des **6 derniers mois** comptent. Au-delà de 3 mois, un prix est marqué « (ancien) ».
- Deux prix ne se comparent que dans la **même unité** : un prix au kilo ne se compare pas à un prix
  « à la boîte ».
- L'écart entre deux magasins est une moyenne des rapports de prix, calculée seulement sur les
  produits relevés dans les deux. Il n'est pas donné sous **3 produits en commun**.
- Pour la liste de courses, un article n'est proposé ailleurs que si son prix est connu **dans le
  magasin de la liste**. Sinon, on ne peut pas savoir s'il est moins cher ailleurs.
- L'économie n'est chiffrée que si la quantité de l'article se convertit dans l'unité du prix. Sinon
  le bandeau indique « sans quantité comparable ».

**Évolution.** Un produit est **suivi** quand il a été relevé au moins deux fois dans le **même
magasin**, à au moins 4 semaines d'écart. Changer de magasin ne compte pas comme une hausse.

- Le **panier** regroupe les produits suivis qui ont été relevés dans les 3 premiers mois de la
  période, puis revus ensuite.
- Chaque mois, on prend le dernier prix connu de chaque produit, rapporté à son premier prix.
- La moyenne est **pondérée par ce qu'on a dépensé** pour chaque produit : le beurre acheté chaque
  semaine compte plus que le safran acheté une fois.
- Sous 3 produits dans le panier, aucun indice n'est donné. La page dit combien de produits et de
  relevés il y a.

**Deux règles de prix changent** :

- un prix noté après coup, avec une date plus ancienne que le prix retenu pour le produit, ne le
  remplace plus ;
- supprimer un relevé rend au produit le prix du dernier relevé restant. Un prix fixé à la main
  n'est jamais touché.

## Choix et limites

- **Pas de notification** pour une hausse de prix : elle apparaît sur l'accueil et sur la page.
- **Une promotion compte comme un prix.** La fin d'une promotion peut donc apparaître comme une
  hausse marquée. Le texte de l'encadré le signale.
- **Tout dépend des relevés.** Avec les tickets lus depuis le lot 23, il faudra quelques mois avant
  que l'indice du panier apparaisse. C'est voulu : quelques relevés ne font pas une tendance.
- **Magasin de référence** : celui proposé par défaut pour les listes. S'il n'a aucun prix, c'est le
  magasin qui en a le plus.
- Les prix restent **propres à chaque foyer**. Ils ne sont pas partagés avec les foyers reliés.

## Fichiers

| Fichier | Rôle |
|---|---|
| `app/Services/Pricing/PriceComparison.php` | Derniers prix par magasin, produits comparés, magasins face à la référence |
| `app/Services/Pricing/StoreSplit.php` | Articles moins chers ailleurs, économie estimée, réserver ou reprendre |
| `app/Services/Pricing/PersonalInflation.php` | Produits suivis, indice du panier, hausses marquées |
| `app/Livewire/Prices/Index.php` + `resources/views/livewire/prices/index.blade.php` | Page « Prix et magasins » |
| `tests/Feature/Pricing/PricesTest.php` | 17 tests |

Fichiers modifiés :

- `PriceBook` : unité de comparaison, affichage au kilo ou au litre, suppression d'un relevé, relevé
  plus ancien ;
- liste de courses (bandeau « Moins cher ailleurs », blocs « Chez … ») ;
- accueil (bloc « Hausses de prix ») ;
- navigation (section « Prix », Budget reste allumé sur `/prix`) ;
- Budget et Analyses (bouton **Prix**) ;
- icône « tendance » ;
- routes.

## À vérifier sur ton poste

- Après `bouffe:deploy`, ouvre **Budget → Prix**. Avec tes tickets déjà lus, les magasins où tu as
  des prix apparaissent. Coche « Relevés dans un seul magasin aussi » pour tout voir.
- Ouvre un produit et vérifie ses relevés. Supprime un relevé manifestement faux s'il y en a.
- Sur une liste de courses rangée pour ton magasin habituel, regarde si « Moins cher ailleurs »
  apparaît. Il faut des prix relevés dans deux magasins pour les mêmes produits.
- L'onglet **Évolution** dira probablement « Pas encore assez de recul » : c'est normal tant que les
  relevés ne couvrent pas plusieurs mois.
