<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Planning\WeekPlanner;
use App\Services\Pricing\PriceBook;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\StockManager;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Données d'essai des tests dans le navigateur (lot 28, 28.7).
 *
 * Utilisé uniquement par `php artisan bouffe:browser-db`, sur une base SQLite à part : jamais sur la
 * vraie base. Les données sont **inventées** pour remplir les écrans (une semaine de repas, une liste,
 * du stock qui arrive à sa date, deux magasins avec des prix) ; elles ne décrivent personne.
 *
 * Compte : demo@bouffe.test, mot de passe « essai-navigateur » (valeurs de test, sans autre usage).
 */
class BrowserDemoSeeder extends Seeder
{
    public const EMAIL = 'demo@bouffe.test';

    public const PASSWORD = 'essai-navigateur';

    public function run(): void
    {
        $household = Household::query()->orderBy('id')->firstOrFail();
        $household->update(['name' => 'Foyer d\'essai']);

        $user = User::create(['name' => 'Camille', 'email' => self::EMAIL, 'password' => self::PASSWORD, 'is_admin' => true]);
        app(HouseholdManager::class)->attach($household, $user, UserRole::Owner);
        // Lot 41 (41.3) : une deuxième personne inventée, pour répartir les plats.
        $second = User::create(['name' => 'Malik', 'email' => 'malik@exemple.test', 'password' => \Illuminate\Support\Str::random(40)]);
        app(HouseholdManager::class)->attach($household, $second, UserRole::Full);

        CurrentHousehold::run($household, function () {
            $this->call([
                UnitSeeder::class,
                AisleSeeder::class,
                TagSeeder::class,
                MealSlotSeeder::class,
                StorageLocationSeeder::class,
                IngredientSeeder::class,
                SubstitutionSeeder::class,
                SeasonSeeder::class,
                RecipeSeeder::class,
            ]);

            $this->prices();
            $this->week();
            $this->stock();
            $this->journal();
            $this->stay();
        });
    }

    /** Une semaine en partie remplie, autour d'aujourd'hui, et sa liste de courses. */
    private function week(): void
    {
        $planner = app(WeekPlanner::class);
        $start = $planner->weekStart();
        $slots = MealSlot::query()->where('is_active', true)->ordered()->get();
        $lunch = $slots->first();
        $dinner = $slots->last();
        $recipe = fn (string $title) => Recipe::query()->where('title', $title)->firstOrFail();

        // Lot 32 : à table d'habitude, Camille et Léo (personne inventée, sans compte).
        $camille = \App\Models\User::query()->firstWhere('email', self::EMAIL);
        app(\App\Services\Planning\Appetites::class)->saveTable([
            ['name' => 'Camille', 'appetite' => 'normal', 'user_id' => $camille->id],
            ['name' => 'Léo', 'appetite' => 'moyen', 'user_id' => null],
        ]);

        $quiche = $planner->addRecipe($start->copy(), $dinner, $recipe('Quiche lorraine'), 4);
        $planner->addRecipe($start->copy()->addDays(1), $dinner, $recipe('Curry de poulet au lait de coco'), 4);
        $planner->addFree($start->copy()->addDays(2), $lunch, 'Restaurant d\'entreprise');
        $planner->addRecipe($start->copy()->addDays(3), $dinner, $recipe('Salade grecque'), 2);
        $planner->addRecipe($start->copy()->addDays(4), $dinner, $recipe('Gratin dauphinois'), 4);
        $planner->addRecipe($start->copy()->addDays(5), $lunch, $recipe('Crêpes'), 4);
        $planner->addRecipe($start->copy()->addDays(6), $dinner, $recipe('Spaghetti bolognaise'), 4);

        // Lot 31 (31.1, 31.3) : une collection inventée, et un dessert avec le plat du dimanche.
        $collection = app(\App\Services\Recipes\Collections::class)->create('Repas du dimanche', 'Collection inventée pour les tests.');
        foreach (['Spaghetti bolognaise', 'Crêpes', 'Gratin dauphinois'] as $title) {
            app(\App\Services\Recipes\Collections::class)->toggle($collection, $recipe($title));
        }
        $today = Carbon::today();
        $main = \App\Models\PlannedMeal::query()->whereDate('date', $today->toDateString())->where('meal_slot_id', $dinner->id)->where('type', 'recipe')->first()
            ?? $planner->addRecipe($today->copy(), $dinner, $recipe('Gratin dauphinois'), 4);
        $main->update(['course' => \App\Enums\Course::Main]);
        $planner->addRecipe($today->copy(), $dinner, $recipe('Crêpes'), 4)->update(['course' => \App\Enums\Course::Dessert]);

        // Lot 32 (32.3) : une gamelle pour demain midi, avec les restes de la quiche.
        $planner->addLeftover(Carbon::tomorrow(), $lunch, $quiche, null, app(\App\Services\People\HouseholdPeople::class)->forUser($camille)->id);

        // Lot 39 : Léo (inventé) mange à la cantine lundi, mardi, jeudi et vendredi ; un menu inventé
        // pour cette semaine, une recette « facile avec un enfant » et un choix à faire demain soir.
        $people = app(\App\Services\People\HouseholdPeople::class);
        $leo = \App\Models\HouseholdPerson::query()->where('name', 'Léo')->firstOrFail();
        $leo = $people->save($leo, ['name' => 'Léo', 'appetite' => 'moyen', 'color' => 'ciel', 'canteen_days' => [1, 2, 4, 5], 'canteen_name' => 'École du quartier']);
        $people->save($people->forUser($camille), ['name' => 'Camille', 'user_id' => $camille->id, 'appetite' => 'normal', 'color' => 'basilic']);
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        app(\App\Services\People\CanteenCalendar::class)->paste($leo, $monday,
            "Lundi : Potage, poisson pané, purée\nMardi : Lasagnes, salade verte\nJeudi : Poulet rôti, haricots verts\nVendredi : Couscous aux légumes");
        $recipe('Crêpes')->update(['kid_friendly' => true]);
        app(\App\Services\People\ChildChoices::class)->propose(Carbon::tomorrow(), $dinner,
            [$recipe('Crêpes')->id, $recipe('Spaghetti bolognaise')->id, $recipe('Gratin dauphinois')->id], $leo->id);

        $list = app(ShoppingListManager::class)->create($start->copy(), $start->copy()->addDays(6));
        $list->update(['store_id' => Store::query()->first()?->id]);
    }

