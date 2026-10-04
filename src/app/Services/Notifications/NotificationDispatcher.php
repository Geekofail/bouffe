<?php

namespace App\Services\Notifications;

use App\Mail\WeeklyDigest;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Wish;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Stock\ExpiryAlerts;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Tâche planifiée (19.4) : `php artisan bouffe:reminders`, lancée toutes les 5 à 15 minutes.
 *
 *  1. recalcule les rappels de préparation des prochains jours (R21, réceptions comprises) ;
 *  2. envoie sur les téléphones abonnés ce qui est dû, selon les choix de chacun (19.2), dont le
 *     rendez-vous du soir (lot 37, R38) qui regroupe les rappels de la veille ;
 *  3. le jour et à l'heure réglés, envoie le récapitulatif de la semaine par e-mail (19.3).
 *
 * Chaque envoi est noté (notification_deliveries) : lancer la commande deux fois n'envoie rien
 * deux fois. Rien ne part la nuit : ce qui tombe entre 22 h et 7 h attend le matin.
 */
class NotificationDispatcher
{
    /**
     * Types de notifications, leur libellé et leur réglage par défaut (question Q25 : préparation
     * la veille et péremption uniquement).
     */
    public const TYPES = [
        'prep' => ['À préparer à l\'avance', 'Décongeler, faire tremper, mariner, et le rétroplanning des réceptions.', true],
        'expiry' => ['Produits à consommer', 'Un message par jour, à l\'heure réglée, s\'il y a des produits qui arrivent à leur date.', true],
        'wishes' => ['Envies', '« Monique a une envie : lasagnes ».', false],
        'list' => ['Liste de courses prête', 'Quand quelqu\'un d\'autre a préparé la liste.', false],
        // Lot 30 (30.6, Q48 : les deux premiers activés par défaut).
        'linked_invite' => ['Invitation d\'un foyer relié', '« Les Martin vous invitent : dîner du samedi 17 octobre ».', true],
        'linked_reply' => ['Réponse à mon invitation', '« Les Martin viendront à 4 » ou « ne viendront pas ».', true],
        'surplus' => ['Surplus proposé', 'Un foyer relié donne quelque chose : courgettes du jardin, reste de fête…', false],
        'rating' => ['Avis reçu', 'Un proche a noté une de vos recettes.', false],
        'price_rise' => ['Hausse de prix marquée', '+15 % ou plus sur un produit que vous achetez, d\'un relevé au suivant.', false],
        // Lot 41 (41.1) : même pendant les heures calmes — c'est vous qui l'avez lancé.
        'timer' => ['Minuteur terminé', 'Quand aucune page de Bouffe ouverte ne l\'a fait sonner (à quelques minutes près).', true],
    ];

    /** Messages de ce qui s'est passé depuis moins de 24 heures (proches, prix). */
    public const RECENT_HOURS = 24;

    /** Un rappel dû depuis plus longtemps n'est plus envoyé (installation arrêtée quelques jours). */
    public const STALE_HOURS = 12;

    public function __construct(
        private readonly WebPush $push,
        private readonly PrepReminderPlanner $prep,
        private readonly ExpiryAlerts $expiry,
        private readonly HouseholdNews $news,
    ) {}

    public static function wants(User $user, string $type): bool
    {
        return (bool) $user->preference('notify.'.$type, self::TYPES[$type][2] ?? false);
    }

    public static function wantsRecap(User $user): bool
    {
        return (bool) $user->preference('notify.recap', false);
    }

    /**
     * « Ne pas déranger » de chacun (lot 30, 30.6) : activé par défaut, aux heures de l'installation.
     *
     * @return array{on: bool, from: int, until: int}
     */
    public static function quietHours(User $user): array
    {
        return [
            'on' => (bool) $user->preference('notify.quiet', true),
            'from' => (int) $user->preference('notify.quiet_from', (int) config('bouffe.notifications.quiet_from', 22)),
            'until' => (int) $user->preference('notify.quiet_until', (int) config('bouffe.notifications.quiet_until', 7)),
        ];
    }

    public function isQuietFor(User $user, Carbon $now): bool
    {
        $quiet = self::quietHours($user);

        return $quiet['on'] && self::between($now->hour, $quiet['from'], $quiet['until']);
    }

