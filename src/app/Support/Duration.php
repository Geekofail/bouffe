<?php

namespace App\Support;

final class Duration
{
    /** 45 → « 45 min », 60 → « 1 h », 75 → « 1 h 15 ». */
    public static function format(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        if ($minutes < 60) {
            return "{$minutes}\u{00A0}min";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? "{$hours}\u{00A0}h"
            : sprintf("%d\u{00A0}h\u{00A0}%02d", $hours, $rest);
    }
}