    /** Du stock : un produit dépassé, un à consommer vite, des réserves. */
    private function stock(): void
    {
        $stock = app(StockManager::class);
        $today = Carbon::today();
        $id = fn (string $name) => Ingredient::query()->where('name', $name)->value('id');

        $stock->add(['ingredient_id' => $id('Beurre'), 'quantity' => 250, 'expires_on' => $today->copy()->subDays(2)->toDateString()]);
        $stock->add(['ingredient_id' => $id('Lait demi-écrémé'), 'quantity' => 1, 'expires_on' => $today->copy()->addDays(2)->toDateString()]);
        $stock->add(['ingredient_id' => $id('Œuf'), 'quantity' => 6, 'expires_on' => $today->copy()->addDays(12)->toDateString()]);
        $stock->add(['ingredient_id' => $id('Farine'), 'quantity' => 1000]);
        $stock->add(['label' => 'Soupe de potiron maison', 'quantity' => 2]);

        // Lot 30 (30.8) : trois réserves qui n'ont pas bougé depuis plus de deux mois.
        foreach (['Riz basmati', 'Lentilles corail', 'Chapelure'] as $i => $name) {
            $item = $stock->add(['ingredient_id' => $id($name), 'quantity' => 500]);
            $old = $today->copy()->subDays(70 + $i * 5);
            \Illuminate\Support\Facades\DB::table('stock_items')->where('id', $item->id)->update(['created_at' => $old, 'updated_at' => $old]);
            \Illuminate\Support\Facades\DB::table('stock_movements')->where('stock_item_id', $item->id)->update(['created_at' => $old]);
        }
    }

    /** Lot 34 : un séjour inventé, avec des repas, une dépense et un article à emporter. */
    private function stay(): void
    {
        $stays = app(\App\Services\Stays\StayService::class);
        $start = Carbon::today()->addDays(10);
        $stay = $stays->create(['name' => 'Week-end à la campagne', 'place' => 'Gîte (inventé)', 'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addDays(2)->toDateString()]);
        $stays->addHousehold($stay);
        $stays->addPerson($stay, 'Alex', 'grand', 'Les amis');
        $stays->addPerson($stay, 'Sam', 'normal', 'Les amis');
        $stay->load('participants');
        $dinner = MealSlot::query()->where('is_active', true)->ordered()->get()->last();
        $stays->addMeal($stay, $start->toDateString(), $dinner->id, Recipe::query()->where('title', 'Chili con carne')->value('id'));
        $stays->addMeal($stay, $start->copy()->addDay()->toDateString(), $dinner->id, null, 'Barbecue');
        app(\App\Services\Stays\StayCosts::class)->addExpense($stay, 'Les amis', 'Location du gîte', '240');
        app(\App\Services\Stays\StayShopping::class)->create($stay);
    }

    /** Lot 30 (30.2) : quelques lignes de journal, inventées. */
    private function journal(): void
    {
        $user = User::query()->firstWhere('email', self::EMAIL);
        $now = Carbon::now();

        foreach ([
            [0, 'shopping.checked', 'a coché 8 articles dans « Courses de la semaine »', 8],
            [0, 'planning.planned', 'a planifié 3 repas', 3],
            [1, 'wish.added', 'a ajouté une envie : Lasagnes', 1],
            [3, 'stock.in', 'a rangé 5 articles dans le stock', 5],
        ] as [$days, $type, $summary, $count]) {
            $at = $now->copy()->subDays($days)->subHours(2);
            \App\Models\ActivityEvent::create(['user_id' => $user->id, 'type' => $type, 'summary' => $summary, 'count' => $count, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    /** Deux magasins et quelques prix, pour le comparateur et la liste répartie (lot 27). */
    private function prices(): void
    {
        $first = Store::create(['name' => 'Supermarché A', 'is_default' => true]);
        $second = Store::create(['name' => 'Supermarché B', 'color' => 'blue']);
        $book = app(PriceBook::class);
        $gram = Unit::firstWhere('code', 'g');
        $day = Carbon::today()->subDays(3);

        foreach ([['Beurre', 2.49, 2.19], ['Farine', 1.20, 0.99], ['Œuf', 3.60, 2.99]] as [$name, $a, $b]) {
            $ingredient = Ingredient::firstWhere('name', $name);
            $unit = $name === 'Œuf' ? Unit::firstWhere('code', 'piece') : $gram;
            $quantity = $name === 'Œuf' ? 12 : ($name === 'Farine' ? 1000 : 250);
            $book->record($ingredient, $a, $quantity, $unit, $first, $day, IngredientPrice::MANUAL);
            $book->record($ingredient, $b, $quantity, $unit, $second, $day, IngredientPrice::MANUAL);
        }

        // Lot 30 (R35) : un prix en promotion, montré à part comme « meilleur prix vu ».
        $book->record(Ingredient::firstWhere('name', 'Beurre'), 1.79, 250, $gram, $first, $day->copy()->subDays(10), IngredientPrice::MANUAL, true);
    }
}
