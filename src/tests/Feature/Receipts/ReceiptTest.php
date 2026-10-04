<?php

use App\Enums\UserRole;
use App\Livewire\Receipts\Capture;
use App\Livewire\Receipts\Index as ReceiptsIndex;
use App\Livewire\Receipts\Show;
use App\Livewire\Recipes\Import;
use App\Livewire\Settings\ReceiptSettings;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\OcrReading;
use App\Models\Receipt;
use App\Models\ReceiptLabelMapping;
use App\Models\ShoppingList;
use App\Models\StandingItem;
use App\Models\StockItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Receipts\CardScrubber;
use App\Services\Receipts\JpegPdf;
use App\Services\Receipts\OcrService;
use App\Services\Receipts\Readers\AzureReader;
use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptInterpreter;
use App\Services\Receipts\ReceiptMatcher;
use App\Services\Receipts\ReceiptReading;
use App\Services\Receipts\ReceiptService;
use App\Services\Shopping\ShoppingListManager;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Tickets de caisse lus automatiquement (lot 23 — module 24, règles R27 et R28).
 *
 * Aucun appel réseau : les réponses des services sont SIMULÉES (Http::fake), construites d'après
 * la documentation publique de Mistral et d'Azure. Ce ne sont pas des tickets réels — la qualité de
 * lecture sur de vrais tickets se vérifie avec `php artisan bouffe:ticket` (§7.4).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class, StorageLocationSeeder::class]);
    Storage::fake('local');
    AzureReader::$pollDelayMs = 0;

    Settings::set('receipts.provider', 'mistral');
    Settings::set('receipts.mistral_key', 'cle-de-test');

    $this->cactus = Store::create(['name' => 'Cactus']);

    /** Réponse Mistral simulée : un ticket Cactus de 15,41 €. */
    $this->mistral = function (array $overrides = [], ?array $lines = null) {
        $annotation = array_merge([
            'store_name' => 'CACTUS BERELDANGE',
            'date' => '2026-10-13',
            'total' => 15.41,
            'currency' => 'EUR',
            'lines' => $lines ?? [
                ['label' => 'LAIT DEMI ECR UHT 1L', 'readable_name' => 'lait demi-écrémé', 'quantity' => 2, 'unit' => 'piece', 'unit_price' => 1.09, 'amount' => 2.18, 'kind' => 'article'],
                ['label' => 'BEURRE DOUX 250G', 'readable_name' => 'beurre', 'quantity' => 1, 'unit' => 'piece', 'unit_price' => 2.49, 'amount' => 2.49, 'kind' => 'article'],
                ['label' => 'REMISE FIDELITE', 'readable_name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => -0.50, 'kind' => 'discount'],
                ['label' => 'POMMES DE TERRE', 'readable_name' => 'pommes de terre', 'quantity' => 1.25, 'unit' => 'kg', 'unit_price' => 1.60, 'amount' => 2.00, 'kind' => 'article'],
                ['label' => 'LESSIVE LIQUIDE 2L', 'readable_name' => 'lessive', 'quantity' => 1, 'unit' => 'piece', 'unit_price' => 8.99, 'amount' => 8.99, 'kind' => 'article'],
                ['label' => 'CONSIGNE BOUTEILLE', 'readable_name' => null, 'quantity' => 1, 'unit' => null, 'unit_price' => 0.25, 'amount' => 0.25, 'kind' => 'deposit'],
                ['label' => 'TVA 3%', 'readable_name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 0.30, 'kind' => 'tax'],
                ['label' => 'VISA ****1234', 'readable_name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 15.41, 'kind' => 'payment'],
            ],
        ], $overrides);

        Http::fake(['api.mistral.ai/*' => Http::response([
            'pages' => [['index' => 0, 'markdown' => "CACTUS BERELDANGE\nLAIT DEMI ECR UHT 1L 2 x 1,09\nVISA 4970 1012 3456 7890", 'images' => [], 'dimensions' => ['dpi' => 200, 'height' => 2000, 'width' => 800]]],
            'model' => 'mistral-ocr-latest',
            'document_annotation' => json_encode($annotation),
            'usage_info' => ['pages_processed' => 1, 'doc_size_bytes' => 12345],
        ])]);
    };

    $this->newReceipt = fn (int $photos = 1) => app(ReceiptService::class)->create(
        array_map(fn ($i) => UploadedFile::fake()->image("ticket{$i}.jpg", 600, 1600), range(1, $photos)),
    );
});

/* ================================================================ Photos et PDF (24.1, 24.8) */

test('une photo est réduite et gardée sur le disque privé ; plusieurs photos forment un PDF', function () {
    $receipt = app(ReceiptService::class)->create([UploadedFile::fake()->image('long.jpg', 1200, 4000), UploadedFile::fake()->image('suite.jpg', 800, 1000)]);

    expect($receipt->photo_paths)->toHaveCount(2);
    Storage::disk('local')->assertExists($receipt->photo_paths[0]);
    expect(getimagesize(Storage::disk('local')->path($receipt->photo_paths[0]))[1])->toBe(2400);   // 4000 px → 2400

    $document = app(\App\Services\Receipts\ReceiptFiles::class)->document($receipt);
    $pdf = file_get_contents($document['path']);

    expect($document['mime'])->toBe('application/pdf')
        ->and($document['pages'])->toBe(2)
        ->and($pdf)->toStartWith('%PDF-1.4')
        ->and(preg_match_all('#/Type /Page\b#', $pdf))->toBe(2);

    // Table des renvois exacte : chaque décalage pointe sur « N 0 obj ».
    preg_match('/startxref\n(\d+)/', $pdf, $m);
    $xref = substr($pdf, (int) $m[1]);
    preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
    foreach ($offsets[1] as $i => $offset) {
        expect(substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')))->toBe(($i + 1).' 0 obj');
    }
    @unlink($document['path']);
});

test('un PDF seul est accepté, pas mélangé à des photos', function () {
    $image = UploadedFile::fake()->image('a.jpg');
    $pdf = UploadedFile::fake()->createWithContent('ticket.pdf', JpegPdf::build([$image->getRealPath()]));

    expect(app(ReceiptService::class)->create([$pdf])->photo_paths[0])->toEndWith('.pdf')
        ->and(fn () => app(ReceiptService::class)->create([$pdf, UploadedFile::fake()->image('b.jpg')]))->toThrow(InvalidArgumentException::class, 'pas les deux');
});

test('les numéros de carte bancaire ne sont jamais enregistrés', function () {
    expect(CardScrubber::text('VISA ****1234'))->not->toContain('1234')
        ->and(CardScrubber::text('Carte XXXX XXXX XXXX 5678 débit'))->not->toContain('5678')
        ->and(CardScrubber::text('4970 1012 3456 7890'))->toBe('[carte]')
        ->and(CardScrubber::text('BEURRE 250G 2,49'))->toBe('BEURRE 250G 2,49');
});

/* ================================================================ R27 — lecture */

test('les lignes sont interprétées : genres, quantités, remise rattachée, paiement écarté', function () {
    $reading = new ReceiptReading('mistral', 'CACTUS', '13/10/2026', 9.97, [
        ['label' => '2 x 1,29 YAOURT NATURE', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 2.58, 'kind' => 'article'],
        ['label' => 'Réduction -30%', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 0.77, 'kind' => 'article'],
        ['label' => 'CAROTTES 0,532 kg x 3,99 €/kg', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 2.12, 'kind' => 'article'],
        ['label' => 'PFAND', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 0.25, 'kind' => 'other'],
        ['label' => 'SAC CABAS', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 0.10, 'kind' => 'article'],
        ['label' => 'PAIN', 'name' => null, 'quantity' => 1, 'unit' => null, 'unit_price' => 2.10, 'amount' => null, 'kind' => 'article'],
        ['label' => 'CHOCOLAT', 'name' => null, 'quantity' => 3, 'unit' => 'piece', 'unit_price' => 1.00, 'amount' => 3.59, 'kind' => 'article'],
        ['label' => 'TOTAL', 'name' => null, 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => 9.97, 'kind' => 'article'],
    ], 1);

    $data = app(ReceiptInterpreter::class)->interpret($reading);
    $lines = collect($data['lines'])->keyBy('label');

    expect($data['date'])->toBe('2026-10-13')
        ->and($lines)->toHaveCount(6)                                    // remise rattachée, TOTAL écarté
        ->and($lines['YAOURT NATURE']['count'])->toBe(2.0)
        ->and($lines['YAOURT NATURE']['unit_price'])->toBe(1.29)
        ->and($lines['YAOURT NATURE']['discount'])->toBe(-0.77)          // signe rétabli
        ->and($lines['CAROTTES']['weight'])->toBe(532.0)
        ->and($lines['CAROTTES']['doubtful'])->toBeFalse()
        ->and($lines['PFAND']['kind'])->toBe('deposit')
        ->and($lines['SAC CABAS']['kind'])->toBe('bag')
        ->and($lines['PAIN']['amount'])->toBe(2.10)                      // calculé, mais signalé
        ->and($lines['PAIN']['doubtful'])->toBeTrue()
        ->and($lines['CHOCOLAT']['doubtful'])->toBeTrue();               // 3 × 1,00 ≠ 3,59

    $check = app(ReceiptInterpreter::class)->consistency($data['lines'], 9.97);
    expect($check['sum'])->toBe(9.97)->and($check['ok'])->toBeTrue()
        ->and(app(ReceiptInterpreter::class)->consistency($data['lines'], 12.00)['ok'])->toBeFalse();
});

test('Mistral reçoit la photo et le schéma, et sa réponse devient une lecture', function () {
    ($this->mistral)();
    $receipt = ($this->newReceipt)();

    $reading = app(OcrService::class)->readReceipt($receipt);

    expect($reading->storeName)->toBe('CACTUS BERELDANGE')
        ->and($reading->total)->toBe(15.41)
        ->and($reading->lines)->toHaveCount(8)
        ->and($reading->pages)->toBe(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mistral.ai/v1/ocr'
        && $request->hasHeader('Authorization', 'Bearer cle-de-test')
        && str_starts_with($request['document']['image_url'], 'data:image/jpeg;base64,')
        && $request['document_annotation_format']['type'] === 'json_schema'
        && $request['document_annotation_format']['json_schema']['strict'] === true);

    expect(OcrReading::sole())->toMatchArray(['purpose' => 'receipt', 'provider' => 'mistral', 'pages' => 1, 'succeeded' => true])
        ->and((float) OcrReading::sole()->cost_estimate)->toBe(0.005);
});

test('Azure : envoi, attente du résultat, champs du modèle reçu', function () {
    Settings::set('receipts.provider', 'azure');
    Settings::set('receipts.azure_endpoint', 'https://bouffe.cognitiveservices.azure.com/');
    Settings::set('receipts.azure_key', 'cle-azure');

    Http::fake([
        '*:analyze*' => Http::response(null, 202, ['Operation-Location' => 'https://bouffe.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/abc?api-version=2024-11-30']),
        '*/analyzeResults/*' => Http::sequence()
            ->push(['status' => 'running'])
            ->push(['status' => 'succeeded', 'analyzeResult' => [
                'pages' => [['pageNumber' => 1]],
                'content' => 'DELHAIZE',
                'documents' => [['fields' => [
                    'MerchantName' => ['type' => 'string', 'valueString' => 'Delhaize Strassen'],
                    'TransactionDate' => ['type' => 'date', 'valueDate' => '2026-10-12'],
                    'Total' => ['type' => 'currency', 'valueCurrency' => ['amount' => 5.47, 'currencyCode' => 'EUR']],
                    'Items' => ['type' => 'array', 'valueArray' => [
                        ['type' => 'object', 'valueObject' => [
                            'Description' => ['valueString' => 'BEURRE 250G'],
                            'Quantity' => ['valueNumber' => 1],
                            'TotalPrice' => ['valueCurrency' => ['amount' => 2.49]],
                        ]],
                        ['type' => 'object', 'valueObject' => [
                            'Description' => ['valueString' => 'TOMATES'],
                            'Quantity' => ['valueNumber' => 0.745],
                            'QuantityUnit' => ['valueString' => 'kg'],
                            'Price' => ['valueCurrency' => ['amount' => 4.00]],
                            'TotalPrice' => ['valueCurrency' => ['amount' => 2.98]],
                        ]],
                    ]],
                ]]],
            ]]),
    ]);

    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());

    expect($receipt->provider)->toBe('azure')
        ->and($receipt->store_name)->toBe('Delhaize Strassen')
        ->and($receipt->purchased_on->toDateString())->toBe('2026-10-12')
        ->and((float) $receipt->total)->toBe(5.47)
        ->and($receipt->lines->firstWhere('label', 'TOMATES')->weight)->toEqual('745.000')
        ->and(app(ReceiptService::class)->consistency($receipt)['ok'])->toBeTrue();

    Http::assertSent(fn ($r) => $r->hasHeader('Ocp-Apim-Subscription-Key', 'cle-azure') && str_contains($r->url(), 'prebuilt-receipt:analyze?api-version=2024-11-30'));
});

test('un refus du service est expliqué et le ticket reste en brouillon', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    $receipt = ($this->newReceipt)();

    expect(fn () => app(ReceiptService::class)->read($receipt))->toThrow(ReadingFailed::class, 'clé Mistral est refusée');

    expect($receipt->fresh()->status)->toBe(Receipt::DRAFT)
        ->and($receipt->fresh()->error)->toContain('refusée')
        ->and(OcrReading::sole()->succeeded)->toBeFalse();
});

