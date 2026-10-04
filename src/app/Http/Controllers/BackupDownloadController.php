<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupDownloadController
{
    public function __invoke(string $filename, BackupManager $backups): BinaryFileResponse
    {
        $backup = $backups->find($filename);

        abort_unless($backup, 404);

        return response()->download($backup->path, $backup->filename, ['Content-Type' => 'application/zip']);
    }
}
