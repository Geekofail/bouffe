/*
 * Avant les tests : base d'essai recréée (jamais la vraie base, voir BrowserDbCommand).
 */
import { execSync } from 'node:child_process';
import { env } from '../../playwright.config.js';

export default function globalSetup() {
    execSync('php artisan bouffe:browser-db', { env: { ...process.env, ...env }, stdio: 'inherit' });
}
