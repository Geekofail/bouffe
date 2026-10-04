<?php

namespace App\Livewire\Forms;

use App\Enums\Difficulty;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\QuantityParser;
use App\Services\RecipePhotoService;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Formulaire de création / modification d'une recette.
 *
 * Les lignes d'ingrédients et d'étapes sont des tableaux ; chaque ligne porte un `uid`
 * stable (wire:key, glisser-déposer). Les lignes entièrement vides sont ignorées.
 */
class RecipeForm extends Form
{
    public ?Recipe $recipe = null;

    public string $title = '';

    public string $description = '';

    public int|string $servings = 2;

    public int|string|null $prep_minutes = null;

    public int|string|null $cook_minutes = null;

    public int|string|null $rest_minutes = null;

    public ?string $difficulty = null;

    public string $source = '';

    public string $notes = '';

    public bool $is_favorite = false;

    /** Recette importée jamais encore cuisinée (13.13). */
    public bool $is_to_test = false;

    /** Facile à faire avec un enfant (lot 39, 39.4). */
    public bool $kid_friendly = false;

    /** Équipement demandé (lot 40, 40.3). @var list<string> */
    public array $equipment = [];

    /** false : deviné d'après les étapes (rien d'enregistré sur la recette). */
    public bool $equipmentSet = false;

    /** Visibilité pour les proches (26.1) : privée par défaut (Q36). */
    public string $visibility = 'private';

    /** @var list<int> */
    public array $tagIds = [];

    public bool $useGroups = false;

    /**
     * @var list<array{uid: string, name: string, quantity: string, unit_id: int|string|null,
     *                 preparation: string, group_name: string, is_optional: bool, new_aisle_id: int|string|null}>
     */
    public array $ingredients = [];

    /** @var list<array{uid: string, group_name: string, instruction: string}> */
    public array $steps = [];

    /**
     * Sous-recettes (13.8) : « 1 × Pâte brisée ».
     *
     * @var list<array{uid: string, recipe_id: int|string|null, quantity: string, note: string}>
     */
    public array $components = [];

    /** Photo à supprimer à l'enregistrement. */
    public bool $removePhoto = false;

