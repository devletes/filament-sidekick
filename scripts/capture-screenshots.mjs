#!/usr/bin/env node

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(__dirname, '..');
const outDir = resolve(projectRoot, 'docs/images');

const baseUrl = process.env.WORKBENCH_URL ?? 'http://127.0.0.1:8770';

// One seeded user per panel state (see workbench/database/seeders/DatabaseSeeder.php).
// `resume` clicks "Resume last conversation" so the seeded thread is on screen.
const targets = [
    { file: 'panel', user: 'aria@example.com', path: '/admin', selector: '.sidekick-card', resume: true, isolate: true, height: 660 },
    { file: 'empty_state', user: 'mateo@example.com', path: '/admin', selector: '.sidekick-card', isolate: true, height: 600 },
    { file: 'confirm_card', user: 'hana@example.com', path: '/admin', selector: '.sidekick-card', resume: true, isolate: true, height: 620 },
    { file: 'action_outcome', user: 'noor@example.com', path: '/admin', selector: '.sidekick-card', resume: true, isolate: true, height: 620 },
    { file: 'modal_dock', user: 'ivan@example.com', path: '/admin', selector: '.sidekick-card', resume: true, isolate: true, height: 620 },
    { file: 'modal_card', user: 'ivan@example.com', path: '/admin', viewport: true, resume: true, openModal: true },
    { file: 'streaming', user: 'curt@example.com', path: '/admin', selector: '.sidekick-card', resume: true, isolate: true, height: 600 },
    { file: 'layout', user: 'aria@example.com', path: '/admin/leave-requests', viewport: true, resume: true },
    { file: 'closed', user: 'aria@example.com', path: '/admin/leave-requests', viewport: true, open: false },
];

const modes = ['light', 'dark'];
const padding = 25;
const viewport = { width: 1280, height: 900 };

async function setColorMode(page, mode) {
    await page.evaluate((m) => {
        const root = document.documentElement;
        if (m === 'dark') {
            root.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        } else {
            root.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        }
    }, mode);
}

// The workbench exposes a local-only login route: a run needs six different
// users, which the real login form throttles.
async function login(page, email) {
    await page.goto(`${baseUrl}/dev-login/${encodeURIComponent(email)}`);
    await page.waitForURL((url) => url.toString().includes('/admin'), { timeout: 15000 });
}

// One session per user, reused across that user's targets.
const authStates = new Map();

async function stateFor(browser, email) {
    if (! authStates.has(email)) {
        const context = await browser.newContext({ viewport, deviceScaleFactor: 2 });
        const page = await context.newPage();
        await login(page, email);
        authStates.set(email, await context.storageState());
        await context.close();
    }

    return authStates.get(email);
}

async function main() {
    await mkdir(outDir, { recursive: true });

    const browser = await chromium.launch();

    for (const target of targets) {
        const context = await browser.newContext({
            viewport: { ...viewport, height: target.height ?? viewport.height },
            deviceScaleFactor: 2,
            storageState: await stateFor(browser, target.user),
        });

        // The panel reads its open state before first paint, so seed it up front.
        await context.addInitScript((open) => {
            localStorage.setItem('sidekick.open', open ? '1' : '0');
        }, target.open !== false);

        const page = await context.newPage();

        await page.goto(`${baseUrl}${target.path}`);
        await page.waitForLoadState('networkidle');

        await page.waitForSelector('.sidekick-card', { timeout: 15000 });

        if (target.resume) {
            const resume = page.locator('.sidekick-empty button').first();
            await resume.waitFor({ timeout: 15000 });
            await resume.click();
            await page.waitForSelector('.sidekick-empty', { state: 'detached', timeout: 15000 });
            await page.waitForTimeout(600);
        }

        if (target.openModal) {
            await page.locator('.sidekick-action-dock button').first().click();
            await page.waitForSelector('.fi-modal-window', { timeout: 15000 });
            await page.waitForTimeout(500);
        }

        // Keep the page's own chrome out of the padding band, so the border
        // around the panel is flat page background like the sibling packages.
        if (target.isolate) {
            await page.addStyleTag({
                content: '.fi-topbar, .fi-main, .fi-sidebar { visibility: hidden !important; }',
            });
        }

        for (const mode of modes) {
            await setColorMode(page, mode);
            await page.waitForTimeout(400);

            const outPath = resolve(outDir, `${target.file}_${mode}.png`);

            if (target.viewport) {
                await page.screenshot({ path: outPath });
            } else {
                const box = await page.locator(target.selector).first().boundingBox();
                await page.screenshot({
                    path: outPath,
                    fullPage: true,
                    clip: {
                        x: Math.max(0, box.x - padding),
                        y: Math.max(0, box.y + (await page.evaluate(() => window.scrollY)) - padding),
                        width: box.width + padding * 2,
                        height: box.height + padding * 2,
                    },
                });
            }

            console.log(`saved ${outPath}`);
        }

        await context.close();
    }

    await browser.close();
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
