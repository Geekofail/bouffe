# Mise en ligne sur grignott.in (OVH), depuis GitHub

La liste à cocher pour mettre en ligne la dernière version, **avec les données du PC**, sur
`https://grignott.in`. Elle reprend les documents [10](10-mise-en-ligne-ovh.md) et
[11](11-git-et-tests.md) dans l'ordre où on les fait.

- Dépôt : `Geekofail/bouffe`, branche `main`.
- Script d'installation : `outils/ovh/installer.sh`.
- Le code est rangé dans `~/bouffe` sur le serveur. Le dossier racine **`www`** devient un
  raccourci vers `~/bouffe/src/public` : seul `public` est visible depuis Internet, jamais `.env`,
  `vendor` ni les sauvegardes.

> **Le nouveau nom.** L'application s'appelle **Grignott.in** partout où on la voit : écrans,
> e-mails, notifications, impressions, icône sur le téléphone. Les noms techniques ne changent pas,
> pour ne rien casser :
> - le dossier `C:\wamp64\www\Bouffe` ;
> - le dépôt `Geekofail/bouffe` ;
> - les commandes `php artisan bouffe:…` et les réglages `BOUFFE_…` du `.env` ;
> - les noms des sauvegardes (`bouffe-AAAA-MM-JJ_….zip`).

> **Ce que Claude ne peut pas faire à ta place** : se connecter à l'espace client OVH, à GitHub ou en
> SSH, et saisir un mot de passe. Tout le reste est préparé. Les écrans d'OVH n'ont pas été vus :
> leurs libellés peuvent différer un peu.

