<?php

namespace App\Livewire\Receipts;

use App\Services\Budget\BudgetTracker;
use App\Services\Receipts\OcrService;
use App\Services\Receipts\ReceiptService;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tickets de caisse : les derniers, leur état, et les lectures du mois (24.7).
 */
#[Title('Tickets de caisse')]
class Index extends Component
{
    public function render(ReceiptService $receipts, OcrService $ocr, BudgetTracker $tracker)
    {
        return view('livewire.receipts.index', [
            'receipts' => $receipts->recent(),
            'usage' => $ocr->usage(),
            'status' => $ocr->status(),
            'tracker' => $tracker,
        ]);
    }
}
