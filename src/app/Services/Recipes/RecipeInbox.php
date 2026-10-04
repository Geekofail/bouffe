<?php

namespace App\Services\Recipes;

use App\Models\InboxItem;
use App\Models\Recipe;
use App\Services\Receipts\OcrService;
use App\Services\Receipts\ReadingFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * « À trier » (lot 38, 38.2 et 38.3) : les recettes reçues en passant — une adresse partagée depuis
 * Safari ou envoyée par un raccourci, un texte, la photo d'une page de livre.
 *
 * Une adresse est lue **tout de suite** (la page est gardée sous forme de données de recette) ; une
 * photo est lue par le service des tickets (lot 23) au passage suivant de la tâche planifiée. Rien
 * n'entre dans le carnet sans relecture : « Garder » ouvre l'import avec l'ébauche, « Jeter » l'écarte.
 */
class RecipeInbox
{
    /** Au-delà, un rappel discret propose de trier. */
    public const REMIND_ABOVE = 10;

    /** Photos lues à chaque passage de la tâche planifiée (le service est payant à l'usage). */
    public const PHOTOS_PER_RUN = 3;

    public const VIA = ['raccourci', 'partage', 'app'];

    public function __construct(private readonly RecipeImporter $importer) {}

    /** Une adresse (déjà reçue et pas encore triée : on ne la reprend pas). */
    public function receiveUrl(string $url, string $via = 'app'): InboxItem
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('Adresse invalide : elle doit commencer par https://');
        }

        $existing = InboxItem::query()->open()->where('url', $url)->first();

        if ($existing) {
            return $existing;
        }

        $item = InboxItem::create([
            'user_id' => auth()->id(),
            'kind' => 'url',
            'via' => $this->via($via),
            'url' => Str::limit($url, 2000, ''),
            'status' => InboxItem::PENDING,
        ]);

        return $this->process($item);
    }

    /**
     * Un texte : s'il contient une adresse (partage depuis une application), c'est elle qui compte ;
     * sinon c'est le texte d'une recette, analysé à la relecture.
     */
    public function receiveText(string $text, string $via = 'app', ?string $title = null): InboxItem
    {
        $text = trim($text);

        if (preg_match('#https?://[^\s<>"]+#iu', $text, $m)) {
            $item = $this->receiveUrl(rtrim($m[0], '.,;)»'), $via);

            if (! $item->title && $title) {
                $item->update(['title' => Str::limit(trim($title), 200, '')]);
            }

            return $item;
        }

        if (mb_strlen($text) < 3) {
            throw new InvalidArgumentException('Rien à garder : envoyez une adresse ou le texte d\'une recette.');
        }

        return InboxItem::create([
            'user_id' => auth()->id(),
            'kind' => 'text',
            'via' => $this->via($via),
            'text' => Str::limit($text, 20000, ''),
            'title' => Str::limit(trim($title ?: Str::before($text, "\n")), 200, ''),
            'status' => InboxItem::READY,
        ]);
    }

    /** Une photo de page de livre ou de fiche : gardée, lue plus tard. */
    public function receivePhoto(UploadedFile $file, string $via = 'app'): InboxItem
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'], true) || $file->getSize() > 12 * 1024 * 1024) {
            throw new InvalidArgumentException('Une photo (JPEG, PNG, HEIC) ou un PDF de 12 Mo au plus.');
        }

        $path = $file->storeAs('inbox/'.\App\Support\CurrentHousehold::id(), Str::random(24).'.'.$extension, 'local');

        return InboxItem::create([
            'user_id' => auth()->id(),
            'kind' => 'photo',
            'via' => $this->via($via),
            'photo_path' => $path,
            'status' => InboxItem::PENDING,
        ]);
    }

    /** Lit ce qui peut l'être : la page d'une adresse, ou la photo. */
    public function process(InboxItem $item): InboxItem
    {
        try {
            match ($item->kind) {
                'url' => $this->readPage($item),
                'photo' => $this->readPhoto($item),
                default => null,
            };
        } catch (InvalidArgumentException|ReadingFailed $e) {
            $item->update(['status' => InboxItem::FAILED, 'error' => Str::limit($e->getMessage(), 250, '')]);
        }

        return $item->fresh();
    }

    /** Tâche planifiée : les photos en attente, quelques-unes à chaque passage. */
    public function processPending(): int
    {
        $items = InboxItem::query()->where('status', InboxItem::PENDING)->orderBy('id')->limit(self::PHOTOS_PER_RUN)->get();

        foreach ($items as $item) {
            $this->process($item);
        }

        return $items->count();
    }

    /**
     * L'ébauche à relire dans l'import : reconstruite depuis les données gardées.
     *
     * @return array<string, mixed>
     */
    public function draft(InboxItem $item): array
    {
        return match (true) {
            $item->kind === 'url' && is_array($item->payload) => $this->importer->draftFromSchema($item->payload, (string) $item->url),
            $item->kind === 'url' => $this->importer->fromUrl((string) $item->url),
            filled($item->text) => $this->importer->fromText((string) $item->text),
            default => throw new InvalidArgumentException('Cette photo n\'a pas encore été lue.'),
        };
    }

    public function keep(InboxItem $item, Recipe $recipe): void
    {
        $this->deletePhoto($item);
        $item->update(['status' => InboxItem::KEPT, 'recipe_id' => $recipe->id, 'photo_path' => null]);
    }

    public function discard(InboxItem $item): void
    {
        $this->deletePhoto($item);
        $item->update(['status' => InboxItem::DISCARDED, 'photo_path' => null]);
    }

    /** @return Collection<int, InboxItem> */
    public function open(): Collection
    {
        return InboxItem::query()->open()->with('user:id,name')->latest('id')->get();
    }

    public function openCount(): int
    {
        return InboxItem::query()->open()->count();
    }

    /** Les éléments triés depuis plus de 30 jours sont oubliés. */
    public function purge(Carbon $now): int
    {
        return InboxItem::query()->whereIn('status', [InboxItem::KEPT, InboxItem::DISCARDED])
            ->where('updated_at', '<', $now->copy()->subDays(30)->toDateTimeString())->delete();
    }

    /* ================================================================ Lecture */

    private function readPage(InboxItem $item): void
    {
        $data = $this->importer->fetchRecipeData((string) $item->url);
        $draft = $this->importer->draftFromSchema($data, (string) $item->url);

        // Les données sont gardées telles quelles (taille raisonnable) : l'ébauche se refait à la relecture.
        $payload = strlen((string) json_encode($data)) <= 200_000 ? $data : null;

        $item->update([
            'status' => InboxItem::READY,
            'title' => Str::limit((string) $draft['title'], 200, ''),
            'image_url' => $draft['image'] ? Str::limit((string) $draft['image'], 2000, '') : null,
            'payload' => $payload,
            'error' => null,
        ]);
    }

    private function readPhoto(InboxItem $item): void
    {
        $disk = Storage::disk('local');

        if (! $item->photo_path || ! $disk->exists($item->photo_path)) {
            throw new InvalidArgumentException('La photo a disparu.');
        }

        \App\Support\TimeLimit::atLeast(180);
        $file = new UploadedFile($disk->path($item->photo_path), basename($item->photo_path), null, null, true);
        $text = trim(preg_replace('/^#+\s*/m', '', app(OcrService::class)->readRecipeText($file)) ?? '');

        if (mb_strlen($text) < 20) {
            throw new InvalidArgumentException('Presque rien n\'a pu être lu sur cette photo.');
        }

        $this->deletePhoto($item);
        $item->update([
            'status' => InboxItem::READY,
            'text' => Str::limit($text, 20000, ''),
            'title' => Str::limit(trim(Str::before($text, "\n")), 200, ''),
            'photo_path' => null,
            'error' => null,
        ]);
    }

    private function deletePhoto(InboxItem $item): void
    {
        if ($item->photo_path) {
            Storage::disk('local')->delete($item->photo_path);
        }
    }

    private function via(string $via): string
    {
        return in_array($via, self::VIA, true) ? $via : 'app';
    }
}