    /**
     * @return array{reminders: int, sent: int, users: int, recap: int, quiet: bool, closed: int, usage: int, households: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $totals = ['reminders' => 0, 'sent' => 0, 'users' => 0, 'recap' => 0, 'quiet' => $this->isQuiet($now), 'closed' => 0, 'usage' => 0, 'households' => 0];

        // Lot 24 : chaque foyer actif, dans son propre contexte (rien ne passe d'un foyer à l'autre).
        foreach (\App\Models\Household::query()->whereNull('disabled_at')->whereNull('deletion_requested_at')->orderBy('id')->get() as $household) {
            $result = \App\Support\CurrentHousehold::run($household, fn () => $this->runHousehold($now, $totals['quiet']));

            foreach (['reminders', 'sent', 'users', 'recap', 'closed', 'usage'] as $key) {
                $totals[$key] += $result[$key];
            }

            $totals['households']++;
        }

        // Foyers dont la suppression demandée il y a 30 jours arrive à échéance (25.8).
        app(\App\Services\Households\HouseholdManager::class)->purgeDue($now);

        Settings::set('notifications.last_run', ['at' => $now->toIso8601String(), 'sent' => $totals['sent'], 'recap' => $totals['recap']]);

        return $totals;
    }

    /** @return array{reminders: int, sent: int, users: int, recap: int, closed: int, usage: int} */
    private function runHousehold(Carbon $now, bool $quiet): array
    {
        // 1. Rappels à jour (le planning a pu changer depuis le dernier passage).
        $reminders = $this->prep->sync($now->copy()->startOfDay(), $now->copy()->addDays(7));

        // Stock (lot 21) : clôture automatique des repas passés (R23) et consommations régulières (22.4).
        $closed = 0;
        $usage = 0;

        if ($now->hour >= \App\Services\Planning\MealClosing::MORNING_HOUR) {
            $closed = app(\App\Services\Planning\MealClosing::class)->autoClose($now);
            $usage = app(\App\Services\Stock\StockUsage::class)->applyRules($now->copy()->startOfDay());
        }

        // Budget (lot 22) : les dépenses récurrentes échues deviennent des dépenses.
        app(\App\Services\Budget\RecurringExpenses::class)->apply($now->copy()->startOfDay());

        // Journal du foyer (lot 30) : 30 jours.
        app(\App\Services\Activity\ActivityLog::class)->purge($now);

        // Tickets (lot 23, 24.8) : les photos plus anciennes que la durée choisie sont supprimées.
        app(\App\Services\Receipts\ReceiptService::class)->purgePhotos($now->copy()->startOfDay());

        // « À trier » (lot 38) : les photos reçues sont lues, les éléments triés depuis un mois oubliés.
        $inbox = app(\App\Services\Recipes\RecipeInbox::class);
        $inbox->processPending();
        $inbox->purge($now);

        // 2. Téléphones des membres du foyer.
        $sent = 0;
        $users = 0;

        if ($this->push->isSupported() && PushSubscription::query()->exists()) {
            foreach (User::query()->inHousehold()->whereHas('pushSubscriptions')->with('pushSubscriptions')->get() as $user) {
                // Lot 30 (30.6) : chacun ses heures calmes ; ce qui tombe pendant attend le matin.
                // Sauf la fin d'un minuteur (lot 41) : il a été lancé à l'instant.
                $messages = $this->messagesFor($user, $now);

                if ($this->isQuietFor($user, $now)) {
                    $messages = array_values(array_filter($messages, fn (array $m) => $m['type'] === 'timer'));
                }

                $count = $this->pushTo($user, $messages);
                $sent += $count;
                $users += $count > 0 ? 1 : 0;
            }
        }

        // Minuteurs : signalés une fois, même si personne n'avait de téléphone abonné.
        app(\App\Services\Kitchen\KitchenTimers::class)->toNotify($now)->each(fn ($timer) => $timer->update(['notified_at' => $now]));

        // 3. Récapitulatif par e-mail.
        $recap = $this->recapIsDue($now) ? $this->sendRecap($now) : 0;

        return ['reminders' => $reminders, 'sent' => $sent, 'users' => $users, 'recap' => $recap, 'closed' => $closed, 'usage' => $usage];
    }

    /** Clé d'envoi propre au foyer (une personne de deux foyers reçoit les messages de chacun). */
    private function householdKey(string $key): string
    {
        $id = \App\Support\CurrentHousehold::id();

        return $id === \App\Support\CurrentHousehold::defaultId() ? $key : 'h'.$id.':'.$key;
    }

