import {chromium} from 'playwright';
import {readFile,writeFile} from 'node:fs/promises';
const browser=await chromium.launch({headless:true});
try{
 for(const [name,width]of [['horizontal',1740],['horizontal-mono',1740],['compact',990],['compact-mono',990],['icon',512],['icon-mono',512]]){
  const svg=await readFile(`public/brand/${name}.svg`,'utf8'),view=svg.match(/viewBox="0 0 (\d+) (\d+)"/),height=Math.round(width*Number(view[2])/Number(view[1]));const page=await browser.newPage({viewport:{width,height}});
  await page.setContent(`<style>*{box-sizing:border-box}html,body{margin:0;background:transparent}svg{width:${width}px;height:${height}px;display:block}</style>${svg}`);await page.screenshot({path:`public/brand/${name}.png`,omitBackground:true});await page.close();
 }
 const icon=await readFile('public/brand/icon.svg','utf8'),page=await browser.newPage({viewport:{width:1200,height:630}}),font=(await readFile('public/fonts/bebas-neue.woff2')).toString('base64'),cairo=(await readFile('public/fonts/cairo-arabic.woff2')).toString('base64');
 await page.setContent(`<meta charset="utf-8"><style>@font-face{font-family:Bebas;src:url(data:font/woff2;base64,${font})}@font-face{font-family:Cairo;src:url(data:font/woff2;base64,${cairo});font-weight:200 1000}*{box-sizing:border-box}body{margin:0;background:#0A0A0D;color:#F7F5F8;font-family:Bebas;padding:60px 75px}.brand{display:flex;align-items:center;gap:20px;font-size:46px}.brand svg{width:85px}.pink{color:#FF2D8D}h1{font-weight:400;font-size:120px;line-height:.95;margin:48px 0 28px;letter-spacing:1px}p{font-family:Cairo;font-weight:800;font-size:29px;direction:rtl;text-align:left;margin:0}small{font-family:Arial;letter-spacing:5px;font-size:15px;display:block;margin-top:29px;color:#B8B4BE}</style><div class="brand">${icon}<div>STICKER <span class="pink">FIGHT</span></div></div><h1>FIGHT <span class="pink">BORING</span><br>CARDS.</h1><p>بطاقتك مو لازم تشبه البقية.</p><small>Custom Card Skins</small>`);await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:'public/brand/og.png'});await page.close();
}finally{await browser.close();}
console.log('Brand PNG variants and Open Graph image exported.');
