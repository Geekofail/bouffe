<?php

namespace App\Services\Together;

use App\Models\Contribution;
use App\Models\ContributionLink;
use App\Models\MealOccasion;
use App\Models\PlannedMeal;
use App\Models\Scopes\HouseholdScope;
use App\Models\Stay;
use App\Models\StayMeal;
use App\Services\Shopping\ShoppingListManager;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * « Qui apporte quoi » (lot 42, 42.2) : la liste « à apporter » d'une réception ou d'un séjour.
 *
 *  - le foyer qui reçoit (ou qui co-organise le séjour) écrit ce qu'il manque : dessert, vin,
 *    pain, salade — éventuellement un plat qu'il avait prévu ;
 *  - chacun s'inscrit : depuis Bouffe (un foyer relié invité), ou par un lien sans compte ;
 *  - les doublons se voient (« en double ? ») ;
 *  - ce qui est apporté sort de la liste de courses : un plat prévu n'est plus compté ; dans un
 *    séjour, un ingrédient apporté (« pain ») non plus.
 *
 * Le contrôle d'accès est fait par l'appelant (page de la réception, du séjour, repas commun, lien).
 */
class Contributions
{
    public const LINK_DAYS_AFTER = 3;

    /* ================================================================ Lecture */

    /** @return Collection<int, Contribution> */
    public function of(MealOccasion|Stay $subject): Collection
    {
        return $this->query($subject)->with('byHousehold', 'plannedMeal.recipe', 'stayMeal.recipe')->orderBy('id')->get();
    }

    /**
     * Ce qui figure deux fois : même ingrédient, ou même nom (« Vin rouge » demandé, « vin rouge » apporté).
     *
     * @param  Collection<int, Contribution>  $contributions
     * @return list<int> identifiants des lignes « en double ? »
     */
    public function duplicates(Collection $contributions): array
    {
        return $contributions
            ->groupBy(fn (Contribution $c) => $c->ingredient_id ? 'i'.$c->ingredient_id : 'l'.$this->key($c->label))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->flatten()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** « Dîner du 12 octobre », « Chalet à Vianden » */
    public function title(MealOccasion|Stay $subject): string
    {
        return $subject instanceof Stay
            ? $subject->name
            : app(\App\Services\Receptions\Receptions::class)->name($subject);
    }

    /** « le samedi 12 octobre », « du 12 au 19 octobre 2026 » */
    public function when(MealOccasion|Stay $subject): string
    {
        return $subject instanceof Stay ? $subject->period() : 'le '.$subject->date->locale('fr')->isoFormat('dddd D MMMM YYYY');
    }

    public function lastDay(MealOccasion|Stay $subject): Carbon
    {
        return ($subject instanceof Stay ? $subject->ends_on : $subject->date)->copy()->endOfDay();
    }

    /**
     * Les plats prévus qu'on peut demander à quelqu'un d'apporter.
     *
     * @return Collection<int, array{key: string, label: string}>
     */
    public function dishes(MealOccasion|Stay $subject): Collection
    {
        if ($subject instanceof Stay) {
            return $subject->meals()->with('recipe', 'slot')->get()->map(fn (StayMeal $meal) => [
                'key' => 's'.$meal->id,
                'label' => $meal->label().' — '.$meal->date->locale('fr')->isoFormat('ddd D').' '.mb_strtolower((string) $meal->slot?->name),
            ])->values();
        }

        return PlannedMeal::query()->withoutGlobalScope(HouseholdScope::class)->where('household_id', $subject->household_id)
            ->whereDate('date', $subject->date->toDateString())->where('meal_slot_id', $subject->meal_slot_id)
            ->with(['recipe' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)])->orderBy('position')->get()
            ->filter(fn (PlannedMeal $meal) => $meal->recipe || $meal->free_text)
            ->map(fn (PlannedMeal $meal) => ['key' => 'p'.$meal->id, 'label' => $meal->recipe?->title ?? (string) $meal->free_text])
            ->values();
    }

    /* ================================================================ Écriture */

