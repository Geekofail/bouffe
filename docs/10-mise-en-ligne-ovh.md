# Mettre Bouffe en ligne chez OVH (hébergement mutualisé)

Guide pas à pas du lot 25 (module 27 du [document 07](07-version-3.md)). Compte une soirée pour la
première fois.

À la fin :

- Bouffe tourne sur `https://bouffe.ton-domaine.lu`, même quand le PC est éteint.
- Les données de Wamp sont reprises : recettes, photos, tickets, comptes.
- Les rappels partent toutes les 5 minutes.
- Une sauvegarde est faite chaque jour.
- La double authentification protège les comptes sensibles.

> **Ce qui a été vérifié, et ce qui ne l'a pas été.** Côté Bouffe, tout a été vérifié, sans compte
> OVH :
>
> - toute la suite de tests passe sous **MySQL 8.0** (la base d'OVH) ;
> - une sauvegarde de la base MariaDB de développement a été restaurée dans MySQL 8.0, avec le même
>   nombre de lignes pour chaque table.
>
> Les écrans de l'espace client OVH, eux, viennent de la documentation d'OVH (septembre 2026) : leurs
> intitulés peuvent varier un peu. Les points marqués **(à vérifier)** dépendent de ton hébergement.

---

## 0. Ce qu'il faut avant de commencer

| Quoi | Pourquoi |
|---|---|
| Un hébergement **OVH Pro** (Q39) | Il donne l'accès **SSH**, sans lequel on ne peut lancer ni `composer` ni `php artisan`. |
| Un nom de domaine (Q38) | Un sous-domaine d'un domaine que tu as déjà suffit : `bouffe.exemple.lu`. |
| Une adresse e-mail d'envoi | Par exemple `bouffe@exemple.lu`, créée dans l'espace client (e-mails inclus dans l'offre). Elle sert au mot de passe oublié, aux alertes de connexion et à la sauvegarde de la semaine. |
| Sur le PC | Bouffe à jour (`php artisan bouffe:deploy`) et `npm run build` fait : le dossier `public/build` part tel quel, car il n'y a pas de Node.js chez OVH. |
| Un client SFTP | [WinSCP](https://winscp.net) ou FileZilla, pour envoyer les fichiers. |

Identifiants à noter au fil du guide, dans ton gestionnaire de mots de passe :

- **FTP/SSH** : utilisateur, mot de passe, serveur (`ssh.clusterXXX.hosting.ovh.net`) ;
- **base de données** : serveur, nom, utilisateur, mot de passe ;
- **boîte e-mail** : adresse et mot de passe.

---

## 1. Le domaine et le dossier du site

Dans l'espace client OVH : **Web Cloud → Hébergements → ton hébergement → Multisite → Ajouter un
domaine ou sous-domaine**.

1. Domaine : `bouffe.exemple.lu`.
2. **Dossier racine** : `bouffe/src/public`. C'est le point important : seul le dossier `public` doit
   être visible depuis Internet. Le code, le fichier `.env` et les sauvegardes restent hors d'atteinte.
3. Coche **SSL** pour le certificat Let's Encrypt gratuit. Il peut mettre jusqu'à quelques heures à
   s'activer.
4. Si le domaine n'est pas chez OVH, ajoute l'enregistrement DNS que l'assistant indique.

---

## 2. La version de PHP : fichier `.ovhconfig`

À la **racine FTP** de l'hébergement (au-dessus de `www`), crée ou modifie `.ovhconfig` :

```ini
app.engine=php
app.engine.version=8.4

http.firewall=none
environment=production

container.image=stable64
```

- PHP 8.4, comme sous Wamp. OVH propose de 8.2 à 8.5.
- `environment=production` active le cache d'OPcache.
- Ce fichier vaut pour tout l'hébergement (à vérifier). Si d'autres sites y tournent, vérifie qu'ils
  acceptent PHP 8.4, ou place un `.ovhconfig` dans le dossier racine de chaque site.

---

## 3. La base de données

**Hébergements → ton hébergement → Bases de données → Créer une base de données**

- Type : **MySQL** (l'offre Pro inclut des bases MySQL 8).
- Note le **serveur** (du type `xxxxxx.mysql.db`), le **nom de la base**, l'**utilisateur** et le
  **mot de passe**.

Bouffe fonctionne tel quel sur MySQL 8. Le seul écart trouvé en le testant (une suppression refusée
par MySQL lors de la fusion d'ingrédients) est corrigé.

---

## 4. Envoyer les fichiers

Avec WinSCP, en **SFTP** (serveur `ssh.clusterXXX.hosting.ovh.net`, port 22, identifiants FTP) :

1. Crée `www/bouffe/`.
2. Envoie ton dossier `src` dans `www/bouffe/src`, **sans** :
   - `vendor/` (réinstallé à l'étape 5) ;
   - `node_modules/` ;
   - `.env` (le tien est celui du PC ; l'étape 6 en crée un pour le serveur) ;
   - le contenu de `storage/app/` (photos et sauvegardes du PC ; elles arrivent par la restauration,
     étape 7).

   Garde bien la structure de `storage/`, avec ses sous-dossiers vides :
   `storage/app/private`, `storage/framework/cache`, `storage/framework/sessions`,
   `storage/framework/views`, `storage/logs`.

Avec Git (lot 36), cette étape devient un `git clone` du dépôt privé : voir
[11-git-et-tests.md](11-git-et-tests.md), §4.1 (clé de déploiement, puis `git clone … bouffe` dans `~/www`).

---

## 5. Se connecter en SSH et installer les bibliothèques

Depuis PowerShell :

```powershell
ssh ton-login@ssh.clusterXXX.hosting.ovh.net
```

La commande `php` du terminal n'est pas forcément la 8.4. Crée un raccourci une fois pour toutes
(chemin de la forme `/usr/local/php8.x/bin/php`, à vérifier avec `ls /usr/local/ | grep php`) :

```bash
echo "alias php84='/usr/local/php8.4/bin/php'" >> ~/.bashrc
source ~/.bashrc
php84 -v                     # doit afficher PHP 8.4.x
```

Installe Composer dans ton dossier, puis les bibliothèques de Bouffe :

```bash
cd ~/www/bouffe/src
curl -sS https://getcomposer.org/installer | php84
php84 composer.phar install --no-dev --optimize-autoloader
```

---

## 6. Le fichier `.env` du serveur

```bash
cp .env.example .env
nano .env        # ou modifie-le avec WinSCP (clic droit → Éditer)
```

Valeurs à changer (le reste peut garder celles de `.env.example`) :

```ini
APP_NAME=Bouffe
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bouffe.exemple.lu
# Reprends la clé du .env de Wamp : les clés d'API (tickets) et les secrets de double
# authentification sauvegardés sont chiffrés avec elle.
APP_KEY=base64:...la même que sur le PC...

DB_CONNECTION=mysql
DB_HOST=xxxxxx.mysql.db
DB_PORT=3306
DB_DATABASE=nom_de_la_base
DB_USERNAME=utilisateur
DB_PASSWORD=mot_de_passe

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=ssl0.ovh.net
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=bouffe@exemple.lu
MAIL_PASSWORD=mot_de_passe_de_la_boite
MAIL_FROM_ADDRESS=bouffe@exemple.lu
MAIL_FROM_NAME=Bouffe

BOUFFE_FORCE_HTTPS=true
BOUFFE_HOSTING="chez OVHcloud, en France"

# Sauvegardes en ligne (27.9) : une par jour, 14 gardées, lien par e-mail chaque lundi
BOUFFE_BACKUP_AUTO_DAYS=1
BOUFFE_BACKUP_KEEP=14
BOUFFE_BACKUP_WEEKLY_EMAIL=true
BOUFFE_BACKUP_WEEKLY_EMAIL_DAY=1
BOUFFE_BACKUP_MIRROR=
```

Avec `APP_ENV=production`, sans rien ajouter :

- double authentification obligatoire pour l'administrateur et les responsables de foyer ;
- politique de contenu appliquée ;
- e-mail à l'administrateur en cas d'erreur grave.

Les réglages `BOUFFE_2FA_REQUIRED`, `BOUFFE_CSP` et `BOUFFE_ERROR_EMAIL` permettent de changer ces
choix.

Si tu pars d'une installation neuve (sans reprendre Wamp) : `php84 artisan key:generate`.

---

## 7. La structure de la base, puis tes données (27.8)

Sur le serveur, crée d'abord les tables :

```bash
php84 artisan migrate --force
```

Sur le **PC**, fais une sauvegarde fraîche :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:backup
```

Le fichier `bouffe-AAAA-MM-JJ_HHMMSS-manual.zip` est dans `storage\app\backups`. Il contient la base,
les photos des recettes et des réceptions, et les tickets de caisse. Envoie-le avec WinSCP dans
`www/bouffe/src/storage/app/backups/` (crée le dossier au besoin).

Sur le **serveur** :

```bash
php84 artisan bouffe:restore bouffe-AAAA-MM-JJ_HHMMSS-manual.zip --force
php84 artisan bouffe:deploy
```

La restauration adapte d'elle-même la structure MariaDB (Wamp) à MySQL (OVH) : type `uuid`,
contrôles JSON, `current_timestamp()`, interclassements. Le diagnostic de `bouffe:deploy` doit
afficher « Base de données : MySQL 8… » et « Structure à jour ».

Le PC garde sa copie : il devient l'environnement de test (§11).

---

## 8. Première connexion

1. Ouvre `https://bouffe.exemple.lu` et connecte-toi comme sur le PC.
2. Tu arrives sur **Mon compte** : la double authentification est à activer (Q41).
   - Installe une application sur ton téléphone : Google Authenticator, Aegis (Android) ou
     Microsoft Authenticator.
   - Scanne le QR code, puis recopie le code à 6 chiffres.
   - **Note les 8 codes de secours** sur papier : ils remplacent le téléphone s'il est perdu.
3. Monique fera de même à sa première connexion, puisqu'elle est responsable du foyer.

Téléphone **et** codes de secours perdus : en SSH,
`php84 artisan bouffe:user adresse@exemple.lu --sans-2fa`, puis réactiver.

---

## 9. Les tâches planifiées (27.3, Q40)

Chez OVH, la tâche planifiée tourne **au plus une fois par heure**, sans choix de la minute, et
s'arrête après 60 minutes. Les rappels de Bouffe ont besoin de passer plus souvent. D'où deux
mécanismes, qui font le même travail :

| | Fréquence | Rôle |
|---|---|---|
| **Adresse `/taches/{jeton}`** appelée par [cron-job.org](https://cron-job.org) (gratuit) | toutes les 5 minutes | Le principal |
| **`cron.php`** lancé par la tâche planifiée OVH | toutes les heures | Le secours si le service externe tombe |

Un passage fait, en quelques secondes :

- les rappels, les notifications et le récapitulatif ;
- la clôture des repas, les dépenses récurrentes et les purges ;
- la **sauvegarde du jour** ;
- le lundi, l'**e-mail** avec le lien de la sauvegarde.

Deux appels simultanés ne se gênent pas.

**Service externe**

1. Dans Bouffe : **Paramètres → Mise en ligne → Afficher**, puis copie l'adresse.
2. Sur cron-job.org : **Create cronjob**, colle l'adresse, choisis **Every 5 minutes**, et active les
   alertes en cas d'échec.
3. Si l'adresse fuit : bouton **Changer** dans Paramètres → Mise en ligne, puis reporte la nouvelle
   adresse sur cron-job.org.

**Secours OVH** : **Hébergements → ton hébergement → Tâches planifiées - Cron → Ajouter une
planification**.

- Commande : `www/bouffe/src/cron.php` (chemin depuis la racine FTP).
- Langage : PHP 8.4.
- Fréquence : toutes les heures.
- Rapport d'erreurs : ton adresse (OVH l'envoie une fois par jour).

Sans service externe, tout fonctionne quand même, avec au plus une heure de retard sur les rappels.

---

## 10. Surveillance (27.11)

- L'adresse `https://bouffe.exemple.lu/sante` répond `{"status":"ok",…}` (code 200), ou le code 503
  si la base, le stockage ou les tâches sont en panne (aucun passage depuis 90 minutes). Elle ne
  contient aucune donnée des foyers.
- Ajoute-la dans [UptimeRobot](https://uptimerobot.com) (gratuit) : moniteur HTTP(s), toutes les
  5 minutes, alerte par e-mail.
- Erreur grave dans Bouffe : un e-mail part à l'administrateur, **un par heure au plus**. Le détail
  est dans `storage/logs/laravel.log`.
- **Paramètres → Mise en ligne** résume tout : tâches, sécurité, surveillance, sauvegardes.
  **Paramètres → Diagnostic** donne le détail.

---

## 11. Mettre à jour (27.10)

Le PC (Wamp) devient l'**environnement de test** : chaque lot y est essayé avant d'aller en ligne.

**Avec Git** (recommandé ; dépôt privé sur GitHub ou GitLab) :

```bash
cd ~/www/bouffe/src
php84 artisan bouffe:deploy --git
```

Lot 36 : `--git` récupère la version de `main` (avance rapide seulement, rien n'est écrasé si un
fichier a été modifié sur le serveur) et relance `composer install` si besoin. Avant, étiquette la
version (`git tag mise-en-ligne-…` puis `git push origin <étiquette>`) et attends que les tests
« Toutes les bases » soient au vert : [11-git-et-tests.md](11-git-et-tests.md), §4.2.

**Sans Git** : envoie les fichiers modifiés avec WinSCP (mêmes exclusions qu'à l'étape 4), puis
`php84 artisan bouffe:deploy`.

`bouffe:deploy` enchaîne :

1. une sauvegarde ;
2. le passage en **maintenance** : les visiteurs voient « Maintenance en cours » et la page se
   recharge seule ;
3. la mise à jour de la base et le vidage des caches ;
4. la réouverture du site, même en cas d'erreur ;
5. le diagnostic.

La commande prévient aussi si `composer.lock` ne correspond plus au dossier `vendor`.

Pour revenir en arrière : `php84 artisan bouffe:restore` et choisir la sauvegarde « Avant
restauration » (ou « auto ») prise juste avant la mise à jour.

---

## 12. Sécurité en place (27.4 à 27.7)

| Mesure | Détail |
|---|---|
| Double authentification | Codes TOTP, 8 codes de secours, « appareil de confiance » 30 jours. Obligatoire pour l'administrateur et les responsables, facultative pour les autres. |
| Mot de passe oublié | Lien par e-mail valable 60 minutes, même réponse que l'adresse existe ou non, 3 demandes par quart d'heure et par adresse IP. |
| Appareils et journal | Mon compte → Appareils connectés (déconnexion à distance), Dernières connexions (180 jours), e-mail quand un navigateur inconnu se connecte. |
| Limitation des essais | Connexion : 5 essais par adresse et 20 par IP. Codes de vérification : 5 essais. Invitations et réinitialisation : 30 ouvertures par minute. |
| En-têtes | Politique de contenu (scripts de Bouffe seulement), HSTS en https, pas d'affichage dans le cadre d'un autre site, caméra réservée au site. |
| Cookies | `SESSION_SECURE_COOKIE=true` : jamais envoyés en http://. |
| Envois de fichiers | Types et tailles vérifiés, photos ré-encodées (déjà le cas depuis les lots 2 et 23). |

---

## 13. En cas de problème

| Symptôme | Piste |
|---|---|
| Page blanche ou « 500 » | `storage/logs/laravel.log`. Souvent : `.env` incomplet, dossiers `storage/framework/*` manquants, ou mauvaise version de PHP (`.ovhconfig`). |
| « No application encryption key » | `APP_KEY` vide : reprends celle du PC, ou `php84 artisan key:generate` pour une installation neuve. |
| Les clés d'API des tickets ont disparu après la restauration | `APP_KEY` différente de celle du PC : remets la même, ou ressaisis les clés dans Paramètres → Tickets de caisse. |
| Le site s'ouvre en `http://` sans basculer en `https://` | Vérifie le SSL du multisite. Sinon (à vérifier), ajoute en tête de `public/.htaccess` : `RewriteCond %{HTTPS} off` puis `RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]` (sous `RewriteEngine On`). |
| Le diagnostic dit « HTTPS : non » alors que l'adresse est en https | Vérifie `APP_URL=https://…` et `BOUFFE_FORCE_HTTPS=true`. |
| Aucun e-mail ne part | Paramètres → Mise en ligne → « Envoi d'e-mails ». Vérifie `MAIL_*`, et le mot de passe de la boîte dans l'espace client. |
| Un écran reste figé après une mise à jour | Recharge la page (Ctrl+F5) ; les fichiers de `public/build` changent de nom à chaque version. Si la console du navigateur signale « Content Security Policy », mets `BOUFFE_CSP=report` le temps de corriger et préviens-moi. |
| La tâche OVH est « désactivée » | Elle s'arrête après 10 échecs de suite. Regarde ses journaux dans l'espace client, corrige, réactive-la. |

---

## Sources

- OVHcloud :
  - [tâches planifiées de l'hébergement web](https://docs.ovhcloud.com/en/guides/web-cloud/web-hosting/cron-tasks) (une exécution par heure au plus, 60 minutes, chemin depuis la racine FTP) ;
  - [configuration et `.ovhconfig`](https://github.com/ovh/docs/blob/develop/pages/web_cloud/web_hosting/configure_your_web_hosting/guide.fr-fr.md) ;
  - [versions de PHP disponibles](https://docs.ovhcloud.com/en/guides/web-cloud/web-hosting/web-hosting-main-info).
- Communauté : [Laravel sur un mutualisé OVH, PHP en ligne de commande via `/usr/local/php8.x/bin/php`](https://grafikart.fr/forum/37588) ; [choisir la version de PHP en SSH](https://dev.to/capripot/use-php-cli-5-4-on-ovhcloud-performance-hosting-ah0).
- OVH et MySQL 8 : [passage des bases mutualisées à MySQL 8.0](https://www.wasi.fr/ovh-va-passer-a-mysql-8-faut-il-mettre-a-jour-version-de-mysql/).
