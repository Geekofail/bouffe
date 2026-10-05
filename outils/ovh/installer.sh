#!/usr/bin/env bash
# Bouffe : première installation chez OVH (hébergement mutualisé Pro), après le « git clone ».
# À lancer en SSH, depuis n'importe où :  bash ~/www/bouffe/outils/ovh/installer.sh
#
# Le script peut être relancé sans risque : chaque étape vérifie ce qui est déjà fait.
#  1er passage : PHP 8.4, Composer, bibliothèques, dossiers de storage, .env créé depuis .env.example
#                → il s'arrête pour que tu remplisses le .env (base, e-mail, APP_KEY du PC…).
#  2e passage  : vérifie le .env, crée les tables, lance le diagnostic.
# Il ne touche ni à ta base de données existante ni à tes fichiers (sauf .env, créé s'il manque).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="$ROOT/src"
ok()   { printf '\033[32m✔\033[0m %s\n' "$*"; }
info() { printf '  %s\n' "$*"; }
stop() { printf '\n\033[31m✖ %s\033[0m\n' "$*"; exit 1; }

[ -f "$SRC/artisan" ] || stop "Bouffe introuvable dans $SRC (le script doit rester dans outils/ovh du dépôt cloné)."
cd "$SRC"

# ---------------------------------------------------------------- PHP 8.4
PHP=""
for candidate in /usr/local/php8.4/bin/php /usr/local/php8.5/bin/php; do
    [ -x "$candidate" ] && { PHP="$candidate"; break; }
done
[ -n "$PHP" ] || stop "PHP 8.4 introuvable dans /usr/local (voir : ls /usr/local/ | grep php)."
ok "PHP : $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"
if ! grep -q "alias php84=" ~/.bashrc 2>/dev/null; then
    echo "alias php84='$PHP'" >> ~/.bashrc
    ok "Raccourci php84 ajouté à ~/.bashrc (actif à la prochaine connexion SSH)"
fi

# ---------------------------------------------------------------- .ovhconfig (PHP 8.4 pour le site)
OVHCONFIG="$HOME/.ovhconfig"
if [ -f "$OVHCONFIG" ]; then
    if grep -q "app.engine.version=8.4" "$OVHCONFIG"; then
        ok ".ovhconfig : PHP 8.4"
    else
        info "⚠ $OVHCONFIG existe déjà et ne demande pas PHP 8.4 : je n'y touche pas (il vaut pour tout"
        info "  l'hébergement). Contenu actuel :"; sed 's/^/    /' "$OVHCONFIG"
        info "  Voir le document 10, étape 2."
    fi
else
    printf 'app.engine=php\napp.engine.version=8.4\n\nhttp.firewall=none\nenvironment=production\n\ncontainer.image=stable64\n' > "$OVHCONFIG"
    ok ".ovhconfig créé (PHP 8.4, production)"
fi

# ---------------------------------------------------------------- storage
for dir in storage/app/private storage/app/backups storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$dir"
done
chmod -R u+rwX,go-w storage bootstrap/cache
ok "Dossiers storage prêts"

# ---------------------------------------------------------------- Composer et bibliothèques
if [ ! -f composer.phar ]; then
    curl -fsS https://getcomposer.org/installer -o composer-setup.php || stop "Téléchargement de Composer impossible (getcomposer.org)."
    "$PHP" composer-setup.php --quiet && rm -f composer-setup.php
    [ -f composer.phar ] || stop "Composer ne s'est pas installé."
    ok "Composer installé (composer.phar)"
fi
"$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction --no-progress
ok "Bibliothèques installées"

# ---------------------------------------------------------------- .env
if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    ok ".env créé depuis .env.example"
    printf '\n\033[33mÀ faire maintenant :\033[0m remplis %s/.env (nano .env), voir le document 10, étape 6 :\n' "$SRC"
    info "APP_ENV=production, APP_DEBUG=false, APP_URL=https://ton-sous-domaine"
    info "APP_KEY = la même que dans le .env du PC (sinon les secrets sauvegardés ne se déchiffrent plus)"
    info "DB_* = la base créée dans l'espace client OVH ; MAIL_* = la boîte d'envoi ; BOUFFE_FORCE_HTTPS=true"
    info "Puis relance : bash $ROOT/outils/ovh/installer.sh"
    exit 0
fi
chmod 600 .env

value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
missing=()
[ "$(value APP_ENV)" = "production" ] || missing+=("APP_ENV=production")
[ "$(value APP_DEBUG)" = "false" ] || missing+=("APP_DEBUG=false")
[[ "$(value APP_URL)" == https://* ]] || missing+=("APP_URL=https://…")
[[ "$(value APP_KEY)" == base64:* ]] || missing+=("APP_KEY (celle du PC)")
[ "$(value DB_CONNECTION)" = "mysql" ] || missing+=("DB_CONNECTION=mysql")
for key in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do
    [ -n "$(value $key)" ] || missing+=("$key")
done
if [ ${#missing[@]} -gt 0 ]; then
    printf '\n\033[33mLe .env n'"'"'est pas encore complet :\033[0m\n'
    for m in "${missing[@]}"; do info "- $m"; done
    stop "Complète $SRC/.env puis relance le script."
fi
ok ".env complet (production, MySQL, HTTPS)"

# ---------------------------------------------------------------- Base et diagnostic
"$PHP" artisan migrate --force --no-interaction
ok "Tables créées ou à jour"
"$PHP" artisan optimize:clear >/dev/null
[ -e public/storage ] || "$PHP" artisan storage:link --no-interaction >/dev/null
"$PHP" artisan bouffe:deploy || true

printf '\n\033[32mInstallation terminée.\033[0m Prochaine étape : reprendre tes données du PC (document 10, étape 7) :\n'
info "1. Sur le PC : php artisan bouffe:backup, puis envoie le .zip dans $SRC/storage/app/backups/"
info "2. Ici : php84 artisan bouffe:restore <fichier.zip> --force   puis   php84 artisan bouffe:deploy"
