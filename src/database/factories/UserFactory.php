<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Household;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Lot 24 : un compte de test appartient au premier foyer, avec le rôle demandé
     * (« full » d'avant les foyers = responsable du foyer ; « viewer » = consultation et courses).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->households()->exists()) {
                return;
            }

            $raw = $user->getAttributes()['role'] ?? null;
            $role = match ($raw) {
                'viewer' => UserRole::Viewer,
                'full' => UserRole::Owner,
                default => UserRole::tryFrom((string) $raw) ?? UserRole::Owner,
            };

            // Comme une vraie installation : le premier compte l'administre.
            if (! User::query()->where('is_admin', true)->exists()) {
                $user->forceFill(['is_admin' => true])->saveQuietly();
            }

            app(HouseholdManager::class)->attach(Household::query()->orderBy('id')->firstOrFail(), $user, $role);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
