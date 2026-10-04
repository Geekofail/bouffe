<?php

namespace App\Http\Controllers;

use App\Services\System\DataExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Export de toutes les données (20.8) : une archive à garder ailleurs, lisible sans Bouffe. */
class DataExportController extends Controller
{
    public function __invoke(DataExporter $exporter): BinaryFileResponse
    {
        return response()
            ->download($exporter->toZip(), $exporter->filename(), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
