<?php

use App\Models\User;
use App\Services\Backup\BackupManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Lot 25 (27.8, 27.9) : la sauvegarde emporte aussi les tickets de caisse, pour déménager chez OVH
 * sans rien perdre.
 */

beforeEach(function () {
    $this->seed();
    User::factory()->create();
});

test('les tickets de caisse sont dans la sauvegarde et reviennent à la restauration', function () {
    $dir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $dir]);
    Storage::fake('local');
    Storage::disk('local')->put('receipts/2026/10/ticket.jpg', 'ticket');
    Storage::disk('local')->put('recipes/tarte.jpg', 'tarte');

    $backup = app(BackupManager::class)->create();
    expect($backup->manifest['photos'])->toBe(2);

    Storage::disk('local')->delete('receipts/2026/10/ticket.jpg');
    app(BackupManager::class)->restore($backup->filename, safetyBackup: false);

    expect(Storage::disk('local')->get('receipts/2026/10/ticket.jpg'))->toBe('ticket');
    File::deleteDirectory($dir);
});
