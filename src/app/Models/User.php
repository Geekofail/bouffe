<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'preferences', 'current_household_id', 'is_admin', 'share_restrictions'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'calendar_token', 'calendar_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',               // lot 25 (27.4)
            'two_factor_recovery_codes' => 'array',           // condensés SHA-256 des codes de secours
            'two_factor_confirmed_at' => 'datetime',
            'share_restrictions' => 'boolean',                // contraintes montrées aux foyers reliés (26.6)
            'calendar_token' => 'encrypted',                  // agenda du planning (C4)
        ];
    }

    /* ================================================================ Sécurité (lot 25) */

    /** Double authentification active (27.4). */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret;
    }

    /** Responsable d'au moins un foyer (la double authentification lui est demandée, Q41). */
    public function ownsAnyHousehold(): bool
    {
        return \Illuminate\Support\Facades\DB::table('household_user')->where('user_id', $this->id)->where('role', UserRole::Owner->value)->exists();
    }

    /** Journal des connexions (27.5). @return HasMany<LoginEvent, $this> */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    /** Lien de réinitialisation du mot de passe (27.6) : e-mail en français. */
    public function sendPasswordResetNotification($token): void
    {
        \Illuminate\Support\Facades\Mail::to($this)->send(\App\Mail\Notice::passwordReset($this, $token));
    }

    /**
     * Contraintes alimentaires du membre, dans le foyer actif (18.1) : celles de **sa personne**
     * (lot 39). @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<PersonRestriction, HouseholdPerson, $this>
     */
    public function restrictions(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(PersonRestriction::class, HouseholdPerson::class, 'user_id', 'person_id');
    }

    /** Sa personne dans le foyer actif (lot 39), s'il y en a une. @return \Illuminate\Database\Eloquent\Relations\HasOne<HouseholdPerson, $this> */
    public function person(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(HouseholdPerson::class);
    }

    /** Réactions laissées sur des repas (18.4). @return HasMany<MealReaction, $this> */
    public function mealReactions(): HasMany
    {
        return $this->hasMany(MealReaction::class);
    }

    /* ================================================================ Foyers (lot 24) */

    /** @return BelongsToMany<Household, $this> */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)->using(HouseholdMember::class)->withPivot('id', 'role', 'last_active_at')->withTimestamps()->orderBy('households.name');
    }

    /** Rôle dans un foyer (le foyer actif par défaut) ; null s'il n'en est pas membre. */
    public function roleIn(?int $householdId = null): ?UserRole
    {
        $householdId ??= \App\Support\CurrentHousehold::id();
        $cache = $this->roleCache ??= [];

        if (! array_key_exists((int) $householdId, $cache)) {
            $role = $householdId ? \Illuminate\Support\Facades\DB::table('household_user')->where('household_id', $householdId)->where('user_id', $this->id)->value('role') : null;
            $this->roleCache[(int) $householdId] = $role ? UserRole::tryFrom($role) : null;
        }

        return $this->roleCache[(int) $householdId];
    }

    /** Mémoire du rôle par foyer, le temps de la requête. @var array<int, UserRole|null>|null */
    private ?array $roleCache = null;

    /**
     * Rôle dans le foyer actif (25.3). L'ancienne colonne `users.role` ne sert plus qu'à la création
     * (fabriques de test, commande bouffe:user) : elle donne le rôle de départ dans le foyer.
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ($this->exists ? $this->roleIn() : null) ?? UserRole::tryFrom((string) $value),
            set: fn ($value) => $value instanceof UserRole ? $value->value : $value,
        );
    }

    /** Change le rôle dans le foyer actif. */
    public function setRoleIn(UserRole $role, ?int $householdId = null): void
    {
        $householdId ??= \App\Support\CurrentHousehold::id();
        \Illuminate\Support\Facades\DB::table('household_user')->where('household_id', $householdId)->where('user_id', $this->id)
            ->update(['role' => $role->value, 'updated_at' => now()]);
        $this->roleCache = null;
    }

    /** Peut-il modifier les données (recettes, planning, stock, réglages) ? (18.5) */
    public function canEdit(): bool
    {
        return (bool) $this->roleIn()?->canEdit();
    }

    /** Responsable du foyer actif : inviter, retirer, exporter, supprimer (25.3). */
    public function managesHousehold(): bool
    {
        return (bool) $this->roleIn()?->managesHousehold();
    }

    /** Administrateur de l'installation (25.7). */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Membres d'un foyer (le foyer actif par défaut). */
    public function scopeInHousehold(Builder $query, ?int $householdId = null): Builder
    {
        $householdId ??= \App\Support\CurrentHousehold::id();

        return $query->whereIn('users.id', \Illuminate\Support\Facades\DB::table('household_user')->where('household_id', $householdId ?? 0)->select('user_id'));
    }

    public function forgetRoles(): void
    {
        $this->roleCache = null;
    }

    /** Préférence d'affichage (barre du bas, accueil…), avec valeur par défaut. */
    /** Téléphones et navigateurs abonnés aux notifications (lot 20, 19.2). @return \Illuminate\Database\Eloquent\Relations\HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences ?? [], $key, $default);
    }

    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);
        $this->preferences = $preferences;
        $this->save();
    }
}
