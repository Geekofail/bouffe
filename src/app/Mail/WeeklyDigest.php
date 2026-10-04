<?php

namespace App\Mail;

use App\Enums\ListStatus;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Receptions\Receptions;
use App\Services\Shopping\ShoppingItemPresenter;
use App\Services\Stock\ExpiryAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Récapitulatif de la semaine par e-mail (19.3) : le menu, la liste de courses, ce qu'il faut
 * consommer vite et les réceptions à venir. Envoyé le dimanche soir (réglable).
 */
class WeeklyDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public Carbon $weekStart) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bouffe — la semaine du '.$this->weekStart->locale('fr')->isoFormat('D MMMM'));
    }

    public function content(): Content
    {
        $planner = app(WeekPlanner::class);
        $weekEnd = $this->weekStart->copy()->addDays(6);

        $meals = $planner->mealsForWeek($this->weekStart)->groupBy(fn (PlannedMeal $m) => $m->date->toDateString());

        $list = ShoppingList::query()
            ->where('status', ListStatus::Active->value)
            ->whereDate('period_end', '>=', $this->weekStart->toDateString())
            ->whereDate('period_start', '<=', $weekEnd->toDateString())
            ->latest('id')->first();

        $presenter = app(ShoppingItemPresenter::class);
        $items = $list?->items()->where('is_removed', false)->where('is_checked', false)
            ->with('ingredient.aisle', 'aisle')->get()
            ->groupBy(fn ($item) => $item->aisle?->name ?? $item->ingredient?->aisle?->name ?? 'Autres')
            ->map(fn ($group) => $group->map(fn ($item) => $presenter->text($item))->all())
            ->all() ?? [];

        return new Content(view: 'mail.weekly-digest', with: [
            'days' => collect(range(0, 6))->map(fn (int $i) => $this->weekStart->copy()->addDays($i)),
            'meals' => $meals,
            'list' => $list,
            'items' => $items,
            'expiring' => app(ExpiryAlerts::class)->expiringBy(Carbon::today()->addDays(3))->take(10),
            'receptions' => app(Receptions::class)->upcoming()->filter(fn ($o) => $o->date->lte($weekEnd)),
            'receptionService' => app(Receptions::class),
        ]);
    }
}
