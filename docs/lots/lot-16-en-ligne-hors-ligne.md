# Lot 16 — En ligne et hors-ligne

**Objectif** : la liste de courses fonctionne en magasin, même sans réseau.

**Statut** : ✅ développé et vérifié en local (584 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 / 900 px et iPhone 390 px, mode clair et mode sombre, **avec scénario mode avion**) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 20 (20.1 à 20.8), 15.1, 15.4, et règle **R20**.

## Mise à jour depuis le lot 15

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

C'est la nouvelle commande de mise à jour (20.6) : elle sauvegarde, met la base à jour, vide les caches et affiche le diagnostic. L'ancienne façon de faire reste valable :

```powershell
php artisan migrate      # table offline_operations
php artisan optimize:clear
php artisan test         # 584 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 20.2 | **Application installable (PWA)** | Manifeste complet (icônes, raccourcis Courses / Planning / Que cuisiner), service worker (`public/sw.js`), page « Pas de réseau », bandeau d'invitation à installer sur l'écran d'accueil | ✅ |
| 15.1 / 20.3 | **Liste hors-ligne** (R20) | La liste est embarquée dans la page ; cocher, décocher et ajouter ne demandent **jamais** le réseau ; les gestes sont gardés sur le téléphone et remontent tout seuls | ✅ |
| 15.4 | **Mode magasin** | Page à grands caractères, rayons repliables avec compteur, « Tout ce rayon est fait », masquer ce qui est pris, barre de progression, écran gardé allumé, ajout rapide en bas | ✅ |
| 20.4 | **Sauvegarde externe** | Chaque sauvegarde est recopiée dans un dossier hors du PC (`BOUFFE_BACKUP_MIRROR`) ; un disque débranché est signalé, pas bloquant | ✅ |
| 20.5 | **Sécurité** | Blocage après 5 essais de connexion ratés (déjà en place), réglable ; état affiché dans le diagnostic | ✅ |
| 20.6 | **Mise à jour** | `php artisan bouffe:deploy` + section « À propos » dans Paramètres → Diagnostic | ✅ |
| 20.7 | **Diagnostic** | Paramètres → Diagnostic : 16 vérifications (PHP, extensions, mémoire, base, structure, droits d'écriture, photos, disque, sauvegardes, copie externe, clé, débogage, HTTPS, comptes, blocage) avec le geste à faire pour chaque point | ✅ |
| 20.8 | **Export des données** | Archive `bouffe-export-AAAA-MM-JJ.zip` : `donnees.json`, `recettes.md`, `photos/`, `lisez-moi.txt` — lisible sans Bouffe | ✅ |
| 20.1 | **Accès sécurisé** | Proxys de confiance + `BOUFFE_FORCE_HTTPS` ; guide du tunnel privé : [09-acces-distant.md](../09-acces-distant.md) | ✅ |
| 16.9 | Tests | +22 tests (rejeu hors-ligne et les trois conflits de R20, mode magasin, diagnostic, export, copie externe, `bouffe:deploy`) — 584 au total | ✅ |

## Règle R20 — ce qui se passe au retour du réseau

Le téléphone envoie chaque geste avec **l'heure à laquelle il a eu lieu** et un identifiant unique. Le serveur les rejoue **dans l'ordre des gestes**, pas dans l'ordre d'arrivée, et explique ce qu'il a fait.

| Situation | Ce que fait Bouffe | Message |
|---|---|---|
| Geste normal | Appliqué | — |
| Même geste envoyé deux fois (réseau instable, onglet rouvert) | Compté **une seule fois** (identifiant unique) | — |
| Coché puis décoché, arrivés à l'envers | Rejoué dans l'ordre des gestes : décoché | — |
| L'article a disparu (liste vidée, article supprimé) | Geste **ignoré** | « Article retiré de la liste entre-temps : modification ignorée. » |
| La liste a été **régénérée** pendant les courses | La coche est **reportée** sur l'article du même ingrédient | « La liste a été régénérée : « Beurre » a été reporté sur le nouvel article. » |
| Quelqu'un a coché le même article **plus récemment** à la maison | Le geste du magasin **n'écrase pas** | « « Beurre » a été modifié plus récemment sur un autre appareil : geste ignoré. » |

Tout est tracé dans la table `offline_operations` : ce qui a été fait, quand, par qui, et le résultat.

## Le mode magasin en pratique

1. Sur la page de la liste, bouton **Mode magasin** (à côté de « Mettre à jour »).
2. La page s'ouvre : gros caractères, un rayon par bloc, compteur « 2 / 5 » par rayon.
3. Un appui coche l'article (vibration courte), un appui sur le rayon le replie.
4. **Tout ce rayon est fait** coche d'un coup ce qui reste dans le rayon.
5. L'icône soleil garde l'écran allumé (HTTPS uniquement, voir plus bas).
6. L'icône filtre masque ce qui est déjà dans le panier.
7. En bas, un champ pour ajouter ce qu'on a vu en rayon.

Sans réseau, un bandeau noir **Hors ligne · N modification(s) en attente** apparaît. Tout continue de fonctionner. Dès le retour du réseau, le bandeau passe en « Envoi en cours… » puis disparaît ; les éventuels messages de conflit s'affichent sous la liste.

## Choix techniques

- **Page hors Livewire.** Une page Livewire a besoin du serveur à chaque geste : c'est exactement ce qu'on ne veut pas en magasin. Le mode magasin est une page autonome (Blade + Alpine), la liste est écrite dans la page, la file d'attente est dans `localStorage`.
- **Deux aller-retours seulement** : `POST /courses/{id}/synchroniser` (les gestes, réponse = rapport + liste à jour) et `GET /courses/{id}/etat` (juste la liste, quand il n'y a rien à envoyer). Un rafraîchissement au retour sur la page, sinon toutes les 30 secondes.
- **Service worker** : les fichiers construits sont pris dans le cache, les pages sont demandées au réseau puis servies depuis le cache si le réseau manque, et à défaut la page « Pas de réseau ».
- **HTTPS**. Le service worker, l'installation sur l'écran d'accueil et le maintien de l'écran allumé demandent une origine sûre : `https://` ou `localhost`. En `http://` sur le Wi-Fi de la maison, la liste hors-ligne fonctionne **tant que l'onglet reste ouvert** (rien n'est perdu), mais un rechargement de page a besoin du réseau. Avec le tunnel privé du point 20.1, tout fonctionne, rechargement compris.