    public function setRecipe(Recipe $recipe): void
    {
        $recipe->loadMissing(['ingredients.ingredient', 'steps', 'tags']);

        $this->recipe = $recipe;
        $this->fill($recipe->only(['title', 'servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'is_favorite', 'is_to_test', 'kid_friendly']));
        $this->equipmentSet = is_array($recipe->equipment);
        $this->equipment = app(\App\Services\Recipes\KitchenEquipment::class)->required($recipe);
        $this->visibility = (string) ($recipe->visibility ?: 'private');
        $this->description = (string) $recipe->description;
        $this->source = (string) $recipe->source;
        $this->notes = (string) $recipe->notes;
        $this->difficulty = $recipe->difficulty?->value;
        $this->tagIds = $recipe->tags->pluck('id')->all();

        $this->ingredients = $recipe->ingredients->map(fn ($line) => [
            'uid' => Str::random(8),
            'name' => $line->ingredient->name,
            'quantity' => $this->quantityToInput($line->quantity),
            'unit_id' => $line->unit_id,
            'preparation' => (string) $line->preparation,
            'group_name' => (string) $line->group_name,
            'is_optional' => $line->is_optional,
            'new_aisle_id' => null,
        ])->all();

        $this->steps = $recipe->steps->map(fn ($step) => [
            'uid' => Str::random(8),
            'group_name' => (string) $step->group_name,
            'instruction' => $step->instruction,
            'step_uid' => (string) $step->uid,   // lot 40 (40.2) : notes et photos suivent l'étape
            'adult_help' => $step->adult_help,   // lot 39 (39.4) : correction gardée d'une modification à l'autre
        ])->all();

        $this->useGroups = $recipe->ingredients->contains(fn ($line) => filled($line->group_name));

        $this->components = $recipe->components()->get()->map(fn ($component) => [
            'uid' => Str::random(8),
            'recipe_id' => $component->component_recipe_id,
            'quantity' => $this->quantityToInput($component->quantity),
            'note' => (string) $component->note,
        ])->all();

        $this->ensureEmptyRows();
    }

    public function newComponentRow(): array
    {
        return ['uid' => Str::random(8), 'recipe_id' => null, 'quantity' => '1', 'note' => ''];
    }

    /** @return list<array{recipe_id: int, quantity: float|null, note: string}> */
    private function filledComponentRows(): array
    {
        return array_filter($this->components, fn ($row) => (int) ($row['recipe_id'] ?? 0) > 0);
    }

    public function initNew(): void
    {
        $this->servings = config('bouffe.default_servings', 2);
        $this->ensureEmptyRows();
    }

    /* ------------------------------------------------------------ Lignes */

    public function newIngredientRow(string $group = ''): array
    {
        return ['uid' => Str::random(8), 'name' => '', 'quantity' => '', 'unit_id' => null,
            'preparation' => '', 'group_name' => $group, 'is_optional' => false, 'new_aisle_id' => null];
    }

    public function newStepRow(string $group = ''): array
    {
        return ['uid' => Str::random(8), 'group_name' => $group, 'instruction' => ''];
    }

    /** Toujours au moins une ligne vide pour pouvoir saisir. */
    public function ensureEmptyRows(): void
    {
        if ($this->ingredients === []) {
            $this->ingredients[] = $this->newIngredientRow();
        }

        if ($this->steps === []) {
            $this->steps[] = $this->newStepRow();
        }
    }

    public function moveRow(string $list, string $uid, int $position): void
    {
        $rows = collect($this->{$list});
        $row = $rows->firstWhere('uid', $uid);

        if (! $row) {
            return;
        }

        $rows = $rows->reject(fn ($r) => $r['uid'] === $uid)->values();
        $rows->splice(max(0, min($position, $rows->count())), 0, [$row]);

        $this->{$list} = $rows->all();
    }

    /**
     * Remplit le formulaire avec une ébauche d'import (lot 13) : rien n'est enregistré,
     * tout reste modifiable avant l'appel à save().
     *
     * @param  array<string, mixed>  $draft
     */
    public function fillFromDraft(array $draft): void
    {
        $this->title = (string) ($draft['title'] ?? '');
        $this->description = (string) ($draft['description'] ?? '');
        $this->servings = (int) ($draft['servings'] ?? config('bouffe.default_servings', 2));
        $this->prep_minutes = $draft['prep_minutes'] ?? null;
        $this->cook_minutes = $draft['cook_minutes'] ?? null;
        $this->rest_minutes = $draft['rest_minutes'] ?? null;
        $this->source = (string) ($draft['source'] ?? '');
        $this->tagIds = array_values(array_map('intval', $draft['tagIds'] ?? []));
        $this->is_to_test = true;

        $this->ingredients = array_map(fn (array $line) => $this->rowFromParsedLine($line), $draft['ingredients'] ?? []);
        $this->useGroups = collect($this->ingredients)->contains(fn ($row) => $row['group_name'] !== '');

        $this->steps = array_map(fn (array $step) => [
            'uid' => Str::random(8),
            'group_name' => (string) ($step['group'] ?? ''),
            'instruction' => (string) ($step['instruction'] ?? ''),
        ], $draft['steps'] ?? []);

        $this->ensureEmptyRows();
    }

    /**
     * Ligne de formulaire à partir d'une ligne analysée par IngredientLineParser.
     *
     * @param  array<string, mixed>  $line
     */
    public function rowFromParsedLine(array $line): array
    {
        $ingredient = $line['ingredient'] ?? null;
        // L'unité par défaut de l'ingrédient n'a de sens que s'il y a une quantité (« Poivre » n'est pas « 0 pincée »).
        $unitId = $line['unit']?->id ?? (($line['quantity'] ?? null) !== null ? $ingredient?->default_unit_id : null);

        return [
            'uid' => Str::random(8),
            'name' => (string) ($line['name'] ?? ''),
            'quantity' => $this->quantityToInput($line['quantity'] ?? null),
            'unit_id' => $unitId,
            'preparation' => (string) ($line['preparation'] ?? ''),
            'group_name' => (string) ($line['group'] ?? ''),
            'is_optional' => (bool) ($line['optional'] ?? false),
            'new_aisle_id' => $ingredient ? null : self::defaultAisleId(),
        ];
    }

    /* ------------------------------------------------------------ Validation */

    /** Lignes d'ingrédients réellement renseignées (au moins un nom ou une quantité). */
    private function filledIngredientRows(): array
    {
        return array_filter($this->ingredients, fn ($row) => trim($row['name']) !== '' || trim((string) $row['quantity']) !== '');
    }

    protected function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200', \App\Support\HouseholdRule::unique('recipes', 'title')->ignore($this->recipe?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'servings' => ['required', 'integer', 'min:1', 'max:50'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:2880'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:2880'],
            'rest_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'source' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_favorite' => ['boolean'],
            'is_to_test' => ['boolean'],
            'kid_friendly' => ['boolean'],
            'equipment' => ['array'],
            'equipment.*' => ['string', Rule::in(array_keys(\App\Services\Recipes\KitchenEquipment::ITEMS))],
            'visibility' => ['required', Rule::in(array_keys(Recipe::VISIBILITIES))],
            'steps.*.group_name' => ['nullable', 'string', 'max:80'],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', Rule::exists('tags', 'id')],
            'steps.*.instruction' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (array_keys($this->filledIngredientRows()) as $i) {
            $isNew = ! $this->findIngredient($this->ingredients[$i]['name']);

            $rules["ingredients.{$i}.name"] = ['required', 'string', 'max:150'];
            $rules["ingredients.{$i}.quantity"] = ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (app(QuantityParser::class)->tryParse($value) === false) {
                    $fail('Quantité invalide (ex. 200, 1,5, 1/2).');
                }
            }];
            $rules["ingredients.{$i}.unit_id"] = ['nullable', 'integer', Rule::exists('units', 'id')];
            $rules["ingredients.{$i}.preparation"] = ['nullable', 'string', 'max:150'];
            $rules["ingredients.{$i}.group_name"] = ['nullable', 'string', 'max:80'];
            $rules["ingredients.{$i}.new_aisle_id"] = $isNew
                ? ['required', 'integer', Rule::exists('aisles', 'id')]
                : ['nullable'];
        }

        foreach (array_keys($this->filledComponentRows()) as $i) {
            $rules["components.{$i}.recipe_id"] = ['required', 'integer', Rule::exists('recipes', 'id'), function (string $attribute, mixed $value, \Closure $fail) {
                $candidate = Recipe::find((int) $value);

                if ($candidate && $this->recipe && ($reason = app(\App\Services\Recipes\SubRecipes::class)->refusal($this->recipe, $candidate))) {
                    $fail($reason);
                }
            }];
            $rules["components.{$i}.quantity"] = ['required', 'string', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                $parsed = app(QuantityParser::class)->tryParse($value);

                if ($parsed === false || $parsed === null || $parsed <= 0 || $parsed > 20) {
                    $fail('Indiquez combien de fois la recette (ex. 1, 0,5, 1/2).');
                }
            }];
            $rules["components.{$i}.note"] = ['nullable', 'string', 'max:150'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'ingredients.*.name.required' => 'Indiquez l\'ingrédient.',
            'ingredients.*.new_aisle_id.required' => 'Choisissez le rayon de ce nouvel ingrédient.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'title' => 'titre',
            'servings' => 'nombre de portions',
            'prep_minutes' => 'temps de préparation',
            'cook_minutes' => 'temps de cuisson',
            'rest_minutes' => 'temps de repos',
            'difficulty' => 'difficulté',
            'steps.*.instruction' => 'étape',
        ];
    }

