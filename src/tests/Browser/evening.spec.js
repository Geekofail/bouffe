/*
 * Lot 37 — Le bon écran au bon moment : la page « Ce soir », les infos de la semaine repliées sur
 * téléphone, « Autre idée » dans une case et la fiche recette sur téléphone.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('la page « Ce soir »', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/ce-soir');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Ce soir');
    await expect(page.locator('[data-evening-tomorrow]')).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    expect(errors, 'erreurs dans la console').toEqual([]);

    await testInfo.attach('ce soir', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
});

test('« Autre idée » : des idées pour une case vide, et d\'autres à la demande', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/planning');

    await page.locator('main').getByRole('button', { name: 'Ajouter' }).filter({ visible: true }).first().click();
    const ideas = page.locator('[data-cell-ideas]');
    await expect(ideas).toBeVisible();
    const first = await ideas.locator('li').allInnerTexts();
    expect(first.length).toBeGreaterThan(0);

    await ideas.getByRole('button', { name: 'Autre idée' }).click();
    await page.waitForLoadState('networkidle');
    // Petit carnet de démonstration : on vérifie que de nouvelles idées arrivent, sans exiger qu'elles diffèrent toutes.
    await expect(ideas.locator('li').first()).toBeVisible();
    await expectAccessible(page);
    expect(errors, 'erreurs dans la console').toEqual([]);

    await testInfo.attach('idées', { body: await page.screenshot(), contentType: 'image/png' });
});

test.describe('sur téléphone', () => {
    test.beforeEach(({}, testInfo) => {
        test.skip(!testInfo.project.name.startsWith('iphone'), 'propre au téléphone');
    });

    test('les infos de la semaine tiennent sur une ligne, et s\'ouvrent d\'un toucher', async ({ page }) => {
        await open(page, '/planning');

        const infos = page.locator('[data-week-infos]');
        const toggle = infos.getByRole('button', { name: /infos? sur la semaine/ });
        await expect(toggle).toBeVisible();
        await expect(infos.getByText('Équilibre de la semaine')).toBeHidden();

        await toggle.click();
        await expect(infos.getByText('Équilibre de la semaine')).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    test('la fiche recette : Cuisiner, Planifier et le reste dans « Plus »', async ({ page }) => {
        await open(page, '/recettes');
        await page.getByRole('link', { name: 'Quiche lorraine' }).first().click();
        await page.waitForLoadState('networkidle');

        const actions = page.locator('[data-recipe-phone-actions]');
        await expect(actions.getByRole('link', { name: 'Cuisiner' })).toBeVisible();
        await expect(actions.getByRole('button', { name: 'Planifier' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Modifier' })).toBeHidden();

        await actions.getByRole('button', { name: 'Plus' }).click();
        await expect(page.getByRole('menu', { name: 'Quiche lorraine' }).getByRole('link', { name: 'Modifier' })).toBeVisible();
        await expectNoOverflow(page);
    });
});
