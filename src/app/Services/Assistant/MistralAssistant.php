<?php

namespace App\Services\Assistant;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mistral — conversation (POST /v1/chat/completions), modèle Mistral Small par défaut (Q46).
 *
 * Avec un schéma, `response_format` en mode json_schema strict : la réponse est un JSON conforme.
 * La clé est celle des tickets de caisse (Paramètres → Tickets de caisse), chiffrée en base.
 */
class MistralAssistant implements CookingAssistant
{
    public const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';

    public function label(): string
    {
        return 'Mistral Small';
    }

    public function configured(): bool
    {
        return Settings::secret('receipts.mistral_key') !== null;
    }

    public function ask(string $system, string $prompt, ?array $schema, int $maxTokens): AssistantAnswer
    {
        $key = Settings::secret('receipts.mistral_key') ?? throw new AssistantFailed('Aucune clé Mistral n\'est enregistrée.');

        $payload = [
            'model' => (string) config('bouffe.assistant.model', 'mistral-small-latest'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => $maxTokens,
        ];

        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'reponse', 'schema' => $schema, 'strict' => true],
            ];
        }

        try {
            $response = Http::withToken($key)->acceptJson()
                ->timeout((int) config('bouffe.assistant.timeout', 45))
                ->post((string) config('bouffe.assistant.endpoint', self::ENDPOINT), $payload);
        } catch (ConnectionException) {
            throw new AssistantFailed('L\'assistant ne répond pas (réseau ?). Réessayez plus tard.');
        }

        if (! $response->successful()) {
            throw new AssistantFailed(match (true) {
                $response->status() === 401 => 'La clé Mistral est refusée : vérifiez-la dans Paramètres → Tickets de caisse.',
                $response->status() === 429 => 'Trop de demandes d\'un coup chez Mistral : réessayez dans une minute.',
                $response->status() >= 500 => 'Le service Mistral est indisponible pour le moment.',
                default => 'Demande refusée par Mistral (erreur '.$response->status().').',
            });
        }

        $content = $response->json('choices.0.message.content');
        $text = is_string($content) ? trim($content) : '';
        $data = null;

        if ($schema !== null) {
            $data = json_decode($text, true);

            if (! is_array($data)) {
                throw new AssistantFailed('La réponse de l\'assistant est incomplète. Réessayez.');
            }
        }

        return new AssistantAnswer(
            data: $data,
            text: $text,
            tokensIn: (int) $response->json('usage.prompt_tokens', 0),
            tokensOut: (int) $response->json('usage.completion_tokens', 0),
        );
    }
}
