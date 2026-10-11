# Première mise en ligne chez OVH, depuis GitHub

La liste à cocher de la première mise en ligne. Elle reprend les documents
[10](10-mise-en-ligne-ovh.md) et [11](11-git-et-tests.md) dans l'ordre où on les fait, avec le dépôt
`Geekofail/bouffe` et le script `outils/ovh/installer.sh`.

Le code est sur GitHub depuis le 4 octobre 2026 (branche `main`). Compte une soirée.

Le site est servi depuis le dossier racine **`www`** de l'hébergement. Le code est rangé à côté,
dans `~/bouffe`, et `www` devient un raccourci vers `~/bouffe/src/public` : seul le dossier
`public` est visible depuis Internet, jamais `.env`, `vendor` ni les sauvegardes.

> **Avant la mise en ligne : le lot 43 sur le PC.** Déploie d'abord le lot 43 sur ton PC
> (`php artisan bouffe:deploy`). La table Ciqual, le catalogue d'ingrédients et les Nutri-Scores
> des recettes sont alors dans ta base, et donc dans la sauvegarde que tu reprendras à l'étape 4.

> **Ce que Claude ne peut pas faire à ta place** : se connecter à l'espace client OVH ou en SSH, et
> saisir un mot de passe (base, boîte e-mail, GitHub). Tout le reste est préparé.
>
> Le script a été essayé dans un environnement qui imite OVH : PHP 8.4, MySQL 8.0, dépôt cloné sans
> `vendor`. Il est allé jusqu'au bout, et la reprise d'une sauvegarde MariaDB du lot 42 dans MySQL
> 8.0 a réussi. Les écrans d'OVH, eux, n'ont pas été vus.

---

## 0. Sur le PC : tout envoyer sur GitHub

Le script `outils/ovh/installer.sh` doit être sur GitHub : le serveur le récupérera avec le
dépôt. Le serveur prend la branche `main` : le lot 43 doit donc y être fusionné (pull request
`lot-43-nutrition` → `main`, document 11, §2). Voir le document du lot 43, « Mise à jour depuis le
lot 42 ».

Sur GitHub, onglet **Actions** : le workflow **Tests** doit passer au vert. Lance aussi **Toutes
les bases** (bouton **Run workflow**) : ce sont les tests sous MySQL 8, la base d'OVH.

---

## 1. Dans l'espace client OVH

- [ ] **Multisite** : le domaine du site (par exemple `bouffe.ton-domaine.lu`) avec le **dossier
  racine `www`** (celui que tu vois avec `ls`).
  - Coche **SSL**.
  - Si le domaine n'est pas chez OVH, ajoute l'enregistrement DNS indiqué.
- [ ] **Bases de données → Créer une base de données**, de type **MySQL**. Note dans ton gestionnaire
  de mots de passe le serveur (`xxxxxx.mysql.db`), le nom de la base, l'utilisateur et le mot de passe.
- [ ] **E-mails** : une boîte d'envoi (par exemple `bouffe@ton-domaine.lu`) et son mot de passe.
- [ ] Note l'**utilisateur SSH** et le serveur (`ssh.clusterXXX.hosting.ovh.net`).

---

## 2. En SSH : la clé de déploiement, puis le dépôt

Depuis PowerShell :

```powershell
ssh ton-login@ssh.clusterXXX.hosting.ovh.net
```

Sur le serveur :

```bash
ssh-keygen -t ed25519 -C "ovh-bouffe" -f ~/.ssh/bouffe_deploy -N ""
cat ~/.ssh/bouffe_deploy.pub
```

Sur GitHub : dépôt **bouffe → Settings → Deploy keys → Add deploy key**. Colle la ligne affichée,
titre « OVH », **sans** cocher « Allow write access ». Puis, de retour sur le serveur :

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

## 3. En SSH : l'installation

```bash
bash ~/bouffe/outils/ovh/installer.sh
```

**Premier passage** : le script fait quatre choses, puis s'arrête.

1. Il trouve PHP 8.4 et crée le raccourci `php84`.
2. Il crée `~/.ovhconfig` s'il n'existe pas (PHP 8.4, production). S'il existe déjà, il n'y touche pas.
3. Il installe Composer et les bibliothèques, et prépare `storage`.
4. Il crée `src/.env` depuis `.env.example`.

Remplis maintenant `.env` :

```bash
nano ~/bouffe/src/.env
```

Les valeurs à remplir sont dans le document 10, étape 6 :

- `APP_ENV=production`, `APP_DEBUG=false`, et `APP_URL=https://` suivi de ton sous-domaine ;
- **`APP_KEY` : recopie celle du `.env` du PC**, sinon les secrets sauvegardés ne se déchiffrent
  plus ;
- `DB_*` : la base créée à l'étape 1 ;
- `MAIL_*` : la boîte d'envoi ;
- `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `BOUFFE_FORCE_HTTPS=true`.

Pour enregistrer dans nano : `Ctrl+O`, `Entrée`, puis `Ctrl+X` pour quitter.

**Second passage** : relance la même commande. Le script vérifie le `.env`, crée les tables, puis
lance `bouffe:deploy`. Sur cette base encore vide, il importe la table Ciqual et le catalogue
(quelques secondes) ; la reprise de l'étape 4 les remplacera par ceux de ta base.

### Le dossier `www` pointe vers Bouffe

```bash
mv ~/www ~/www-avant-bouffe
ln -s ~/bouffe/src/public ~/www
ls -l ~
```

La dernière commande doit montrer `www -> /homez.…/bouffe/src/public`. L'ancien contenu de `www`
reste dans `~/www-avant-bouffe` : rien n'est supprimé.

Ouvre `https://` suivi de ton domaine : la page de connexion de Bouffe s'affiche.

- **Erreur 403 ou 500** : certains hébergements refusent un raccourci comme dossier racine. Remets
  `www` comme avant (`rm ~/www && mv ~/www-avant-bouffe ~/www`), puis, dans l'espace client,
  **Multisite → Modifier le domaine → dossier racine `bouffe/src/public`**. Le reste ne change pas.

---

## 4. Reprendre les données du PC

Sur le **PC** :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:backup
scp storage\app\backups\bouffe-AAAA-MM-JJ_HHMMSS-manual.zip ton-login@ssh.clusterXXX.hosting.ovh.net:bouffe/src/storage/app/backups/
```

Sur le **serveur** :

```bash
cd ~/bouffe/src
php84 artisan bouffe:restore bouffe-AAAA-MM-JJ_HHMMSS-manual.zip --force
php84 artisan bouffe:deploy
```

Le diagnostic doit afficher « Base de données : MySQL 8… » et « Structure à jour ». La table Ciqual
et le catalogue viennent de la sauvegarde : `bouffe:deploy` ne les réimporte pas.

---

## 5. Les dernières étapes

- [ ] **Première connexion** sur `https://ton-sous-domaine` : active la double authentification et
  note les codes de secours (document 10, étape 8).
- [ ] **Tâche planifiée** : récupère l'adresse dans Bouffe, **Paramètres → Mise en ligne**. Fais-la
  appeler toutes les 5 minutes par cron-job.org (document 10, étape 9).
- [ ] **Surveillance** (document 10, étape 10).

---

## Ensuite, à chaque lot

Le travail ne change pas : une branche par lot, puis une *pull request* fusionnée dans `main`
(document 11, §2). Pour mettre en ligne :

```bash
cd ~/bouffe/src
php84 artisan bouffe:deploy --git
```

Quand un lot change la table Ciqual ou le catalogue, `bouffe:deploy` les applique tout seul.
