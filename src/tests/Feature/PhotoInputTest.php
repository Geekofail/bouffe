<?php

/*
 * Envoi d'une photo depuis un téléphone : un champ qui ouvre directement l'appareil photo
 * (capture="environment"), à côté du choix d'un fichier, tous deux reliés à la même propriété.
 * L'affichage selon l'appareil (tactile ou non) est vérifié dans tests/Browser/photo-input.spec.js.
 */

use App\Livewire\Recipes\Edit;
use App\Livewire\Recipes\Inbox;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

function photoInputs(string $html): array
{
    preg_match_all('/<input type="file"[^>]*>/', $html, $m);

    return $m[0];
}

test('le composant propose l\'appareil photo et le choix d\'un fichier, sur la même propriété', function () {
    $html = Blade::render('<x-photo-input model="photo" accept="image/jpeg,image/png,application/pdf" id="p" data-test />');
    [$camera, $file] = photoInputs($html);

    expect($camera)->toContain('capture="environment"')
        ->toContain('accept="image/jpeg,image/png"')          // l'appareil ne donne que des images
        ->toContain('wire:model="photo"')
        ->not->toContain('id="p"')
        ->and($file)->not->toContain('capture=')
        ->toContain('accept="image/jpeg,image/png,application/pdf"')
        ->toContain('wire:model="photo"')
        ->toContain('id="p"')->toContain('data-test')
        ->and($html)->toContain('Prendre une photo')->toContain('Choisir un fichier')
        // Le bouton « Prendre une photo » n'apparaît que sur un écran tactile.
        ->toMatch('/class="[^"]*hidden[^"]*pointer-coarse:inline-flex[^"]*" data-photo-camera/');
});

test('les écrans qui reçoivent une photo ouvrent l\'appareil photo sur téléphone', function () {
    $this->actingAs(User::factory()->create());

    foreach ([
        [Edit::class, 'photo'],
        [Inbox::class, 'photo'],
    ] as [$component, $model]) {
        $inputs = photoInputs(Livewire::test($component)->html());
        $camera = collect($inputs)->filter(fn ($input) => str_contains($input, 'capture="environment"'));

        expect($camera)->toHaveCount(1, $component)
            ->and($camera->first())->toContain('wire:model="'.$model.'"');
    }
});
