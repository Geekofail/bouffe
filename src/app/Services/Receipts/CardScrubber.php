<?php

namespace App\Services\Receipts;

/**
 * 24.8 : les chiffres d'une carte bancaire éventuellement lus ne sont jamais enregistrés.
 *
 * Masque, dans tout texte venant du service, les suites de chiffres qui ressemblent à un numéro
 * de carte (13 à 19 chiffres, avec ou sans espaces) et les fins de numéro masquées
 * (« **** **** **** 1234 », « XXXXXXXXXXXX1234 »), y compris les 4 derniers chiffres.
 */
final class CardScrubber
{
    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Fin de numéro masquée : « ****1234 », « XXXX XXXX XXXX 1234 », « ••••  1234 »
        $text = preg_replace('/(?:[\*xX•#]{2,}[\s\-]*){1,4}\d{2,4}\b/u', '[carte]', $text);

        // Numéro complet : 13 à 19 chiffres, éventuellement groupés par espaces ou tirets
        $text = preg_replace('/\b\d(?:[\s\-]?\d){12,18}\b/u', '[carte]', $text);

        // « CARTE … 1234 », « VISA 1234 », « N° carte : 1234 »
        return preg_replace('/\b(carte|card|visa|mastercard|maestro|cb|v[\s\-]?pay|bancontact)\b([^\n\d]{0,20})\d{4}\b/iu', '$1$2[carte]', $text);
    }

    /** Nettoie récursivement une structure (réponse brute). */
    public static function deep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_array($value)) {
            return array_map(fn ($v) => self::deep($v), $value);
        }

        return $value;
    }
}
