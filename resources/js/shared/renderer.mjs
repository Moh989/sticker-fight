export const escapeXml = s => String(s ?? '').replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&apos;'}[c]));
export const fallbackGeometry = {width:85.6,height:53.98,radius:3.18,bleed:2,safe:3,holes:[]};
export function rectPath(x,y,w,h,r=0){r=Math.max(0,Math.min(r,w/2,h/2));return `M${x+r} ${y}H${x+w-r}Q${x+w} ${y} ${x+w} ${y+r}V${y+h-r}Q${x+w} ${y+h} ${x+w-r} ${y+h}H${x+r}Q${x} ${y+h} ${x} ${y+h-r}V${y+r}Q${x} ${y} ${x+r} ${y}Z`;}
export function cutPath(g,bleed=0){return rectPath(-bleed,-bleed,g.width+2*bleed,g.height+2*bleed,g.radius+bleed)+(g.holes??[]).filter(h=>h.width>2*bleed&&h.height>2*bleed).map(h=>rectPath(h.x+bleed,h.y+bleed,h.width-2*bleed,h.height-2*bleed,Math.max(0,(h.radius??0)-bleed))).join('');}
export function renderSide(doc,template,opts={}){
 const g=template?.geometry??fallbackGeometry,b=opts.bleed?(g.bleed??0):0,w=g.width+2*b,h=g.height+2*b,resolve=opts.resolve??(e=>e.type==='art'?`/art/${e.art_key}.svg`:`/media/${e.asset_id}`),id=opts.id??'skin';
 const elements=opts.transparent?'':doc.elements.map(e=>{
 const transform=`translate(${Number(e.x)} ${Number(e.y)}) rotate(${Number(e.rotation)} ${e.width/2} ${e.height/2})`;
 let inside='';if(e.type==='text'){
 const firstLetter=String(e.text).match(/\p{Letter}/u)?.[0]??'',rtl=/\p{Script=Arabic}/u.test(firstLetter),x=e.align==='middle'?e.width/2:e.align==='end'?(rtl?0:e.width):(rtl?e.width:0);
 inside=`<text x="${x}" y="${e.fontSize}" font-family="${escapeXml(e.font)}" font-size="${e.fontSize}" fill="${e.color}" text-anchor="${e.align}" direction="${rtl?'rtl':'ltr'}" style="unicode-bidi:plaintext">${String(e.text).split('\n').map((line,i)=>`<tspan x="${x}" dy="${i?e.fontSize*1.45:0}">${escapeXml(line)}</tspan>`).join('')}</text>`;
 }else{inside=`<image href="${escapeXml(resolve(e))}" x="0" y="0" width="${e.width}" height="${e.height}" preserveAspectRatio="xMidYMid ${e.fit==='contain'?'meet':'slice'}"/>`;}
 return `<g data-element="${escapeXml(e.id)}" transform="${transform}">${inside}</g>`;
 }).join('');
 return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}mm" height="${h}mm" viewBox="${-b} ${-b} ${w} ${h}" role="img" aria-label="معاينة التصميم"><defs><clipPath id="clip-${id}"><path d="${cutPath(g,b)}" clip-rule="evenodd"/></clipPath></defs><g clip-path="url(#clip-${id})">${opts.transparent?'':`${doc.background?`<rect x="${-b}" y="${-b}" width="${w}" height="${h}" fill="${doc.background}"/>`:''}${elements}`}</g>${opts.guides?`<g pointer-events="none" fill="none" stroke-width=".15">${g.bleed>0?`<path data-guide="bleed" d="${cutPath(g,g.bleed)}" stroke="#806000" stroke-dasharray="1 .6"/>`:''}<path d="${cutPath(g)}" stroke="#FF2D8D"/><rect x="${g.safe}" y="${g.safe}" width="${g.width-2*g.safe}" height="${g.height-2*g.safe}" rx="${g.radius}" stroke="#18a789" stroke-dasharray="1 1"/>${g.holes.map(v=>`<rect x="${v.x-g.safe}" y="${v.y-g.safe}" width="${v.width+2*g.safe}" height="${v.height+2*g.safe}" stroke="#d88715" stroke-dasharray="1 1"/>`).join('')}</g>`:''}</svg>`;
}
export function renderCut(template){const g=template.geometry;return `<svg xmlns="http://www.w3.org/2000/svg" width="${g.width}mm" height="${g.height}mm" viewBox="0 0 ${g.width} ${g.height}"><title>Cut path — millimetres — ${escapeXml(template.side)} — v${template.version}</title><path d="${cutPath(g)}" fill="none" stroke="#FF0000" stroke-width="0.05" vector-effect="non-scaling-stroke"/></svg>`;}
export function imagePpi(e,asset){const cover=e.fit!=='contain';return (cover?Math.min:Math.max)(asset.width/(e.width/25.4),asset.height/(e.height/25.4));}
export function warnings(doc,template,assets={},targetPpi=300){
 const g=template?.geometry??fallbackGeometry,result=[];
 for(const e of doc.elements){if(e.type==='image'&&assets[e.asset_id]){const ppi=imagePpi(e,assets[e.asset_id]);if(ppi<targetPpi)result.push(`دقة الصورة ${Math.round(ppi)} PPI عند هذا الحجم؛ الهدف ${targetPpi} PPI.`);}
 if(e.type==='text'){
 const a=e.rotation*Math.PI/180,cx=e.x+e.width/2,cy=e.y+e.height/2,pts=[[-e.width/2,-e.height/2],[e.width/2,-e.height/2],[e.width/2,e.height/2],[-e.width/2,e.height/2]].map(([x,y])=>[cx+x*Math.cos(a)-y*Math.sin(a),cy+x*Math.sin(a)+y*Math.cos(a)]);const x=Math.min(...pts.map(p=>p[0])),y=Math.min(...pts.map(p=>p[1])),right=Math.max(...pts.map(p=>p[0])),bottom=Math.max(...pts.map(p=>p[1]));
 if(x<g.safe||y<g.safe||right>g.width-g.safe||bottom>g.height-g.safe||g.holes.some(h=>x<h.x+h.width+g.safe&&right>h.x-g.safe&&y<h.y+h.height+g.safe&&bottom>h.y-g.safe))result.push('نص قريب من القص أو فتحة مستثناة؛ حرّكه داخل منطقة الأمان.');
 }}return [...new Set(result)];
}
