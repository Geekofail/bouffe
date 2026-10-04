<?php

namespace App\Services\Shortcuts;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Activity\ActivityLog;
use App\Services\Recipes\RecipeInbox;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\QuickAddParser;
use App\Services\Stock\StockManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Ce que les raccourcis peuvent faire (lot 38, 38.1, règle R39) — et rien d'autre : ajouter aux
 * courses ou au stock, lire le menu du jour et du lendemain, déposer une recette dans « À trier ».
 * Jamais de suppression, ni de dépenses, ni de données des proches. Chaque geste est noté au journal
 * du foyer. Les réponses sont des phrases courtes, que Siri peut lire.
 */
class ShortcutActions
{
    public function __construct(
        private readonly DictationParser $dictation,
        private readonly ActivityLog $log,
    ) {}

    /** « deux baguettes et du beurre » → la liste de courses en cours. */
    public function addToShopping(string $text): string
    {
        $items = $this->dictation->items($text);

        if ($items === []) {
            throw new InvalidArgumentException('Je n\'ai rien compris à ajouter. Dites par exemple : deux baguettes et du beurre.');
        }

        $manager = app(ShoppingListManager::class);
        $list = $manager->currentOrNew();
        $labels = [];

        foreach ($items as $item) {
            $labels[] = $this->lower($manager->addManual($list, $item)->label);
        }

        $this->log->record('shopping', 'a ajouté aux courses par un raccourci : '.$this->join($labels), $list);

        return 'Ajouté aux courses : '.$this->join($labels).'.';
    }

    /** « six œufs et un litre de lait » → le stock (rangé à l'emplacement habituel). */
    public function addToStock(string $text): string
    {
        $items = $this->dictation->items($text);

        if ($items === []) {
            throw new InvalidArgumentException('Je n\'ai rien compris à ranger. Dites par exemple : six œufs et un litre de lait.');
        }

        $parser = app(QuickAddParser::class);
        $stock = app(StockManager::class);
        $names = [];

        foreach ($items as $item) {
            $parsed = $parser->parse($item);
            $stock->add([
                'ingredient_id' => $parsed['ingredient']?->id,
                'label' => $parsed['ingredient'] ? null : $parsed['name'],
                'quantity' => $parsed['quantity'],
                'unit_id' => $parsed['unit']?->id,
            ]);
            // On répète ce qui a été dit (« 6 œufs »), plus parlant que le nom de l'article seul.
            $names[] = $this->lower($item);
        }

        $this->log->record('stock', 'a ajouté au stock par un raccourci : '.$this->join($names));

        return 'Ajouté au stock : '.$this->join($names).'.';
    }

    /**
     * « On mange quoi ? » : le jour même (les repas pas encore passés), puis le lendemain.
     *
     * @param  string  $when  auto · aujourdhui · demain
     */
    public function menu(string $when = 'auto', ?Carbon $now = null): string
    {
        $now ??= now();
        $slots = MealSlot::query()->active()->ordered()->get();
        $today = $this->day($now->copy()->startOfDay(), $slots);
        $tomorrow = $this->day($now->copy()->addDay()->startOfDay(), $slots);

        $parts = [];

        if ($when !== 'demain') {
            // L'après-midi, le déjeuner est passé : on ne parle plus que du soir.
            $rest = $now->hour >= 15 && $when === 'auto' ? array_slice($today, -1, 1, true) : $today;
            $parts[] = $rest === []
                ? 'Rien de prévu aujourd\'hui.'
                : ($now->hour >= 15 && $when === 'auto' ? 'Ce soir : ' : 'Aujourd\'hui. ').$this->sentence($rest, $now->hour >= 15 && $when === 'auto');
        }

        if ($when !== 'aujourdhui') {
            $parts[] = $tomorrow === [] ? 'Rien de prévu demain.' : 'Demain. '.$this->sentence($tomorrow, false);
        }

        return implode(' ', $parts);
    }

    /** Une adresse, un texte ou une photo de recette → « À trier ». */
    public function receive(?string $url, ?string $text, ?\Illuminate\Http\UploadedFile $photo): string
    {
        $inbox = app(RecipeInbox::class);

        $item = match (true) {
            $photo !== null => $inbox->receivePhoto($photo, 'raccourci'),
            filled($url) => $inbox->receiveUrl((string) $url, 'raccourci'),
            filled($text) => $inbox->receiveText((string) $text, 'raccourci'),
            default => throw new InvalidArgumentException('Rien reçu : partagez une page de recette, un texte ou une photo.'),
        };

        $this->log->record('recipe', 'a envoyé une recette dans « À trier » : '.$item->label());

        return match ($item->status) {
            'ready' => '« '.$item->label().' » est dans À trier, prête à relire.',
            'failed' => 'Reçue dans À trier, mais la page n\'a pas pu être lue : '.rtrim((string) $item->error, '.').'.',
            default => $item->kind === 'photo' ? 'Photo reçue dans À trier : elle sera lue dans quelques minutes.' : 'Reçue dans À trier.',
        };
    }

    /**
     * Repas d'un jour par créneau : [« Déjeuner » => « restes de chili »].
     *
     * @return array<string, string>
     */
    private function day(Carbon $date, $slots): array
    {
        $meals = PlannedMeal::query()->whereDate('date', $date->toDateString())->whereNull('skipped_at')
            ->with('recipe', 'leftoverOf.recipe', 'forPerson')->get()
            ->sortBy(fn (PlannedMeal $m) => [$m->course?->order() ?? 3, $m->position])
            ->groupBy('meal_slot_id');
        $day = [];

        foreach ($slots as $slot) {
            $labels = $meals->get($slot->id, collect())->map(fn (PlannedMeal $m) => $this->spoken($m));

            if ($labels->isNotEmpty()) {
                $day[$slot->name] = $this->join($labels->all());
            }
        }

        return $day;
    }

    /** @param array<string, string> $day */
    private function sentence(array $day, bool $single): string
    {
        if ($single) {
            return reset($day).'.';
        }

        return implode(' ', array_map(fn (string $slot, string $meals) => $slot.' : '.$meals.'.', array_keys($day), $day));
    }

    /** Un repas tel qu'on le dit : « chili con carne », « restes de chili con carne », « gamelle de Léo : … ». */
    private function spoken(PlannedMeal $meal): string
    {
        $title = $this->lower((string) ($meal->eatenRecipe()?->title ?? $meal->label()));

        return match (true) {
            $meal->isLunchbox() => $this->lower($meal->lunchboxLabel()).' : '.$title,
            $meal->isLeftover() => 'restes de '.$title,
            default => $this->lower($meal->label()),
        };
    }

    /** Première lettre en minuscule, sauf sigle ou nom propre probable (deux majuscules). */
    private function lower(string $text): string
    {
        return preg_match('/^\p{Lu}\p{Lu}/u', $text) ? $text : mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** « a, b et c ». @param list<string> $items */
    private function join(array $items): string
    {
        $items = array_values($items);

        return count($items) <= 1 ? (string) ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)).' et '.end($items);
    }
}
