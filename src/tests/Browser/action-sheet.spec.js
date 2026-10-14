/*
 * Menu « Plus » (<x-action-sheet>) : sur ordinateur, un menu sous le bouton, jamais coupé par le bloc
 * qui le contient (la carte d'en-tête d'une recette masque ce qui dépasse) ; sur téléphone, une feuille
 * en bas de l'écran.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open } from './helpers.js';

/** Le point au centre de l'élément est bien sur lui (rien ne le coupe ni ne le recouvre). */
async function expectReachable(page, locator) {
    const box = await locator.boundingBox();
    const hit = await page.evaluate(([x, y]) => {
        const el = document.elementFromPoint(x, y);
        return el?.closest('[role="menu"]') ? (el.closest('.menu-item')?.textContent ?? '').trim() : null;
    }, [box.x + box.width / 2, box.y + box.height / 2]);
    expect(hit, 'élément du menu visible et cliquable').toBe((await locator.textContent()).trim());
}

test('recette : le menu « Plus » s\'affiche en entier, sous le bouton', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    const phone = testInfo.project.name.startsWith('iphone');
    await open(page, '/recettes');
    await page.locator('main article a').first().click();
    await page.waitForLoadState('networkidle');

    const actions = page.locator(phone ? '[data-recipe-phone-actions]' : 'div.sm\\:flex:has(> a:has-text("Cuisiner"))');
    const button = actions.getByRole('button', { name: 'Plus' });
    const menu = actions.locator('[data-action-menu]');

    await button.click();
    await expect(menu).toBeVisible();
    // La feuille glisse vers le haut à l'ouverture : on mesure une fois arrivée.
    await menu.evaluate((el) => Promise.all(el.getAnimations().map((a) => a.finished)));
    const items = menu.locator('.menu-item');
    await expect(items.first()).toBeVisible();

    const viewport = page.viewportSize();
    const box = await menu.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);

    // Sans rien faire défiler : un bloc qui masque ce qui dépasse se laisserait défiler par le test
    // et cacherait le défaut.
    for (const item of await items.all()) {
        await expectReachable(page, item);
    }

    if (phone) {
        // Feuille en bas de l'écran, sur toute la largeur.
        expect(Math.round(box.y + box.height)).toBeGreaterThanOrEqual(viewport.height - 1);
        expect(box.width).toBeGreaterThanOrEqual(viewport.width - 1);
    } else {
        // Sous le bouton, aligné à sa droite… et il le suit quand la page défile.
        const check = async () => {
            const b = await button.boundingBox();
            const m = await menu.boundingBox();
            expect(Math.abs(m.x + m.width - (b.x + b.width))).toBeLessThanOrEqual(1);
            expect(m.y).toBeGreaterThanOrEqual(b.y + b.height);
            expect(m.y - (b.y + b.height)).toBeLessThanOrEqual(8);
        };
        await check();
        await page.mouse.wheel(0, 120);
        await page.waitForTimeout(150);
        await check();
    }

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    expect(errors, 'erreurs dans la console').toEqual([]);
});
