<?php

namespace App\Services\Receipts\Readers;

use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptReading;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Azure Document Intelligence (§7.3) : modèle prêt à l'emploi « prebuilt-receipt ».
 *
 * POST {endpoint}/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=…
 * avec { base64Source } → 202 et un en-tête Operation-Location, interrogé jusqu'à « succeeded ».
 * Champs : MerchantName, TransactionDate, Total, Items[] (Description, Quantity, QuantityUnit,
 * Price, TotalPrice). Le genre de ligne (remise, consigne…) est déduit ensuite par Bouffe (R27).
 */
class AzureReader implements ReceiptReader
{
    /** Délai entre deux interrogations du résultat, en millisecondes (0 dans les tests). */
    public static int $pollDelayMs = 1000;

    public function provider(): string
    {
        return 'azure';
    }

    public function label(): string
    {
        return 'Azure Document Intelligence';
    }

    public function configured(): bool
    {
        return $this->endpoint() !== null && Settings::secret('receipts.azure_key') !== null;
    }

    public function read(string $path, string $mime): ReceiptReading
    {
        $result = $this->analyze('prebuilt-receipt', $path);
        $fields = (array) ($result['documents'][0]['fields'] ?? []);
        $lines = [];

        foreach ((array) ($fields['Items']['valueArray'] ?? []) as $item) {
            $object = (array) ($item['valueObject'] ?? []);
            $label = trim((string) (self::value($object['Description'] ?? null) ?? ''));

            if ($label === '') {
                continue;
            }

            $unit = mb_strtolower(trim((string) (self::value($object['QuantityUnit'] ?? null) ?? '')));

            $lines[] = [
                'label' => $label,
                'name' => null,
                'quantity' => self::float(self::value($object['Quantity'] ?? null)),
                'unit' => match (true) {
                    in_array($unit, ['kg', 'kilo', 'kilogramme'], true) => 'kg',
                    in_array($unit, ['l', 'litre'], true) => 'l',
                    default => null,
                },
                'unit_price' => self::float(self::value($object['Price'] ?? null)),
                'amount' => self::float(self::value($object['TotalPrice'] ?? null)),
                'kind' => 'article',
            ];
        }

        return new ReceiptReading(
            provider: 'azure',
            storeName: self::text(self::value($fields['MerchantName'] ?? null)),
            date: self::text(self::value($fields['TransactionDate'] ?? null)),
            total: self::float(self::value($fields['Total'] ?? null)),
            lines: $lines,
            pages: max(1, count((array) ($result['pages'] ?? []))),
            raw: ['fields' => $fields, 'text' => (string) ($result['content'] ?? '')],
        );
    }

    public function readText(string $path, string $mime): array
    {
        $result = $this->analyze('prebuilt-read', $path);

        return ['text' => (string) ($result['content'] ?? ''), 'pages' => max(1, count((array) ($result['pages'] ?? [])))];
    }

    /** @return array<string, mixed> analyzeResult */
    private function analyze(string $model, string $path): array
    {
        $endpoint = $this->endpoint() ?? throw new ReadingFailed('Le point de terminaison Azure n\'est pas renseigné (Paramètres → Tickets de caisse).');
        $key = Settings::secret('receipts.azure_key') ?? throw new ReadingFailed('Aucune clé Azure n\'est enregistrée (Paramètres → Tickets de caisse).');
        $version = (string) config('bouffe.receipts.azure_api_version', '2024-11-30');
        $timeout = (int) config('bouffe.receipts.timeout', 60);

        try {
            $response = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])
                ->acceptJson()->timeout($timeout)
                ->post("{$endpoint}/documentintelligence/documentModels/{$model}:analyze?api-version={$version}", [
                    'base64Source' => base64_encode((string) file_get_contents($path)),
                ]);

            $this->guard($response);
            $location = $response->header('Operation-Location') ?: throw new ReadingFailed('Réponse inattendue du service Azure.');
            $deadline = microtime(true) + $timeout;

            do {
                if (self::$pollDelayMs > 0) {
                    usleep(self::$pollDelayMs * 1000);
                }

                $poll = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])->acceptJson()->timeout($timeout)->get($location);
                $this->guard($poll);
                $status = (string) $poll->json('status');

                if ($status === 'succeeded') {
                    return (array) $poll->json('analyzeResult');
                }

                if ($status === 'failed') {
                    throw new ReadingFailed('Le service Azure n\'a pas su lire ce document.');
                }
            } while (microtime(true) < $deadline);
        } catch (ConnectionException) {
            throw new ReadingFailed('Le service de lecture ne répond pas (réseau ?). Le ticket reste enregistré : réessayez plus tard ou saisissez-le à la main.');
        }

        throw new ReadingFailed('La lecture a pris trop de temps : réessayez.');
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ReadingFailed(match (true) {
            in_array($response->status(), [401, 403], true) => 'La clé Azure est refusée : vérifiez-la dans Paramètres → Tickets de caisse.',
            $response->status() === 404 => 'Point de terminaison Azure introuvable : vérifiez l\'adresse dans Paramètres → Tickets de caisse.',
            $response->status() === 429 => 'Quota Azure atteint (niveau gratuit : 500 pages par mois). Saisissez le ticket à la main.',
            $response->status() >= 500 => 'Le service Azure est indisponible pour le moment.',
            default => 'Lecture refusée par Azure (erreur '.$response->status().').',
        });
    }

    private function endpoint(): ?string
    {
        $endpoint = trim((string) (Settings::get('receipts.azure_endpoint') ?? ''));

        return $endpoint === '' ? null : rtrim($endpoint, '/');
    }

    /** Valeur typée d'un champ (valueString, valueDate, valueNumber, valueCurrency…), sinon son texte. */
    private static function value(mixed $field): mixed
    {
        if (! is_array($field)) {
            return null;
        }

        foreach (['valueString', 'valueDate', 'valueNumber', 'valueInteger'] as $key) {
            if (isset($field[$key])) {
                return $field[$key];
            }
        }

        if (isset($field['valueCurrency']['amount'])) {
            return $field['valueCurrency']['amount'];
        }

        return $field['content'] ?? null;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private static function float(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/-?\d+(?:[.,]\d+)?/', str_replace(' ', '', $value), $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }

        return null;
    }
}
