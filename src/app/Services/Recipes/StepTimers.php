<?php

namespace App\Services\Recipes;

use App\Support\Duration;

/**
 * Durées trouvées dans le texte d'une étape (13.5) : « cuire 25 min », « 1 h 30 », « laisser reposer une heure ».
 * Rien n'est enregistré en base : l'extraction est refaite à l'affichage, donc toujours à jour.
 */
class StepTimers
{
    /** Mots qui annoncent une durée à minuter (sinon « 20 min avant de servir » n'est pas un minuteur). */
    private const VERBS = [
        'cuire', 'cuisson', 'cuit', 'four', 'mijoter', 'mijote', 'bouillir', 'ébullition', 'blanchir', 'rissoler',
        'revenir', 'dorer', 'griller', 'rôtir', 'réchauffer', 'chauffer', 'infuser', 'reposer', 'repos', 'lever',
        'refroidir', 'mariner', 'tremper', 'frémir', 'pocher', 'sauter', 'attendre', 'laisser', 'minuteur',
        'réfrigérateur', 'frigo', 'congélateur', 'compter', 'patienter', 'égoutter', 'saisir', 'poursuivre', 'prolonger',
    ];

    private const WORDS = [
        'une demi-heure' => 30, 'une demi heure' => 30, 'un quart d\'heure' => 15, 'une heure' => 60,
        'deux heures' => 120, 'trois heures' => 180, 'une nuit' => 720, 'toute une nuit' => 720,
    ];

    /**
     * @return list<array{minutes: int, label: string}> durées de l'étape, dans l'ordre, sans doublon
     */
    public function extract(?string $instruction): array
    {
        $text = mb_strtolower((string) $instruction);

        if ($text === '' || ! $this->mentionsCooking($text)) {
            return [];
        }

        $timers = [];

        foreach (self::WORDS as $words => $minutes) {
            if (str_contains($text, $words)) {
                $timers[] = $minutes;
            }
        }

        // « 1 h 30 », « 1h30 », « 2 heures », « 25 min », « 45 minutes », « 30 s »
        preg_match_all('/(\d+)\s*(h(?:eures?)?|min(?:utes?)?|m\b|s(?:econdes?)?)\s*(\d{1,2})?/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $value = (int) $match[1];
            $unit = $match[2];
            $extra = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;

            $minutes = match (true) {
                str_starts_with($unit, 'h') => $value * 60 + $extra,
                str_starts_with($unit, 's') => (int) ceil($value / 60),
                default => $value,
            };

            if ($minutes >= 1 && $minutes <= 1440) {
                $timers[] = $minutes;
            }
        }

        return collect($timers)->unique()->values()
            ->map(fn (int $minutes) => ['minutes' => $minutes, 'label' => Duration::format($minutes)])
            ->all();
    }

    private function mentionsCooking(string $text): bool
    {
        foreach (self::VERBS as $verb) {
            if (str_contains($text, $verb)) {
                return true;
            }
        }

        return false;
    }
}
