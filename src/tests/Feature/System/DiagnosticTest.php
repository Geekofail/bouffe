<?php

use App\Livewire\Settings\SystemCheck;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\System\DataExporter;
use App\Services\System\Diagnostic;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Diagnostic (20.7), export des données (20.8), copie hors du PC (20.4), mise à jour (20.6).
 */

beforeEach(function () {
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
});

test('le diagnostic vérifie la plateforme, la base, les fichiers, les sauvegardes et la sécurité', function () {
    $checks = app(Diagnostic::class)->checks();
    $keys = collect($checks)->pluck('key');

    expect($keys)->toContain('php', 'extensions', 'database', 'migrations', 'writable-storage', 'backup', 'backup-mirror', 'app-key', 'debug', 'https')
        ->and(collect($checks)->every(fn ($c) => in_array($c['status'], ['ok', 'warn', 'error'], true)))->toBeTrue();

    // Un point qui ne va pas explique toujours quoi faire.
    expect(collect($checks)->where('status', '!=', 'ok')->every(fn ($c) => filled($c['help'])))->toBeTrue();
});

test('le diagnostic signale une structure de base en retard', function () {
    \Illuminate\Support\Facades\DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

    $migrations = collect(app(Diagnostic::class)->checks())->firstWhere('key', 'migrations');

    expect($migrations['status'])->toBe('error')
        ->and($migrations['help'])->toContain('php artisan migrate');
});

test('le diagnostic prévient quand aucune sauvegarde n\'existe', function () {
    config(['bouffe.backups.path' => base_path('storage/framework/testing/aucune-sauvegarde')]);

    $backup = collect(app(Diagnostic::class)->checks())->firstWhere('key', 'backup');

    expect($backup['status'])->toBe('error')
        ->and($backup['value'])->toBe('Aucune');
});

test('la page Diagnostic affiche le bilan et la commande de mise à jour', function () {
    Livewire::test(SystemCheck::class)
        ->assertOk()
        ->assertSee('Version de PHP')
        ->assertSee('À propos')
        ->assertSee('php artisan bouffe:deploy')
        ->assertSee('Emporter mes données');
});

test('un compte en consultation n\'ouvre pas le diagnostic', function () {
    $this->actingAs(User::factory()->create(['role' => 'viewer']));

    // Un compte en consultation est renvoyé à l'accueil avec un message, pas sur une page d'erreur (18.5).
    // Lot 24 : le diagnostic concerne toute l'installation, il est réservé à son administrateur.
    $this->get(route('settings.diagnostic'))->assertForbidden();
    $this->get(route('settings.export'))->assertRedirect(route('dashboard'));
});

test('l\'export contient les données lisibles et les recettes en texte', function () {
    Recipe::factory()->create(['title' => 'Tarte aux pommes', 'servings' => 6]);

    $exporter = app(DataExporter::class);
    $data = $exporter->data();

    expect($data['application'])->toBe('bouffe')
        ->and(collect($data['recettes'])->pluck('titre'))->toContain('Tarte aux pommes')
        ->and($data)->toHaveKeys(['planning', 'listes_de_courses', 'stock', 'ingredients', 'invites', 'comptes']);

    $zip = $exporter->toZip();
    $archive = new ZipArchive;
    $archive->open($zip);

    expect($archive->locateName('donnees.json'))->not->toBeFalse()
        ->and($archive->locateName('recettes.md'))->not->toBeFalse()
        ->and($archive->getFromName('recettes.md'))->toContain('Tarte aux pommes')
        ->and($archive->getFromName('lisez-moi.txt'))->toContain('n\'est pas une sauvegarde');

    $archive->close();
    File::delete($zip);
});

test('l\'export se télécharge', function () {
    $this->get(route('settings.export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
});

test('20.4 — chaque sauvegarde est recopiée dans le dossier externe', function () {
    Storage::fake('local');

    $backups = base_path('storage/framework/testing/sauvegardes');
    $mirror = base_path('storage/framework/testing/cle-usb');
    File::ensureDirectoryExists($mirror);

    config(['bouffe.backups.path' => $backups, 'bouffe.backups.mirror' => $mirror, 'bouffe.backups.mirror_keep' => 2]);

    $manager = app(BackupManager::class);
    $backup = $manager->create('manual');

    expect(File::exists($mirror.DIRECTORY_SEPARATOR.$backup->filename))->toBeTrue();

    $status = $manager->mirrorStatus();

    expect($status['configured'])->toBeTrue()
        ->and($status['available'])->toBeTrue()
        ->and($status['copies'])->toBe(1);

    File::deleteDirectory($backups);
    File::deleteDirectory($mirror);
});

test('20.4 — un disque débranché ne fait pas échouer la sauvegarde', function () {
    Storage::fake('local');

    $backups = base_path('storage/framework/testing/sauvegardes');
    config(['bouffe.backups.path' => $backups, 'bouffe.backups.mirror' => base_path('storage/framework/testing/disque-absent')]);

    $manager = app(BackupManager::class);
    $backup = $manager->create('manual');

    expect(File::exists($backup->path))->toBeTrue();

    $status = $manager->mirrorStatus();

    expect($status['available'])->toBeFalse()
        ->and($status['error'])->toContain('débranché');

    File::deleteDirectory($backups);
});

test('20.6 — la commande de mise à jour met la base à jour et affiche le diagnostic', function () {
    config(['bouffe.backups.path' => base_path('storage/framework/testing/deploy')]);

    $this->artisan('bouffe:deploy --sans-sauvegarde')
        ->expectsOutputToContain('Diagnostic')
        ->expectsOutputToContain('Version de PHP');

    File::deleteDirectory(base_path('storage/framework/testing/deploy'));
});