## Réglages ajoutés dans `.env`

```ini
# Copie des sauvegardes hors du PC (20.4) — clé USB, disque externe, OneDrive…
BOUFFE_BACKUP_MIRROR="D:\Sauvegardes\Bouffe"
BOUFFE_BACKUP_MIRROR_KEEP=5

# Accès depuis l'extérieur (20.1) — voir docs/09-acces-distant.md
BOUFFE_TRUSTED_PROXIES="*"
BOUFFE_FORCE_HTTPS=true

# Sécurité (20.5)
BOUFFE_LOGIN_ATTEMPTS=5
BOUFFE_LOCKOUT_MINUTES=1
```

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_09_28_100000_create_lot16_tables.php` | Table `offline_operations` |
| `app/Models/OfflineOperation.php` | Trace d'un geste rejoué |
| `app/Services/Shopping/OfflineSync.php` | Rejeu des gestes (R20) et photo de la liste |
| `app/Http/Controllers/StoreModeController.php` | Page magasin, état, synchronisation |
| `resources/views/shopping/store-mode.blade.php` | La page magasin (hors Livewire) |
| `resources/js/app.js` (ajouts) | `storeMode()`, `bouffeInstall()`, enregistrement du service worker |
| `public/sw.js`, `public/manifest.webmanifest` | PWA |
| `resources/views/offline.blade.php` | Page « Pas de réseau » |
| `resources/views/components/install-prompt.blade.php` | Invitation à installer |
| `app/Services/System/Diagnostic.php` | Les 16 vérifications |
| `app/Services/System/DataExporter.php` | Export des données (20.8) |
| `app/Livewire/Settings/SystemCheck.php` + vue | Paramètres → Diagnostic, À propos |
| `app/Console/Commands/DeployCommand.php` | `php artisan bouffe:deploy` |
| `tests/Feature/Shopping/OfflineSyncTest.php`, `tests/Feature/System/DiagnosticTest.php` | 22 tests |

## À vérifier sur ton poste

- Ouvrir une liste → **Mode magasin** sur le téléphone, mettre le téléphone en mode avion, cocher quelques articles et en ajouter un, puis ressortir du mode avion : le bandeau doit disparaître et la liste être à jour sur le PC.
- Paramètres → Diagnostic : les points en orange chez moi sont « Mode débogage activé » (normal en développement), « Copie hors du PC pas configurée » (20.4, à toi de choisir le dossier) et « Accès chiffré : non » (normal sur le Wi-Fi).
- Paramètres → Diagnostic → **Télécharger l'export** : ouvrir `recettes.md` et vérifier que tes recettes sont lisibles.
