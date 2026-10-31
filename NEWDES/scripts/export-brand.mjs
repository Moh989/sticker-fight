import {chromium} from 'playwright';
import {readFile,writeFile} from 'node:fs/promises';
const browser=await chromium.launch({headless:true});
try{
 for(const [name,width]of [['horizontal',1740],['horizontal-mono',1740],['compact',990],['compact-mono',990],['icon',512],['icon-mono',512]]){
  const svg=await readFile(`public/brand/${name}.svg`,'utf8'),view=svg.match(/viewBox="0 0 (\d+) (\d+)"/),height=Math.round(width*Number(view[2])/Number(view[1]));const page=await browser.newPage({viewport:{width,height}});
  await page.setContent(`<style>*{box-sizing:border-box}html,body{margin:0;background:transparent}svg{width:${width}px;height:${height}px;display:block}</style>${svg}`);await page.screenshot({path:`public/brand/${name}.png`,omitBackground:true});await page.close();
 }
 const faviconPage=await browser.newPage({viewport:{width:32,height:32}}),faviconSvg=await readFile('public/brand/icon.svg','utf8');
 await faviconPage.setContent(`<style>html,body{margin:0;background:transparent;width:32px;height:32px;display:flex;align-items:center;justify-content:center}svg{width:28px;height:21px}</style>${faviconSvg}`);
 const faviconPng=await faviconPage.screenshot({omitBackground:true}),icoHeader=Buffer.alloc(22);icoHeader.writeUInt16LE(1,2);icoHeader.writeUInt16LE(1,4);icoHeader[6]=32;icoHeader[7]=32;icoHeader.writeUInt16LE(1,10);icoHeader.writeUInt16LE(32,12);icoHeader.writeUInt32LE(faviconPng.length,14);icoHeader.writeUInt32LE(22,18);
 await writeFile('public/brand/favicon.ico',Buffer.concat([icoHeader,faviconPng]));await writeFile('public/favicon.ico',Buffer.concat([icoHeader,faviconPng]));await faviconPage.close();
 const icon=await readFile('public/brand/icon.svg','utf8'),page=await browser.newPage({viewport:{width:1200,height:630}}),font=(await readFile('public/fonts/bebas-neue.woff2')).toString('base64'),cairo=(await readFile('public/fonts/cairo-arabic.woff2')).toString('base64');
 await page.setContent(`<meta charset="utf-8"><style>@font-face{font-family:Cairo;src:url(data:font/woff2;base64,${cairo});font-weight:200 1000}*{box-sizing:border-box}body{margin:0;background:#FAF9F6;color:#30262B;padding:70px 80px;font-family:Arial,sans-serif}.brand{display:flex;align-items:center;gap:18px;font-size:38px;font-weight:750;letter-spacing:-1px}.brand svg{width:65px}h1{font-family:Cairo;font-weight:650;font-size:66px;line-height:1.5;margin:65px 0 25px;direction:rtl;text-align:left;color:#AB4E70}p{font-family:Cairo;font-size:23px;direction:rtl;text-align:left;margin:0}small{letter-spacing:4px;font-size:12px;display:block;margin-top:32px;color:#705F67}</style><div class="brand">${icon}<div>sticker fight.</div></div><h1>تفاصيل صغيرة… تعبّر عنك.</h1><p>من العراق، على ذوقك. ستيكرات لبطاقتك وأجهزتك.</p><small>SMALL DETAILS. IRAQI SPIRIT.</small>`);await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:'public/brand/og.png'});await page.close();
}finally{await browser.close();}
console.log('Brand PNG variants and Open Graph image exported.');
