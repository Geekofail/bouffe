<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages des responsables du foyer (lot 24, 25.3) : export, suppression du foyer. */
class EnsureHouseholdOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->managesHousehold()) {
            return redirect()->route('dashboard')->with('status', 'Réservé aux responsables du foyer.');
        }

        return $next($request);
    }
}
