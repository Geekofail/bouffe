<?php

namespace App\Services\Assistant;

/** Réponse de l'assistant, avec les jetons comptés par le service (33.5). */
final class AssistantAnswer
{
    /** @param  array<string, mixed>|null  $data */
    public function __construct(
        public readonly ?array $data,
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
