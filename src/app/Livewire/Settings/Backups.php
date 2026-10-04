<?php

namespace App\Livewire\Settings;

use App\Services\Backup\Backup;
use App\Services\Backup\BackupManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sauvegardes')]
class Backups extends Component
{
    public ?string $deleting = null;

    public function create(BackupManager $backups): void
    {
        try {
            $backup = $backups->create();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Sauvegarde impossible : '.$e->getMessage(), type: 'warning');

            return;
        }

        unset($this->backups);
        $this->dispatch('notify', message: "Sauvegarde créée ({$backup->humanSize()}).");
    }

    public function confirmDelete(string $filename): void
    {
        $this->deleting = Backup::isValidFilename($filename) ? $filename : null;
    }

    public function closeDelete(): void
    {
        $this->deleting = null;
    }

    public function delete(BackupManager $backups): void
    {
        if ($this->deleting) {
            try {
                $backups->delete($this->deleting);
                $this->dispatch('notify', message: 'Sauvegarde supprimée.');
            } catch (\Throwable $e) {
                $this->dispatch('notify', message: $e->getMessage(), type: 'warning');
            }
        }

        $this->deleting = null;
        unset($this->backups);
    }

    /** @return Collection<int, Backup> */
    #[Computed]
    public function backups(): Collection
    {
        return app(BackupManager::class)->all();
    }

    public function render(BackupManager $manager)
    {
        $latest = $this->backups->first(fn (Backup $b) => $b->type !== 'restore');
        $autoDays = (int) config('bouffe.backups.auto_days');

        return view('livewire.settings.backups', [
            'latest' => $latest,
            'isOld' => $latest === null || ($autoDays > 0 && $latest->createdAt->lt(now()->subDays($autoDays))),
            'autoDays' => $autoDays,
            'keep' => (int) config('bouffe.backups.keep'),
            'directory' => $manager->directory(),
            'zipAvailable' => $manager->zipAvailable(),
            'mirror' => $manager->mirrorStatus(),
            'mirrorKeep' => (int) config('bouffe.backups.mirror_keep'),
        ]);
    }
}
