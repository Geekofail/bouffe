/*
 * Confort sur téléphone (lot 28) : planning jour par jour, menu « Plus », filtres repliés.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open } from './helpers.js';

test.beforeEach(({}, testInfo) => {
    test.skip(!testInfo.project.name.startsWith('iphone'), 'propre au téléphone');
});

test('le planning montre un jour à la fois, et le garde après une action', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/planning');

    const days = page.locator('[data-day-index]');
    await expect(days.filter({ visible: true })).toHaveCount(1);

    // Jeudi : un seul jour affiché, celui-là.
    await page.locator('[data-day-chip="3"]').click();
    await expect(days.filter({ visible: true })).toHaveCount(1);
    await expect(page.locator('[data-day-index="3"]')).toBeVisible();

    // Une action qui recharge le composant (« Mes repas en avant ») ne ramène pas au jour d'avant.
    await page.locator('main').getByRole('button', { name: 'Plus', exact: true }).click();
    await expect(page.getByRole('menu', { name: 'Actions de la semaine' })).toBeVisible();
    await expect(page.getByRole('menu').getByText('Copier la semaine')).toBeVisible();
    await page.getByRole('button', { name: 'Mes repas en avant' }).click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-day-index="3"]')).toBeVisible();
    await expect(days.filter({ visible: true })).toHaveCount(1);

    // « Tout » : toute la semaine.
    await page.locator('[data-week-days]').getByRole('button', { name: 'Tout', exact: true }).click();
    await page.waitForLoadState('networkidle');
    await expect(days.filter({ visible: true })).toHaveCount(7);

    expect(errors).toEqual([]);
});

test('un balayage passe au jour suivant', async ({ page }) => {
    await open(page, '/planning');
    await page.locator('[data-day-chip="2"]').click();

    const box = await page.locator('[data-day-index="2"]').boundingBox();
    const y = box.y + 40;
    await page.evaluate(({ y }) => {
        const target = document.querySelector('[data-day-index="2"] header');
        const touch = (x) => new Touch({ identifier: 1, target, clientX: x, clientY: y });
        target.dispatchEvent(new TouchEvent('touchstart', { bubbles: true, changedTouches: [touch(300)], touches: [touch(300)] }));
        target.dispatchEvent(new TouchEvent('touchend', { bubbles: true, changedTouches: [touch(120)], touches: [] }));
    }, { y });

    await expect(page.locator('[data-day-index="3"]')).toBeVisible();
    await expect(page.locator('[data-day-index="2"]')).toBeHidden();
});

test('les filtres des recettes sont repliés derrière un bouton', async ({ page }) => {
    await open(page, '/recettes');
    await expect(page.getByLabel('Temps maximum')).toBeHidden();

    await page.locator('main').getByRole('button', { name: 'Filtres', exact: true }).click();
    await expect(page.getByLabel('Temps maximum')).toBeVisible();

    await page.getByRole('button', { name: 'Favoris' }).click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByLabel('Temps maximum')).toBeVisible();        // le panneau reste ouvert
    await expect(page.getByRole('button', { name: 'Filtres (1)' })).toBeVisible();
});

test('les filtres du stock sont dans une feuille', async ({ page }) => {
    await open(page, '/stock');
    await page.locator('main').getByRole('button', { name: 'Filtres', exact: true }).click();
    await page.getByRole('menu', { name: 'Filtrer le stock' }).getByRole('button', { name: 'Date dépassée' }).click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('button', { name: 'Filtres (1)' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retirer ce filtre' }).or(page.getByTitle('Retirer ce filtre'))).toBeVisible();
});
