<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export des dépenses d'une période en CSV (lot 22 — 23.9), prêt pour Excel en français :
 * séparateur « ; », virgule décimale, encodage UTF-8 avec BOM.
 */
class ExpenseExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date']]);

        $from = Carbon::parse($request->query('du', Carbon::today()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('au', Carbon::today()->toDateString()))->endOfDay();

        $expenses = Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->with('splits.category', 'store', 'payer')
            ->orderBy('spent_on')->orderBy('id')
            ->get();

        $filename = 'bouffe-depenses-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($expenses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Lieu', 'Poste', 'Montant', 'Personnes', 'Payé par', 'Origine', 'Note'], ';');

            foreach ($expenses as $expense) {
                // Un ticket mixte donne une ligne par poste : les totaux par poste restent justes dans le tableur.
                foreach ($expense->splits as $split) {
                    fputcsv($out, [
                        $expense->spent_on->format('d/m/Y'),
                        $expense->placeLabel(),
                        $split->category?->name,
                        number_format((float) $split->amount, 2, ',', ''),
                        $expense->persons,
                        $expense->payer?->name,
                        ['manual' => 'saisie', 'receipt' => 'ticket', 'recurring' => 'récurrente'][$expense->source] ?? $expense->source,
                        $expense->note,
                    ], ';');
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
