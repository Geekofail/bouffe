# Git, tests automatiques et mise en ligne depuis Git (lot 36)

Ce document explique comment mettre Bouffe dans un **dépôt Git privé** sur GitHub (Q49), ce que
vérifient les **tests automatiques** à chaque envoi, et comment **mettre en ligne depuis Git** chez
OVH.

Le travail avec Claude ne change pas : il dépose les fichiers d'un lot dans ton dossier, comme
avant. Git garde en plus l'historique de chaque lot, et GitHub lance les tests tout seul.

---

## 1. Une seule fois : créer le dépôt

### 1.1 Sur GitHub

1. Crée un compte sur [github.com](https://github.com) (gratuit).
2. **New repository** : nom `bouffe`, **Private**, **sans** README, sans `.gitignore`, sans licence
   (ils sont déjà dans le dossier).

### 1.2 Sur le PC

Installe [Git pour Windows](https://git-scm.com/download/win) si ce n'est pas déjà fait (les
réglages proposés par l'installeur conviennent). Puis, dans PowerShell :

```powershell
cd C:\wamp64\www\Bouffe

# Les tests automatiques : Claude ne peut pas écrire dans le dossier .github (protégé),
# ils attendent donc dans outils\github-actions. Une seule fois :
New-Item -ItemType Directory -Force .github\workflows | Out-Null
Move-Item outils\github-actions\*.yml .github\workflows\ -Force
Remove-Item outils -Recurse

git init -b main
git add -A
git status
```

`git status` liste ce qui va partir dans le dépôt. **Vérifie qu'il n'y a** ni `src/.env`, ni
`src/vendor/`, ni `src/node_modules/`, ni `vendor.zip`, ni photos (`src/storage/app/…`) : les fichiers
`.gitignore` les écartent. Les fichiers construits (`src/public/build/`) sont gardés exprès : OVH n'a
pas de quoi les reconstruire.

```powershell
git commit -m "Bouffe : état au lot 36"
git remote add origin https://github.com/TON-COMPTE/bouffe.git
git push -u origin main
```

Au premier `push`, Windows ouvre une fenêtre de connexion à GitHub : accepte-la, elle est retenue
ensuite.

> `src/public/build/assets/` peut contenir d'anciens fichiers de lots précédents (ils ont changé de
> nom à chaque construction). Ils ne gênent pas ; pour les enlever avant le premier `commit` :
> `cd src; npm run build; cd ..` (le dossier est vidé puis reconstruit).

---

## 2. À chaque lot

Chaque lot devient une **branche**, fusionnée quand tu l'as validé.

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-33-assistant        # avant que Claude dépose les fichiers du lot
```

Claude dépose les fichiers, tu essaies le lot (`php artisan bouffe:deploy`), puis :

```powershell
git add -A
git commit -m "Lot 33 — assistant culinaire"
git push -u origin lot-33-assistant
```

Sur GitHub, un bandeau propose **Compare & pull request** : ouvre-la. Les tests s'y lancent (§3).
Quand ils sont au vert et que le lot te convient : **Merge pull request**, puis sur le PC :

```powershell
git switch main
git pull
```

Si tu oublies la branche, ce n'est pas grave : `git switch -c lot-33-assistant` fonctionne aussi
après coup, tant que rien n'est encore enregistré (`commit`).

### Revenir en arrière

| Besoin | Commande |
|---|---|
| Voir ce qu'un lot a changé | `git log --oneline` puis `git show --stat <numéro>` |
| Remettre **un fichier** comme sur `main` | `git restore --source=main -- src/app/Livewire/Planner/Week.php` |
| Remettre un fichier comme il était à un lot donné | `git log --oneline -- <fichier>` puis `git restore --source=<numéro> -- <fichier>` |
| Abandonner tout ce qui n'est pas encore enregistré | `git restore .` |
| Annuler un lot entier déjà fusionné | `git revert -m 1 <numéro de la fusion>` (un nouvel enregistrement qui défait le lot) |

La base de données n'est pas dans Git : pour elle, ce sont toujours les **sauvegardes** de Bouffe
(`php artisan bouffe:restore`).

---

## 3. Les tests automatiques (36.2)

Deux « workflows » GitHub Actions sont dans `.github/workflows/` (déplacés depuis
`outils/github-actions/` au §1.2 ; si un lot futur les modifie, il les déposera de nouveau dans
`outils/github-actions/` et il suffira de refaire le `Move-Item`). Les résultats sont dans l'onglet
**Actions** du dépôt ; GitHub envoie un e-mail en cas d'échec.

| Workflow | Quand | Ce qu'il fait | Durée |
|---|---|---|---|
| **Tests** | à chaque `push` et chaque *pull request* | style du code (Pint), tous les tests sous SQLite (PHP 8.3, la version minimum prise en charge), construction des fichiers, tests dans un vrai navigateur (iPhone sombre et ordinateur clair) | ~10 min |
| **Toutes les bases** | chaque nuit (3 h 17 en été), avant une mise en ligne, ou à la demande (**Run workflow**) | tous les tests sous **MariaDB 10.11** et **MySQL 8.0**, en PHP 8.4 comme chez OVH | ~10 min chacune |

Coût : rien. Un dépôt privé a 2 000 minutes gratuites par mois ; ces tests en utilisent environ
700 (document 08, §7.3). Aucun secret n'est nécessaire : les tests n'appellent jamais de service
extérieur, et un test vérifie qu'aucune clé ni mot de passe n'est dans les fichiers suivis.

En cas d'échec des tests dans le navigateur, le rapport (captures, traces) est joint au workflow :
**Actions → le workflow → Artifacts → rapport-navigateur**.

### Le style du code

`src/pint.json` règle [Pint](https://laravel.com/docs/pint) (le style Laravel, sans imposer les
`use` en tête de fichier). Sur le PC, avant d'enregistrer un changement fait à la main :

```powershell
cd C:\wamp64\www\Bouffe\src
composer lint           # corrige la mise en forme
composer lint:test      # vérifie seulement, comme GitHub
```

---

## 4. Mettre en ligne depuis Git (36.3)

### 4.1 Une seule fois, sur OVH

Il faut qu'OVH puisse **lire** le dépôt privé, sans pouvoir y écrire : une « clé de déploiement ».

```bash
ssh ton-login@ssh.clusterXXX.hosting.ovh.net
ssh-keygen -t ed25519 -C "ovh-bouffe" -f ~/.ssh/bouffe_deploy -N ""
cat ~/.ssh/bouffe_deploy.pub
```

Sur GitHub : dépôt **bouffe → Settings → Deploy keys → Add deploy key**, colle la ligne affichée,
**sans** cocher « Allow write access ». Puis, sur OVH :

```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/bouffe_deploy\n' >> ~/.ssh/config
cd ~/www
git clone git@github.com:TON-COMPTE/bouffe.git bouffe     # remplace l'envoi SFTP de l'étape 4 du doc 10
```

La suite de l'installation ne change pas (document 10, étapes 5 à 10 : bibliothèques, `.env`, base,
tâches planifiées). Si Bouffe est déjà en ligne par copie SFTP : renomme l'ancien dossier, clone, puis
recopie dans le nouveau `src/.env` et `src/storage/app/` de l'ancien.

### 4.2 À chaque mise en ligne

1. Sur le PC, le lot est fusionné dans `main` et essayé sur Wamp.
2. Marque la version et lance les tests des deux bases :

   ```powershell
   git tag mise-en-ligne-2026-10-20
   git push origin mise-en-ligne-2026-10-20
   ```

   Attends que **Toutes les bases** soit au vert dans l'onglet Actions.
3. Sur OVH :

   ```bash
   cd ~/www/bouffe/src
   php84 artisan bouffe:deploy --git
   ```

`bouffe:deploy --git` :

1. vérifie que Git est là et qu'**aucun fichier n'a été modifié sur le serveur** — sinon il s'arrête
   sans rien toucher et liste les fichiers ;
2. interroge GitHub et affiche les nouvelles versions ;
3. fait la sauvegarde, passe le site en maintenance ;
4. récupère la version de `main` **par avance rapide seulement** (jamais de fusion sur le serveur) ;
5. relance `composer install` si `composer.lock` a changé (avec `composer.phar` à côté de Bouffe) ;
6. met la base à jour, vide les caches, rouvre le site — même en cas d'erreur —, puis le diagnostic.

Autre branche : `--branche=nom`. La copie par SFTP reste possible : `php84 artisan bouffe:deploy`
sans `--git`, comme avant.

---

## 5. Ce qui a été réorganisé dans le code (36.4)

Rien ne change à l'écran : les pages ont été comparées avant et après, à l'identique.

| Avant | Après |
|---|---|
| `app/Livewire/Planner/Week.php` (815 lignes) et sa vue (736 lignes) | 240 lignes + `Planner/Concerns/` (détail d'un repas, propositions, semaine entière, données) ; vue découpée dans `views/livewire/planner/week/` (en-tête, bandeaux, grille, détail, copie) |
| `app/Livewire/Recipes/Show.php` (617 lignes) et sa vue (666 lignes) | 170 lignes + `Recipes/Concerns/` (notes et planification, entre foyers, données) ; vue dans `views/livewire/recipes/show/` |
| `app/Livewire/Stock/Index.php` (669 lignes) et sa vue (467 lignes) | 125 lignes + `Stock/Concerns/` (ajout, article, données) ; vue dans `views/livewire/stock/index/` |
| `app/Services/Shopping/ShoppingListManager.php` (746 lignes) | 265 lignes + `Shopping/Concerns/` (mise à jour depuis le planning, affichage) |
