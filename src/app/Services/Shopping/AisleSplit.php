<?php

namespace App\Services\Shopping;

use App\Models\ShoppingList;
use App\Models\ShoppingListAisleOwner;
use App\Models\StayHousehold;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Courses à deux en magasin (lot 42, 42.3) : « On se partage ? ».
 *
 * Chacun prend des rayons ; le mode magasin montre d'abord les siens, puis les rayons libres, puis
 * ceux des autres (repliés), et relit la liste toutes les quelques secondes pour voir ce que l'autre
 * a coché. Les rayons sont ceux du mode magasin (0 : autres, -1 : placard, -2 : ajoutés en magasin).
 */
class AisleSplit
{
    /**
     * Ceux qui peuvent faire ces courses : les comptes du foyer de la liste, et pour la liste d'un
     * séjour, ceux des foyers qui le co-organisent.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function people(ShoppingList $list): Collection
    {
        $households = [(int) $list->household_id];

        if ($list->stay_id) {
            array_push($households, ...StayHousehold::query()->where('stay_id', $list->stay_id)->where('status', 'accepted')->pluck('household_id')->map(fn ($id) => (int) $id)->all());
        }

        $users = User::query()->whereIn('id', DB::table('household_user')->whereIn('household_id', $households)->select('user_id'))->orderBy('name')->get(['id', 'name']);
        $first = $users->mapWithKeys(fn (User $u) => [$u->id => Str::before(trim((string) $u->name), ' ') ?: (string) $u->name]);
        $twice = $first->countBy()->filter(fn (int $n) => $n > 1)->keys()->all();

        return $users->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => in_array($first[$u->id], $twice, true) ? (string) $u->name : $first[$u->id],
        ])->values();
    }

    /** @return array<int, int> rayon → compte */
    public function owners(ShoppingList $list): array
    {
        return ShoppingListAisleOwner::query()->where('shopping_list_id', $list->id)->pluck('user_id', 'aisle_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** Donne un rayon à quelqu'un (ou le libère). */
    public function assign(ShoppingList $list, int $aisleId, ?int $userId): void
    {
        if ($aisleId < -2) {
            throw new InvalidArgumentException('Rayon inconnu.');
        }

        if ($userId === null) {
            ShoppingListAisleOwner::query()->where('shopping_list_id', $list->id)->where('aisle_id', $aisleId)->delete();

            return;
        }

        if (! $this->people($list)->contains('id', $userId)) {
            throw new InvalidArgumentException('Cette personne ne fait pas ces courses.');
        }

        ShoppingListAisleOwner::query()->updateOrCreate(['shopping_list_id' => $list->id, 'aisle_id' => $aisleId], ['user_id' => $userId]);
    }

    /** Fin du partage : tous les rayons redeviennent communs. */
    public function reset(ShoppingList $list): void
    {
        ShoppingListAisleOwner::query()->where('shopping_list_id', $list->id)->delete();
    }

    /** Ce que le mode magasin embarque. @return array{me: int|null, people: list<array{id: int, name: string}>, owners: array<int, int>} */
    public function payload(ShoppingList $list, ?User $user): array
    {
        return ['me' => $user?->id, 'people' => $this->people($list)->all(), 'owners' => (object) $this->owners($list)];
    }
}
