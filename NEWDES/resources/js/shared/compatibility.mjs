export const activeSides = options => options.mode === 'single' ? [options.printed_side] : ['front', 'back'];
export const isClearSide = (options, side) => options.mode === 'mixed' && options.transparent_side === side;

export function templateCompatible(template, options, side, {ignoreFinish = false} = {}) {
    if (template.side !== side) return false;
    const rules = template.compatibility ?? {};
    const clear = isClearSide(options, side);
    const material = Number(options[clear ? 'transparent_material_id' : 'material_id']);
    return (rules.modes ?? []).includes(options.mode)
        && (rules.material_ids ?? []).map(Number).includes(material)
        && (clear || ignoreFinish || !options.finish_id || (rules.finish_ids ?? []).map(Number).includes(Number(options.finish_id)));
}

export function materialAvailable(templates, options, materialId, clear = false) {
    const candidate = {...options, [clear ? 'transparent_material_id' : 'material_id']: Number(materialId)};
    const sides = activeSides(candidate).filter(side => isClearSide(candidate, side) === clear);
    return sides.length > 0 && sides.every(side => templates.some(template => templateCompatible(template, candidate, side, {ignoreFinish: true})));
}

export function finishAvailable(templates, options, finishId) {
    const candidate = {...options, finish_id: finishId ? Number(finishId) : null};
    return activeSides(candidate).every(side => templates.some(template => templateCompatible(template, candidate, side)));
}
