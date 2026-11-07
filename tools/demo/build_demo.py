#!/usr/bin/env python3
"""Capture the running subtitlesmith app (base_path=/subtitlesmith) into a static GitHub Pages demo under docs/."""
import http.cookiejar
import json
import os
import re
import shutil
import sys
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DOCS = os.path.join(ROOT, 'docs')
STORAGE = sys.argv[1]
ORIGIN = os.environ.get('DEMO_ORIGIN', 'http://127.0.0.1:8099')
BASE = os.environ.get('DEMO_BASE', '/subtitlesmith2')
REPO_URL = os.environ.get('DEMO_REPO_URL', 'https://github.com/haru-sudo/subtitlesmith2')
VIDEOS = {1: ['ja', 'zh-Hans'], 2: ['ja']}
THUMBS = {1: 'demo1.jpg', 2: 'demo2.jpg'}


def opener(login):
    cj = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    if login:
        data = urllib.parse.urlencode({'email': 'demo@example.com', 'password': 'pw'}).encode()
        op.open(urllib.request.Request(ORIGIN + BASE + '/login', data=data, headers={'Origin': ORIGIN}))
    return op


def get(op, path, accept=None):
    req = urllib.request.Request(ORIGIN + BASE + path, headers={'Accept': accept} if accept else {})
    with op.open(req) as r:
        return r.read()


def write(rel, data):
    p = os.path.join(DOCS, rel)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'wb') as f:
        f.write(data if isinstance(data, bytes) else data.encode('utf-8'))


def rewrite(html, logged_in):
    b = BASE
    if logged_in:
        html = html.replace(f'href="{b}/"', f'href="{b}/dashboard/"')
    html = re.sub(rf'href="{b}/\?folder=(\d+)"', rf'href="{b}/dashboard/folder-\1/"', html)
    for vid, thumb in THUMBS.items():
        html = html.replace(f'{b}/videos/{vid}/thumbnail', f'{b}/storage/thumbs/{thumb}')
    for vid, langs in VIDEOS.items():
        html = html.replace(f'src="{b}/videos/{vid}/subtitles.vtt"', f'src="{b}/videos/{vid}/subtitles-{langs[0]}.vtt"')
        html = re.sub(rf'{b}/videos/{vid}/subtitles\.vtt\?dl=1&amp;lang=([A-Za-z-]+)', rf'{b}/videos/{vid}/subtitles-\1.vtt', html)
        html = re.sub(rf'{b}/videos/{vid}/segments\.xlsx\?lang=([A-Za-z-]+)', rf'{b}/videos/{vid}/segments-\1.xlsx', html)
        html = html.replace(f'{b}/videos/{vid}/source/download', f'{b}/storage/videos/demo{vid}.mp4')
        html = html.replace(f'href="{b}/videos/{vid}"', f'href="{b}/videos/{vid}/"')
    html = html.replace(f'href="{b}/health?refresh=1"', f'href="{b}/health/"')
    for page in ['settings', 'news', 'memory', 'health', 'glossary', 'privacy', 'login']:
        html = html.replace(f'href="{b}/{page}"', f'href="{b}/{page}/"')
    # ビルド環境のパスや URL は例示用の値に置き換える
    html = re.sub(r'/tmp/tmp\.[A-Za-z0-9]+', '/var/www/subtitlesmith', html)
    html = html.replace(ORIGIN, 'https://example.com')
    # キャッシュ用の ?v= は静的配信では不要
    html = re.sub(r'(\.(?:css|js|png))\?v=\d+', r'\1', html)
    inject = (f'<link rel="stylesheet" href="{b}/assets/css/demo.css">\n'
              f'    <script src="{b}/assets/js/demo-data.js"></script>\n'
              f'    <script src="{b}/assets/js/demo.js"></script>\n    ')
    html = html.replace('<link rel="stylesheet"', inject + '<link rel="stylesheet"', 1)
    html = html.replace('<meta name="robots" content="noindex, nofollow">', '<meta name="robots" content="noindex">')
    # 編集画面: 埋め込みの初期データを、ブラウザ内で編集した内容に差し替える
    html = re.sub(r'(<script type="application/json" id="initialData">.*?</script>)',
                  r'\1\n<script>window.__demoPatchInitial && window.__demoPatchInitial();</script>', html, count=1, flags=re.S)
    return html


