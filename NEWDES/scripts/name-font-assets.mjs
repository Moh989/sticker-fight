import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {renderSide,fitTextElements} from '../resources/js/shared/renderer.mjs';

export async function loadDesignFonts(spec,root){
 const definitions=JSON.parse(await readFile(path.join(root,'resources/design-fonts.json'),'utf8'));
 const families=[...new Set(Object.values(spec.document.sides).flatMap(side=>side.elements.filter(element=>element.type==='text').map(element=>element.font)))];
 let css='',publicCss='';const privateFamilies=[];
 for(const family of families){const definition=definitions[family];if(!definition)throw Error('Unsupported font');const frozen=spec.fonts?.[family],privateFont=frozen?.server_rendered??definition.server_rendered??false,files=frozen?.files??definition.files;if(privateFont)privateFamilies.push(family);
  for(const file of files){if(path.basename(file.file)!==file.file)throw Error('Invalid font file');const privateRoot=path.join(root,'storage/app/private'),filePath=file.path?path.resolve(privateRoot,file.path):privateFont?path.join(privateRoot,'design-fonts',file.file):path.join(root,'public/fonts',file.file);if(file.path&&!filePath.startsWith(privateRoot+path.sep))throw Error('Invalid frozen font path');const bytes=await readFile(filePath);if(file.sha256&&createHash('sha256').update(bytes).digest('hex')!==file.sha256)throw Error('Frozen font checksum mismatch');const face=`@font-face{font-family:'${family}';src:url('data:font/${file.file.endsWith('.ttf')?'ttf':'woff2'};base64,${bytes.toString('base64')}');font-weight:${file.weight??'400'};${file.unicode_range?'unicode-range:'+file.unicode_range+';':''}}`;css+=face;if(!privateFont)publicCss+=face;}
 }
 return {css,publicCss,privateFamilies};
}

export async function namePng(browser,css,element){
 const width=Math.max(1,Math.round(element.width/25.4*600)),height=Math.max(1,Math.round(element.height/25.4*600));
 const page=await browser.newPage({viewport:{width,height},deviceScaleFactor:1});
 try{await page.route('**/*',route=>route.abort());const template={geometry:{width:element.width,height:element.height,radius:0,holes:[],bleed:0,safe:0}};const svg=renderSide({background:null,elements:[{...element,x:0,y:0,rotation:0}]},template);await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>${css}html,body{margin:0;background:transparent}svg{display:block;width:100vw;height:100vh}</style></head><body>${svg}</body></html>`);await page.evaluate(fitTextElements);return await page.screenshot({omitBackground:true,animations:'disabled'});}finally{await page.close();}
}

export async function replacePrivateText(page,browser,fonts,document,cache){
 for(const element of document.elements){if(element.type!=='text'||!fonts.privateFamilies.includes(element.font))continue;const key=JSON.stringify(element);if(!cache.has(key))cache.set(key,'data:image/png;base64,'+(await namePng(browser,fonts.css,element)).toString('base64'));await page.evaluate(({id,width,height,url})=>{const group=[...document.querySelectorAll('[data-element]')].find(node=>node.dataset.element===id);if(!group)return;const image=document.createElementNS('http://www.w3.org/2000/svg','image');for(const [name,value]of Object.entries({href:url,x:0,y:0,width,height,preserveAspectRatio:'none'}))image.setAttribute(name,value);group.replaceChildren(image);},{id:element.id,width:element.width,height:element.height,url:cache.get(key)});}
}
