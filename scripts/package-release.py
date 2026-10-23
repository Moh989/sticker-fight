"""Package source/build assets; exclude all runtime data and credentials."""
from pathlib import Path
import zipfile,hashlib,json
ROOT=Path(__file__).resolve().parent.parent
TARGET=ROOT/'deliverables/STICKER-FIGHT-project.zip'
folders=['app','bootstrap','config','database','lang','resources','routes','tests','public','scripts','docs','samples','sss']
files=[]
for folder in folders:
 for f in (ROOT/folder).rglob('*'):
  if not f.is_file():continue
  rel=f.relative_to(ROOT)
  if folder=='bootstrap' and rel.parts[1]=='cache' and f.name!='.gitignore':continue
  if folder=='database' and '.sqlite' in f.name:continue
  if f.name in ['hot','.DS_Store'] or '__pycache__' in rel.parts:continue
  files.append(f)
files += [ROOT/name for name in ['artisan','composer.json','composer.lock','package.json','package-lock.json','vite.config.js','phpunit.xml','README.md','AGENTS.md','CLAUDE.md','.env.example','.editorconfig','.gitattributes','.gitignore','.npmrc'] if (ROOT/name).exists()]
files += list((ROOT/'storage').rglob('.gitignore'))
files += [ROOT/'deliverables/STICKER-FIGHT-brand-kit.zip',ROOT/'deliverables/storefront-desktop.png',ROOT/'deliverables/storefront-mobile.png']
with zipfile.ZipFile(TARGET,'w',zipfile.ZIP_DEFLATED,compresslevel=8) as z:
 for f in sorted(set(files)):z.write(f,'STICKER-FIGHT/'+str(f.relative_to(ROOT)))
# Read actual local secrets without emitting them; confirm none entered the release.
secrets=[]
for envfile in ['.env','.env.qa']:
 if not (ROOT/envfile).exists():continue
 for line in (ROOT/envfile).read_text().splitlines():
  if line.startswith(('APP_KEY=','DB_PASSWORD=','BACKUP_PASSWORD=')):
   value=line.split('=',1)[1].strip().strip('"')
   if len(value)>12:secrets.append(value.encode())
for line in (ROOT/'.runtime/admin-access.txt').read_text().splitlines() if (ROOT/'.runtime/admin-access.txt').exists() else []:
 if line.startswith('Password: '):secrets.append(line.split(': ',1)[1].encode())
with zipfile.ZipFile(TARGET) as z:
 names=z.namelist()
 assert not any('/.runtime/' in n or '/node_modules/' in n or '/vendor/' in n for n in names)
 assert not any(n.endswith('/.env') or n.endswith('/.env.qa') or n.endswith('.sqlite') for n in names)
 assert 'STICKER-FIGHT/lang/ar.json' in names and 'STICKER-FIGHT/lang/en.json' in names
 for n in names:
  content=z.read(n)
  assert not any(secret in content for secret in secrets),f'Private credential detected in {n}'
 assert z.testzip() is None
checksum=hashlib.sha256(TARGET.read_bytes()).hexdigest()
(ROOT/'deliverables/SHA256SUMS.txt').write_text('\n'.join(hashlib.sha256(f.read_bytes()).hexdigest()+'  '+f.name for f in sorted((ROOT/'deliverables').glob('*.zip')))+'\n')
print(json.dumps({'archive':str(TARGET),'files':len(names),'bytes':TARGET.stat().st_size,'sha256':checksum,'credentials_excluded':True}))
