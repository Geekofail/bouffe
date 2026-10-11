/*
 * Lot 43 — Nutrition : Nutri-Score estimé sur la fiche et la liste, équilibre de la semaine,
 * source Ciqual dans les paramètres. Recettes et semaine d'essai inventées ; valeurs nutritionnelles
 * réelles (table Ciqual 2025 de l'Anses).
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('fiche recette › Nutri-Score estimé, repères et « pourquoi »', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/recettes/veloute-de-potiron');

    const block = page.locator('[data-nutrition]');
    await expect(block.locator('summary [data-nutri-score]')).toBeVisible();
    await expect(block).toContainText('Par portion');
    await block.getByText(/Pourquoi « [A-E] » \?/).click();
    await expect(block.locator('[data-nutri-score-detail]')).toContainText('Fruits, légumes, légumineuses');
    await expect(block).toContainText('Source : Anses');
    await block.scrollIntoViewIfNeeded();

    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('fiche nutrition', { body: await block.screenshot(), contentType: 'image/png' });
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('liste des recettes › filtre « Nutri-Score A ou B »', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/recettes');

    if (testInfo.project.name.startsWith('iphone')) {
        await page.getByRole('button', { name: /Filtres/ }).click();
    }
    await page.getByLabel('Nutri-Score estimé').selectOption('AB');
    await expect(page).toHaveURL(/nutri=AB/);
    await expect(page.locator('main [data-nutri-score]').first()).toBeVisible();
    const letters = await page.locator('main article [data-nutri-score]').evaluateAll((els) => els.map((el) => el.dataset.nutriScore));
    expect(letters.length).toBeGreaterThan(0);
    expect(letters.every((letter) => letter === 'A' || letter === 'B')).toBe(true);

    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('liste filtrée', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('planning › équilibre de la semaine avec le Nutri-Score moyen', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/planning');

    if (testInfo.project.name.startsWith('iphone')) {
        await page.locator('[data-week-infos] > button').click();
    }
    await page.getByText('Équilibre de la semaine').click();
    const week = page.locator('[data-week-nutrition]');
    await expect(week).toBeVisible();
    await expect(week).toContainText('repas chiffré');
    await expect(week).toContainText('Sel / repas');

    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('équilibre', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('paramètres › nutrition : source Anses et origine des associations', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/parametres/nutrition?filtre=associes');

    await expect(page.locator('[data-attribution]')).toContainText('Source : Anses');
    await expect(page.locator('main')).toContainText('catalogue');

    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('paramètres nutrition', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    expect(errors, 'erreurs dans la console').toEqual([]);
});
