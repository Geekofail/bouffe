<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuration Pest
|--------------------------------------------------------------------------
| Les tests "Feature" utilisent l'application Laravel complète et une base
| SQLite en mémoire (voir phpunit.xml), réinitialisée à chaque test.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Restauration de sauvegarde : les tables sont supprimées puis recréées, incompatible avec les
// transactions de RefreshDatabase → migrations rejouées avant chaque test.
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Backup');
