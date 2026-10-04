<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Export SQL complet de la base (structure + données), écrit en PHP : pas besoin de mysqldump,
 * qui n'est pas dans le PATH sous Wamp.
 *
 * Format produit : une instruction par bloc, terminée par « ; » en fin de ligne. Les valeurs sont
 * échappées par PDO, le fichier est relu par SqlStatementSplitter lors d'une restauration.
 *
 * Pilotes gérés : mariadb / mysql (Wamp) et sqlite (tests automatisés).
 */
class SqlDumper
{
    private const ROWS_PER_INSERT = 200;

    /**
     * @param  resource  $stream  fichier ouvert en écriture
     * @param  list<string>  $structureOnly  tables dont les données sont ignorées
     * @return array<string, int> nombre de lignes exportées par table
     */
    public function dump($stream, array $structureOnly = [], ?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $counts = [];

        $this->write($stream, '-- Sauvegarde Bouffe — '.now()->format('Y-m-d H:i:s')." — pilote {$driver}\n");
        $this->write($stream, match ($driver) {
            'mysql', 'mariadb' => "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n",
            'sqlite' => "PRAGMA foreign_keys=OFF;\n",
            default => throw new RuntimeException("Pilote de base non géré pour la sauvegarde : {$driver}"),
        });

        foreach ($this->tables($connection) as $table) {
            $this->write($stream, "\n-- Table {$table}\n");
            $this->write($stream, $this->createStatement($connection, $table).";\n");

            $counts[$table] = 0;

            if (in_array($table, $structureOnly, true)) {
                continue;
            }

            $quotedTable = $this->quoteIdentifier($driver, $table);
            $columns = null;
            $batch = [];

            foreach ($connection->table($table)->orderBy($this->orderColumn($connection, $table))->cursor() as $row) {
                $row = (array) $row;
                $columns ??= implode(', ', array_map(fn ($c) => $this->quoteIdentifier($driver, $c), array_keys($row)));
                $batch[] = '('.implode(', ', array_map(fn ($value) => $this->value($pdo, $value), $row)).')';
                $counts[$table]++;

                if (count($batch) === self::ROWS_PER_INSERT) {
                    $this->write($stream, "INSERT INTO {$quotedTable} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->write($stream, "INSERT INTO {$quotedTable} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->write($stream, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        }

        return $counts;
    }

    /** @return list<string> */
    public function tables(?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $schema = Schema::connection($connection->getName());

        $tables = $schema->getTableListing($schema->getCurrentSchemaName(), false);
        $tables = array_values(array_filter($tables, fn (string $t) => ! str_starts_with($t, 'sqlite_')));
        sort($tables);

        return $tables;
    }

    private function createStatement(Connection $connection, string $table): string
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $sql = $connection->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table])->sql;
            $statements = ['DROP TABLE IF EXISTS '.$this->quoteIdentifier($driver, $table), $sql];

            foreach ($connection->select("select sql from sqlite_master where type = 'index' and tbl_name = ? and sql is not null", [$table]) as $index) {
                $statements[] = $index->sql;
            }

            return implode(";\n", $statements);
        }

        $row = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($driver, $table));

        return 'DROP TABLE IF EXISTS '.$this->quoteIdentifier($driver, $table).";\n".$row['Create Table'];
    }

    private function orderColumn(Connection $connection, string $table): string
    {
        $columns = Schema::connection($connection->getName())->getColumnListing($table);

        return in_array('id', $columns, true) ? 'id' : $columns[0];
    }

    private function value(\PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $pdo->quote((string) $value),
        };
    }

    private function quoteIdentifier(string $driver, string $name): string
    {
        return $driver === 'sqlite' ? '"'.str_replace('"', '""', $name).'"' : '`'.str_replace('`', '``', $name).'`';
    }

    private function write($stream, string $text): void
    {
        if (fwrite($stream, $text) === false) {
            throw new RuntimeException('Écriture impossible dans le fichier de sauvegarde (disque plein ?).');
        }
    }
}
