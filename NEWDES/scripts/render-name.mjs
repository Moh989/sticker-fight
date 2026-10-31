import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
import {chromium} from 'playwright';
import {loadDesignFonts,namePng} from './name-font-assets.mjs';
const [sourcePath,outputPath]=process.argv.slice(2);if(!sourcePath||!outputPath)throw Error('Name source and output are required.');
const source=JSON.parse(await readFile(sourcePath,'utf8')),spec={document:{sides:{front:{elements:[source.element]}}},fonts:source.fonts??{}};
const fonts=await loadDesignFonts(spec,path.resolve(import.meta.dirname,'..'));
const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});
try{await writeFile(outputPath,await namePng(browser,fonts.css,source.element));}finally{await browser.close();}
