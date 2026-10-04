<?php

use App\Models\Recipe;
use App\Models\User;
use App\Services\Backup\Backup;
use App\Services\Backup\BackupManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    $this->backupDir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $this->backupDir, 'bouffe.backups.keep' => 3, 'bouffe.backups.auto_days' => 7]);
    Storage::fake('local');
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
    Carbon::setTestNow();
});

it('crée une archive avec la base, les photos et un manifeste', function () {
    Storage::disk('local')->put('recipes/abc.jpg', 'photo');
    Storage::disk('local')->put('recipes/abc-thumb.jpg', 'vignette');

    $backup = app(BackupManager::class)->create();

    expect($backup->filename)->toMatch(Backup::PATTERN)
        ->and($backup->type)->toBe('manual')
        ->and($backup->manifest['driver'])->toBe(DB::connection()->getDriverName())
        ->and($backup->manifest['rows']['recipes'])->toBe(Recipe::count())
        ->and($backup->manifest['rows']['sessions'])->toBe(0)
        ->and($backup->manifest['photos'])->toBe(2)
        ->and($backup->summary())->toContain(Recipe::count().' recettes')->toContain('2 photos');

    $zip = new ZipArchive;
    $zip->open($backup->path);
    expect($zip->getFromName('photos/recipes/abc.jpg'))->toBe('photo')
        ->and($zip->getFromName('database.sql'))->toContain('CREATE TABLE');
});

it('restaure les données, textes difficiles compris, et les photos', function () {
    $recipe = Recipe::first();
    $tricky = "Couper; l'oignon \"émincé\" \\ puis\n-- mélanger ; 50 % — ½ c. à café 🍅";
    $recipe->steps()->create(['position' => 99, 'instruction' => $tricky]);
    Storage::disk('local')->put('recipes/garde.jpg', 'photo gardée');

    $manager = app(BackupManager::class);
    $backup = $manager->create();

    // Modifications après la sauvegarde
    $recipe->update(['title' => 'Titre modifié']);
    Recipe::whereKeyNot($recipe->id)->limit(2)->get()->each->delete();
    User::factory()->create(['email' => 'intrus@example.com']);
    Storage::disk('local')->delete('recipes/garde.jpg');
    Storage::disk('local')->put('recipes/nouvelle.jpg', 'x');
    $recipesBefore = $backup->manifest['rows']['recipes'];

    $result = $manager->restore($backup->filename);

    expect(Recipe::count())->toBe($recipesBefore)
        ->and(Recipe::find($recipe->id)->title)->not->toBe('Titre modifié')
        ->and($recipe->steps()->where('position', 99)->value('instruction'))->toBe($tricky)
        ->and(User::where('email', 'intrus@example.com')->exists())->toBeFalse()
        ->and(Storage::disk('local')->get('recipes/garde.jpg'))->toBe('photo gardée')
        ->and(Storage::disk('local')->exists('recipes/nouvelle.jpg'))->toBeFalse()
        ->and($result['safety']->type)->toBe('restore')
        ->and($result['statements'])->toBeGreaterThan(10);

    // L'application fonctionne toujours après restauration (contraintes, auto-incréments)
    $new = Recipe::factory()->create();
    expect($new->id)->toBeGreaterThan($recipe->id);
});

it('garde un filet de sécurité qui permet d\'annuler la restauration', function () {
    $manager = app(BackupManager::class);
    $old = $manager->create();

    Carbon::setTestNow(now()->addSecond());
    Recipe::factory()->create(['title' => 'Recette ajoutée après']);

    $safety = $manager->restore($old->filename)['safety'];
    expect(Recipe::where('title', 'Recette ajoutée après')->exists())->toBeFalse();

    $manager->restore($safety->filename, safetyBackup: false);
    expect(Recipe::where('title', 'Recette ajoutée après')->exists())->toBeTrue();
});

it('refuse une sauvegarde venant d\'un autre type de base', function () {
    $manager = app(BackupManager::class);
    $backup = $manager->create();

    $zip = new ZipArchive;
    $zip->open($backup->path);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $manifest['driver'] = DB::connection()->getDriverName() === 'sqlite' ? 'mariadb' : 'sqlite';
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();

    expect(fn () => $manager->restore($backup->filename))->toThrow(RuntimeException::class, 'Cette sauvegarde vient');
    expect(Recipe::count())->toBeGreaterThan(0);
});

it('liste les sauvegardes de la plus récente à la plus ancienne et ignore les autres fichiers', function () {
    $manager = app(BackupManager::class);
    Carbon::setTestNow('2026-09-10 10:00:00');
    $manager->create();
    Carbon::setTestNow('2026-09-12 10:00:00');
    $manager->create('auto');
    File::put($this->backupDir.'/notes.txt', 'x');
    File::put($this->backupDir.'/bouffe-2026-01-01_000000-other.zip', 'x');

    expect($manager->all()->pluck('type')->all())->toBe(['auto', 'manual'])
        ->and($manager->latest()->createdAt->toDateString())->toBe('2026-09-12')
        ->and($manager->find('../.env'))->toBeNull();
});

it('ne garde que les dernières sauvegardes automatiques', function () {
    $manager = app(BackupManager::class);

    Carbon::setTestNow('2026-09-01 08:00:00');
    $manager->create('manual');

    foreach (range(1, 5) as $day) {
        Carbon::setTestNow("2026-09-0{$day} 09:00:00");
        $manager->create('auto');
    }

    expect($manager->all()->where('type', 'auto'))->toHaveCount(3)
        ->and($manager->all()->where('type', 'manual'))->toHaveCount(1)
        ->and($manager->all()->where('type', 'auto')->last()->createdAt->day)->toBe(3);
});

it('sait quand une sauvegarde automatique est nécessaire', function () {
    $manager = app(BackupManager::class);
    expect($manager->needsAutomaticBackup())->toBeTrue();

    Carbon::setTestNow('2026-09-01 09:00:00');
    $manager->create();

    Carbon::setTestNow('2026-09-07 09:00:00');
    expect($manager->needsAutomaticBackup())->toBeFalse();

    Carbon::setTestNow('2026-09-08 09:00:01');
    expect($manager->needsAutomaticBackup())->toBeTrue();

    config(['bouffe.backups.auto_days' => 0]);
    expect($manager->needsAutomaticBackup())->toBeFalse();
});
