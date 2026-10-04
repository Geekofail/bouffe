# Lot 8 — Péremption & alertes

**Objectif** : voir à temps, sans ouvrir la page Stock, ce qui doit être mangé, congelé ou jeté.

**Statut** : ✅ développé et vérifié en local (355 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur ordinateur 1280 / 1024 / 768 px et iPhone 390 px) — ⏳ à valider sur ton poste.

Spécification : [05-evolutions-convives-stock.md](../05-evolutions-convives-stock.md), module 11 (11.2 à 11.5) et règle R11.

## Mise à jour depuis le lot 7

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # table settings
php artisan view:clear
php artisan test         # 355 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 8.1 | Date effective et niveaux | Calcul du lot 7 (R11) : ouverture, congélation, décongélation, plats préparés ; niveaux Dépassée · Aujourd'hui / demain · Bientôt · À vérifier | ✅ (lot 7) |
| 8.2 | Réglages enregistrés | Table `settings` (clé / valeur) et `App\Support\Settings`, avec repli sur `.env` | ✅ |
| 8.3 | Paramètres → Alertes stock | Délai « bientôt » des DLC et dates estimées (1 à 14 jours, 3 par défaut) et des DDM (1 à 60, 7 par défaut), exemples en direct ; badge du menu et bandeau du planning activables | ✅ |
| 8.4 | Service `ExpiryAlerts` | Produits à surveiller triés par urgence, compteurs (total et urgents), produits qui périment avant une date | ✅ |
| 8.5 | Badge du menu | Nombre de produits à surveiller sur **Stock** (ordinateur et barre du bas du téléphone) : rouge si une DLC est dépassée, du jour ou du lendemain, orange sinon | ✅ |
| 8.6 | Accueil : « À consommer rapidement » | Les 6 produits les plus urgents, avec date, quantité et emplacement ; mention « à jeter » ou « encore consommable, à vérifier » ; lien vers le stock filtré | ✅ |
| 8.7 | Actions depuis l'alerte | **Consommé**, **Jeté** (raison), **Congeler** (si congelable et pas dépassé), **Ouvert** (si durée après ouverture), **Date…** (nouvelle date) ; bouton **Annuler** après chaque action | ✅ |
| 8.8 | Planning | Bandeau sur la semaine en cours : « 2 produits à consommer d'ici dimanche : Crème liquide (J-3), Restes de chili (J-3) » → **Voir le stock** | ✅ |
| 8.9 | Page Stock | Nouveau filtre **À surveiller** (tous les niveaux d'alerte), cible des liens du badge, de l'accueil et du planning | ✅ |
| 8.10 | Tests | +8 tests (tri et compteurs, délais réglables, écran de réglages, badge, carte de l'accueil et ses actions, bandeau du planning, filtre) — 355 au total | ✅ |

## Règles

- **À surveiller** = DLC dépassée, DLC aujourd'hui ou demain, « bientôt » selon les délais réglés, DDM ou date estimée dépassée.
- **Urgent** (badge rouge) = DLC dépassée, du jour ou du lendemain.
- **Bandeau du planning** : produits dont la date effective tombe au plus tard le dimanche de la semaine en cours, dates dépassées comprises, produits congelés exclus. Rien sur les autres semaines.
- Congeler est proposé uniquement pour un produit congelable dont la DLC n'est pas dépassée.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/parametres/stock` | `settings.stock` | `Settings\StockSettings` |
| `/stock?filtre=alertes` | `stock.index` | `Stock\Index` |
| *(accueil)* | — | `Stock\ExpiryAlertsCard` |

## Choix faits pendant le lot

- **Composant de carte réutilisable** (`ExpiryAlertsCard`) : il pourra être placé sur d'autres écrans (ex. suggestions au lot 10).
- **Réglages en base avec repli sur `.env`** : les valeurs de `.env` restent les valeurs par défaut tant que rien n'est enregistré dans l'écran. La même table servira au lot 9 (mode de déduction du stock).
- **Pas de notification sur le téléphone ni d'e-mail** : les notifications exigent HTTPS (PWA) et l'e-mail un serveur d'envoi ; prévus après un éventuel hébergement (lot 11).

## Prochain lot

**Lot 9 — Stock ↔ planning & courses** : retrait du stock quand un repas est mangé (avec confirmation), restes au frigo, liste de courses qui déduit le stock, stock minimum, inventaire.
