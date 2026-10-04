<?php

namespace App\Livewire\Recipes;

use App\Livewire\Forms\RecipeForm;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\RecipeAssistant;
use App\Services\Recipes\IngredientLineParser;
use App\Services\Recipes\RecipeArchive;
use App\Services\Recipes\RecipeImporter;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Import d'une recette (lot 13) : depuis une adresse (13.1), depuis du texte collé (13.2)
 * ou depuis un export JSON (13.10).
 *
 * Les deux premiers onglets produisent une « ébauche » relue à l'écran avant enregistrement :
 * Bouffe propose, Pierre corrige. Rien n'est écrit tant que « Enregistrer » n'est pas cliqué.
 */
class Import extends Component
{
    use WithFileUploads;

    #[Url(as: 'onglet', except: 'url')]
    public string $tab = 'url';

    public string $url = '';

    public string $text = '';

    #[Validate('required|file|mimes:json,txt|max:5120', as: 'fichier')]
    public $file = null;

    /* ---------------------------------------------------------------- Relecture */

    public RecipeForm $form;

    public bool $reviewing = false;

    /** Confiance de l'analyse par ligne d'ingrédient : high · medium · none. @var array<string, string> */
    public array $confidence = [];

    /** Nom d'origine de chaque ligne, avant correction : sert à mémoriser les alias. @var array<string, string> */
    public array $originalNames = [];

    public ?string $imageUrl = null;

    public bool $downloadImage = true;

    /** Recette déjà présente portant le même titre ou la même source. */
    public ?int $existingId = null;

    public string $existingTitle = '';

    public bool $ignoreExisting = false;

    /** Compte rendu d'un import JSON. @var array{created: list<string>, skipped: list<string>}|null */
    public ?array $report = null;

    /** Recette venue de « À trier » (lot 38) : marquée gardée une fois enregistrée. */
    #[\Livewire\Attributes\Locked]
    public ?int $inboxId = null;

    /** Brouillon proposé par l'assistant (lot 33) : d'où il vient, affiché en bandeau. */
    public string $assistantLabel = '';

    /**
     * Propositions de l'assistant pour compléter l'import (33.3), cochées une à une.
     *
     * @var array{prep_minutes: int|null, cook_minutes: int|null, rest_minutes: int|null, difficulty: string|null, tags: list<string>}|null
     */
    public ?array $suggestion = null;

    /** Propositions retenues : prep_minutes, cook_minutes, rest_minutes, difficulty, tag:<nom>. @var list<string> */
    public array $accepted = [];

    public function mount(RecipeImporter $importer): void
    {
        $this->form->initNew();

        // Brouillon laissé par l'assistant (recette transformée, idée) : relu ici comme un import.
        $pending = session()->pull('assistant.draft');

        if (is_array($pending)) {
            $this->startReview($importer->fromAssistant($pending));
            $this->assistantLabel = (string) ($pending['label'] ?? 'proposition');
        }

        // Lot 38 (38.3) : « Relire et garder » depuis « À trier ».
        $inboxId = request()->integer('a-trier');

        if ($inboxId && ($item = \App\Models\InboxItem::query()->open()->find($inboxId))) {
            try {
                $this->startReview(app(\App\Services\Recipes\RecipeInbox::class)->draft($item));
                $this->inboxId = $item->id;
                $this->url = (string) $item->url;
            } catch (InvalidArgumentException $e) {
                $this->addError('url', $e->getMessage());
            }
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['url', 'texte', 'photo', 'json'], true) ? $tab : 'url';
        $this->report = null;
        $this->resetErrorBag();
    }

    /* ---------------------------------------------------------------- 13.1 · adresse */

