<?php

namespace App\Services\Receptions;

use App\Enums\Course;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\Planning\OccasionService;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Planning\WeekPlanner;
use App\Services\RecipePhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Réceptions (module 21) : liste, création, menu et souvenirs.
 *
 * Une réception n'est pas une nouvelle notion : c'est une case du planning avec des invités
 * (MealOccasion), à laquelle on ajoute une heure, un menu par plat et, après coup, un souvenir.
 */
class Receptions
{
    public const PHOTO_FOLDER = 'recipes/receptions';

    public function __construct(
        private readonly OccasionService $occasions,
        private readonly WeekPlanner $planner,
        private readonly ReceptionPlanner $receptionPlanner,
    ) {}

    /** @return Collection<int, MealOccasion> */
    public function upcoming(?Carbon $today = null): Collection
    {
        return $this->query()->whereDate('date', '>=', ($today ?? Carbon::today())->toDateString())
            ->orderBy('date')->orderBy('meal_slot_id')->get()
            ->filter(fn (MealOccasion $o) => $this->receptionPlanner->isReception($o))->values();
    }

    /** @return Collection<int, MealOccasion> */
    public function past(int $limit = 30, ?Carbon $today = null): Collection
    {
        return $this->query()->whereDate('date', '<', ($today ?? Carbon::today())->toDateString())
            ->orderByDesc('date')->limit($limit * 2)->get()
            ->filter(fn (MealOccasion $o) => $this->receptionPlanner->isReception($o))->take($limit)->values();
    }

    /** Réceptions auxquelles un invité a participé (21.4, fiche invité). @return Collection<int, MealOccasion> */
    public function forGuest(int $guestId): Collection
    {
        return $this->query()->whereHas('guests', fn ($q) => $q->whereKey($guestId))->orderByDesc('date')->get();
    }

    /**
     * Organise une réception : crée (ou complète) la case du planning.
     */
    public function create(Carbon|string $date, int $slotId, string $title, ?string $serveTime = null): MealOccasion
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('Donnez un nom à la réception (ex. « Anniversaire de Julie »).');
        }

        if (! MealSlot::whereKey($slotId)->exists()) {
            throw new InvalidArgumentException('Choisissez un créneau.');
        }

        $existing = $this->occasions->find($date, $slotId);

        $this->occasions->save($date, $slotId, [
            'title' => $title,
            'notes' => $existing?->notes,
            'absent_user_ids' => $existing?->absent_user_ids ?? [],
            'guest_ids' => $existing?->guests->pluck('id')->all() ?? [],
            'extra_adults' => $existing?->extra_adults ?? 0,
            'extra_children' => $existing?->extra_children ?? 0,
        ]);

        $occasion = $this->occasions->find($date, $slotId);
        $this->setServeTime($occasion, $serveTime);

        return $occasion->fresh(['guests', 'slot']);
    }

    public function setServeTime(MealOccasion $occasion, ?string $time): void
    {
        $time = trim((string) $time);

        if ($time !== '' && ! preg_match('/^([01]?\d|2[0-3])[:h]([0-5]\d)$/', $time, $m)) {
            throw new InvalidArgumentException('Heure invalide (ex. 19:30).');
        }

        $occasion->update(['serve_time' => $time === '' ? null : sprintf('%02d:%02d', (int) $m[1], (int) $m[2])]);
        $this->syncReminders($occasion);
    }

    /* ================================================================ Menu (21.1) */

    public function addDish(MealOccasion $occasion, int $recipeId, ?Course $course, int|float|null $servings = null): PlannedMeal
    {
        $recipe = Recipe::find($recipeId) ?? throw new InvalidArgumentException('Choisissez une recette.');
        $servings ??= max(1, $this->occasions->diners($occasion));

        $meal = $this->planner->addRecipe($occasion->date, $occasion->meal_slot_id, $recipe, $servings);
        $meal->update(['course' => $course?->value]);
        $this->syncReminders($occasion);

        return $meal;
    }

    public function setCourse(PlannedMeal $meal, ?Course $course): void
    {
        $meal->update(['course' => $course?->value]);

        if ($occasion = $this->occasions->find($meal->date, $meal->meal_slot_id)) {
            $this->syncReminders($occasion);
        }
    }

    /** Les rappels (cloche, téléphone) suivent le menu et l'heure. */
    public function syncReminders(MealOccasion $occasion): void
    {
        app(PrepReminderPlanner::class)->sync($occasion->date->copy(), $occasion->date->copy());
    }

    /* ================================================================ Souvenirs (21.4) */

    public function saveMemory(MealOccasion $occasion, ?string $note, ?UploadedFile $photo = null, bool $removePhoto = false): void
    {
        $photos = app(RecipePhotoService::class);
        $data = ['memory_note' => mb_substr(trim((string) $note), 0, 2000) ?: null];

        if (($removePhoto || $photo) && $occasion->memory_photo) {
            $photos->delete($occasion->memory_photo);
            $data['memory_photo'] = null;
        }

        if ($photo) {
            $data['memory_photo'] = $photos->store($photo, null, self::PHOTO_FOLDER);
        }

        $occasion->update($data);
    }

    public function photoUrl(MealOccasion $occasion, string $size = 'thumb'): ?string
    {
        return $occasion->memory_photo
            ? route('receptions.photo', ['occasion' => $occasion->id, 'size' => $size, 'v' => $occasion->updated_at?->timestamp])
            : null;
    }

    /** « Dîner du 12 octobre » */
    public function name(MealOccasion $occasion): string
    {
        $slot = $occasion->slot?->name ?? 'Repas';

        return $occasion->title ?: $slot.' du '.$occasion->date->locale('fr')->isoFormat('D MMMM YYYY');
    }

    private function query()
    {
        return MealOccasion::query()->with('guests', 'slot');
    }
}
