<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Support\NameNormalizer;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property int $servings
 * @property int|null $prep_minutes
 * @property int|null $cook_minutes
 * @property int|null $rest_minutes
 * @property Difficulty|null $difficulty
 * @property string|null $photo_path
 * @property string|null $source
 * @property string|null $notes
 * @property bool $is_favorite
 * @property bool $is_to_test
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property-read int|null $total_minutes
 */
#[Fillable([
    'title', 'description', 'servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty',
    'photo_path', 'source', 'notes', 'is_favorite', 'is_to_test', 'archived_at', 'created_by', 'updated_by',
    'visibility', 'origin_recipe_id', 'origin_household_id', 'origin_synced_at', 'origin_hash',
    'kid_friendly', 'equipment',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use \App\Models\Concerns\BelongsToHousehold, HasFactory;

    /** Segments d'URL réservés (routes /recettes/nouvelle…). */
    public const RESERVED_SLUGS = ['nouvelle', 'importer', 'exporter', 'collections'];

    /** Visibilité (26.1, Q36 : privée par défaut). */
    public const VISIBILITIES = [
        'private' => 'Privée (notre foyer)',
        'linked' => 'Foyers reliés',
        'instance' => 'Toute l\'installation',
    ];

    protected $attributes = [
        'servings' => 2,
        'is_favorite' => false,
        'is_to_test' => false,
        'kid_friendly' => false,
        'visibility' => 'private',
    ];

    protected function casts(): array
    {
        return [
            'servings' => 'integer',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'rest_minutes' => 'integer',
            'difficulty' => Difficulty::class,
            'is_favorite' => 'boolean',
            'is_to_test' => 'boolean',
            'kid_friendly' => 'boolean',
            'equipment' => 'array',               // lot 40 (40.3) : vide = d'après le texte des étapes
            'archived_at' => 'datetime',
            'origin_synced_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Recipe $recipe) {
            $recipe->search_title = NameNormalizer::normalize($recipe->title);

            // Ouverte aux proches à l'instant : le fil des proches l'annonce (26.10).
            if ($recipe->isDirty('visibility') && $recipe->visibility !== 'private' && in_array($recipe->getOriginal('visibility'), [null, 'private'], true)) {
                $recipe->shared_at = now();
            }

            if ($recipe->isDirty('title') || ! $recipe->slug) {
                $recipe->slug = static::uniqueSlug($recipe->title, $recipe->id);
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(str_replace(["'", '’'], ' ', $title)) ?: 'recette';
        $slug = $base;
        $i = 2;

        while (in_array($slug, self::RESERVED_SLUGS, true)
            || static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ----------------------------------------------------------------- Entre foyers (lot 26) */

    /** Recette d'un autre foyer que le foyer actif (partagée, ou planifiée telle quelle). */
    public function isForeign(): bool
    {
        $current = \App\Support\CurrentHousehold::id();

        return $this->household_id !== null && $current !== null && (int) $this->household_id !== $current;
    }

    /** Adresse : le slug chez soi, « proche-12 » pour la recette d'un autre foyer (les slugs sont propres à chaque foyer). */
    public function getRouteKey(): mixed
    {
        return $this->isForeign() ? 'proche-'.$this->id : $this->slug;
    }

    /**
     * « proche-12 » : recette d'un autre foyer, seulement si elle est lisible par le foyer actif
     * (partagée, sous-recette d'une recette partagée, ou déjà planifiée). Les pages de modification
     * (modifier, variantes) refusent d'elles-mêmes une recette d'un autre foyer.
     * Sinon : la recette du foyer, comme avant.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (preg_match('/^proche-(\d+)$/', (string) $value, $m)) {
            return app(\App\Services\Linked\SharedRecipes::class)->findReadable((int) $m[1]);
        }

        $own = parent::resolveRouteBinding($value, $field);

        if (! $own && $field === 'id' && ctype_digit((string) $value)) {
            return app(\App\Services\Linked\SharedRecipes::class)->findReadable((int) $value);
        }

        return $own;
    }

    /** Recette d'origine d'une copie (R31), lue sans filtre de foyer. @return BelongsTo<Recipe, $this> */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'origin_recipe_id')->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function originHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'origin_household_id');
    }

    /* ----------------------------------------------------------------- Relations */

    /** @return HasMany<RecipeIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<RecipeStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    /** Variantes de la recette : « version végétarienne », « sans lactose » (lot 19, 13.7). */
    /** @return HasMany<RecipeVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(RecipeVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Sous-recettes utilisées (pâte brisée, béchamel) — lot 20, 13.8. */
    /** @return HasMany<RecipeComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Recettes qui utilisent celle-ci comme sous-recette. */
    /** @return HasMany<RecipeComponent, $this> */
    public function usedIn(): HasMany
    {
        return $this->hasMany(RecipeComponent::class, 'component_recipe_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /** Collections du foyer où elle figure (31.1). @return BelongsToMany<RecipeCollection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(RecipeCollection::class, 'collection_recipe', 'recipe_id', 'collection_id')->withPivot('position')->orderBy('name');
    }

    /** Photos d'étapes et « notre version » (31.2). @return HasMany<RecipePhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(RecipePhoto::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<RecipeShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(RecipeShareLink::class)->latest('id');
    }

    /** @return HasMany<RecipeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(RecipeRevision::class)->latest('id');
    }

    /** @return HasMany<RecipeCookNote, $this> */
    public function cookNotes(): HasMany
    {
        return $this->hasMany(RecipeCookNote::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<RecipeRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /** @return HasMany<PlannedMeal, $this> */
    public function plannedMeals(): HasMany
    {
        return $this->hasMany(PlannedMeal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ----------------------------------------------------------------- Scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** Recherche dans le titre OU les ingrédients (insensible aux accents et au pluriel). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $normalized = NameNormalizer::normalize($term);

        if ($normalized === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($normalized) {
            $q->where('search_title', 'like', "%{$normalized}%")
                ->orWhereHas('ingredients.ingredient', fn (Builder $i) => $i->where('search_name', 'like', "%{$normalized}%"));
        });
    }

    /** Temps total en minutes (préparation + cuisson + repos), ou null si rien n'est renseigné. */
    public function scopeMaxTotalMinutes(Builder $query, int $minutes): Builder
    {
        return $query->whereRaw('(COALESCE(prep_minutes, 0) + COALESCE(cook_minutes, 0) + COALESCE(rest_minutes, 0)) <= ?', [$minutes])
            ->where(fn (Builder $q) => $q->whereNotNull('prep_minutes')->orWhereNotNull('cook_minutes'));
    }

    /* ----------------------------------------------------------------- Accesseurs */

    public function getTotalMinutesAttribute(): ?int
    {
        if ($this->prep_minutes === null && $this->cook_minutes === null && $this->rest_minutes === null) {
            return null;
        }

        return (int) $this->prep_minutes + (int) $this->cook_minutes + (int) $this->rest_minutes;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function photoUrl(string $size = 'thumb'): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return route('recipes.photo', ['recipe' => $this->id, 'size' => $size, 'v' => $this->updated_at?->timestamp]);
    }

    public function sourceIsUrl(): bool
    {
        return (bool) filter_var($this->source, FILTER_VALIDATE_URL);
    }
}