    public function fetch(RecipeImporter $importer): void
    {
        $this->validate(['url' => 'required|url:http,https'], [], ['url' => 'adresse']);

        try {
            $this->startReview($importer->fromUrl($this->url));
        } catch (InvalidArgumentException $e) {
            $this->addError('url', $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- 13.2 · texte collé */

    public function analyse(RecipeImporter $importer): void
    {
        $this->validate(['text' => 'required|string|min:20|max:20000'], [], ['text' => 'texte']);

        $this->startReview($importer->fromText($this->text));
    }

    /* ---------------------------------------------------------------- C3 · photo d'une recette (lot 23) */

    /** Page de livre ou fiche manuscrite, lue par le service des tickets. */
    public $photo = null;

    public function readPhoto(\App\Services\Receipts\OcrService $ocr, RecipeImporter $importer): void
    {
        $this->validate(
            ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288']],
            ['photo.mimes' => 'Une photo (JPEG, PNG) ou un PDF.'],
            ['photo' => 'photo'],
        );

        \App\Support\TimeLimit::atLeast(180);

        try {
            $text = trim($ocr->readRecipeText($this->photo));
        } catch (\App\Services\Receipts\ReadingFailed $e) {
            $this->addError('photo', $e->getMessage());

            return;
        }

        // Le texte lu reste modifiable dans « Coller du texte » si l'analyse ne convient pas.
        $this->text = mb_substr(preg_replace('/^#+\s*/m', '', $text) ?? $text, 0, 20000);
        $this->reset('photo');

        if (mb_strlen($this->text) < 20) {
            $this->tab = 'texte';
            $this->addError('text', 'Presque rien n\'a pu être lu sur cette photo : complétez le texte à la main.');

            return;
        }

        $this->tab = 'texte';
        $this->startReview($importer->fromText($this->text));
    }

    /* ---------------------------------------------------------------- 13.10 · export JSON */

    public function importJson(RecipeArchive $archive): void
    {
        $this->validateOnly('file');

        try {
            $this->report = $archive->import((string) file_get_contents($this->file->getRealPath()));
            $this->reset('file');
        } catch (InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- Relecture */

    private function startReview(array $draft): void
    {
        $this->form->fillFromDraft($draft);

        $this->confidence = collect($draft['ingredients'] ?? [])
            ->mapWithKeys(fn (array $line, int $i) => [$this->form->ingredients[$i]['uid'] ?? $i => $line['confidence'] ?? 'none'])
            ->all();

        $this->originalNames = collect($draft['ingredients'] ?? [])
            ->mapWithKeys(fn (array $line, int $i) => [$this->form->ingredients[$i]['uid'] ?? $i => (string) ($line['name'] ?? '')])
            ->all();

        $this->imageUrl = $draft['image'] ?? null;
        $this->downloadImage = (bool) $this->imageUrl;
        $this->existingId = $draft['existing']?->id;
        $this->existingTitle = (string) $draft['existing']?->title;
        $this->ignoreExisting = false;
        $this->assistantLabel = '';
        $this->suggestion = null;
        $this->accepted = [];
        $this->reviewing = true;
        $this->resetErrorBag();
    }

    /* ---------------------------------------------------------------- 33.3 · compléter avec l'assistant */

    /** Seuls le titre, les lignes d'ingrédients et les étapes partent chez l'assistant (R34). */
    public function complete(RecipeAssistant $assistant): void
    {
        $lines = collect($this->form->ingredients)
            ->filter(fn ($row) => trim($row['name']) !== '')
            ->map(function ($row) {
                $unit = $row['unit_id'] ? $this->units->firstWhere('id', (int) $row['unit_id'])?->label : null;

                return trim(implode(' ', array_filter([trim((string) $row['quantity']), $unit, trim($row['name'])])).($row['preparation'] ? ', '.$row['preparation'] : ''));
            })->values()->all();
        $steps = collect($this->form->steps)->pluck('instruction')->map(fn ($s) => trim((string) $s))->filter()->values()->all();

        try {
            $result = $assistant->complete($this->form->title, $lines, $steps, $this->tags->pluck('name')->all());
        } catch (AssistantFailed $e) {
            $this->addError('assistant', $e->getMessage());

            return;
        }

        // Ne propose que ce qui manque ou change : les temps déjà saisis ne sont pas écrasés d'office.
        $selected = $this->tags->whereIn('id', $this->form->tagIds)->pluck('name')->all();
        $result['tags'] = array_values(array_diff($result['tags'], $selected));

        $this->suggestion = $result;
        $this->accepted = collect(['prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty'])
            ->filter(fn ($key) => $result[$key] !== null && blank($this->form->{$key}))
            ->merge(array_map(fn ($name) => 'tag:'.$name, $result['tags']))
            ->values()->all();
        $this->resetErrorBag('assistant');
    }

    public function applySuggestion(): void
    {
        if (! $this->suggestion) {
            return;
        }

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty'] as $key) {
            if (in_array($key, $this->accepted, true) && $this->suggestion[$key] !== null) {
                $this->form->{$key} = $this->suggestion[$key];
            }
        }

        foreach ($this->suggestion['tags'] as $name) {
            $tag = $this->tags->firstWhere('name', $name);

            if ($tag && in_array('tag:'.$name, $this->accepted, true) && ! in_array($tag->id, $this->form->tagIds, true)) {
                $this->form->tagIds[] = $tag->id;
            }
        }

        $this->reset('suggestion', 'accepted');
    }

    public function dismissSuggestion(): void
    {
        $this->reset('suggestion', 'accepted');
    }

    #[Computed]
    public function assistantAvailable(): bool
    {
        return app(AssistantService::class)->available();
    }

    public function addIngredient(): void
    {
        $this->form->ingredients[] = $this->form->newIngredientRow();
    }

    public function removeIngredient(string $uid): void
    {
        $this->form->ingredients = array_values(array_filter($this->form->ingredients, fn ($row) => $row['uid'] !== $uid));
        unset($this->confidence[$uid]);
        $this->form->ensureEmptyRows();
    }

    public function removeStep(string $uid): void
    {
        $this->form->steps = array_values(array_filter($this->form->steps, fn ($row) => $row['uid'] !== $uid));
        $this->form->ensureEmptyRows();
    }

    public function addStep(): void
    {
        $this->form->steps[] = $this->form->newStepRow();
    }

    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^form\.ingredients\.(\d+)\.name$/', $property, $m)) {
            $i = (int) $m[1];
            $uid = $this->form->ingredients[$i]['uid'];
            $ingredient = $this->form->findIngredient((string) $value);

            $this->confidence[$uid] = $ingredient ? 'high' : 'none';
            $this->form->ingredients[$i]['new_aisle_id'] = $ingredient ? null : RecipeForm::defaultAisleId();

            if ($ingredient && empty($this->form->ingredients[$i]['unit_id']) && $ingredient->default_unit_id) {
                $this->form->ingredients[$i]['unit_id'] = $ingredient->default_unit_id;
            }
        }
    }

    public function restart(): void
    {
        $this->reset(['reviewing', 'confidence', 'originalNames', 'imageUrl', 'existingId', 'existingTitle', 'ignoreExisting', 'report', 'assistantLabel', 'suggestion', 'accepted']);
        $this->form->reset();
        $this->form->initNew();
    }

    public function save(RecipeImporter $importer): void
    {
        if ($this->existing && ! $this->ignoreExisting) {
            $this->addError('form.title', 'Une recette porte déjà ce titre : ouvrez-la, ou choisissez « Créer quand même ».');

            return;
        }

        $photo = $this->downloadImage ? $importer->downloadImage($this->imageUrl) : null;
        $recipe = $this->form->save($photo);
        $this->rememberAliases();

        if ($this->inboxId && ($item = \App\Models\InboxItem::query()->find($this->inboxId))) {
            app(\App\Services\Recipes\RecipeInbox::class)->keep($item, $recipe);
        }

        session()->flash('status', 'Recette importée. Vérifiez-la et ajustez ce qui manque.');
        $this->redirectRoute('recipes.show', ['recipe' => $recipe], navigate: true);
    }

    /**
     * Le nom trouvé sur le site devient un alias de l'ingrédient retenu (« ail rose de Lautrec » → Ail),
     * pour que le prochain import le reconnaisse tout seul (R22).
     */
    private function rememberAliases(): void
    {
        foreach ($this->form->ingredients as $row) {
            $original = trim($this->originalNames[$row['uid']] ?? '');
            $search = NameNormalizer::normalize($original);

            if ($original === '' || $search === '' || $search === NameNormalizer::normalize($row['name'])) {
                continue;
            }

            $ingredient = Ingredient::findByName($row['name']);

            if (! $ingredient
                || Ingredient::query()->where('search_name', $search)->exists()
                || IngredientAlias::query()->where('search_name', $search)->exists()) {
                continue;
            }

            IngredientAlias::create(['ingredient_id' => $ingredient->id, 'name' => $original]);
        }
    }

    /* ---------------------------------------------------------------- Données de la vue */

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get(['id', 'label']);
    }

    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function tags(): Collection
    {
        return \App\Models\Tag::query()->ordered()->get();
    }

    #[Computed]
    public function ingredientNames(): array
    {
        return Ingredient::query()->orderBy('name')->pluck('name')->all();
    }

    #[Computed]
    public function existing(): ?Recipe
    {
        return $this->existingId ? Recipe::find($this->existingId) : null;
    }

    /** Nombre de lignes dont l'ingrédient n'a pas été reconnu. */
    #[Computed]
    public function unknownCount(): int
    {
        return collect($this->form->ingredients)
            ->filter(fn ($row) => trim($row['name']) !== '' && ($this->confidence[$row['uid']] ?? 'none') === 'none')
            ->count();
    }

    public function toggleTag(int $tagId): void
    {
        $this->form->tagIds = in_array($tagId, $this->form->tagIds, true)
            ? array_values(array_diff($this->form->tagIds, [$tagId]))
            : [...$this->form->tagIds, $tagId];
    }

    /** Lignes collées en vrac dans la relecture (même service que la saisie rapide, 13.3). */
    public function parseLines(string $block, IngredientLineParser $parser): void
    {
        foreach (preg_split('/\r\n|\r|\n/u', $block) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parsed = $parser->parse($line);

            if ($parsed['name'] === '') {
                continue;
            }

            $row = $this->form->rowFromParsedLine($parsed);
            $this->form->ingredients[] = $row;
            $this->confidence[$row['uid']] = $parsed['confidence'];
        }
    }

    public function render()
    {
        return view('livewire.recipes.import')->title('Importer une recette');
    }
}
