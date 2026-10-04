/*
 * Les écrans principaux, sur iPhone (sombre) et sur ordinateur (clair) — lot 28, 28.7.
 * Pour chacun : la page s'ouvre, sans erreur JavaScript ni refus de la politique de contenu,
 * sans débordement horizontal, sans défaut d'accessibilité grave ; sur téléphone, des cibles
 * tactiles d'au moins 24 px. Une capture est jointe au rapport.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open, expectNoOverflow, expectAccessible, expectTouchTargets } from './helpers.js';

const PAGES = [
    ['Accueil', '/'],
    ['Recettes', '/recettes'],
    ['Planning', '/planning'],
    ['Listes de courses', '/courses'],
    ['Stock', '/stock'],
    ['Que cuisiner ?', '/que-cuisiner'],
    ['Budget', '/budget'],
    ['Prix et magasins', '/prix'],
    ['Réceptions', '/receptions'],
    ['Tickets', '/tickets'],
    ['Invités', '/invites'],
    ['Paramètres', '/parametres'],
    ['Mon compte', '/compte'],
    ['Proches', '/proches'],
    ['Journal du foyer', '/foyer/journal'],
    ['Revue du stock', '/stock/revue'],
    ['Foyer (à table d\'habitude)', '/parametres/foyer'],
    ['Gamelles du midi', '/planning/gamelles'],
    ['Collections', '/recettes/collections'],
    ['Une collection', '/recettes/collections/1'],
    ['Séjours', '/sejours'],
    ['Un séjour : participants', '/sejours/1'],
    ['Un séjour : repas', '/sejours/1?onglet=repas'],
    ['Un séjour : courses', '/sejours/1?onglet=courses'],
    ['Un séjour : à emporter', '/sejours/1?onglet=emporter'],
    ['Un séjour : frais', '/sejours/1?onglet=frais'],
    ['Un séjour : qui apporte quoi', '/sejours/1?onglet=apporter'],   // lot 42
    ['Écran de cuisine', '/cuisine'],
    ['Statistiques de planning', '/planning/statistiques'],
    ['L\'année en cuisine', '/planning/annee'],
];

for (const [name, path] of PAGES) {
    test(`${name} (${path})`, async ({ page }, testInfo) => {
        const errors = watchErrors(page);
        await open(page, path);

        await expectNoOverflow(page);
        await expectAccessible(page);
        if (testInfo.project.name.startsWith('iphone')) {
            await expectTouchTargets(page);
        }
        expect(errors, 'erreurs dans la console').toEqual([]);

        await testInfo.attach('capture', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    });
}

test('une fiche recette et une liste de courses', async ({ page }) => {
    const errors = watchErrors(page);

    await open(page, '/recettes');
    await page.getByRole('link', { name: 'Quiche lorraine' }).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Quiche lorraine');
    await expectNoOverflow(page);
    await expectAccessible(page);

    await open(page, '/courses');
    await page.getByRole('link', { name: /Courses du/ }).first().click();
    await page.waitForLoadState('networkidle');
    await expectNoOverflow(page);
    await expectAccessible(page);

    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('lot 31 : cuisiner le repas complet depuis l\'accueil, et l\'historique d\'une recette', async ({ page }, testInfo) => {
    const errors = watchErrors(page);

    await open(page, '/');
    await page.getByRole('link', { name: /Cuisiner tout le repas/ }).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Cuisiner le repas');
    await expect(page.getByText('À table !')).toBeVisible();
    await expectNoOverflow(page);
    await expectAccessible(page);
    await testInfo.attach('repas complet', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });

    await open(page, '/recettes');
    await page.getByRole('link', { name: 'Quiche lorraine' }).first().click();
    await page.waitForLoadState('networkidle');
    const history = await page.locator('a[href$="/historique"]').first().getAttribute('href');
    await open(page, new URL(history).pathname);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Historique');
    await expectNoOverflow(page);
    await expectAccessible(page);

    expect(errors, 'erreurs dans la console').toEqual([]);
});
