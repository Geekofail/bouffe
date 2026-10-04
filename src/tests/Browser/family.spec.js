/*
 * Lot 39 — Enfants, école et cantine : les personnes du foyer, la cantine, le choix des enfants,
 * les étapes « avec un adulte ». Données d'essai inventées (Léo, sa cantine, son menu).
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('Paramètres › Foyer : les personnes, leur cantine, la fenêtre d\'une personne', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/parametres/foyer');

    const leo = page.locator('[data-person="Léo"]');
    await expect(leo).toContainText('Sans compte');
    await expect(leo).toContainText('Cantine lun, mar, jeu, ven');
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('personnes', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    await leo.getByRole('button', { name: 'Modifier' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Prénom')).toHaveValue('Léo');
    await expect(dialog.getByLabel('Jeudi')).toBeChecked();
    await expect(dialog.getByLabel('Mercredi')).not.toBeChecked();
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Annuler' }).click();
    await expect(dialog).toBeHidden();
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('Planning › Cantine : le menu de la semaine, un jour sans cantine', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/planning/cantine');

    const card = page.locator('[data-canteen-person="Léo"]');
    await expect(card).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('cantine', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    // Le premier jour : pas de cantine, puis on la remet.
    const first = card.locator('li').first();
    await first.getByRole('button', { name: 'Pas de cantine' }).click();
    await expect(first).toContainText('À la maison ce midi');
    await first.getByRole('button', { name: 'Remettre la cantine' }).click();
    await expect(first.getByRole('textbox')).toBeVisible();
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('le choix des enfants : préparé par un adulte, fait sur l\'écran de cuisine', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/planning/choix-des-enfants');
    await page.getByLabel('Qui choisit ?').selectOption({ label: 'Léo' });
    await page.getByRole('button', { name: 'Proposer 3 idées' }).click();
    await expect(page.getByLabel('Recette 1')).not.toHaveValue('');
    await page.getByRole('button', { name: 'Préparer le choix' }).click();
    await expect(page.locator('[data-open-choice]').first()).toContainText('Léo');
    await expectNoOverflow(page);
    await expectAccessible(page);

    await open(page, '/cuisine');
    await page.locator('[data-kitchen-choice]').first().click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Léo, choisis ton');
    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('choix', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    await page.locator('[data-pick]').first().click();
    await expect(page.getByText('Tu choisis')).toBeVisible();
    await page.getByRole('button', { name: 'Oui !' }).click();
    await expect(page.getByText('C\'est noté !')).toBeVisible();
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('mode cuisine : une recette « facile avec un enfant » signale les étapes pour un adulte', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/recettes?enfants=1');
    await page.getByRole('link', { name: 'Crêpes' }).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Facile avec un enfant')).toBeVisible();
    await page.locator('[data-recipe-phone-actions], main').getByRole('link', { name: 'Cuisiner' }).first().click();
    await page.waitForLoadState('networkidle');

    await page.getByRole('button', { name: /Commencer/ }).click();
    await expect(page.locator('[data-adult-step]')).toBeVisible();
    await expectAccessible(page);
    expect(errors, 'erreurs dans la console').toEqual([]);
});
