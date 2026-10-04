<?php

/*
|--------------------------------------------------------------------------
| Réglages propres à l'application Bouffe
|--------------------------------------------------------------------------
*/

return [

    // Mot de passe initial des comptes créés par `php artisan db:seed` (UserSeeder).
    // Vide : un mot de passe aléatoire est généré et affiché dans le terminal.
    'seed_password' => env('BOUFFE_SEED_PASSWORD'),

    // Nombre de personnes qui mangent habituellement un repas (portions proposées au planning,
    // calcul des restes : 4 portions planifiées pour 2 personnes = 2 portions de restes).
    'household_size' => (int) env('BOUFFE_HOUSEHOLD_SIZE', 2),

    // Part de portion comptée pour un enfant invité (0,5 = demi-portion). Convives = adultes + enfants × ce facteur.
    'child_portion' => (float) env('BOUFFE_CHILD_PORTION', 0.5),

    // Nombre de portions proposé par défaut pour une nouvelle recette.
    'default_servings' => (int) env('BOUFFE_DEFAULT_SERVINGS', 2),

    // Photos de recettes : taille maximale acceptée à l'envoi (Ko) et dimensions générées (px).
    'photos' => [
        'max_upload_kb' => 10240,
        'large_width' => 1600,
        'thumb_width' => 640,
        'quality' => 82,

        // Taille maximale d'une photo à traiter, en mégapixels (une image décompressée occupe
        // 4 octets par pixel : 12 Mpx = 48 Mo). 0 = calculé d'après le « memory_limit » de PHP.
        'max_megapixels' => (float) env('BOUFFE_PHOTO_MAX_MEGAPIXELS', 0),
    ],

    // Sauvegardes (php artisan bouffe:backup, Paramètres → Sauvegardes).
    'backups' => [
        // Dossier des sauvegardes. Un dossier synchronisé (OneDrive, clé USB…) garde une copie hors du PC :
        // BOUFFE_BACKUP_PATH="C:\Users\Pierre\OneDrive\Bouffe"
        'path' => env('BOUFFE_BACKUP_PATH') ?: storage_path('app/backups'),

        // Sauvegarde automatique à l'utilisation de l'application si la dernière date de plus de N jours (0 = jamais).
        'auto_days' => (int) env('BOUFFE_BACKUP_AUTO_DAYS', 7),

        // Sauvegarde créée avant chaque fusion d'ingrédients (lot 11).
        'before_merge' => (bool) env('BOUFFE_BACKUP_BEFORE_MERGE', true),

        // Nombre de sauvegardes automatiques conservées (les sauvegardes manuelles ne sont jamais supprimées).
        'keep' => (int) env('BOUFFE_BACKUP_KEEP', 10),

        // Tables dont seule la structure est sauvegardée (données temporaires).
        'structure_only' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'],

        // Copie hors du PC (20.4). Chaque sauvegarde est recopiée dans ce dossier : clé USB,
        // disque externe, dossier OneDrive / Google Drive, partage réseau…
        // BOUFFE_BACKUP_MIRROR="D:\\Sauvegardes\\Bouffe"
        // Un disque débranché n'est pas une erreur : la sauvegarde reste faite, la copie est signalée manquante.
        'mirror' => env('BOUFFE_BACKUP_MIRROR'),

        // Nombre de copies gardées dans le dossier externe (0 = toutes).
        'mirror_keep' => (int) env('BOUFFE_BACKUP_MIRROR_KEEP', 5),

        // En ligne (lot 25, 27.9) : e-mail hebdomadaire à l'administrateur avec le lien de la dernière
        // sauvegarde, pour en garder une copie hors de l'hébergement. Jour : 0 = dimanche … 6 = samedi.
        'weekly_email' => (bool) env('BOUFFE_BACKUP_WEEKLY_EMAIL', false),
        'weekly_email_day' => (int) env('BOUFFE_BACKUP_WEEKLY_EMAIL_DAY', 1),
    ],

    // Accès sécurisé (20.1) et sécurité (20.5).
    'security' => [
        // Derrière un tunnel ou un reverse proxy (Tailscale + Caddy, IIS, nginx…) qui termine le HTTPS :
        // BOUFFE_TRUSTED_PROXIES="*" pour faire confiance à l'en-tête X-Forwarded-Proto.
        'trusted_proxies' => env('BOUFFE_TRUSTED_PROXIES'),

        // Forcer les liens en https:// (à activer une fois le tunnel en place).
        'force_https' => (bool) env('BOUFFE_FORCE_HTTPS', false),

        // Blocage temporaire après N essais de connexion ratés, et durée du blocage (minutes).
        'login_attempts' => (int) env('BOUFFE_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('BOUFFE_LOCKOUT_MINUTES', 1),

        // Double authentification obligatoire pour l'administrateur et les responsables de foyer (lot 25, 27.4 ; Q41).
        // Par défaut : oui en ligne (APP_ENV=production), non sur le PC de la maison.
        'two_factor_required' => (bool) env('BOUFFE_2FA_REQUIRED', env('APP_ENV') === 'production'),

        // E-mail « nouvelle connexion depuis… » quand un compte s'ouvre sur un navigateur jamais vu (27.5).
        'new_device_email' => (bool) env('BOUFFE_NEW_DEVICE_EMAIL', true),

        // Durée de conservation du journal des connexions, en jours (27.5).
        'journal_days' => (int) env('BOUFFE_LOGIN_JOURNAL_DAYS', 180),

        // Politique de contenu (27.7) : enforce (appliquée) · report (signalée dans la console seulement) · off.
        // Par défaut appliquée en ligne, signalée seulement en local (la page d'erreur de Laravel en a besoin).
        'csp' => env('BOUFFE_CSP', env('APP_ENV') === 'production' ? 'enforce' : 'report'),

        // HSTS (27.7) : une fois une page vue en https, le navigateur refuse le http:// pendant cette durée.
        'hsts' => (bool) env('BOUFFE_HSTS', true),
        'hsts_seconds' => (int) env('BOUFFE_HSTS_SECONDS', 31536000),

        // E-mail à l'administrateur sur erreur grave, un par heure au plus (27.11). Par défaut en ligne seulement.
        'error_email' => (bool) env('BOUFFE_ERROR_EMAIL', env('APP_ENV') === 'production'),
    ],

    // Tâches planifiées (lot 25, 27.3) : adresse /taches/{jeton} appelée par un service externe
    // (cron-job.org…) et cron.php lancé par la tâche planifiée horaire d'OVH, en secours.
    'tasks' => [
        // Jeton de l'adresse. Vide : généré à la première ouverture de Paramètres → Mise en ligne.
        'token' => env('BOUFFE_TASKS_TOKEN'),
        // Au-delà de ce délai sans passage, /sante répond « en panne » (minutes).
        'max_delay' => (int) env('BOUFFE_TASKS_MAX_DELAY', 90),
    ],

    // Hébergement, pour la page « Vos données » (27.12) : où sont les données.
    'hosting' => env('BOUFFE_HOSTING', 'sur l\'ordinateur de l\'administrateur de cette installation'),

    // Stock : seuils des badges de date (jours avant la date effective). Réglables dans un écran au lot 8.
    'stock' => [
        'dlc_soon_days' => (int) env('BOUFFE_STOCK_DLC_SOON_DAYS', 3),
        'ddm_soon_days' => (int) env('BOUFFE_STOCK_DDM_SOON_DAYS', 7),
        // Durée pendant laquelle la dernière action sur un article peut être annulée (minutes).
        'undo_minutes' => 15,
        // Badge du nombre de produits à consommer dans le menu, bandeau du planning.
        'nav_badge' => true,
        'planning_banner' => true,
        // Quand un repas est coché « mangé » : ask (demander), auto (retirer sans demander), never.
        'deduction_mode' => env('BOUFFE_STOCK_DEDUCTION', 'ask'),
    ],

    // Planning (lot 14).
    'planning' => [
        // Règles de la semaine : temps maximum par créneau, quotas de catégories (Paramètres → Planning).
        'rules' => [],
        // Heure des rappels de préparation anticipée posés la veille (règle R21).
        'reminder_hour' => (int) env('BOUFFE_PLANNING_REMINDER_HOUR', 18),
        // Nombre de propositions parmi lesquelles le remplissage tire au sort (R15).
        'fill_pick_from' => 5,
        // Heure supposée des repas, faute d'heure sur les créneaux (calcul des rappels de repos).
        'meal_hour' => (int) env('BOUFFE_PLANNING_MEAL_HOUR', 19),
    ],

    // Rappels et notifications (lot 20 : 19.2, 19.3, 19.4).
    'notifications' => [
        // Heure du message quotidien « produits à consommer » (téléphone).
        'daily_hour' => (int) env('BOUFFE_NOTIFICATIONS_DAILY_HOUR', 18),
        // Pas de notification sur le téléphone entre ces deux heures : elles attendent le matin.
        'quiet_from' => (int) env('BOUFFE_NOTIFICATIONS_QUIET_FROM', 22),
        'quiet_until' => (int) env('BOUFFE_NOTIFICATIONS_QUIET_UNTIL', 7),
        // Récapitulatif par e-mail (19.3) : nécessite un serveur d'envoi (MAIL_* dans .env).
        'recap_enabled' => (bool) env('BOUFFE_RECAP_ENABLED', false),
        'recap_day' => (int) env('BOUFFE_RECAP_DAY', 0),       // 0 = dimanche … 6 = samedi
        'recap_hour' => (int) env('BOUFFE_RECAP_HOUR', 18),
        // Adresse de contact envoyée aux services de notification des navigateurs (norme VAPID).
        'vapid_subject' => env('BOUFFE_VAPID_SUBJECT'),
    ],

    // Tickets de caisse lus automatiquement (lot 23, module 24). Réglables aussi dans
    // Paramètres → Tickets de caisse (les clés saisies là-bas sont chiffrées en base).
    'receipts' => [
        // Service de lecture : mistral · azure · none (saisie manuelle seulement).
        'provider' => env('BOUFFE_RECEIPTS_PROVIDER', 'mistral'),
        // Mistral — Document AI : https://console.mistral.ai → API Keys.
        'mistral_key' => env('MISTRAL_API_KEY'),
        'mistral_model' => env('MISTRAL_OCR_MODEL', 'mistral-ocr-latest'),
        // Azure Document Intelligence (modèle « prebuilt-receipt ») : point de terminaison et clé de la ressource.
        'azure_endpoint' => env('AZURE_DI_ENDPOINT'),
        'azure_key' => env('AZURE_DI_KEY'),
        'azure_api_version' => env('AZURE_DI_API_VERSION', '2024-11-30'),
        // Lectures par mois au-delà desquelles on passe en saisie manuelle (24.7).
        'monthly_cap' => (int) env('BOUFFE_RECEIPTS_MONTHLY_CAP', 50),
        // Durée de conservation des photos de tickets, en mois (24.8). 0 = supprimées dès la validation.
        'keep_months' => (int) env('BOUFFE_RECEIPTS_KEEP_MONTHS', 12),
        // Coût indicatif par page, en dollars (tarifs publiés en septembre 2026) : affiché, jamais facturé par Bouffe.
        'cost_per_page' => [
            'mistral' => 0.005,          // Document AI (OCR + extraction) : 5 $ / 1 000 pages
            'mistral_text' => 0.004,     // OCR seul (recette depuis une photo) : 4 $ / 1 000 pages
            'azure' => 0.0,              // niveau gratuit : 500 pages par mois
        ],
        // Délai maximal d'une lecture, en secondes.
        'timeout' => (int) env('BOUFFE_RECEIPTS_TIMEOUT', 60),
    ],

    // Assistant culinaire (lot 33, module 33 du document 08, R34, Q46) : même clé Mistral que les tickets.
    // Réglable aussi dans Paramètres → Assistant (activation et plafond mensuel en euros).
    'assistant' => [
        'enabled' => (bool) env('BOUFFE_ASSISTANT_ENABLED', true),
        'model' => env('MISTRAL_CHAT_MODEL', 'mistral-small-latest'),
        // Adresse du service (compatible Mistral) : à ne changer que pour un relais ou des essais.
        'endpoint' => env('BOUFFE_ASSISTANT_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions'),
        // Plafond de dépense estimée par mois, en euros, pour toute l'installation (Q46 : 1 €).
        'monthly_cap' => (float) env('BOUFFE_ASSISTANT_MONTHLY_CAP', 1.0),
        // Tarif Mistral Small publié (septembre 2026), en dollars par million de jetons : affiché, jamais facturé par Bouffe.
        'price_in' => 0.15,
        'price_out' => 0.60,
        // Conversion indicative des dollars en euros (arrondie vers le haut, par prudence).
        'usd_to_eur' => 0.9,
        'timeout' => (int) env('BOUFFE_ASSISTANT_TIMEOUT', 45),
    ],

    // Version affichée dans Paramètres → À propos, et dans le manifeste des sauvegardes.
    'version' => '1.0',

];
