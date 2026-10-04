/*
 * Connexion du compte d'essai, gardée pour tous les tests (cookie de session).
 */
import { test as setup, expect } from '@playwright/test';

setup('connexion du compte d\'essai', async ({ page }) => {
    await page.goto('/connexion');
    await page.fill('input[type=email]', 'demo@bouffe.test');
    await page.fill('input[type=password]', 'essai-navigateur');
    await page.click('form button[type=submit]');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Camille');
    await page.context().storageState({ path: 'storage/framework/testing/browser-state.json' });
});
