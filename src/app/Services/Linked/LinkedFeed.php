<?php

namespace App\Services\Linked;

use App\Models\Household;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\Scopes\HouseholdScope;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fil des proches (26.10) : quelques lignes discrètes sur l'accueil — recette partagée, avis reçu,
 * surplus proposé, invitation à un repas. Rien n'est enregistré : le fil se calcule à l'affichage,
 * sur les 14 derniers jours. Masquable dans Paramètres → Affichage.
 */
class LinkedFeed
{
    public const DAYS = 14;

    public function __construct(
        private readonly HouseholdLinks $links,
        private readonly SharedRecipes $recipes,
        private readonly Surplus $surplus,
        private readonly SharedMeals $meals,
    ) {}

    /**
     * @return Collection<int, array{icon: string, text: string, detail: string|null, url: string|null, at: Carbon}>
     */
    public function items(int $limit = 6): Collection
    {
        $me = (int) CurrentHousehold::id();

        if ($me === 0 || $this->links->linkedIds($me) === []) {
            return collect();
        }

        $since = now()->subDays(self::DAYS);
        $names = Household::query()->pluck('name', 'id');
        $items = collect();

        // Recettes nouvellement partagées avec nous.
        foreach ($this->recipes->visibleQuery($me)->where('recipes.shared_at', '>=', $since)->latest('shared_at')->limit(10)->get() as $recipe) {
            $items->push([
                'icon' => 'recipes',
                'text' => 'Recette partagée : '.$recipe->title,
                'detail' => $names->get($recipe->household_id),
                'url' => route('recipes.show', $recipe),
                'at' => $recipe->shared_at,
            ]);
        }

        // Avis laissés par les proches sur nos recettes (26.3).
        $ratings = RecipeRating::query()
            ->whereIn('recipe_id', Recipe::query()->select('id'))
            ->whereNotNull('household_id')->where('household_id', '!=', $me)
            ->where('updated_at', '>=', $since)
            ->with(['user', 'recipe' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)])
            ->latest('updated_at')->limit(10)->get();

        foreach ($ratings as $rating) {
            $items->push([
                'icon' => 'star',
                'text' => ($rating->user?->name ?? 'Un proche').' a noté « '.$rating->recipe?->title.' » '.str_repeat('★', $rating->rating),
                'detail' => $rating->comment ? '« '.\Illuminate\Support\Str::limit($rating->comment, 80).' »' : $names->get($rating->household_id),
                'url' => $rating->recipe ? route('recipes.show', $rating->recipe) : null,
                'at' => $rating->updated_at,
            ]);
        }

        // Surplus proposés par les foyers reliés (26.7).
        foreach ($this->surplus->fromLinked($me)->where('created_at', '>=', $since) as $offer) {
            $items->push([
                'icon' => 'share',
                'text' => 'À donner : '.$offer->label.($offer->quantity ? ' ('.$offer->quantity.')' : ''),
                'detail' => $offer->household?->name.' · jusqu\'au '.$offer->available_until->locale('fr')->isoFormat('dddd D MMMM'),
                'url' => route('linked.surplus'),
                'at' => $offer->created_at,
            ]);
        }

        // Invitations à un repas commun sans réponse (26.5).
        foreach ($this->meals->invitationsFor($me)->where('status', 'invited') as $row) {
            $items->push([
                'icon' => 'cake',
                'text' => 'Invitation : '.($row->occasion->title ?: 'repas').' du '.$row->occasion->date->locale('fr')->isoFormat('dddd D MMMM'),
                'detail' => $row->occasion->household?->name,
                'url' => route('linked.meal', $row->id),
                'at' => $row->created_at,
            ]);
        }

        // Lot 42 (42.1) : invitations à co-organiser un séjour.
        foreach (app(\App\Services\Stays\StayCoorganizers::class)->invitationsFor($me) as $row) {
            $items->push([
                'icon' => 'sun',
                'text' => 'Organiser ensemble : '.$row->stay->name.', '.$row->stay->period(),
                'detail' => $row->stay->household?->name,
                'url' => route('stays.index'),
                'at' => $row->updated_at,
            ]);
        }

        return $items->sortByDesc(fn ($item) => $item['at'])->take($limit)->values();
    }
}
