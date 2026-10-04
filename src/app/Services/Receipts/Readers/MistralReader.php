<?php

namespace App\Services\Receipts\Readers;

use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptReading;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mistral — Document AI (§7.3) : OCR + extraction dans un schéma JSON fourni par Bouffe.
 *
 * POST https://api.mistral.ai/v1/ocr avec `document_annotation_format` (json_schema) : la réponse
 * contient `document_annotation`, une chaîne JSON conforme au schéma, et `usage_info.pages_processed`.
 * Limite du service : 8 pages par document annoté.
 */
class MistralReader implements ReceiptReader
{
    public const ENDPOINT = 'https://api.mistral.ai/v1/ocr';

    public const MAX_PAGES = 8;

    public function provider(): string
    {
        return 'mistral';
    }

    public function label(): string
    {
        return 'Mistral (Document AI)';
    }

    public function configured(): bool
    {
        return Settings::secret('receipts.mistral_key') !== null;
    }

    public function read(string $path, string $mime): ReceiptReading
    {
        $response = $this->call([
            'model' => (string) config('bouffe.receipts.mistral_model', 'mistral-ocr-latest'),
            'document' => $this->document($path, $mime),
            'document_annotation_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'ticket_de_caisse', 'schema' => self::schema(), 'strict' => true],
            ],
            'document_annotation_prompt' => self::PROMPT,
            'include_image_base64' => false,
        ]);

        $annotation = $response['document_annotation'] ?? null;
        $data = is_string($annotation) ? json_decode($annotation, true) : (is_array($annotation) ? $annotation : null);

        if (! is_array($data)) {
            throw new ReadingFailed('Le service n\'a pas su lire ce ticket. Essayez une photo plus nette, ou saisissez-le à la main.');
        }

        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line) || trim((string) ($line['label'] ?? '')) === '') {
                continue;
            }

            $lines[] = [
                'label' => trim((string) $line['label']),
                'name' => self::string($line['readable_name'] ?? null),
                'quantity' => self::number($line['quantity'] ?? null),
                'unit' => self::string($line['unit'] ?? null),
                'unit_price' => self::number($line['unit_price'] ?? null),
                'amount' => self::number($line['amount'] ?? null),
                'kind' => (string) ($line['kind'] ?? 'article'),
            ];
        }

        return new ReceiptReading(
            provider: 'mistral',
            storeName: self::string($data['store_name'] ?? null),
            date: self::string($data['date'] ?? null),
            total: self::number($data['total'] ?? null),
            lines: $lines,
            pages: max(1, (int) ($response['usage_info']['pages_processed'] ?? count((array) ($response['pages'] ?? [])))),
            raw: [
                'annotation' => $data,
                'text' => collect((array) ($response['pages'] ?? []))->pluck('markdown')->filter()->implode("\n\n"),
                'model' => $response['model'] ?? null,
            ],
        );
    }

    public function readText(string $path, string $mime): array
    {
        $response = $this->call([
            'model' => (string) config('bouffe.receipts.mistral_model', 'mistral-ocr-latest'),
            'document' => $this->document($path, $mime),
            'include_image_base64' => false,
        ]);

        $pages = (array) ($response['pages'] ?? []);

        return [
            'text' => collect($pages)->pluck('markdown')->filter()->implode("\n\n"),
            'pages' => max(1, (int) ($response['usage_info']['pages_processed'] ?? count($pages))),
        ];
    }

    /** @return array<string, mixed> */
    private function call(array $payload): array
    {
        $key = Settings::secret('receipts.mistral_key') ?? throw new ReadingFailed('Aucune clé Mistral n\'est enregistrée (Paramètres → Tickets de caisse).');

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('bouffe.receipts.timeout', 60))
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            throw new ReadingFailed('Le service de lecture ne répond pas (réseau ?). Le ticket reste enregistré : réessayez plus tard ou saisissez-le à la main.');
        }

        $this->guard($response);

        return (array) $response->json();
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ReadingFailed(match (true) {
            $response->status() === 401 => 'La clé Mistral est refusée : vérifiez-la dans Paramètres → Tickets de caisse.',
            $response->status() === 429 => 'Trop de lectures d\'un coup chez Mistral : réessayez dans une minute.',
            $response->status() === 413 => 'Le fichier est trop lourd pour le service.',
            $response->status() >= 500 => 'Le service Mistral est indisponible pour le moment.',
            default => 'Lecture refusée par Mistral (erreur '.$response->status().').',
        });
    }

    /** @return array{type: string, image_url?: string, document_url?: string} */
    private function document(string $path, string $mime): array
    {
        $data = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));

        return $mime === 'application/pdf'
            ? ['type' => 'document_url', 'document_url' => $data]
            : ['type' => 'image_url', 'image_url' => $data];
    }

    private static function string(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+(?:[.,]\d+)?$/', trim($value))) {
            return (float) str_replace(',', '.', trim($value));
        }

        return null;
    }

    private const PROMPT = <<<'TXT'
        Ticket de caisse d'un magasin (Luxembourg, France, Belgique, Allemagne). Recopie chaque ligne
        d'article dans l'ordre, telle qu'imprimée (label), avec un nom lisible en français si tu le
        comprends (readable_name, ex. « LAIT DEMI ECR UHT 1L » → « lait demi-écrémé »). quantity et
        unit : « 2 x 1,29 » → 2 et piece ; « 0,532 kg x 3,99 €/kg » → 0.532 et kg. amount : montant de
        la ligne en euros, négatif pour une remise, un bon d'achat ou un retour de consigne. kind :
        article, discount (remise, réduction, promo), deposit (consigne, Pfand, vidange), voucher (bon
        d'achat, coupon), bag (sac), tax (lignes de TVA), payment (total, moyen de paiement, rendu),
        other. date au format AAAA-MM-JJ, total = montant payé. Ne recopie jamais un numéro de carte
        bancaire, même partiel.
        TXT;

    /** Schéma JSON demandé au service (mode strict : toutes les propriétés sont requises, null permis). */
    public static function schema(): array
    {
        $nullable = fn (string $type, string $description) => ['type' => [$type, 'null'], 'description' => $description];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['store_name', 'date', 'total', 'currency', 'lines'],
            'properties' => [
                'store_name' => $nullable('string', 'Nom du magasin'),
                'date' => $nullable('string', 'Date d\'achat, AAAA-MM-JJ'),
                'total' => $nullable('number', 'Total payé'),
                'currency' => $nullable('string', 'Devise, ex. EUR'),
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'readable_name', 'quantity', 'unit', 'unit_price', 'amount', 'kind'],
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Libellé tel qu\'imprimé'],
                            'readable_name' => $nullable('string', 'Nom lisible en français'),
                            'quantity' => $nullable('number', 'Nombre d\'articles ou poids'),
                            'unit' => $nullable('string', 'piece, kg ou l'),
                            'unit_price' => $nullable('number', 'Prix unitaire ou au kilo'),
                            'amount' => $nullable('number', 'Montant de la ligne, négatif pour une remise'),
                            'kind' => ['type' => 'string', 'enum' => ['article', 'discount', 'deposit', 'voucher', 'bag', 'tax', 'payment', 'other']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
