import {chromium, webkit} from 'playwright';
import assert from 'node:assert/strict';
import {readFile, writeFile} from 'node:fs/promises';

const base = process.env.TEST_BASE_URL ?? 'http://127.0.0.1:8001';
const users = JSON.parse(await readFile('.runtime/qa-users.json', 'utf8'));
const results = [];

async function theme(page, value) {
    if (await page.locator('html').getAttribute('data-theme') !== value) await page.locator('[data-theme-toggle]').click();
    assert.equal(await page.locator('html').getAttribute('data-theme'), value);
}

for (const [name, engine] of Object.entries({chromium, webkit})) {
    const browser = await engine.launch({headless: true});
    try {
        const context = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, colorScheme: 'light', reducedMotion: 'reduce'});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base);
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
        const target = await page.locator('[data-theme-toggle]').boundingBox();
        assert.ok(target.width >= 44 && target.height >= 44);
        await page.locator('[data-theme-toggle]').tap();
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'light');
        assert.equal(await page.locator('[data-theme-toggle]').getAttribute('aria-pressed'), 'true');
        assert.equal(await page.evaluate(() => localStorage.getItem('sticker-fight:theme')), 'light');
        assert.equal((await context.cookies()).find(cookie => cookie.name === 'sf_theme')?.value, 'light');
        const next = await page.goto(base + '/designs');
        assert.match(await next.text(), /data-theme="light"/);
        assert.equal(await page.locator('meta[name="theme-color"]').getAttribute('content'), '#F7F5F8');
        const peer = await context.newPage();
        await peer.goto(base);
        await page.locator('[data-theme-toggle]').focus();
        await page.locator('[data-theme-toggle]').press('Space');
        await peer.locator('html[data-theme="dark"]').waitFor();
        await page.locator('[data-theme-toggle]').press('Enter');
        await peer.locator('html[data-theme="light"]').waitFor();
        await peer.close();

        for (const width of [360, 390, 768, 1024, 1440]) {
            await page.setViewportSize({width, height: 900});
            for (const language of ['ar', 'en']) {
                await page.goto(base + '/language/' + language + '?return_to=%2F');
                for (const mode of ['light', 'dark']) {
                    await theme(page, mode);
                    for (const path of ['/', '/products/afterhours', '/editor']) {
                        assert.equal((await page.goto(base + path)).status(), 200);
                        await page.evaluate(() => document.fonts.ready);
                        assert.equal(await page.locator('html').getAttribute('lang'), language);
                        assert.equal(await page.locator('html').getAttribute('data-theme'), mode);
                        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name}:${width}:${language}:${mode}:${path}`);
                    }
                }
            }
        }

        await page.setViewportSize({width: 390, height: 844});
        await page.goto(base + '/language/ar?return_to=%2Feditor');
        await theme(page, 'light');
        await page.locator('[data-step="2"]').click();
        await page.locator('input[name="template-front"]:enabled').first().check();
        await page.locator('[data-step="3"]').click();
        const chooser = page.waitForEvent('filechooser');
        await page.locator('#choose-image').click();
        await (await chooser).setFiles('samples/production/back-print.png');
        await page.locator('#upload-status').filter({hasText: 'تم رفع الصورة ومعالجتها.'}).waitFor();
        await page.locator('[data-tool="text"]').click();
        await page.locator('#new-text').fill('ألواني ثابتة · MY COLORS');
        await page.locator('#add-text').click();
        const saved = page.waitForResponse(response => response.url().includes('/api/designs/') && response.request().method() === 'PUT' && response.status() === 200);
        await page.locator('#save-design').click();
        await saved;
        await page.reload();
        const draft = JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
        await page.locator('#mat-color').evaluate(input => { input.value = '#9ab3c4'; input.dispatchEvent(new Event('input', {bubbles: true})); });
        const artwork = await page.locator('#canvas-container').innerHTML();
        const mat = await page.locator('#canvas-mat').evaluate(element => getComputedStyle(element).backgroundColor);
        for (const mode of ['dark', 'light']) {
            await theme(page, mode);
            assert.equal(await page.locator('#canvas-container').innerHTML(), artwork);
            assert.equal(await page.locator('#canvas-mat').evaluate(element => getComputedStyle(element).backgroundColor), mat);
        }
        const unchanged = await page.request.get(base + '/api/designs/' + draft.id);
        assert.equal(unchanged.status(), 200);
        assert.deepEqual((await unchanged.json()).document, draft.document);
        await page.locator('[data-step="4"]').click();
        await page.locator('#add-to-cart:enabled').waitFor();
        await Promise.all([page.waitForURL('**/cart'), page.locator('#add-to-cart').click()]);
        const cart = await page.locator('[data-design-preview]').first().getAttribute('data-design-preview');
        await theme(page, 'dark');
        assert.equal(await page.locator('[data-design-preview]').first().getAttribute('data-design-preview'), cart);
        await page.goto(base + '/checkout');
        await page.locator('#checkout-governorate').selectOption('1');
        await page.locator('#place-order:enabled').waitFor();
        const total = await page.locator('#checkout-summary').innerText();
        await theme(page, 'light');
        assert.equal(await page.locator('#checkout-summary').innerText(), total);
        await page.screenshot({path: `.runtime/screenshots/theme-checkout-${name}-390.png`, fullPage: true, animations: 'disabled'});

        const staff = await browser.newContext({viewport: {width: 390, height: 844}});
        const admin = await staff.newPage();
        admin.on('pageerror', error => errors.push(error.message));
        await admin.goto(base + '/login');
        await theme(admin, 'light');
        await admin.locator('form[action="/login"] [name="email"]').fill(users.admin.email);
        await admin.locator('form[action="/login"] [name="password"]').fill(users.admin.password);
        await Promise.all([admin.waitForURL('**/admin'), admin.locator('form[action="/login"] button').click()]);
        for (const width of [360, 390, 768, 1024, 1440]) {
            await admin.setViewportSize({width, height: 900});
            for (const mode of ['light', 'dark']) {
                await theme(admin, mode);
                for (const path of ['/admin', '/admin/manage/products', '/admin/settings']) {
                    const response = await admin.goto(base + path);
                    assert.equal(response.status(), 200);
                    assert.match(await response.text(), new RegExp(`data-theme="${mode}"`));
                    assert.equal(await admin.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name}:admin:${width}:${mode}:${path}`);
                }
            }
        }
        await admin.setViewportSize({width: 1440, height: 1000});
        await theme(admin, 'light');
        await admin.goto(base + '/admin');
        await admin.screenshot({path: `.runtime/screenshots/theme-admin-${name}-1440.png`, fullPage: true, animations: 'disabled'});
        await staff.close();

        const blocked = await browser.newContext();
        await blocked.addInitScript(() => Object.defineProperty(window, 'localStorage', {get() { throw new DOMException('Blocked', 'SecurityError'); }}));
        const fallback = await blocked.newPage();
        await fallback.goto(base);
        await theme(fallback, 'light');
        assert.match(await (await fallback.goto(base + '/designs')).text(), /data-theme="light"/);
        await blocked.close();
        assert.deepEqual(errors, []);
        results.push({engine: name, defaultDark: true, touchAndKeyboard: true, persistedBeforePaint: true, crossTabSync: true, storageFallback: true, artworkAndMatUnchanged: true, checkoutTotalUnchanged: true, admin: true, widths: [360, 390, 768, 1024, 1440], languages: ['ar', 'en'], errors});
        await context.close();
    } finally {
        await browser.close();
    }
}
await writeFile('.runtime/theme-results.json', JSON.stringify({passed: true, results}, null, 2));
console.log(JSON.stringify({passed: true, results}));