    /**
     * Ce qu'il faudrait envoyer maintenant à une personne (hors messages déjà envoyés).
     *
     * @return list<array{key: string, type: string, title: string, body: string|null, url: string, tag: string}>
     */
    public function messagesFor(User $user, Carbon $now): array
    {
        $messages = [];
        $evening = app(\App\Services\Planning\EveningRendezvous::class);

        // Lot 37 (37.1, R38) : le rendez-vous du soir, une fois par jour et par personne.
        if ($evening->isDue($user, $now)) {
            $key = $this->householdKey('evening:'.$now->toDateString());

            if (! $this->wasDelivered($user, $key) && ($message = $evening->message($user, $now, $key))) {
                $messages[] = $message;
            }
        }

        if (self::wants($user, 'prep')) {
            $due = Reminder::query()->due($now)
                ->where('due_at', '>=', $now->copy()->subHours(self::STALE_HOURS)->toDateTimeString())
                ->with('meal')->orderBy('due_at')->get();
            $eveningSent = fn (Carbon $day) => $this->wasDelivered($user, $this->householdKey('evening:'.$day->toDateString()));

            foreach ($due as $reminder) {
                // Regroupé dans le rendez-vous du soir (R38) : pas de message à part.
                if ($evening->groups($user, $reminder, $now, $eveningSent)) {
                    continue;
                }

                $messages[] = [
                    'key' => 'reminder:'.$reminder->id, 'type' => 'prep',
                    'title' => $reminder->title,
                    'body' => $reminder->detail,
                    'url' => route('planner.week', ['semaine' => $reminder->meal?->date->copy()->startOfWeek()->toDateString()]),
                    'tag' => 'reminder-'.$reminder->id,
                ];
            }
        }

        if (self::wants($user, 'expiry') && $now->hour >= Settings::int('notifications.daily_hour', 18)) {
            $items = $this->expiry->expiringBy($now->copy()->addDay()->startOfDay(), $now->copy()->startOfDay());

            if ($items->isNotEmpty()) {
                $names = $items->take(4)->map(fn (array $row) => $row['item']->name())->join(', ');
                $messages[] = [
                    'key' => $this->householdKey('expiry:'.$now->toDateString()), 'type' => 'expiry',
                    'title' => $items->count() === 1 ? '1 produit à consommer d\'ici demain' : $items->count().' produits à consommer d\'ici demain',
                    'body' => $names.($items->count() > 4 ? '…' : ''),
                    'url' => route('stock.index', ['filtre' => 'alertes']),
                    'tag' => 'expiry',
                ];
            }
        }

        if (self::wants($user, 'wishes')) {
            $wishes = Wish::query()->open()->where('user_id', '!=', $user->id)
                ->where('created_at', '>=', $now->copy()->subDay())->with('user', 'recipe')->get();

            foreach ($wishes as $wish) {
                $messages[] = [
                    'key' => 'wish:'.$wish->id, 'type' => 'wishes',
                    'title' => ($wish->user?->name ?? 'Quelqu\'un').' a une envie',
                    'body' => $wish->label(),
                    'url' => route('planner.week'),
                    'tag' => 'wish-'.$wish->id,
                ];
            }
        }

        if (self::wants($user, 'list')) {
            foreach ($this->news->readyLists($user, $now) as $list) {
                $messages[] = [
                    'key' => 'list:'.$list->id, 'type' => 'list',
                    'title' => ($list->creator?->name ?? 'Quelqu\'un').' a préparé la liste de courses',
                    'body' => $list->items_count.' article'.($list->items_count > 1 ? 's' : '').' à acheter',
                    'url' => route('shopping.show', $list),
                    'tag' => 'list-'.$list->id,
                ];
            }
        }

        array_push($messages, ...$this->linkedMessages($user, $now));
        array_push($messages, ...$this->timerMessages($user, $now));

        $already = DB::table('notification_deliveries')->where('user_id', $user->id)->where('channel', 'push')
            ->whereIn('key', array_column($messages, 'key'))->pluck('key')->all();

        return array_values(array_filter($messages, fn (array $m) => ! in_array($m['key'], $already, true)));
    }