def main():
    if os.path.isdir(DOCS):
        shutil.rmtree(DOCS)
    os.makedirs(DOCS)
    anon = opener(False)
    user = opener(True)

    pages = {
        'index.html': (anon, '/', False),
        'login/index.html': (anon, '/login', False),
        'privacy/index.html': (anon, '/privacy', False),
        'dashboard/index.html': (user, '/', True),
        'dashboard/folder-1/index.html': (user, '/?folder=1', True),
        'dashboard/folder-2/index.html': (user, '/?folder=2', True),
        'glossary/index.html': (user, '/glossary', True),
        'memory/index.html': (user, '/memory', True),
        'settings/index.html': (user, '/settings', True),
        'news/index.html': (user, '/news', True),
        'health/index.html': (user, '/health', True),
    }
    for vid in VIDEOS:
        pages[f'videos/{vid}/index.html'] = (user, f'/videos/{vid}', True)
    for rel, (op, path, li) in pages.items():
        write(rel, rewrite(get(op, path).decode('utf-8'), li))

    data = {'videos': json.loads(get(user, '/api/videos', 'application/json')),
            'load': json.loads(get(user, '/api/system-load', 'application/json')),
            'video': {}, 'segments': {}}
    for vid, langs in VIDEOS.items():
        data['video'][vid] = json.loads(get(user, f'/api/videos/{vid}', 'application/json'))
        data['segments'][vid] = {}
        for lang in langs:
            seg = json.loads(get(user, f'/api/videos/{vid}/segments?lang={lang}', 'application/json'))
            data['segments'][vid][lang] = seg['segments']
            write(f'videos/{vid}/subtitles-{lang}.vtt', get(user, f'/videos/{vid}/subtitles.vtt?lang={lang}'))
            write(f'videos/{vid}/segments-{lang}.xlsx', get(user, f'/videos/{vid}/segments.xlsx?lang={lang}'))
    write('assets/js/demo-data.js',
          '/* デモ用のサンプルデータ（build_demo で実際のアプリから取得） */\n'
          + 'window.DEMO_BASE = ' + json.dumps(BASE) + ';\n'
          + 'window.DEMO_REPO_URL = ' + json.dumps(REPO_URL) + ';\n'
          + 'window.DEMO_DATA = '
          + json.dumps(data, ensure_ascii=False, indent=1) + ';\n')

    for d in ['css', 'js']:
        os.makedirs(os.path.join(DOCS, 'assets', d), exist_ok=True)
        for f in os.listdir(os.path.join(ROOT, 'assets', d)):
            shutil.copy(os.path.join(ROOT, 'assets', d, f), os.path.join(DOCS, 'assets', d, f))
    for f in os.listdir(os.path.join(ROOT, 'assets')):
        if f.endswith('.png'):
            shutil.copy(os.path.join(ROOT, 'assets', f), os.path.join(DOCS, 'assets', f))
    for sub in ['videos', 'thumbs']:
        os.makedirs(os.path.join(DOCS, 'storage', sub), exist_ok=True)
        for f in sorted(x for x in os.listdir(os.path.join(STORAGE, sub)) if not x.startswith('.')):
            shutil.copy(os.path.join(STORAGE, sub, f), os.path.join(DOCS, 'storage', sub, f))
    here = os.path.dirname(os.path.abspath(__file__))
    shutil.copy(os.path.join(here, 'demo.js'), os.path.join(DOCS, 'assets', 'js', 'demo.js'))
    shutil.copy(os.path.join(here, 'demo.css'), os.path.join(DOCS, 'assets', 'css', 'demo.css'))
    write('.nojekyll', '')
    print('built', DOCS)


if __name__ == '__main__':
    main()
