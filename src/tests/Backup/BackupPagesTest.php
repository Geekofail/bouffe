<?php

use App\Livewire\Settings\Backups;
use App\Models\User;
use App\Services\Backup\BackupManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->backupDir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $this->backupDir, 'bouffe.backups.auto_days' => 0]);
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
});

afterEach(fn () => File::deleteDirectory($this->backupDir));

test('la page des sauvegardes invite à créer la première', function () {
    $this->get(route('settings.backups'))
        ->assertOk()
        ->assertSee('Aucune sauvegarde pour l\'instant', false)
        ->assertSee('php artisan bouffe:restore');
});

test('on crée, télécharge et supprime une sauvegarde depuis l\'écran', function () {
    Livewire::test(Backups::class)
        ->call('create')
        ->assertDispatched('notify')
        ->assertSee('Manuelle');

    $backup = app(BackupManager::class)->all()->first();

    $this->get(route('settings.backups.download', $backup->filename))
        ->assertOk()
        ->assertDownload($backup->filename);

    Livewire::test(Backups::class)
        ->call('confirmDelete', $backup->filename)
        ->assertSet('deleting', $backup->filename)
        ->call('delete')
        ->assertSet('deleting', null);

    expect(app(BackupManager::class)->all())->toBeEmpty();
});

test('un nom de fichier non conforme n\'est ni téléchargé ni supprimé', function () {
    File::ensureDirectoryExists($this->backupDir);
    File::put($this->backupDir.'/secret.zip', 'x');

    $this->get('/parametres/sauvegardes/secret.zip')->assertNotFound();
    $this->get('/parametres/sauvegardes/bouffe-2026-01-01_000000-manual.zip')->assertNotFound();

    Livewire::test(Backups::class)->call('confirmDelete', '../secret.zip')->assertSet('deleting', null);
    expect(File::exists($this->backupDir.'/secret.zip'))->toBeTrue();
});

test('les pages de sauvegarde demandent une connexion', function () {
    auth()->logout();

    $this->get(route('settings.backups'))->assertRedirect(route('login'));
    $this->get('/parametres/sauvegardes/bouffe-2026-01-01_000000-manual.zip')->assertRedirect(route('login'));
});

test('bouffe:backup crée une sauvegarde manuelle', function () {
    $this->artisan('bouffe:backup')->expectsOutputToContain('Sauvegarde créée')->assertSuccessful();
    $this->artisan('bouffe:backup --auto')->assertSuccessful();

    expect(app(BackupManager::class)->all()->pluck('type')->sort()->values()->all())->toBe(['auto', 'manual']);
});

test('bouffe:restore restaure après confirmation et sans question avec --force', function () {
    $this->artisan('bouffe:backup')->assertSuccessful();
    $filename = app(BackupManager::class)->all()->first()->filename;
    User::factory()->create(['email' => 'apres@example.com']);

    $this->artisan('bouffe:restore', ['fichier' => $filename])
        ->expectsConfirmation('Restaurer cette sauvegarde ?', 'no')
        ->expectsOutputToContain('Restauration annulée')
        ->assertSuccessful();
    expect(User::where('email', 'apres@example.com')->exists())->toBeTrue();

    Carbon::setTestNow(now()->addSecond());
    $this->artisan('bouffe:restore', ['fichier' => $filename, '--force' => true])
        ->expectsOutputToContain('Sauvegarde restaurée')
        ->assertSuccessful();
    Carbon::setTestNow();

    expect(User::where('email', 'apres@example.com')->exists())->toBeFalse();
});

test('bouffe:restore signale une archive inconnue', function () {
    $this->artisan('bouffe:backup')->assertSuccessful();

    $this->artisan('bouffe:restore', ['fichier' => 'bouffe-2020-01-01_000000-manual.zip', '--force' => true])
        ->expectsOutputToContain('Sauvegarde introuvable')
        ->assertFailed();
});

test('la sauvegarde automatique se déclenche quand la dernière est trop ancienne', function () {
    config(['bouffe.backups.auto_days' => 7]);
    $this->withoutDefer();

    $this->get(route('settings.index'))->assertOk();
    expect(app(BackupManager::class)->all())->toHaveCount(1)
        ->and(app(BackupManager::class)->all()->first()->type)->toBe('auto');

    // Vérifiée au plus une fois par heure
    Cache::flush();
    $this->get(route('settings.index'))->assertOk();
    expect(app(BackupManager::class)->all())->toHaveCount(1);
});
