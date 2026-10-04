<?php

namespace App\Services\Stays;

use App\Models\Guest;
use App\Models\Household;
use App\Models\HouseholdPerson;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Stay;
use App\Models\StayMeal;
use App\Models\StayParticipant;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\LinkedEater;
use App\Services\Linked\LinkedEaters;
use App\Services\Planning\Appetites;
use App\Services\Planning\GuestCompatibility;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Séjours (lot 34, 34.1) : le séjour, ses participants, leurs appétits et contraintes, et son
 * planning — à part de celui de la maison.
 */
class StayService
{
    public const MAX_PARTICIPANTS = 40;

    public function __construct(
        private readonly Appetites $appetites,
        private readonly GuestCompatibility $compatibility,
    ) {}

    /* ================================================================ Le séjour */

    /** @param array{name?: string, place?: string|null, starts_on?: string, ends_on?: string, split_mode?: string, notes?: string|null} $data */
    public function create(array $data): Stay
    {
        return Stay::create($this->clean($data) + ['created_by' => auth()->id()]);
    }

    /**
     * Change le séjour. Raccourci, ses repas hors des nouvelles dates sont retirés.
     *
     * @return int repas retirés
     */
    public function update(Stay $stay, array $data): int
    {
        $stay->update($this->clean($data));

        return $stay->meals()->where(fn ($q) => $q->whereDate('date', '<', $stay->starts_on->toDateString())->orWhereDate('date', '>', $stay->ends_on->toDateString()))->delete();
    }