Compte une soirée. Les étapes 3 et 4 ne servent que la **première** fois : si `~/bouffe` existe
déjà sur le serveur, saute-les (l'étape 2 dit comment le voir).

---

## 0. Sur le PC : la dernière version, puis la sauvegarde

### 0.1 Mettre le PC à jour

Ferme ton éditeur, puis dans PowerShell :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Ouvre `C:\wamp64\www\Bouffe\src\.env` et change la première ligne :

```ini
APP_NAME=Grignott.in
```

Facultatif, pour que « Vos données » dise la vérité sur le PC aussi (sinon elle annonce OVH) :

```ini
BOUFFE_HOSTING="sur l'ordinateur de l'administrateur"
```

Puis `php artisan optimize:clear`. Ouvre l'application : l'en-tête affiche « Grignott.in ».

### 0.2 Tout envoyer sur GitHub

Regarde d'abord sur quelle branche tu es :

```powershell
cd C:\wamp64\www\Bouffe
git branch --show-current
git status
```

Si c'est `lot-43-nutrition` (le cas si tu as suivi le document du lot 43) :

```powershell
git add -A
git commit -m "Grignott.in : nouveau nom, page Vos données (OVH, Mistral), guide de mise en ligne"
git push
git switch main
git pull
git merge lot-43-nutrition
git push
```

Si c'est déjà `main` : seulement `git add -A`, le `git commit` ci-dessus, puis `git push`.

Sur GitHub, onglet **Actions** : le workflow **Tests** doit passer au vert sur `main`.

### 0.3 La sauvegarde des données

Juste avant la mise en ligne, pour emporter les données **à jour** :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:backup
dir storage\app\backups
```

Note le nom du fichier le plus récent, par exemple `bouffe-2026-10-11_201500-manual.zip`. Copie-le
aussi sur une clé USB ou dans OneDrive : c'est ta copie de secours.

**À partir de maintenant, ne modifie plus rien sur le PC** : ce qui serait saisi après ne partira
pas en ligne.

---

## 1. Dans l'espace client OVH

- [ ] **Hébergements → ton hébergement → Multisite** : `grignott.in` et `www.grignott.in` doivent y
  être, avec le **dossier racine `www`** et le **SSL** activé. Le domaine étant chez OVH, la zone
  DNS est réglée toute seule. S'il en manque un : **Ajouter un domaine**, dossier `www`, coche SSL.
- [ ] **Hébergements → ton hébergement → Informations générales** : version de PHP **8.4**.
- [ ] **Bases de données → Créer une base de données**, de type **MySQL**. Note dans ton
  gestionnaire de mots de passe le serveur (`xxxxxx.mysql.db`), le nom de la base, l'utilisateur et
  le mot de passe.
- [ ] **E-mails** (sur le domaine `grignott.in`) : crée une boîte d'envoi, par exemple
  `contact@grignott.in`, et note son mot de passe.
- [ ] **FTP-SSH** : note l'**utilisateur SSH** et le serveur (`ssh.clusterXXX.hosting.ovh.net`).

---

## 2. Se connecter en SSH et voir où on en est

Depuis PowerShell :

```powershell
ssh ton-login@ssh.clusterXXX.hosting.ovh.net
```

Sur le serveur :

```bash
ls -la ~
```

- Pas de dossier `bouffe` : c'est la première installation, fais les étapes **3, 4 et 5**.
- Un dossier `bouffe` existe déjà : mets-le à jour, puis passe à l'étape **5** :

  ```bash
  cd ~/bouffe
  git switch main
  git pull
  ```

---

## 3. Première fois : la clé de déploiement, puis le dépôt

```bash
ssh-keygen -t ed25519 -C "ovh-grignott" -f ~/.ssh/bouffe_deploy -N ""
cat ~/.ssh/bouffe_deploy.pub
```

Sur GitHub : dépôt **bouffe → Settings → Deploy keys → Add deploy key**. Colle la ligne affichée,
titre « OVH grignott.in », **sans** cocher « Allow write access ». De retour sur le serveur :

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/bouffe_deploy\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
ssh -T git@github.com
```

À la question « Are you sure you want to continue connecting », réponds `yes`. La réponse attendue
commence par « Hi Geekofail/bouffe! You've successfully authenticated ». Puis :

```bash
cd ~
git clone git@github.com:Geekofail/bouffe.git bouffe
```

---

## 4. Première fois : l'installation

```bash
bash ~/bouffe/outils/ovh/installer.sh
```

**Premier passage** : le script trouve PHP 8.4 (raccourci `php84`), crée `~/.ovhconfig` s'il
manque, installe Composer et les bibliothèques, prépare `storage`, crée `src/.env`, puis s'arrête.

Remplis `.env` :

```bash
nano ~/bouffe/src/.env
```

Les lignes à changer (le reste garde ses valeurs) :

```ini
APP_NAME=Grignott.in
APP_ENV=production
APP_DEBUG=false
APP_URL=https://grignott.in
# La même clé que dans le .env du PC : les clés d'API et les secrets de double
# authentification de la sauvegarde sont chiffrés avec elle.
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
MAIL_USERNAME=contact@grignott.in
MAIL_PASSWORD=mot_de_passe_de_la_boite
MAIL_FROM_ADDRESS=contact@grignott.in
MAIL_FROM_NAME=Grignott.in

BOUFFE_FORCE_HTTPS=true

# Sauvegardes en ligne : une par jour, 14 gardées, lien par e-mail chaque lundi
BOUFFE_BACKUP_AUTO_DAYS=1
BOUFFE_BACKUP_KEEP=14
BOUFFE_BACKUP_WEEKLY_EMAIL=true
BOUFFE_BACKUP_WEEKLY_EMAIL_DAY=1
```

- Pas besoin de `BOUFFE_HOSTING` : par défaut, « Vos données » annonce « chez OVHcloud, hébergeur
  français, sur un serveur situé en France ».
- Pour enregistrer dans nano : `Ctrl+O`, `Entrée`, puis `Ctrl+X`.

**Second passage** : relance la même commande. Le script vérifie le `.env`, crée les tables, puis
lance `bouffe:deploy`. Sur cette base vide, il importe la table Ciqual et le catalogue ; la reprise
de l'étape 6 les remplacera par ceux de ta base.

```bash
bash ~/bouffe/outils/ovh/installer.sh
```

---

## 5. Le dossier `www` pointe vers Grignott.in

À faire une seule fois. Si `ls -l ~` montre déjà `www -> …/bouffe/src/public`, passe à l'étape 6.

```bash
mv ~/www ~/www-avant-grignott
ln -s ~/bouffe/src/public ~/www
ls -l ~
```

La dernière commande doit montrer `www -> /homez.…/bouffe/src/public`. L'ancien contenu de `www`
reste dans `~/www-avant-grignott` : rien n'est supprimé.

Ouvre `https://grignott.in` : la page de connexion de Grignott.in s'affiche.

- **Erreur 403 ou 500** : certains hébergements refusent un raccourci comme dossier racine. Remets
  `www` comme avant (`rm ~/www && mv ~/www-avant-grignott ~/www`). Puis, dans l'espace client,
  **Multisite → Modifier le domaine** pour `grignott.in` et `www.grignott.in` : dossier racine
  `bouffe/src/public`. Le reste ne change pas.

---

## 6. Reprendre les données du PC

### 6.1 Si le site en ligne a déjà des données

Sauvegarde-les avant de les remplacer (sinon passe au 6.2) :

```bash
cd ~/bouffe/src
php84 artisan bouffe:backup
```

### 6.2 Envoyer et reprendre la sauvegarde du PC

Sur le **PC**, dans une **nouvelle** fenêtre PowerShell (avec le nom noté à l'étape 0.3) :

```powershell
cd C:\wamp64\www\Bouffe\src
scp storage\app\backups\bouffe-AAAA-MM-JJ_HHMMSS-manual.zip ton-login@ssh.clusterXXX.hosting.ovh.net:bouffe/src/storage/app/backups/
```

Sur le **serveur** :

```bash
cd ~/bouffe/src
php84 artisan bouffe:restore bouffe-AAAA-MM-JJ_HHMMSS-manual.zip --force
php84 artisan bouffe:deploy
```

Le diagnostic doit afficher « Base de données : MySQL 8… » et « Structure à jour ». La table Ciqual,
le catalogue et les Nutri-Scores viennent de la sauvegarde : `bouffe:deploy` ne les réimporte pas.

### 6.3 Vérifier

- `https://grignott.in` : connecte-toi avec ton compte du PC. Tes recettes, ton planning et ton
  stock sont là.
- **Double authentification** : obligatoire en ligne pour l'administrateur et les responsables de
  foyer. Active-la, et garde les codes de secours (document 10, étape 8).
- **Vos données** (`https://grignott.in/vos-donnees`) : « hébergées chez OVHcloud… en France » et
  la lecture des tickets « pas encore activée », prévue avec Mistral AI.
- `https://grignott.in/sante` répond `{"status":"ok",…}`.

---

## 7. La tâche planifiée OVH (cron)

Elle fait tourner, sans personne :

- les rappels et notifications ;
- la clôture des repas et les dépenses récurrentes ;
- la sauvegarde du jour ;
- le recalcul nutritionnel des recettes modifiées.

Dans l'espace client : **Hébergements → ton hébergement → Tâches planifiées - Cron → Ajouter une
planification**.

| Champ | Valeur |
|---|---|
| Commande à exécuter | `bouffe/src/cron.php` (chemin depuis la racine FTP) |
| Langage | PHP 8.4 |
| Activation | Activée |
| Fréquence | Toutes les heures (mode simple) |
| Rapport d'erreurs | Ton adresse e-mail |

Grignott.in affiche le chemin exact dans **Paramètres → Mise en ligne**.

**Vérifier** : une heure plus tard, **Paramètres → Mise en ligne** affiche « dernier passage : il y a
… minutes (tâche planifiée OVH) ». En attendant, tu peux lancer un passage à la main :

```bash
cd ~/bouffe/src
php84 artisan bouffe:tasks --source=cron
```

**Recommandé en plus** : OVH ne lance la tâche qu'**une fois par heure**. Pour des rappels à l'heure
juste, fais aussi appeler l'adresse des tâches toutes les 5 minutes :

1. Dans Grignott.in : **Paramètres → Mise en ligne → Afficher**, puis copie l'adresse
   `https://grignott.in/taches/…`.
2. Sur [cron-job.org](https://cron-job.org) (gratuit) : **Create cronjob**, colle l'adresse,
   **Every 5 minutes**, et active les alertes en cas d'échec.

Les deux font le même travail et ne se gênent pas : si le service externe tombe, la tâche OVH prend
le relais à l'heure suivante.

---

## 8. Plus tard : la lecture des tickets par Mistral

Quand tu voudras l'activer :

1. Crée une clé sur [console.mistral.ai](https://console.mistral.ai) (API Keys).
2. Vérifie dans la console Mistral le réglage qui permet d'utiliser tes données d'API pour améliorer
   les modèles, et désactive-le.
3. Dans Grignott.in : **Paramètres → Tickets de caisse**, colle la clé. Elle est chiffrée dans la
   base.

« Vos données » le dit alors toute seule : ticket envoyé à Mistral AI (serveurs dans l'Union
européenne), gardé 30 jours au plus. L'assistant culinaire utilise la même clé.

---

## Ensuite, à chaque lot

Une branche par lot, puis une *pull request* fusionnée dans `main` (document 11, §2). Pour mettre en
ligne :

```bash
cd ~/bouffe/src
php84 artisan bouffe:deploy --git
```

La commande sauvegarde la base, récupère `main`, applique les migrations, puis la table Ciqual et le
catalogue s'ils ont changé.

- **Téléphone et codes de secours perdus** : en SSH, `php84 artisan bouffe:user adresse@exemple.lu
  --sans-2fa`, puis réactive la double authentification.
- **Revenir en arrière** : `php84 artisan bouffe:restore` liste les sauvegardes. `bouffe:deploy` en
  fait une juste avant chaque mise à jour (`…-restore.zip`).
