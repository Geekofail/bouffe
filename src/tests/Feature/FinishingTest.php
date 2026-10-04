<?php

use App\Enums\ListStatus;
use App\Livewire\Settings\PhoneAccess;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Shopping\ShoppingListManager;
use App\Support\NetworkAddresses;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
});

test('la page d\'accès téléphone affiche l\'adresse, le QR code et la configuration Apache', function () {
    $this->mock(NetworkAddresses::class, function ($mock) {
        $mock->shouldReceive('localIpv4')->andReturn(['192.168.1.42', '172.28.0.1']);
        $mock->shouldReceive('isLan')->andReturn(false);
    });

    Livewire::test(PhoneAccess::class)
        ->assertSee('http://192.168.1.42')
        ->assertSee('http://172.28.0.1')
        ->assertSee('bouffeQrCode', false)
        ->assertSee('ServerAlias 192.168.1.42')
        ->assertSee('Require ip 192.168.1')
        ->assertDontSee('utilise déjà Bouffe par le réseau local');
});

test('la page d\'accès téléphone confirme quand on l\'ouvre depuis le réseau', function () {
    $this->mock(NetworkAddresses::class, function ($mock) {
        $mock->shouldReceive('localIpv4')->andReturn([]);
        $mock->shouldReceive('isLan')->andReturn(true);
    });

    Livewire::test(PhoneAccess::class)
        ->assertSee('utilise déjà Bouffe par le réseau local')
        ->assertSee('ipconfig');
});

test('les pages déclarent le manifeste et l\'icône pour l\'écran d\'accueil', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('apple-touch-icon.png', false)
        ->assertSee('bouffe-connection', false);

    auth()->logout();
    $this->get(route('login'))->assertSee('manifest.webmanifest', false);

    expect(json_decode(file_get_contents(public_path('manifest.webmanifest')), true)['short_name'])->toBe('Bouffe')
        ->and(file_exists(public_path('icon-512.png')))->toBeTrue();
});

test('l\'accueil propose des recettes oubliées et un raccourci nouvelle recette', function () {
    $this->seed(DatabaseSeeder::class);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ça fait longtemps')
        ->assertSee('Jamais planifiée')
        ->assertSee('Nouvelle recette');
});

test('l\'accueil signale une sauvegarde automatique en retard', function () {
    $dir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $dir, 'bouffe.backups.auto_days' => 7]);
    \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
    \Illuminate\Support\Facades\File::put($dir.'/bouffe-'.now()->subDays(12)->format('Y-m-d_His').'-auto.zip', 'x');

    $this->get(route('dashboard'))->assertSee('Dernière sauvegarde il y a');

    \Illuminate\Support\Facades\File::deleteDirectory($dir);
});

test('les listes en cours terminées depuis plus de 30 jours sont classées automatiquement', function () {
    $today = Carbon::parse('2026-09-16');
    $old = ShoppingList::create(['name' => 'Vieille', 'period_start' => '2026-08-01', 'period_end' => '2026-08-07', 'status' => ListStatus::Active]);
    $recent = ShoppingList::create(['name' => 'Récente', 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => ListStatus::Active]);

    expect(app(ShoppingListManager::class)->closeStaleLists(30, $today))->toBe(1)
        ->and($old->fresh()->status)->toBe(ListStatus::Done)
        ->and($recent->fresh()->status)->toBe(ListStatus::Active);
});

test('les paramètres donnent accès aux sauvegardes, au téléphone et affichent la version', function () {
    config(['bouffe.backups.path' => storage_path('framework/testing/vide-'.uniqid())]);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('Sauvegardes')
        ->assertSee('Accès téléphone')
        ->assertSee('Bouffe 1.0');
});
