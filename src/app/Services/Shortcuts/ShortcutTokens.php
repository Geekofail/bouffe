<?php

namespace App\Services\Shortcuts;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Support\Str;

/**
 * Jeton personnel des raccourcis (lot 38, 38.1, règle R39).
 *
 * Un jeton par personne et par foyer, créé à la demande et montré **une seule fois** : seule son
 * empreinte (SHA-256) est gardée. En créer un nouveau révoque l'ancien ; « Révoquer » coupe tous les
 * raccourcis de la personne. Il ne permet que les gestes de ShortcutActions.
 */
class ShortcutTokens
{
    /** Préfixe lisible : on reconnaît un jeton Bouffe dans le raccourci. */
    public const PREFIX = 'bouffe_';

    /** @return string le jeton en clair, à recopier tout de suite */
    public function create(User $user): string
    {
        $this->revoke($user);
        $token = self::PREFIX.Str::random(40);

        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'hint' => substr($token, -4),
        ]);

        return $token;
    }

    public function revoke(User $user): int
    {
        return ApiToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function active(User $user): ?ApiToken
    {
        return ApiToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->latest('id')->first();
    }

    /** Jeton valable, tous foyers confondus (l'appel n'a pas encore de foyer). */
    public function find(?string $token): ?ApiToken
    {
        $token = trim((string) $token);

        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 100) {
            return null;
        }

        return ApiToken::withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class)
            ->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')
            ->with('user')->first();
    }

    public function touch(ApiToken $token): void
    {
        $token->forceFill(['last_used_at' => now(), 'uses' => $token->uses + 1])->saveQuietly();
    }

    /** Pour les tests et la page : le foyer du jeton est-il toujours celui de la personne ? */
    public function belongs(ApiToken $token): bool
    {
        return $token->user !== null
            && $token->user->households()->whereKey($token->household_id)->exists()
            && CurrentHousehold::forUser($token->user) !== null;
    }
}
