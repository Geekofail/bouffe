<?php

namespace App\Support;

use App\Models\Household;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * Foyer actif (lot 24, R29).
 *
 *  - pour une personne connectée : son foyer courant (users.current_household_id), sinon le premier
 *    dont elle est membre ; aucun foyer = rien de visible ;
 *  - pour une tâche planifiée ou une commande : le foyer fixé par run() (boucle sur les foyers) ;
 *  - sans personne ni foyer fixé (installation, graines) : le premier foyer de l'installation.
 *
 * Mémorisé dans le conteneur (jamais partagé entre deux requêtes ou deux tests).
 */
final class CurrentHousehold
{
    private const EXPLICIT = 'bouffe.household.explicit';

    private const CACHE = 'bouffe.household.cache';

    public static function id(): ?int
    {
        if (app()->bound(self::EXPLICIT)) {
            return app(self::EXPLICIT);
        }

        $user = auth()->user();
        $key = $user ? 'u'.$user->getAuthIdentifier() : 'default';
        $cache = app()->bound(self::CACHE) ? app(self::CACHE) : [];

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $user instanceof User ? self::forUser($user) : self::defaultId();
            app()->instance(self::CACHE, $cache);
        }

        return $cache[$key];
    }

    public static function get(): ?Household
    {
        $id = self::id();

        return $id ? Household::find($id) : null;
    }

    /** Foyer d'une personne : le foyer courant s'il en est membre, sinon le premier. */
    public static function forUser(User $user): ?int
    {
        $ids = $user->households()->pluck('households.id')->all();

        if ($ids === []) {
            return null;
        }

        return in_array($user->current_household_id, $ids, true) ? (int) $user->current_household_id : (int) $ids[0];
    }

    /** Exécute $callback dans le foyer donné (tâches planifiées, administration, tests). */
    public static function run(Household|int $household, Closure $callback): mixed
    {
        $previous = app()->bound(self::EXPLICIT) ? app(self::EXPLICIT) : false;
        app()->instance(self::EXPLICIT, $household instanceof Household ? $household->id : $household);
        Settings::flush();

        try {
            return $callback();
        } finally {
            $previous === false ? app()->forgetInstance(self::EXPLICIT) : app()->instance(self::EXPLICIT, $previous);
            Settings::flush();
        }
    }

    /** Oublie le foyer mémorisé (changement de foyer, connexion). */
    public static function forget(): void
    {
        app()->forgetInstance(self::CACHE);
        app()->forgetInstance('bouffe.household.default');
        app()->forgetInstance('bouffe.household.members');
        Settings::flush();
    }

    public static function defaultId(): ?int
    {
        if (! app()->bound('bouffe.household.default')) {
            $id = Schema::hasTable('households') ? Household::query()->orderBy('id')->value('id') : null;
            app()->instance('bouffe.household.default', $id);
        }

        return app('bouffe.household.default');
    }

    /** Comptes membres du foyer actif. @return list<int> */
    public static function memberIds(): array
    {
        $id = self::id();
        $cache = app()->bound('bouffe.household.members') ? app('bouffe.household.members') : [];

        if (! array_key_exists((int) $id, $cache)) {
            $cache[(int) $id] = $id ? \Illuminate\Support\Facades\DB::table('household_user')->where('household_id', $id)->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];
            app()->instance('bouffe.household.members', $cache);
        }

        return $cache[(int) $id];
    }
}
