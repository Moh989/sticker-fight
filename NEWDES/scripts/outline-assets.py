"""Optional brand build helper. Requires fonttools + brotli. Shipped SVGs are already outlined."""
from pathlib import Path
import re
import xml.etree.ElementTree as ET
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
ROOT=Path(__file__).resolve().parent.parent
NS='http://www.w3.org/2000/svg'
ET.register_namespace('',NS)
fonts={name:TTFont(ROOT/'public/fonts'/filename) for name,filename in [('cairo','cairo-latin.woff2'),('bebas','bebas-neue.woff2')]}
def render_text(parent,text):
    family=text.get('font-family','sans-serif');kind='bebas' if 'Impact' in family and '/art/' in str(current) else 'cairo'
    font=fonts[kind];weight=int(text.get('font-weight','400'));glyphs=font.getGlyphSet(location={'wght':weight} if kind=='cairo' else None);cmap=font.getBestCmap();units=font['head'].unitsPerEm
    size=float(text.get('font-size','16'));scale=size/units;spacing=float(text.get('letter-spacing','0'));segments=[(text.text or '',text.get('fill','#000000'))]+[(child.text or '',child.get('fill',text.get('fill','#000000'))) for child in text]
    width=sum((sum(glyphs[cmap.get(ord(c),'.notdef')].width*scale+spacing for c in value)) for value,_ in segments)-spacing
    x=float(text.get('x','0'));y=float(text.get('y','0'));anchor=text.get('text-anchor','start');x-=width/2 if anchor=='middle' else width if anchor=='end' else 0
    group=ET.Element('{'+NS+'}g',{'aria-label':''.join(v for v,_ in segments)})
    for value,fill in segments:
        for char in value:
            glyph=glyphs[cmap.get(ord(char),'.notdef')];pen=SVGPathPen(glyphs);glyph.draw(pen);commands=pen.getCommands()
            if commands:ET.SubElement(group,'{'+NS+'}path',{'d':commands,'transform':f'translate({x:.6f} {y:.6f}) scale({scale:.8f} {-scale:.8f})','fill':fill})
            x+=glyph.width*scale+spacing
    index=list(parent).index(text);parent.remove(text);parent.insert(index,group)
for current in [*sorted((ROOT/'public/art').glob('*.svg')),*sorted((ROOT/'public/brand').glob('*.svg'))]:
    tree=ET.parse(current)
    for parent in list(tree.iter()):
        for text in list(parent):
            if text.tag=='{'+NS+'}text':render_text(parent,text)
    tree.write(current,encoding='unicode',xml_declaration=False)
print('SVG text outlined with locally licensed fonts.')
