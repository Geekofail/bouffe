<?php

namespace App\Services\Households;

use App\Models\Household;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Données d'un foyer (lot 24 — 25.7, 25.8) : place occupée, suppression définitive.
 * L'export (JSON + photos) est fait par App\Services\System\DataExporter, limité au foyer actif.
 */
class HouseholdData
{
    /**
     * Ordre de suppression : les tables qui en référencent d'autres d'abord (les clés étrangères
     * « restrict » entre tables d'un même foyer interdisent de s'en remettre aux cascades).
     */
    public const DELETION_ORDER = [
        'contribution_links', 'contributions', 'child_choices', 'canteen_meals', 'api_tokens', 'inbox_items', 'kitchen_timers', 'meal_tasks', 'stays', 'surplus_offers', 'receipts', 'ocr_readings', 'expenses', 'recurring_expenses', 'budget_categories',
        'offline_operations', 'stock_movements', 'stock_items', 'stock_usage_rules',
        'shopping_list_items', 'shopping_lists', 'standing_items', 'recurring_items',
        'ingredient_prices', 'receipt_label_mappings', 'stores', 'reminders', 'wishes',
        'person_restrictions', 'planned_meals', 'meal_occasions', 'household_people', 'guests', 'week_templates', 'collections', 'recipe_step_notes', 'ingredient_substitutions', 'recipes', 'tags',
        'meal_slots', 'storage_locations', 'household_ingredient_settings', 'settings', 'invitations',
        'undo_tokens', 'activity_events', 'learned_suggestions', 'assistant_usages',
    ];

    /** Fichiers d'un foyer sur le disque privé : photos de recettes et de réceptions, tickets. @return list<string> */
    public function files(Household $household): array
    {
        $files = [];

        foreach (DB::table('recipes')->where('household_id', $household->id)->whereNotNull('photo_path')->pluck('photo_path') as $base) {
            array_push($files, $base.'.jpg', $base.'-thumb.jpg');
        }

        // Photos d'étapes et « notre version » (lot 31, 31.2).
        foreach (DB::table('recipe_photos')->join('recipes', 'recipes.id', '=', 'recipe_photos.recipe_id')
            ->where('recipes.household_id', $household->id)->pluck('recipe_photos.path') as $base) {
            array_push($files, $base.'.jpg', $base.'-thumb.jpg');
        }

        foreach (DB::table('meal_occasions')->where('household_id', $household->id)->whereNotNull('memory_photo')->pluck('memory_photo') as $base) {
            array_push($files, $base.'.jpg', $base.'-thumb.jpg');
        }

        foreach (DB::table('receipts')->where('household_id', $household->id)->whereNull('photos_deleted_at')->pluck('photo_paths') as $paths) {
            array_push($files, ...(array) json_decode((string) $paths, true));
        }

        // Photos reçues dans « À trier » (lot 38), pas encore lues.
        array_push($files, ...DB::table('inbox_items')->where('household_id', $household->id)->whereNotNull('photo_path')->pluck('photo_path')->all());

        $disk = Storage::disk('local');

        return array_values(array_filter($files, fn ($path) => $path && $disk->exists($path)));
    }

    /** Place occupée par les fichiers du foyer, en octets. */
    public function storageBytes(Household $household): int
    {
        $disk = Storage::disk('local');

        return array_sum(array_map(fn ($path) => (int) $disk->size($path), $this->files($household)));
    }

    /** Suppression définitive (25.8, après 30 jours) : données et fichiers ; les comptes restent. */
    public function destroy(Household $household): void
    {
        $files = $this->files($household);

        DB::transaction(function () use ($household) {
            // Les repas restes pointent vers leur repas d'origine : on défait ce lien d'abord.
            DB::table('planned_meals')->where('household_id', $household->id)->update(['leftover_of_id' => null]);

            // Lot 26 : recettes planifiées telles quelles par des foyers reliés → leurs repas gardent le nom.
            app(\App\Services\Linked\RecipeRemoval::class)->detachFromOtherHouseholds(
                DB::table('recipes')->where('household_id', $household->id)->pluck('id')->all(), $household->id);

            foreach (self::DELETION_ORDER as $table) {
                DB::table($table)->where('household_id', $household->id)->delete();
            }

            DB::table('users')->where('current_household_id', $household->id)->update(['current_household_id' => null]);
            DB::table('household_user')->where('household_id', $household->id)->delete();
            DB::table('households')->where('id', $household->id)->delete();
        });

        Storage::disk('local')->delete($files);
    }
}
