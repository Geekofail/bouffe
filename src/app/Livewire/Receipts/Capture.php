<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use App\Services\Receipts\OcrService;
use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptService;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Nouveau ticket (24.1) : une ou plusieurs photos (ou un PDF), puis lecture ou saisie à la main.
 */
#[Title('Nouveau ticket')]
class Capture extends Component
{
    use WithFileUploads;

    /** Photo en cours d'envoi (préparée dans le navigateur). */
    public $newPhoto = null;

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public function updatedNewPhoto(): void
    {
        $this->validate(
            ['newPhoto' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288']],
            ['newPhoto.mimes' => 'Une photo (JPEG, PNG) ou un PDF.', 'newPhoto.max' => 'Fichier trop lourd (12 Mo au plus).'],
        );

        $this->photos[] = $this->newPhoto;
        $this->newPhoto = null;
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function read(ReceiptService $receipts)
    {
        abort_unless(auth()->user()->canEdit(), 403);

        if (! $receipt = $this->store($receipts)) {
            return null;
        }

        \App\Support\TimeLimit::atLeast(180);

        try {
            $receipts->read($receipt);
        } catch (ReadingFailed $e) {
            session()->flash('receipt-error', $e->getMessage());
        }

        return $this->redirectRoute('receipts.show', $receipt, navigate: true);
    }

    public function manual(ReceiptService $receipts)
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $receipt = $this->photos === []
            ? Receipt::create(['status' => Receipt::DRAFT, 'created_by' => auth()->id()])
            : $this->store($receipts);

        if (! $receipt) {
            return null;
        }

        $receipts->manual($receipt);

        return $this->redirectRoute('receipts.show', $receipt, navigate: true);
    }

    private function store(ReceiptService $receipts): ?Receipt
    {
        try {
            return $receipts->create($this->photos);
        } catch (InvalidArgumentException $e) {
            $this->addError('photos', $e->getMessage());

            return null;
        }
    }

    public function render(OcrService $ocr)
    {
        return view('livewire.receipts.capture', [
            'status' => $ocr->status(),
            'usage' => $ocr->usage(),
        ]);
    }
}
