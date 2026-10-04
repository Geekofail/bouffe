<?php

use App\Services\RecipePhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->photos = app(RecipePhotoService::class);
});

/** Photo JPEG d'une taille donnée, écrite dans un fichier temporaire. */
function fakePhoto(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 60));

    ob_start();
    imagejpeg($image, null, 85);
    $jpeg = ob_get_clean();
    imagedestroy($image);

    $path = tempnam(sys_get_temp_dir(), 'bouffe-test-').'.jpg';
    file_put_contents($path, $jpeg);

    return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
}

test('une photo est enregistrée en deux tailles sans dépasser la mémoire raisonnable', function () {
    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    $base = $this->photos->store(fakePhoto(2400, 1800));

    // L'original est libéré avant de fabriquer la vignette : deux images en mémoire au maximum.
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(40 * 1024 * 1024);

    Storage::disk('local')->assertExists([$base.'.jpg', $base.'-thumb.jpg']);

    expect(getimagesizefromstring(Storage::disk('local')->get($base.'.jpg'))[0])->toBe(1600)
        ->and(getimagesizefromstring(Storage::disk('local')->get($base.'-thumb.jpg'))[0])->toBe(640);
});

test('une petite photo n\'est pas agrandie', function () {
    $base = $this->photos->store(fakePhoto(400, 300));

    expect(getimagesizefromstring(Storage::disk('local')->get($base.'.jpg'))[0])->toBe(400)
        ->and(getimagesizefromstring(Storage::disk('local')->get($base.'-thumb.jpg'))[0])->toBe(400);
});

test('une photo trop grande pour la mémoire disponible est refusée avec un message clair', function () {
    // Réglage explicite plutôt que memory_limit : un test ne doit jamais pouvoir faire tomber le processus.
    config(['bouffe.photos.max_megapixels' => 0.5]);

    expect(fn () => $this->photos->store(fakePhoto(1200, 900)))
        ->toThrow(RuntimeException::class, 'Photo trop grande pour la mémoire disponible (1.1 Mpx, maximum 0.5 Mpx)');
});

test('une photo dans les limites passe malgré le réglage', function () {
    config(['bouffe.photos.max_megapixels' => 0.5]);

    $base = $this->photos->store(fakePhoto(600, 400));

    Storage::disk('local')->assertExists($base.'.jpg');
});

test('un fichier qui n\'est pas une image est refusé', function () {
    $path = tempnam(sys_get_temp_dir(), 'bouffe-test-').'.jpg';
    file_put_contents($path, 'ceci n\'est pas une image');

    expect(fn () => $this->photos->store(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true)))
        ->toThrow(RuntimeException::class, 'Image illisible.');
});
