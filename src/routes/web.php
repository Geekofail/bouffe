<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\ExpenseExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HouseholdSwitchController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReceiptPhotoController;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\RecipeExportController;
use App\Http\Controllers\RecipePhotoController;
use App\Http\Controllers\StoreModeController;
use App\Http\Controllers\TasksController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Invités (non connectés)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::livewire('/connexion', Livewire\Auth\Login::class)->name('login');

    // Lot 25 : code de double authentification (27.4), mot de passe oublié (27.6).
    Route::livewire('/connexion/verification', Livewire\Auth\TwoFactorChallenge::class)->name('two-factor.challenge');
    Route::livewire('/mot-de-passe-oublie', Livewire\Auth\ForgotPassword::class)->middleware('throttle:30,1')->name('password.request');
    Route::livewire('/reinitialiser-mot-de-passe/{token}', Livewire\Auth\ResetPassword::class)->middleware('throttle:30,1')->name('password.reset');
});

// « Vos données » (27.12) : lisible avant de rejoindre un foyer.
Route::livewire('/vos-donnees', Livewire\Account\Privacy::class)->name('privacy');

// Tâches planifiées (27.3) et surveillance (27.11) : appelées par des services externes, sans session ni cookie.
Route::get('/taches/{token}', TasksController::class)->where('token', '[A-Za-z0-9]{20,128}')
    ->withoutMiddleware('web')->middleware('throttle:12,1')->name('tasks.run');
Route::get('/sante', HealthController::class)->withoutMiddleware('web')->middleware('throttle:30,1')->name('health');

// Raccourcis (lot 38, R39) : Siri et l'application Raccourcis, avec le jeton personnel ; sans session.
Route::prefix('api/raccourcis')->name('shortcuts.')->withoutMiddleware('web')
    ->middleware(['throttle:120,1', \App\Http\Middleware\AuthenticateShortcutToken::class])->group(function () {
        Route::post('/courses', [\App\Http\Controllers\ShortcutController::class, 'shopping'])->name('shopping');
        Route::post('/stock', [\App\Http\Controllers\ShortcutController::class, 'stock'])->name('stock');
        Route::get('/menu', [\App\Http\Controllers\ShortcutController::class, 'menu'])->name('menu');
        Route::post('/a-trier', [\App\Http\Controllers\ShortcutController::class, 'inbox'])->name('inbox');
    });

// Agenda du planning (C4, lot 26) : abonnement iCalendar, identifié par un jeton personnel.
Route::get('/agenda/{token}.ics', CalendarController::class)->where('token', '[A-Za-z0-9]{20,100}')
    ->withoutMiddleware('web')->middleware('throttle:30,1')->name('calendar.feed');

// Recette partagée par lien (31.4) : lecture seule, sans compte, 30 jours au plus, révocable.
Route::get('/partage/recette/{token}', [\App\Http\Controllers\SharedRecipeController::class, 'show'])->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:60,1')->name('share.recipe');
// Lot 42 (42.2) : « qui apporte quoi » pour ceux qui n'ont pas Bouffe.
Route::get('/apporter/{token}', [\App\Http\Controllers\BringController::class, 'show'])->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:60,1')->name('bring.show');
Route::post('/apporter/{token}', [\App\Http\Controllers\BringController::class, 'update'])->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:20,1')->name('bring.update');
Route::get('/partage/recette/{token}/photo/{size}', [\App\Http\Controllers\SharedRecipeController::class, 'photo'])->where('token', '[A-Za-z0-9]{32,64}')
    ->whereIn('size', ['large', 'thumb'])->middleware('throttle:120,1')->name('share.recipe.photo');

// Page servie par le service worker quand il n'y a pas de réseau (20.2) : elle doit rester accessible sans session.
Route::view('/hors-ligne', 'offline')->name('offline');

/*
|--------------------------------------------------------------------------
| Application (connexion obligatoire)
|--------------------------------------------------------------------------
*/
// Invitation dans un foyer (lot 24, 25.2) : accessible avec ou sans compte.
// Lot 25 (27.5) : essais limités, comme pour la connexion.
Route::livewire('/invitation/{token}', Livewire\Households\AcceptInvitation::class)->where('token', '[A-Za-z0-9]{20,100}')
    ->middleware('throttle:30,1')->name('invitation.show');

