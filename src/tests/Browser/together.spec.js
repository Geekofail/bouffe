/*
 * Lot 41 — Cuisiner à plusieurs, même sans réseau : minuteurs partagés, recettes gardées dans
 * l'appareil, qui fait quoi.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('un minuteur lancé sur l\'écran de cuisine apparaît sur les autres pages, et s\'y arrête', async ({ page, context }) => {
    const errors = watchErrors(page);
    await open(page, '/cuisine');
    await page.locator('[data-timers-panel]').getByRole('button', { name: '+5' }).click();
    await expect(page.locator('[data-timers-panel]').getByText('5 min', { exact: true })).toBeVisible();

    // Un autre onglet (ou un autre appareil) : la pastille des minuteurs, en haut.
    const other = await context.newPage();
    await open(other, '/');
    const pill = other.locator('[data-timer-pill]');
    await expect(pill).toBeVisible();
    await expect(pill.getByRole('button', { name: /Minuteurs/ })).toContainText(/0[45]:\d\d/);
    await expectAccessible(other);

    await pill.getByRole('button', { name: /Minuteurs/ }).click();
    await pill.getByRole('button', { name: 'Arrêter' }).click();
    await expect(pill).toBeHidden();

    // L'écran de cuisine le voit disparaître à la lecture suivante (toutes les 5 secondes).
    await expect(page.locator('[data-timers-panel]').getByText('5 min', { exact: true })).toBeHidden({ timeout: 8000 });
    expect(errors, 'erreurs dans la console').toEqual([]);
    await other.close();
});

test('recettes sans réseau : la liste, une recette, et « Garder sur cet appareil »', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/sans-reseau');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Recettes sans réseau');
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }

    await page.getByRole('button', { name: 'Garder sur cet appareil' }).click();
    await expect(page.locator('[data-offline-status]')).toContainText('Gardé sur cet appareil', { timeout: 15000 });

    const recipe = await page.locator('main a[href*="/sans-reseau/recette/"]').first().getAttribute('href');
    const kept = await page.evaluate(async (url) => !!(await (await caches.open('bouffe-offline')).match(url)), recipe);
    expect(kept, 'recette gardée dans le cache de l\'appareil').toBe(true);

    await open(page, new URL(recipe).pathname + new URL(recipe).search);
    await expect(page.getByRole('heading', { name: 'Ingrédients' })).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    expect(errors, 'erreurs dans la console').toEqual([]);

    await testInfo.attach('recette sans réseau', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
});

test('qui fait quoi : répartir un plat dans « Cuisiner le repas »', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/');
    await page.getByRole('link', { name: /Cuisiner tout le repas/ }).first().click();
    await page.waitForLoadState('networkidle');

    const who = page.getByRole('combobox', { name: /Qui cuisine/ }).first();
    await expect(who).toBeVisible();
    await who.selectOption({ index: 2 });
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('button', { name: 'Voir mes étapes seulement' })).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    expect(errors, 'erreurs dans la console').toEqual([]);

    await testInfo.attach('qui fait quoi', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
});
