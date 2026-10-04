<?php

namespace App\Http\Controllers;

use App\Models\ShoppingList;
use App\Services\Shopping\AisleSplit;
use App\Services\Shopping\OfflineSync;
use App\Services\Stays\StayCoorganizers;
use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Mode magasin (15.4) et liste hors-ligne (15.1, 20.3).
 *
 * Cette page est volontairement **hors Livewire** : en magasin, il n'y a pas toujours de réseau,
 * et une page Livewire a besoin du serveur à chaque geste. Ici, la liste est embarquée dans la
 * page, les gestes sont gardés sur le téléphone, et tout remonte au retour du réseau (R20).
 *
 * Lot 42 : la liste d'un séjour co-organisé s'ouvre aussi pour les foyers qui le co-organisent
 * (42.1), et on peut se partager les rayons (42.3).
 */
class StoreModeController extends Controller
{
    public function show(int $shoppingList, OfflineSync $sync): View
    {
        $list = $this->list($shoppingList);
        $own = (int) $list->household_id === (int) CurrentHousehold::id();

        return view('shopping.store-mode', [
            'list' => $list,
            'snapshot' => $this->asOwner($list, fn () => $sync->snapshot($list)),
            'back' => $own ? route('shopping.show', $list) : route('stays.show', ['stay' => $list->stay_id, 'onglet' => 'courses']),
        ]);
    }

    /** Photo à jour de la liste, demandée par le téléphone quand il retrouve le réseau. */
    public function state(int $shoppingList, OfflineSync $sync): JsonResponse
    {
        $list = $this->list($shoppingList);

        return response()->json($this->asOwner($list, fn () => $sync->snapshot($list)));
    }

    /** Rejeu des gestes faits sans réseau, puis renvoi de la liste à jour. */
    public function sync(Request $request, int $shoppingList, OfflineSync $sync): JsonResponse
    {
        $list = $this->list($shoppingList);
        $data = $request->validate([
            'operations' => 'array|max:500',
            'operations.*.uuid' => 'required|string|max:64',
            'operations.*.action' => 'required|string|in:'.implode(',', OfflineSync::ACTIONS),
            'operations.*.item_id' => 'nullable|integer',
            'operations.*.label' => 'nullable|string|max:200',
            'operations.*.value' => 'nullable|string|max:200',
            'operations.*.at' => 'nullable|string|max:40',
        ]);

        return response()->json($this->asOwner($list, fn () => [
            'report' => $sync->apply($list, $data['operations'] ?? [], $request->user()),
            'list' => $sync->snapshot($list->fresh()),
        ]));
    }

    /** Lot 42 (42.3) : « je prends ce rayon », « Monique prend celui-là », « fin du partage ». */
    public function aisles(Request $request, int $shoppingList, OfflineSync $sync, AisleSplit $split): JsonResponse
    {
        $list = $this->list($shoppingList);
        $data = $request->validate([
            'aisle_id' => 'nullable|integer|min:-2',
            'user_id' => 'nullable|integer',
            'reset' => 'nullable|boolean',
        ]);

        try {
            $request->boolean('reset')
                ? $split->reset($list)
                : $split->assign($list, (int) ($data['aisle_id'] ?? 0), isset($data['user_id']) ? (int) $data['user_id'] : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->asOwner($list, fn () => $sync->snapshot($list)));
    }

    /** Une liste du foyer actif, ou celle d'un séjour qu'il co-organise. */
    private function list(int $id): ShoppingList
    {
        return app(StayCoorganizers::class)->findList($id) ?? abort(404);
    }

    private function asOwner(ShoppingList $list, Closure $callback): mixed
    {
        return (int) $list->household_id === (int) CurrentHousehold::id()
            ? $callback()
            : CurrentHousehold::run((int) $list->household_id, $callback);
    }
}
