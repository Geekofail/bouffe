/*
 * Lot 40 — Recettes qui s'adaptent : remplacements, notes d'étape, équipement. Données d'essai inventées.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('Paramètres › Remplacements : la liste commune, en ajouter un', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/parametres/remplacements');

    const list = page.locator('[data-substitutions]');
    await expect(list).toContainText('Crème liquide');
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('remplacements', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    await page.getByLabel('Ingrédient', { exact: true }).selectOption({ label: 'Pomme de terre' });
    await page.getByLabel('Remplacé par').selectOption({ label: 'Patate douce' });
    await page.locator('[data-substitution-form]').getByRole('button', { name: 'Ajouter' }).click();
    await expect(list).toContainText('patate douce');
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('Paramètres › Équipement : sans four, les recettes au four sont écartées', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/parametres/equipement');
    await page.getByLabel('Four', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('heading', { name: /Pas faisables ici/ })).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);

    // On remet le four, pour les autres vérifications.
    await page.getByLabel('Four', { exact: true }).check();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('heading', { name: /Pas faisables ici/ })).toHaveCount(0);
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('mode cuisine : « pas de lait ? » et une note du foyer sur une étape', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/recettes');
    await page.getByRole('link', { name: 'Crêpes' }).first().click();
    await page.waitForLoadState('networkidle');
    await page.locator('[data-recipe-phone-actions], main').getByRole('link', { name: 'Cuisiner' }).first().click();
    await page.waitForLoadState('networkidle');

    const subs = page.locator('[data-substitutes]').first();
    await expect(subs).toBeVisible();
    await expectAccessible(page);
    await testInfo.attach('remplacements en cuisine', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    // Ce qui n'est pas en stock est replié : on déplie.
    if (await subs.locator('details').count()) {
        await subs.locator('details summary').first().click();
    }
    await subs.getByRole('button', { name: 'Je l\'utilise' }).first().click();

    await page.getByRole('button', { name: /Commencer/ }).click();
    const notes = page.locator('[data-step-notes]');
    await notes.getByRole('button', { name: 'Ajouter une note à cette étape' }).click();
    const text = 'Note d\'essai ' + testInfo.project.name;
    await notes.getByLabel(/Note sur l'étape/).fill(text);
    await notes.getByRole('button', { name: 'Ajouter' }).click();
    await expect(notes).toContainText(text);
    await expectNoOverflow(page);
    expect(errors, 'erreurs dans la console').toEqual([]);
});
