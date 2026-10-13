"""Acquire only declared public visual assets; never request accounts or execute JS."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from io import BytesIO
from pathlib import Path
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, urlopen
import hashlib
import json
import re

from PIL import Image

ROOT = Path(__file__).resolve().parents[3]
DEST = ROOT / 'madina-dealer-platform/public/reference'
PUBLIC = json.loads((ROOT / 'planning/evidence/reference-public-design.json').read_text(encoding='utf-8-sig'))
SUMMARY = json.loads((ROOT / 'planning/evidence/supplied-dashboard-source-summary.json').read_text(encoding='utf-8-sig'))
ALLOWED = {'naturalgroupmart.com', 'cdnjs.cloudflare.com', 'fonts.bunny.net', 'fonts.gstatic.com', 'fonts.googleapis.com', 'raw.githubusercontent.com'}
tasks = {}
records = []
previous_path = DEST / 'asset-manifest.json'
previous = {r['source_url']: r for r in json.loads(previous_path.read_text(encoding='utf-8')).get('assets', [])} if previous_path.exists() else {}

def add(url, local, kind, label=None):
    parsed = urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname not in ALLOWED:
        return
    if '/customer-profile/' in parsed.path or parsed.query or '/account/' in parsed.path:
        return
    tasks.setdefault(url, {'source_url': url, 'local_path': local, 'kind': kind, 'label': label})

def fetch(task):
    row = dict(task)
    try:
        old = previous.get(row['source_url'])
        path = (DEST / row['local_path']).resolve()
        if old and old.get('status') == 'downloaded' and path.exists() and hashlib.sha256(path.read_bytes()).hexdigest() == old.get('sha256'):
            return old
        request = Request(row['source_url'], headers={'User-Agent': 'Mozilla/5.0', 'Accept': '*/*'})
        with urlopen(request, timeout=35) as response:
            if urlsplit(response.url).hostname not in ALLOWED:
                raise ValueError('Unexpected redirect host')
            data = response.read()
            mime = response.headers.get_content_type()
            if mime == 'text/html':
                raise ValueError('HTML challenge or error returned instead of asset')
            row.update(status='downloaded', http_status=response.status, content_type=mime,
                       bytes=len(data), sha256=hashlib.sha256(data).hexdigest())
        path = (DEST / row['local_path']).resolve()
        if not path.is_relative_to(DEST.resolve()):
            raise ValueError('Output outside reference asset directory')
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        if mime.startswith('image/'):
            with Image.open(BytesIO(data)) as img:
                row.update(width=img.width, height=img.height, image_format=img.format)
    except Exception as exc:
        row.update(status='failed', error=f'{type(exc).__name__}: {exc}')
    return row

slider = side = product = 0
for item in PUBLIC['images']:
    url = item['url']; extension = Path(urlsplit(url).path).suffix.lower()
    if item['alt'] == 'Slider':
        slider += 1; name = f'slider-{slider:02}{extension}'; kind = 'slider'
    elif item['alt'] == 'Side Banner':
        side += 1; name = f'side-{side:02}{extension}'; kind = 'side-banner'
    elif '/products/thumbnails/' in url:
        product += 1; name = f'products/product-{product:02}{extension}'; kind = 'product-thumbnail'
    elif '/settings/' in url:
        name = 'logo' + extension; kind = 'logo'
    else:
        base = Path(urlsplit(url).path).name
        names = {'GetItOnGooglePlay_Badge_Web_color_English.png': 'google-play.png',
                 '5a902db97f96951c82922874.png': 'app-store.png'}
        name = 'icons/' + names.get(base, base); kind = 'footer-icon'
    add(url, name, kind, item.get('alt'))

for item in SUMMARY['asset_references']:
    url = item['url']
    if not url.startswith('https://') or url in tasks: continue
    if '/settings/' in url and item['tag'] == 'link' and url.endswith('.png'):
        add(url, 'favicon.png', 'favicon')
    elif '/settings/' in url and item['tag'] == 'img':
        add(url, 'promotion.png', 'promotion')

for url in PUBLIC['styles']:
    name = 'source/fontawesome-original.css' if 'font-awesome' in url else 'source/reference-app-original.css'
    add(url, name, 'original-css')

# The reference CSS names Instrument Sans but declares no downloadable font URL.
# Obtain that named family from its official Google Fonts repository separately;
# this is matching-family acquisition, not proof of a font loaded by the reference.
font_base = 'https://raw.githubusercontent.com/google/fonts/main/ofl/instrumentsans/'
add(font_base + 'InstrumentSans%5Bwdth,wght%5D.ttf', 'fonts/instrument-sans-variable.ttf', 'matching-family-font', 'Instrument Sans normal, official Google Fonts; not reference runtime evidence')
add(font_base + 'InstrumentSans-Italic%5Bwdth,wght%5D.ttf', 'fonts/instrument-sans-variable-italic.ttf', 'matching-family-font', 'Instrument Sans italic, official Google Fonts; not reference runtime evidence')
add(font_base + 'OFL.txt', 'fonts/InstrumentSans-OFL.txt', 'font-license', 'Instrument Sans SIL Open Font License')

DEST.mkdir(parents=True, exist_ok=True)
with ThreadPoolExecutor(max_workers=6) as executor:
    records.extend(executor.map(fetch, list(tasks.values())))

# Discover only stylesheet-declared font dependencies, not scripts or arbitrary routes.
css_rows = [r for r in records if r['kind'] == 'original-css' and r['status'] == 'downloaded']
font_tasks = []
for row in css_rows:
    css = (DEST / row['local_path']).read_text(encoding='utf-8')
    for ref in re.findall(r'url\(\s*[\"\x27]?([^\)\"\x27]+)', css):
        ref = ref.strip()
        if ref.startswith('data:'): continue
        url = urljoin(row['source_url'], ref)
        suffix = Path(urlsplit(url).path).suffix.lower()
        if suffix not in {'.woff', '.woff2', '.ttf', '.otf', '.eot'}: continue
        if url not in tasks:
            add(url, 'fonts/' + Path(urlsplit(url).path).name, 'font')
            if url in tasks: font_tasks.append(tasks[url])
with ThreadPoolExecutor(max_workers=6) as executor:
    records.extend(executor.map(fetch, font_tasks))

# A separate optional stylesheet uses local paths; original CSS remains unchanged/inert.
for row in css_rows:
    if 'fontawesome' not in row['local_path']: continue
    css = (DEST / row['local_path']).read_text(encoding='utf-8')
    def local_font(match):
        ref = match.group(1).strip(' \"\x27')
        url = urljoin(row['source_url'], ref)
        record = next((r for r in records if r['source_url'] == url and r['status'] == 'downloaded'), None)
        return 'url(/reference/' + record['local_path'] + ')' if record else match.group(0)
    rewritten = re.sub(r'url\(([^)]+)\)', local_font, css)
    (DEST / 'fontawesome-local.css').write_text(rewritten, encoding='utf-8')

(DEST / 'instrument-sans-local.css').write_text(
    "/* Matching reference-named family from official Google Fonts; see manifest provenance. */\n"
    "@font-face{font-family:'Instrument Sans';font-style:normal;font-weight:400 700;font-stretch:75% 100%;font-display:swap;src:url('/reference/fonts/instrument-sans-variable.ttf') format('truetype');}\n"
    "@font-face{font-family:'Instrument Sans';font-style:italic;font-weight:400 700;font-stretch:75% 100%;font-display:swap;src:url('/reference/fonts/instrument-sans-variable-italic.ttf') format('truetype');}\n",
    encoding='utf-8')
derived_files = []
for name in ('fontawesome-local.css', 'instrument-sans-local.css'):
    path = DEST / name
    if path.exists():
        data = path.read_bytes()
        derived_files.append({'local_path': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'status': 'generated'})

manifest = {'acquired_at': datetime.now(timezone.utc).isoformat(),
            'scope': 'User-authorized reference visual copy. Public GET assets only, no cookies, private media, tokens or JavaScript execution.',
            'css_usage': 'source/* original CSS preserved for inert inspection. fontawesome-local.css references locally downloaded fonts.',
            'instrument_sans_provenance': 'Reference CSS font-family names Instrument Sans but contains no @font-face/import/URL for it. Matching family acquired from official Google Fonts; reference-loaded font remains unverified.',
            'assets': records,
            'derived_files': derived_files,
            'summary': {'downloaded': sum(r['status']=='downloaded' for r in records),
                        'failed': sum(r['status']=='failed' for r in records)}}
(DEST / 'asset-manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps(manifest['summary']))
for row in records:
    print(row['status'], row['local_path'], row.get('bytes', ''), row.get('error', ''))
