/*
 * Outils communs des tests dans le navigateur (lot 28, 28.7).
 */
import { expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/** Écoute les erreurs JavaScript et les refus de la politique de contenu (CSP) d'une page. */
export function watchErrors(page) {
    const errors = [];
    page.on('pageerror', (error) => errors.push('Erreur JavaScript : ' + error.message));
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' || /Content Security Policy|Refused to/i.test(text)) {
            errors.push('Console : ' + text);
        }
    });
    return errors;
}

/** Ouvre une page et attend que Livewire ait fini. */
export async function open(page, path) {
    const response = await page.goto(path);
    await page.waitForLoadState('networkidle');
    expect(response?.status(), `statut HTTP de ${path}`).toBeLessThan(400);
}

/** Rien ne dépasse à droite : pas de défilement horizontal. */
export async function expectNoOverflow(page) {
    const { scroll, width } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, width: window.innerWidth }));
    expect(scroll, 'largeur de la page (débordement horizontal)').toBeLessThanOrEqual(width);
}

/**
 * Accessibilité (axe-core, règles WCAG 2 A et AA) : aucun défaut « critique » ou « grave »,
 * contraste des couleurs compris depuis la nouvelle palette (lot 29).
 */
export async function expectAccessible(page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .exclude('#nprogress')   // barre de chargement de Livewire (wire:navigate), passagère
        .analyze();
    const serious = results.violations
        .filter((v) => v.impact === 'critical' || v.impact === 'serious')
        .map((v) => `${v.id} (${v.impact}) : ${v.help} — ${v.nodes.slice(0, 3).map((n) => n.target.join(' ') + contrastOf(n)).join(' | ')}`);
    expect(serious, 'défauts d\'accessibilité graves').toEqual([]);
}

/** Pour un défaut de contraste : les deux couleurs et le rapport mesuré, pour savoir quoi corriger. */
function contrastOf(node) {
    const data = node.any?.[0]?.data;
    return data?.contrastRatio ? ` (${data.fgColor} sur ${data.bgColor} : ${data.contrastRatio})` : '';
}

/**
 * Cibles tactiles (28.6) : sur téléphone, un bouton ou un lien isolé fait au moins 24 × 24 px, ou
 * bien il est assez éloigné des autres pour qu'un disque de 24 px centré sur lui n'en touche aucun
 * (WCAG 2.2, critère 2.5.8, et son exception d'espacement). Les liens au fil d'un texte et les cases
 * à cocher dans leur libellé ne sont pas concernés.
 */
export async function expectTouchTargets(page) {
    const small = await page.evaluate(() => {
        const targets = [...document.querySelectorAll('main a[href], main button, main [role=button], main input, main select')]
            .filter((el) => {
                const box = el.getBoundingClientRect();
                if (box.width === 0 || box.height === 0 || getComputedStyle(el).visibility === 'hidden') return false;
                if (el.tagName === 'A' && el.closest('p, li > span, td')) return false;   // lien dans une phrase
                if (el.tagName === 'INPUT' && el.closest('label')) return false;          // le libellé est la cible
                return true;
            })
            .map((el) => ({ el, box: el.getBoundingClientRect() }));

        const circleHits = (a, b) => {
            const cx = a.box.left + a.box.width / 2;
            const cy = a.box.top + a.box.height / 2;
            const nx = Math.max(b.box.left, Math.min(cx, b.box.right));
            const ny = Math.max(b.box.top, Math.min(cy, b.box.bottom));
            return (nx - cx) ** 2 + (ny - cy) ** 2 < 12 ** 2;
        };

        return targets
            .filter((t) => (t.box.width < 24 || t.box.height < 24) && targets.some((o) => o !== t && !o.el.contains(t.el) && !t.el.contains(o.el) && circleHits(t, o)))
            .map(({ el, box }) => `${el.tagName.toLowerCase()} « ${(el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || '').trim().slice(0, 40)} » ${Math.round(box.width)}×${Math.round(box.height)}`);
    });
    expect(small, 'cibles tactiles trop petites et trop serrées').toEqual([]);
}
