/*
 * « Annuler » (lot 30, 30.1, R32) : vider la semaine, puis tout remettre d'un geste.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectAccessible } from './helpers.js';

test.beforeEach(({}, testInfo) => {
    test.skip(!testInfo.project.name.startsWith('ordinateur'), 'sur ordinateur');
});

test('vider la semaine puis « Annuler » remet tous les repas', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/planning');

    const meals = page.locator('[wire\\:sort\\:item]');
    const before = await meals.count();
    expect(before, 'repas de la semaine de démonstration').toBeGreaterThan(0);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('main').getByRole('button', { name: 'Vider', exact: true }).click();
    await expect(meals).toHaveCount(0);

    // Le message propose « Annuler », avec le temps qui reste.
    const undo = page.getByRole('button', { name: 'Annuler', exact: true });
    await expect(undo).toBeVisible();
    await expect(page.locator('.toast-timer')).toBeVisible();
    await expectAccessible(page);

    await undo.click();
    await expect(page.getByText(/Annulé : semaine du .* vidée\./)).toBeVisible();
    await expect(meals).toHaveCount(before);

    expect(errors, 'erreurs dans la console').toEqual([]);
});
