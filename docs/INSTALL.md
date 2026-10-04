# Installation de Bouffe sous WampServer

Guide pas à pas pour faire tourner l'application sur `http://bouffe.local`.
Toutes les commandes sont à lancer dans un terminal (PowerShell ou Invite de commandes) **dans le dossier `C:\wamp64\www\Bouffe\src`**.

---

## 1. Prérequis

### 1.1 PHP 8.3 minimum (8.4 recommandé) dans Wamp

1. Clic gauche sur l'icône Wamp → **PHP** → **Version** → choisir **8.3.x ou 8.4.x**.
   (Si absente : télécharger l'addon PHP sur <https://wampserver.aviatechno.net/> et l'installer.)
2. Clic gauche → **PHP** → **Extensions PHP** : cocher `intl`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `zip`, `curl`.
3. Clic gauche → **Apache** → **Modules Apache** : vérifier que `rewrite_module` est coché.

### 1.2 PHP en ligne de commande

Wamp n'ajoute pas PHP au `PATH` et utilise **un autre `php.ini` pour la ligne de commande** que pour Apache.

1. Ajouter au `PATH` Windows le dossier de la version choisie, par ex. `C:\wamp64\bin\php\php8.4.x`
   (Paramètres Windows → « Modifier les variables d'environnement système » → Path → Nouveau).
2. Ouvrir **`C:\wamp64\bin\php\php8.4.x\php.ini`** (celui du dossier PHP, pas `phpForApache.ini`) et décommenter (retirer le `;`) :
   ```ini
   extension=curl
   extension=fileinfo
   extension=gd
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=pdo_sqlite   ; nécessaire pour les tests automatisés
   extension=sqlite3
   extension=zip
   ```
3. Nouveau terminal, vérifier :
   ```powershell
   php -v
   php -m
   ```

### 1.3 Taille des photos envoyées

Wamp limite par défaut les envois à 2 Mo, trop peu pour une photo de téléphone. Clic gauche sur Wamp → **PHP** → **Réglages PHP** :
`upload_max_filesize` = **16M** et `post_max_size` = **20M** (faire la même modification dans le `php.ini` de la ligne de commande pour les tests).

### 1.4 Composer

Installer Composer 2 avec <https://getcomposer.org/Composer-Setup.exe> en lui indiquant le `php.exe` ci-dessus. Vérifier : `composer -V`.

### 1.5 Node.js (facultatif)

Les fichiers CSS/JS compilés sont **fournis** dans `public/build`. Node.js LTS n'est nécessaire que pour modifier le style ou le JavaScript (`npm install` puis `npm run build`).

---

## 2. Base de données

1. Démarrer Wamp (icône verte).
2. Ouvrir **phpMyAdmin** (<http://localhost/phpmyadmin>), se connecter avec `root` sans mot de passe en choisissant le serveur **MariaDB** (ou MySQL).
3. **Nouvelle base de données** : nom `bouffe`, interclassement `utf8mb4_unicode_ci` → Créer.
4. Noter le **port** du serveur choisi (menu Wamp → MariaDB → « Port utilisé ») :
   - MariaDB seule, ou MariaDB par défaut : souvent **3306** ;
   - MySQL **et** MariaDB installés : MariaDB est en général sur **3307**.

---

## 3. Installation de l'application

```powershell
cd C:\wamp64\www\Bouffe\src

# 1. Dépendances PHP (dossier vendor)
composer install

# 2. Fichier de configuration
copy .env.example .env
php artisan key:generate
```

Ouvrir `.env` et ajuster si besoin la section base de données :

```ini
DB_CONNECTION=mariadb     # ou mysql
DB_HOST=127.0.0.1
DB_PORT=3307              # le port relevé à l'étape 2.4
DB_DATABASE=bouffe
DB_USERNAME=root
DB_PASSWORD=
```

Puis :

```powershell
# 3. Création des tables et données de départ (unités, rayons, catégories, créneaux, ~150 ingrédients)
php artisan migrate --seed

# 4. Lien public vers les fichiers envoyés (photos des recettes)
php artisan storage:link

```

`php artisan migrate --seed` crée aussi les comptes **Pierre** (ptripodi@free.fr) et **Monique** (monique.van@hotmail.com).
Leur mot de passe initial est **affiché dans le terminal** (ou vaut `BOUFFE_SEED_PASSWORD` si cette ligne est renseignée dans `.env`).
Pour le changer ensuite : `php artisan bouffe:user ptripodi@free.fr`.

> `php artisan storage:link` n'est plus indispensable : les photos de recettes sont servies par l'application elle-même.

---

> **Erreur `1071 Specified key was too long; max key length is 1000 bytes`** : les tables sont créées avec le moteur MyISAM (réglage par défaut de certaines installations Wamp). Le projet force désormais InnoDB (`DB_ENGINE=InnoDB`, `config/database.php`). Relancer depuis une base vide avec `php artisan migrate:fresh`.

## 4. VirtualHost `bouffe.local`

1. Aller sur <http://localhost/add_vhost.php> (page d'accueil Wamp → « Ajouter un Virtual Host »).
2. Renseigner :
   - **Nom du Virtual Host** : `bouffe.local`
   - **Chemin complet absolu du dossier** : `C:/wamp64/www/Bouffe/src/public`
3. Valider, puis clic droit sur l'icône Wamp → **Outils** → **Redémarrage DNS**.
4. Ouvrir <http://bouffe.local> → l'écran de connexion s'affiche.

Le fichier généré se trouve dans `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf` et doit ressembler à :

```apache
<VirtualHost *:80>
    ServerName bouffe.local
    DocumentRoot "c:/wamp64/www/bouffe/src/public"
    <Directory "c:/wamp64/www/bouffe/src/public/">
        Options +Indexes +Includes +FollowSymLinks +MultiViews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

`AllowOverride All` est indispensable (Laravel utilise le fichier `public/.htaccess`).

---

## 5. Vérifier que tout fonctionne

```powershell
php artisan about        # résumé : versions, environnement, base, locale "fr"
php artisan test         # tests automatisés (Pest) — tout doit être vert
```

Puis sur <http://bouffe.local> : se connecter, parcourir les 5 onglets, se déconnecter.

---

## 6. (Optionnel) Accès depuis les téléphones sur le Wi-Fi de la maison

La page **Paramètres → Accès téléphone** affiche l'adresse du PC, un **QR code** à scanner et le bloc VirtualHost à copier, déjà complété avec la bonne adresse. En résumé :

1. **VirtualHost** (clic gauche sur Wamp → Apache → `httpd-vhosts.conf`) : ajouter l'adresse du PC en alias et autoriser le réseau local :
   ```apache
   <VirtualHost *:80>
       ServerName bouffe.local
       ServerAlias 192.168.1.20
       DocumentRoot "c:/wamp64/www/bouffe/src/public"
       <Directory "c:/wamp64/www/bouffe/src/public/">
           Options +Indexes +Includes +FollowSymLinks +MultiViews
           AllowOverride All
           Require local
           Require ip 192.168.1
       </Directory>
   </VirtualHost>
   ```
2. **Pare-feu Windows** : « Autoriser une application via le Pare-feu Windows » → cocher **Apache HTTP Server** en **Privé**. Le Wi-Fi de la maison doit être un réseau **privé**.
3. **Redémarrer Wamp**, puis ouvrir `http://192.168.1.20` sur le téléphone (ou scanner le QR code).
4. **Adresse fixe** : dans l'interface de la box, réserver l'adresse du PC (« bail statique » / « réservation DHCP »), sinon elle peut changer après un redémarrage.
5. **Écran d'accueil** : iPhone (Safari) → Partager → « Sur l'écran d'accueil » ; Android (Chrome) → ⋮ → « Ajouter à l'écran d'accueil ». L'application s'ouvre alors en plein écran avec son icône.

Pas besoin de modifier `APP_URL` : les liens suivent automatiquement l'adresse utilisée.

---

## 7. Sauvegardes

Une sauvegarde est une archive `.zip` contenant **toute la base** (recettes, planning, listes, comptes) et **les photos**.

| Comment | Détail |
|---|---|
| **Automatique** | Quand l'application est utilisée et que la dernière sauvegarde a plus de 7 jours. Les 10 dernières sauvegardes automatiques sont gardées. |
| **Écran** | Paramètres → Sauvegardes : « Sauvegarder maintenant », télécharger, supprimer. |
| **Commande** | `php artisan bouffe:backup` |
| **Restaurer** | `php artisan bouffe:restore` (choix dans la liste, confirmation). L'état actuel est d'abord sauvegardé (« Avant restauration »). |

Réglages facultatifs dans `.env` (puis `php artisan config:clear`) :

```ini
BOUFFE_BACKUP_PATH="C:\Users\Pierre\OneDrive\Bouffe"   # dossier des sauvegardes (défaut : storage\app\backups)
BOUFFE_BACKUP_AUTO_DAYS=7                               # 0 = pas de sauvegarde automatique
BOUFFE_BACKUP_KEEP=10                                   # sauvegardes automatiques conservées
```

> Un dossier **OneDrive** (ou une clé USB) protège aussi contre une panne du disque du PC.

L'extension PHP **zip** doit être active pour Apache **et** pour la ligne de commande (voir 1.1 et 1.2).

**Sauvegarde planifiée même sans ouvrir l'application** (facultatif) : Planificateur de tâches Windows → Créer une tâche de base → chaque jour → Démarrer un programme :
- Programme : `C:\wamp64\bin\php\php8.4.x\php.exe`
- Arguments : `artisan bouffe:backup --auto`
- Commencer dans : `C:\wamp64\www\Bouffe\src`

MariaDB doit être démarré (Wamp lancé) au moment de la tâche.

---

## 8. Commandes utiles au quotidien

| Commande | Rôle |
|---|---|
| `php artisan bouffe:user` | Créer un compte ou changer un mot de passe |
| `php artisan bouffe:backup` | Sauvegarder la base et les photos |
| `php artisan bouffe:restore` | Restaurer une sauvegarde (remplace toutes les données) |
| `php artisan migrate` | Appliquer les nouvelles tables après une mise à jour |
| `php artisan db:seed` | (Re)charger les données de départ — ne crée que ce qui manque, n'écrase rien |
| `php artisan optimize:clear` | Vider tous les caches (config, routes, vues) en cas de comportement bizarre |
| `php artisan test` | Lancer les tests |
| `npm run build` | Recompiler CSS/JS (uniquement si Node est installé et le style modifié) |
| `composer update` | Mettre à jour les dépendances PHP (à faire avec prudence) |

Journaux d'erreurs : `storage/logs/laravel-AAAA-MM-JJ.log`.

---

## 9. Hébergement chez OVH

Depuis le lot 25, la mise en ligne a son propre guide : **[10-mise-en-ligne-ovh.md](10-mise-en-ligne-ovh.md)**.

Il couvre :

- l'offre Pro, le dossier racine sur `src/public` et PHP 8.4 via `.ovhconfig` ;
- la base MySQL, le `.env` de production et la reprise des données de Wamp ;
- les tâches planifiées, la double authentification, la surveillance et les mises à jour.

L'installation Wamp décrite ici devient alors l'environnement de test.
