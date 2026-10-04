<?php

namespace App\Services\Stays;

use App\Models\Stay;
use App\Models\StayParticipant;
use App\Models\StayPayment;
use App\Services\Planning\Appetites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Frais partagés d'un séjour (lot 34, 34.3, règle R37).
 *
 * - La part de chacun se calcule **par personne** ou **par appétit**, au prorata des jours de
 *   présence ; elle est portée par son groupe (ceux qui paient ensemble).
 * - Les remboursements proposés sont les moins nombreux possible ; montants au centime, l'arrondi
 *   restant va à celui qui a le plus avancé.
 * - Bouffe n'effectue aucun paiement : un remboursement fait est coché à la main.
 *
 * Tous les calculs se font en centimes, pour que tout tombe juste.
 */
class StayCosts
{
    public function __construct(private readonly Appetites $appetites) {}

    public function addExpense(Stay $stay, string $group, string $label, float|string $amount, ?string $date = null): StayPayment
    {
        $cents = $this->cents($amount);
        $group = Str::limit(Str::squish($group), 60, '');

        if ($group === '') {
            throw new InvalidArgumentException('Qui a payé ?');
        }

        if ($cents <= 0 || $cents > 10_000_000) {
            throw new InvalidArgumentException('Indiquez un montant positif.');
        }

        return $stay->payments()->create([
            'household_id' => \App\Support\CurrentHousehold::id(),   // lot 42 : le foyer qui l'a notée (R45)
            'kind' => StayPayment::EXPENSE,
            'group_label' => $group,
            'label' => Str::limit(Str::squish($label), 150, '') ?: 'Dépense',
            'amount' => $cents / 100,
            'paid_on' => $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString(),
            'created_by' => auth()->id(),
        ]);
    }

