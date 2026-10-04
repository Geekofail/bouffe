<?php

namespace App\Livewire\Recipes;

use App\Models\InboxItem;
use App\Services\Recipes\RecipeInbox;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * « À trier » (lot 38, 38.3) : les recettes reçues en passant (raccourci, partage, ici), relues
 * quand on a le temps. « Garder » ouvre l'import avec l'ébauche ; « Jeter » l'écarte.
 */
#[Title('À trier')]
class Inbox extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use WithFileUploads;

    /** Une adresse ou le texte d'une recette, collé ici. */
    public string $entry = '';

    public $photo = null;

    public function add(RecipeInbox $inbox): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['entry' => 'required|string|max:20000'], [], ['entry' => 'adresse ou texte']);

        try {
            $item = $inbox->receiveText($this->entry, 'app');
        } catch (InvalidArgumentException $e) {
            $this->addError('entry', $e->getMessage());

            return;
        }

        $this->reset('entry');
        $this->dispatch('notify', type: $item->status === InboxItem::FAILED ? 'warning' : 'success', message: $item->status === InboxItem::FAILED
            ? 'Gardé dans « À trier », mais la page n\'a pas pu être lue.'
            : '« '.$item->label().' » ajouté à « À trier ».');
    }

    public function addPhoto(RecipeInbox $inbox): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        // Le type est vérifié à la réception (photo ou PDF) : le navigateur de l'iPhone envoie parfois du HEIC.
        $this->validate(['photo' => ['required', 'file', 'max:12288']], [], ['photo' => 'photo']);

        try {
            $inbox->receivePhoto($this->photo, 'app');
        } catch (InvalidArgumentException $e) {
            $this->addError('photo', $e->getMessage());

            return;
        }

        $this->reset('photo');
        $this->dispatch('notify', message: 'Photo gardée : elle sera lue dans quelques minutes (ou touchez « Lire maintenant »).');
    }

    /** La photo choisie est gardée dès la fin de l'envoi. */
    public function updatedPhoto(RecipeInbox $inbox): void
    {
        $this->addPhoto($inbox);
    }

    /** Garder : relecture dans l'import, l'ébauche déjà remplie. */
    public function keep(int $id): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $item = InboxItem::query()->open()->findOrFail($id);
        $this->redirectRoute('recipes.import', ['a-trier' => $item->id], navigate: true);
    }

    /** Réessayer une page, ou lire une photo tout de suite. */
    public function retry(int $id, RecipeInbox $inbox): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $item = $inbox->process(InboxItem::query()->open()->findOrFail($id));
        $this->dispatch('notify', type: $item->status === InboxItem::READY ? 'success' : 'warning', message: $item->status === InboxItem::READY
            ? '« '.$item->label().' » est prête à relire.'
            : 'Toujours illisible : '.$item->error);
    }

    public function discard(int $id, RecipeInbox $inbox): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $inbox->discard(InboxItem::query()->open()->findOrFail($id));
        $this->dispatch('notify', message: 'Jeté.');
    }

    public function render(RecipeInbox $inbox)
    {
        return view('livewire.recipes.inbox', [
            'items' => $inbox->open(),
            'canEdit' => auth()->user()->canEdit(),
        ]);
    }
}
