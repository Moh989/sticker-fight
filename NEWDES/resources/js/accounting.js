import {api} from './app.js';

const entry=document.querySelector('[data-accounting-entry-form]');
if(entry){
    const sync=()=>{const type=entry.elements.type.value,material=type==='material_purchase',capital=type==='capital_in'||type==='capital_out';entry.querySelector('[data-material-purchase-fields]').hidden=!material;for(const name of ['stock_item_id','quantity']){entry.elements[name].disabled=!material;entry.elements[name].required=material;}entry.querySelector('[data-paid-field]').hidden=capital;entry.elements.paid_iqd.disabled=capital;entry.querySelector('[data-capital-hint]').hidden=!capital;};
    entry.elements.type.addEventListener('change',sync);sync();
}
const stock=document.querySelector('[data-stock-form]');
if(stock){const sync=()=>{const sheet=stock.elements.unit.value==='sheet';stock.querySelector('[data-stock-dimensions]').hidden=!sheet;for(const name of ['width_mm','height_mm']){stock.elements[name].disabled=!sheet;stock.elements[name].required=sheet;}};stock.elements.unit.addEventListener('change',sync);sync();}
const cost=document.querySelector('[data-cost-form]');
if(cost){
    const rows=cost.querySelector('[data-cost-components]'),add=cost.querySelector('[data-add-cost-component]'),error=cost.querySelector('[data-cost-error]'),result=cost.querySelector('[data-cost-result]');
    const sync=()=>{for(const select of rows.querySelectorAll('select[name$="[stock_item_id]"]'))for(const option of select.options)option.disabled=Boolean(option.value)&&option.value===cost.elements.paper_id.value;add.disabled=rows.children.length>=8;};
    cost.elements.paper_id.addEventListener('change',sync);sync();
    add.addEventListener('click',()=>{if(rows.children.length>=8)return;const index=rows.children.length,row=rows.firstElementChild.cloneNode(true);for(const input of row.querySelectorAll('[name]')){input.name=input.name.replace(/components\[\d+\]/,`components[${index}]`);if(input.tagName==='SELECT')input.value=input.name.endsWith('[basis]')?'per_sheet':'';else input.value='0';}rows.append(row);sync();});
    cost.addEventListener('submit',async event=>{const button=event.submitter;if(!button?.hasAttribute('data-calculate'))return;event.preventDefault();button.disabled=true;error.hidden=true;result.setAttribute('aria-busy','true');try{const response=await api(cost.action,{body:new FormData(cost)});result.innerHTML=response.html;result.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});}catch(failure){error.textContent=failure.message;error.hidden=false;}finally{button.disabled=false;result.removeAttribute('aria-busy');}});
}
