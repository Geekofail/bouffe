<?php

namespace App\Services\Shopping;

use App\Models\OfflineOperation;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rejeu des actions faites en magasin sans réseau (15.1, 20.3 — règle R20).
 *
 * Le téléphone envoie ses gestes dans l'ordre où ils ont eu lieu, chacun avec un identifiant
 * unique et l'heure du geste. Le serveur les rejoue et explique ce qu'il a fait : c'est la
 * seule façon d'être honnête quand la liste a bougé pendant les courses.
 *
 * Conflits (R20) :
 *  - deux personnes ont touché le même article → le geste le plus récent gagne ;
 *  - l'article a disparu (liste vidée, article supprimé) → geste ignoré et signalé ;
 *  - la liste a été régénérée → la coche est reportée sur l'article du même ingrédient.
 */
class OfflineSync
{
    public const ACTIONS = ['check', 'uncheck', 'add', 'quantity'];

    public function __construct(private readonly ShoppingListManager $manager) {}

    /**
     * @param  list<array{uuid?: string, action?: string, item_id?: int|null, value?: string|null, at?: string|null}>  $operations
     * @return array{applied: int, ignored: int, moved: int, messages: list<string>}
     */
    public function apply(ShoppingList $list, array $operations, ?User $user = null): array
    {
        $report = ['applied' => 0, 'ignored' => 0, 'moved' => 0, 'messages' => []];

        // Dans l'ordre des gestes, pas dans l'ordre d'envoi.
        $sorted = collect($operations)
            ->filter(fn ($op) => in_array($op['action'] ?? '', self::ACTIONS, true))
            ->sortBy(fn ($op) => $this->moment($op['at'] ?? null)->getTimestamp())
            ->values();

        foreach ($sorted as $operation) {
            $uuid = (string) ($operation['uuid'] ?? Str::uuid());

            // Déjà rejoué (envoi en double, onglet rouvert) : on ne refait rien.
            if (OfflineOperation::query()->where('uuid', $uuid)->exists()) {
                continue;
            }

            $record = DB::transaction(fn () => $this->applyOne($list, $operation, $uuid, $user));

            $report[$record->result === OfflineOperation::MOVED ? 'moved' : ($record->result === OfflineOperation::IGNORED ? 'ignored' : 'applied')]++;

            if ($record->detail) {
                $report['messages'][] = $record->detail;
            }
        }

        return $report;
    }

    private function applyOne(ShoppingList $list, array $operation, string $uuid, ?User $user): OfflineOperation
    {
        $action = (string) $operation['action'];
        $itemId = isset($operation['item_id']) ? (int) $operation['item_id'] : null;
        $value = isset($operation['value']) ? mb_substr((string) $operation['value'], 0, 200) : null;
        $moment = $this->moment($operation['at'] ?? null);

        $record = [
            'uuid' => $uuid,
            'user_id' => $user?->id,
            'shopping_list_id' => $list->id,
            'shopping_list_item_id' => $itemId,
            'action' => $action,
            'value' => $value,
            'happened_at' => $moment,
            'applied_at' => now(),
            'result' => OfflineOperation::APPLIED,
            'detail' => null,
        ];

        if ($action === 'add') {
            return OfflineOperation::create($this->addItem($list, $value, $record));
        }

        [$item, $moved] = $this->resolveItem($list, $itemId, isset($operation['label']) ? (string) $operation['label'] : null);

        if (! $item) {
            return OfflineOperation::create([...$record, 'result' => OfflineOperation::IGNORED, 'detail' => 'Article retiré de la liste entre-temps : modification ignorée.']);
        }

        if ($moved) {
            $record['shopping_list_item_id'] = $item->id;
            $record['result'] = OfflineOperation::MOVED;
            $record['detail'] = "La liste a été régénérée : «\u{00A0}{$item->label}\u{00A0}» a été reporté sur le nouvel article.";
        }

        // Le geste le plus récent gagne : une modification faite au chaud après coup n'est pas écrasée.
        if ($item->checked_at && $item->checked_at->gt($moment) && in_array($action, ['check', 'uncheck'], true)) {
            return OfflineOperation::create([
                ...$record,
                'result' => OfflineOperation::IGNORED,
                'detail' => "«\u{00A0}{$item->label}\u{00A0}» a été modifié plus récemment sur un autre appareil : geste ignoré.",
            ]);
        }

        match ($action) {
            'check' => $this->setChecked($item, true, $moment, $user),
            'uncheck' => $this->setChecked($item, false, $moment, $user),
            'quantity' => $this->manager->updateQuantity($item, $value, $item->unit_id),
            default => null,
        };

        return OfflineOperation::create($record);
    }