Route::middleware(['auth', 'two.factor', 'household'])->group(function () {
    Route::livewire('/', Livewire\Dashboard::class)->name('dashboard');
    // Lot 37 (37.1) : le rendez-vous du soir.
    Route::livewire('/ce-soir', Livewire\Evening::class)->name('evening');

    // Mon compte (lot 25) : profil, mot de passe, double authentification, appareils.
    Route::livewire('/compte', Livewire\Account\Show::class)->name('account.show');
    Route::livewire('/compte/raccourcis', Livewire\Account\Shortcuts::class)->name('account.shortcuts');

    // Entre foyers (lot 26 : module 26, R31, C4).
    Route::livewire('/proches', Livewire\Linked\Index::class)->name('linked.index');
    Route::livewire('/proches/relier/{token}', Livewire\Linked\Accept::class)->where('token', '[A-Za-z0-9]{20,100}')
        ->middleware('throttle:30,1')->name('linked.accept');
    Route::livewire('/proches/{household}/planning', Livewire\Linked\Planning::class)->whereNumber('household')->name('linked.planning');
    Route::livewire('/proches/repas/{row}', Livewire\Linked\SharedMeal::class)->whereNumber('row')->name('linked.meal');
    Route::livewire('/proches/listes/{list}', Livewire\Linked\GroupList::class)->whereNumber('list')->name('linked.list');
    Route::livewire('/surplus', Livewire\Linked\SurplusPage::class)->name('linked.surplus');
    Route::livewire('/carnet-familial', Livewire\Linked\FamilyBookPage::class)->name('linked.book');
    Route::get('/carnet-familial/imprimer', [PrintController::class, 'familyBook'])->name('linked.book.print');

    // Foyers (lot 24) : changer de foyer, aucun foyer, administration de l'installation.
    Route::post('/foyer/{household}/choisir', HouseholdSwitchController::class)->whereNumber('household')->name('households.switch');
    Route::view('/sans-foyer', 'households.none')->name('no-household');
    Route::livewire('/administration', Livewire\Admin\Households::class)->middleware('admin')->name('admin.households');

    Route::prefix('recettes')->name('recipes.')->group(function () {
        Route::livewire('/', Livewire\Recipes\Index::class)->name('index');
        Route::livewire('/nouvelle', Livewire\Recipes\Edit::class)->middleware('can.edit')->name('create');
        Route::livewire('/importer', Livewire\Recipes\Import::class)->middleware('can.edit')->name('import');
        // Lot 38 (38.3) : « À trier », avant « /{recipe} ».
        Route::livewire('/a-trier', Livewire\Recipes\Inbox::class)->name('inbox');
        Route::get('/exporter', RecipeExportController::class)->name('export');
        // Collections (31.1) : avant « /{recipe} ».
        Route::livewire('/collections', Livewire\Recipes\Collections::class)->name('collections');
        Route::livewire('/collections/{collection}', Livewire\Recipes\CollectionShow::class)->whereNumber('collection')->name('collections.show');
        Route::livewire('/{recipe}', Livewire\Recipes\Show::class)->name('show');
        Route::livewire('/{recipe}/historique', Livewire\Recipes\History::class)->name('history');
        Route::livewire('/{recipe}/modifier', Livewire\Recipes\Edit::class)->middleware('can.edit')->name('edit');
        Route::livewire('/{recipe}/cuisiner', Livewire\Recipes\Cook::class)->name('cook');
        Route::livewire('/{recipe}/variantes', Livewire\Recipes\Variants::class)->middleware('can.edit')->name('variants');
        Route::get('/{recipe}/imprimer', [PrintController::class, 'recipe'])->name('print');
        Route::get('/{recipe:id}/photo/{size}', RecipePhotoController::class)
            ->whereNumber('recipe')->whereIn('size', ['large', 'thumb'])->name('photo');
        Route::get('/{recipe:id}/photos/{photo}/{size}', [RecipePhotoController::class, 'extra'])
            ->whereNumber(['recipe', 'photo'])->whereIn('size', ['large', 'thumb'])->name('photos.show');
    });

    Route::livewire('/planning', Livewire\Planner\Week::class)->name('planner.week');
    Route::livewire('/planning/remplir', Livewire\Planner\FillWeek::class)->middleware('can.edit')->name('planner.fill');
    Route::livewire('/planning/semaines-types', Livewire\Planner\Templates::class)->middleware('can.edit')->name('planner.templates');
    Route::get('/planning/imprimer', [PrintController::class, 'menu'])->name('planner.print');
    Route::get('/planning/gamelles', [PrintController::class, 'lunchboxes'])->name('planner.lunchboxes');
    // Lot 39 : la cantine du midi (39.2) et le choix des enfants (39.3).
    Route::livewire('/planning/cantine', Livewire\Planner\Canteen::class)->name('planner.canteen');
    Route::livewire('/planning/choix-des-enfants', Livewire\Planner\ChildChoices::class)->middleware('can.edit')->name('planner.choices');
    // Cuisiner un repas complet (31.3) : les étapes de tous les plats de la case, entrelacées.
    Route::livewire('/planning/repas/{date}/{slot}/cuisiner', Livewire\Planner\CookMeal::class)
        ->where('date', '\d{4}-\d{2}-\d{2}')->whereNumber('slot')->name('planner.cook');
    Route::livewire('/planning/cuisiner-en-avance', Livewire\Planner\BatchCook::class)->middleware('can.edit')->name('planner.batch');
    // Séjours et grandes tablées (lot 34).
    Route::livewire('/sejours', Livewire\Stays\Index::class)->name('stays.index');
    Route::livewire('/sejours/{stay}', Livewire\Stays\Show::class)->whereNumber('stay')->name('stays.show');

    // Écran de cuisine, statistiques et année en cuisine (lot 35).
    Route::livewire('/cuisine', Livewire\Kitchen\Screen::class)->name('kitchen');
    // Lot 39 (39.3) : le choix des enfants, en grand, sur l'écran de cuisine.
    Route::livewire('/cuisine/choix', Livewire\Kitchen\Choice::class)->name('kitchen.choice');
    Route::livewire('/planning/statistiques', Livewire\Planner\Statistics::class)->name('planner.stats');
    Route::livewire('/planning/annee/{year?}', Livewire\Planner\YearInReview::class)->where('year', '20[0-9]{2}')->name('planner.year');

    // Réceptions (lot 20 : module 21).
    Route::livewire('/receptions', Livewire\Receptions\Index::class)->name('receptions.index');
    Route::livewire('/receptions/{occasion}', Livewire\Receptions\Show::class)->whereNumber('occasion')->name('receptions.show');
    Route::get('/receptions/{occasion}/menu', [ReceptionController::class, 'menu'])->whereNumber('occasion')->name('receptions.menu');
    Route::get('/receptions/{occasion}/photo/{size}', [ReceptionController::class, 'photo'])
        ->whereNumber('occasion')->whereIn('size', ['large', 'thumb'])->name('receptions.photo');
    Route::livewire('/invites', Livewire\Guests\Index::class)->name('guests.index');
    Route::livewire('/invites/{guest}', Livewire\Guests\Show::class)->whereNumber('guest')->name('guests.show');
    Route::livewire('/stock', Livewire\Stock\Index::class)->name('stock.index');
    Route::livewire('/stock/inventaire/{location}', Livewire\Stock\Inventory::class)->middleware('can.edit')->name('stock.inventory');
    Route::livewire('/que-cuisiner', Livewire\Stock\Suggestions::class)->name('suggestions');

    // Stock sans saisie (lot 18) : scan, congélateur, historique et statistiques.
    Route::livewire('/stock/scanner', Livewire\Stock\Scan::class)->middleware('can.edit')->name('stock.scan');
    Route::livewire('/stock/congelateur', Livewire\Stock\Freezer::class)->name('stock.freezer');
    Route::livewire('/stock/historique', Livewire\Stock\History::class)->name('stock.history');
    // Lot 30 : inventaire par ancienneté (30.8), journal du foyer (30.2).
    Route::livewire('/stock/revue', Livewire\Stock\Review::class)->middleware('can.edit')->name('stock.review');
    Route::livewire('/foyer/journal', Livewire\Journal::class)->name('journal');
    // Lot 38 (38.2) : cible de partage d'Android (manifeste) — une adresse ou un texte partagé arrive dans « À trier ».
    Route::get('/partager', \App\Http\Controllers\ShareTargetController::class)->middleware('throttle:30,1')->name('share.target');
    // Lot 41 (41.2) : recettes à garder dans le téléphone pour cuisiner sans réseau.
    Route::get('/sans-reseau', [\App\Http\Controllers\OfflineRecipesController::class, 'week'])->name('offline.week');
    Route::get('/sans-reseau/sejour/{stay}', [\App\Http\Controllers\OfflineRecipesController::class, 'stay'])->whereNumber('stay')->name('offline.stay');
    Route::get('/sans-reseau/recette/{recipe}', [\App\Http\Controllers\OfflineRecipesController::class, 'recipe'])->name('offline.recipe');
    // Lot 41 (41.1) : minuteurs partagés entre les appareils du foyer.
    Route::get('/minuteurs', [\App\Http\Controllers\KitchenTimerController::class, 'index'])->middleware('throttle:240,1')->name('timers.index');
    Route::post('/minuteurs', [\App\Http\Controllers\KitchenTimerController::class, 'store'])->middleware('throttle:60,1')->name('timers.store');
    Route::post('/minuteurs/{timer}/arreter', [\App\Http\Controllers\KitchenTimerController::class, 'stop'])->whereNumber('timer')->middleware('throttle:60,1')->name('timers.stop');
    Route::post('/minuteurs/{timer}/sonne', [\App\Http\Controllers\KitchenTimerController::class, 'rang'])->whereNumber('timer')->middleware('throttle:60,1')->name('timers.rang');
    Route::post('/aide/{key}', \App\Http\Controllers\HintController::class)->where('key', '[a-z-]+')->name('hints.seen');
    Route::get('/stock/etiquettes', [PrintController::class, 'labels'])->name('stock.labels');
    // Dépenses et budget réel (lot 22 : module 23).
    Route::livewire('/budget', Livewire\Budget\Index::class)->name('budget.index');
    Route::livewire('/budget/analyses', Livewire\Budget\Analyses::class)->name('budget.analyses');
    Route::livewire('/prix', Livewire\Prices\Index::class)->name('prices.index');   // lot 27 : prix et magasins (C1, C2)
    Route::get('/budget/export', ExpenseExportController::class)->name('budget.export');

    // Tickets de caisse lus automatiquement (lot 23 : module 24).
    Route::livewire('/tickets', Livewire\Receipts\Index::class)->name('receipts.index');
    Route::livewire('/tickets/nouveau', Livewire\Receipts\Capture::class)->middleware('can.edit')->name('receipts.create');
    Route::livewire('/tickets/{receipt}', Livewire\Receipts\Show::class)->whereNumber('receipt')->name('receipts.show');
    Route::get('/tickets/{receipt}/photo/{index}', ReceiptPhotoController::class)->whereNumber(['receipt', 'index'])->name('receipts.photo');

    Route::livewire('/courses', Livewire\Shopping\Index::class)->name('shopping.index');
    Route::livewire('/courses/{shoppingList}/ranger', Livewire\Stock\PutAway::class)->whereNumber('shoppingList')->middleware('can.edit')->name('shopping.put-away');
    Route::livewire('/courses/{shoppingList}', Livewire\Shopping\Show::class)->whereNumber('shoppingList')->name('shopping.show');

    // Mode magasin et liste hors-ligne (lot 16) : pages et API sans Livewire.
    Route::get('/courses/{shoppingList}/magasin', [StoreModeController::class, 'show'])->whereNumber('shoppingList')->name('shopping.store');
    Route::get('/courses/{shoppingList}/etat', [StoreModeController::class, 'state'])->whereNumber('shoppingList')->name('shopping.state');
    Route::post('/courses/{shoppingList}/synchroniser', [StoreModeController::class, 'sync'])->whereNumber('shoppingList')->name('shopping.sync');
    // Lot 42 (42.3) : courses à deux, chacun ses rayons.
    Route::post('/courses/{shoppingList}/rayons', [StoreModeController::class, 'aisles'])->whereNumber('shoppingList')->middleware('throttle:60,1')->name('shopping.aisles');

    Route::prefix('parametres')->name('settings.')->group(function () {
        Route::livewire('/', Livewire\Settings\Index::class)->name('index');
        Route::livewire('/ingredients', Livewire\Settings\Ingredients::class)->middleware('can.edit')->name('ingredients');
        Route::livewire('/rayons', Livewire\Settings\Aisles::class)->middleware('can.edit')->name('aisles');
        Route::livewire('/unites', Livewire\Settings\Units::class)->middleware('can.edit')->name('units');
        Route::livewire('/categories', Livewire\Settings\Tags::class)->middleware('can.edit')->name('tags');
        Route::livewire('/creneaux', Livewire\Settings\MealSlots::class)->middleware('can.edit')->name('slots');
        Route::livewire('/emplacements', Livewire\Settings\StorageLocations::class)->middleware('can.edit')->name('locations');
        Route::livewire('/stock', Livewire\Settings\StockSettings::class)->middleware('can.edit')->name('stock');
        Route::livewire('/affichage', Livewire\Settings\Display::class)->name('display');
        Route::livewire('/planning', Livewire\Settings\Planning::class)->middleware('can.edit')->name('planning');
        Route::livewire('/foyer', Livewire\Settings\Household::class)->name('household');
        Route::livewire('/ingredients/doublons', Livewire\Settings\IngredientDuplicates::class)->middleware('can.edit')->name('ingredient-duplicates');
        // Lot 40 : remplacements (40.1) et équipement de la cuisine (40.3).
        Route::livewire('/remplacements', Livewire\Settings\Substitutions::class)->middleware('can.edit')->name('substitutions');
        Route::livewire('/equipement', Livewire\Settings\Equipment::class)->middleware('can.edit')->name('equipment');

        // Notifications (lot 20 : 19.2, 19.3, 19.4) — chacun règle les siennes.
        Route::livewire('/notifications', Livewire\Settings\Notifications::class)->name('notifications');

        // Saisons et nutrition (lot 19 : 17.1, 17.4).
        Route::livewire('/saisons', Livewire\Settings\Seasons::class)->middleware('can.edit')->name('seasons');
        Route::livewire('/nutrition', Livewire\Settings\Nutrition::class)->middleware('can.edit')->name('nutrition');

        // Magasins et budget (lot 17 : 15.3, 17.3).
        Route::livewire('/magasins', Livewire\Settings\Stores::class)->middleware('can.edit')->name('stores');
        Route::livewire('/budget', Livewire\Settings\BudgetSettings::class)->middleware('can.edit')->name('budget');
        Route::livewire('/tickets', Livewire\Settings\ReceiptSettings::class)->middleware('can.edit')->name('receipts');
        // Assistant culinaire (lot 33, 33.5) : réglages d'installation, modifiables par l'administrateur.
        Route::livewire('/assistant', Livewire\Settings\AssistantSettings::class)->middleware('can.edit')->name('assistant');

        // Diagnostic, À propos et export des données (lot 16 : 20.6, 20.7, 20.8).
        Route::livewire('/diagnostic', Livewire\Settings\SystemCheck::class)->middleware('admin')->name('diagnostic');
        Route::get('/diagnostic/export', DataExportController::class)->middleware('household.owner')->name('export');
        Route::livewire('/articles-recurrents', Livewire\Settings\RecurringItems::class)->middleware('can.edit')->name('recurring');
        // Sauvegarde de toute l'installation : réservée à son administrateur (R29).
        Route::livewire('/sauvegardes', Livewire\Settings\Backups::class)->middleware('admin')->name('backups');
        Route::get('/sauvegardes/{filename}', BackupDownloadController::class)->middleware('admin')
            ->where('filename', 'bouffe-[0-9_-]+-(manual|auto|restore)\.zip')->name('backups.download');
        Route::livewire('/telephone', Livewire\Settings\PhoneAccess::class)->middleware('can.edit')->name('phone');
        // Mise en ligne (lot 25, module 27) : tâches, surveillance, sécurité — administrateur.
        Route::livewire('/mise-en-ligne', Livewire\Settings\Online::class)->middleware('admin')->name('online');
    });

    // Notifications sur le téléphone (lot 20 : 19.2).
    Route::get('/notifications/cle', [PushSubscriptionController::class, 'key'])->name('push.key');
    Route::post('/notifications/abonnement', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('/notifications/desabonnement', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    Route::post('/deconnexion', LogoutController::class)->name('logout');
});
