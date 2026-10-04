<?php

namespace App\Support;

/**
 * Codes à usage unique basés sur l'heure (RFC 6238, TOTP) : ceux qu'affichent Google Authenticator,
 * Aegis, Microsoft Authenticator, 1Password… (lot 25, 27.4).
 *
 * Paramètres standard, compris par toutes les applications : SHA-1, 6 chiffres, 30 secondes.
 * Écrit à la main (une cinquantaine de lignes) plutôt qu'une dépendance de plus.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouveau secret : 20 octets aléatoires (160 bits, la taille recommandée par la RFC 4226), en base 32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Numéro de la période de 30 secondes contenant cet instant. */
    public static function step(?int $timestamp = null): int
    {
        // Horloge de l'application (Carbon) : la même que le reste de Bouffe, et réglable dans les tests.
        return intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD);
    }

    /** Code attendu pour une période donnée. */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code, avec une période de tolérance de chaque côté (horloge du téléphone décalée).
     *
     * @return int|null la période reconnue (pour refuser qu'un même code serve deux fois), ou null
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);

        if (strlen((string) $code) !== self::DIGITS) {
            return null;
        }

        $current = self::step($timestamp);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $current + $i), $code)) {
                return $current + $i;
            }
        }

        return null;
    }

    /** Adresse otpauth:// lue par les applications (QR code). */
    public static function uri(string $secret, string $account, string $issuer = 'Bouffe'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    /** Secret lisible à recopier à la main : groupes de 4 caractères. */
    public static function format(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = '';

        foreach (str_split($text) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                throw new \InvalidArgumentException('Secret invalide (base 32).');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
