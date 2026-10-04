<?php

namespace App\Support;

/**
 * Laisse le temps à un service extérieur de répondre (lecture d'un ticket, assistant) sans jamais
 * raccourcir une limite plus large : en ligne de commande et dans les tests, il n'y en a aucune (0),
 * et `set_time_limit()` en imposerait une au processus entier.
 */
final class TimeLimit
{
    public static function atLeast(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current > 0 && $current < $seconds) {
            @set_time_limit($seconds);
        }
    }
}
