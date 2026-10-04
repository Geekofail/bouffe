# Lot 25 — En ligne chez OVH

**Objectif** : Bouffe en ligne, sécurisé, PC éteint.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste, puis chez OVH.

- **822 tests** passent sous SQLite, MariaDB 10.11 **et MySQL 8.0**.
- Une sauvegarde de la base MariaDB de développement a été restaurée dans MySQL 8.0 : même nombre de
  lignes dans chaque table, diagnostic « MySQL 8.0 · structure à jour ».
- Vérifications dans le navigateur :
  - 1280 px en mode clair, et iPhone 390 px en mode sombre ;
  - activation de la double authentification (QR code scanné, code, codes de secours), puis
    connexion avec code ;
  - aucune erreur de politique de contenu sur les 24 pages parcourues, navigation Livewire comprise ;
  - aucun débordement horizontal.

Spécification : [07-version-3.md](../07-version-3.md), module 27.

Mode d'emploi de la mise en ligne : **[10-mise-en-ligne-ovh.md](../10-mise-en-ligne-ovh.md)**.

## Mise à jour depuis le lot 24 (sur le PC)

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Sur le PC, rien ne change au quotidien :

- la double authentification reste **facultative** (`APP_ENV=local`) ;
- la politique de contenu est seulement **signalée** dans la console, et non appliquée, pour garder
  la page d'erreur détaillée de Laravel.

Tu peux essayer la double authentification dans **Mon compte** (icône « personne » en haut à droite).

**Réglages par défaut appliqués** (questions sans réponse) :

- Q38 : domaine à choisir ; le guide prend un sous-domaine.
- Q39 : offre OVH **Pro**.
- Q40 : service externe (cron-job.org) toutes les 5 minutes, et tâche OVH horaire en secours.
- Q41 : double authentification **obligatoire pour l'administrateur et les responsables de foyer**,
  facultative pour les autres.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 27.1 | **MySQL 8** | Suite complète sous MySQL 8.0.46. Un seul écart trouvé et corrigé : la fusion d'ingrédients supprimait avec une sous-requête sur la même table (erreur MySQL 1093). Le diagnostic distingue MySQL de MariaDB. | ✅ |
| 27.2 | **Guide** | [10-mise-en-ligne-ovh.md](../10-mise-en-ligne-ovh.md) : Pro, multisite sur `src/public`, `.ovhconfig` PHP 8.4, base, SSH et Composer, `.env`, HTTPS, e-mails, dépannage. | ✅ |
| 27.3 | **Tâches** | Adresse `/taches/{jeton}` (jeton chiffré, comparaison à temps constant, 12 appels par minute au plus, sans session ni cookie) et `cron.php` pour la tâche OVH. Commande `bouffe:tasks` : rappels, sauvegarde du jour, e-mail de la semaine, purges. Verrou contre les passages simultanés ; une étape en échec n'arrête pas les autres. | ✅ |
| 27.4 | **Double authentification** | TOTP (RFC 6238, écrit à la main et testé sur les valeurs de la RFC), secret chiffré, QR code, 8 codes de secours gardés en condensés, un code ne sert qu'une fois, appareil de confiance 30 jours (cookie signé). Obligatoire selon Q41 quand `BOUFFE_2FA_REQUIRED` (vrai par défaut en production). `bouffe:user … --sans-2fa` en dernier recours. | ✅ |
| 27.5 | **Sessions et alertes** | Appareils connectés, déconnectables un par un ou tous ensemble (le jeton « rester connecté » est renouvelé). Journal des connexions sur 180 jours. E-mail « nouvelle connexion » pour un navigateur jamais vu. Limites : 20 essais par IP en plus des 5 par adresse ; invitations et réinitialisation limitées. | ✅ |
| 27.6 | **Mot de passe oublié** | Lien par e-mail en français, valable 60 minutes. Même réponse que le compte existe ou non. 3 demandes par quart d'heure. Après réinitialisation, toutes les sessions du compte sont fermées. | ✅ |
| 27.7 | **En-têtes** | Politique de contenu avec *nonce* (appliquée · signalée · coupée) ; HSTS en https ; `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` (caméra réservée au site). Plus aucun `onclick` dans les vues. | ✅ |
| 27.8 | **Migration des données** | La sauvegarde emporte aussi les **tickets de caisse**. La restauration adapte la structure MariaDB ↔ MySQL. Testé : base de développement MariaDB → MySQL 8. | ✅ |
| 27.9 | **Sauvegardes en ligne** | Sauvegarde du jour par les tâches (`BOUFFE_BACKUP_AUTO_DAYS=1`, 14 gardées). E-mail hebdomadaire à l'administrateur avec le lien de téléchargement, qui demande d'être connecté. | ✅ |
| 27.10 | **Déploiement** | `bouffe:deploy` passe le site en maintenance pendant la mise à jour et le rouvre toujours. Il prévient si `vendor` ne correspond plus à `composer.lock`. Instructions Git dans le guide. | ✅ |
| 27.11 | **Surveillance** | `/sante` : JSON, 200 ou 503 (base, stockage, dernière tâche), sans donnée privée. E-mail à l'administrateur sur erreur grave, un par heure au plus. | ✅ |
| 27.12 | **Vos données** | Page lisible sans compte. Elle dit ce qui est enregistré, où, qui y a accès et quels services extérieurs sont appelés, d'après la configuration réelle. Nouvelle page **Mon compte** (profil, mot de passe, double authentification, appareils, journal, suppression du compte). | ✅ |
| — | Tests | +31 tests — 822 au total | ✅ |

