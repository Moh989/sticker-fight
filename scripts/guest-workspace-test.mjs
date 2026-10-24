import {chromium, webkit} from 'playwright';
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';

const base = process.env.TEST_BASE_URL ?? 'http://127.0.0.1:8001';
const results = [];
const initial = async page => JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
async function text(page, value) {
    await page.locator('[data-step="3"]').click();
    await page.locator('[data-tool="text"]').click();
    await page.locator('#new-text').fill(value);
    await page.locator('#add-text').click();
}
async function save(page) {
    const response = page.waitForResponse(r => r.url().includes('/api/designs/') && r.request().method() === 'PUT' && r.status() === 200);
    await page.locator('#save-design').click();
    return (await response).json();
}

for (const [engine, type] of Object.entries({chromium, webkit})) {
    const browser = await type.launch({headless: true});
    try {
        const first = await browser.newContext({viewport: {width: 390, height: 844}});
        const second = await browser.newContext({viewport: {width: 390, height: 844}});
        const a = await first.newPage(), b = await second.newPage();
        const errors = [];
        for (const page of [a, b]) page.on('pageerror', error => errors.push(error.message));
        await a.goto(base + '/editor');
        const workspaceA = (await initial(a)).workspace;
        await a.locator('[data-step="3"]').click();
        await a.locator('#image-upload').setInputFiles('samples/production/back-print.png');
        await a.locator('#upload-status').filter({hasText: 'تم رفع الصورة ومعالجتها.'}).waitFor();
        await text(a, 'زائر أول · PRIVATE A');
        const savedA = await save(a);
        const documentA = await (await a.request.get(base + '/api/designs/' + savedA.id)).json();
        const imageA = documentA.document.sides.front.elements.find(element => element.type === 'image').asset_id;

        await b.goto(base + '/editor');
        const workspaceB = (await initial(b)).workspace;
        assert.notEqual(workspaceA, workspaceB);
        assert.equal(await b.locator('#editor-alerts').innerText(), '');
        assert.equal(await b.locator('#canvas-container [data-element]').count(), 0);
        assert.equal((await b.request.get(base + '/api/designs/' + savedA.id)).status(), 404);
        assert.equal((await b.request.get(base + '/editor/' + savedA.id)).status(), 404);
        assert.equal((await b.request.get(base + '/media/' + imageA)).status(), 404);
        await text(b, 'زائر ثاني · PRIVATE B');
        const savedB = await save(b);
        assert.notEqual(savedA.id, savedB.id);
        await b.goto(base + '/saved');
        assert.match(await b.locator('#main').innerText(), /تصميمي/);
        assert.equal(await b.locator(`a[href="/editor/${savedA.id}"]`).count(), 0);
        assert.ok(await b.locator(`a[href="/editor/${savedB.id}"]`).count());

        await a.evaluate(draft => {
            localStorage.setItem('sticker-fight:last-draft', draft.id);
            localStorage.setItem('sticker-fight:design:' + draft.id, JSON.stringify({...draft, updatedAt: Date.now()}));
        }, documentA);
        const c = await first.newPage();
        c.on('pageerror', error => errors.push(error.message));
        await c.goto(base + '/editor');
        assert.equal(await c.locator('#editor-alerts').innerText(), '', 'Opening a new design must not show a pending draft');
        assert.equal(await c.locator('#canvas-container [data-element]').count(), 0);
        await Promise.all([c.waitForNavigation(), c.locator('#new-guest-workspace button').click()]);
        const workspaceC = (await initial(c)).workspace;
        assert.notEqual(workspaceC, workspaceA);
        assert.equal(await c.locator('#editor-alerts').innerText(), '', 'A new guest must not see another guest’s pending local draft');
        assert.equal(await c.locator('#canvas-container [data-element]').count(), 0);
        assert.equal((await c.request.get(base + '/api/designs/' + savedA.id)).status(), 404);
        await c.goto(base + '/saved');
        assert.equal(await c.locator('.saved-card').count(), 0);
        await c.goto(base + '/cart');
        assert.equal(await c.locator('.cart-row').count(), 0);
        await c.goto(base + '/editor');
        for (const width of [360, 390, 768, 1024, 1440]) {
            await c.setViewportSize({width, height: 900});
            assert.equal(await c.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.ok((await c.locator('#new-guest-workspace button').boundingBox()).height >= 44);
            if (width === 390) await c.screenshot({path: `.runtime/screenshots/guest-workspace-${engine}-390.png`, animations: 'disabled'});
        }
        await c.setViewportSize({width: 390, height: 844});
        const csrf = await c.locator('meta[name=csrf-token]').getAttribute('content');
        const stale = await a.evaluate(async ({csrf, workspace}) => {
            const id = crypto.randomUUID();
            const payload = {name: 'STALE TAB MUST NOT TRANSFER', revision: 0, options: {mode: 'single', printed_side: 'front', transparent_side: 'front'}, document: {version: 1, sides: {front: {template_version_id: null, background: '#FF2D8D', elements: []}, back: {template_version_id: null, background: null, elements: []}}}};
            const response = await fetch('/api/designs/' + id, {method: 'PUT', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Guest-Workspace': workspace}, body: JSON.stringify(payload)});
            return {id, status: response.status, body: await response.json()};
        }, {csrf, workspace: workspaceA});
        assert.equal(stale.status, 409);
        assert.equal(stale.body.code, 'guest_workspace_changed');
        assert.equal((await c.request.get(base + '/api/designs/' + stale.id)).status(), 404);
        await text(c, 'زائر جديد · PRIVATE C');
        const savedC = await save(c);
        assert.notEqual(savedC.id, savedA.id);
        assert.equal((await a.request.get(base + '/api/designs/' + savedB.id)).status(), 404);

        await b.goto(base + '/editor/' + savedB.id);
        await b.route('**/api/designs/*', route => route.request().method() === 'PUT'
            ? route.fulfill({status: 503, contentType: 'application/json', body: '{"message":"QA offline save"}'})
            : route.continue());
        await text(b, 'تغييرات زائر ثاني فقط · UNSENT B');
        const failed = b.waitForResponse(r => r.request().method() === 'PUT' && r.status() === 503);
        await b.locator('#save-design').click();
        await failed;
        b.on('dialog', dialog => dialog.accept());
        await b.reload();
        await b.unroute('**/api/designs/*');
        await b.getByRole('button', {name: 'استعادة التغييرات', exact: true}).click();
        assert.match(await b.locator('#canvas-container').innerHTML(), /UNSENT B/);
        await save(b);
        await b.goto(base + '/editor');
        assert.equal(await b.locator('#editor-alerts').innerText(), '');
        await b.locator('#restore-local-draft').click();
        assert.match(await b.locator('#canvas-container').innerHTML(), /UNSENT B/);
        assert.doesNotMatch(await b.locator('#canvas-container').innerHTML(), /PRIVATE A|PRIVATE C/);
        await save(b);
        await first.clearCookies();
        await c.goto(base + '/editor');
        assert.notEqual((await initial(c)).workspace, workspaceC);
        assert.equal(await c.locator('#editor-alerts').innerText(), '');
        assert.equal(await c.locator('#restore-local-draft').isVisible(), false);
        assert.equal(await c.locator('#canvas-container [data-element]').count(), 0);
        assert.deepEqual(errors, []);
        results.push({engine, anonymousVisitorsIsolated: true, sharedBrowserNewVisitorButton: true, newDesignStartsBlank: true, newSessionIsolated: true, legacyDraftNotExposed: true, staleTabCannotTransfer: true, foreignDesignAndImageRejected: true, ownOfflineRecoveryPreserved: true, errors});
        await first.close();
        await second.close();
    } finally {
        await browser.close();
        await writeFile('.runtime/guest-workspace-results.json', JSON.stringify(results, null, 2));
    }
}
console.log(JSON.stringify(results, null, 2));
