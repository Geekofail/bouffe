<?php

use App\Services\Backup\SqlStatementSplitter;

function splitSql(string $sql, bool $backslash = true): array
{
    return iterator_to_array((new SqlStatementSplitter)->split($sql, $backslash), false);
}

it('découpe les instructions sur les points-virgules', function () {
    expect(splitSql("SET NAMES utf8mb4;\nSELECT 1;\n\nSELECT 2"))->toBe(['SET NAMES utf8mb4', 'SELECT 1', 'SELECT 2']);
});

it('ignore les points-virgules à l\'intérieur des textes', function () {
    $sql = "INSERT INTO t VALUES ('Couper; puis cuire', 'l\\'oignon; \\\\', \"a;b\");\nSELECT 3;";

    expect(splitSql($sql))->toBe([
        "INSERT INTO t VALUES ('Couper; puis cuire', 'l\\'oignon; \\\\', \"a;b\")",
        'SELECT 3',
    ]);
});

it('gère les guillemets doublés et les retours à la ligne (SQLite)', function () {
    $sql = "INSERT INTO t VALUES ('l''oignon;\nhaché\\');\nSELECT 4;";

    expect(splitSql($sql, backslash: false))->toBe(["INSERT INTO t VALUES ('l''oignon;\nhaché\\')", 'SELECT 4']);
});

it('ignore les commentaires de début de ligne', function () {
    expect(splitSql("-- Table recipes; avec point-virgule\nDROP TABLE `recipes`;\n-- fin"))->toBe(['DROP TABLE `recipes`']);
});

it('ne prend pas « -- » dans un texte pour un commentaire', function () {
    expect(splitSql("INSERT INTO t VALUES ('x -- y; z');"))->toBe(["INSERT INTO t VALUES ('x -- y; z')"]);
});
