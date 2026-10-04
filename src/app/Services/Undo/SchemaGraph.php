<?php

namespace App\Services\Undo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les liens entre tables, lus dans la base elle-même (30.1) : qui pointe vers qui, et ce que la base
 * fait des lignes liées quand on en supprime une (suppression en cascade, lien remis à vide).
 *
 * C'est ce qui permet à l'annulation de remettre **tout** ce qu'un geste a emporté, sans liste à
 * tenir à jour à la main : les réactions d'un repas supprimé, les sources d'un article de courses,
 * le lien d'un article du stock vers son repas…
 *
 * Lu une fois, puis gardé en cache tant que la base n'a pas reçu de nouvelle migration.
 */
class SchemaGraph
{
    /** @var array{in: array<string, list<array{table: string, column: string, on_delete: string}>>, out: array<string, list<array{column: string, table: string}>>, id: array<string, bool>}|null */
    private ?array $graph = null;

    /** Tables qui pointent vers $table, et ce qui leur arrive quand une ligne de $table disparaît. */
    public function incoming(string $table): array
    {
        return $this->graph()['in'][$table] ?? [];
    }

    /** Colonnes de $table qui pointent vers une autre table. */
    public function outgoing(string $table): array
    {
        return $this->graph()['out'][$table] ?? [];
    }

    /** La table a-t-elle une clé « id » ? (les tables de liaison n'en ont pas) */
    public function hasId(string $table): bool
    {
        return $this->graph()['id'][$table] ?? true;
    }

    private function graph(): array
    {
        if ($this->graph !== null) {
            return $this->graph;
        }

        $version = DB::table('migrations')->count().'-'.DB::table('migrations')->max('id');
        $key = 'bouffe.schema-graph.'.DB::connection()->getDriverName().'.'.$version;

        return $this->graph = Cache::rememberForever($key, function () {
            $graph = ['in' => [], 'out' => [], 'id' => []];
            $schema = Schema::getCurrentSchemaListing()[0] ?? null;

            foreach (Schema::getTableListing($schema, false) as $table) {
                $graph['id'][$table] = Schema::hasColumn($table, 'id');

                foreach (Schema::getForeignKeys($table) as $key) {
                    if (count($key['columns']) !== 1) {
                        continue;
                    }

                    $graph['out'][$table][] = ['column' => $key['columns'][0], 'table' => $key['foreign_table']];
                    $graph['in'][$key['foreign_table']][] = [
                        'table' => $table,
                        'column' => $key['columns'][0],
                        'on_delete' => strtolower((string) $key['on_delete']),
                    ];
                }
            }

            return $graph;
        });
    }
}
