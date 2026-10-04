<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Notifications sur le téléphone (19.2) : le protocole Web Push, sans dépendance externe.
 *
 * Deux normes, toutes deux prises en charge par PHP (extension openssl) :
 *  - VAPID (RFC 8292) : Bouffe signe chaque envoi avec sa propre clé (ES256), générée une fois ;
 *  - chiffrement « aes128gcm » (RFC 8188 et 8291) : le message est chiffré pour le seul navigateur
 *    abonné ; le service de notification (Google, Apple, Mozilla) ne peut pas le lire.
 *
 * Le navigateur donne à l'abonnement une adresse (endpoint) et deux clés (p256dh, auth).
 */
class WebPush
{
    /** Durée pendant laquelle le service garde un message si le téléphone est éteint. */
    public const TTL = 86400;

    /** En-tête DER d'une clé publique EC P-256 non compressée (SubjectPublicKeyInfo). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Après tant d'échecs d'affilée, l'abonnement est oublié. */
    public const MAX_FAILURES = 5;

    /* ================================================================ Clés VAPID */

    public function isSupported(): bool
    {
        return extension_loaded('openssl') && function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    /** Clé publique à donner au navigateur (base64url), générée à la première demande. */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /** @return array{public: string, private: string} */
    public function keys(): array
    {
        $keys = Settings::get('push.vapid');

        if (is_array($keys) && isset($keys['public'], $keys['private'])) {
            return $keys;
        }

        [$private, $public] = $this->newKeyPair();
        $keys = ['public' => self::b64($public), 'private' => $private];
        Settings::set('push.vapid', $keys);

        return $keys;
    }

    /**
     * @return array{0: string, 1: string} clé privée PEM, clé publique brute (65 octets)
     */
    private function newKeyPair(): array
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => resource_path('openssl/openssl.cnf')];
        $key = openssl_pkey_new($options);

        if ($key === false) {
            throw new RuntimeException('Impossible de créer une clé de chiffrement (OpenSSL) : '.openssl_error_string());
        }

        openssl_pkey_export($key, $pem, null, $options);
        $details = openssl_pkey_get_details($key);

        return [$pem, "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)];
    }

    /* ================================================================ Envoi */

    /**
     * Envoie un message à un abonnement.
     *
     * @param  array{title: string, body?: string|null, url?: string|null, tag?: string|null}  $message
     * @return string sent · gone (abonnement expiré, supprimé) · failed
     */
    public function send(PushSubscription $subscription, array $message): string
    {
        $payload = json_encode(array_filter($message, fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $this->encrypt($payload, self::unb64($subscription->public_key), self::unb64($subscription->auth_token));

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'TTL' => (string) self::TTL,
                    'Urgency' => 'normal',
                    'Content-Encoding' => 'aes128gcm',
                    'Authorization' => $this->authorization($subscription->endpoint),
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (\Throwable) {
            $this->failed($subscription);

            return 'failed';
        }

        if ($response->successful()) {
            $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

            return 'sent';
        }

        // 404 / 410 : le navigateur s'est désabonné (ou a été réinstallé) — on oublie l'adresse.
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return 'gone';
        }

        $this->failed($subscription);

        return 'failed';
    }

    private function failed(PushSubscription $subscription): void
    {
        $subscription->increment('failures');

        if ($subscription->failures >= self::MAX_FAILURES) {
            $subscription->delete();
        }
    }

    /* ================================================================ VAPID (RFC 8292) */

    /** En-tête « Authorization: vapid t=…, k=… » pour l'origine du service. */
    public function authorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $keys = $this->keys();

        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $this->subject()], JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($header.'.'.$claims, $der, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible : '.openssl_error_string());
        }

        return 'vapid t='.$header.'.'.$claims.'.'.self::b64(self::derToRaw($der)).', k='.$keys['public'];
    }

    private function subject(): string
    {
        $subject = (string) config('bouffe.notifications.vapid_subject');

        if ($subject !== '') {
            return str_contains($subject, ':') ? $subject : 'mailto:'.$subject;
        }

        $email = \App\Models\User::query()->orderBy('id')->value('email');

        return $email ? 'mailto:'.$email : (string) config('app.url');
    }

    /* ================================================================ Chiffrement (RFC 8291) */

    /**
     * Chiffre un message pour un navigateur.
     *
     * @param  string  $userPublic  clé publique du navigateur (65 octets, p256dh)
     * @param  string  $authSecret  secret d'authentification du navigateur (16 octets)
     * @param  array{0: string, 1: string}|null  $ephemeral  [clé privée PEM, clé publique brute] — pour les tests
     */
    public function encrypt(string $payload, string $userPublic, string $authSecret, ?array $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($userPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Clés d\'abonnement invalides.');
        }

        [$serverPrivatePem, $serverPublic] = $ephemeral ?? $this->newKeyPair();
        $salt ??= random_bytes(16);

        $userKey = openssl_pkey_get_public(self::pem(hex2bin(self::P256_SPKI_PREFIX).$userPublic));
        $sharedSecret = openssl_pkey_derive($userKey, openssl_pkey_get_private($serverPrivatePem), 32);

        if ($sharedSecret === false) {
            throw new RuntimeException('Échange de clés impossible : '.openssl_error_string());
        }

        // Clé d'entrée : mélange du secret partagé et du secret d'authentification du navigateur.
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\0".$userPublic.$serverPublic, $authSecret);
        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Un seul enregistrement : le message suivi du délimiteur 0x02 (« dernier enregistrement »).
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', 4096).chr(65).$serverPublic.$cipher.$tag;
    }

    /* ================================================================ Outils */

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** Signature ECDSA : DER (openssl) → r||s de 64 octets (JWT). */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
