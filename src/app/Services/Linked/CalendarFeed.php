<?php

namespace App\Services\Linked;

use App\Models\Household;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agenda du planning (idée C4, 14.10) : une adresse d'abonnement iCalendar (.ics) par personne,
 * que l'application Agenda du téléphone relit d'elle-même. Un événement par repas planifié
 * (créneau), de 2 semaines en arrière à 2 mois devant.
 *
 * L'adresse contient un jeton secret : chiffré en base pour être réaffiché, cherché par son condensé.
 * Pour un foyer relié qui ouvre son planning (26.4), la même adresse suivie de ?foyer=N.
 */
class CalendarFeed
{
    public const DAYS_BEFORE = 14;

    public const DAYS_AFTER = 62;

    public function __construct(private readonly HouseholdLinks $links) {}

    public function token(User $user): string
    {
        return $user->calendar_token ?: $this->regenerate($user);
    }

    public function regenerate(User $user): string
    {
        $token = Str::random(40);
        $user->forceFill(['calendar_token' => $token, 'calendar_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function url(User $user, ?int $householdId = null): string
    {
        return route('calendar.feed', ['token' => $this->token($user)] + ($householdId ? ['foyer' => $householdId] : []));
    }

    public function findUser(string $token): ?User
    {
        return User::query()->where('calendar_token_hash', hash('sha256', $token))->first();
    }

    /** Foyer dont la personne peut suivre le planning : le sien, ou un foyer relié qui l'ouvre. */
    public function allowedHousehold(User $user, ?int $householdId): ?Household
    {
        $own = CurrentHousehold::forUser($user);
        $householdId ??= $own;

        if (! $householdId) {
            return null;
        }

        $member = $user->households()->where('households.id', $householdId)->exists();

        if (! $member) {
            $viewer = $user->households()->pluck('households.id')->first(fn ($id) => $this->links->planningAccess($householdId, (int) $id) !== 'none');

            if (! $viewer) {
                return null;
            }
        }

        return Household::query()->whereKey($householdId)->whereNull('disabled_at')->first();
    }

    public function render(Household $household, ?Carbon $today = null): string
    {
        $today ??= Carbon::today();
        $from = $today->copy()->subDays(self::DAYS_BEFORE);
        $to = $today->copy()->addDays(self::DAYS_AFTER);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'bouffe';
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Bouffe//Planning des repas//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape('Repas — '.$household->name),
            'X-WR-TIMEZONE:'.config('app.timezone'),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        // Tout est lu dans le foyer du planning (l'adresse est appelée sans session).
        $events = CurrentHousehold::run($household, function () use ($from, $to, $household, $host, $stamp) {
            $meals = PlannedMeal::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNull('skipped_at')
                ->with('recipe', 'leftoverOf.recipe')->orderBy('date')->orderBy('position')->get();
            $slots = MealSlot::query()->ordered()->get()->keyBy('id');
            $occasions = MealOccasion::query()->between($from, $to)->with('slot')->get()->keyBy(fn (MealOccasion $o) => $o->cellKey());
            $lines = [];

            foreach ($meals->groupBy(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id) as $key => $cell) {
                [$date, $slotId] = explode('|', $key);
                $slot = $slots->get((int) $slotId);
                $occasion = $occasions->get($key);
                $start = $occasion?->serveAt() ?? Carbon::parse($date)->setTime(...MealOccasion::defaultTime($slot?->name));
                $labels = $cell->map(fn (PlannedMeal $m) => $m->label())->filter()->values();

                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:bouffe-'.$household->id.'-'.$date.'-'.$slotId.'@'.$host;
                $lines[] = 'DTSTAMP:'.$stamp;
                $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$start->copy()->addHour()->utc()->format('Ymd\THis\Z');
                $lines[] = 'SUMMARY:'.$this->escape(($occasion?->title ? $occasion->title.' — ' : ($slot?->name ? $slot->name.' : ' : '')).$labels->join(' · '));
                $lines[] = 'DESCRIPTION:'.$this->escape($labels->join("\n"));
                $lines[] = 'TRANSP:TRANSPARENT';
                $lines[] = 'END:VEVENT';
            }

            return $lines;
        });

        array_push($lines, ...$events);

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn (string $line) => $this->fold($line), $lines))."\r\n";
    }

    /** Texte iCalendar : barre oblique inverse, virgule, point-virgule et retour à la ligne échappés (RFC 5545 §3.3.11). */
    public function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Lignes de 75 octets au plus, suite précédée d'une espace (RFC 5545 §3.1), sans couper un caractère. */
    public function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            $limit = $out === '' ? 75 : 74;

            if (strlen($current.$char) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $char;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
