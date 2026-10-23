import {chromium, webkit} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';

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
        for (const [index, mode] of ['single', 'double', 'mixed'].entries()) {
            await page.goto(base + '/designs');
            const href = await page.locator('.product-info a[aria-label]').first().getAttribute('href');
            assert.ok(!href.includes('/editor'));
            await page.goto(base + href);
            assert.equal(await page.locator('#editor-app').count(), 0);
            const form = page.locator('#ready-product-form');
            assert.notEqual(await form.locator('[name="material_id"]').inputValue(), '');
            await form.locator('[name="mode"]').selectOption(mode);
            await form.locator('[name="front_template_id"]:enabled').first().check();
            if (mode !== 'single') await form.locator('[name="back_template_id"]:enabled').first().check();
            await page.locator('#ready-add-to-cart:enabled').waitFor();
            assert.match(await page.locator('#ready-summary').innerText(), /د.ع/);
            assert.equal(await page.locator('#ready-preview figure').count(), mode === 'single' ? 1 : 2);
            const designId = await form.locator('[name="request_id"]').inputValue();
            if (index === 0) {
                const imageProtection = await page.locator('#ready-original-preview img').evaluate(image => ({
                    contextMenuBlocked: !image.dispatchEvent(new MouseEvent('contextmenu', {bubbles: true, cancelable: true})),
                    dragBlocked: !image.dispatchEvent(new Event('dragstart', {bubbles: true, cancelable: true})),
                    draggable: image.draggable,
                }));
                assert.deepEqual(imageProtection, {contextMenuBlocked: true, dragBlocked: true, draggable: false});
                assert.equal(await form.locator('[name="quantity"]').evaluate(input => input.dispatchEvent(new MouseEvent('contextmenu', {bubbles: true, cancelable: true}))), true);
            }
            await page.evaluate(()=>{document.activeElement?.blur();window.scrollTo(0,0);});
            await page.screenshot({path: `.runtime/screenshots/ready-${name}-${mode}-390.png`, fullPage: true, animations: 'disabled'});
            await Promise.all([page.waitForURL('**/cart'), page.locator('#ready-add-to-cart').click()]);
            assert.equal(await page.locator('.cart-row').count(), index + 1);
            assert.equal(await page.locator('.cart-row a[href^="/editor"]').count(), 0);
            const previews = await page.locator('[data-design-preview]').evaluateAll(elements => elements.map(element => JSON.parse(element.dataset.designPreview)));
            assert.ok(previews.every(preview => preview.options.ready_locked));
            const preview = previews.find(item => item.options.mode === mode);
            for (const side of Object.values(preview.document.sides)) assert.ok(side.elements.every(element => element.locked));
            if (mode === 'mixed') assert.deepEqual(preview.document.sides.front.elements, []);
            const options = {...preview.options, product_id: null};
            delete options.ready_locked;
            const document = structuredClone(preview.document);
            document.sides.front.elements = [];
            const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
            const edit = await page.request.put(base + '/api/designs/' + designId, {headers: {'X-CSRF-TOKEN': token, Accept: 'application/json'}, data: {name: 'tampered', revision: 1, document, options}});
            assert.equal(edit.status(), 403);
            if (index === 0) {
                await page.locator('.cart-row a[href^="/products/"]').first().click();
                await page.locator('#ready-add-to-cart:enabled').waitFor();
                await page.locator('[name="quantity"]').fill('3');
                await page.locator('[name="quantity"]').press('Tab');
                await page.locator('#ready-add-to-cart:enabled').waitFor();
                await Promise.all([page.waitForURL('**/cart'), page.locator('#ready-add-to-cart').click()]);
                assert.equal(await page.locator('.cart-row').count(), 1);
            }
        }
        const optionsPath = await page.locator('.cart-row a[href^="/products/"]').first().getAttribute('href');
        for (const width of [360, 390, 768, 1024, 1440]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(base + optionsPath);
            await page.evaluate(() => document.fonts.ready);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
            assert.equal(await page.locator('html').getAttribute('lang'), 'en');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await Promise.all([page.waitForNavigation(), page.locator('.locale-switch').click()]);
        }
        await page.setViewportSize({width: 390, height: 844});
        if (name === 'chromium') {
            await page.goto(base + '/checkout');
            for (const [field, value] of Object.entries({name: 'QA fixed design', phone: '07701234567', city: 'QA', district: 'QA', address: 'QA TEST ADDRESS', landmark: 'QA'})) await page.locator(`[name="${field}"]`).fill(value);
            await page.locator('#checkout-governorate').selectOption('1');
            await page.locator('[name="rights"]').check();
            await page.locator('[name="confirmed"]').check();
            await page.locator('#place-order:enabled').waitFor();
            await Promise.all([page.waitForURL('**/orders/**'), page.locator('#place-order').click()]);
            const orderId = new URL(page.url()).pathname.split('/').pop();
            assert.equal(await page.locator('.admin-order-item').count(), 3);
            const users = JSON.parse(await readFile('.runtime/qa-users.json', 'utf8'));
            const staff = await browser.newContext({viewport: {width: 1440, height: 1000}});
            const admin = await staff.newPage();
            await admin.goto(base + '/login');
            await admin.locator('form[action="/login"] [name="email"]').fill(users.admin.email);
            await admin.locator('form[action="/login"] [name="password"]').fill(users.admin.password);
            await Promise.all([admin.waitForURL('**/admin'), admin.locator('form[action="/login"] button').click()]);
            await admin.goto(base + '/admin/orders/' + orderId);
            await admin.locator('form[action$="/status"] select').selectOption('approved');
            await Promise.all([admin.waitForNavigation(), admin.locator('form[action$="/status"] button').click()]);
            execFileSync('php', ['artisan', 'queue:work', '--env=qa', '--stop-when-empty', '--tries=1', '--timeout=210'], {cwd: process.cwd(), timeout: 180000, stdio: 'pipe'});
            await admin.reload();
            assert.equal(await admin.getByText('الملفات جاهزة', {exact: true}).count(), 3);
            const download = admin.waitForEvent('download');
            await admin.locator('.file-links a').filter({hasText: 'حزمة الإنتاج ZIP'}).first().click();
            await (await download).saveAs('.runtime/ready-production.zip');
            assert.ok((await readFile('.runtime/ready-production.zip')).byteLength > 1000);
            assert.equal(await admin.locator('.brand img').evaluate(image => image.dispatchEvent(new MouseEvent('contextmenu', {bubbles: true, cancelable: true}))), true);
            await staff.close();
        }
        assert.deepEqual(errors, []);
        results.push({browser: name, fixedArtwork: true, modes: ['single', 'double', 'mixed'], directCart: true, privateCartOptionsUpdate: true, tamperingRejected: true, visitorImageMenuAndDragBlocked: true, inputsPreserveContextMenu: true, productionExport: name === 'chromium', errors});
    } finally {
        await browser.close();
    }
}
await writeFile('.runtime/ready-product-results.json', JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