    private function clean(array $data): array
    {
        $name = Str::limit(Str::squish((string) ($data['name'] ?? '')), 100, '');

        if ($name === '') {
            throw new InvalidArgumentException('Donnez un nom au séjour.');
        }

        try {
            $from = Carbon::parse((string) ($data['starts_on'] ?? ''))->startOfDay();
            $to = Carbon::parse((string) ($data['ends_on'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Indiquez les dates du séjour.');
        }

        if ($to->lt($from)) {
            throw new InvalidArgumentException('La fin du séjour précède son début.');
        }

        if ($from->diffInDays($to) + 1 > Stay::MAX_DAYS) {
            throw new InvalidArgumentException('Un séjour dure '.Stay::MAX_DAYS.' jours au plus.');
        }

        return [
            'name' => $name,
            'place' => Str::limit(Str::squish((string) ($data['place'] ?? '')), 150, '') ?: null,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'split_mode' => array_key_exists((string) ($data['split_mode'] ?? ''), Stay::SPLIT_MODES) ? $data['split_mode'] : 'appetite',
            'notes' => Str::limit(trim((string) ($data['notes'] ?? '')), 2000, '') ?: null,
            // Lot 40 (40.3) : l'équipement du lieu ; vide = comme à la maison.
            'equipment' => isset($data['equipment']) && is_array($data['equipment'])
                ? array_values(array_intersect($data['equipment'], array_keys(\App\Services\Recipes\KitchenEquipment::ITEMS)))
                : null,
        ];
    }

    /* ================================================================ Participants */

    /**
     * Les personnes à table d'habitude du foyer actif (32.1), groupées sous le nom du foyer.
     *
     * Lot 42 (42.1) : pour un foyer qui co-organise, ce sont **ses** personnes — notées comme celles
     * d'un foyer relié (`linked_household_id`, `linked_user_id`), qu'il gère ensuite lui-même.
     */
    public function addHousehold(Stay $stay): int
    {
        $current = (int) CurrentHousehold::id();
        $ours = $current === (int) $stay->household_id;
        $userColumn = $ours ? 'user_id' : 'linked_user_id';
        $group = (string) (CurrentHousehold::get()?->name ?: 'Notre foyer');
        $mine = $stay->participants()->when(! $ours, fn ($q) => $q->where('linked_household_id', $current));
        $known = (clone $mine)->whereNotNull($userColumn)->pluck($userColumn)->all();
        $knownPeople = (clone $mine)->whereNotNull('person_id')->pluck('person_id')->all();
        $names = (clone $mine)->whereNull($userColumn)->whereNull('person_id')->where('group_label', $group)->pluck('name')->all();
        $added = 0;

        foreach ($this->appetites->table() as $row) {
            if (($row['user_id'] !== null && in_array($row['user_id'], $known, true))
                || ($row['id'] !== null && in_array($row['id'], $knownPeople, true))
                || ($row['user_id'] === null && in_array($row['name'], $names, true))) {
                continue;
            }

            // Lot 39 : la personne suit le participant, et ses allergies avec elle (un enfant aussi).
            $this->add($stay, ['name' => $row['name'], 'appetite' => $row['appetite'], 'group_label' => $group, $userColumn => $row['user_id'], 'person_id' => $row['id']]
                + ($ours ? [] : ['linked_household_id' => $current]));
            $added++;
        }

        return $added;
    }

    /** Un invité du carnet, avec son appétit et ses contraintes ; groupé avec son groupe du carnet. */
    public function addGuest(Stay $stay, Guest $guest): StayParticipant
    {
        if ($stay->participants()->where('guest_id', $guest->id)->exists()) {
            throw new InvalidArgumentException("{$guest->name} participe déjà.");
        }

        return $this->add($stay, [
            'name' => $guest->name,
            'appetite' => $guest->appetite ?: ($guest->is_child ? 'petit' : 'normal'),
            'group_label' => $guest->group_name ?: $guest->name,
            'guest_id' => $guest->id,
        ] + $this->coorganizerOwner($stay));
    }

    /** Les comptes d'un foyer relié ; leurs contraintes viennent de leur profil, si elles sont partagées (26.6). */
    public function addLinkedHousehold(Stay $stay, Household $household): int
    {
        if (! app(HouseholdLinks::class)->areLinked((int) CurrentHousehold::id(), $household->id)) {
            throw new InvalidArgumentException('Ce foyer n\'est pas relié au vôtre.');
        }

        $known = $stay->participants()->whereNotNull('linked_user_id')->pluck('linked_user_id')->all();
        $added = 0;

        foreach (app(LinkedEaters::class)->membersOf($household) as $member) {
            // Les comptes seulement : une personne sans compte d'un foyer relié s'ajoute par son prénom.
            if ($member->userId === null || in_array($member->userId, $known, true)) {
                continue;
            }

            $this->add($stay, [
                'name' => Str::before($member->name, ' ('),
                'appetite' => 'normal',
                'group_label' => Str::limit((string) $household->name, 60, ''),
                'linked_household_id' => $household->id,
                'linked_user_id' => $member->userId,
            ]);
            $added++;
        }

        return $added;
    }

    /** Une personne sans compte ni fiche (un enfant d'amis, un voisin). */
    public function addPerson(Stay $stay, string $name, string $appetite, string $group): StayParticipant
    {
        $name = Str::limit(Str::squish($name), 60, '');

        if ($name === '') {
            throw new InvalidArgumentException('Indiquez un prénom.');
        }

        return $this->add($stay, ['name' => $name, 'appetite' => $appetite, 'group_label' => Str::squish($group) ?: $name] + $this->coorganizerOwner($stay));
    }

    /** Lot 42 (42.1) : ce qu'ajoute un foyer qui co-organise est à lui. */
    private function coorganizerOwner(Stay $stay): array
    {
        $current = (int) CurrentHousehold::id();

        return $current === (int) $stay->household_id ? [] : ['linked_household_id' => $current];
    }

    private function add(Stay $stay, array $data): StayParticipant
    {
        if ($stay->participants()->count() >= self::MAX_PARTICIPANTS) {
            throw new InvalidArgumentException('Un séjour compte '.self::MAX_PARTICIPANTS.' personnes au plus.');
        }

        $data['appetite'] = $this->appetites->level($data['appetite'] ?? null);
        $data['group_label'] = Str::limit(Str::squish((string) $data['group_label']), 60, '') ?: $data['name'];
        $data['position'] = (int) $stay->participants()->max('position') + 1;

        return $stay->participants()->create($data);
    }

    /** @param array{name?: string, appetite?: string, group_label?: string, present_from?: string|null, present_to?: string|null} $data */
    public function updateParticipant(StayParticipant $participant, array $data): void
    {
        $stay = $participant->stay;
        $changes = [];

        if (array_key_exists('name', $data)) {
            $changes['name'] = Str::limit(Str::squish((string) $data['name']), 60, '') ?: $participant->name;
        }

        if (array_key_exists('appetite', $data)) {
            $changes['appetite'] = $this->appetites->level($data['appetite']);
        }

        if (array_key_exists('group_label', $data)) {
            $changes['group_label'] = Str::limit(Str::squish((string) $data['group_label']), 60, '') ?: $participant->name;
        }

        foreach (['present_from', 'present_to'] as $key) {
            if (array_key_exists($key, $data)) {
                $date = $data[$key] ? Carbon::parse($data[$key])->startOfDay() : null;
                $changes[$key] = $date && $date->between($stay->starts_on, $stay->ends_on) ? $date->toDateString() : null;
            }
        }

        $from = $changes['present_from'] ?? $participant->present_from?->toDateString();
        $to = $changes['present_to'] ?? $participant->present_to?->toDateString();

        if ($from && $to && $to < $from) {
            throw new InvalidArgumentException('Le départ de '.$participant->name.' précède son arrivée.');
        }

        $participant->update($changes);
    }

    /** Renomme un groupe partout : participants, dépenses, remboursements. */
    public function renameGroup(Stay $stay, string $from, string $to): void
    {
        $to = Str::limit(Str::squish($to), 60, '');

        if ($to === '' || $to === $from) {
            return;
        }

        DB::transaction(function () use ($stay, $from, $to) {
            $stay->participants()->where('group_label', $from)->update(['group_label' => $to]);
            $stay->payments()->where('group_label', $from)->update(['group_label' => $to]);
            $stay->payments()->where('to_group_label', $from)->update(['to_group_label' => $to]);
        });
    }

    /** @return list<string> groupes, dans l'ordre d'arrivée */
    public function groups(Stay $stay): array
    {
        return $stay->participants()->pluck('group_label')->unique()->values()->all();
    }

    /* ================================================================ Portions et contraintes */

    /** Portions des présents un jour donné (R33), arrondies à la demi-portion supérieure. */
    public function portionsOn(Stay $stay, Carbon $date): float
    {
        $sum = $stay->participants->filter(fn (StayParticipant $p) => $p->presentOn($date, $stay))
            ->sum(fn (StayParticipant $p) => $this->appetites->part($p->appetite));

        return $sum > 0 ? Appetites::roundUp($sum) : 0.0;
    }

    public function servingsFor(StayMeal $meal, Stay $stay): float
    {
        return $meal->servings !== null ? (float) $meal->servings : max(1.0, $this->portionsOn($stay, $meal->date));
    }

    /**
     * Les présents d'un jour, sous la forme attendue par GuestCompatibility : un invité du carnet,
     * ou une personne avec ses contraintes (membre du foyer, compte d'un foyer relié qui les partage).
     *
     * @return Collection<int, Guest|LinkedEater>
     */
    public function eatersOn(Stay $stay, Carbon $date): Collection
    {
        return $this->eaters($stay)->filter(fn (array $row) => $row['participant']->presentOn($date, $stay))->pluck('eater')->filter()->values();
    }

    /**
     * Chaque participant et ses contraintes, **telles que le foyer actif peut les voir**.
     *
     * Lot 42 (42.1, R45) : chaque participant appartient à un foyer (l'organisateur, ou celui qui
     * co-organise et l'a ajouté). Ses propres participants, un foyer les voit en entier ; ceux des
     * autres foyers, seulement par ce qu'ils partagent avec leurs foyers reliés (26.6) — jamais la
     * fiche d'un invité de l'autre foyer.
     *
     * @return Collection<int, array{participant: StayParticipant, eater: Guest|LinkedEater|null, restrictions: Collection, owner: int}>
     */
    public function eaters(Stay $stay): Collection
    {
        $viewer = (int) CurrentHousehold::id();
        $coorganizers = app(StayCoorganizers::class);
        $participants = $stay->participants;
        $owned = $participants->groupBy(fn (StayParticipant $p) => $coorganizers->ownerOf($p, $stay));
        $rows = collect();

        foreach ($owned as $owner => $group) {
            $resolved = (int) $owner === $viewer
                ? $this->ownEaters($stay, $group, (int) $owner)
                : $this->sharedEaters($group, (int) $owner, $viewer);

            $rows = $rows->merge($resolved->map(fn (array $row) => $row + ['owner' => (int) $owner]));
        }

        // Dans l'ordre de la liste des participants.
        return $rows->sortBy(fn (array $row) => $participants->search(fn ($p) => $p->id === $row['participant']->id))->values();
    }

    /**
     * Les participants d'un foyer, vus par ce foyer (dans son contexte) : ses invités, ses personnes,
     * et — pour l'organisateur — les comptes des foyers reliés qui ne co-organisent pas.
     *
     * @param  Collection<int, StayParticipant>  $participants
     * @return Collection<int, array{participant: StayParticipant, eater: Guest|LinkedEater|null, restrictions: Collection}>
     */
    private function ownEaters(Stay $stay, Collection $participants, int $owner): Collection
    {
        $organizer = $owner === (int) $stay->household_id;
        $participants->loadMissing('linkedHousehold');
        $guests = Guest::query()->with('restrictions.ingredient', 'restrictions.tag')
            ->whereIn('id', $participants->pluck('guest_id')->filter()->all() ?: [0])->get()->keyBy('id');
        // Nos personnes (lot 39) : par la personne notée sur le participant, sinon par son compte.
        $people = HouseholdPerson::query()->with('restrictions.ingredient', 'restrictions.tag')->get();
        $ours = fn (StayParticipant $p) => ($p->person_id ? $people->firstWhere('id', $p->person_id) : null)
            ?? (($user = $organizer ? $p->user_id : $p->linked_user_id) ? $people->firstWhere('user_id', $user) : null);
        $linked = $organizer
            ? $participants->whereNotNull('linked_household_id')->pluck('linkedHousehold')->filter()->unique('id')
                ->flatMap(fn (Household $household) => app(LinkedEaters::class)->membersOf($household))
                ->keyBy('userId')
            : collect();

        return $participants->map(function (StayParticipant $p) use ($guests, $ours, $linked, $organizer) {
            $guest = $p->guest_id ? $guests->get($p->guest_id) : null;
            $eater = match (true) {
                $guest !== null => $guest,
                ($person = $ours($p)) !== null => new LinkedEater($p->name, true, $person->restrictions, $person->restrictions, $organizer ? $p->user_id : $p->linked_user_id),
                $organizer && $p->linked_user_id !== null && $linked->has($p->linked_user_id) => new LinkedEater(
                    $p->name, $linked[$p->linked_user_id]->shared, $linked[$p->linked_user_id]->restrictions, $linked[$p->linked_user_id]->all, $p->linked_user_id,
                ),
                default => null,
            };

            if ($guest !== null) {
                $p->setRelation('guest', $guest);
            }

            return ['participant' => $p, 'eater' => $eater, 'restrictions' => $eater instanceof Guest ? $eater->restrictions : ($eater?->all ?? collect())];
        })->values();
    }

    /**
     * Les participants d'un autre foyer, vus du foyer actif : seulement ce que ce foyer partage avec
     * ses foyers reliés (comptes qui l'ont accepté, enfants dont un adulte l'a coché).
     *
     * @param  Collection<int, StayParticipant>  $participants
     * @return Collection<int, array{participant: StayParticipant, eater: LinkedEater|null, restrictions: Collection}>
     */
    private function sharedEaters(Collection $participants, int $owner, int $viewer): Collection
    {
        $members = collect();

        // Le foyer de chaque personne : le sien si c'est la personne d'un foyer relié, sinon celui qui gère.
        $homes = $participants->map(fn (StayParticipant $p) => (int) ($p->linked_household_id ?: $owner))->unique()
            ->filter(fn (int $home) => $home !== $viewer && app(HouseholdLinks::class)->areLinked($home, $viewer));

        foreach (Household::query()->whereIn('id', $homes->all() ?: [0])->get() as $household) {
            $members[$household->id] = app(LinkedEaters::class)->membersOf($household);
        }

        return $participants->map(function (StayParticipant $p) use ($members, $owner) {
            $home = (int) ($p->linked_household_id ?: $owner);
            $user = $p->linked_user_id ?? $p->user_id;
            $list = $members->get($home, collect());
            $member = $p->guest_id ? null : ($user
                ? $list->first(fn (LinkedEater $e) => $e->userId === (int) $user)
                : $list->first(fn (LinkedEater $e) => $e->userId === null && Str::before($e->name, ' (') === $p->name));
            $eater = $member ? new LinkedEater($p->name, $member->shared, $member->restrictions, $member->all, $member->userId) : null;
            $p->setRelation('guest', null);

            return ['participant' => $p, 'eater' => $eater, 'restrictions' => $eater?->all ?? collect()];
        })->values();
    }

    /**
     * Les alertes d'un plat du séjour, pour le foyer qui regarde ($viewer).
     *
     * Lot 42 (42.1) : chaque foyer vérifie le plat pour **ses** participants, avec tout ce qu'il sait
     * d'eux. Les alertes qui concernent les participants d'un autre foyer sont montrées sans nom
     * (« allergie de quelqu'un de « Martin » ») : on sait qu'il y a un problème, pas qui ni pourquoi
     * au-delà de l'ingrédient.
     *
     * @return list<array{level: string, message: string}>
     */
    public function conflicts(StayMeal $meal, Stay $stay, ?int $viewer = null): array
    {
        if (! $meal->recipe) {
            return [];
        }

        $viewer ??= (int) CurrentHousehold::id();
        $coorganizers = app(StayCoorganizers::class);
        $owners = $stay->participants->filter(fn (StayParticipant $p) => $p->presentOn($meal->date, $stay))
            ->map(fn (StayParticipant $p) => $coorganizers->ownerOf($p, $stay))->unique()->values();
        $names = $owners->count() > 1 || ! $owners->contains($viewer) ? $coorganizers->names($stay) : [];
        $conflicts = [];

        foreach ($owners as $owner) {
            $found = CurrentHousehold::run($owner, function () use ($meal, $stay, $owner) {
                $present = $stay->participants->filter(fn (StayParticipant $p) => $p->presentOn($meal->date, $stay)
                    && app(StayCoorganizers::class)->ownerOf($p, $stay) === $owner);
                $eaters = $this->ownEaters($stay, $present->values(), $owner)->pluck('eater')->filter();

                return $eaters->isEmpty() ? [] : $this->compatibility->conflicts($this->withLocalTags($meal->recipe, $owner), $eaters);
            });

            foreach ($found as $conflict) {
                if ($owner === $viewer) {
                    $conflicts[] = $conflict;
                } elseif (($conflict['source'] ?? null) !== 'stock') {
                    // Le stock de l'autre foyer ne nous regarde pas ; ses convives, si — sans les nommer.
                    $conflicts[] = ['message' => $this->anonymous($conflict, $names[$owner] ?? 'un autre foyer'), 'guest' => ''] + $conflict;
                }
            }
        }

        $conflicts = collect($conflicts)->unique('message')->values()->all();

        // Lot 40 (40.3) : « demande un four : pas sur place ».
        $equipment = app(\App\Services\Recipes\KitchenEquipment::class);
        $missing = $coorganizers->asOrganizer($stay, fn () => $equipment->missing($meal->recipe, $stay));

        if ($missing !== []) {
            $conflicts[] = [
                'level' => \App\Services\Planning\GuestCompatibility::WARNING,
                'guest' => '',
                'type' => \App\Enums\RestrictionType::Diet,
                'subject' => 'équipement',
                'optional' => false,
                'ingredient_id' => null,
                'message' => 'Demande : '.$equipment->labels($missing).' — pas sur place',
            ];
        }

        return $conflicts;
    }

    /** « Contient : noix — allergie de quelqu'un de « Martin » » */
    private function anonymous(array $conflict, string $household): string
    {
        $who = 'quelqu\'un de « '.$household.' »';
        $type = $conflict['type'] ?? null;

        if ($type instanceof \App\Enums\RestrictionType && ! $type->usesIngredient()) {
            return 'Pas « '.$conflict['subject'].' » — régime de '.$who;
        }

        $contains = 'Contient'.(($conflict['optional'] ?? false) ? ' (facultatif)' : '').' : '.$conflict['subject'];

        return $type === \App\Enums\RestrictionType::Allergy ? $contains.' — allergie de '.$who : $contains.' — '.$who.' n\'aime pas';
    }

    /**
     * Les catégories (végétarien…) sont propres à chaque foyer : pour vérifier une recette d'un autre
     * foyer, on lui prête les catégories du même nom du foyer qui vérifie.
     */
    private function withLocalTags(\App\Models\Recipe $recipe, int $household): \App\Models\Recipe
    {
        if ((int) $recipe->household_id === $household) {
            return $recipe;
        }

        $names = \Illuminate\Support\Facades\DB::table('recipe_tag')->join('tags', 'tags.id', '=', 'recipe_tag.tag_id')
            ->where('recipe_tag.recipe_id', $recipe->id)->pluck('tags.name')->all();
        $copy = clone $recipe;
        $copy->setRelation('tags', \App\Models\Tag::query()->whereIn('name', $names ?: [''])->get());

        return $copy;
    }

    /* ================================================================ Planning du séjour */

    public function addMeal(Stay $stay, string $date, int $slotId, ?int $recipeId, ?string $freeText = null): StayMeal
    {
        $day = Carbon::parse($date)->startOfDay();

        if (! $day->between($stay->starts_on, $stay->ends_on)) {
            throw new InvalidArgumentException('Ce jour n\'est pas dans le séjour.');
        }

        // Les créneaux sont ceux du foyer qui organise (lot 42 : aussi pour un foyer qui co-organise).
        if (! MealSlot::query()->withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class)->whereKey($slotId)->where('household_id', $stay->household_id)->exists()) {
            throw new InvalidArgumentException('Créneau inconnu.');
        }

        $recipe = $recipeId ? Recipe::active()->find($recipeId) : null;
        $freeText = Str::limit(Str::squish((string) $freeText), 150, '');

        if (! $recipe && $freeText === '') {
            throw new InvalidArgumentException('Choisissez une recette ou écrivez le repas.');
        }

        return $stay->meals()->create([
            'household_id' => CurrentHousehold::id(),   // lot 42 : qui l'a prévu (une recette de son carnet)
            'date' => $day->toDateString(),
            'meal_slot_id' => $slotId,
            'position' => (int) $stay->meals()->whereDate('date', $day->toDateString())->where('meal_slot_id', $slotId)->max('position') + 1,
            'recipe_id' => $recipe?->id,
            'free_text' => $recipe ? null : $freeText,
        ]);
    }

    public function setServings(StayMeal $meal, float|string|null $servings): void
    {
        $meal->update(['servings' => $servings === null || $servings === '' ? null : Appetites::clamp($servings)]);
    }
}
