<?php

namespace App\Services\Activity;

use App\Enums\MovementType;
use App\Models\Expense;
use App\Models\PlannedMeal;
use App\Models\Receipt;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\StockMovement;
use App\Models\Wish;

/**
 * Ce que le journal du foyer retient (30.2), branché sur les enregistrements eux-mêmes : où que le
 * geste soit fait (planning, accueil, liste, ticket…), il est noté de la même façon.
 */
class ActivityWatcher
{
    public static function register(): void
    {
        $log = fn (): ActivityLog => app(ActivityLog::class);
        $p = fn (int $n, string $one, ?string $many = null) => ActivityLog::plural($n, $one, $many);

        // Planning.
        PlannedMeal::created(function (PlannedMeal $meal) use ($log, $p) {
            $label = $meal->label();
            $log()->record('planning.planned', "a planifié « {$label} »", null, fn (int $n) => 'a planifié '.$p($n, 'repas', 'repas'));
        });

        PlannedMeal::deleted(function (PlannedMeal $meal) use ($log, $p) {
            $log()->record('planning.removed', 'a retiré « '.$meal->label().' » du planning', null, fn (int $n) => 'a retiré '.$p($n, 'repas', 'repas').' du planning');
        });

        PlannedMeal::updated(function (PlannedMeal $meal) use ($log, $p) {
            if ($meal->wasChanged('cooked_at') && $meal->cooked_at && ! $meal->closed_automatically) {
                $log()->record('planning.cooked', 'a noté « '.$meal->label().' » comme mangé', null, fn (int $n) => 'a noté '.$p($n, 'repas', 'repas').' comme mangés');
            }
        });

        Wish::created(function (Wish $wish) use ($log) {
            $log()->record('wish.added', 'a ajouté une envie : '.$wish->label());
        });

        // Courses.
        ShoppingList::created(function (ShoppingList $list) use ($log) {
            $log()->record('shopping.created', "a préparé la liste « {$list->name} »", $list);
        });

        ShoppingListItem::updated(function (ShoppingListItem $item) use ($log, $p) {
            if ($item->wasChanged('is_checked') && $item->is_checked && ($list = $item->shoppingList)) {
                $log()->record('shopping.checked', "a coché « {$item->label} » dans « {$list->name} »", $list,
                    fn (int $n) => 'a coché '.$p($n, 'article')." dans « {$list->name} »");
            }
        });

        // Stock.
        StockMovement::created(function (StockMovement $movement) use ($log, $p) {
            match ($movement->type) {
                MovementType::In => $log()->record('stock.in', "a rangé « {$movement->label} » dans le stock", null, fn (int $n) => 'a rangé '.$p($n, 'article').' dans le stock'),
                MovementType::Waste => $log()->record('stock.waste', "a jeté « {$movement->label} »", null, fn (int $n) => 'a jeté '.$p($n, 'article')),
                default => null,
            };
        });

        // Recettes, budget.
        Recipe::created(function (Recipe $recipe) use ($log) {
            $log()->record('recipe.added', "a ajouté la recette « {$recipe->title} »");
        });

        Receipt::created(function () use ($log) {
            $log()->record('budget.receipt', 'a ajouté un ticket de caisse');
        });

        Expense::created(function (Expense $expense) use ($log) {
            if ($expense->receipt_id === null) {
                $log()->record('budget.expense', 'a noté une dépense de '.number_format((float) $expense->amount, 2, ',', ' ').' €');
            }
        });
    }
}
