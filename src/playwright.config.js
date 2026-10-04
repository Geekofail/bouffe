/*
 * Tests dans un vrai navigateur (lot 28, 28.7) : `npm run test:browser`.
 *
 * - Une base SQLite à part (database/browser.sqlite), recréée à chaque lancement par
 *   `php artisan bouffe:browser-db` avec des données d'essai : la vraie base n'est jamais touchée.
 * - Bouffe est servi sur http://127.0.0.1:8124 avec la politique de contenu stricte (BOUFFE_CSP=enforce).
 * - Deux appareils : iPhone en mode sombre, ordinateur 1280 px en mode clair.
 *
 * Première fois sur un PC : `npm install`, puis `npx playwright install chromium`.
 */
import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const env = {
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: 'http://127.0.0.1:8124',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.resolve('database/browser.sqlite'),
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BOUFFE_CSP: 'enforce',
};

export default defineConfig({
    testDir: 'tests/Browser',
    outputDir: 'storage/framework/testing/browser',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    globalSetup: './tests/Browser/global-setup.js',
    use: {
        baseURL: env.APP_URL,
        locale: 'fr-FR',
        timezoneId: 'Europe/Luxembourg',
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8124',
        url: env.APP_URL + '/hors-ligne',
        env,
        reuseExistingServer: false,
        timeout: 60_000,
    },
    projects: [
        { name: 'connexion', testMatch: /auth\.setup\.js/ },
        {
            name: 'iphone-sombre',
            use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium', browserName: 'chromium', colorScheme: 'dark', storageState: 'storage/framework/testing/browser-state.json' },
            dependencies: ['connexion'],
        },
        {
            name: 'ordinateur-clair',
            use: { viewport: { width: 1280, height: 900 }, colorScheme: 'light', storageState: 'storage/framework/testing/browser-state.json' },
            dependencies: ['connexion'],
        },
    ],
});

export { env };
