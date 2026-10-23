import {chromium} from 'playwright';
import {mkdir,writeFile} from 'node:fs/promises';
await mkdir('.runtime/screenshots',{recursive:true});
const browser=await chromium.launch({headless:true});
const failures=[];
try{for(const width of [360,390,768,1024,1440]){
const page=await browser.newPage({viewport:{width,height:1000}});page.on('pageerror',e=>failures.push({width,error:e.message}));
const response=await page.goto('http://127.0.0.1:8000/');await page.evaluate(()=>document.fonts.ready);if(response.status()!==200)failures.push({width,status:response.status()});
const sizes=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));if(sizes.scroll>sizes.viewport)failures.push({width,overflow:sizes});
await page.screenshot({path:`.runtime/screenshots/home-${width}.png`,fullPage:true,animations:'disabled'});
if(width===390){await page.goto('http://127.0.0.1:8000/designs');const productPath=await page.locator('.product-card .product-image').first().getAttribute('href');for(const path of ['/designs',productPath,'/editor','/saved','/guide','/installation','/faq','/contact','/cart','/login','/track']){const r=await page.goto('http://127.0.0.1:8000'+path);if(r.status()!==200)failures.push({path,status:r.status()});await page.evaluate(()=>document.fonts.ready);if(path.startsWith('/editor'))await page.screenshot({path:'.runtime/screenshots/editor-390.png',fullPage:true,animations:'disabled'});}}
await page.goto('http://127.0.0.1:8000/language/en');const enResponse=await page.goto('http://127.0.0.1:8000/');await page.evaluate(()=>document.fonts.ready);if(enResponse.status()!==200)failures.push({width,englishStatus:enResponse.status()});const enSize=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth,dir:document.documentElement.dir}));if(enSize.dir!=='ltr'||enSize.scroll>enSize.viewport)failures.push({width,englishLayout:enSize});if(width===390||width===1440)await page.screenshot({path:`.runtime/screenshots/home-en-${width}.png`,fullPage:true,animations:'disabled'});await page.close();}
}finally{await browser.close();}
await writeFile('.runtime/preview-results.json',JSON.stringify(failures,null,2));console.log(JSON.stringify({failures},null,2));if(failures.length)process.exitCode=1;
