import {chromium, webkit} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir, writeFile} from 'node:fs/promises';

const base = process.env.TEST_BASE_URL ?? 'http://127.0.0.1:8001';
const results = [];
await mkdir('.runtime/screenshots', {recursive: true});

for (const [name, engine] of Object.entries({chromium, webkit})) {
    const browser = await engine.launch({headless: true});
    try {
        const context = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const gallery = '/designs?q=orbit&sort=name';
        await page.goto(base + gallery);
        assert.equal(await page.evaluate(async () => (await fetch('/privacy')).status), 404);
        await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, gallery);
        assert.equal(await page.locator('html').getAttribute('lang'), 'en');
        assert.equal(await page.evaluate(async () => (await fetch('/products/missing-regression-product')).status), 404);
        await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, gallery);
        assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');

        await page.goto(base + '/editor');
        await page.locator('[data-step="3"]').click();
        assert.equal(await page.locator('#upload-consent').isVisible(), true);
        assert.equal(await page.locator('#upload-privacy').isVisible(), true);
        const chooser = page.waitForEvent('filechooser');
        await page.locator('#choose-image').click();
        await (await chooser).setFiles('samples/production/back-print.png');
        await page.locator('#upload-status').filter({hasText: 'تم رفع الصورة ومعالجتها.'}).waitFor();
        await page.locator('[data-tool="text"]').click();
        await page.locator('#new-text').fill('صورتي الخاصة · MY PHOTO');
        await page.locator('#add-text').click();
        await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        await page.locator('html[lang="en"]').waitFor();
        const designPath = new URL(page.url()).pathname;
        assert.match(designPath, /^\/editor\/[0-9a-f-]{36}$/);
        assert.equal(await page.locator('html').getAttribute('lang'), 'en');
        const draft = JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
        assert.ok(draft.document.sides.front.elements.some(element => element.text === 'صورتي الخاصة · MY PHOTO'));
        const photo = draft.document.sides.front.elements.find(element => element.type === 'image');
        assert.ok(photo);
        assert.equal(await page.locator('#editor-alerts').innerText(), '');
        const unchangedSave = page.waitForResponse(response => response.url().includes('/api/designs/') && response.request().method() === 'PUT');
        await page.locator('#save-design').click();
        assert.equal((await (await unchangedSave).json()).revision, draft.revision);
        const stranger = await browser.newContext();
        assert.equal((await stranger.request.get(base + '/media/' + photo.asset_id)).status(), 404);
        await stranger.close();
        await page.locator('[data-step="3"]').click();
        assert.match(await page.locator('#upload-consent').innerText(), /By uploading, I confirm/);
        await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        assert.equal(new URL(page.url()).pathname, designPath);
        assert.equal(await page.locator('html').getAttribute('lang'), 'ar');

        await page.locator('[data-step="3"]').click();
        await page.locator('[data-tool="text"]').click();
        await page.route('**/api/designs/*', async route => {
            if (route.request().method() === 'PUT') {
                await route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({message: 'تعذر الحفظ للاختبار'})});
            } else {
                await route.continue();
            }
        });
        await page.locator('#new-text').fill('تبقى محفوظة عند تعذر تغيير اللغة');
        await page.locator('#add-text').click();
        await page.locator('.locale-switch').click();
        await page.locator('#editor-alerts').filter({hasText: 'تعذر حفظ التغييرات قبل تغيير اللغة'}).waitFor();
        assert.equal(new URL(page.url()).pathname, designPath);
        assert.equal(await page.locator('html').getAttribute('lang'), 'ar');
        await page.unroute('**/api/designs/*');
        await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        const restored = JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
        assert.ok(restored.document.sides.front.elements.some(element => element.text === 'تبقى محفوظة عند تعذر تغيير اللغة'));
        await page.locator('[data-step="3"]').click();
        await page.screenshot({path: `.runtime/screenshots/editor-upload-${name}.png`, fullPage: true, animations: 'disabled'});
        assert.deepEqual(errors, []);
        results.push({browser: name, upload: 'passed', privateAsset: 'passed', languageBothDirections: 'passed', draftPreserved: 'passed', unchangedSaveIsIdempotent: 'passed', failedSaveBlocksNavigation: 'passed', errors});
    } finally {
        await browser.close();
    }
}

await writeFile('.runtime/editor-regression-results.json', JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
