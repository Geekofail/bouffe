<?php

namespace App\Support;

/**
 * Palette de couleurs utilisée pour les rayons et les catégories.
 *
 * On stocke en base une clé ('green', 'red'…) et on la traduit ici en classes Tailwind.
 * Les classes sont écrites en toutes lettres pour que Tailwind les détecte à la compilation.
 */
final class Palette
{
    public const DEFAULT = 'stone';

    /** @var array<string, array{label: string, badge: string, dot: string}> */
    private const COLORS = [
        'red' => ['label' => 'Rouge', 'badge' => 'bg-red-50 text-red-700 ring-red-200', 'dot' => 'bg-red-500'],
        'orange' => ['label' => 'Orange', 'badge' => 'bg-orange-50 text-orange-700 ring-orange-200', 'dot' => 'bg-orange-500'],
        'amber' => ['label' => 'Ambre', 'badge' => 'bg-amber-50 text-amber-800 ring-amber-200', 'dot' => 'bg-amber-500'],
        'yellow' => ['label' => 'Jaune', 'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-200', 'dot' => 'bg-yellow-400'],
        'lime' => ['label' => 'Citron vert', 'badge' => 'bg-lime-50 text-lime-800 ring-lime-200', 'dot' => 'bg-lime-500'],
        'green' => ['label' => 'Vert', 'badge' => 'bg-green-50 text-green-700 ring-green-200', 'dot' => 'bg-green-500'],
        'teal' => ['label' => 'Sarcelle', 'badge' => 'bg-teal-50 text-teal-700 ring-teal-200', 'dot' => 'bg-teal-500'],
        'cyan' => ['label' => 'Cyan', 'badge' => 'bg-cyan-50 text-cyan-800 ring-cyan-200', 'dot' => 'bg-cyan-500'],
        'sky' => ['label' => 'Ciel', 'badge' => 'bg-sky-50 text-sky-700 ring-sky-200', 'dot' => 'bg-sky-500'],
        'blue' => ['label' => 'Bleu', 'badge' => 'bg-blue-50 text-blue-700 ring-blue-200', 'dot' => 'bg-blue-500'],
        'indigo' => ['label' => 'Indigo', 'badge' => 'bg-indigo-50 text-indigo-700 ring-indigo-200', 'dot' => 'bg-indigo-500'],
        'violet' => ['label' => 'Violet', 'badge' => 'bg-violet-50 text-violet-700 ring-violet-200', 'dot' => 'bg-violet-500'],
        'pink' => ['label' => 'Rose', 'badge' => 'bg-pink-50 text-pink-700 ring-pink-200', 'dot' => 'bg-pink-500'],
        'stone' => ['label' => 'Gris', 'badge' => 'bg-stone-100 text-stone-700 ring-stone-200', 'dot' => 'bg-stone-400'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::COLORS);
    }

    /** @return array<string, string> clé => libellé */
    public static function options(): array
    {
        return array_map(fn (array $color) => $color['label'], self::COLORS);
    }

    public static function badge(?string $key): string
    {
        return self::COLORS[$key]['badge'] ?? self::COLORS[self::DEFAULT]['badge'];
    }

    public static function dot(?string $key): string
    {
        return self::COLORS[$key]['dot'] ?? self::COLORS[self::DEFAULT]['dot'];
    }
}
