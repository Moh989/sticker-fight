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
        await page.locator('.upload-help summary').click();
        assert.equal(await page.locator('#upload-privacy').isVisible(), true);
        for (const width of [360, 390, 768, 1024, 1440]) {
            await page.setViewportSize({width, height: 900});
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No overflow at ${width}`);
            assert.ok((await page.locator('#next-step').boundingBox()).height >= 44);
        }
        await page.setViewportSize({width: 390, height: 844});
        const portrait = Buffer.from(await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = 240; canvas.height = 480;
            const ctx = canvas.getContext('2d'); ctx.fillStyle = '#FF2D8D'; ctx.fillRect(0, 0, 240, 480);
            ctx.fillStyle = '#FFFFFF'; ctx.fillRect(0, 0, 60, 60); ctx.fillRect(180, 420, 60, 60);
            return canvas.toDataURL('image/png').split(',')[1];
        }), 'base64');
        const largePortrait = Buffer.alloc(14 * 1024 * 1024); portrait.copy(largePortrait);
        const uploadResponse = page.waitForResponse(response => response.url().endsWith('/api/assets') && response.request().method() === 'POST');
        await page.locator('#image-upload').setInputFiles({name: 'portrait-14mb.png', mimeType: 'image/png', buffer: largePortrait});
        assert.equal((await uploadResponse).status(), 201, 'A real 14 MB request reaches the image processor');
        await page.locator('#upload-status').filter({hasText: 'تم رفع الصورة ومعالجتها.'}).waitFor();
        const dimensions = async () => Object.fromEntries(await Promise.all(['x', 'y', 'width', 'height', 'rotation'].map(async key => [key, Number(await page.locator('#element-' + key).inputValue())])));
        const whole = await dimensions();
        assert.ok(Math.abs(whole.width / whole.height - .5) < .00001);
        assert.ok(whole.x >= 0 && whole.y >= 0 && whole.x + whole.width <= 85.6 && whole.y + whole.height <= 53.98);
        assert.equal(await page.locator('.precise-controls').getAttribute('open'), null);
        await page.locator('#image-scale').evaluate(input => {
            for (const value of [120, 140, 170]) {input.value = value; input.dispatchEvent(new Event('input', {bubbles: true}));}
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
        assert.ok(Math.abs((await dimensions()).width / whole.width - 1.7) < .00001);
        await page.locator('#undo').click(); assert.ok(Math.abs((await dimensions()).width - whole.width) < .00001, 'One undo restores a continuous slider adjustment');
        await page.locator('#zoom-in').click(); const enlarged = await dimensions();
        assert.ok(enlarged.width > whole.width);
        assert.ok(Math.abs((enlarged.x + enlarged.width / 2) - (whole.x + whole.width / 2)) < .00001);
        await page.locator('#undo').click(); assert.ok(Math.abs((await dimensions()).width - whole.width) < .00001);
        await page.locator('#redo').click(); assert.ok((await dimensions()).width > whole.width);
        await page.locator('#rotate-element').click(); assert.equal((await dimensions()).rotation, 90);
        await page.locator('#contain-image').click(); assert.equal((await dimensions()).rotation, 0);
        await page.locator('#fill-image').click(); assert.ok((await dimensions()).width > whole.width);
        await page.locator('#contain-image').click();
        await page.locator('#canvas-container').scrollIntoViewIfNeeded();
        if (name === 'chromium') {
            const box = await page.locator('#canvas-container').boundingBox();
            const cdp = await context.newCDPSession(page);
            const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
            const touch = (x, y, id) => ({x, y, id});
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [touch(cx - 12, cy, 1), touch(cx + 12, cy, 2)]});
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [touch(cx - 20, cy + 6, 1), touch(cx + 20, cy + 6, 2)]});
            await page.waitForFunction(width => Number(document.querySelector('#element-width').value) > width * 1.4, whole.width);
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
            const pinched = await dimensions();
            assert.ok(pinched.width > whole.width * 1.4, 'Pinch zoom scales the whole image');
            assert.ok(Math.abs(pinched.width / pinched.height - .5) < .00001);
            const moveX = cx, moveY = cy;
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [touch(moveX, moveY, 3)]});
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [touch(moveX + 12, moveY + 8, 3)]});
            await cdp.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
            const moved = await dimensions();
            assert.ok(Math.abs(moved.x - pinched.x - 12 * 85.6 / box.width) < .1, 'A new drag moves the image without a jump');
            await cdp.detach();
            await page.locator('#undo').click(); assert.ok(Math.abs((await dimensions()).x - pinched.x) < .00001);
            await page.locator('#undo').click(); assert.ok(Math.abs((await dimensions()).width - whole.width) < .00001, 'One undo restores the entire gesture');
        }
        await page.locator('#contain-image').click();
        const saved = page.waitForResponse(response => response.url().includes('/api/designs/') && response.request().method() === 'PUT');
        await page.locator('#save-design').click();
        const savedResponse = await saved; assert.equal(savedResponse.status(), 200);
        const savedId = (await savedResponse.json()).id;
        const persisted = await (await context.request.get(base + '/api/designs/' + savedId)).json();
        const savedPhoto = persisted.document.sides.front.elements.find(element => element.type === 'image');
        assert.equal(savedPhoto.fit, 'contain'); assert.ok(Math.abs(savedPhoto.width / savedPhoto.height - .5) < .00001);
        const savedImage = await (await context.request.get(base + '/media/' + savedPhoto.asset_id)).body();
        assert.equal(savedImage.readUInt32BE(16), 240); assert.equal(savedImage.readUInt32BE(20), 480);
        const oversized = Buffer.alloc(14 * 1024 * 1024 + 1); portrait.copy(oversized);
        await page.locator('#image-upload').setInputFiles({name: 'too-large.png', mimeType: 'image/png', buffer: oversized});
        await page.locator('#upload-status').filter({hasText: 'الحد الأعلى للصورة 14 ميغابايت.'}).waitFor();
        assert.ok(Math.abs((await dimensions()).width - whole.width) < .00001, 'Refused upload preserves current work');
        await page.screenshot({path: `.runtime/screenshots/editor-mobile-controls-${name}.png`, fullPage: true, animations: 'disabled'});
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
        for (const width of [360, 390, 768, 1024, 1440]) {
            await page.setViewportSize({width, height: 900});
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No English overflow at ${width}`);
        }
        await page.setViewportSize({width: 390, height: 844});
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
        results.push({browser: name, upload: 'passed', real14MbUpload: 'passed', wholeImageDefault: 'passed', imageControlsAndUndo: 'passed', mobileWidths: 'passed', privateAsset: 'passed', languageBothDirections: 'passed', draftPreserved: 'passed', unchangedSaveIsIdempotent: 'passed', failedSaveBlocksNavigation: 'passed', errors});
    } finally {
        await browser.close();
    }
}

await writeFile('.runtime/editor-regression-results.json', JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
