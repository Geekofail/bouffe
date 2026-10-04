<?php

namespace App\Support;

/**
 * Adresses IPv4 du PC sur le réseau local, pour ouvrir Bouffe depuis un téléphone.
 */
class NetworkAddresses
{
    /** @return list<string> adresses privées, les plus probables (box Internet 192.168.x.x) en premier */
    public function localIpv4(?string $serverAddress = null): array
    {
        $candidates = array_merge(
            gethostbynamel((string) gethostname()) ?: [],
            $serverAddress ? [$serverAddress] : [],
        );

        $addresses = array_values(array_unique(array_filter($candidates, [$this, 'isLan'])));

        usort($addresses, fn (string $a, string $b) => [$this->rank($a), ip2long($a)] <=> [$this->rank($b), ip2long($b)]);

        return $addresses;
    }

    public function isLan(?string $ip): bool
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Adresse privée (RFC 1918), hors bouclage et hors auto-configuration 169.254.x.x
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
            && ! str_starts_with($ip, '127.')
            && ! str_starts_with($ip, '169.254.');
    }

    private function rank(string $ip): int
    {
        return match (true) {
            str_starts_with($ip, '192.168.') => 0,
            str_starts_with($ip, '10.') => 1,
            default => 2, // 172.16–31 : souvent des cartes réseau virtuelles (WSL, VirtualBox, Docker)
        };
    }
}
