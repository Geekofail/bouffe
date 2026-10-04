<?php

namespace App\Services\System;

use App\Models\Recipe;
use App\Models\User;
use App\Services\Backup\BackupManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnostic de l'installation (lot 16, point 20.7).
 *
 * « Est-ce que tout va bien ? » en une page : ce qui manque, ce qui est à surveiller,
 * et une phrase d'explication pour chaque point. Sert aussi au bilan de `bouffe:deploy`.
 *
 * Chaque vérification renvoie : clé, intitulé, état (ok · warn · error), valeur constatée,
 * et le geste à faire quand ça ne va pas.
 */
class Diagnostic
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const ERROR = 'error';

    /** Extensions PHP nécessaires, et ce qui cesse de fonctionner sans elles. */
    public const EXTENSIONS = [
        'pdo_mysql' => 'la base de données MariaDB ou MySQL',
        'gd' => 'les photos de recettes',
        'zip' => 'les sauvegardes et les exports',
        'mbstring' => 'les accents',
        'fileinfo' => 'l\'envoi de fichiers',
        'intl' => 'les dates en français',
    ];

    public function __construct(private readonly BackupManager $backups) {}

    /** @return list<array{key: string, label: string, status: string, value: string, help: string|null}> */
    public function checks(): array
    {
        return [
            ...$this->platform(),
            ...$this->database(),
            ...$this->storage(),
            ...$this->backupChecks(),
            ...$this->notificationChecks(),
            ...$this->security(),
        ];
    }

    /** @return array{ok: int, warn: int, error: int} */
    public function summary(?array $checks = null): array
    {
        $checks = collect($checks ?? $this->checks());

        return [
            'ok' => $checks->where('status', self::OK)->count(),
            'warn' => $checks->where('status', self::WARN)->count(),
            'error' => $checks->where('status', self::ERROR)->count(),
        ];
    }

    /** Ce qui s'affiche dans « À propos » (20.6). */
    public function about(): array
    {
        return [
            'version' => (string) config('bouffe.version'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'database' => $this->databaseLabel(),
            'environment' => app()->environment(),
            'url' => (string) config('app.url'),
            'recipes' => Recipe::count(),
            'users' => User::count(),
            'path' => base_path(),
        ];
    }

    /* ================================================================ Vérifications */

    private function platform(): array
    {
        $checks = [[
            'key' => 'php',
            'label' => 'Version de PHP',
            'status' => version_compare(PHP_VERSION, '8.4', '>=') ? self::OK : self::ERROR,
            'value' => PHP_VERSION,
            'help' => version_compare(PHP_VERSION, '8.4', '>=') ? null
                : 'Bouffe demande PHP 8.4 : choisissez-la dans WampServer (clic sur l\'icône → PHP → Version).',
        ]];

        $missing = collect(self::EXTENSIONS)->reject(fn ($usage, $extension) => extension_loaded($extension));

        $checks[] = [
            'key' => 'extensions',
            'label' => 'Extensions PHP',
            'status' => $missing->isEmpty() ? self::OK : self::ERROR,
            'value' => $missing->isEmpty() ? count(self::EXTENSIONS).' présentes' : 'Manque : '.$missing->keys()->join(', '),
            'help' => $missing->isEmpty() ? null
                : 'Sans '.$missing->keys()->join(' ni ').', '.$missing->values()->join(' et ').' ne fonctionne(nt) pas. Activez-les dans WampServer → PHP → Extensions.',
        ];

        $limit = $this->bytes((string) ini_get('memory_limit'));

        $checks[] = [
            'key' => 'memory',
            'label' => 'Mémoire allouée à PHP',
            'status' => $limit < 0 || $limit >= 268435456 ? self::OK : self::WARN,
            'value' => $limit < 0 ? 'Sans limite' : (string) ini_get('memory_limit'),
            'help' => $limit < 0 || $limit >= 268435456 ? null
                : 'Les grandes photos peuvent manquer de mémoire. Passez « memory_limit » à 256M dans php.ini.',
        ];

        return $checks;
    }

    private function database(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            $pending = collect(File::files(database_path('migrations')))
                ->map(fn (\SplFileInfo $file) => $file->getFilenameWithoutExtension())
                ->diff(DB::table('migrations')->pluck('migration'))
                ->count();

            return [
                [
                    'key' => 'database',
                    'label' => 'Base de données',
                    'status' => self::OK,
                    'value' => $this->databaseLabel(),
                    'help' => null,
                ],
                [
                    'key' => 'migrations',
                    'label' => 'Structure à jour',
                    'status' => $pending === 0 ? self::OK : self::ERROR,
                    'value' => $pending === 0 ? 'À jour' : $pending.' migration(s) en attente',
                    'help' => $pending === 0 ? null : 'Lancez « php artisan migrate » dans le dossier src.',
                ],
            ];
        } catch (\Throwable $e) {
            return [[
                'key' => 'database',
                'label' => 'Base de données',
                'status' => self::ERROR,
                'value' => 'Injoignable',
                'help' => 'MariaDB ne répond pas : vérifiez que WampServer est démarré (icône verte).',
            ]];
        }
    }

    private function storage(): array
    {
        $checks = [];

        foreach ([
            'storage' => storage_path(),
            'cache' => base_path('bootstrap/cache'),
        ] as $key => $path) {
            $writable = File::isDirectory($path) && File::isWritable($path);

            $checks[] = [
                'key' => 'writable-'.$key,
                'label' => 'Écriture dans '.($key === 'storage' ? 'storage' : 'bootstrap/cache'),
                'status' => $writable ? self::OK : self::ERROR,
                'value' => $writable ? 'Autorisée' : 'Refusée',
                'help' => $writable ? null : "Bouffe ne peut pas écrire dans {$path} : vérifiez les droits du dossier.",
            ];
        }

        $photos = Storage::disk('local')->allFiles(BackupManager::PHOTO_DIRECTORY);
        $weight = collect($photos)->sum(fn (string $file) => Storage::disk('local')->size($file));

        $checks[] = [
            'key' => 'photos',
            'label' => 'Photos de recettes',
            'status' => self::OK,
            'value' => count($photos).' fichier(s) · '.$this->humanSize($weight),
            'help' => null,
        ];

        $free = @disk_free_space(base_path());

        if ($free !== false) {
            $checks[] = [
                'key' => 'disk',
                'label' => 'Place libre sur le disque',
                'status' => $free > 2147483648 ? self::OK : ($free > 536870912 ? self::WARN : self::ERROR),
                'value' => $this->humanSize((int) $free),
                'help' => $free > 2147483648 ? null : 'Les sauvegardes prennent de la place : faites du ménage sur le disque.',
            ];
        }

        return $checks;
    }

    private function backupChecks(): array
    {
        $latest = $this->backups->latest();
        $days = $latest ? $latest->createdAt->diffInDays(now()) : null;

        $checks = [[
            'key' => 'backup',
            'label' => 'Dernière sauvegarde',
            'status' => match (true) {
                $latest === null => self::ERROR,
                $days > 14 => self::WARN,
                default => self::OK,
            },
            'value' => $latest ? $latest->createdAt->locale('fr')->isoFormat('D MMMM YYYY à HH:mm') : 'Aucune',
            'help' => $latest && $days <= 14 ? null
                : 'Faites une sauvegarde depuis Paramètres → Sauvegardes, ou lancez « php artisan bouffe:backup ».',
        ]];

        $mirror = $this->backups->mirrorStatus();

        // En ligne (27.9) : l'e-mail hebdomadaire tient lieu de copie externe.
        if (! $mirror['configured'] && config('bouffe.backups.weekly_email')) {
            $checks[] = [
                'key' => 'backup-mirror',
                'label' => 'Copie hors de l\'hébergement',
                'status' => self::OK,
                'value' => 'Lien envoyé chaque semaine à l\'administrateur',
                'help' => null,
            ];

            return $checks;
        }

        $checks[] = [
            'key' => 'backup-mirror',
            'label' => 'Copie hors du PC',
            'status' => match (true) {
                ! $mirror['configured'] => self::WARN,
                ! $mirror['available'] => self::ERROR,
                default => self::OK,
            },
            'value' => match (true) {
                ! $mirror['configured'] => 'Pas configurée',
                ! $mirror['available'] => $mirror['error'] ?? 'Indisponible',
                default => $mirror['copies'].' copie(s) dans '.$mirror['path'],
            },
            'help' => match (true) {
                ! $mirror['configured'] => 'Une panne de disque emporte tout. Indiquez un dossier externe avec BOUFFE_BACKUP_MIRROR dans le fichier .env (clé USB, disque externe, OneDrive).',
                ! $mirror['available'] => 'Rebranchez le disque, ou corrigez BOUFFE_BACKUP_MIRROR dans le fichier .env.',
                default => null,
            },
        ];

        return $checks;
    }

    /** Rappels et notifications (lot 20) : fonctionnalités facultatives, donc au pire un avertissement. */
    private function notificationChecks(): array
    {
        // Lot 25 : dernier passage des tâches (adresse /taches, cron OVH, tâche Windows…), sinon des rappels.
        $lastRun = app(TaskRunner::class)->lastRun();
        $at = $lastRun['at'] ?? null;
        $online = app()->environment('production');

        $push = app(\App\Services\Notifications\WebPush::class);
        $pushError = null;

        try {
            $push->isSupported() ? $push->publicKey() : $pushError = 'extension openssl incomplète';
        } catch (\Throwable $e) {
            $pushError = $e->getMessage();
        }

        $devices = \App\Models\PushSubscription::count();
        $recap = \App\Support\Settings::bool('notifications.recap_enabled', false);
        $mailer = (string) config('mail.default');

        return [
            [
                'key' => 'reminders-task',
                'label' => 'Tâche planifiée (rappels)',
                'status' => $at && $at->gte(now()->subMinutes(30)) ? self::OK : self::WARN,
                'value' => $at ? 'Dernier passage '.$at->locale('fr')->diffForHumans().($lastRun['source'] ? ' ('.$lastRun['source'].')' : '') : 'Jamais lancée',
                'help' => $at && $at->gte(now()->subMinutes(30)) ? null
                    : ($online ? 'Sans elle, ni notification ni sauvegarde du jour : voir Paramètres → Mise en ligne (adresse à faire appeler toutes les 5 minutes).'
                        : 'Sans elle, ni notification ni récapitulatif. Pour la créer : « php artisan bouffe:reminders --tache-windows ».'),
            ],
            [
                'key' => 'push',
                'label' => 'Notifications sur le téléphone',
                'status' => $pushError ? self::WARN : self::OK,
                'value' => $pushError ? 'Indisponibles' : $devices.' appareil'.($devices > 1 ? 's' : '').' abonné'.($devices > 1 ? 's' : ''),
                'help' => $pushError ? 'Chiffrement impossible : '.$pushError.'.' : null,
            ],
            [
                'key' => 'mail',
                'label' => 'Envoi d\'e-mails',
                'status' => $recap && $mailer === 'log' ? self::WARN : self::OK,
                'value' => $mailer === 'log' ? 'Non configuré' : 'Par « '.$mailer.' »',
                'help' => $recap && $mailer === 'log' ? 'Le récapitulatif est activé mais MAIL_MAILER=log : renseignez le serveur d\'envoi (MAIL_*) dans le fichier .env.' : null,
            ],
        ];
    }

    private function security(): array
    {
        $debug = (bool) config('app.debug');
        $https = str_starts_with((string) config('app.url'), 'https://') || (bool) config('bouffe.security.force_https');
        $viewers = \Illuminate\Support\Facades\DB::table('household_user')->where('role', 'viewer')->distinct()->count('user_id');

        return [
            [
                'key' => 'app-key',
                'label' => 'Clé de l\'application',
                'status' => config('app.key') ? self::OK : self::ERROR,
                'value' => config('app.key') ? 'Définie' : 'Absente',
                'help' => config('app.key') ? null : 'Lancez « php artisan key:generate » : sans elle, les sessions ne tiennent pas.',
            ],
            [
                'key' => 'debug',
                'label' => 'Mode débogage',
                'status' => $debug ? self::WARN : self::OK,
                'value' => $debug ? 'Activé' : 'Désactivé',
                'help' => $debug ? 'En usage courant, mettez APP_DEBUG=false dans le fichier .env : les erreurs n\'afficheront plus le détail interne.' : null,
            ],
            [
                'key' => 'https',
                'label' => 'Accès chiffré (HTTPS)',
                'status' => $https ? self::OK : self::WARN,
                'value' => $https ? 'Oui' : 'Non (http:// sur le réseau de la maison)',
                'help' => $https ? null
                    : 'Sur le Wi-Fi de la maison c\'est acceptable. En ligne : APP_URL=https://… et BOUFFE_FORCE_HTTPS=true (docs/10-mise-en-ligne-ovh.md).',
            ],
            $this->twoFactorCheck(),
            [
                'key' => 'accounts',
                'label' => 'Comptes',
                'status' => self::OK,
                'value' => User::count().' compte(s) dont '.$viewers.' en consultation',
                'help' => null,
            ],
            [
                'key' => 'login-throttle',
                'label' => 'Blocage après essais ratés',
                'status' => self::OK,
                'value' => config('bouffe.security.login_attempts').' essais, puis '.config('bouffe.security.lockout_minutes').' minute(s) d\'attente',
                'help' => null,
            ],
        ];
    }

    /** Double authentification (lot 25, 27.4) : comptes qui la doivent et ne l'ont pas encore. */
    private function twoFactorCheck(): array
    {
        $required = (bool) config('bouffe.security.two_factor_required');
        $missing = User::query()->whereNull('two_factor_confirmed_at')
            ->where(fn ($q) => $q->where('is_admin', true)->orWhereIn('id', DB::table('household_user')->where('role', 'owner')->select('user_id')))
            ->count();
        $enabled = User::query()->whereNotNull('two_factor_confirmed_at')->count();

        return [
            'key' => 'two-factor',
            'label' => 'Double authentification',
            'status' => $required && $missing > 0 ? self::WARN : self::OK,
            'value' => ($required ? 'Obligatoire pour l\'administrateur et les responsables' : 'Facultative').' · '.$enabled.' compte(s) protégé(s)',
            'help' => $required && $missing > 0 ? $missing.' compte(s) doivent encore l\'activer (à leur prochaine connexion, dans « Mon compte »).' : null,
        ];
    }

    /* ================================================================ Utilitaires */

    private function databaseLabel(): string
    {
        $connection = DB::connection();
        try {
            $version = $connection->getDriverName() === 'sqlite'
                ? $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION)
                : (string) $connection->selectOne('select version() as v')->v;
        } catch (\Throwable) {
            $version = null;
        }

        // Lot 25 : OVH est en MySQL, Wamp en MariaDB — le pilote Laravel ne suffit pas à les distinguer.
        $driver = match (true) {
            $connection->getDriverName() === 'sqlite' => 'SQLite',
            str_contains(strtolower((string) $version), 'mariadb') => 'MariaDB',
            in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) => 'MySQL',
            default => $connection->getDriverName(),
        };

        $version = $version ? preg_replace('/-.*$/', '', (string) $version) : null;

        return trim($driver.' '.($version ?? '')).' · '.$connection->getDatabaseName();
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 1, ',', ' ').' Go',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', ' ').' Mo',
            $bytes >= 1024 => number_format($bytes / 1024, 0, ',', ' ').' Ko',
            default => $bytes.' o',
        };
    }
}
