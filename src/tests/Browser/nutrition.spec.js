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
    // L'adresse change avant que la liste filtrée n'arrive : on attend la liste elle-même.
    const letters = () => page.locator('main article [data-nutri-score]').evaluateAll((els) => els.map((el) => el.dataset.nutriScore));
    await expect.poll(async () => {
        const list = await letters();
        return list.length > 0 && list.every((letter) => letter === 'A' || letter === 'B');
    }, { message: 'seulement des recettes A ou B' }).toBe(true);

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

test('pastille Nutri-Score : la lettre est au milieu de la pastille', async ({ page }) => {
    await open(page, '/recettes');
    await page.locator('main article a').first().click();
    await page.waitForLoadState('networkidle');

    // Pastilles de la liste (petites) et de la fiche (moyenne) : la lettre, rognée à sa hauteur de
    // capitale, a le même centre que le disque coloré.
    for (const path of ['/recettes', page.url()]) {
        await open(page, new URL(path, page.url()).pathname);
        const offsets = await page.locator('main [style*="background:#"]').evaluateAll((discs) => discs
            .filter((disc) => disc.offsetParent !== null)
            .map((disc) => {
                const glyph = disc.querySelector('span:not(.sr-only)');
                if (! glyph) return { letter: disc.textContent.trim(), dy: 99 };
                const d = disc.getBoundingClientRect();
                const g = glyph.getBoundingClientRect();
                return {
                    letter: glyph.textContent.trim(),
                    dy: Math.abs((g.top + g.bottom) / 2 - (d.top + d.bottom) / 2),
                    capRatio: g.height / parseFloat(getComputedStyle(glyph).fontSize),
                };
            }));
        expect(offsets.length, `pastilles sur ${path}`).toBeGreaterThan(0);
        for (const o of offsets) {
            expect(o.dy, `lettre ${o.letter} centrée`).toBeLessThanOrEqual(0.75);
            expect(o.capRatio, `lettre ${o.letter} rognée à la hauteur de capitale`).toBeLessThan(0.85);
        }
    }
});
