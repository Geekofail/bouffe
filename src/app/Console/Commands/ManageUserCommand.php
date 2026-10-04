<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Création ou mise à jour d'un compte utilisateur.
 *
 * L'inscription publique est volontairement absente (application privée, potentiellement
 * hébergée chez OVH plus tard) : les comptes se gèrent en ligne de commande.
 *
 *   php artisan bouffe:user                      → mode interactif
 *   php artisan bouffe:user pierre@exemple.lu    → crée le compte ou change son mot de passe
 *   php artisan bouffe:user pierre@exemple.lu --sans-2fa   → téléphone perdu et plus de code de secours (lot 25)
 */
class ManageUserCommand extends Command
{
    protected $signature = 'bouffe:user
                            {email? : Adresse e-mail du compte}
                            {--name= : Prénom affiché dans l\'application}
                            {--password= : Mot de passe (déconseillé : reste dans l\'historique du terminal)}
                            {--role= : Rôle dans le foyer : owner (responsable), full (tout faire) ou viewer (consultation et courses)}
                            {--foyer= : Numéro du foyer pour un nouveau compte (par défaut le premier)}
                            {--admin : Administrateur de l\'installation}
                            {--sans-2fa : Désactive la double authentification du compte (téléphone perdu)}';

    protected $description = 'Crée un compte Bouffe ou modifie le nom / mot de passe d\'un compte existant';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text(
            label: 'Adresse e-mail',
            required: true,
            validate: ['email' => 'email'],
        );

        $user = User::firstWhere('email', $email);

        // Lot 25 (27.4) : dernier recours quand le téléphone et les codes de secours sont perdus.
        if ($this->option('sans-2fa')) {
            if (! $user) {
                $this->components->error("Aucun compte {$email}.");

                return self::FAILURE;
            }

            app(\App\Services\Security\TwoFactor::class)->disable($user);
            $this->components->info("Double authentification désactivée pour {$email}. Elle sera redemandée à la connexion si elle est obligatoire pour ce compte.");

            return self::SUCCESS;
        }

        if ($user) {
            $this->components->info("Le compte {$email} existe : mise à jour.");
        }

        $name = $this->option('name') ?? ($this->input->isInteractive()
            ? text(label: 'Prénom', default: $user->name ?? '', required: true)
            : $user?->name);

        $password = $this->option('password') ?? ($this->input->isInteractive()
            ? password(label: 'Mot de passe (8 caractères minimum)', required: true, validate: ['password' => 'min:8'])
            : null);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            ['email' => 'required|email|max:191', 'name' => 'required|string|max:100', 'password' => 'required|string|min:8'],
            [],
            ['email' => 'adresse e-mail', 'name' => 'prénom', 'password' => 'mot de passe'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $role = \App\Enums\UserRole::tryFrom((string) $this->option('role'));

        $user ??= new User(['email' => $email]);
        $user->name = $name;
        $user->password = $password; // haché automatiquement (cast "hashed")

        if ($this->option('admin')) {
            $user->is_admin = true;
        }

        $wasNew = ! $user->exists;
        $user->save();

        // Lot 24 : un nouveau compte rejoint un foyer ; le rôle s'applique dans ses foyers.
        $manager = app(\App\Services\Households\HouseholdManager::class);

        if (! $user->households()->exists()) {
            $household = \App\Models\Household::query()->when($this->option('foyer'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->first();

            if (! $household) {
                $this->components->error('Foyer introuvable.');

                return self::FAILURE;
            }

            $manager->attach($household, $user, $role ?? \App\Enums\UserRole::Owner);
        } elseif ($role) {
            foreach ($user->households as $household) {
                $user->setRoleIn($role, $household->id);
            }
        }

        $this->components->info($wasNew
            ? "Compte créé pour {$name} ({$email})."
            : "Compte de {$name} ({$email}) mis à jour.");

        return self::SUCCESS;
    }
}
