<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeShareLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Partager une recette par lien (lot 31, 31.4) — idée 15.9 étendue aux recettes.
 *
 *  - lecture seule, pour quelqu'un qui n'a pas Bouffe ;
 *  - valable 30 jours, révocable à tout moment ;
 *  - la page montre la recette (photo principale, ingrédients, étapes), jamais les notes de
 *    cuisine, les prix, le foyer ni qui l'a écrite ;
 *  - seule l'empreinte du jeton est enregistrée : l'adresse n'est affichée qu'à sa création.
 */
class RecipeShares
{
    public const DAYS = 30;

    public const MAX_ACTIVE = 10;

    /** @return array{link: RecipeShareLink, url: string} */
    public function create(Recipe $recipe): array
    {
        if ($recipe->isForeign()) {
            throw new InvalidArgumentException('Seul le foyer de la recette peut la partager par lien.');
        }

        if ($this->active($recipe)->count() >= self::MAX_ACTIVE) {
            throw new InvalidArgumentException('Déjà '.self::MAX_ACTIVE.' liens actifs pour cette recette : révoquez-en un d\'abord.');
        }

        $token = Str::random(40);

        $link = RecipeShareLink::create([
            'recipe_id' => $recipe->id,
            'token_hash' => $this->hash($token),
            'expires_at' => Carbon::now()->addDays(self::DAYS),
            'created_by' => auth()->id(),
        ]);

        return ['link' => $link, 'url' => route('share.recipe', ['token' => $token])];
    }

    public function revoke(RecipeShareLink $link): void
    {
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => Carbon::now()]);
        }
    }

    /** @return Collection<int, RecipeShareLink> liens encore valables, du plus récent au plus ancien */
    public function active(Recipe $recipe): Collection
    {
        return $recipe->shareLinks()->whereNull('revoked_at')->where('expires_at', '>', Carbon::now())->get();
    }

    /** Le lien d'un jeton, s'il est encore valable et que la recette existe (non archivée). */
    public function find(string $token): ?RecipeShareLink
    {
        $link = RecipeShareLink::query()->where('token_hash', $this->hash($token))->with('recipe')->first();

        if (! $link || ! $link->isActive() || ! $link->recipe || $link->recipe->isArchived()) {
            return null;
        }

        return $link;
    }

    public function recordView(RecipeShareLink $link): void
    {
        // Une vue par minute au plus (rechargements, impression).
        if ($link->last_viewed_at === null || $link->last_viewed_at->lt(Carbon::now()->subMinute())) {
            $link->forceFill(['views' => $link->views + 1, 'last_viewed_at' => Carbon::now()])->saveQuietly();
        }
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
