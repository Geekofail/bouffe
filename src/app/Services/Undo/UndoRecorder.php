<?php

namespace App\Services\Undo;

use Closure;

/**
 * Ce qu'un geste annulable va toucher (30.1).
 *
 * track() photographie les lignes **avant** le geste. Une liste peut être donnée sous forme de
 * fonction : elle est relue après le geste, pour y ajouter les lignes créées (une semaine copiée,
 * les articles ajoutés par une mise à jour de la liste).
 */
class UndoRecorder
{
    /** @var array<string, array{ids: list<int>, resolver: Closure|null}> */
    private array $scopes = [];

    private ?array $before = null;

    public function __construct(private readonly Snapshotter $snapshots) {}

    /**
     * @param  iterable<int>|Closure(): iterable<int>  $ids
     */
    public function track(string $table, iterable|Closure $ids): static
    {
        $resolver = $ids instanceof Closure ? $ids : null;
        $list = $this->ids($resolver ? $resolver() : $ids);

        $existing = $this->scopes[$table] ?? ['ids' => [], 'resolver' => null];
        $this->scopes[$table] = [
            'ids' => array_values(array_unique([...$existing['ids'], ...$list])),
            'resolver' => $resolver ?? $existing['resolver'],
        ];

        // Photographie de l'état d'avant, table par table (les lignes déjà vues ne sont pas reprises).
        $snapshot = $this->snapshots->capture([$table => $list]);
        $this->before ??= ['rows' => [], 'refs' => []];
        $this->before['rows'] += $snapshot['rows'];
        $this->before['refs'] = [...$this->before['refs'], ...$snapshot['refs']];

        return $this;
    }

    /** @return array{scopes: array<string, list<int>>, before: array, after: array} */
    public function seal(): array
    {
        $scopes = [];

        foreach ($this->scopes as $table => $scope) {
            $after = $scope['resolver'] ? $this->ids(($scope['resolver'])()) : [];
            $scopes[$table] = array_values(array_unique([...$scope['ids'], ...$after]));
        }

        return [
            'scopes' => $scopes,
            'before' => $this->before ?? ['rows' => [], 'refs' => []],
            'after' => $this->snapshots->capture($scopes),
        ];
    }

    /** @return list<int> */
    private function ids(iterable $ids): array
    {
        $list = [];

        foreach ($ids as $id) {
            $list[] = (int) $id;
        }

        return $list;
    }
}
