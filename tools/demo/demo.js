/*
 * subtitlesmith デモ（GitHub Pages）用のブラウザ内モック。
 *
 * 実際のアプリの画面をそのまま静的に配信し、サーバーへの API 呼び出しだけを
 * ここで横取りしてブラウザ内のサンプルデータで応答する。
 *  - 字幕の編集・確認済み・分割・削除・元に戻す は動作する（このタブを閉じるまで保持）
 *  - アップロード・書き出し・カット・言語追加・設定の保存などはサーバーが必要なので実行しない
 */
(function () {
  'use strict';

  var BASE = window.DEMO_BASE || '/subtitlesmith';
  var REPO_URL = window.DEMO_REPO_URL || 'https://github.com/haru-sudo/subtitlesmith';
  var DATA = window.DEMO_DATA || { videos: { videos: [] }, video: {}, segments: {}, load: {} };
  var STORE_KEY = 'subtitlesmith-demo-v1';
  var BLOCKED = 'デモ版ではこの操作は実行できません（実際の環境ではサーバーで処理されます）。';

  // ---- 状態（字幕の編集内容）はタブ内の sessionStorage に保持 ----
  function loadState() {
    try {
      var raw = sessionStorage.getItem(STORE_KEY);
      if (raw) return JSON.parse(raw);
    } catch (e) { /* 使えない環境では毎回初期データ */ }
    return { segments: JSON.parse(JSON.stringify(DATA.segments)), nextId: 1000 };
  }
  var state = loadState();
  function saveState() {
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* 無視 */ }
  }
  function resetState() {
    try { sessionStorage.removeItem(STORE_KEY); } catch (e) { /* 無視 */ }
  }

  function langsOf(vid) { return Object.keys(state.segments[vid] || {}); }
  function segs(vid, lang) {
    var byLang = state.segments[vid] || {};
    return byLang[lang] || byLang[langsOf(vid)[0]] || [];
  }
  function sortSegs(list) { list.sort(function (a, b) { return a.start_ms - b.start_ms; }); }

  // ---- 応答のヘルパー ----
  function json(body, status) {
    return Promise.resolve(new Response(JSON.stringify(body), {
      status: status || 200,
      headers: { 'Content-Type': 'application/json' }
    }));
  }
  function blocked() {
    toast(BLOCKED);
    return json({ error: 'demo', message: BLOCKED }, 422);
  }

  // ---- API の横取り ----
  function handle(method, url, body) {
    var u = new URL(url, location.href);
    var path = u.pathname.indexOf(BASE) === 0 ? u.pathname.slice(BASE.length) : u.pathname;
    var lang = u.searchParams.get('lang') || (body && body.lang) || '';
    var m;

    if (method === 'GET' && path === '/api/videos') return json(DATA.videos);
    if (method === 'GET' && path === '/api/system-load') return json(DATA.load);
    if (method === 'POST' && path === '/api/spawn-worker') return json({ ok: true, pending: 0 });

    if ((m = path.match(/^\/api\/videos\/(\d+)$/)) && method === 'GET') {
      return DATA.video[m[1]] ? json(DATA.video[m[1]]) : json({ error: 'not_found' }, 404);
    }

    if ((m = path.match(/^\/api\/videos\/(\d+)\/segments$/))) {
      var vid = m[1];
      if (!lang || !(state.segments[vid] || {})[lang]) lang = langsOf(vid)[0];
      if (method === 'GET') return json({ lang: lang, segments: segs(vid, lang) });
      if (method === 'POST') return createSegment(vid, lang, body || {});
    }

    if ((m = path.match(/^\/api\/videos\/(\d+)\/segments\/(\d+)$/))) {
      if (method === 'PUT') return updateSegment(m[1], parseInt(m[2], 10), lang, body || {});
      if (method === 'DELETE') return deleteSegment(m[1], parseInt(m[2], 10));
    }

    if ((m = path.match(/^\/api\/videos\/(\d+)\/segments\/(\d+)\/split$/)) && method === 'POST') {
      return splitSegment(m[1], parseInt(m[2], 10), body || {});
    }

    // それ以外の書き込み（書き出し・カット・言語・用語集AI・削除・移動・アップロード・取り込み）はサーバーが必要
    if (method !== 'GET') return blocked();
    return null;
  }

  function updateSegment(vid, sid, lang, b) {
    if (!lang || !(state.segments[vid] || {})[lang]) lang = langsOf(vid)[0];
    var found = null;
    langsOf(vid).forEach(function (l) {
      segs(vid, l).forEach(function (s) {
        if (s.id !== sid) return;
        // 原文と時刻は全言語共通、訳文と確認済みは言語ごと
        if (b.source_text != null) s.source_text = String(b.source_text);
        if (b.start_ms != null) s.start_ms = Math.max(0, parseInt(b.start_ms, 10));
        if (b.end_ms != null) s.end_ms = Math.max(0, parseInt(b.end_ms, 10));
        if (l === lang) {
          if (b.edited_translation != null) s.edited_translation = String(b.edited_translation);
          if (b.is_approved != null) {
            s.is_approved = !!parseInt(b.is_approved, 10);
            s.approved_by = s.is_approved ? (b.approved_by || 'デモユーザー') : null;
          }
          found = s;
        }
      });
      sortSegs(segs(vid, l));
    });
    if (!found) return json({ error: 'not_found' }, 404);
    if (found.end_ms < found.start_ms) return json({ error: 'invalid_timing', message: '終了時刻が開始より前です。' }, 422);
    saveState();
    return json({ ok: true, segment: found });
  }

  function deleteSegment(vid, sid) {
    var translations = {};
    langsOf(vid).forEach(function (l) {
      var list = segs(vid, l);
      for (var i = list.length - 1; i >= 0; i--) {
        if (list[i].id === sid) {
          var s = list[i];
          translations[l] = {
            machine_translation: s.machine_translation, edited_translation: s.edited_translation,
            is_approved: s.is_approved, approved_by: s.approved_by
          };
          list.splice(i, 1);
        }
      }
    });
    saveState();
    return json({ ok: true, translations: translations });
  }

  function createSegment(vid, lang, b) {
    var id = state.nextId++;
    var created = null;
    var tr = b.translations || {};
    langsOf(vid).forEach(function (l) {
      var t = tr[l] || (l === lang ? b : {});
      var s = {
        id: id,
        start_ms: Math.max(0, parseInt(b.start_ms || 0, 10)),
        end_ms: Math.max(0, parseInt(b.end_ms || 0, 10)),
        source_text: String(b.source_text || ''),
        machine_translation: t.machine_translation || null,
        edited_translation: t.edited_translation || null,
        is_approved: !!(t.is_approved && parseInt(t.is_approved, 10)),
        approved_by: t.approved_by || null
      };
      segs(vid, l).push(s);
      sortSegs(segs(vid, l));
      if (l === lang) created = s;
    });
    saveState();
    return json({ ok: true, segment: created }, 201);
  }

  function splitText(text, ratio) {
    text = String(text || '');
    if (!text) return ['', ''];
    var spaced = /\s/.test(text.trim());
    if (spaced) {
      var words = text.trim().split(/\s+/);
      var k = Math.max(1, Math.min(words.length - 1, Math.round(words.length * ratio)));
      return [words.slice(0, k).join(' '), words.slice(k).join(' ')];
    }
    var chars = Array.from(text.replace(/\n/g, ''));
    var c = Math.max(1, Math.min(chars.length - 1, Math.round(chars.length * ratio)));
    return [chars.slice(0, c).join(''), chars.slice(c).join('')];
  }

  function splitSegment(vid, sid, b) {
    var at = parseInt(b.at_ms || 0, 10);
    var base = segs(vid, langsOf(vid)[0]).filter(function (s) { return s.id === sid; })[0];
    if (!base || at <= base.start_ms + 100 || at >= base.end_ms - 100) {
      return json({ error: 'invalid_split', message: '分割位置が範囲外です。動画を分割したい位置まで再生してから押してください。' }, 422);
    }
    var ratio = (at - base.start_ms) / (base.end_ms - base.start_ms);
    var newId = state.nextId++;
    var srcParts = splitText(base.source_text, ratio);
    langsOf(vid).forEach(function (l) {
      var list = segs(vid, l);
      var s = list.filter(function (x) { return x.id === sid; })[0];
      if (!s) return;
      var trParts = splitText(s.edited_translation || s.machine_translation, ratio);
      var second = {
        id: newId, start_ms: at, end_ms: s.end_ms, source_text: srcParts[1],
        machine_translation: trParts[1], edited_translation: trParts[1], is_approved: false, approved_by: null
      };
      s.end_ms = at;
      s.source_text = srcParts[0];
      s.edited_translation = trParts[0];
      s.is_approved = false;
      s.approved_by = null;
      list.push(second);
      sortSegs(list);
    });
    saveState();
    return json({ ok: true });
  }

  // 編集画面は最初の字幕を HTML に埋め込まれた JSON から描くので、
  // editor.js が読む直前に、このタブで編集した内容へ差し替える（build_demo が呼び出しを埋め込む）
  window.__demoPatchInitial = function () {
    var el = document.getElementById('initialData');
    if (!el) return;
    try {
      var d = JSON.parse(el.textContent);
      var vid = String(d.video && d.video.id);
      if (state.segments[vid]) {
        d.segments = segs(vid, langsOf(vid)[0]);
        el.textContent = JSON.stringify(d);
      }
    } catch (e) { /* 差し替えられなければ初期データのまま */ }
  };

  // ---- fetch を差し替え ----
  var realFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    init = init || {};
    var url = typeof input === 'string' ? input : input.url;
    var method = (init.method || (typeof input !== 'string' && input.method) || 'GET').toUpperCase();
    var u = new URL(url, location.href);
    if (u.origin === location.origin && u.pathname.indexOf(BASE + '/') === 0
        && /^\/(api|videos)\//.test(u.pathname.slice(BASE.length))) {
      var body = null;
      if (typeof init.body === 'string') {
        try { body = JSON.parse(init.body); } catch (e) { body = null; }
      }
      var res = handle(method, url, body);
      if (res) return res;
    }
    return realFetch(input, init);
  };

  // アップロード（XHR）はサーバーが必要なので送らない
  var realOpen = XMLHttpRequest.prototype.open;
  var realSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__demoBlocked = String(url).indexOf(BASE + '/videos/chunk') !== -1;
    return realOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (this.__demoBlocked) {
      var xhr = this;
      toast('デモ版では動画をアップロードできません。');
      setTimeout(function () {
        Object.defineProperty(xhr, 'status', { value: 422 });
        Object.defineProperty(xhr, 'readyState', { value: 4 });
        Object.defineProperty(xhr, 'responseText', { value: JSON.stringify({ error: 'demo', message: 'デモ版では動画をアップロードできません。' }) });
        if (typeof xhr.onload === 'function') xhr.onload();
        xhr.dispatchEvent(new Event('load'));
        xhr.dispatchEvent(new Event('loadend'));
      }, 300);
      return;
    }
    return realSend.apply(this, arguments);
  };

  // ---- プレビューの字幕は、編集中の内容からその場で作る ----
  function pad(n, w) { n = String(n); while (n.length < w) n = '0' + n; return n; }
  function ts(ms) {
    return pad(Math.floor(ms / 3600000), 2) + ':' + pad(Math.floor(ms / 60000) % 60, 2) + ':'
      + pad(Math.floor(ms / 1000) % 60, 2) + '.' + pad(ms % 1000, 3);
  }
  function vttFor(vid, lang) {
    var out = ['WEBVTT', ''];
    segs(vid, lang).forEach(function (s, i) {
      var text = s.edited_translation || s.machine_translation || '';
      if (!text) return;
      out.push(String(i + 1), ts(s.start_ms) + ' --> ' + ts(s.end_ms), text, '');
    });
    return out.join('\n');
  }
  var trackDesc = Object.getOwnPropertyDescriptor(HTMLTrackElement.prototype, 'src');
  if (trackDesc && trackDesc.set) {
    Object.defineProperty(HTMLTrackElement.prototype, 'src', {
      configurable: true,
      get: trackDesc.get,
      set: function (v) {
        var m = String(v).match(/\/videos\/(\d+)\/subtitles\.vtt\?(?:.*&)?lang=([A-Za-z-]+)/);
        if (m && state.segments[m[1]]) {
          v = URL.createObjectURL(new Blob([vttFor(m[1], m[2])], { type: 'text/vtt' }));
        }
        trackDesc.set.call(this, v);
      }
    });
  }

  // ---- リンク・フォーム ----
  function mapHref(href) {
    var m;
    var u = new URL(href, location.href);
    if (u.origin !== location.origin || u.pathname.indexOf(BASE) !== 0) return null;
    var p = u.pathname.slice(BASE.length);
    var lang = u.searchParams.get('lang');
    if ((m = p.match(/^\/videos\/(\d+)\/subtitles\.vtt$/))) {
      return BASE + '/videos/' + m[1] + '/subtitles-' + (lang || langsOf(m[1])[0]) + '.vtt';
    }
    if ((m = p.match(/^\/videos\/(\d+)\/segments\.xlsx$/))) {
      return BASE + '/videos/' + m[1] + '/segments-' + (lang || langsOf(m[1])[0]) + '.xlsx';
    }
    if ((m = p.match(/^\/videos\/(\d+)\/source\/download$/))) return BASE + '/storage/videos/demo' + m[1] + '.mp4';
    if (p === '' || p === '/') return document.querySelector('.topbar') ? BASE + '/dashboard/' : null;
    return null;
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var mapped = mapHref(a.getAttribute('href'));
    if (mapped) a.setAttribute('href', mapped);
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    var action = form.getAttribute('action') || '';
    e.preventDefault();
    e.stopImmediatePropagation();
    if (/\/login$/.test(action)) { location.href = BASE + '/dashboard/'; return; }
    if (/\/logout$/.test(action)) { resetState(); location.href = BASE + '/'; return; }
    if (form.id === 'uploadForm') { toast('デモ版では動画をアップロードできません。'); return; }
    toast('デモ版のため保存されません。');
  }, true);

  // 外部ログインのボタンは、デモではそのままダッシュボードへ
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="/auth/"]');
    if (!a) return;
    e.preventDefault();
    location.href = BASE + '/dashboard/';
  }, true);

  // ---- 案内バーと通知 ----
  var toastTimer = null;
  function toast(msg) {
    var el = document.getElementById('demoToast');
    if (!el) return;
    el.textContent = msg;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.createElement('div');
    bar.className = 'demo-bar';
    bar.innerHTML =
      '<span class="demo-bar-tag">DEMO</span>'
      + '<span class="demo-bar-text">これは subtitlesmith のデモです。字幕の編集はこのタブの中だけで動き、アップロード・書き出し・設定の保存はできません。</span>'
      + '<span class="demo-bar-actions">'
      + '<button type="button" class="demo-bar-btn" id="demoReset">サンプルを元に戻す</button>'
      + '<a class="demo-bar-btn" href="' + REPO_URL + '">GitHub</a>'
      + '</span>';
    document.body.appendChild(bar);
    var t = document.createElement('div');
    t.id = 'demoToast';
    t.className = 'demo-toast';
    t.hidden = true;
    document.body.appendChild(t);
    document.getElementById('demoReset').addEventListener('click', function () {
      resetState();
      location.reload();
    });
  });
})();
