/*
 * Lignes d'ingrédients et d'étapes d'une recette : les déplacer ou en retirer une ne doit jamais
 * dupliquer une ligne ni en écraser une autre.
 *
 * Le piège : chaque champ est relié à la ligne par sa position (form.ingredients.2.quantity).
 * Quand une ligne change de place, son champ doit être relié à sa nouvelle position, sinon ce qu'on
 * y tape part dans une autre ligne, et la ligne affiche les valeurs d'une autre.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open } from './helpers.js';

test.beforeEach(({}, testInfo) => {
    test.skip(!testInfo.project.name.startsWith('ordinateur'), 'glisser-déposer : sur ordinateur');
});

const ingredientRows = (page) => page.locator('ul[wire\\:sort="sortIngredients"] > li');
const stepRows = (page) => page.locator('ol[wire\\:sort="sortSteps"] > li');

/** Ce qu'affiche chaque ligne d'ingrédient, dans l'ordre : « quantité nom ». */
async function ingredientValues(page) {
    return ingredientRows(page).evaluateAll((rows) => rows.map((row) => [
        row.querySelector('input[aria-label="Quantité"]').value,
        row.querySelector('input[aria-label="Ingrédient"]').value,
    ].join(' ').trim()));
}

async function stepValues(page) {
    return stepRows(page).evaluateAll((rows) => rows.map((row) => row.querySelector('textarea').value.trim()));
}

/** Livewire a fini de répondre (le déplacement passe par le serveur). */
async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(150);
}

/** Glisse la poignée d'une ligne sur une autre ligne (SortableJS, glisser-déposer du navigateur). */
async function drag(page, rows, from, to) {
    const handle = rows.nth(from).locator('[wire\\:sort\\:handle]');
    const target = rows.nth(to);
    const box = await target.boundingBox();
    // Vers le haut : on vise le haut de la ligne cible ; vers le bas : son bas.
    const y = to < from ? box.y + 4 : box.y + box.height - 4;
    await handle.dragTo(target, { targetPosition: { x: box.width / 2, y: y - box.y } });
    await settle(page);
}

async function fillIngredients(page, lines) {
    const rows = ingredientRows(page);
    for (const [i, [quantity, name]] of lines.entries()) {
        if (await rows.count() <= i) {
            await page.getByRole('button', { name: 'Ajouter un ingrédient' }).click();
            await expect(rows).toHaveCount(i + 1);
        }
        await rows.nth(i).getByLabel('Quantité').fill(quantity);
        await rows.nth(i).getByLabel('Ingrédient').fill(name);
        await rows.nth(i).getByLabel('Ingrédient').blur();
    }
    await settle(page);
}

test('déplacer un ingrédient ne duplique ni n\'écrase aucune ligne, avant et après l\'enregistrement', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/recettes/nouvelle');
    await page.getByLabel('Titre').fill(`Essai ordre des ingrédients ${Date.now()}`);

    await fillIngredients(page, [['100', 'Farine'], ['50', 'Sucre'], ['25', 'Beurre'], ['2', 'Œuf']]);
    expect(await ingredientValues(page)).toEqual(['100 Farine', '50 Sucre', '25 Beurre', '2 Œuf']);

    // Le beurre passe en premier : chaque ligne garde ses valeurs.
    await drag(page, ingredientRows(page), 2, 0);
    expect(await ingredientValues(page)).toEqual(['25 Beurre', '100 Farine', '50 Sucre', '2 Œuf']);

    // On modifie la ligne déplacée, puis une ligne qui a glissé d'un cran : la saisie va à la bonne ligne.
    await ingredientRows(page).nth(0).getByLabel('Quantité').fill('30');
    await ingredientRows(page).nth(2).getByLabel('Quantité').fill('60');
    expect(await ingredientValues(page)).toEqual(['30 Beurre', '100 Farine', '60 Sucre', '2 Œuf']);

    // Second déplacement, vers le bas cette fois, avec une saisie en attente juste avant.
    await ingredientRows(page).nth(3).getByLabel('Quantité').fill('3');
    await drag(page, ingredientRows(page), 0, 3);
    expect(await ingredientValues(page)).toEqual(['100 Farine', '60 Sucre', '3 Œuf', '30 Beurre']);

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL((url) => /^\/recettes\/[^/]+$/.test(url.pathname) && !url.pathname.endsWith("/nouvelle"));

    await open(page, new URL(page.url()).pathname + '/modifier');
    expect(await ingredientValues(page)).toEqual(['100 Farine', '60 Sucre', '3 Œuf', '30 Beurre']);

    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('retirer un ingrédient : les lignes suivantes restent reliées à leurs propres valeurs', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/recettes/nouvelle');
    await page.getByLabel('Titre').fill(`Essai retrait d'un ingrédient ${Date.now()}`);

    await fillIngredients(page, [['1', 'Farine'], ['2', 'Sucre'], ['3', 'Beurre']]);
    await ingredientRows(page).nth(0).getByRole('button', { name: 'Retirer' }).click();
    await settle(page);
    expect(await ingredientValues(page)).toEqual(['2 Sucre', '3 Beurre']);

    await ingredientRows(page).nth(1).getByLabel('Quantité').fill('4');
    expect(await ingredientValues(page)).toEqual(['2 Sucre', '4 Beurre']);

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL((url) => /^\/recettes\/[^/]+$/.test(url.pathname) && !url.pathname.endsWith("/nouvelle"));
    await open(page, new URL(page.url()).pathname + '/modifier');
    expect(await ingredientValues(page)).toEqual(['2 Sucre', '4 Beurre']);

    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('déplacer une étape garde le texte de chaque étape', async ({ page }) => {
    const errors = watchErrors(page);
    await open(page, '/recettes/nouvelle');
    await page.getByLabel('Titre').fill(`Essai ordre des étapes ${Date.now()}`);
    await fillIngredients(page, [['1', 'Farine']]);

    const steps = ['Préchauffer le four.', 'Mélanger.', 'Enfourner 20 minutes.'];
    for (const [i, text] of steps.entries()) {
        if (await stepRows(page).count() <= i) {
            await page.getByRole('button', { name: 'Ajouter une étape' }).click();
            await expect(stepRows(page)).toHaveCount(i + 1);
        }
        await stepRows(page).nth(i).locator('textarea').fill(text);
    }

    await drag(page, stepRows(page), 2, 0);
    expect(await stepValues(page)).toEqual(['Enfourner 20 minutes.', 'Préchauffer le four.', 'Mélanger.']);

    await stepRows(page).nth(0).locator('textarea').fill('Enfourner 25 minutes.');
    expect(await stepValues(page)).toEqual(['Enfourner 25 minutes.', 'Préchauffer le four.', 'Mélanger.']);

    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await page.waitForURL((url) => /^\/recettes\/[^/]+$/.test(url.pathname) && !url.pathname.endsWith("/nouvelle"));
    await open(page, new URL(page.url()).pathname + '/modifier');
    expect(await stepValues(page)).toEqual(['Enfourner 25 minutes.', 'Préchauffer le four.', 'Mélanger.']);

    expect(errors, 'erreurs dans la console').toEqual([]);
});