    /**
     * Lot 30 (30.6) : foyers reliés et prix — ce qui s'est passé depuis moins de 24 heures.
     *
     * @return list<array{key: string, type: string, title: string, body: string|null, url: string, tag: string}>
     */
    private function linkedMessages(User $user, Carbon $now): array
    {
        $messages = [];
        $since = $now->copy()->subHours(self::RECENT_HOURS);
        $me = (int) \App\Support\CurrentHousehold::id();
        $scope = \App\Models\Scopes\HouseholdScope::class;
        $date = fn (?Carbon $d) => $d ? $d->locale('fr')->isoFormat('dddd D MMMM') : '';

        if (self::wants($user, 'linked_invite')) {
            $invitations = \App\Models\MealOccasionHousehold::query()->where('household_id', $me)->where('status', 'invited')
                ->where('created_at', '>=', $since)
                ->with(['occasion' => fn ($q) => $q->withoutGlobalScope($scope), 'occasion.household'])->get();

            foreach ($invitations as $row) {
                $messages[] = [
                    'key' => 'occasion-invite:'.$row->id, 'type' => 'linked_invite',
                    'title' => ($row->occasion?->household?->name ?? 'Un foyer relié').' vous invite',
                    'body' => ($row->occasion?->title ?: 'Repas').' du '.$date($row->occasion?->date),
                    'url' => route('linked.meal', $row->id),
                    'tag' => 'occasion-invite-'.$row->id,
                ];
            }
        }

        if (self::wants($user, 'linked_reply')) {
            $replies = \App\Models\MealOccasionHousehold::query()
                ->whereIn('meal_occasion_id', \App\Models\MealOccasion::query()->select('id'))
                ->where('household_id', '!=', $me)
                ->whereNotNull('responded_at')->where('responded_at', '>=', $since)
                ->with('household', 'occasion')->get();

            foreach ($replies as $row) {
                $name = $row->household?->name ?? 'Un foyer relié';
                $messages[] = [
                    'key' => 'occasion-reply:'.$row->id.':'.$row->status, 'type' => 'linked_reply',
                    'title' => $row->status === 'accepted' ? $name.' viendra'.($row->people ? ' à '.$row->people : '') : $name.' ne viendra pas',
                    'body' => ($row->occasion?->title ?: 'Repas').' du '.$date($row->occasion?->date).($row->message ? ' · « '.$row->message.' »' : ''),
                    'url' => $row->occasion ? route('receptions.show', $row->occasion) : route('receptions.index'),
                    'tag' => 'occasion-reply-'.$row->id,
                ];
            }
        }

        if (self::wants($user, 'surplus')) {
            foreach (app(\App\Services\Linked\Surplus::class)->fromLinked($me)->where('created_at', '>=', $since) as $offer) {
                $messages[] = [
                    'key' => 'surplus:'.$offer->id, 'type' => 'surplus',
                    'title' => ($offer->household?->name ?? 'Un foyer relié').' donne : '.$offer->label,
                    'body' => 'Jusqu\'au '.$date($offer->available_until),
                    'url' => route('linked.surplus'),
                    'tag' => 'surplus-'.$offer->id,
                ];
            }
        }

        if (self::wants($user, 'rating')) {
            $ratings = \App\Models\RecipeRating::query()
                ->whereIn('recipe_id', \App\Models\Recipe::query()->select('id'))
                ->whereNotNull('household_id')->where('household_id', '!=', $me)
                ->where('updated_at', '>=', $since)
                ->with(['user', 'recipe'])->get();

            foreach ($ratings as $rating) {
                $messages[] = [
                    'key' => 'rating:'.$rating->id.':'.$rating->updated_at->timestamp, 'type' => 'rating',
                    'title' => ($rating->user?->name ?? 'Un proche').' a noté « '.$rating->recipe?->title.' »',
                    'body' => str_repeat('★', (int) $rating->rating).($rating->comment ? ' · '.\Illuminate\Support\Str::limit($rating->comment, 80) : ''),
                    'url' => $rating->recipe ? route('recipes.show', $rating->recipe) : route('recipes.index'),
                    'tag' => 'rating-'.$rating->id,
                ];
            }
        }

        if (self::wants($user, 'price_rise')) {
            foreach (app(\App\Services\Pricing\PersonalInflation::class)->rises($now->copy()->startOfDay(), 1) as $rise) {
                $messages[] = [
                    'key' => $this->householdKey('rise:'.$rise['ingredient']->id.':'.($rise['store']?->id ?? 0).':'.$rise['after_on']->toDateString()), 'type' => 'price_rise',
                    'title' => 'Hausse de prix : '.$rise['ingredient']->name.' +'.number_format($rise['change'] * 100, 0, ',', '').' %',
                    'body' => ($rise['store']?->name ?? 'Magasin non précisé').' : '.$rise['label_before'].' → '.$rise['label_after'],
                    'url' => route('prices.index', ['vue' => 'evolution']),
                    'tag' => 'rise-'.$rise['ingredient']->id,
                ];
            }
        }

        return $messages;
    }

