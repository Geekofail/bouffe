<?php

namespace App\Services\System;

use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Jeton de l'adresse /taches/{jeton} (lot 25, 27.3) : sans lui, personne ne peut déclencher les tâches.
 * Gardé chiffré dans les réglages de l'installation (ou BOUFFE_TASKS_TOKEN dans .env).
 */
class TaskToken
{
    public function current(): ?string
    {
        return Settings::secret('tasks.token');
    }

    /** Le jeton, créé au premier besoin. */
    public function ensure(): string
    {
        return $this->current() ?? $this->regenerate();
    }

    /** Nouveau jeton : l'ancienne adresse cesse de fonctionner (à reporter dans le service externe). */
    public function regenerate(): string
    {
        $token = Str::random(40);
        Settings::set('tasks.token', $token);

        return $token;
    }

    public function matches(string $candidate): bool
    {
        $expected = $this->current();

        return $expected !== null && hash_equals(hash('sha256', $expected), hash('sha256', $candidate));
    }

    public function url(): string
    {
        return route('tasks.run', $this->ensure());
    }
}
