<?php

namespace App\Services\Receipts\Readers;

use App\Services\Receipts\ReceiptReading;

/**
 * Service de lecture de documents (§7.3) : Mistral ou Azure, derrière la même interface.
 * Les tests n'appellent jamais le réseau (réponses simulées par Http::fake).
 */
interface ReceiptReader
{
    /** mistral · azure */
    public function provider(): string;

    public function label(): string;

    /** Clé (et point de terminaison) présents. */
    public function configured(): bool;

    /**
     * Lit un ticket : un JPEG ou un PDF (plusieurs photos sont réunies en un PDF).
     *
     * @throws \App\Services\Receipts\ReadingFailed
     */
    public function read(string $path, string $mime): ReceiptReading;

    /**
     * Texte brut d'une page (recette depuis une photo, C3).
     *
     * @return array{text: string, pages: int}
     *
     * @throws \App\Services\Receipts\ReadingFailed
     */
    public function readText(string $path, string $mime): array;
}