/* ================================================================ R28 — rapprochement */

test('les libellés se normalisent et se rapprochent des ingrédients', function () {
    expect(ReceiptMatcher::normalize('Lait demi écr. UHT 1L'))->toBe('LAIT DEMI ECR UHT')
        ->and(ReceiptMatcher::normalize('BEURRE 250 G'))->toBe('BEURRE')
        ->and(ReceiptMatcher::normalize('YAOURT NAT 4X125G'))->toBe('YAOURT NAT');

    $matcher = app(ReceiptMatcher::class);
    [$qty, $unit] = $matcher->pack('YAOURT NAT 4X125G');

    expect($qty)->toBe(500.0)->and($unit->code)->toBe('g')
        ->and($matcher->byName('BEURRE DOUX 250G')?->name)->toBe('Beurre')
        ->and($matcher->byName('POMMES DE TERRE')?->name)->toBe('Pomme de terre')
        ->and($matcher->byName('SELLERIE'))->toBeNull();
});

test('une correspondance apprise dans le magasin passe avant tout le reste (24.5)', function () {
    $lait = Ingredient::firstWhere('name', 'Lait demi-écrémé');
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    ReceiptLabelMapping::create(['store_id' => null, 'normalized_label' => 'LAIT DEMI ECR UHT', 'kind' => 'article', 'ingredient_id' => $beurre->id, 'confirmations' => 5]);
    ReceiptLabelMapping::create(['store_id' => $this->cactus->id, 'normalized_label' => 'LAIT DEMI ECR UHT', 'kind' => 'article', 'ingredient_id' => $lait->id, 'confirmations' => 1]);

    $line = ['kind' => 'article', 'label' => 'LAIT DEMI ECR UHT 1L', 'suggested_name' => null];

    expect(app(ReceiptMatcher::class)->match([$line], $this->cactus)[0])->toMatchArray(['ingredient_id' => $lait->id, 'confidence' => 'learned'])
        ->and(app(ReceiptMatcher::class)->match([$line], null)[0]['ingredient_id'])->toBe($beurre->id);   // ailleurs : la plus confirmée
});

