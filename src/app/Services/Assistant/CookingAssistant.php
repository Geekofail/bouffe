<?php

namespace App\Services\Assistant;

/**
 * Service de langage derrière l'assistant culinaire (lot 33, document 08 §7.4) : Mistral Small
 * aujourd'hui ; un autre service pourrait être branché ici sans toucher aux écrans.
 * Les tests n'appellent jamais le réseau (réponses enregistrées, Http::fake).
 */
interface CookingAssistant
{
    public function label(): string;

    /** Clé présente. */
    public function configured(): bool;

    /**
     * Une demande. Avec $schema, la réponse est un JSON conforme au schéma (décodé dans `data`) ;
     * sans, c'est du texte (dans `text`).
     *
     * @param  array<string, mixed>|null  $schema
     *
     * @throws AssistantFailed
     */
    public function ask(string $system, string $prompt, ?array $schema, int $maxTokens): AssistantAnswer;
}
