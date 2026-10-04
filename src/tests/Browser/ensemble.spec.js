/*
 * Lot 42 — Ensemble : qui apporte quoi (avec le lien pour ceux qui n'ont pas Bouffe), et les
 * courses à deux en magasin. Données d'essai inventées.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

test('séjour › qui apporte quoi : une ligne, le lien, une inscription sans compte', async ({ page, browser }, testInfo) => {
    const errors = watchErrors(page);
    const what = 'Pain ' + testInfo.project.name;
    await open(page, '/sejours/1?onglet=apporter');

    const board = page.locator('[data-contributions]');
    await board.getByLabel('À apporter').fill(what);
    await board.getByLabel('Qui l\'apporte ? (facultatif)').fill('Alex');
    await board.locator('[data-contribution-form]').getByRole('button', { name: 'Ajouter' }).click();
    await expect(board.locator('[data-contribution-rows]')).toContainText(what);

    const link = board.locator('[data-contribution-link]');
    if (await link.getByRole('button', { name: 'Créer le lien' }).count()) {
        await link.getByRole('button', { name: 'Créer le lien' }).click();
    }
    const url = await link.getByLabel('Lien « qui apporte quoi »').inputValue();
    expect(url).toContain('/apporter/');
    await expectNoOverflow(page);
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('qui apporte quoi', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    // Quelqu'un sans compte ouvre le lien (navigateur vierge).
    const guest = await browser.newContext({ ...testInfo.project.use, storageState: undefined });
    const other = await guest.newPage();
    const guestErrors = watchErrors(other);
    await other.goto(url);
    await expect(other.locator('[data-bring]')).toContainText(what);
    await other.getByLabel('Quoi').fill('Gâteau ' + testInfo.project.name);
    await other.getByLabel('Votre prénom').fill('Sam');
    await other.getByRole('button', { name: 'Ajouter' }).click();
    await expect(other.getByRole('status')).toContainText('Merci');
    await expect(other.locator('[data-bring]')).toContainText('Sam');
    await expectNoOverflow(other);
    await expectAccessible(other);
    await testInfo.attach('lien sans compte', { body: await other.screenshot({ fullPage: true }), contentType: 'image/png' });
    expect(guestErrors, 'erreurs dans la console (lien)').toEqual([]);
    await guest.close();

    await page.reload();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-contribution-rows]')).toContainText('Sam');
    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('mode magasin : « On se partage ? », mes rayons d\'abord', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    await open(page, '/sejours/1?onglet=courses');
    await page.getByRole('link', { name: 'Mode magasin' }).click();
    await page.waitForLoadState('networkidle');

    await page.locator('[data-split-toggle]').click();
    const panel = page.locator('[data-split-panel]');
    await expect(panel).toBeVisible();
    await panel.getByRole('button', { name: 'Moi' }).first().click();
    await expect(page.getByRole('heading', { name: 'Vos rayons' })).toBeVisible();
    await expectAccessible(page);
    if (testInfo.project.name.startsWith('iphone')) {
        await expectTouchTargets(page);
    }
    await testInfo.attach('courses à deux', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    // On remet tout en commun pour les autres vérifications.
    await panel.getByRole('button', { name: 'Arrêter le partage' }).click();
    await expect(page.getByRole('heading', { name: 'Vos rayons' })).toHaveCount(0);
    expect(errors, 'erreurs dans la console').toEqual([]);
});