    /**
     * Une ligne : « Dessert » (personne encore), ou « Tarte aux pommes — Monique ».
     *
     * @param  array{by_label?: string|null, by_household_id?: int|null, by_user_id?: int|null, token_hash?: string|null, dish?: string|null}  $data
     */
    public function add(MealOccasion|Stay $subject, string $label, array $data = []): Contribution
    {
        $label = Str::limit(Str::squish($label), 100, '');
        $dish = $this->dish($subject, $data['dish'] ?? null);
        $label = $label !== '' ? $label : ($dish['label'] ?? '');

        if ($label === '') {
            throw new InvalidArgumentException('Indiquez ce qu\'il faut apporter.');
        }

        if ($this->query($subject)->count() >= Contribution::MAX_PER_EVENT) {
            throw new InvalidArgumentException('La liste compte déjà '.Contribution::MAX_PER_EVENT.' lignes.');
        }

        $by = $this->name($data['by_label'] ?? null);

        return Contribution::create([
            'household_id' => $subject->household_id,
            $this->column($subject) => $subject->id,
            'label' => $label,
            'ingredient_id' => $dish ? null : app(ShoppingListManager::class)->matchIngredient($label)?->id,
            'planned_meal_id' => $dish['planned_meal_id'] ?? null,
            'stay_meal_id' => $dish['stay_meal_id'] ?? null,
            'by_label' => $by,
            'by_household_id' => $by ? ($data['by_household_id'] ?? null) : null,
            'by_user_id' => $by ? ($data['by_user_id'] ?? auth()->id()) : null,
            'token_hash' => $data['token_hash'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * « Je l'apporte » : seulement une ligne que personne n'a prise.
     *
     * @param  array{by_household_id?: int|null, by_user_id?: int|null, token_hash?: string|null}  $data
     */
    public function claim(Contribution $contribution, string $by, array $data = []): Contribution
    {
        $by = $this->name($by) ?? throw new InvalidArgumentException('Indiquez votre prénom.');

        $updated = Contribution::query()->withoutGlobalScope(HouseholdScope::class)->whereKey($contribution->id)->whereNull('by_label')->update([
            'by_label' => $by,
            'by_household_id' => $data['by_household_id'] ?? null,
            'by_user_id' => $data['by_user_id'] ?? auth()->id(),
            'token_hash' => $data['token_hash'] ?? null,
            'updated_at' => now(),
        ]);

        if (! $updated) {
            throw new InvalidArgumentException('Quelqu\'un vient de le prendre.');
        }

        return $contribution->refresh();
    }

    /** Plus personne ne l'apporte : la ligne redevient « à prendre » (ou disparaît si elle avait été ajoutée ainsi). */
    public function release(Contribution $contribution): void
    {
        $contribution->forceFill(['by_label' => null, 'by_household_id' => null, 'by_user_id' => null, 'token_hash' => null])->save();
    }

    public function remove(Contribution $contribution): void
    {
        $contribution->delete();
    }

    /** Une ligne de cet évènement. */
    public function find(MealOccasion|Stay $subject, int $id): Contribution
    {
        return $this->query($subject)->whereKey($id)->first() ?? throw new InvalidArgumentException('Cette ligne n\'existe plus.');
    }

    /* ================================================================ Liste de courses */

    /** Plats du planning de la maison apportés par quelqu'un : ils ne comptent plus dans les courses. */
    public static function broughtPlannedMeals(): \Illuminate\Database\Query\Builder
    {
        return DB::table('contributions')->whereNotNull('planned_meal_id')->whereNotNull('by_label')->select('planned_meal_id');
    }

    /** @return list<int> plats du séjour apportés par quelqu'un */
    public function broughtStayMeals(Stay $stay): array
    {
        return DB::table('contributions')->where('stay_id', $stay->id)->whereNotNull('stay_meal_id')->whereNotNull('by_label')
            ->pluck('stay_meal_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, list<string>> ingrédients apportés à un séjour, et par qui */
    public function broughtIngredients(Stay $stay): array
    {
        return DB::table('contributions')->where('stay_id', $stay->id)->whereNotNull('ingredient_id')->whereNotNull('by_label')
            ->whereNull('stay_meal_id')->get(['ingredient_id', 'by_label'])
            ->groupBy('ingredient_id')->map(fn ($rows) => $rows->pluck('by_label')->unique()->values()->all())->all();
    }

    /** Après un changement : la liste du séjour suit tout de suite (celle de la maison, à sa prochaine mise à jour). */
    public function afterChange(MealOccasion|Stay $subject): void
    {
        if ($subject instanceof Stay && ($list = $subject->shoppingList()->first())) {
            \App\Support\CurrentHousehold::run((int) $subject->household_id, fn () => app(\App\Services\Stays\StayShopping::class)->sync($list));
        }
    }

    /* ================================================================ Lien sans compte */

    public function link(MealOccasion|Stay $subject): ?ContributionLink
    {
        return ContributionLink::query()->withoutGlobalScope(HouseholdScope::class)
            ->where($this->column($subject), $subject->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest('id')->first();
    }

    /** Un nouveau lien (l'ancien ne marche plus). @return array{link: ContributionLink, url: string} */
    public function createLink(MealOccasion|Stay $subject): array
    {
        $expires = $this->lastDay($subject)->addDays(self::LINK_DAYS_AFTER);

        if ($expires->isPast()) {
            throw new InvalidArgumentException('Cet évènement est passé.');
        }

        $this->revokeLink($subject);
        $token = Str::random(40);
        $link = ContributionLink::create([
            'household_id' => $subject->household_id,
            $this->column($subject) => $subject->id,
            'token_hash' => self::hash($token),
            'token' => $token,
            'expires_at' => $expires,
            'created_by' => auth()->id(),
        ]);

        return ['link' => $link, 'url' => $this->url($link)];
    }

    public function revokeLink(MealOccasion|Stay $subject): void
    {
        ContributionLink::query()->withoutGlobalScope(HouseholdScope::class)
            ->where($this->column($subject), $subject->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function url(ContributionLink $link): string
    {
        return route('bring.show', ['token' => $link->token]);
    }

    /** Le lien d'un jeton, et son évènement, s'il est encore valable. @return array{link: ContributionLink, subject: MealOccasion|Stay}|null */
    public function findLink(string $token): ?array
    {
        $link = ContributionLink::query()->withoutGlobalScope(HouseholdScope::class)->where('token_hash', self::hash($token))->first();

        if (! $link || ! $link->isActive()) {
            return null;
        }

        $subject = $link->stay_id
            ? Stay::query()->withoutGlobalScope(HouseholdScope::class)->find($link->stay_id)
            : MealOccasion::query()->withoutGlobalScope(HouseholdScope::class)->with(['slot' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)])->find($link->meal_occasion_id);

        return $subject ? ['link' => $link, 'subject' => $subject] : null;
    }

    public function recordView(ContributionLink $link): void
    {
        if ($link->last_viewed_at === null || $link->last_viewed_at->lt(now()->subMinute())) {
            $link->forceFill(['views' => $link->views + 1, 'last_viewed_at' => now()])->saveQuietly();
        }
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /* ================================================================ Outils */

    private function query(MealOccasion|Stay $subject)
    {
        return Contribution::query()->withoutGlobalScope(HouseholdScope::class)->where($this->column($subject), $subject->id);
    }

    private function column(MealOccasion|Stay $subject): string
    {
        return $subject instanceof Stay ? 'stay_id' : 'meal_occasion_id';
    }

    /** @return array{label: string, planned_meal_id?: int, stay_meal_id?: int}|null */
    private function dish(MealOccasion|Stay $subject, ?string $key): ?array
    {
        if (! $key) {
            return null;
        }

        $found = $this->dishes($subject)->firstWhere('key', $key) ?? throw new InvalidArgumentException('Ce plat n\'est plus prévu.');
        $id = (int) substr($key, 1);

        return ['label' => Str::before($found['label'], ' — ')] + ($subject instanceof Stay ? ['stay_meal_id' => $id] : ['planned_meal_id' => $id]);
    }

    private function name(?string $name): ?string
    {
        $name = Str::limit(Str::squish((string) $name), 60, '');

        return $name === '' ? null : $name;
    }

    private function key(string $label): string
    {
        return NameNormalizer::normalize($label);
    }
}
