# Bouffe — application Laravel

Code source de l'application **Bouffe** (planning des repas, recettes, liste de courses).

- Installation sous WampServer : [`../docs/INSTALL.md`](../docs/INSTALL.md)
- Analyse et choix techniques : [`../docs`](../docs)
- Avancement par lot : [`../docs/lots`](../docs/lots)

Stack : PHP 8.3+ · Laravel 13 · Livewire 4 · Alpine.js · Tailwind CSS 4 · MariaDB · Pest 4.

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan bouffe:user
php artisan test
```
