import {readFile,mkdir,writeFile} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {chromium} from 'playwright';
import {PDFDocument} from 'pdf-lib';
import {renderSide,renderCut,warnings,fitTextElements} from '../resources/js/shared/renderer.mjs';
import {loadDesignFonts,replacePrivateText} from './name-font-assets.mjs';
const [specPath,outDir]=process.argv.slice(2);
if(!specPath||!outDir)throw Error('Usage: node scripts/render.mjs private-spec.json output-directory');
const spec=JSON.parse(await readFile(specPath,'utf8')),root=path.resolve(import.meta.dirname,'..');
const profile=spec.print_profile??{color_space:'sRGB',pdf_standard:'PDF',ppi:300};
if(profile.color_space!=='sRGB'||profile.icc||profile.white_ink||profile.mirror||profile.pdf_standard!=='PDF')throw Error('Unsupported print profile. sRGB PDF only.');
const ppi=Number(profile.ppi??300);if(ppi<72||ppi>600)throw Error('PPI out of range');
await mkdir(outDir,{recursive:true});
const fontAssets=await loadDesignFonts(spec,root),fonts=fontAssets.css,nameRasterCache=new Map();
const resolveMap={};for(const [id,a] of Object.entries(spec.assets??{})){
 const privateRoot=path.join(root,'storage/app/private'),assetPath=path.resolve(privateRoot,a.path);if(!assetPath.startsWith(privateRoot+path.sep))throw Error('Invalid asset path');const bytes=await readFile(assetPath);if(a.sha256&&createHash('sha256').update(bytes).digest('hex')!==a.sha256)throw Error('Frozen asset checksum mismatch');resolveMap[id]=`data:image/png;base64,${bytes.toString('base64')}`;
}
const artMap={};for(const key of ['afterhours','baghdad','orbit','nope','checker','bloom','tiger','minimal','name-card']){let artPath=path.join(root,`public/art/${key}.svg`);if(spec.art_assets?.[key]){const privateRoot=path.join(root,'storage/app/private');artPath=path.resolve(privateRoot,spec.art_assets[key].path);if(!artPath.startsWith(privateRoot+path.sep))throw Error('Invalid artwork path');}const bytes=await readFile(artPath);if(spec.art_assets?.[key]?.sha256&&createHash('sha256').update(bytes).digest('hex')!==spec.art_assets[key].sha256)throw Error('Frozen artwork checksum mismatch');artMap[key]=`data:image/svg+xml;base64,${bytes.toString('base64')}`;}
const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});
const files=[],checks=[];
function pngDensity(bytes){
 const data=Buffer.alloc(9);data.writeUInt32BE(Math.round(ppi/0.0254),0);data.writeUInt32BE(Math.round(ppi/0.0254),4);data[8]=1;
 const type=Buffer.from('pHYs'),payload=Buffer.concat([type,data]);let crc=0xffffffff;for(const byte of payload){crc^=byte;for(let i=0;i<8;i++)crc=crc&1?(crc>>>1)^0xedb88320:crc>>>1;}const header=Buffer.alloc(4),end=Buffer.alloc(4);header.writeUInt32BE(9);end.writeUInt32BE((crc^0xffffffff)>>>0);
 return Buffer.concat([bytes.subarray(0,33),header,payload,end,bytes.subarray(33)]);
}
async function capture(page,file,wmm,hmm){const width=Math.round(wmm/25.4*ppi),height=Math.round(hmm/25.4*ppi);await page.setViewportSize({width,height});await page.locator('svg').evaluate((svg,{width,height})=>{svg.style.width=width+'px';svg.style.height=height+'px';},{width,height});const bytes=await page.screenshot({clip:{x:0,y:0,width,height},omitBackground:true,animations:'disabled'});await writeFile(file,pngDensity(bytes));await page.locator('svg').evaluate(svg=>{svg.style.width='';svg.style.height='';});}

