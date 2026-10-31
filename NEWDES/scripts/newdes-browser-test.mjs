import {chromium,webkit} from 'playwright';
import {mkdir,readFile,writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {PDFDocument} from 'pdf-lib';
const base=process.env.TEST_BASE_URL??'http://localhost:8002';
await mkdir('.runtime/screenshots',{recursive:true});
const browsers=[['chromium',await chromium.launch({headless:true})],['webkit',await webkit.launch({headless:true})]],results=[],errors=[];
const orders=[];
try{
 for(const [engine,browser] of browsers){
  const context=await browser.newContext({viewport:{width:390,height:844},hasTouch:true,isMobile:true,reducedMotion:'reduce'});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(engine+': '+e.message));
  await page.goto(base);assert.equal(await page.locator('.demo-banner').count(),1,'Refusing commerce writes outside the labelled NEWDES demo.');
  await page.goto(base+'/editor');
  assert.equal(await page.locator('.editor-steps [data-step]').count(),3);
  await page.locator('input[name="template-front"]').first().check();
  await page.locator('#next-step').click();
  const photo=Buffer.from(await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=1600;canvas.height=1000;const ctx=canvas.getContext('2d');ctx.fillStyle='#D6DFCC';ctx.fillRect(0,0,1600,1000);ctx.fillStyle='#52674F';ctx.fillRect(200,200,1200,600);ctx.fillStyle='#FAF9F6';ctx.beginPath();ctx.arc(800,500,220,0,Math.PI*2);ctx.fill();return canvas.toDataURL('image/png').split(',')[1];}),'base64');
  await page.locator('#image-upload').setInputFiles({name:'DEMO-test-image.png',mimeType:'image/png',buffer:photo});
  await page.locator('#canvas-container image').waitFor({timeout:15000});
  const before=await page.locator('#canvas-container [data-element]').first().getAttribute('transform');
  const box=await page.locator('#canvas-container').boundingBox();
  await page.mouse.move(box.x+box.width*.65,box.y+box.height*.65);await page.mouse.down();await page.mouse.move(box.x+box.width*.72,box.y+box.height*.69,{steps:6});await page.mouse.up();
  const after=await page.locator('#canvas-container [data-element]').first().getAttribute('transform');assert.notEqual(before,after,'Image must move freely.');
  await page.locator('[data-tool="text"]').click();await page.locator('#new-text').fill('بطاقتي · MY CARD');await page.locator('#add-text').click();
  await page.locator('#save-design').click();await page.locator('#save-status').filter({hasText:'تم الحفظ على الخادم'}).waitFor({timeout:15000});
  const designId=new URL(page.url()).pathname.split('/').pop();
  await page.locator('#new-text').fill('offline test');await context.setOffline(true);await page.locator('#add-text').click();await page.locator('#save-design').click();await page.locator('#save-status').filter({hasText:'تعذر الحفظ'}).waitFor({timeout:10000});await context.setOffline(false);await page.locator('#undo').click();await page.locator('#save-design').click();await page.locator('#save-status').filter({hasText:'تم الحفظ على الخادم'}).waitFor({timeout:15000});
  await page.reload();const initial=JSON.parse(await page.locator('#editor-app').getAttribute('data-initial'));assert.equal(initial.id,designId);assert.equal(initial.document.sides.front.elements.find(e=>e.type==='image').fit,'contain');assert.ok(initial.document.sides.front.elements.some(e=>e.text==='بطاقتي · MY CARD'));
  await page.locator('[data-step="3"]').click();await page.screenshot({path:'.runtime/screenshots/newdes-editor-'+engine+'.png',fullPage:true,animations:'disabled'});
  await page.locator('[data-step="4"]').click();assert.equal(await page.locator('#add-to-cart').isDisabled(),true);await page.locator('#design-reviewed').check();await page.locator('#add-to-cart:enabled').waitFor({timeout:10000});await page.locator('#add-to-cart').click();await page.waitForURL('**/cart');
  assert.equal(await page.locator('.cart-row').count(),1);
  // Editing must replace the chosen cart item rather than create a duplicate.
  await page.locator('.cart-row a[href*="/editor/"]').click();await page.locator('[data-step="4"]').click();await page.locator('#design-quantity').fill('2');await page.locator('#design-quantity').press('Tab');await page.locator('#design-reviewed').check();await page.locator('#add-to-cart:enabled').waitFor();await page.locator('#add-to-cart').click();await page.waitForURL('**/cart');assert.equal(await page.locator('.cart-row').count(),1);
  await page.locator('a[href="/checkout"]').click();
  for(const [field,value] of Object.entries({name:'DEMO '+engine+' shopper',phone:'07701234567',district:'المنصور',address:'DEMO test address 12',email:'demo@example.test',notes:'AUTOMATED DEMO ORDER — NOT A REAL SALE'}))await page.locator('[name="'+field+'"]').fill(value);
  await page.locator('#checkout-governorate').fill('بغداد');await page.locator('#checkout-governorate').press('Tab');await page.locator('[name="rights"]').check();await page.locator('[name="confirmed"]').check();assert.equal(await page.locator('[data-publication-item] input[value="0"]').isChecked(),true);
  await page.locator('#place-order:enabled').waitFor({timeout:10000});assert.ok((await page.locator('#checkout-summary').textContent()).includes('21,000'));
  await page.screenshot({path:'.runtime/screenshots/newdes-checkout-'+engine+'.png',fullPage:true,animations:'disabled'});
  await page.locator('#place-order').click();await page.waitForURL('**/orders/**');const orderId=new URL(page.url()).pathname.split('/').pop();orders.push(orderId);assert.ok((await page.locator('main').textContent()).includes('21,000'));
  const other=await browser.newContext();assert.equal((await other.request.get(base+'/api/designs/'+designId)).status(),404);assert.equal((await other.request.get(base+'/orders/'+orderId)).status(),404);await other.close();
  results.push({engine,flow:'mobile image upload, free movement, Arabic/English text, offline retry, saved reload, reviewed cart edit, private guest checkout',orderId,passed:true});
  await context.close();
 }
 execFileSync(process.env.PHP_BINARY??'/opt/homebrew/bin/php',['-d','memory_limit=512M','artisan','queue:work','--stop-when-empty','--tries=1','--timeout=210','--no-interaction'],{cwd:process.cwd(),env:{...process.env,PATH:'/opt/homebrew/bin:/usr/local/bin:'+process.env.PATH},timeout:240000,stdio:'pipe'});
 const browser=browsers[0][1],context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const access=await readFile('.runtime/admin-access.txt','utf8'),email=access.match(/^Email: (.+)$/m)[1],password=access.match(/^Password: (.+)$/m)[1];
 await page.goto(base+'/login');await page.locator('input[name="email"]').first().fill(email);await page.locator('input[name="password"]').first().fill(password);await page.locator('form[action="/login"] button[type="submit"], form[action="/login"] button').first().click();await page.waitForURL('**/admin');
 for(const order of orders){
  const orderResponse=await page.goto(base+'/admin/orders/'+order);assert.equal(orderResponse.status(),200);assert.ok((await page.locator('main').textContent()).includes('الملفات جاهزة'));
  const links=await page.locator('a[href*="/admin/files/"]').evaluateAll(nodes=>nodes.map(n=>({href:n.href,label:n.textContent.trim()})));
  assert.ok(links.length>=6,'Production files must exist in admin.');
  let pdfFound=false,svgFound=false,zipFound=false;
  for(const link of links){const response=await context.request.get(link.href);assert.equal(response.status(),200);const disposition=response.headers()['content-disposition']??'';const buffer=await response.body();
   if(disposition.includes('.pdf')){const pdf=await PDFDocument.load(buffer);const page=pdf.getPage(0),mm=page.getSize().width*25.4/72;assert.ok(Math.abs(mm-89.6)<.01);pdfFound=true;}
   if(disposition.includes('-artwork.svg')){const svg=buffer.toString();assert.ok(svg.includes('بطاقتي'));for(const forbidden of ['selection-outline','data-guide','STICKER FIGHT'])assert.ok(!svg.includes(forbidden));svgFound=true;}
   if(disposition.includes('.zip')){assert.equal(buffer.readUInt32LE(0),0x04034b50);zipFound=true;await mkdir('samples/production',{recursive:true});await writeFile('samples/production/demo-order-production.zip',buffer);}
  }
  assert.ok(pdfFound&&svgFound&&zipFound);results.push({flow:'actual queued PDF, artwork, cut and ZIP export',orderId:order,passed:true});
 }
 // Public pages in both directions and required widths.
 for(const language of ['ar','en']){
  await page.goto(base+'/language/'+language+'?return_to=%2F');
  assert.equal(await page.locator('html').getAttribute('dir'),language==='ar'?'rtl':'ltr');
  for(const route of ['/','/designs','/editor','/products/afterhours','/devices','/wholesale','/installation','/guide'])for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:900});const response=await page.goto(base+route);assert.equal(response.status(),200);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),language+' '+route+' '+width);
   if(route==='/'&&(width===1440||width===390))await page.screenshot({path:'.runtime/screenshots/newdes-home-'+language+'-'+width+'.png',fullPage:true,animations:'disabled'});
  }
 }
 results.push({flow:'8 public pages, 5 widths, Arabic RTL and English LTR',passed:true});
 assert.deepEqual(errors,[]);
 await writeFile('.runtime/newdes-browser-results.json',JSON.stringify({passed:true,results,errors},null,2));
 console.log(JSON.stringify({passed:true,flows:results.length,errors}));await context.close();
}finally{for(const [,browser]of browsers)await browser.close();}
