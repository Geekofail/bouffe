<?php

namespace App\Support;

use App\Models\User;

/**
 * Sections de l'application, barre du bas du téléphone et blocs de l'accueil (lot 11, 12.3 et 12.7).
 * Les choix sont enregistrés dans les préférences de chaque utilisateur.
 */
final class Navigation
{
    /** clé => [route, libellé, icône] */
    public const SECTIONS = [
        'dashboard' => ['dashboard', 'Accueil', 'home'],
        'recipes' => ['recipes.index', 'Recettes', 'recipes'],
        'planner' => ['planner.week', 'Planning', 'calendar'],
        'shopping' => ['shopping.index', 'Courses', 'cart'],
        'stock' => ['stock.index', 'Stock', 'pantry'],
        'suggestions' => ['suggestions', 'Que cuisiner ?', 'sparkles'],
        'guests' => ['guests.index', 'Invités', 'users'],
        'receptions' => ['receptions.index', 'Réceptions', 'cake'],
        'stays' => ['stays.index', 'Séjours', 'sun'],
        'budget' => ['budget.index', 'Budget', 'euro'],
        'receipts' => ['receipts.index', 'Tickets', 'receipt'],
        'prices' => ['prices.index', 'Prix', 'tag'],
        'linked' => ['linked.index', 'Proches', 'heart'],
        'settings' => ['settings.index', 'Paramètres', 'settings'],
    ];

    /** Barre du bas par défaut : 4 raccourcis, le reste dans « Plus ». */
    public const DEFAULT_BOTTOM = ['dashboard', 'planner', 'shopping', 'stock'];

    public const BOTTOM_SIZE = 4;

    /** Blocs de l'accueil : clé => libellé. */
    public const HOME_SECTIONS = [
        'menu' => 'Au menu aujourd\'hui',
        'leftovers' => 'Restes à finir',
        'expiring' => 'À consommer rapidement',
        'reminders' => 'À préparer à l\'avance',
        'shopping' => 'Courses',
        'week' => 'Cette semaine',
        'wishes' => 'À planifier bientôt',
        'ideas' => 'Ça fait longtemps…',
        'linked' => 'Fil des proches',
        'prices' => 'Hausses de prix',
    ];

    /** @return list<string> */
    public static function bottom(?User $user): array
    {
        $keys = array_values(array_filter((array) $user?->preference('bottom_nav', self::DEFAULT_BOTTOM), fn ($k) => isset(self::SECTIONS[$k])));
        $keys = array_values(array_unique($keys));

        return count($keys) === self::BOTTOM_SIZE ? $keys : self::DEFAULT_BOTTOM;
    }

    /**
     * Blocs de l'accueil dans l'ordre choisi, avec leur visibilité.
     *
     * @return list<array{key: string, label: string, visible: bool}>
     */
    public static function home(?User $user): array
    {
        $saved = (array) $user?->preference('home', []);
        $order = array_values(array_unique(array_filter(array_column($saved, 'key'), fn ($k) => isset(self::HOME_SECTIONS[$k]))));
        $hidden = array_column(array_filter($saved, fn ($row) => empty($row['visible'])), 'key');

        foreach (array_keys(self::HOME_SECTIONS) as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;   // bloc ajouté dans une version plus récente : visible, à la fin
            }
        }

        return array_map(fn (string $key) => ['key' => $key, 'label' => self::HOME_SECTIONS[$key], 'visible' => ! in_array($key, $hidden, true)], $order);
    }
}
