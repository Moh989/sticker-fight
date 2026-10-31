import {chromium, webkit} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {PDFDocument} from 'pdf-lib';

const base = 'http://127.0.0.1:8001';
const users = JSON.parse(await readFile('.runtime/qa-users.json', 'utf8'));
const results = [];
await mkdir('.runtime/screenshots', {recursive: true});

for (const [engineName, engine] of Object.entries({chromium, webkit})) {
    const browser = await engine.launch({headless: true});
    try {
        const context = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/editor');
        const initial = JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
        assert.deepEqual(initial.templates.filter(template => template.side === 'front').map(template => template.kind), ['small_chip', 'no_chip']);
        await page.locator('[data-step="2"]').click();
        for (const locale of ['ar', 'en']) {
            if (locale === 'en') {
                await page.goto(base + '/language/en?return_to=%2Feditor');
                await page.locator('[data-step="2"]').click();
            }
            for (const width of [360, 390, 768, 1024, 1440]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.locator('#template-options .template-option').count(), 2);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${engineName} ${locale} ${width}`);
            }
        }
        await page.goto(base + '/language/ar?return_to=%2Feditor');
        await page.setViewportSize({width: 390, height: 844});
        await page.locator('[data-step="2"]').click();
        await page.getByText('قالب بدون شريحة', {exact: true}).click();
        await page.locator('#save-design').click();
        await page.locator('#save-status').filter({hasText: 'تم الحفظ على الخادم'}).waitFor();
        await page.reload();
        const saved = JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));
        const noChip = saved.templates.find(template => template.kind === 'no_chip');
        assert.equal(saved.document.sides.front.template_version_id, noChip.id);
        assert.equal((await page.locator('#canvas-container clipPath path').getAttribute('d')).match(/M/g).length, 1);
        await page.locator('[data-step="2"]').click();
        await page.screenshot({path: `.runtime/screenshots/templates-${engineName}-390.png`, fullPage: true});
        await page.goto(base + '/designs');
        const productUrl = await page.locator('.product-info a[aria-label]').first().getAttribute('href');
        await page.goto(base + productUrl);
        assert.equal(await page.locator('[data-ready-template-side="front"] .template-option').count(), 2);
        if (!noChip.approved) {
            assert.equal(await page.locator(`[name=front_template_id][value="${noChip.id}"]`).isDisabled(), true);
        }

        const adminContext = await browser.newContext({viewport: {width: 1440, height: 1000}});
        const admin = await adminContext.newPage();
        admin.on('pageerror', error => errors.push(error.message));
        await admin.goto(base + '/login');
        await admin.locator('form[action="/login"] [name=email]').fill(users.admin.email);
        await admin.locator('form[action="/login"] [name=password]').fill(users.admin.password);
        await admin.locator('form[action="/login"] button').click();
        await admin.waitForURL('**/admin');
        await admin.goto(base + '/admin/templates');
        const form = admin.locator('[data-template-form]');
        const templateId = await form.locator('option[data-kind="no_chip"]').getAttribute('value');
        await form.locator('[name=template_id]').selectOption(templateId);
        assert.equal(await form.locator('[name=kind]').inputValue(), 'no_chip');
        assert.deepEqual(JSON.parse(await form.locator('[name=geometry]').inputValue()).holes, []);
        if (!noChip.approved) {
            await Promise.all([admin.waitForNavigation(), form.locator('button').click()]);
            assert.equal(await admin.locator('.notice.error').count(), 0);
            await form.locator('[name=template_id]').selectOption(templateId);
            await form.locator('[name=physical_approval]').check();
            await form.locator('[name=sample_reference]').fill('AUTOMATED QA ONLY — NOT A PHYSICAL APPROVAL');
            await Promise.all([admin.waitForNavigation(), form.locator('button').click()]);
            assert.equal(await admin.locator('.notice.error').count(), 0);
        }

        await page.goto(base + productUrl);
        await page.reload();
        const noChipRadio = page.locator('[data-ready-template-side="front"] .template-option').filter({hasText: 'قالب بدون شريحة'}).locator('input');
        await noChipRadio.check();
        await page.locator('#ready-add-to-cart:enabled').waitFor();
        assert.equal((await page.locator('#ready-preview clipPath path').getAttribute('d')).match(/M/g).length, 1);
        await page.locator('#ready-add-to-cart').click();
        await page.waitForURL('**/cart');
        await page.goto(base + '/checkout');
        await page.locator('[name=name]').fill('QA no chip');
        await page.locator('[name=phone]').fill('07701234567');
        await page.locator('[name=governorate]').fill('بغداد');
        await page.locator('[name=district]').fill('اختبار القالب');
        await page.locator('[name=landmark]').fill('اختبار معزول فقط');
        await page.locator('[name=rights]').check();
        await page.locator('[name=confirmed]').check();
        await page.locator('#place-order:enabled').waitFor();
        await page.locator('#place-order').click();
        await page.waitForURL('**/orders/**');
        const orderId = new URL(page.url()).pathname.split('/').pop();
        await admin.goto(base + '/admin/orders/' + orderId);
        await admin.locator('form[action$="/status"] select').selectOption('approved');
        await Promise.all([admin.waitForNavigation(), admin.locator('form[action$="/status"] button').click()]);
        execFileSync('php', ['artisan', 'queue:work', '--env=qa', '--stop-when-empty', '--tries=1', '--timeout=210'], {timeout: 180000, stdio: 'pipe'});
        await admin.reload();
        const fileUrl = async kind => new URL(await admin.locator('.file-links a').filter({hasText: kind}).first().getAttribute('href'), base).href;
        const cut = await (await admin.request.get(await fileUrl('front · cut'))).text();
        assert.equal(cut.match(/<path d="([^"]+)"/)[1].match(/M/g).length, 1);
        assert.ok(cut.includes('width="85.6mm"'));
        const pdf = await PDFDocument.load(await (await admin.request.get(await fileUrl('print_pdf'))).body());
        assert.ok(Math.abs(pdf.getPage(0).getTrimBox().width * 25.4 / 72 - 85.6) < .0001);
        assert.ok(Math.abs(pdf.getPage(0).getTrimBox().height * 25.4 / 72 - 53.98) < .0001);
        const manifest = await (await admin.request.get(await fileUrl('manifest'))).json();
        assert.deepEqual(manifest.template_physical_approval.front.geometry.holes, []);
        const zip = await (await admin.request.get(await fileUrl('حزمة الإنتاج ZIP'))).body();
        assert.ok(zip.byteLength > 1000);
        await writeFile(`.runtime/no-chip-${engineName}.zip`, zip);
        assert.deepEqual(errors, []);
        results.push({engine: engineName, choices: 2, savedAndRestored: true, noChipCutContours: 1, trimMm: [85.6, 53.98], orderExportAndZip: true, errors});
    } finally {
        await browser.close();
    }
}
await writeFile('.runtime/template-choices-results.json', JSON.stringify(results, null, 2));
console.log(JSON.stringify({passed: true, results}));
