import './theme.js';
import {t as translate} from './i18n.js';
import {materialAvailable,finishAvailable,templateCompatible} from './shared/compatibility.mjs';
import {renderSide,warnings as designWarnings} from './shared/renderer.mjs';
export async function api(url,{method='POST',body}={}){
 const headers={'X-Requested-With':'XMLHttpRequest','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content??''};
 if(!(body instanceof FormData))headers['Content-Type']='application/json';
 let response;try{response=await fetch(url,{method,headers,credentials:'same-origin',body:body instanceof FormData?body:JSON.stringify(body)});}catch{const e=new Error(translate('انقطع الاتصال. عملك محفوظ على هذا الجهاز؛ أعد المحاولة.'));e.status=0;throw e;}
 const data=await response.json().catch(()=>({}));if(!response.ok){const message=data.errors?Object.values(data.errors).flat().join(' '):(response.status===419?translate('انتهت صلاحية الجلسة. احفظ نسخة محلية وأعد فتح الصفحة.'):data.message??translate('تعذر إكمال العملية. أعد المحاولة.'));const error=new Error(message);error.status=response.status;throw error;}return data;
}
export function toast(text){const el=document.querySelector('#toast');if(!el)return;el.textContent=text;el.hidden=false;clearTimeout(window.stickerToastTimer);window.stickerToastTimer=setTimeout(()=>el.hidden=true,3500);}
const siteHeader=document.querySelector('[data-site-header]');
if(siteHeader){const syncHeader=()=>siteHeader.classList.toggle('is-scrolled',window.scrollY>8);syncHeader();window.addEventListener('scroll',syncHeader,{passive:true});}
const menu=document.querySelector('#menu-toggle'),mobileNav=document.querySelector('#mobile-nav');
if(menu&&mobileNav){
 const setMenu=open=>{mobileNav.hidden=!open;menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',translate(open?'إغلاق القائمة':'فتح القائمة'));};
 menu.onclick=()=>setMenu(mobileNav.hidden);
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!mobileNav.hidden){setMenu(false);menu.focus();}});
 window.matchMedia('(min-width: 801px)').addEventListener('change',event=>{if(event.matches)setMenu(false);});
}
const revealTargets=document.querySelectorAll('[data-reveal]');
if(revealTargets.length&&'IntersectionObserver' in window&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
 const revealObserver=new IntersectionObserver(entries=>{for(const entry of entries){if(entry.isIntersecting){entry.target.classList.add('is-revealed');revealObserver.unobserve(entry.target);}}},{rootMargin:'0px 0px -6% 0px',threshold:.06});
 revealTargets.forEach(element=>{if(element.getBoundingClientRect().top>window.innerHeight*.9){element.classList.add('reveal');revealObserver.observe(element);}});
}
if(document.body.hasAttribute('data-protect-images')){
 document.addEventListener('contextmenu',event=>{if(!(event.target instanceof Element)||!event.target.closest('input,textarea,[contenteditable="true"]'))event.preventDefault();});
 document.addEventListener('dragstart',event=>{if(event.target instanceof Element&&event.target.closest('img,.product-image,.detail-art,.preview-skin'))event.preventDefault();});
 document.querySelectorAll('img').forEach(image=>image.draggable=false);
}
const readyForm=document.querySelector('#ready-product-form');
if(readyForm){
 const submit=readyForm.querySelector('#ready-add-to-cart'),summary=readyForm.querySelector('#ready-summary'),errorBox=readyForm.querySelector('#ready-error');let quoteSequence=0,quoteTimer;
 const formData=()=>Object.fromEntries(new FormData(readyForm));
 const showError=message=>{errorBox.replaceChildren();if(message){const note=document.createElement('p');note.className='notice error';note.textContent=message;errorBox.append(note);}};
 readyForm.querySelectorAll('[data-ready-template]').forEach((element,index)=>{const template=JSON.parse(element.dataset.readyTemplate);element.innerHTML=renderSide({background:'#34343E',elements:[]},template,{id:'ready-template-'+index});});
 const templateRecords=[...readyForm.querySelectorAll('[data-ready-template]')].map(element=>JSON.parse(element.dataset.readyTemplate));
 const selectedOptions=()=>({mode:readyForm.elements.mode.value,printed_side:readyForm.elements.printed_side.value,transparent_side:readyForm.elements.transparent_side.value,material_id:Number(readyForm.elements.material_id.value)||null,transparent_material_id:Number(readyForm.elements.transparent_material_id.value)||null,finish_id:Number(readyForm.elements.finish_id.value)||null});
 function controls(initial=false){let options=selectedOptions();const mode=options.mode,printed=options.printed_side;
  for(const [field,visible]of [['printed_side',mode==='single'],['transparent_side',mode==='mixed'],['transparent_material_id',mode==='mixed']]){const label=readyForm.querySelector(`[data-ready-field="${field}"]`);label.hidden=!visible;label.querySelector('select').disabled=!visible;label.querySelector('select').required=visible;}
  for(const [field,clear]of [['material_id',false],['transparent_material_id',true]]){if(clear&&mode!=='mixed')continue;const select=readyForm.elements[field];for(const option of select.options)option.disabled=Boolean(option.value)&&!materialAvailable(templateRecords,options,option.value,clear);if(initial&&readyForm.dataset.editingCart!=='1'&&select.selectedOptions[0]?.disabled)select.value=[...select.options].find(option=>option.value&&!option.disabled)?.value??'';options=selectedOptions();}
  for(const option of readyForm.elements.finish_id.options)option.disabled=Boolean(option.value)&&!finishAvailable(templateRecords,options,option.value);
  readyForm.querySelectorAll('[data-ready-template-side]').forEach(group=>{const side=group.dataset.readyTemplateSide,visible=mode!=='single'||side===printed;group.hidden=!visible;group.disabled=!visible;
   group.querySelectorAll('.template-option').forEach(label=>{const template=JSON.parse(label.querySelector('[data-ready-template]').dataset.readyTemplate),compatible=templateCompatible(template,options,side),input=label.querySelector('input');label.hidden=!compatible;input.disabled=!visible||!compatible;input.required=visible&&compatible;if(!compatible)input.checked=false;});
   group.querySelector('[data-ready-no-compatible]').hidden=!visible||!group.querySelector('.template-option')||Boolean(group.querySelector('input:enabled'));
  });
  return materialAvailable(templateRecords,options,options.material_id)&&(mode!=='mixed'||materialAvailable(templateRecords,options,options.transparent_material_id,true))&&(!options.finish_id||finishAvailable(templateRecords,options,options.finish_id));
 }
 async function quote(){const sequence=++quoteSequence;submit.disabled=true;summary.className='notice';showError('');const compatible=controls();document.querySelector('#ready-preview').hidden=true;document.querySelector('#ready-original-preview').hidden=false;if(readyForm.dataset.configured!=='1')return;
  if(!compatible){summary.textContent=translate('اختار خامة وتشطيباً متوافقين مع القوالب المتاحة.');return;}
  if(!readyForm.checkValidity()){summary.textContent=translate('اختار القالب وخيارات الطلب لحساب الإجمالي.');return;}
  summary.textContent=translate('جارٍ حساب السعر...');
  try{const result=await api(readyForm.dataset.quoteUrl,{body:formData()});if(sequence!==quoteSequence)return;summary.textContent=Number(result.subtotal_iqd).toLocaleString('en-US')+translate(' د.ع')+' · '+result.quantity+' '+translate('قطعة — قبل التوصيل');summary.className='notice success';
   const preview=document.querySelector('#ready-preview');preview.replaceChildren();const sides=result.preview.options.mode==='single'?[result.preview.options.printed_side]:['front','back'];
   for(const side of sides){const figure=document.createElement('figure'),skin=document.createElement('div'),caption=document.createElement('figcaption');skin.className='preview-skin';const clear=result.preview.options.mode==='mixed'&&result.preview.options.transparent_side===side;skin.innerHTML=renderSide(result.preview.document.sides[side],result.preview.templates[side],{id:'ready-preview-'+side,transparent:clear,resolve:()=>readyForm.dataset.imageUrl});caption.textContent=(side==='front'?translate('الأمام'):translate('الخلف'))+(clear?' · '+translate('شفاف غير مطبوع'):'');figure.append(skin,caption);preview.append(figure);}
   preview.hidden=false;document.querySelector('#ready-original-preview').hidden=true;submit.disabled=false;
  }catch(error){if(sequence===quoteSequence){summary.className='notice warning';summary.textContent=error.message;}}
 }
 readyForm.addEventListener('change',()=>{clearTimeout(quoteTimer);quoteSequence++;submit.disabled=true;controls();quoteTimer=setTimeout(quote,120);});
 readyForm.onsubmit=async event=>{event.preventDefault();clearTimeout(quoteTimer);quoteSequence++;submit.disabled=true;showError('');try{if(!controls()||!readyForm.checkValidity())throw Error(translate('اختار القالب والخامة والتشطيب المتوافقين قبل الإضافة للسلة.'));const result=await api(readyForm.action,{body:formData()});location.assign(result.redirect);}catch(error){showError(error.message);submit.disabled=false;}};
 controls(true);quote();
}
document.querySelectorAll('[data-design-preview]').forEach((el,i)=>{const p=JSON.parse(el.dataset.designPreview),s=p.side??'front',t=p.templates[s];el.innerHTML=renderSide(p.document.sides[s],t,{id:'preview-'+i,transparent:p.options.mode==='mixed'&&p.options.transparent_side===s,resolve:e=>{if(p.orderId)return `/orders/${p.orderId}/${e.type==='art'?'art/'+e.art_key:'assets/'+e.asset_id}?token=${encodeURIComponent(p.token)}`;if(p.itemId)return `/admin/items/${p.itemId}/${e.type==='art'?'art/'+e.art_key:'assets/'+e.asset_id}`;return e.type==='art'?`/art/${e.art_key}.svg`:`/${p.staff?'admin/assets':'media'}/${e.asset_id}`;}});if(p.staff){for(const message of designWarnings(p.document.sides[s],t,p.assets??{})){const note=document.createElement('p');note.className='notice warning';note.textContent=message;el.after(note);}}if(p.options.mode==='mixed'&&p.options.transparent_side===s){const n=document.createElement('span');n.className='preview-clear-label';n.textContent=translate('شفاف غير مطبوع');el.style.position='relative';el.append(n);}});
document.querySelectorAll('.copy-value').forEach(b=>b.onclick=async()=>{try{await navigator.clipboard.writeText(b.dataset.copy);toast(translate('تم نسخ الرابط الخاص.'));}catch{const input=document.createElement('input');input.value=b.dataset.copy;input.dir='ltr';b.parentNode.append(input);input.select();toast(translate('انسخ الرابط من الحقل.'));}});
if(new URLSearchParams(location.search).get('focus')==='search')document.querySelector('#catalog-search')?.focus();
const checkout=document.querySelector('#checkout-form');if(checkout){
 const gov=checkout.querySelector('#checkout-governorate'),area=checkout.querySelector('#checkout-area'),areas=JSON.parse(checkout.dataset.areas),submit=checkout.querySelector('#place-order'),summary=checkout.querySelector('#checkout-summary'),errorBox=checkout.querySelector('#checkout-error');let quoteSequence=0;
 const data=()=>Object.fromEntries(new FormData(checkout));
 const showError=text=>{errorBox.replaceChildren();if(text){const p=document.createElement('div');p.className='notice error';p.textContent=text;errorBox.append(p);}};
 async function quote(){const sequence=++quoteSequence;submit.disabled=true;if(!gov.value)return;showError('');try{const q=await api('/api/checkout/quote',{body:{governorate_id:Number(gov.value),area_id:area.value?Number(area.value):null,coupon:data().coupon}});if(sequence!==quoteSequence)return;summary.replaceChildren();for(const [label,key]of [[translate('المنتجات'),'subtotal_iqd'],[translate('الخصم'),'discount_iqd'],[translate('التوصيل'),'shipping_iqd'],[translate('الإجمالي'),'total_iqd']]){const row=document.createElement('div');row.className='summary-row'+(key==='total_iqd'?' total':'');const s=document.createElement('span'),b=document.createElement('b');s.textContent=label;b.textContent=Number(q[key]).toLocaleString('en-US')+translate(' د.ع');row.append(s,b);summary.append(row);}if(q.bundle){const p=document.createElement('p');p.className='tool-hint';p.textContent=(document.documentElement.lang==='en'?'Bundle: ':'الباقة: ')+q.bundle;summary.append(p);}if(q.estimated_time){const p=document.createElement('p');p.className='tool-hint';p.textContent=translate('المدة التقديرية: ')+q.estimated_time;summary.append(p);}submit.disabled=false;}catch(e){if(sequence===quoteSequence)showError(e.message);}}
 gov.onchange=()=>{area.replaceChildren(new Option(translate('التغطية العامة'),''));const matching=areas.filter(a=>Number(a.governorate_id)===Number(gov.value));matching.forEach(a=>area.add(new Option(a.name,a.id)));checkout.querySelector('#checkout-area-field').hidden=matching.length===0;quote();};area.onchange=quote;checkout.querySelector('#apply-coupon').onclick=quote;
 checkout.onsubmit=async e=>{e.preventDefault();submit.disabled=true;showError('');try{const d=data();d.governorate_id=Number(d.governorate_id);d.area_id=d.area_id?Number(d.area_id):null;const result=await api('/api/checkout',{body:d});location.assign(result.redirect);}catch(e){showError(e.message);submit.disabled=false;}};
}
const tracking=document.querySelector('#tracking-form');if(tracking)tracking.onsubmit=e=>{e.preventDefault();const box=document.querySelector('#tracking-error');try{const url=new URL(document.querySelector('#tracking-url').value,location.origin);if(url.origin!==location.origin||!/^\/orders\/[0-9a-f-]{36}$/i.test(url.pathname)||!url.searchParams.get('token')?.match(/^[a-f0-9]{64}$/))throw Error();location.assign(url.href);}catch{box.textContent=translate('أدخل رابط المتابعة الخاص من هذا المتجر كاملاً.');box.className='notice error';}};
