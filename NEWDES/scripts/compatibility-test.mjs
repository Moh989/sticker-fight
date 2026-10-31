import assert from 'node:assert/strict';
import {activeSides,isClearSide,templateCompatible,materialAvailable,finishAvailable} from '../resources/js/shared/compatibility.mjs';

const template=(id,side,materials,finishes=[])=>({id,side,compatibility:{modes:['single','double','mixed'],material_ids:materials,finish_ids:finishes}});
const front=template(1,'front',[1,2],[1]),back=template(2,'back',[1,2],[2]);
const single={mode:'single',printed_side:'front',transparent_side:'front',material_id:1,transparent_material_id:2,finish_id:null};
const checks=[];
function check(name,run){run();checks.push(name);}
check('single face and material restrictions',()=>{assert.deepEqual(activeSides(single),['front']);assert.equal(templateCompatible(front,single,'front'),true);assert.equal(templateCompatible(back,single,'front'),false);assert.equal(materialAvailable([front,back],single,4),false);});
check('numeric IDs from forms and JSON are compatible',()=>{assert.equal(templateCompatible(template(3,'front',['1'],['1']),{...single,material_id:'1',finish_id:'1'},'front'),true);});
check('double faces require compatible stock on both sides',()=>{assert.equal(materialAvailable([front,template(4,'back',[3])],{...single,mode:'double'},1),false);assert.equal(materialAvailable([front,back],{...single,mode:'double'},1),true);});
check('material recovery is possible when the current finish is incompatible',()=>{assert.equal(materialAvailable([front],{...single,finish_id:9},1),true);assert.equal(finishAvailable([front],single,9),false);assert.equal(finishAvailable([front],single,null),true);});
for(const clearSide of ['front','back'])check(`unprinted ${clearSide} ignores printed finish`,()=>{const options={...single,mode:'mixed',transparent_side:clearSide,finish_id:1},clear=template(5,clearSide,[2],[]),printed=template(6,clearSide==='front'?'back':'front',[1],[1]);assert.equal(isClearSide(options,clearSide),true);assert.equal(templateCompatible(clear,options,clearSide),true);assert.equal(finishAvailable([clear,printed],options,1),true);assert.equal(materialAvailable([clear,printed],options,2,true),true);});
check('a missing opposite template cannot be treated as producible',()=>{assert.equal(finishAvailable([front],{...single,mode:'mixed'},1),false);});
check('unsupported modes remain rejected',()=>{assert.equal(templateCompatible({...front,compatibility:{...front.compatibility,modes:['double']}},single,'front'),false);});
console.log(JSON.stringify({passed:true,checks:checks.length,names:checks}));