/* ================================================================ Relecture et validation (24.3, 24.4) */

test('un ticket lu est prêt à relire : magasin, date, lignes reconnues, liste proposée', function () {
    ($this->mistral)();
    $list = ShoppingList::create(['name' => 'Semaine', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18']);
    app(ShoppingListManager::class)->addManual($list, 'Beurre');

    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    $lines = $receipt->lines->keyBy('label');

    expect($receipt->status)->toBe(Receipt::REVIEW)
        ->and($receipt->store_id)->toBe($this->cactus->id)
        ->and($receipt->purchased_on->toDateString())->toBe('2026-10-13')
        ->and($receipt->shopping_list_id)->toBe($list->id)
        ->and($lines)->toHaveCount(6)                                              // remise rattachée, TVA gardée, paiement écarté
        ->and($lines['BEURRE DOUX 250G']->confidence)->toBe('name')
        ->and((float) $lines['BEURRE DOUX 250G']->discount)->toBe(-0.5)
        ->and((float) $lines['BEURRE DOUX 250G']->pack_quantity)->toBe(250.0)
        ->and($lines['LAIT DEMI ECR UHT 1L']->confidence)->toBe('suggested')
        ->and($lines['LESSIVE LIQUIDE 2L']->kind)->toBe('non_food')
        ->and($lines['LESSIVE LIQUIDE 2L']->budget_category_id)->toBe(BudgetCategory::firstWhere('kind', 'household')->id)
        ->and(app(ReceiptService::class)->consistency($receipt)['ok'])->toBeTrue()
        ->and(json_encode($receipt->raw_result))->not->toContain('1234')->not->toContain('7890');

    expect(Expense::count())->toBe(0)->and(StockItem::count())->toBe(0);   // R27 : rien avant « Valider »
});

test('valider remplit la dépense, les prix, le stock et la liste, et apprend les libellés', function () {
    ($this->mistral)();
    $list = ShoppingList::create(['name' => 'Semaine', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18']);
    $beurreItem = app(ShoppingListManager::class)->addManual($list, 'Beurre');
    $oeufs = app(ShoppingListManager::class)->addManual($list, 'Œuf');

    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    $outcome = app(ReceiptService::class)->validate($receipt);

    $expense = Expense::sole();
    expect((float) $expense->amount)->toBe(15.41)
        ->and($expense->source)->toBe('receipt')
        ->and($expense->receipt_id)->toBe($receipt->id)
        ->and($expense->store_id)->toBe($this->cactus->id)
        ->and($expense->spent_on->toDateString())->toBe('2026-10-13')
        ->and($expense->splits->pluck('amount', 'budget_category_id')->map(fn ($v) => (float) $v)->all())->toBe([
            BudgetCategory::groceries()->id => 6.42,
            BudgetCategory::firstWhere('kind', 'household')->id => 8.99,
        ]);

    // Prix (R17), par magasin
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $price = IngredientPrice::where('ingredient_id', $beurre->id)->sole();
    expect($price->source)->toBe('receipt')->and((float) $price->price)->toBe(1.99)->and($price->store_id)->toBe($this->cactus->id)
        // Lot 30 (R35) : ligne avec remise → prix en promotion, qui ne devient pas le prix de référence.
        ->and($price->is_promo)->toBeTrue()
        ->and($beurre->fresh()->reference_price)->toBeNull();

    // Stock : 2 × 1 l de lait, 1 250 g de pommes de terre, 250 g de beurre ; pas la lessive
    $stock = StockItem::with('ingredient', 'unit')->get()->mapWithKeys(fn ($i) => [$i->ingredient->name => (float) $i->quantity.' '.$i->unit?->code]);
    expect($stock->all())->toEqual(['Lait demi-écrémé' => '2 l', 'Beurre' => '250 g', 'Pomme de terre' => '1250 g']);

    // Liste : le beurre est coché et rangé ; les œufs n'ont pas été achetés (24.6)
    expect($beurreItem->fresh()->is_checked)->toBeTrue()
        ->and($beurreItem->fresh()->stocked_at)->not->toBeNull()
        ->and($outcome['not_bought'])->toBe([$oeufs->id])
        ->and($outcome['off_list'])->toContain('Lait demi-écrémé')
        ->and($outcome)->toMatchArray(['prices' => 3, 'stocked' => 3, 'checked' => 1]);

    // Apprentissage : le ticket suivant reconnaît d'office
    expect(ReceiptLabelMapping::where('store_id', $this->cactus->id)->pluck('normalized_label')->sort()->values()->all())
        ->toBe(['BEURRE DOUX', 'LAIT DEMI ECR UHT', 'LESSIVE LIQUIDE', 'POMMES DE TERRE']);

    $second = app(ReceiptService::class)->read(($this->newReceipt)());
    expect($second->lines->firstWhere('label', 'LAIT DEMI ECR UHT 1L')->confidence)->toBe('learned')
        ->and($second->lines->firstWhere('label', 'LESSIVE LIQUIDE 2L')->confidence)->toBe('learned');

    // Validé : plus modifiable
    expect(fn () => app(ReceiptService::class)->validate($receipt->fresh()))->toThrow(InvalidArgumentException::class);
});

test('les articles non achetés peuvent être gardés pour la prochaine fois (24.6)', function () {
    ($this->mistral)();
    $list = ShoppingList::create(['name' => 'Semaine', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18']);
    app(ShoppingListManager::class)->addManual($list, 'Beurre');
    $oeufs = app(ShoppingListManager::class)->addManual($list, 'Œuf');

    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    app(ReceiptService::class)->validate($receipt);

    Livewire::test(Show::class, ['receipt' => $receipt])
        ->assertSee('1 article de la liste non acheté')
        ->call('resolveNotBought', true)
        ->assertDontSee('non acheté');

    expect(StandingItem::sole()->label)->toBe($oeufs->label)
        ->and($oeufs->fresh()->is_removed)->toBeTrue();
});

test('une dépense saisie à la main pour le même ticket est remplacée, pas doublée (R26)', function () {
    ($this->mistral)();
    $manual = app(\App\Services\Budget\BudgetTracker::class)->record([
        'amount' => 15.41, 'spent_on' => '2026-10-13', 'budget_category_id' => BudgetCategory::groceries()->id, 'store_id' => $this->cactus->id,
    ]);

    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    expect(app(ReceiptService::class)->existingExpense($receipt)?->id)->toBe($manual->id);

    $outcome = app(ReceiptService::class)->validate($receipt);

    expect(Expense::count())->toBe(1)
        ->and($manual->fresh()->receipt_id)->toBe($receipt->id)
        ->and($manual->fresh()->source)->toBe('receipt')
        ->and($manual->fresh()->splits)->toHaveCount(2)
        ->and($outcome['replaced_expense'])->toBeTrue();
});

test('l\'écran de relecture corrige une ligne en un geste et demande confirmation d\'un écart', function () {
    ($this->mistral)();
    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    $index = $receipt->lines->search(fn ($l) => $l->label === 'LAIT DEMI ECR UHT 1L');
    $lessive = $receipt->lines->search(fn ($l) => $l->label === 'LESSIVE LIQUIDE 2L');

    $page = Livewire::test(Show::class, ['receipt' => $receipt])
        ->assertSee('Le compte est bon')
        ->set("lines.{$index}.ingredient", 'Œuf')
        ->assertSet("lines.{$index}.confidence", 'manual')
        ->set("lines.{$lessive}.kind", 'article')
        ->set("lines.{$lessive}.kind", 'non_food')
        ->set("lines.{$index}.amount", '3,18')                     // la somme ne tombe plus juste
        ->assertSee('le ticket semble incomplet ou mal lu')
        ->call('validateReceipt')
        ->assertSet('gapWarning', true);

    expect($receipt->lines()->find($receipt->lines[$index]->id)->ingredient->name)->toBe('Œuf')
        ->and(Receipt::find($receipt->id)->status)->toBe(Receipt::REVIEW);

    $page->call('validateReceipt')->assertHasNoErrors()->assertSee('Ce que le ticket a rempli');

    // Écart accepté : il revient aux courses, le total fait foi
    expect((float) Expense::sole()->amount)->toBe(15.41)
        ->and(Expense::sole()->splits->sum('amount'))->toEqual(15.41);
});

test('sans service de lecture, le ticket se saisit à la main (principe 5)', function () {
    Settings::set('receipts.mistral_key', '');
    config(['bouffe.receipts.mistral_key' => null]);

    expect(app(OcrService::class)->status())->toMatchArray(['available' => false]);

    Livewire::test(Capture::class)
        ->assertSee('Aucune clé pour Mistral')
        ->call('manual')
        ->assertRedirect(route('receipts.show', Receipt::sole()));

    $receipt = Receipt::sole();
    expect($receipt->status)->toBe(Receipt::REVIEW)->and($receipt->purchased_on->toDateString())->toBe('2026-10-14');

    Livewire::test(Show::class, ['receipt' => $receipt])
        ->set('storeId', (string) $this->cactus->id)
        ->set('total', '23,90')
        ->call('validateReceipt')
        ->assertHasNoErrors();

    expect((float) Expense::sole()->amount)->toBe(23.9)->and(Expense::sole()->source)->toBe('receipt');
});

test('le plafond mensuel coupe la lecture automatique (24.7)', function () {
    ($this->mistral)();
    Settings::set('receipts.monthly_cap', 1);

    app(ReceiptService::class)->read(($this->newReceipt)());

    expect(app(OcrService::class)->usage())->toMatchArray(['count' => 1, 'pages' => 1, 'remaining' => 0])
        ->and(app(OcrService::class)->status()['reason'])->toContain('Plafond de 1 lectures')
        ->and(fn () => app(ReceiptService::class)->read(($this->newReceipt)()))->toThrow(ReadingFailed::class, 'Plafond');

    Http::assertSentCount(1);
});

test('la capture envoie les photos puis ouvre la relecture', function () {
    $this->travelBack();   // les envois temporaires de Livewire sont signés : pas de voyage dans le temps
    ($this->mistral)();

    Livewire::test(Capture::class)
        ->set('newPhoto', UploadedFile::fake()->image('haut.jpg', 600, 1500))
        ->set('newPhoto', UploadedFile::fake()->image('bas.jpg', 600, 900))
        ->assertCount('photos', 2)
        ->call('removePhoto', 1)
        ->assertCount('photos', 1)
        ->call('read')
        ->assertRedirect(route('receipts.show', Receipt::sole()));

    expect(Receipt::sole()->status)->toBe(Receipt::REVIEW);

    $this->get(route('receipts.index'))->assertOk()->assertSee('Cactus')->assertSee('À relire');
    $this->get(route('receipts.show', Receipt::sole()))->assertOk()->assertSee('LAIT DEMI ECR UHT 1L');
});

/* ================================================================ Conservation, accès (24.8) */

test('les photos sont servies aux seuls connectés, puis supprimées après la durée choisie', function () {
    $receipt = ($this->newReceipt)();

    $this->get(route('receipts.photo', [$receipt, 0]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->travelTo(Carbon::parse('2027-11-15 08:00'));
    expect(app(ReceiptService::class)->purgePhotos())->toBe(1);

    Storage::disk('local')->assertMissing($receipt->photo_paths[0]);
    expect($receipt->fresh()->photos_deleted_at)->not->toBeNull();
    $this->get(route('receipts.photo', [$receipt, 0]))->assertNotFound();

    auth()->logout();
    $this->get(route('receipts.photo', [$receipt, 0]))->assertRedirect(route('login'));
});

test('en consultation seule, on voit les tickets sans pouvoir en ajouter ni valider', function () {
    ($this->mistral)();
    $receipt = app(ReceiptService::class)->read(($this->newReceipt)());
    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

    $this->get(route('receipts.create'))->assertRedirect();
    $this->get(route('receipts.show', $receipt))->assertOk()->assertDontSee('Valider le ticket');
    Livewire::test(Show::class, ['receipt' => $receipt])->call('validateReceipt')->assertForbidden();
});

/* ================================================================ Paramètres et recette depuis une photo */

test('les clés d\'API sont chiffrées en base et jamais réaffichées', function () {
    Livewire::test(ReceiptSettings::class)
        ->assertSee('Enregistrée dans Bouffe')
        ->set('provider', 'azure')
        ->set('azureEndpoint', 'https://bouffe.cognitiveservices.azure.com')
        ->set('azureKey', 'secret-azure-123')
        ->set('monthlyCap', 30)
        ->set('keepMonths', 6)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('azureKey', '')
        ->assertDontSee('secret-azure-123');

    $stored = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'receipts.azure_key')->value('value');

    expect($stored)->not->toContain('secret-azure-123')
        ->and(Settings::secret('receipts.azure_key'))->toBe('secret-azure-123')
        ->and(Settings::int('receipts.monthly_cap'))->toBe(30)
        ->and(app(OcrService::class)->reader()->provider())->toBe('azure');

    Livewire::test(ReceiptSettings::class)->call('forgetKey', 'azure');
    expect(Settings::secret('receipts.azure_key'))->toBeNull();
});

test('une photo de recette est lue puis analysée comme un texte (C3)', function () {
    Http::fake(['api.mistral.ai/*' => Http::response([
        'pages' => [['index' => 0, 'markdown' => "# Crêpes de mamie\n\nPour 4 personnes\n\n## Ingrédients\n\n- 250 g de farine\n- 3 œufs\n- 50 cl de lait demi-écrémé\n\n## Préparation\n\nMélanger la farine et les œufs.\nAjouter le lait petit à petit."]],
        'model' => 'mistral-ocr-latest',
        'usage_info' => ['pages_processed' => 1],
    ])]);
    $this->travelBack();

    Livewire::test(Import::class)
        ->call('selectTab', 'photo')
        ->set('photo', UploadedFile::fake()->image('page.jpg', 1000, 1400))
        ->call('readPhoto')
        ->assertHasNoErrors()
        ->assertSet('reviewing', true)
        ->assertSet('form.title', 'Crêpes de mamie');

    Http::assertSent(fn ($r) => ! isset($r['document_annotation_format']));
    expect(OcrReading::sole())->toMatchArray(['purpose' => 'recipe', 'pages' => 1])
        ->and((float) OcrReading::sole()->cost_estimate)->toBe(0.004);
});

test('la commande d\'essai lit un vrai fichier sans rien garder', function () {
    ($this->mistral)();
    $file = UploadedFile::fake()->image('ticket.jpg', 600, 1600);
    $path = $file->getRealPath();

    $this->artisan('bouffe:ticket', ['fichiers' => [$path]])
        ->expectsOutputToContain('Le compte est bon')
        ->assertSuccessful();

    expect(Receipt::count())->toBe(0)->and(OcrReading::count())->toBe(1);
});

test('la page Tickets résume les lectures du mois', function () {
    Livewire::test(ReceiptsIndex::class)->assertSee('Aucun ticket pour l\'instant')->assertSee('Lectures ce mois-ci');
});
