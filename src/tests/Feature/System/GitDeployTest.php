<?php

use App\Services\System\GitUpdater;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Lot 36 (36.3) — « bouffe:deploy --git » : récupérer la version validée depuis le dépôt.
 * Les tests travaillent sur de vrais petits dépôts Git créés dans un dossier temporaire.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/bouffe-git-'.uniqid();
    File::makeDirectory($this->root.'/origine', 0755, true);
    $git = fn (string $dir, string ...$args) => Process::path($dir)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.org', ...$args])->throw();
    $this->git = $git;

    // Dépôt « GitHub » (nu) et le poste de travail qui y pousse.
    $git($this->root.'/origine', 'init', '--bare', '--quiet', '--initial-branch=main');
    Process::path($this->root)->run(['git', 'clone', '--quiet', $this->root.'/origine', 'pc'])->throw();
    File::put($this->root.'/pc/lisez-moi.txt', "Bouffe\n");
    $git($this->root.'/pc', 'checkout', '--quiet', '-b', 'main');
    $git($this->root.'/pc', 'add', '.');
    $git($this->root.'/pc', 'commit', '--quiet', '-m', 'Lot 35');
    $git($this->root.'/pc', 'push', '--quiet', 'origin', 'main');

    // Le serveur (OVH) : un clone de la version en ligne.
    Process::path($this->root)->run(['git', 'clone', '--quiet', '--branch', 'main', $this->root.'/origine', 'serveur'])->throw();
    app()->instance(GitUpdater::class, new GitUpdater($this->root.'/serveur'));
});

afterEach(fn () => File::deleteDirectory($this->root));

function pushNewVersion(string $message = 'Lot 36 — socle technique'): void
{
    $test = test();
    File::put($test->root.'/pc/nouveau.txt', "nouvelle version\n");
    ($test->git)($test->root.'/pc', 'add', '.');
    ($test->git)($test->root.'/pc', 'commit', '--quiet', '-m', $message);
    ($test->git)($test->root.'/pc', 'push', '--quiet', 'origin', 'main');
}

test('--git récupère la nouvelle version par avance rapide, puis met la base à jour', function () {
    pushNewVersion();

    $this->artisan('bouffe:deploy', ['--git' => true, '--sans-sauvegarde' => true])
        ->expectsOutputToContain('Nouvelles versions')
        ->expectsOutputToContain('Lot 36 — socle technique')
        ->expectsOutputToContain('Nouvelle version récupérée')
        ->expectsOutputToContain('Structure de la base de données')
        ->expectsOutputToContain('Site rouvert');

    expect(File::exists($this->root.'/serveur/nouveau.txt'))->toBeTrue()
        ->and(app(GitUpdater::class)->describe())->toContain('Lot 36');
});

test('déjà à jour : la commande le dit et continue', function () {
    $this->artisan('bouffe:deploy', ['--git' => true, '--sans-sauvegarde' => true])
        ->expectsOutputToContain('Déjà à la dernière version')
        ->doesntExpectOutputToContain('Nouvelle version récupérée');
});

test('un fichier modifié sur le serveur : rien n\'est écrasé', function () {
    pushNewVersion();
    File::put($this->root.'/serveur/lisez-moi.txt', "modifié sur le serveur\n");

    $this->artisan('bouffe:deploy', ['--git' => true, '--sans-sauvegarde' => true])
        ->expectsOutputToContain('Des fichiers ont été modifiés sur ce serveur')
        ->expectsOutputToContain('lisez-moi.txt')
        ->assertFailed();

    expect(File::exists($this->root.'/serveur/nouveau.txt'))->toBeFalse()
        ->and(File::get($this->root.'/serveur/lisez-moi.txt'))->toBe("modifié sur le serveur\n");
});

test('historiques divergents : l\'avance rapide est refusée et le site rouvre', function () {
    pushNewVersion();
    File::put($this->root.'/serveur/autre.txt', "commit fait sur le serveur\n");
    ($this->git)($this->root.'/serveur', 'add', '.');
    ($this->git)($this->root.'/serveur', 'commit', '--quiet', '-m', 'Correctif sur le serveur');

    $this->artisan('bouffe:deploy', ['--git' => true, '--sans-sauvegarde' => true])
        ->expectsOutputToContain('avance rapide a échoué')
        ->expectsOutputToContain('Site rouvert')
        ->assertFailed();

    expect(app()->isDownForMaintenance())->toBeFalse();
});

test('sans dépôt Git, --git s\'arrête avant toute modification', function () {
    app()->instance(GitUpdater::class, new GitUpdater($this->root));   // dossier ordinaire

    $this->artisan('bouffe:deploy', ['--git' => true, '--sans-sauvegarde' => true])
        ->expectsOutputToContain('Git est introuvable')
        ->doesntExpectOutputToContain('Site en maintenance')
        ->assertFailed();

    $this->artisan('bouffe:deploy', ['--git' => true, '--branche' => 'main; rm -rf /', '--sans-sauvegarde' => true])
        ->expectsOutputToContain('Nom de branche invalide')
        ->assertFailed();
});
