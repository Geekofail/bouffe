<?php

use App\Models\OfflineOperation;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\Shopping\OfflineSync;
use App\Services\Shopping\ShoppingListManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Liste de courses hors-ligne (lot 16 — 15.1, 20.3, règle R20).
 *
 * Le téléphone envoie les gestes faits en magasin avec l'heure à laquelle ils ont eu lieu.
 * Ce qui est vérifié ici : le rejeu dans le bon ordre, le double envoi sans effet, et les
 * trois conflits prévus par R20 (article disparu, liste régénérée, geste plus récent ailleurs).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->sync = app(OfflineSync::class);
    $this->manager = app(ShoppingListManager::class);

    $this->list = ShoppingList::create(['name' => 'Semaine du 16', 'period_start' => '2026-09-16', 'period_end' => '2026-09-20']);
    $this->beurre = $this->manager->addManual($this->list, 'Beurre');
    $this->pommes = $this->manager->addManual($this->list, 'Pommes');
});

/** Un geste tel que le téléphone l'envoie. */
function gesture(string $action, ?ShoppingListItem $item = null, ?string $at = null, ?string $value = null): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'action' => $action,
        'item_id' => $item?->id,
        'label' => $item?->label,
        'value' => $value,
        'at' => $at ?? now()->toIso8601String(),
    ];
}

test('la photo de la liste contient tout ce qu\'il faut pour cocher sans réseau', function () {
    $snapshot = $this->sync->snapshot($this->list);

    expect($snapshot['id'])->toBe($this->list->id)
        ->and($snapshot['name'])->toBe('Semaine du 16')
        ->and($snapshot['items'])->toHaveCount(2);

    $beurre = collect($snapshot['items'])->firstWhere('label', 'Beurre');

    expect($beurre)->toHaveKeys(['id', 'text', 'label', 'aisle', 'aisle_id', 'checked', 'optional', 'note', 'origin'])
        ->and($beurre['checked'])->toBeFalse()
        ->and($beurre['aisle'])->not->toBe('')
        ->and(collect($snapshot['aisles'])->pluck('id'))->toContain($beurre['aisle_id']);
});

test('les gestes faits sans réseau sont rejoués au retour', function () {
    $report = $this->sync->apply($this->list, [
        gesture('check', $this->beurre, '2026-09-16T14:00:00+02:00'),
        gesture('add', null, '2026-09-16T14:02:00+02:00', 'Piles AAA'),
    ], $this->user);

    expect($report['applied'])->toBe(2)
        ->and($report['ignored'])->toBe(0)
        ->and($report['messages'])->toBe([]);

    expect($this->beurre->fresh()->is_checked)->toBeTrue()
        ->and($this->beurre->fresh()->checked_by)->toBe($this->user->id)
        ->and($this->beurre->fresh()->checked_at->format('H:i'))->toBe('14:00')   // l'heure du geste, pas celle de l'envoi
        ->and($this->list->items()->where('label', 'Piles AAA')->exists())->toBeTrue();
});

test('les gestes sont rejoués dans l\'ordre où ils ont eu lieu, pas dans l\'ordre d\'envoi', function () {
    // Le téléphone a coché puis décoché ; le réseau les fait remonter à l'envers.
    $this->sync->apply($this->list, [
        gesture('uncheck', $this->beurre, '2026-09-16T14:05:00+02:00'),
        gesture('check', $this->beurre, '2026-09-16T14:00:00+02:00'),
    ], $this->user);

    expect($this->beurre->fresh()->is_checked)->toBeFalse();
});

test('un envoi en double ne compte qu\'une fois', function () {
    $geste = gesture('add', null, null, 'Piles AAA');

    $this->sync->apply($this->list, [$geste], $this->user);
    $second = $this->sync->apply($this->list, [$geste], $this->user);

    expect($second['applied'])->toBe(0)
        ->and($this->list->items()->where('label', 'Piles AAA')->count())->toBe(1)
        ->and(OfflineOperation::count())->toBe(1);
});

test('R20 — un article retiré entre-temps : le geste est ignoré et expliqué', function () {
    $id = $this->pommes->id;
    $this->pommes->delete();

    $report = $this->sync->apply($this->list, [
        ['uuid' => (string) Str::uuid(), 'action' => 'check', 'item_id' => $id, 'label' => 'Pommes', 'at' => now()->toIso8601String()],
    ], $this->user);

    expect($report['ignored'])->toBe(1)
        ->and($report['applied'])->toBe(0)
        ->and($report['messages'][0])->toContain('retiré de la liste');
});

test('R20 — la liste a été régénérée : la coche est reportée sur le nouvel article', function () {
    $ancien = $this->beurre->id;
    $this->beurre->delete();
    $nouveau = $this->manager->addManual($this->list, 'Beurre');

    $report = $this->sync->apply($this->list, [
        ['uuid' => (string) Str::uuid(), 'action' => 'check', 'item_id' => $ancien, 'label' => 'Beurre', 'at' => now()->toIso8601String()],
    ], $this->user);

    expect($report['moved'])->toBe(1)
        ->and($report['messages'][0])->toContain('régénérée')
        ->and($nouveau->fresh()->is_checked)->toBeTrue();
});

test('R20 — quelqu\'un a coché plus récemment ailleurs : le geste du magasin ne l\'écrase pas', function () {
    // Monique coche à 15 h à la maison ; le téléphone remonte un décochage fait à 14 h.
    $this->beurre->update(['is_checked' => true, 'checked_at' => Carbon::parse('2026-09-16 15:00')]);

    $report = $this->sync->apply($this->list, [
        gesture('uncheck', $this->beurre, '2026-09-16T14:00:00+02:00'),
    ], $this->user);

    expect($report['ignored'])->toBe(1)
        ->and($report['messages'][0])->toContain('plus récemment')
        ->and($this->beurre->fresh()->is_checked)->toBeTrue();
});

test('un article vide ou une action inconnue ne casse rien', function () {
    $report = $this->sync->apply($this->list, [
        gesture('add', null, null, '   '),
        ['uuid' => (string) Str::uuid(), 'action' => 'supprimer', 'item_id' => $this->beurre->id, 'at' => now()->toIso8601String()],
    ], $this->user);

    expect($report['ignored'])->toBe(1)
        ->and($report['applied'])->toBe(0)
        ->and($this->list->items()->count())->toBe(2);
});

test('le mode magasin s\'ouvre avec la liste embarquée', function () {
    $this->get(route('shopping.store', $this->list))
        ->assertOk()
        ->assertSee('Mode magasin')
        ->assertSee('Beurre')
        ->assertSee('storeMode(', false);
});

test('la synchronisation renvoie le rapport et la liste à jour', function () {
    $response = $this->postJson(route('shopping.sync', $this->list), [
        'operations' => [gesture('check', $this->pommes)],
    ]);

    $response->assertOk()
        ->assertJsonPath('report.applied', 1)
        ->assertJsonPath('list.id', $this->list->id);

    expect(collect($response->json('list.items'))->firstWhere('label', 'Pommes')['checked'])->toBeTrue();
});

test('l\'état de la liste et le mode magasin demandent une session', function () {
    auth()->logout();

    $this->get(route('shopping.store', $this->list))->assertRedirect(route('login'));
    $this->getJson(route('shopping.state', $this->list))->assertUnauthorized();
});

test('la page hors-ligne du service worker reste accessible sans session', function () {
    auth()->logout();

    $this->get(route('offline'))->assertOk()->assertSee('Pas de réseau');
});
