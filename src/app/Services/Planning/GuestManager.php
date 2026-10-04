<?php

namespace App\Services\Planning;

use App\Enums\RestrictionType;
use App\Models\Aisle;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Carnet d'invités : création, contraintes alimentaires, archivage.
 */
class GuestManager
{
    /** @var list<string> ingrédients créés lors du dernier enregistrement */
    public array $createdIngredients = [];

    /**
     * @param  array{name: string, group_name?: string|null, is_child?: bool, appetite?: string|null, notes?: string|null}  $data
     * @param  list<array{type: string, ingredient?: string|null, tag_id?: int|string|null, note?: string|null}>  $restrictions
     */
    public function save(?Guest $guest, array $data, array $restrictions = []): Guest
    {
        $this->createdIngredients = [];
        $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']));

        if ($name === '') {
            throw new InvalidArgumentException('Le nom de l\'invité est obligatoire.');
        }

        $rows = $this->normalizeRestrictions($restrictions);

        return DB::transaction(function () use ($guest, $data, $name, $rows) {
            $guest ??= new Guest;
            $guest->fill([
                'name' => mb_substr($name, 0, 100),
                'group_name' => mb_substr(trim((string) ($data['group_name'] ?? '')), 0, 50) ?: null,
                'is_child' => (bool) ($data['is_child'] ?? false),
                'appetite' => array_key_exists((string) ($data['appetite'] ?? ''), Appetites::LEVELS) ? $data['appetite'] : null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ])->save();

            $guest->restrictions()->delete();
            $guest->restrictions()->createMany($rows);

            return $guest->load('restrictions.ingredient', 'restrictions.tag');
        });
    }

    public function setArchived(Guest $guest, bool $archived): void
    {
        $guest->update(['archived_at' => $archived ? now() : null]);
    }

    /** Suppression possible seulement si l'invité n'a jamais été convive (sinon : archiver). */
    public function delete(Guest $guest): void
    {
        if ($guest->occasions()->exists()) {
            throw new InvalidArgumentException("« {$guest->name} » a déjà partagé des repas : archivez-le pour garder l'historique.");
        }

        $guest->delete();
    }

    /** @return list<string> noms de groupes existants */
    public function groupNames(): array
    {
        return Guest::query()->whereNotNull('group_name')->distinct()->orderBy('group_name')->pluck('group_name')->all();
    }

    /**
     * @return list<array{type: RestrictionType, ingredient_id: int|null, tag_id: int|null, note: string|null}>
     */
    private function normalizeRestrictions(array $restrictions): array
    {
        $rows = [];

        foreach ($restrictions as $index => $row) {
            $type = RestrictionType::tryFrom((string) ($row['type'] ?? ''));
            $position = $index + 1;

            if (! $type) {
                throw new InvalidArgumentException("Contrainte n° {$position} : type inconnu.");
            }

            $note = mb_substr(trim((string) ($row['note'] ?? '')), 0, 150) ?: null;

            if ($type->usesIngredient()) {
                $label = trim((string) ($row['ingredient'] ?? ''));

                if ($label === '') {
                    throw new InvalidArgumentException("Contrainte n° {$position} : indiquez l'ingrédient.");
                }

                $ingredient = $this->ingredient($label);
                $key = $type->value.'|i'.$ingredient->id;
                $rows[$key] = ['type' => $type, 'ingredient_id' => $ingredient->id, 'tag_id' => null, 'note' => $note];
            } else {
                $tag = Tag::find((int) ($row['tag_id'] ?? 0));

                if (! $tag) {
                    throw new InvalidArgumentException("Contrainte n° {$position} : choisissez la catégorie du régime.");
                }

                $rows[$type->value.'|t'.$tag->id] = ['type' => $type, 'ingredient_id' => null, 'tag_id' => $tag->id, 'note' => $note];
            }
        }

        // Une allergie prime sur « n'aime pas » pour le même ingrédient.
        foreach (array_keys($rows) as $key) {
            if (str_starts_with($key, RestrictionType::Dislike->value.'|') && isset($rows[RestrictionType::Allergy->value.substr($key, strlen(RestrictionType::Dislike->value))])) {
                unset($rows[$key]);
            }
        }

        return array_values($rows);
    }

    /** Ingrédient existant (insensible aux accents / pluriel), sinon créé dans le rayon Divers. */
    private function ingredient(string $label): Ingredient
    {
        $existing = Ingredient::findByName($label);

        if ($existing) {
            return $existing;
        }

        $aisleId = Aisle::where('name', 'Divers')->value('id') ?? Aisle::query()->orderBy('sort_order')->value('id');

        if (! $aisleId) {
            throw new InvalidArgumentException('Aucun rayon : chargez les données de départ (php artisan db:seed).');
        }

        $name = mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
        $this->createdIngredients[] = $name;

        return Ingredient::create(['name' => mb_substr($name, 0, 150), 'aisle_id' => $aisleId]);
    }
}
