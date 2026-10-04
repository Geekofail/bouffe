<?php

namespace App\Livewire\Concerns;

use App\Services\Undo\UndoManager;
use Closure;

/**
 * « Annuler » pendant 10 secondes après un geste qui retire, vide, remplace ou déplace (30.1, R32).
 *
 *   $this->undoable('planning.clear', 'Semaine vidée', fn (UndoRecorder $r) => …, 'Semaine vidée (5 repas retirés).');
 *
 * Le message s'affiche en bas de l'écran avec son bouton ; l'annulation est faite par le composant
 * Layout\Undo, qui prévient ensuite la page (événement « bouffe-undone ») pour qu'elle se redessine.
 */
trait OffersUndo
{
    /**
     * @param  Closure(\App\Services\Undo\UndoRecorder): mixed  $work  le geste ; s'il renvoie un texte, c'est le message affiché
     * @param  string|Closure(mixed): ?string|null  $message  message, ou fonction du résultat (null : rien à annuler, pas de message)
     */
    protected function undoable(string $action, string $label, Closure $work, string|Closure|null $message = null): mixed
    {
        $done = app(UndoManager::class)->run($action, $label, $work);

        $text = match (true) {
            $message instanceof Closure => $message($done['result']),
            is_string($done['result']) => $done['result'],
            default => $message ?? $label.'.',
        };

        if ($text !== null) {
            $this->dispatch('notify',
                message: $text,
                action: ['label' => 'Annuler', 'event' => 'bouffe-undo', 'params' => ['token' => $done['token']]],
                duration: UndoManager::SECONDS * 1000,
            );
        }

        return $done['result'];
    }
}
