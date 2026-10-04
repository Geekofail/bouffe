<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Services\RecipePhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Photos et PDF des tickets (24.1, 24.8), sur le disque privé « local » : jamais servis sans connexion.
 *
 * Une photo est réduite (2 400 px au plus sur le grand côté) et réenregistrée en JPEG, ce qui
 * retire aussi ses métadonnées (position GPS du téléphone). Plusieurs photos d'un ticket long
 * sont réunies en un PDF d'une page par photo au moment de la lecture.
 */
class ReceiptFiles
{
    public const MAX_SIDE = 2400;

    public const MAX_PHOTOS = 6;

    public const MAX_PDF_KB = 10240;

    public function __construct(private readonly RecipePhotoService $photos) {}

    /**
     * @param  list<UploadedFile>  $files  des photos, ou un seul PDF
     * @return list<string> chemins relatifs sur le disque « local »
     */
    public function store(array $files): array
    {
        $files = array_values(array_filter($files));

        if ($files === []) {
            throw new InvalidArgumentException('Ajoutez une photo du ticket (ou son PDF).');
        }

        $pdfs = array_filter($files, fn (UploadedFile $f) => $this->isPdf($f));

        if ($pdfs !== [] && count($files) > 1) {
            throw new InvalidArgumentException('Un PDF seul, ou des photos : pas les deux à la fois.');
        }

        if (count($files) > self::MAX_PHOTOS) {
            throw new InvalidArgumentException('Six photos au plus pour un ticket.');
        }

        $folder = 'receipts/'.now()->format('Y/m');
        $paths = [];

        foreach ($files as $file) {
            $name = $folder.'/'.Str::uuid();

            if ($this->isPdf($file)) {
                if ($file->getSize() > self::MAX_PDF_KB * 1024) {
                    throw new InvalidArgumentException('Le PDF dépasse 10 Mo.');
                }

                Storage::disk('local')->putFileAs($folder, $file, basename($name).'.pdf');
                $paths[] = $name.'.pdf';

                continue;
            }

            try {
                $image = $this->photos->load($file->getRealPath());
            } catch (RuntimeException $e) {
                throw new InvalidArgumentException('« '.$file->getClientOriginalName().' » : '.$e->getMessage());
            }

            $image = $this->shrink($image);
            ob_start();
            imagejpeg($image, null, 85);
            imagedestroy($image);
            Storage::disk('local')->put($name.'.jpg', (string) ob_get_clean());
            $paths[] = $name.'.jpg';
        }

        return $paths;
    }

    /**
     * Le document à envoyer au service : la photo seule, le PDF, ou un PDF réunissant les photos.
     *
     * @return array{path: string, mime: string, temporary: bool, pages: int}
     */
    public function document(Receipt $receipt): array
    {
        $paths = array_values((array) $receipt->photo_paths);

        if ($paths === [] || $receipt->photos_deleted_at) {
            throw new ReadingFailed('Les photos de ce ticket ne sont plus disponibles.');
        }

        $disk = Storage::disk('local');

        if (count($paths) === 1) {
            $pdf = str_ends_with($paths[0], '.pdf');

            return ['path' => $disk->path($paths[0]), 'mime' => $pdf ? 'application/pdf' : 'image/jpeg', 'temporary' => false, 'pages' => $pdf ? $this->pdfPages($disk->path($paths[0])) : 1];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'ticket').'.pdf';
        file_put_contents($temporary, JpegPdf::build(array_map(fn ($p) => $disk->path($p), $paths)));

        return ['path' => $temporary, 'mime' => 'application/pdf', 'temporary' => true, 'pages' => count($paths)];
    }

    /** Copie réduite en JPEG d'une image envoyée (recette depuis une photo), dans un fichier temporaire. */
    public function temporaryJpeg(string $path): string
    {
        try {
            $image = $this->shrink($this->photos->load($path));
        } catch (RuntimeException $e) {
            throw new ReadingFailed('Image illisible : '.$e->getMessage());
        }

        $temporary = tempnam(sys_get_temp_dir(), 'ocr').'.jpg';
        imagejpeg($image, $temporary, 85);
        imagedestroy($image);

        return $temporary;
    }

    public function delete(Receipt $receipt): void
    {
        foreach ((array) $receipt->photo_paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    public function absolute(string $path): string
    {
        return Storage::disk('local')->path($path);
    }

    private function isPdf(UploadedFile $file): bool
    {
        return $file->getMimeType() === 'application/pdf' || strtolower($file->getClientOriginalExtension()) === 'pdf';
    }

    /** Nombre de pages d'un PDF (compte des objets /Type /Page) : suffit pour le suivi et la limite de 8 pages. */
    public function pdfPages(string $path): int
    {
        $content = (string) @file_get_contents($path);

        return max(1, preg_match_all('#/Type\s*/Page(?!s)#', $content));
    }

    /** @param  \GdImage  $image */
    private function shrink($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = self::MAX_SIDE / max($width, $height);

        if ($ratio >= 1) {
            return $image;
        }

        $resized = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);
        imagedestroy($image);

        return $resized;
    }
}
