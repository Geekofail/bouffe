<?php

namespace App\Services\Assistant;

use App\Models\AssistantUsage;
use App\Models\Scopes\HouseholdScope;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Accès à l'assistant culinaire (lot 33) : disponible ou non, coût du mois, plafond (33.5, R34).
 *
 * Principe 5 du document 07 : l'assistant n'est jamais indispensable. Sans clé, désactivé ou au-delà
 * du plafond, ses boutons disparaissent ; tout le reste de Bouffe fonctionne comme avant.
 */
class AssistantService
{
    public function __construct(private readonly CookingAssistant $assistant) {}

    public function enabled(): bool
    {
        return Settings::bool('assistant.enabled', (bool) config('bouffe.assistant.enabled', true));
    }

    public function cap(): float
    {
        return round(Settings::float('assistant.monthly_cap', (float) config('bouffe.assistant.monthly_cap', 1.0)), 2);
    }

    /**
     * @return array{available: bool, reason: string|null, label: string}
     */
    public function status(): array
    {
        $label = $this->assistant->label();

        if (! $this->enabled()) {
            return ['available' => false, 'reason' => 'L\'assistant est désactivé (Paramètres → Assistant).', 'label' => $label];
        }

        if (! $this->assistant->configured()) {
            return ['available' => false, 'reason' => 'Aucune clé Mistral : renseignez-la dans Paramètres → Tickets de caisse.', 'label' => $label];
        }

        $usage = $this->usage();

        if ($usage['cost'] >= $usage['cap']) {
            return ['available' => false, 'reason' => 'Plafond de '.self::euros($usage['cap']).' atteint ce mois-ci : l\'assistant revient le mois prochain.', 'label' => $label];
        }

        return ['available' => true, 'reason' => null, 'label' => $label];
    }

    public function available(): bool
    {
        return $this->status()['available'];
    }

    /**
     * Demandes du mois, pour toute l'installation (la clé est payée une seule fois).
     *
     * @return array{count: int, failed: int, tokens_in: int, tokens_out: int, cost: float, cap: float, by_kind: array<string, int>}
     */
    public function usage(?Carbon $month = null): array
    {
        $month ??= Carbon::now();
        $rows = AssistantUsage::withoutGlobalScope(HouseholdScope::class)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get();
        $ok = $rows->where('succeeded', true);

        return [
            'count' => $ok->count(),
            'failed' => $rows->where('succeeded', false)->count(),
            'tokens_in' => (int) $ok->sum('tokens_in'),
            'tokens_out' => (int) $ok->sum('tokens_out'),
            'cost' => round((float) $rows->sum('cost_estimate'), 4),
            'cap' => $this->cap(),
            'by_kind' => $ok->groupBy('kind')->map->count()->all(),
        ];
    }

    /** Coût estimé en euros, d'après le tarif publié (config bouffe.assistant). */
    public function cost(int $tokensIn, int $tokensOut): float
    {
        $usd = ($tokensIn * (float) config('bouffe.assistant.price_in', 0.15) + $tokensOut * (float) config('bouffe.assistant.price_out', 0.60)) / 1_000_000;

        return round($usd * (float) config('bouffe.assistant.usd_to_eur', 0.9), 6);
    }

    /**
     * Une demande, comptée et plafonnée.
     *
     * @param  array<string, mixed>|null  $schema
     *
     * @throws AssistantFailed
     */
    public function run(string $kind, string $system, string $prompt, ?array $schema, int $maxTokens): AssistantAnswer
    {
        $status = $this->status();

        if (! $status['available']) {
            throw new AssistantFailed((string) $status['reason']);
        }

        // Même au pire (réponse la plus longue permise), la demande ne doit pas dépasser le plafond.
        $worst = $this->cost((int) ceil(mb_strlen($system.$prompt) / 3), $maxTokens);

        if ($this->usage()['cost'] + $worst > $this->cap()) {
            throw new AssistantFailed('Cette demande dépasserait le plafond de '.self::euros($this->cap()).' du mois : l\'assistant revient le mois prochain.');
        }

        try {
            $answer = $this->assistant->ask($system, $prompt, $schema, $maxTokens);
        } catch (AssistantFailed $e) {
            $this->record($kind, 0, 0, false);

            throw $e;
        }

        $this->record($kind, $answer->tokensIn, $answer->tokensOut, true);

        return $answer;
    }

    private function record(string $kind, int $in, int $out, bool $succeeded): void
    {
        AssistantUsage::create([
            'user_id' => auth()->id(),
            'kind' => $kind,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'cost_estimate' => $this->cost($in, $out),
            'succeeded' => $succeeded,
            'created_at' => now(),
        ]);
    }

    /** « 0,04 € », « 1 € » */
    public static function euros(float $amount): string
    {
        $decimals = $amount > 0 && $amount < 0.1 ? 3 : 2;
        $text = number_format($amount, $decimals, ',', ' ');

        return (str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text).' €';
    }
}