    /**
     * Lot 41 (41.1) : minuteurs finis qu'aucune page ouverte n'a fait sonner. Pour la personne qui
     * l'a lancé ; si elle n'a aucun appareil abonné (tablette de cuisine), pour tout le foyer.
     *
     * @return list<array{key: string, type: string, title: string, body: string|null, url: string, tag: string}>
     */
    private function timerMessages(User $user, Carbon $now): array
    {
        if (! self::wants($user, 'timer')) {
            return [];
        }

        $timers = app(\App\Services\Kitchen\KitchenTimers::class);
        $messages = [];

        foreach ($timers->toNotify($now)->load('user.pushSubscriptions') as $timer) {
            $ownerReachable = $timer->user && $timer->user->pushSubscriptions->isNotEmpty();

            if ($ownerReachable && (int) $timer->user_id !== (int) $user->id) {
                continue;
            }

            $messages[] = [
                'key' => 'timer:'.$timer->id, 'type' => 'timer',
                'title' => '⏰ '.$timer->label.' : c\'est l\'heure',
                'body' => 'Minuteur de '.\App\Support\Duration::format(max(1, (int) round($timer->duration / 60))).($timer->user && ! $ownerReachable ? ' lancé par '.$timer->user->name : '').'.',
                'url' => $timers->urlFor($timer),
                'tag' => 'timer-'.$timer->id,
            ];
        }

        return $messages;
    }

    /**
     * @param  list<array>  $messages
     * @return int messages envoyés
     */
    public function pushTo(User $user, array $messages): int
    {
        $sent = 0;

        foreach ($messages as $message) {
            $delivered = false;

            foreach ($user->pushSubscriptions()->get() as $subscription) {
                $delivered = $this->push->send($subscription, [
                    'title' => $message['title'], 'body' => $message['body'], 'url' => $message['url'], 'tag' => $message['tag'],
                    'badge' => $message['badge'] ?? null,
                ]) === 'sent' || $delivered;
            }

            // Noté même en cas d'échec : un téléphone éteint ne doit pas recevoir dix fois le même rappel.
            $this->markDelivered($user, $message['key'], 'push');
            $sent += $delivered ? 1 : 0;
        }

        return $sent;
    }

    /** Message d'essai, depuis Paramètres → Notifications. @return array{sent: int, failed: int} */
    public function test(User $user): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($user->pushSubscriptions()->get() as $subscription) {
            $this->push->send($subscription, [
                'title' => 'Bouffe',
                'body' => 'Les notifications fonctionnent sur cet appareil. 👍',
                'url' => route('settings.notifications'),
                'tag' => 'test',
            ]) === 'sent' ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /* ================================================================ Récapitulatif (19.3) */

    public function recapIsDue(Carbon $now): bool
    {
        return Settings::bool('notifications.recap_enabled', false)
            && $now->dayOfWeek === Settings::int('notifications.recap_day', 0)
            && $now->hour >= Settings::int('notifications.recap_hour', 18);
    }

    /**
     * Envoie le récapitulatif de la semaine à venir aux personnes qui l'ont demandé.
     *
     * @param  User|null  $only  envoi d'essai à une seule personne (toujours envoyé)
     * @return int e-mails envoyés
     */
    public function sendRecap(Carbon $now, ?User $only = null): int
    {
        $weekStart = $now->copy()->addDay()->startOfWeek();
        $key = $this->householdKey('recap:'.$weekStart->toDateString());
        $users = $only ? collect([$only]) : User::query()->inHousehold()->whereNotNull('email')->get()->filter(fn (User $u) => self::wantsRecap($u));
        $count = 0;

        foreach ($users as $user) {
            if (! $only && DB::table('notification_deliveries')->where(['user_id' => $user->id, 'key' => $key, 'channel' => 'mail'])->exists()) {
                continue;
            }

            Mail::to($user->email, $user->name)->send(new WeeklyDigest($user, $weekStart));

            if (! $only) {
                $this->markDelivered($user, $key, 'mail');
            }

            $count++;
        }

        return $count;
    }

    /* ================================================================ Outils */

    /** Heures calmes par défaut de l'installation (chacun peut régler les siennes, lot 30). */
    public function isQuiet(Carbon $now): bool
    {
        return self::between($now->hour, (int) config('bouffe.notifications.quiet_from', 22), (int) config('bouffe.notifications.quiet_until', 7));
    }

    private static function between(int $hour, int $from, int $until): bool
    {
        if ($from === $until) {
            return false;
        }

        return $from > $until ? ($hour >= $from || $hour < $until) : ($hour >= $from && $hour < $until);
    }

    private function wasDelivered(User $user, string $key): bool
    {
        return DB::table('notification_deliveries')->where(['user_id' => $user->id, 'key' => mb_substr($key, 0, 120), 'channel' => 'push'])->exists();
    }

    private function markDelivered(User $user, string $key, string $channel): void
    {
        DB::table('notification_deliveries')->insertOrIgnore([
            'user_id' => $user->id, 'key' => mb_substr($key, 0, 120), 'channel' => $channel, 'sent_at' => now(),
        ]);
    }
}
