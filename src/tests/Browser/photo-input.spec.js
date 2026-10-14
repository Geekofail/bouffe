/*
 * Photo depuis un téléphone : « Prendre une photo » ouvre directement l'appareil photo
 * (capture="environment"), à côté du choix d'une photo existante. Sur ordinateur, seul le choix
 * d'un fichier est proposé.
 */
import { test, expect } from '@playwright/test';
import { watchErrors, open } from './helpers.js';

// Une petite photo JPEG (40 × 30 px), comme prise par le téléphone.
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdBRkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0//wAARCAAeACgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwCaiiivGPaCiiigAooooAKKKKACiiigAooooA//2Q==', 'base64');

test('recette : appareil photo sur téléphone, choix d\'un fichier sur ordinateur', async ({ page }, testInfo) => {
    const errors = watchErrors(page);
    const phone = testInfo.project.name.startsWith('iphone');
    await open(page, '/recettes/nouvelle');

    const picker = page.locator('[data-photo-input]').first();
    const camera = picker.locator('[data-photo-camera]');
    const file = picker.locator('[data-photo-file]');

    await expect(camera.locator('input[type="file"]')).toHaveAttribute('capture', 'environment');
    await expect(file.locator('input[type="file"]')).not.toHaveAttribute('capture', /.*/);
    await expect(file).toBeVisible();

    if (phone) {
        await expect(camera).toBeVisible();
        await expect(camera).toHaveText(/Prendre une photo/);
        await expect(file).toHaveText(/Choisir une photo/);

        // La photo prise remplit le formulaire comme une photo choisie : l'aperçu s'affiche.
        await camera.locator('input').setInputFiles({ name: 'photo.jpg', mimeType: 'image/jpeg', buffer: JPEG });
        await expect(page.getByAltText('Aperçu de la photo')).toBeVisible();
    } else {
        await expect(camera).toBeHidden();
        await expect(file).toHaveText(/Choisir une photo/);
    }

    expect(errors, 'erreurs dans la console').toEqual([]);
});

test('ticket de caisse : « Prendre une photo » seulement sur téléphone', async ({ page }, testInfo) => {
    const phone = testInfo.project.name.startsWith('iphone');
    await open(page, '/tickets/nouveau');

    const camera = page.locator('input[capture="environment"]');
    await expect(camera).toHaveCount(1);
    if (phone) {
        await expect(page.getByText('Prendre une photo')).toBeVisible();
    } else {
        await expect(page.getByText('Prendre une photo')).toBeHidden();
    }
    await expect(page.getByText('Choisir un fichier')).toBeVisible();
});
