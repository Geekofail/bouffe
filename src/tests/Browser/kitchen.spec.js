/*
 * Écran de cuisine (lot 35, 35.1) : les minuteurs lancés en mode cuisine s'y retrouvent ; les
 * minuteurs rapides s'ajoutent et s'arrêtent. Depuis le lot 41, ils sont partagés par le serveur
 * (tous les appareils du foyer), et gardés dans l'appareil quand il n'y a pas de réseau.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open } from './helpers.js';

test('écran de cuisine : minuteurs du mode cuisine et minuteurs rapides', async ({ page, context }) => {
    const errors = watchErrors(page);
    await open(page, '/');
    // Comme le bouton « Minuteur » d'une étape en mode cuisine.
    await page.evaluate(() => window.bouffeTimerStore.add(25, 'Étape 3 · cuisson', 'recette:1'));

    await open(page, '/cuisine');
    const timers = page.locator('section[aria-labelledby="k-timers"]');
    await expect(timers.getByText('Étape 3 · cuisson')).toBeVisible();
    await expect(timers.getByText('lancé par vous').first()).toBeVisible();

    await timers.getByRole('button', { name: '+5' }).click();
    await expect(timers.getByText('5 min', { exact: true })).toBeVisible();

    await timers.locator('div', { hasText: 'Étape 3 · cuisson' }).last().getByRole('button', { name: 'Arrêter' }).click();
    await expect(timers.getByText('Étape 3 · cuisson')).toHaveCount(0);
    await timers.locator('div', { hasText: '5 min' }).last().getByRole('button', { name: 'Arrêter' }).click();
    await expect(timers.getByText('5 min', { exact: true })).toHaveCount(0);

    // Sans réseau : le minuteur reste dans l'appareil, et sonne quand même.
    await context.setOffline(true);
    await timers.getByRole('button', { name: '+10' }).click();
    await expect(timers.getByText('sur cet appareil (sans réseau)')).toBeVisible();
    await timers.locator('div', { hasText: '10 min' }).last().getByRole('button', { name: 'Arrêter' }).click();
    await context.setOffline(false);

    expect(errors.filter((e) => !/Failed to fetch|ERR_INTERNET_DISCONNECTED|net::/.test(e)), 'erreurs dans la console').toEqual([]);
});

test('écran de cuisine : sombre le soir, quel que soit le thème', async ({ page }) => {
    await page.clock.install({ time: new Date('2026-10-15T21:30:00') });
    await open(page, '/cuisine');
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.clock.setSystemTime(new Date('2026-10-16T08:00:00'));
    await page.clock.runFor(16000);
    const dark = await page.evaluate(() => {
        const theme = localStorage.getItem('bouffe-theme') || 'auto';
        return theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    });
    await expect(page.locator('html')).toHaveClass(dark ? /dark/ : /^(?!.*\bdark\b).*$/);
});
