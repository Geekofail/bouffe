<?php

namespace App\Services\Undo;

use Illuminate\Support\Facades\DB;

/**
 * Photographie de lignes de la base (30.1), et remise en état d'après une photographie.
 *
 * Une photographie comprend les lignes demandées **et** tout ce que la base supprimerait avec elles
 * (lignes liées en cascade, à toute profondeur), ainsi que les liens qu'elle remettrait à vide
 * (un article du stock qui pointe vers un repas supprimé).
 *
 * Format : ['rows' => ['table:id' => ['table' => …, 'key' => …, 'depth' => …, 'row' => [...]]], 'refs' => [...]]
 */
class Snapshotter
{
    public function __construct(private readonly SchemaGraph $schema) {}

    /**
     * @param  array<string, list<int>>  $scopes  table → identifiants
     * @return array{rows: array<string, array{table: string, key: string, depth: int, row: array<string, mixed>}>, refs: list<array{table: string, column: string, id: int, value: int}>}
     */
    public function capture(array $scopes): array
    {
        $snapshot = ['rows' => [], 'refs' => []];

        foreach ($scopes as $table => $ids) {
            $this->collect($snapshot, $table, 'id', array_values(array_unique(array_map('intval', $ids))), 0);
        }

        // Un lien « remis à vide » dont la ligne est elle-même photographiée est déjà dans sa photo.
        $snapshot['refs'] = array_values(array_filter(
            $snapshot['refs'],
            fn (array $ref) => ! isset($snapshot['rows'][$ref['table'].':'.$ref['id']]),
        ));

        return $snapshot;
    }

    private function collect(array &$snapshot, string $table, string $column, array $values, int $depth): void
    {
        if ($values === [] || $depth > 6) {
            return;
        }

        $rows = [];

        foreach (array_chunk($values, 500) as $chunk) {
            foreach (DB::table($table)->whereIn($column, $chunk)->get() as $row) {
                $rows[] = (array) $row;
            }
        }

        $ids = [];

        foreach ($rows as $row) {
            $key = $this->keyOf($table, $row);

            if (isset($snapshot['rows'][$key])) {
                continue;
            }

            $snapshot['rows'][$key] = ['table' => $table, 'key' => $key, 'depth' => $depth, 'row' => $row];

            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        if ($ids === []) {
            return;
        }

        foreach ($this->schema->incoming($table) as $child) {
            if ($child['on_delete'] === 'cascade') {
                $this->collect($snapshot, $child['table'], $child['column'], $ids, $depth + 1);
            } elseif ($child['on_delete'] === 'set null' && $this->schema->hasId($child['table'])) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach (DB::table($child['table'])->whereIn($child['column'], $chunk)->get(['id', $child['column']]) as $ref) {
                        $snapshot['refs'][] = ['table' => $child['table'], 'column' => $child['column'], 'id' => (int) $ref->id, 'value' => (int) $ref->{$child['column']}];
                    }
                }
            }
        }
    }

    private function keyOf(string $table, array $row): string
    {
        return isset($row['id']) ? $table.':'.$row['id'] : $table.':#'.md5(json_encode($row));
    }

    /** Deux photographies montrent-elles exactement les mêmes lignes ? */
    public function same(array $a, array $b): bool
    {
        if (array_keys($a['rows']) != array_keys($b['rows'])) {
            $keysA = array_keys($a['rows']);
            $keysB = array_keys($b['rows']);
            sort($keysA);
            sort($keysB);

            if ($keysA !== $keysB) {
                return false;
            }
        }

        foreach ($a['rows'] as $key => $entry) {
            if ($this->normalize($entry['row']) !== $this->normalize($b['rows'][$key]['row'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remet la base dans l'état $target, depuis l'état $current (qui doit être celui de la base).
     */
    public function restore(array $target, array $current): void
    {
        // 1. Ce qui n'existait pas avant : supprimé, les lignes liées d'abord.
        $extra = array_diff_key($current['rows'], $target['rows']);
        uasort($extra, fn ($x, $y) => $y['depth'] <=> $x['depth']);

        foreach ($extra as $entry) {
            $this->delete($entry);
        }

        // 2. Ce qui a changé : remis comme avant, colonne par colonne.
        foreach (array_intersect_key($target['rows'], $current['rows']) as $key => $entry) {
            $before = $entry['row'];
            $now = $current['rows'][$key]['row'];
            $changes = [];

            foreach ($before as $column => $value) {
                if ($column !== 'id' && $this->normalizeValue($value) !== $this->normalizeValue($now[$column] ?? null)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes !== [] && isset($before['id'])) {
                DB::table($entry['table'])->where('id', $before['id'])->update($changes);
            }
        }

        // 3. Ce qui a disparu : réinséré tel quel, chaque ligne après celles vers lesquelles elle pointe.
        foreach ($this->insertionOrder(array_diff_key($target['rows'], $current['rows'])) as $entry) {
            DB::table($entry['table'])->insert($entry['row']);
        }

        // 4. Les liens remis à vide par la suppression : rétablis s'ils sont toujours vides.
        foreach ($target['refs'] as $ref) {
            DB::table($ref['table'])->where('id', $ref['id'])->whereNull($ref['column'])->update([$ref['column'] => $ref['value']]);
        }
    }

    private function delete(array $entry): void
    {
        $query = DB::table($entry['table']);

        if (isset($entry['row']['id'])) {
            $query->where('id', $entry['row']['id'])->delete();

            return;
        }

        foreach ($entry['row'] as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        $query->delete();
    }

    /**
     * @param  array<string, array>  $entries
     * @return list<array>
     */
    private function insertionOrder(array $entries): array
    {
        $pending = $entries;
        $ordered = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $key => $entry) {
                $waiting = false;

                foreach ($this->schema->outgoing($entry['table']) as $fk) {
                    $value = $entry['row'][$fk['column']] ?? null;

                    if ($value !== null && isset($pending[$fk['table'].':'.$value]) && $key !== $fk['table'].':'.$value) {
                        $waiting = true;
                        break;
                    }
                }

                if (! $waiting) {
                    $ordered[] = $entry;
                    unset($pending[$key]);
                    $progress = true;
                }
            }

            // Boucle de références (ne devrait pas arriver) : on insère le reste tel quel.
            if (! $progress) {
                array_push($ordered, ...array_values($pending));
                break;
            }
        }

        return $ordered;
    }

    /** Valeurs comparables d'une base à l'autre ; clés triées (MySQL range les clés d'un JSON à sa façon). */
    private function normalize(array $row): array
    {
        $row = array_map(fn ($value) => $this->normalizeValue($value), $row);
        ksort($row);

        return $row;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
