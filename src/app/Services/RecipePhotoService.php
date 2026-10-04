<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enregistrement des photos de recettes.
 *
 * Chaque photo est redimensionnée en deux JPEG (grand format + vignette) sur le disque « local »
 * (storage/app/private/recipes) : pas de lien symbolique à créer sous Windows, et les photos
 * ne sont servies qu'aux utilisateurs connectés (RecipePhotoController).
 *
 * photo_path contient la base du nom, sans suffixe : « recipes/9b1c… »
 *   → recipes/9b1c….jpg        (large)
 *   → recipes/9b1c…-thumb.jpg  (thumb)
 */
class RecipePhotoService
{
    public const SIZES = ['large', 'thumb'];

    /**
     * @param  string  $folder  sous-dossier de `recipes/` (les souvenirs de réception vont dans `recipes/receptions`,
     *                          pour être inclus dans les sauvegardes avec les photos de recettes)
     */
    public function store(UploadedFile $file, ?Recipe $recipe = null, string $folder = 'recipes'): string
    {
        $base = $folder.'/'.Str::uuid();

        // L'original (photo de téléphone : 12 Mpx = ~48 Mo en mémoire) est libéré dès que le grand format
        // existe, et la vignette est tirée de ce grand format : deux images en mémoire au maximum.
        $source = $this->load($file->getRealPath());

        try {
            $large = $this->resize($source, (int) config('bouffe.photos.large_width', 1600));
        } finally {
            imagedestroy($source);
        }

        try {
            $this->write($large, $this->path($base, 'large'));

            $thumb = $this->resize($large, (int) config('bouffe.photos.thumb_width', 640));
        } finally {
            imagedestroy($large);
        }

        try {
            $this->write($thumb, $this->path($base, 'thumb'));
        } finally {
            imagedestroy($thumb);
        }

        if ($recipe?->photo_path) {
            $this->delete($recipe->photo_path);
        }

        return $base;
    }

    /** Copie les fichiers d'une photo (duplication de recette). */
    public function copy(string $base): string
    {
        $new = 'recipes/'.Str::uuid();

        foreach (self::SIZES as $size) {
            if (Storage::disk('local')->exists($this->path($base, $size))) {
                Storage::disk('local')->copy($this->path($base, $size), $this->path($new, $size));
            }
        }

        return $new;
    }

    public function delete(?string $base): void
    {
        if (! $base) {
            return;
        }

        Storage::disk('local')->delete(array_map(fn (string $size) => $this->path($base, $size), self::SIZES));
    }

    public function path(string $base, string $size): string
    {
        return $size === 'thumb' ? "{$base}-thumb.jpg" : "{$base}.jpg";
    }

    /**
     * Image décodée, orientation EXIF appliquée, taille contrôlée (sert aussi aux tickets, lot 23).
     *
     * @return \GdImage
     */
    public function load(string $path)
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Image illisible.');
        }

        $isJpeg = str_starts_with($contents, "\xFF\xD8");
        $this->ensureFitsInMemory($contents);

        $image = @imagecreatefromstring($contents);
        unset($contents);   // le fichier entier n'a plus à rester en mémoire pendant le traitement

        if ($image === false) {
            throw new RuntimeException('Image illisible.');
        }

        // Photos de téléphone : appliquer l'orientation EXIF si l'extension exif est disponible.
        if ($isJpeg && function_exists('exif_read_data')) {
            $angle = match ((int) (@exif_read_data($path)['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            // imagerotate renvoie une NOUVELLE image : l'ancienne doit être libérée tout de suite.
            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    /**
     * Une image décompressée occupe 4 octets par pixel : une photo de 50 Mpx demande 200 Mo.
     * Mieux vaut un message clair qu'un « Allowed memory size exhausted » au milieu du traitement.
     */
    private function ensureFitsInMemory(string $contents): void
    {
        $size = @getimagesizefromstring($contents);
        $maxPixels = $this->maxPixels();

        if (! $size || $maxPixels === 0) {
            return;
        }

        $pixels = (int) $size[0] * (int) $size[1];

        if ($pixels > $maxPixels) {
            $mpx = round($pixels / 1_000_000, 1);
            $max = round($maxPixels / 1_000_000, 1);

            throw new RuntimeException(
                "Photo trop grande pour la mémoire disponible ({$mpx} Mpx, maximum {$max} Mpx) : "
                .'réduisez-la ou augmentez « memory_limit » dans php.ini.'
            );
        }
    }

    /** Nombre de pixels traitables : réglage explicite, sinon d'après le memory_limit ; 0 = sans limite. */
    private function maxPixels(): int
    {
        $configured = (float) config('bouffe.photos.max_megapixels', 0);

        if ($configured > 0) {
            return (int) round($configured * 1_000_000);
        }

        $limit = $this->memoryLimit();

        if ($limit === 0) {
            return 0;
        }

        // 4 octets par pixel, pour l'original et le grand format, en gardant de la marge.
        return (int) max(1, ($limit - memory_get_usage(true)) * 0.6 / 4);
    }

    /** memory_limit en octets ; 0 = illimité. */
    private function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        return match (strtolower(substr($value, -1))) {
            'g' => (int) $value * 1024 ** 3,
            'm' => (int) $value * 1024 ** 2,
            'k' => (int) $value * 1024,
            default => (int) $value,
        };
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage nouvelle image, jamais plus large que $maxWidth (pas d'agrandissement)
     */
    private function resize($image, int $maxWidth)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $targetWidth = max(1, min($width, $maxWidth));
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            throw new RuntimeException('Image trop grande pour être traitée.');
        }

        // Fond blanc pour les PNG transparents
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /** @param \GdImage $image */
    private function write($image, string $relativePath): void
    {
        ob_start();
        imagejpeg($image, null, (int) config('bouffe.photos.quality', 82));
        $jpeg = ob_get_clean();

        Storage::disk('local')->put($relativePath, $jpeg);
        unset($jpeg);
    }
}
