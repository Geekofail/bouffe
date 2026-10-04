<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Services\Households\HouseholdManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Passer d'un foyer à l'autre (lot 24, 25.4). */
class HouseholdSwitchController extends Controller
{
    public function __invoke(Request $request, int $household, HouseholdManager $manager): RedirectResponse
    {
        try {
            $target = Household::findOrFail($household);
            $manager->switchTo($request->user(), $target);
        } catch (InvalidArgumentException $e) {
            return back()->with('status', $e->getMessage());
        }

        return redirect()->route('dashboard')->with('status', "Vous êtes dans « {$target->name} ».");
    }
}
