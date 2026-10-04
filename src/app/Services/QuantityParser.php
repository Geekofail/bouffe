<?php

namespace App\Services;

/**
 * Transforme une quantité saisie par l'utilisateur en nombre.
 *
 * Formats acceptés : « 2 », « 1,5 », « 1.5 », « 1/2 », « 1 1/2 », « ½ », « 1½ », « 1 ½ ».
 * Une saisie vide renvoie null (« à convenance »). Une saisie invalide lève une exception.
 */
class QuantityParser
{
    private const UNICODE_FRACTIONS = [
        '½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4',
        '⅕' => '1/5', '⅛' => '1/8',
    ];

    public function parse(string|int|float|null $input): ?float
    {
        if ($input === null) {
            return null;
        }

        if (is_int($input) || is_float($input)) {
            return $this->ensureValid((float) $input, (string) $input);
        }

        $raw = $input;
        $value = trim($input);

        if ($value === '') {
            return null;
        }

        foreach (self::UNICODE_FRACTIONS as $symbol => $fraction) {
            $value = str_replace($symbol, ' '.$fraction, $value);
        }

        $value = preg_replace('/\s+/u', ' ', trim(str_replace(',', '.', $value)));

        // Séparateur de milliers « 1 500 » (affiché par QuantityFormatter)
        if (preg_match('/^\d{1,3}( \d{3})+(\.\d+)?$/', $value)) {
            $value = str_replace(' ', '', $value);
        }

        // Nombre entier ou décimal
        if (preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $value)) {
            return $this->ensureValid((float) $value, $raw);
        }

        // Fraction simple « a/b » ou nombre mixte « n a/b »
        if (preg_match('/^(?:(\d+) )?(\d+)\/(\d+)$/', $value, $m)) {
            if ((int) $m[3] === 0) {
                throw new InvalidQuantityException($raw);
            }

            return $this->ensureValid((int) ($m[1] ?: 0) + (int) $m[2] / (int) $m[3], $raw);
        }

        throw new InvalidQuantityException($raw);
    }

    /** Variante sans exception : renvoie false si la saisie est invalide. */
    public function tryParse(string|int|float|null $input): float|null|false
    {
        try {
            return $this->parse($input);
        } catch (InvalidQuantityException) {
            return false;
        }
    }

    private function ensureValid(float $value, string $raw): float
    {
        if ($value < 0 || ! is_finite($value)) {
            throw new InvalidQuantityException($raw);
        }

        return $value;
    }
}