    /**
     * Retrouve l'article visé. Le téléphone envoie aussi le libellé qu'il avait à l'écran :
     * si la liste a été régénérée entre-temps, c'est ce qui permet de reporter la coche.
     *
     * @return array{0: ShoppingListItem|null, 1: bool} l'article, et s'il a fallu le retrouver autrement
     */
    private function resolveItem(ShoppingList $list, ?int $itemId, ?string $label): array
    {
        if ($itemId) {
            $item = ShoppingListItem::query()->where('shopping_list_id', $list->id)->whereKey($itemId)->first();

            if ($item && ! $item->is_removed) {
                return [$item, false];
            }
        }

        if ($label === null || trim($label) === '') {
            return [null, false];
        }

        $replacement = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            ->where('is_removed', false)
            ->get()
            ->first(fn (ShoppingListItem $i) => NameNormalizer::normalize($i->label) === NameNormalizer::normalize($label));

        return $replacement ? [$replacement, true] : [null, false];
    }

    private function setChecked(ShoppingListItem $item, bool $checked, Carbon $moment, ?User $user): void
    {
        if ((bool) $item->is_checked === $checked) {
            return;
        }

        $item->update([
            'is_checked' => $checked,
            'checked_by' => $checked ? $user?->id : null,
            'checked_at' => $checked ? $moment : null,
        ]);
    }

    private function addItem(ShoppingList $list, ?string $label, array $record): array
    {
        if (! $label || trim($label) === '') {
            return [...$record, 'result' => OfflineOperation::IGNORED, 'detail' => 'Article vide : ignoré.'];
        }

        $item = $this->manager->addManual($list, $label);

        return [...$record, 'shopping_list_item_id' => $item->id];
    }

    private function moment(?string $at): Carbon
    {
        try {
            return $at ? Carbon::parse($at) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    /* ================================================================ Photo de la liste */

    /**
     * Ce que le téléphone emporte : tout ce qu'il faut pour afficher et cocher la liste sans réseau.
     *
     * @return array{id: int, name: string, updated_at: string, aisles: list<array>, items: list<array>}
     */
    public function snapshot(ShoppingList $list): array
    {
        $grouped = $this->manager->grouped($list);
        $presenter = app(ShoppingItemPresenter::class);
        $items = [];
        // Lot 42 (42.3) : « pris par Monique » quand on fait les courses à deux.
        $split = app(AisleSplit::class);
        $this->names = $split->people($list)->pluck('name', 'id')->all();

        foreach ($grouped['aisles'] as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $this->itemPayload($item, $presenter, $group['aisle']?->name ?? 'Autres', (int) ($group['aisle']?->id ?? 0));
            }
        }

        foreach ($grouped['staples'] as $item) {
            $items[] = $this->itemPayload($item, $presenter, 'Placard', -1);
        }

        return [
            'id' => $list->id,
            'name' => $list->name,
            'updated_at' => now()->toIso8601String(),
            'aisles' => collect($items)->unique('aisle_id')->map(fn ($i) => ['id' => $i['aisle_id'], 'name' => $i['aisle']])->values()->all(),
            'items' => $items,
            'split' => $split->payload($list, auth()->user()),
        ];
    }

    /** @var array<int, string> prénoms de ceux qui font les courses */
    private array $names = [];

    private function itemPayload(ShoppingListItem $item, ShoppingItemPresenter $presenter, string $aisle, int $aisleId): array
    {
        return [
            'id' => $item->id,
            'text' => $presenter->text($item),
            'label' => $item->label,
            'aisle' => $aisle,
            'aisle_id' => $aisleId,
            'checked' => (bool) $item->is_checked,
            'checked_by' => $item->is_checked && $item->checked_by ? (int) $item->checked_by : null,
            'checked_name' => $item->is_checked && $item->checked_by ? ($this->names[(int) $item->checked_by] ?? null) : null,
            'optional' => (bool) $item->is_optional,
            'note' => $item->stock_note,
            'origin' => $item->origin->value,
        ];
    }
}
