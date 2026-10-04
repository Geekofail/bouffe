<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Double authentification (lot 25, 27.4 ; Q41).
 *
 *  - secret TOTP chiffré avec APP_KEY, confirmé par un premier code avant d'être exigé ;
 *  - 8 codes de secours à usage unique, montrés une seule fois et gardés sous forme de condensés ;
 *  - un même code ne sert pas deux fois (période mémorisée) ;
 *  - « appareil de confiance » : cookie signé de 30 jours, invalidé si la double authentification
 *    est coupée ou réactivée (il dépend du secret).
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public const TRUST_COOKIE = 'bouffe_appareil';

    public const TRUST_DAYS = 30;

    /** Double authentification exigée pour ce compte : administrateur et responsables de foyer (Q41). */
    public function required(User $user): bool
    {
        return (bool) config('bouffe.security.two_factor_required') && ($user->isAdmin() || $user->ownsAnyHousehold());
    }

    /** Étape 1 : nouveau secret, pas encore exigé (il faut le confirmer par un code). */
    public function begin(User $user): string
    {
        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * Étape 2 : le premier code affiché par l'application confirme que tout est en place.
     *
     * @return list<string>|null codes de secours à montrer une fois, ou null si le code est faux
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || $user->two_factor_confirmed_at || ! $this->verifyCode($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** Code à 6 chiffres de l'application (une seule utilisation par période). */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        $step = Totp::verify($user->two_factor_secret, $code);
        $key = "bouffe.2fa.step.{$user->id}";

        if ($step === null || $step <= (int) Cache::get($key, 0)) {
            return false;
        }

        Cache::put($key, $step, now()->addMinutes(5));

        return true;
    }

    /** Code de secours : accepté une fois, puis rayé de la liste. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hash($code);
        $codes = (array) $user->two_factor_recovery_codes;

        foreach ($codes as $i => $stored) {
            if (hash_equals((string) $stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> nouveaux codes, en clair (les anciens ne marchent plus) */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // Sans 0/O ni 1/l : se recopie sans hésitation depuis une feuille.
            $codes[] = $this->randomChunk(5).'-'.$this->randomChunk(5);
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => $this->hash($c), $codes)])->save();

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) $user->two_factor_recovery_codes);
    }

    /* ================================================================ Appareil de confiance */

    public function trustDevice(User $user): void
    {
        $expires = now()->addDays(self::TRUST_DAYS)->getTimestamp();

        Cookie::queue(Cookie::make(self::TRUST_COOKIE, $user->id.'|'.$expires.'|'.$this->signature($user, $expires), self::TRUST_DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $parts = explode('|', (string) $request->cookie(self::TRUST_COOKIE), 3);

        if (count($parts) !== 3 || (int) $parts[0] !== $user->id || (int) $parts[1] < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($user, (int) $parts[1]), $parts[2]);
    }

    public function forgetDevice(): void
    {
        Cookie::queue(Cookie::forget(self::TRUST_COOKIE));
    }

    private function signature(User $user, int $expires): string
    {
        return hash_hmac('sha256', $user->id.'|'.$expires.'|'.$user->two_factor_secret.'|'.$user->two_factor_confirmed_at?->getTimestamp(), (string) config('app.key'));
    }

    private function hash(string $code): string
    {
        return hash('sha256', Str::lower(preg_replace('/[^a-z0-9]/i', '', $code)));
    }

    private function randomChunk(int $length): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