    /** « Remboursé » : le remboursement proposé est noté comme fait. */
    public function settle(Stay $stay, string $from, string $to, float|string $amount): StayPayment
    {
        $cents = $this->cents($amount);

        if ($cents <= 0 || $from === $to) {
            throw new InvalidArgumentException('Remboursement invalide.');
        }

        return $stay->payments()->create([
            'household_id' => \App\Support\CurrentHousehold::id(),
            'kind' => StayPayment::REFUND,
            'group_label' => $from,
            'to_group_label' => $to,
            'label' => 'Remboursement',
            'amount' => $cents / 100,
            'paid_on' => Carbon::today()->toDateString(),
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Le bilan : part de chaque groupe, ce qu'il a avancé, son solde, et les remboursements à faire.
     *
     * @return array{mode: string, total: float, weight: float, groups: list<array{label: string, people: list<string>, weight: float, share: float, paid: float, sent: float, received: float, balance: float}>, transfers: list<array{from: string, to: string, amount: float}>, balanced: bool}
     */
    public function summary(Stay $stay): array
    {
        $stay->loadMissing('participants', 'payments');
        $weights = [];
        $people = [];

        foreach ($stay->participants as $participant) {
            $group = $participant->group_label;
            $weights[$group] = ($weights[$group] ?? 0.0) + $this->weight($participant, $stay);
            $people[$group][] = $participant->name;
        }

        $paid = $sent = $received = [];

        foreach ($stay->payments as $payment) {
            $cents = $this->cents($payment->amount);

            if ($payment->isRefund()) {
                $sent[$payment->group_label] = ($sent[$payment->group_label] ?? 0) + $cents;
                $received[$payment->to_group_label] = ($received[$payment->to_group_label] ?? 0) + $cents;
            } else {
                $paid[$payment->group_label] = ($paid[$payment->group_label] ?? 0) + $cents;
            }
        }

        // Tous les groupes : participants, et ceux qui ont payé sans venir.
        $labels = array_values(array_unique([...array_keys($weights), ...array_keys($paid), ...array_keys($sent), ...array_keys($received)]));
        $total = array_sum($paid);
        $weight = array_sum($weights);
        $shares = $this->shares($labels, $weights, $paid, $total);

        $groups = [];
        $balances = [];

        foreach ($labels as $label) {
            $balance = ($paid[$label] ?? 0) + ($sent[$label] ?? 0) - ($received[$label] ?? 0) - $shares[$label];
            $balances[$label] = $balance;
            $groups[] = [
                'label' => $label,
                'people' => $people[$label] ?? [],
                'weight' => round($weights[$label] ?? 0.0, 2),
                'share' => (float) ($shares[$label] / 100),
                'paid' => (float) (($paid[$label] ?? 0) / 100),
                'sent' => (float) (($sent[$label] ?? 0) / 100),
                'received' => (float) (($received[$label] ?? 0) / 100),
                'balance' => (float) ($balance / 100),
            ];
        }

        $transfers = array_map(fn (array $t) => ['from' => $t[0], 'to' => $t[1], 'amount' => (float) ($t[2] / 100)], $this->transfers($balances));

        return [
            'mode' => $stay->split_mode,
            'total' => (float) ($total / 100),
            'weight' => round($weight, 2),
            'groups' => $groups,
            'transfers' => $transfers,
            'balanced' => $total > 0 && $transfers === [],
        ];
    }

    /** Poids d'une personne : 1 (par personne) ou sa part d'appétit (R33), fois ses jours de présence. */
    public function weight(StayParticipant $participant, Stay $stay): float
    {
        $unit = $stay->split_mode === 'person' ? 1.0 : $this->appetites->part($participant->appetite);

        return $unit * $participant->daysPresent($stay);
    }

    /**
     * Parts au centime ; l'arrondi restant va au groupe qui a le plus avancé (R37).
     *
     * @return array<string, int>
     */
    private function shares(array $labels, array $weights, array $paid, int $total): array
    {
        $weight = array_sum($weights);
        $shares = [];

        foreach ($labels as $label) {
            $shares[$label] = $weight > 0 ? (int) round($total * ($weights[$label] ?? 0.0) / $weight) : 0;
        }

        $rest = $weight > 0 ? $total - array_sum($shares) : 0;

        if ($rest !== 0) {
            $biggest = collect($labels)->sortByDesc(fn ($label) => $paid[$label] ?? 0)->first();
            $shares[$biggest] += $rest;
        }

        return $shares;
    }

    /**
     * Le moins de remboursements possible : d'abord les dettes qui s'annulent exactement deux à deux,
     * puis le plus gros débiteur rembourse le plus gros créancier, et ainsi de suite (au plus
     * « nombre de groupes − 1 » virements).
     *
     * @param  array<string, int>  $balances  centimes : positif = on lui doit, négatif = il doit
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function transfers(array $balances): array
    {
        $debtors = array_filter($balances, fn (int $b) => $b < 0);
        $creditors = array_filter($balances, fn (int $b) => $b > 0);
        $transfers = [];

        foreach ($debtors as $debtor => $debt) {
            foreach ($creditors as $creditor => $credit) {
                if ($credit === -$debt) {
                    $transfers[] = [$debtor, $creditor, $credit];
                    unset($debtors[$debtor], $creditors[$creditor]);

                    break;
                }
            }
        }

        while ($debtors !== [] && $creditors !== []) {
            arsort($creditors);
            asort($debtors);
            $debtor = array_key_first($debtors);
            $creditor = array_key_first($creditors);
            $amount = min(-$debtors[$debtor], $creditors[$creditor]);

            $transfers[] = [$debtor, $creditor, $amount];
            $debtors[$debtor] += $amount;
            $creditors[$creditor] -= $amount;

            if ($debtors[$debtor] === 0) {
                unset($debtors[$debtor]);
            }

            if ($creditors[$creditor] === 0) {
                unset($creditors[$creditor]);
            }
        }

        return $transfers;
    }

    private function cents(float|string|null $amount): int
    {
        return (int) round((float) str_replace([',', ' ', "\u{202F}", "\u{00A0}", '€'], ['.', '', '', '', ''], (string) $amount) * 100);
    }
}