try{
 const page=await browser.newPage({deviceScaleFactor:1});await page.route('**/*',r=>r.abort());
 const sides=spec.options.mode==='single'?[spec.options.printed_side]:['front','back'];
 for(const side of sides){const t=spec.templates[side];if(!t)throw Error('Missing template '+side);const doc=spec.document.sides[side],transparent=spec.options.mode==='mixed'&&side===spec.options.transparent_side,g=t.geometry;
 const opts={id:side,transparent,resolve:e=>e.type==='art'?artMap[e.art_key]:resolveMap[e.asset_id]};
 if(!transparent&&doc.elements.some(e=>e.type==='image'&&!resolveMap[e.asset_id]))throw Error('Missing image');
 const html=svg=>`<!doctype html><html><head><meta charset="utf-8"><style>${fonts}*{box-sizing:border-box}html,body{margin:0;padding:0;background:transparent}svg{display:block} @page{margin:0}</style></head><body>${svg}</body></html>`;
 if(spec.purpose==='catalog'){
  if(transparent)continue;
  const artworkTemplate={...t,geometry:{...g,holes:[],radius:0,bleed:0}};
  await page.setContent(html(renderSide(doc,artworkTemplate,opts)));await page.evaluate(()=>document.fonts.ready);await page.evaluate(fitTextElements);await replacePrivateText(page,browser,fontAssets,doc,nameRasterCache);
  await page.evaluate(async()=>{await Promise.all([...document.querySelectorAll('image')].map(el=>new Promise((resolve,reject)=>{const image=new Image();image.onload=resolve;image.onerror=reject;image.src=el.getAttribute('href');})));});
  await capture(page,path.join(outDir,`${side}-catalog.png`),g.width,g.height);files.push({side,kind:'catalog',name:`${side}-catalog.png`});continue;
 }
 const clean=renderSide(doc,t,opts);await page.setContent(html(clean));await page.evaluate(()=>document.fonts.ready);await page.evaluate(fitTextElements);await replacePrivateText(page,browser,fontAssets,doc,nameRasterCache);await page.locator('img').evaluateAll(imgs=>Promise.all(imgs.map(i=>i.decode())));
 await capture(page,path.join(outDir,`${side}-preview.png`),g.width,g.height);files.push({side,kind:'preview',name:`${side}-preview.png`});
 const cut=renderCut(t);await writeFile(path.join(outDir,`${side}-cut.svg`),cut);files.push({side,kind:'cut',name:`${side}-cut.svg`});
 if(!transparent){const bleed=renderSide(doc,t,{...opts,bleed:true}),w=g.width+2*g.bleed,h=g.height+2*g.bleed;await page.setContent(html(bleed));await page.evaluate(()=>document.fonts.ready);await page.evaluate(fitTextElements);await replacePrivateText(page,browser,fontAssets,doc,nameRasterCache);await page.evaluate(async()=>{await Promise.all([...document.querySelectorAll('image')].map(el=>new Promise((res,rej)=>{const i=new Image();i.onload=res;i.onerror=rej;i.src=el.getAttribute('href');})));});
 await capture(page,path.join(outDir,`${side}-print.png`),w,h);
 const pdf=await page.pdf({width:`${w}mm`,height:`${h}mm`,margin:{top:0,right:0,bottom:0,left:0},printBackground:true,omitBackground:true,preferCSSPageSize:false});
 const document=await PDFDocument.load(pdf),pt=72/25.4;for(const p of document.getPages()){const targetW=w*pt,targetH=h*pt,original=p.getSize();p.translateContent(0,targetH-original.height);p.setMediaBox(0,0,targetW,targetH);p.setCropBox(0,0,targetW,targetH);p.setTrimBox(g.bleed*pt,g.bleed*pt,g.width*pt,g.height*pt);p.setBleedBox(0,0,w*pt,h*pt);}
 await writeFile(path.join(outDir,`${side}-print.pdf`),await document.save());const fitted=await page.locator('svg').evaluate(svg=>new XMLSerializer().serializeToString(svg));await writeFile(path.join(outDir,`${side}-artwork.svg`),fitted.replace('<defs>','<defs><style>'+fontAssets.publicCss+'</style>'));
 for(const [kind,ext]of [['print_png','png'],['print_pdf','pdf'],['artwork','svg']])files.push({side,kind,name:`${side}-${kind==='artwork'?'artwork':'print'}.${ext}`});
 }
 checks.push({side,printed:!transparent,material_id:transparent?spec.options.transparent_material_id:spec.options.material_id,finish:transparent?null:spec.finish??null,width_mm:g.width,height_mm:g.height,bleed_mm:g.bleed,template_id:t.id,template_version:t.version,orientation:'upright; no automatic mirroring',warnings:transparent?[]:warnings(doc,t,spec.assets,ppi)});
 }
 const manifest={order:spec.order_number??'ENGINEERING-SAMPLE',item:spec.item_id??'SAMPLE',quantity:spec.quantity??1,color_space:'sRGB',pdf_standard:'PDF (not PDF/X)',ppi,personalization:spec.options.personalization??null,materials:spec.materials??[],finish:spec.finish??null,finish_applies_to:sides.filter(side=>!(spec.options.mode==='mixed'&&side===spec.options.transparent_side)),template_physical_approval:spec.templates,checks,files,approved_for_production:Boolean(spec.production_approved),generated_at:new Date().toISOString()};
 await writeFile(path.join(outDir,'manifest.json'),JSON.stringify(manifest,null,2));await writeFile(path.join(outDir,'design.json'),JSON.stringify({...spec,fonts:Object.fromEntries(Object.entries(spec.fonts??{}).map(([family,font])=>[family,{files:font.files.map(file=>({...file,path:undefined}))}])),art_assets:Object.fromEntries(Object.entries(spec.art_assets??{}).map(([key,a])=>[key,{...a,path:undefined}])),assets:Object.fromEntries(Object.entries(spec.assets??{}).map(([id,a])=>[id,{...a,path:undefined}]))},null,2));
 files.push({side:'all',kind:'manifest',name:'manifest.json'},{side:'all',kind:'design',name:'design.json'});
 await writeFile(path.join(outDir,'files.json'),JSON.stringify(files));process.stdout.write(JSON.stringify({files,checks}));
}finally{await browser.close();}