## Où trouver quoi

- **Mon compte** :
  - sur ordinateur, icône « personne » en haut à droite (menu : Mon compte, Vos données, Se
    déconnecter) ;
  - sur téléphone, « Plus » puis « Mon compte » ;
  - dans Paramètres, premier onglet.
- **Paramètres → Mise en ligne** (administrateur) :
  - adresse des tâches (masquée, « Afficher », « Changer ») et dernier passage, bouton « Lancer
    maintenant » ;
  - état de la sécurité et adresse de surveillance ;
  - sauvegardes en ligne.
- **Vos données** : lien en bas des pages de connexion, dans le menu du compte et dans Paramètres.
- **Mot de passe oublié ?** : lien sur l'écran de connexion.

## Choix et écarts

- **Pas de bibliothèque ajoutée.**
  - Le TOTP tient en une centaine de lignes, vérifiées sur les valeurs de test de la RFC 6238.
  - Le QR code réutilise celui de « Accès téléphone ».
  - Les e-mails passent par un seul modèle simple (`App\Mail\Notice`, HTML et texte).
- **Politique de contenu** :
  - `'unsafe-eval'` reste nécessaire à Alpine (expressions dans `x-data`, `x-on`…) ;
  - `style-src 'unsafe-inline'` reste nécessaire aux attributs `style` (barres, Alpine) ;
  - tout le reste est limité au site lui-même ;
  - les balises `<script>` des vues portent `@nonce`, et un test vérifie qu'aucune n'en manque.
- **Suppression du compte** : refusée à l'administrateur unique, à un responsable unique d'un foyer
  qui a d'autres membres, et à qui est seul dans un foyer non supprimé. Ce qu'on a saisi dans un
  foyer reste au foyer.
- **Appareils connectés** : il faut `SESSION_DRIVER=database`, ce qui est déjà le cas.
- **Tâche Windows** : `bouffe:reminders --tache-windows` propose désormais `bouffe:tasks`, qui fait
  en plus la sauvegarde automatique. Ta tâche actuelle continue de fonctionner telle quelle.
- **Non vérifiable d'ici** : les écrans de l'espace client OVH et le comportement exact de leur
  proxy HTTPS. Le guide marque ces points « (à vérifier) » et donne la parade.

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_07_100000_create_lot25_security.php` | Colonnes de double authentification, table `login_events` |
| `app/Support/Totp.php`, `app/Support/DeviceLabel.php` | Codes TOTP ; « Chrome sur Windows » |
| `app/Services/Security/TwoFactor.php`, `LoginJournal.php`, `AccountDeletion.php` | Double authentification ; journal et appareils ; suppression du compte |
| `app/Services/System/TaskRunner.php`, `TaskToken.php`, `Health.php`, `ErrorAlert.php` | Tâches ; jeton ; `/sante` ; alerte sur erreur |
| `app/Services/Backup/SqlDialect.php` | Structure MariaDB ↔ MySQL à la restauration |
| `app/Http/Middleware/EnsureTwoFactor.php`, `SecurityHeaders.php` | Double authentification obligatoire ; en-têtes |
| `app/Http/Controllers/TasksController.php`, `HealthController.php` | `/taches/{jeton}`, `/sante` |
| `app/Console/Commands/TasksCommand.php`, `cron.php` | `bouffe:tasks` ; script de la tâche OVH |
| `app/Livewire/Auth/TwoFactorChallenge.php`, `ForgotPassword.php`, `ResetPassword.php` + vues | Code de vérification, mot de passe oublié |
| `app/Livewire/Account/Show.php`, `Privacy.php`, `app/Livewire/Settings/Online.php` + vues | Mon compte, Vos données, Mise en ligne |
| `app/Models/LoginEvent.php`, `app/Mail/Notice.php` + `mail/notice*.blade.php` | Journal ; e-mails courts |
| `tests/Feature/Online/OnlineTest.php`, `tests/Backup/OnlineBackupTest.php` | 31 tests |
| `docs/10-mise-en-ligne-ovh.md` | Le guide |

Fichiers modifiés :

- connexion (étape du code, lien « oublié », limite par IP) ;
- barre du haut (menu du compte), onglets et accueil des Paramètres ;
- `bouffe:deploy`, `bouffe:user` et `BackupManager` (tickets, base vide, dialecte) ;
- diagnostic (MySQL, double authentification, tâches) ;
- fusion d'ingrédients (MySQL), `routes`, `bootstrap/app.php`, `config/bouffe.php`, `.env.example` ;
- vues contenant un `<script>` ou un `onclick`.

## À vérifier sur ton poste

- Après `bouffe:deploy`, tout est comme avant. Le menu « personne » en haut à droite ouvre Mon
  compte.
- Mon compte :
  - active la double authentification avec ton téléphone et note les codes de secours ;
  - déconnecte-toi, puis reconnecte-toi : le code est demandé ;
  - coche « Ne plus demander… » pour cet ordinateur.
- Paramètres → Mise en ligne : l'état « Sécurité » est orange sur le PC (http, débogage), c'est
  normal ; tout passe au vert une fois en ligne.
- Quand tu es prêt pour OVH : suis [10-mise-en-ligne-ovh.md](../10-mise-en-ligne-ovh.md) de bout en
  bout.