    /* ------------------------------------------------------------ Enregistrement */

    public function save(mixed $photo = null): Recipe
    {
        // Champs numériques vides → null
        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }
        $this->difficulty = $this->difficulty ?: null;

        $this->validate();

        $photos = app(RecipePhotoService::class);
        $parser = app(QuantityParser::class);
        $userId = auth()->id();

        return DB::transaction(function () use ($photo, $photos, $parser, $userId) {
            $recipe = $this->recipe ?? new Recipe(['created_by' => $userId]);

            $recipe->fill([
                'title' => trim($this->title),
                'description' => trim($this->description) ?: null,
                'servings' => (int) $this->servings,
                'prep_minutes' => $this->prep_minutes,
                'cook_minutes' => $this->cook_minutes,
                'rest_minutes' => $this->rest_minutes,
                'difficulty' => $this->difficulty,
                'source' => trim($this->source) ?: null,
                'notes' => trim($this->notes) ?: null,
                'is_favorite' => $this->is_favorite,
                'is_to_test' => $this->is_to_test,
                'kid_friendly' => $this->kid_friendly,
                // Lot 40 (40.3) : enregistré seulement si on l'a choisi ; sinon deviné d'après les étapes.
                'equipment' => $this->equipmentSet ? array_values(array_unique($this->equipment)) : null,
                'visibility' => $this->visibility,
                'updated_by' => $userId,
            ]);

            if ($this->removePhoto && $recipe->photo_path) {
                $photos->delete($recipe->photo_path);
                $recipe->photo_path = null;
            }

            if ($photo) {
                $recipe->photo_path = $photos->store($photo, $recipe->exists ? $recipe : null);
            }

            $recipe->save();

            // Ingrédients : on remplace toutes les lignes
            $recipe->ingredients()->delete();
            $order = 1;

            foreach ($this->filledIngredientRows() as $row) {
                $ingredient = $this->findIngredient($row['name']) ?? Ingredient::create([
                    'name' => Str::ucfirst(trim($row['name'])),
                    'aisle_id' => $row['new_aisle_id'],
                    'default_unit_id' => $row['unit_id'] ?: null,
                ]);

                $recipe->ingredients()->create([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $parser->parse($row['quantity']),
                    'unit_id' => $row['unit_id'] ?: null,
                    'preparation' => trim($row['preparation']) ?: null,
                    'group_name' => $this->useGroups ? (trim($row['group_name']) ?: null) : null,
                    'is_optional' => (bool) $row['is_optional'],
                    'sort_order' => $order++,
                ]);
            }

            // Étapes
            $recipe->steps()->delete();
            $position = 1;

            foreach ($this->steps as $step) {
                if (trim($step['instruction']) !== '') {
                    $recipe->steps()->create([
                        'position' => $position++,
                        'group_name' => trim((string) ($step['group_name'] ?? '')) ?: null,
                        'instruction' => trim($step['instruction']),
                        'uid' => ($step['step_uid'] ?? '') !== '' ? (string) $step['step_uid'] : null,
                        'adult_help' => isset($step['adult_help']) && $step['adult_help'] !== null && $step['adult_help'] !== '' ? (bool) $step['adult_help'] : null,
                    ]);
                }
            }

            // Lot 40 : les photos d'étapes reprennent le numéro de leur étape.
            app(\App\Services\Recipes\StepNotes::class)->syncPhotos($recipe);

            $recipe->tags()->sync($this->tagIds);

            app(\App\Services\Recipes\SubRecipes::class)->sync($recipe, array_values(array_map(fn (array $row) => [
                'recipe_id' => (int) $row['recipe_id'],
                'quantity' => $parser->parse($row['quantity']) ?? 1,
                'note' => $row['note'] ?? '',
            ], $this->filledComponentRows())));

            return $recipe;
        });
    }

    /* ------------------------------------------------------------ Outils */

    public function findIngredient(?string $name): ?Ingredient
    {
        $normalized = NameNormalizer::normalize($name);

        return $normalized === '' ? null : Ingredient::findByName($name);
    }

    private function quantityToInput(string|float|null $quantity): string
    {
        if ($quantity === null || $quantity === '') {
            return '';
        }

        if (is_float($quantity)) {
            return str_replace('.', ',', rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'));
        }

        return rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
    }

    /** Rayon proposé par défaut pour un nouvel ingrédient. */
    public static function defaultAisleId(): ?int
    {
        return Aisle::where('name', 'Divers')->value('id') ?? Aisle::query()->ordered()->value('id');
    }
}
