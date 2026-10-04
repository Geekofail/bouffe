/*
 * Lot 38 — Raccourcis, voix et partage : la page des raccourcis, « À trier », le mode cuisine mains libres.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

// Une photo JPEG minuscule (1 × 1 pixel), comme une page de livre prise au téléphone.
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');

test('Mon compte › Raccourcis : créer le jeton, le voir une fois, le révoquer', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/compte/raccourcis');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Raccourcis et Siri');

    await page.getByRole('button', { name: 'Créer mon jeton' }).click();
    await expect(page.getByLabel('Valeur de l\'en-tête Authorization')).toHaveValue(/^Bearer bouffe_/);
    await expect(page.getByText('Ajouter aux courses', { exact: true })).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('raccourcis', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Révoquer' }).click();
    await expect(page.getByRole('button', { name: 'Créer mon jeton' })).toBeVisible();
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('« À trier » : garder un texte et une photo pour plus tard, puis les jeter', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/recettes/a-trier');

    await page.getByLabel('Une adresse, ou le texte d\'une recette').fill('Crumble aux pommes\n4 pommes\n100 g de beurre\nCuire 30 minutes.');
    await page.getByRole('button', { name: 'Garder pour plus tard' }).click();
    const list = page.locator('[data-inbox]');
    await expect(list.getByText('Crumble aux pommes', { exact: true })).toBeVisible();

    await page.locator('[data-inbox-photo]').setInputFiles({ name: 'page.jpg', mimeType: 'image/jpeg', buffer: JPEG });
    await expect(list.getByText('Photo d\'une recette')).toBeVisible({ timeout: 10000 });

    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('à trier', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    // Jeter les deux, l'un après l'autre (chaque geste attend que la liste ait changé).
    const items = list.locator('> li');
    const count = await items.count();
    for (let left = count; left > 0; left--) {
        await items.first().getByRole('button', { name: 'Jeter' }).click();
        if (left > 1) await expect(items).toHaveCount(left - 1);
    }
    await expect(page.getByText('Rien à trier')).toBeVisible();
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('mode cuisine mains libres : un toucher passe à l\'étape suivante', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/recettes');
    await page.getByRole('link', { name: 'Quiche lorraine' }).first().click();
    await page.waitForLoadState('networkidle');
    await page.locator('[data-recipe-phone-actions], main').getByRole('link', { name: 'Cuisiner' }).first().click();
    await page.waitForLoadState('networkidle');

    const handsFree = page.locator('[data-hands-free]');
    await expect(handsFree).toHaveText(/Lire à voix haute/);
    await handsFree.click();
    await expect(handsFree).toHaveAttribute('aria-pressed', 'true');

    // Un toucher sur le texte de la mise en place : étape 1.
    await page.getByRole('heading', { name: 'Mise en place' }).click();
    await expect(page.getByRole('heading', { name: /Étape 1/ })).toBeVisible();

    await handsFree.click();
    await expect(handsFree).toHaveAttribute('aria-pressed', 'false');
    expect(errors, 'erreurs dans la console').toEqual([]);
});
