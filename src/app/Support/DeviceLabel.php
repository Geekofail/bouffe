<?php

namespace App\Support;

/**
 * Nom lisible d'un appareil d'après l'en-tête User-Agent du navigateur (lot 25, 27.5) :
 * « Chrome sur Windows », « Safari sur iPhone »… Sans bibliothèque : les grands cas suffisent pour
 * reconnaître ses propres appareils dans la liste.
 */
final class DeviceLabel
{
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return 'Appareil inconnu';
        }

        return self::browser($ua).' sur '.self::system($ua);
    }

    /** Icône de la liste : téléphone ou ordinateur. */
    public static function icon(?string $userAgent): string
    {
        return preg_match('/iPhone|iPad|Android|Mobile/i', (string) $userAgent) ? 'phone' : 'desktop';
    }

    /** Empreinte courte du navigateur, pour reconnaître un nouvel appareil sans garder plus que nécessaire. */
    public static function fingerprint(?string $userAgent): ?string
    {
        return $userAgent ? substr(hash('sha256', $userAgent), 0, 16) : null;
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS'), str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };
    }

    private static function system(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'système inconnu',
        };
    }
}
