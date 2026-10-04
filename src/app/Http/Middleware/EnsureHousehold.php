<?php

namespace App\Http\Middleware;

use App\Models\Household;
use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foyer actif (lot 24) : une personne connectée travaille toujours dans un foyer dont elle est membre.
 *
 *  - aucun foyer : page d'explication (l'administrateur, lui, va à l'administration) ;
 *  - foyer désactivé : on passe à un autre foyer de la personne s'il y en a un ;
 *  - dernier passage noté (une fois par heure) pour l'administration (25.7).
 */
class EnsureHousehold
{
    /** Pages accessibles sans foyer actif. */
    private const WITHOUT_HOUSEHOLD = ['logout', 'admin.*', 'households.switch', 'invitation.*', 'no-household', 'account.*', 'privacy'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(...self::WITHOUT_HOUSEHOLD)) {
            return $next($request);
        }

        $id = CurrentHousehold::id();
        $household = $id ? Household::find($id) : null;

        if ($household && ! $household->isActive()) {
            $other = $user->households()->whereNull('disabled_at')->whereKeyNot($household->id)->first();

            if ($other) {
                $user->forceFill(['current_household_id' => $other->id])->save();
                CurrentHousehold::forget();

                return redirect()->route('dashboard')->with('status', "« {$household->name} » est désactivé : vous êtes dans « {$other->name} ».");
            }

            $household = null;
        }

        if (! $household) {
            return $user->isAdmin()
                ? redirect()->route('admin.households')
                : redirect()->route('no-household');
        }

        if ((int) $user->current_household_id !== $household->id) {
            $user->forceFill(['current_household_id' => $household->id])->saveQuietly();
        }

        DB::table('household_user')
            ->where('household_id', $household->id)->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('last_active_at')->orWhere('last_active_at', '<', now()->subHour()))
            ->update(['last_active_at' => now()]);

        View::share('currentHousehold', $household);

        return $next($request);
    }
}
