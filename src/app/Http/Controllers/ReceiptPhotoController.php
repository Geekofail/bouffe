<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Photo ou PDF d'un ticket (24.8) : servi depuis le disque privé, jamais par une adresse publique.
 */
class ReceiptPhotoController extends Controller
{
    public function __invoke(Receipt $receipt, int $index): BinaryFileResponse
    {
        $path = ((array) $receipt->photo_paths)[$index] ?? null;

        abort_if(! $path || $receipt->photos_deleted_at || ! Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => str_ends_with($path, '.pdf') ? 'application/pdf' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
