<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Comptes du foyer.
 *
 * Mot de passe initial :
 *  - la valeur de BOUFFE_SEED_PASSWORD dans le fichier .env si elle est renseignée ;
 *  - sinon un mot de passe aléatoire, affiché UNE fois dans le terminal.
 *
 * Un compte déjà existant n'est jamais modifié (son mot de passe est conservé).
 * Pour changer un mot de passe ensuite : php artisan bouffe:user adresse@mail
 */
class UserSeeder extends Seeder
{
    public const USERS = [
        ['Pierre', 'ptripodi@free.fr'],
        ['Monique', 'monique.van@hotmail.com'],
    ];

    public function run(): void
    {
        foreach (self::USERS as [$name, $email]) {
            if (User::where('email', $email)->exists()) {
                $this->command?->line("  Compte déjà présent : {$name} ({$email}) — inchangé.");

                continue;
            }

            $password = (string) config('bouffe.seed_password') ?: Str::password(12, symbols: false);

            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_admin' => ! User::query()->exists()]);

            // Lot 24 : les comptes de départ sont responsables du premier foyer.
            app(\App\Services\Households\HouseholdManager::class)->attach(
                \App\Models\Household::query()->orderBy('id')->firstOrFail(), $user, \App\Enums\UserRole::Owner,
            );

            $this->command?->warn(config('bouffe.seed_password')
                ? "  Compte créé : {$name} ({$email}) — mot de passe : celui de BOUFFE_SEED_PASSWORD"
                : "  Compte créé : {$name} ({$email}) — mot de passe : {$password}");
        }
    }
}
