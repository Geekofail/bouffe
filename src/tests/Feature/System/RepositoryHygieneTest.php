<?php

use Symfony\Component\Finder\Finder;

/*
 * Lot 36 (36.1) — le dépôt Git ne contient aucun secret (document 08, §7.3).
 * Vérifié à chaque envoi par les tests automatiques.
 */

test('.env, vendor et node_modules ne sont jamais envoyés dans le dépôt', function () {
    $ignored = array_map('trim', file(base_path('.gitignore')));

    expect($ignored)->toContain('.env')->toContain('/vendor')->toContain('/node_modules');
});

test('le modèle .env.example ne contient aucune valeur secrète', function () {
    $sensitive = [];

    foreach (file(base_path('.env.example')) as $line) {
        if (preg_match('/^([A-Z0-9_]*(KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)=(.*)$/', trim($line), $m) && ! in_array(trim($m[3], " \"'"), ['', 'null'], true)) {
            $sensitive[] = $m[1];
        }
    }

    expect($sensitive)->toBe([]);
});

test('aucune clé ni mot de passe dans les fichiers suivis', function () {
    $patterns = [
        'clé Laravel' => '/APP_KEY=base64:[A-Za-z0-9+\/=]{20,}/',
        'clé privée' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'jeton GitHub' => '/\b(ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{20,}/',
        'clé d\'API' => '/\b(sk|pk)-[A-Za-z0-9]{24,}/',
        'clé renseignée' => '/^(MISTRAL_API_KEY|AZURE_DI_KEY|VAPID_PRIVATE_KEY|MAIL_PASSWORD|DB_PASSWORD|BOUFFE_TASKS_TOKEN)=[^\s#]+/m',
    ];

    $files = Finder::create()->files()->in(base_path())->ignoreDotFiles(false)
        ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'playwright-report', 'test-results'])
        ->notName(['.env', '*.sqlite', '*.sqlite-*', '*.png', '*.jpg', '*.webp', '*.ico', '*.woff2', '*.zip', 'composer.lock', 'package-lock.json'])
        ->notPath('tests/Feature/System/RepositoryHygieneTest.php');

    $found = [];

    foreach ($files as $file) {
        $content = $file->getContents();

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $content)) {
                $found[] = $file->getRelativePathname().' ('.$label.')';
            }
        }
    }

    expect($found)->toBe([]);
});
