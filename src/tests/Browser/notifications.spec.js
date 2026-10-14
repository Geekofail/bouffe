/*
 * La cloche « Rappels et alertes » : sur téléphone, le panneau occupe toute la largeur de l'écran,
 * centré sous l'en-tête ; sur ordinateur, il s'ouvre sous la cloche. Un clic à côté ou Échap le ferme.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectAccessible } from './helpers.js';

test('panneau de la cloche : lisible et entier à l\'écran, fermé d\'un clic à côté ou par Échap', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    const phone = testInfo.project.name.startsWith('iphone');
    await open(page, '/');

    const bell = page.getByRole('button', { name: 'Rappels et alertes' });
    const panel = page.locator('[data-notifications-panel]');

    await bell.click();
    await expect(panel).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'À ne pas oublier' })).toBeVisible();

    const box = await panel.boundingBox();
    const viewport = page.viewportSize();
    const header = await page.locator('header').first().boundingBox();

    // Entièrement à l'écran.
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);

    if (phone) {
        // Sous l'en-tête, toute la largeur, avec la même marge des deux côtés.
        expect(box.y).toBeGreaterThanOrEqual(header.y + header.height);
        const right = viewport.width - (box.x + box.width);
        expect(box.x).toBeGreaterThanOrEqual(8);
        expect(Math.abs(box.x - right)).toBeLessThanOrEqual(1);
        expect(box.width).toBeGreaterThan(viewport.width - 40);
    } else {
        // Sous la cloche, aligné à sa droite.
        const bellBox = await bell.boundingBox();
        expect(box.y).toBeGreaterThanOrEqual(bellBox.y + bellBox.height);
        expect(Math.abs(box.x + box.width - (bellBox.x + bellBox.width))).toBeLessThanOrEqual(1);
    }

    await expectAccessible(page);

    // Un clic sur la page, à côté du panneau, le ferme.
    await page.mouse.click(viewport.width / 2, viewport.height - 120);
    await expect(panel).toBeHidden();

    // Échap aussi ; et la cloche le rouvre puis le referme.
    await bell.click();
    await expect(panel).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await bell.click();
    await expect(panel).toBeVisible();
    await bell.click();
    await expect(panel).toBeHidden();

    expect(errors, 'erreurs dans la console').toEqual([]);
});
