/* 編集画面: 字幕の編集（セグメントごとの保存ボタン）・タイミング調整・書き出し */
(function () {
  'use strict';

  const VIDEO_ID = window.APP_VIDEO_ID;
  var BASE = window.APP_BASE || '';
  const PROCESSING = ['uploaded', 'extracting', 'transcribing', 'translating'];
  const STATUS_LABELS = (window.APP_COMMON && window.APP_COMMON.STATUS_LABELS) || {};
  const ICONS = (window.APP_COMMON && window.APP_COMMON.ICONS) || {};

  const data = JSON.parse(document.getElementById('initialData').textContent);
  const player = document.getElementById('player');
  const list = document.getElementById('segmentList');
  const editorMain = document.getElementById('editorMain');
  const processingPanel = document.getElementById('processingPanel');
  const processingText = document.getElementById('processingText');
  const processingSub = document.getElementById('processingSub');
  const statusBadge = document.getElementById('statusBadge');
  const saveIndicator = document.getElementById('saveIndicator');
  const exportBtn = document.getElementById('exportBtn');
  const segCount = document.getElementById('segCount');
  // 進行バー・負荷モニタ
  const progressWrap = document.getElementById('progressWrap');
  const progressBar = document.getElementById('progressBar');
  const progressLabel = document.getElementById('progressLabel');
  const loadWrap = document.getElementById('loadWrap');
  const cpuBar = document.getElementById('cpuBar');
  const cpuVal = document.getElementById('cpuVal');
  const memBar = document.getElementById('memBar');
  const memVal = document.getElementById('memVal');
  const loadExtra = document.getElementById('loadExtra');
  const procStatus = document.getElementById('procStatus');
  const loadTitle = document.getElementById('loadTitle');
  // ローカル処理フラグ（負荷パネルの表示判定）
  const LOCAL_TRANSCRIBE = !!window.APP_LOCAL_TRANSCRIBE;
  const LOCAL_TRANSLATE = !!window.APP_LOCAL_TRANSLATE;
  let currentStatus = '';

  let pollTimer = null;

  // ---- キーボードショートカット用: いま操作中の行 ----
  let currentRow = null;
  function setCurrentRow(row) {
    if (currentRow === row) return;
    if (currentRow) currentRow.classList.remove('current');
    currentRow = row;
    if (currentRow) currentRow.classList.add('current');
  }
  const IS_MAC = /Mac|iPhone|iPad|iPod/.test((navigator.platform || navigator.userAgent || ''));
  const MOD = IS_MAC ? '\u2318' : 'Ctrl';

  // ---- \u5b57\u5e55\u306e\u51fa\u529b\u8a00\u8a9e\uff08\u540c\u3058\u52d5\u753b\u3067\u8907\u6570\u8a00\u8a9e\u3092\u6301\u3061\u3001\u30bf\u30d6\u3067\u5207\u308a\u66ff\u3048\u308b\uff09 ----
  var LANGS = Array.isArray(window.APP_VIDEO_LANGS) ? window.APP_VIDEO_LANGS.slice() : [];
  var curLang = window.APP_TARGET_LANG || 'ja';
  var TARGET_LABEL = window.APP_TARGET_LANG_LABEL || '\u65e5\u672c\u8a9e';
  var TARGET_TAG = window.APP_TARGET_LANG_TAG || 'ja';

  function langInfo(code) {
    for (var i = 0; i < LANGS.length; i++) {
      if (LANGS[i].lang === code) return LANGS[i];
    }
    return null;
  }
  /* \u8868\u793a\u4e2d\u306e\u8a00\u8a9e\u3092\u5207\u308a\u66ff\u3048\u305f\u3068\u304d\u306b\u3001\u30e9\u30d9\u30eb\u30fb\u5b57\u5e55\u30c8\u30e9\u30c3\u30af\u30fbDL\u30ea\u30f3\u30af\u3092\u8ffd\u5f93\u3055\u305b\u308b */
  function applyLangMeta(code) {
    var info = langInfo(code);
    curLang = code;
    TARGET_LABEL = (info && info.label) || code;
    TARGET_TAG = (info && info.tag) || code;
    var vtt = document.getElementById('vttDlBtn');
    if (vtt) vtt.href = BASE + '/videos/' + VIDEO_ID + '/subtitles.vtt?dl=1&lang=' + encodeURIComponent(code);
    var xlsx = document.getElementById('xlsxDlBtn');
    if (xlsx) xlsx.href = BASE + '/videos/' + VIDEO_ID + '/segments.xlsx?lang=' + encodeURIComponent(code);
    var tabs = document.getElementById('langTabs');
    if (tabs) {
      tabs.dataset.current = code;
      Array.prototype.forEach.call(tabs.querySelectorAll('.lang-tab'), function (t) {
        t.classList.toggle('active', t.dataset.lang === code);
      });
      var del = document.getElementById('removeLangBtn');
      if (del) del.hidden = !(LANGS.length > 1 && info && !info.primary);
    }
    // \u66f8\u304d\u51fa\u3057\u30dc\u30bf\u30f3\u306f\u8a00\u8a9e\u3054\u3068\u306b\u72b6\u614b\u304c\u9055\u3046
    if (info) {
      setExportButtonState(!info.has_export || info.export_stale);
      if (exportBtn) exportBtn.dataset.hasExport = info.has_export ? '1' : '0';
    }
    // URL\u306b\u3082\u8a00\u8a9e\u3092\u6b8b\u3059\uff08\u518d\u8aad\u307f\u8fbc\u307f\u30fb\u5171\u6709\u3067\u3082\u540c\u3058\u8a00\u8a9e\u304c\u958b\u304f\uff09
    try {
      var u = new URL(window.location.href);
      u.searchParams.set('lang', code);
      window.history.replaceState(null, '', u.toString());
    } catch (e) {}
  }
  // \u713c\u304d\u8fbc\u307f\u6642\u306b\u81ea\u52d5\u6539\u884c\u3055\u308c\u308b1\u884c\u306e\u4e0a\u9650\uff08\u5168\u89d2\u63db\u7b97\uff09\u3002\u30b5\u30fc\u30d0\u30fc\u5074\u306e\u8a08\u7b97\u3068\u540c\u3058\u5024\u3002
  const MAX_LINE_CHARS = Math.max(1, parseInt(window.APP_MAX_LINE_CHARS, 10) || 20);

  /* \u6587\u5b57\u306e\u8868\u793a\u5e45\uff08\u5168\u89d2=1.0 / \u534a\u89d2=0.5\uff09\u3002PHP\u5074 Subtitles::charWidth \u3068\u540c\u3058\u57fa\u6e96\u3002 */
  function charWidth(ch) {
    const o = ch.codePointAt(0);
    if (o <= 0x00FF || (o >= 0xFF61 && o <= 0xFF9F)) return 0.5;
    return 1.0;
  }
  function textWidth(s) {
    let w = 0;
    for (const ch of String(s)) w += charWidth(ch);
    return w;
  }
  /* \u4e0a\u9650\u3092\u8d85\u3048\u3066\u3044\u308b\u884c\u3092\u8fd4\u3059 [{no, width}] \uff08\u884c\u756a\u53f7\u306f1\u59cb\u307e\u308a\uff09 */
  function overLongLines(text) {
    const out = [];
    String(text).split(/\r\n|\r|\n/).forEach(function (line, i) {
      const w = textWidth(line);
      if (w > MAX_LINE_CHARS) out.push({ no: i + 1, width: w });
    });
    return out;
  }
  // 字幕の最低表示時間（秒）[2行以上, 1行] と、延長時に次の字幕との間に空けるすき間(ms)。
  // サーバー側 Subtitles::timedCues と同じ規則で「届かない字幕」に警告を出す。
  const MIN_DISPLAY_SEC = (function () {
    const v = Array.isArray(window.APP_MIN_DISPLAY_SEC) ? window.APP_MIN_DISPLAY_SEC : [];
    const n = function (x, d) { x = parseFloat(x); return isFinite(x) && x >= 0 ? x : d; };
    return { multi: n(v[0], 5), single: n(v[1], 2.5) };
  })();
  const MIN_GAP_MS = Math.max(0, parseInt(window.APP_MIN_GAP_MS, 10) || 0);

  /* 焼き込み時の行数（手動改行＋自動改行。空行は数えない）。PHP側 Subtitles::lineCount と同じ計算。 */
  function displayLineCount(text) {
    const max = Math.max(6, MAX_LINE_CHARS);
    let n = 0;
    String(text).split(/\r\n|\r|\n/).forEach(function (line) {
      line = line.trim();
      if (line === '') return;
      n += Math.max(1, Math.ceil(textWidth(line) / max));
    });
    return Math.max(1, n);
  }
  /* \u5e45\u306e\u8868\u793a\uff0820 / 20.5 \u306e\u3088\u3046\u306b\u3001\u7aef\u6570\u304c\u3042\u308b\u3068\u304d\u3060\u3051\u5c0f\u6570\u3092\u51fa\u3059\uff09 */
  function fmtWidth(w) { return (Math.round(w * 10) / 10).toString(); }

  // ---- 時刻ヘルパ ----
  function msToInput(ms) {
    const t = Math.max(0, ms | 0);
    const m = Math.floor(t / 60000);
    const s = Math.floor((t % 60000) / 1000);
    const mm = t % 1000;
    return pad(m, 2) + ':' + pad(s, 2) + '.' + pad(mm, 3);
  }
  function inputToMs(str) {
    const m = String(str).trim().match(/^(\d+):(\d{1,2})(?:\.(\d{1,3}))?$/);
    if (!m) return null;
    const milli = m[3] ? parseInt((m[3] + '000').slice(0, 3), 10) : 0;
    return (+m[1]) * 60000 + (+m[2]) * 1000 + milli;
  }
  function pad(n, w) { return String(n).padStart(w, '0'); }

  // ---- 保存状態（セグメントごとの保存ボタン方式） ----
  function dirtyRows() { return document.querySelectorAll('.seg.dirty'); }
  function setRowDirty(row, dirty) {
    row.classList.toggle('dirty', dirty);
    var btn = row.querySelector('.seg-save');
    if (btn) {
      btn.disabled = !dirty;
      btn.classList.toggle('is-dirty', dirty);
      btn.textContent = '保存';
      btn.title = dirty ? 'この行の変更を保存' : '変更はありません';
    }
  }
  function markDirty(row) { setRowDirty(row, true); }
  function pendingSave() { return dirtyRows().length > 0; }

  function debounce(fn, wait) {
    let t;
    return function () {
      const args = arguments, ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  // ---- API ----
  function putSegment(id, payload) {
    payload.lang = curLang;
    return fetch(BASE + '/api/videos/' + VIDEO_ID + '/segments/' + id, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      if (!r.ok) throw new Error('save failed');
      return r.json();
    });
  }

  // 指定行を保存（編集訳・原文・タイミング・確認済みをまとめてPUT）
  function saveRow(row) {
    var id = row.dataset.id;
    var cb = row.querySelector('.seg-approve');
    var payload = {
      edited_translation: row.querySelector('.seg-ja').value,
      source_text: row.querySelector('.seg-source').value,
      // 確認済みの行は編集後も「確定訳」として翻訳メモリへ反映させるため状態を明示送信
      is_approved: (cb && cb.checked) ? 1 : 0
    };
    var sMs = inputToMs(row.querySelector('.seg-start').value);
    var eMs = inputToMs(row.querySelector('.seg-end').value);
    if (sMs !== null && eMs !== null && eMs >= sMs) { payload.start_ms = sMs; payload.end_ms = eMs; }
    var btn = row.querySelector('.seg-save');
    if (btn) { btn.disabled = true; btn.textContent = '保存中…'; }
    if (saveIndicator) saveIndicator.textContent = '保存中…';
    return putSegment(id, payload).then(function (res) {
      setRowDirty(row, false);
      var approverEl = row.querySelector('.approver');
      if (approverEl && res && res.segment) {
        var by = res.segment.approved_by;
        approverEl.textContent = by ? ('済 ' + by) : '';
        approverEl.title = by ? ('確認済: ' + by) : '';
      }
      if (saveIndicator) { saveIndicator.textContent = '保存しました'; setTimeout(function () { if (!dirtyRows().length) saveIndicator.textContent = ''; }, 1500); }
      reloadSubs();
      // 字幕を変更したので、既存の焼き込み動画は「古い」→ 次回は作り直しが必要
      if (typeof setExportButtonState === 'function') setExportButtonState(true);
      return res;
    }).catch(function () {
      setRowDirty(row, true);
      if (btn) btn.textContent = '保存';
      if (saveIndicator) saveIndicator.textContent = '保存に失敗しました';
    });
  }

  // 変更のある行をすべて保存（書き出し/分割/用語集/離脱前などに使う）
  function saveAllDirty() {
    var rows = Array.prototype.slice.call(dirtyRows());
    if (!rows.length) return Promise.resolve();
    if (saveIndicator) saveIndicator.textContent = '保存中…';
    return Promise.all(rows.map(saveRow));
  }

  // ---- 削除の取り消し（⌘Z / Ctrl+Z）用の履歴 ----
  // 削除は確認ダイアログを出さず即実行し、取り消せるようにここへ積む（後入れ先出し）。
  var undoStack = [];
  const UNDO_MAX = 50;

  function updateSegCount() {
    if (segCount) segCount.textContent = list.children.length + ' セグメント';
  }
  function flashIndicator(msg, ms) {
    if (!saveIndicator) return;
    saveIndicator.textContent = msg;
    setTimeout(function () {
      if (saveIndicator.textContent === msg && !dirtyRows().length) saveIndicator.textContent = '';
    }, ms || 1500);
  }

  // 1行を削除（削除ボタンとショートカットの共通処理）。確認ダイアログは出さない。
  function deleteSegmentRow(row) {
    if (!row) return;
    var id = row.dataset.id;
    if (!id) return;
    // 取り消しに備えて、いま画面にある内容（未保存の編集も含む）を控えておく
    var cb = row.querySelector('.seg-approve');
    var approverEl = row.querySelector('.approver');
    var snapshot = {
      lang: curLang,
      start_ms: parseInt(row.dataset.start, 10) || 0,
      end_ms: parseInt(row.dataset.end, 10) || 0,
      source_text: row.querySelector('.seg-source').value,
      edited_translation: row.querySelector('.seg-ja').value,
      machine_translation: row.dataset.machine || null,
      is_approved: (cb && cb.checked) ? 1 : 0,
      approved_by: (approverEl && approverEl.dataset.by) || ''
    };
    return fetch(BASE + '/api/videos/' + VIDEO_ID + '/segments/' + id, {
      method: 'DELETE', headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      if (!r.ok) { flashIndicator('削除に失敗しました', 2500); return r.json().then(function () {}); }
      // 他の言語の訳も一緒に消えるので、復元できるようサーバーの控えを受け取る
      return r.json().then(function (j) {
        if (j && j.translations) {
          snapshot.translations = j.translations;
          // 表示中の言語だけは、画面上の未保存の編集を優先して戻す
          if (snapshot.translations[curLang]) {
            snapshot.translations[curLang].edited_translation = snapshot.edited_translation;
            snapshot.translations[curLang].is_approved = snapshot.is_approved;
          }
        }
      }).catch(function () {});
    }).then(function () {
      if (!document.body.contains(row)) return;
      if (currentRow === row) currentRow = null;
      if (activeRow === row) activeRow = null;
      row.remove();
      undoStack.push(snapshot);
      if (undoStack.length > UNDO_MAX) undoStack.shift();
      reloadSubs();
      updateSegCount();
      flashIndicator('1件削除しました（' + MOD + '+Z で元に戻せます）', 4000);
      // 字幕が変わったので、既存の焼き込み動画は作り直しが必要
      setExportButtonState(true);
    }).catch(function () { flashIndicator('削除に失敗しました', 2500); });
  }

  // 直前の削除を取り消す（サーバーに同じ内容で作り直す。IDは新しくなる）
  function undoDelete() {
    var snap = undoStack.pop();
    if (!snap) { flashIndicator('元に戻せる削除はありません'); return; }
    flashIndicator('元に戻しています…', 3000);
    if (!snap.lang) snap.lang = curLang;   // 削除したときの言語で戻す
    return fetch(BASE + '/api/videos/' + VIDEO_ID + '/segments', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(snap)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j || !res.j.segment) {
          undoStack.push(snap);   // 失敗したので、もう一度取り消せるように戻す
          flashIndicator('元に戻せませんでした', 2500);
          return;
        }
        var seg = res.j.segment;
        if (snap.lang !== curLang) {
          // 別の言語を表示中に戻した場合は、その言語の内容で表示し直す
          var t = (snap.translations && snap.translations[curLang]) || {};
          seg = Object.assign({}, seg, {
            machine_translation: t.machine_translation || null,
            edited_translation: t.edited_translation || null,
            is_approved: !!t.is_approved,
            approved_by: t.approved_by || null
          });
        }
        var row = buildRow(seg);
        insertRowInOrder(row);
        reloadSubs();
        updateSegCount();
        setCurrentRow(row);
        row.classList.add('restored');
        setTimeout(function () { row.classList.remove('restored'); }, 1200);
        if (row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
        flashIndicator('削除を元に戻しました');
        setExportButtonState(true);
      })
      .catch(function () {
        undoStack.push(snap);
        flashIndicator('元に戻せませんでした', 2500);
      });
  }

  // 開始時刻の順になる位置へ行を差し込む
  function insertRowInOrder(row) {
    var start = parseInt(row.dataset.start, 10) || 0;
    var rows = list.children;
    for (var i = 0; i < rows.length; i++) {
      if ((parseInt(rows[i].dataset.start, 10) || 0) > start) {
        list.insertBefore(row, rows[i]);
        return;
      }
    }
    list.appendChild(row);
  }

  // ---- 字幕プレビュー(VTT)再読込 ----
  const reloadSubs = debounce(function () {
    const old = document.getElementById('subTrack');
    if (!old) return;
    const track = document.createElement('track');
    track.id = 'subTrack';
    track.kind = 'subtitles';
    track.srclang = TARGET_TAG;
    track.label = TARGET_LABEL;
    track.default = true;
    track.src = BASE + '/videos/' + VIDEO_ID + '/subtitles.vtt?lang=' + encodeURIComponent(curLang) + '&ts=' + Date.now();
    old.replaceWith(track);
    setTimeout(function () {
      if (player.textTracks && player.textTracks[0]) player.textTracks[0].mode = 'showing';
    }, 60);
  }, 600);

  // ---- 行の描画 ----
  function renderRows(segments) {
    list.innerHTML = '';
    segments.forEach(function (seg) {
      list.appendChild(buildRow(seg));
    });
    if (segCount) segCount.textContent = segments.length + ' セグメント';
  }

  function buildRow(seg) {
    const row = document.createElement('div');
    row.className = 'seg';
    row.dataset.id = seg.id;
    row.dataset.start = seg.start_ms;
    row.dataset.end = seg.end_ms;
    // 削除を取り消すときに元の機械翻訳も戻せるよう保持しておく
    if (seg.machine_translation != null) row.dataset.machine = seg.machine_translation;

    // 時刻列
    const timeCol = document.createElement('div');
    timeCol.className = 'seg-time';
    const playBtn = document.createElement('button');
    playBtn.className = 'icon-btn seg-play';
    playBtn.type = 'button';
    playBtn.title = 'ここから再生';
    playBtn.setAttribute('aria-label', 'ここから再生');
    playBtn.innerHTML = ICONS.play || '再生';
    const startIn = document.createElement('input');
    startIn.className = 'time-input seg-start';
    startIn.value = msToInput(seg.start_ms);
    const arrow = document.createElement('span');
    arrow.className = 'time-arrow';
    arrow.textContent = '→';
    const endIn = document.createElement('input');
    endIn.className = 'time-input seg-end';
    endIn.value = msToInput(seg.end_ms);
    timeCol.append(playBtn, startIn, arrow, endIn);

    // テキスト列
    const textCol = document.createElement('div');
    textCol.className = 'seg-texts';
    const src = document.createElement('textarea');
    src.className = 'seg-source';
    src.rows = 1;
    src.spellcheck = false;
    src.value = seg.source_text || '';
    src.placeholder = '原文';
    const ja = document.createElement('textarea');
    ja.className = 'seg-ja';
    ja.rows = 2;
    ja.value = (seg.edited_translation != null && seg.edited_translation !== '')
      ? seg.edited_translation
      : (seg.machine_translation || '');
    ja.placeholder = TARGET_LABEL + '訳';
    // 焼き込み時に自動改行されてしまう長さを超えたら、この行に警告を出す
    const warn = document.createElement('div');
    warn.className = 'seg-warn';
    warn.hidden = true;
    // 最低表示時間に届かない（次の字幕が始まってしまう）ときの警告
    const durWarn = document.createElement('div');
    durWarn.className = 'seg-warn seg-dur-warn';
    durWarn.hidden = true;
    textCol.append(src, ja, warn, durWarn);

    function checkLineLength() {
      const over = overLongLines(ja.value);
      ja.classList.toggle('too-long', over.length > 0);
      if (!over.length) {
        warn.hidden = true;
        warn.textContent = '';
        return;
      }
      const worst = over.reduce(function (a, b) { return b.width > a.width ? b : a; });
      const where = (over.length === 1)
        ? over[0].no + '行目が長すぎます'
        : over.length + '行が長すぎます（最長は' + worst.no + '行目）';
      warn.hidden = false;
      warn.textContent = '⚠ ' + where + ': ' + fmtWidth(worst.width) + ' / 上限 ' + MAX_LINE_CHARS
        + '（全角=1文字・半角=0.5文字で換算）。このままだと焼き込み時に自動で改行されます。';
    }

    // 操作列
    const actCol = document.createElement('div');
    actCol.className = 'seg-actions';
    const approve = document.createElement('label');
    approve.className = 'approve';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'seg-approve';
    cb.checked = !!seg.is_approved;
    approve.append(cb, document.createTextNode(' 確認済'));
    const approverEl = document.createElement('div');
    approverEl.className = 'approver muted';
    var setApprover = function (by) {
      approverEl.textContent = by ? ('済 ' + by) : '';
      approverEl.title = by ? ('確認済: ' + by) : '';
      approverEl.dataset.by = by || '';   // 削除の取り消しで承認者を引き継ぐために保持
    };
    setApprover(seg.is_approved ? seg.approved_by : null);
    const saveBtnEl = document.createElement('button');
    saveBtnEl.className = 'btn sm seg-save';
    saveBtnEl.type = 'button';
    saveBtnEl.textContent = '保存';
    saveBtnEl.disabled = true;
    saveBtnEl.title = '変更はありません';
    saveBtnEl.addEventListener('click', function (e) { e.stopPropagation(); saveRow(row); });
    const splitBtn = document.createElement('button');
    splitBtn.className = 'icon-btn seg-split';
    splitBtn.type = 'button';
    splitBtn.title = '再生位置で分割';
    splitBtn.setAttribute('aria-label', '再生位置で分割');
    splitBtn.innerHTML = ICONS.split || '分割';
    const delBtn = document.createElement('button');
    delBtn.className = 'icon-btn seg-del';
    delBtn.type = 'button';
    delBtn.title = '削除';
    delBtn.setAttribute('aria-label', '削除');
    delBtn.innerHTML = ICONS.trash || '削除';
    actCol.append(approve, approverEl, saveBtnEl, splitBtn, delBtn);

    row.append(timeCol, textCol, actCol);

    // ---- イベント ----
    // 編集すると行が「未保存」になり、その行の「保存」ボタンで保存する
    ja.addEventListener('input', function () { autosize(ja); markDirty(row); checkLineLength(); scheduleDurationCheck(); });
    src.addEventListener('input', function () { autosize(src); markDirty(row); scheduleDurationCheck(); });

    // 確認済チェック時は誤編集を防ぐため、チェックを外すまで編集不可にする
    var setLocked = function (locked) {
      src.readOnly = locked;
      ja.readOnly = locked;
      startIn.disabled = locked;
      endIn.disabled = locked;
      splitBtn.disabled = locked;
      row.classList.toggle('locked', locked);
    };

    // 確認済チェックの切替時は、その行の内容ごと保存（最新訳をTMへ反映・承認者を記録）
    cb.addEventListener('change', function () {
      row.classList.toggle('approved', cb.checked);
      setLocked(cb.checked);
      saveRow(row);
    });
    if (cb.checked) row.classList.add('approved');
    setLocked(cb.checked);

    const commitTime = function () {
      const sMs = inputToMs(startIn.value);
      const eMs = inputToMs(endIn.value);
      if (sMs === null || eMs === null) { startIn.classList.add('bad'); return; }
      if (eMs < sMs) { endIn.classList.add('bad'); return; }
      startIn.classList.remove('bad'); endIn.classList.remove('bad');
      row.dataset.start = sMs; row.dataset.end = eMs;
      markDirty(row);
      scheduleDurationCheck();
    };
    startIn.addEventListener('change', commitTime);
    endIn.addEventListener('change', commitTime);
    // 入力欄クリックでシークしないように
    [startIn, endIn, src, ja].forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    playBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      playSegment(parseInt(row.dataset.start, 10), parseInt(row.dataset.end, 10));
    });
    delBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setCurrentRow(row);
      deleteSegmentRow(row);
    });
    splitBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var doSplit = function () {
        const at = Math.round(player.currentTime * 1000);
        fetch(BASE + '/api/videos/' + VIDEO_ID + '/segments/' + seg.id + '/split', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ at_ms: at })
        }).then(function (r) {
          if (r.ok) { refresh(); reloadSubs(); }
          else { r.json().then(function (j) { alert(j.message || '分割できません。'); }); }
        });
      };
      // 未保存の編集があれば先に保存してから分割
      if (pendingSave()) { saveAllDirty().then(doSplit); } else { doSplit(); }
    });

    // 行クリックでシーク
    row.addEventListener('click', function () {
      setCurrentRow(row);
      player.currentTime = parseInt(row.dataset.start, 10) / 1000;
    });
    // 入力欄にフォーカスが入ったらその行を「操作中」にする（ショートカットの対象）
    row.addEventListener('focusin', function () { setCurrentRow(row); });

    // 初期オートサイズ・初期の長さチェック
    setTimeout(function () { autosize(src); autosize(ja); }, 0);
    checkLineLength();
    return row;
  }

  // ---- 表示時間の警告 ----
  // 読み切れる時間（2行以上/1行で別）に届かない字幕に印を付ける。
  // 書き出し時は次の字幕の直前まで自動で延ばすが、それでも足りないものだけを警告する。
  let durationTimer = null;
  function scheduleDurationCheck() {
    if (durationTimer) return;
    durationTimer = setTimeout(function () { durationTimer = null; checkDurations(); }, 50);
  }
  function rowDisplayText(r) {
    const ja = r.querySelector('.seg-ja');
    const src = r.querySelector('.seg-source');
    const t = ja ? ja.value.trim() : '';
    return t !== '' ? t : (src ? src.value.trim() : '');
  }
  function checkDurations() {
    if (!list) return;
    const items = [];
    Array.prototype.forEach.call(list.children, function (r) {
      if (!r.classList || !r.classList.contains('seg')) return;
      const w = r.querySelector('.seg-dur-warn');
      if (!w) return;
      items.push({ row: r, warn: w, start: +r.dataset.start || 0, end: +r.dataset.end || 0, text: rowDisplayText(r) });
    });
    // 表示テキストが空の行は字幕として出ないので、次の字幕の判定から外す
    const shown = items.filter(function (it) { return it.text !== ''; })
      .sort(function (a, b) { return a.start - b.start; });
    items.forEach(function (it) {
      it.warn.hidden = true;
      it.warn.textContent = '';
      it.row.classList.remove('too-short');
    });
    shown.forEach(function (it, i) {
      const lines = displayLineCount(it.text);
      const minSec = lines >= 2 ? MIN_DISPLAY_SEC.multi : MIN_DISPLAY_SEC.single;
      if (minSec <= 0) return;
      const end = Math.max(it.start + 200, it.end);
      const want = it.start + Math.round(minSec * 1000);
      if (want <= end) return;
      let limit = Infinity;
      for (let j = i + 1; j < shown.length; j++) {
        if (shown[j].start > it.start) { limit = shown[j].start - MIN_GAP_MS; break; }
      }
      const shownEnd = Math.max(end, Math.min(want, limit));
      if (shownEnd >= want) return;   // 自動延長で足りる
      const sec = (Math.max(0, shownEnd - it.start) / 1000).toFixed(1);
      it.row.classList.add('too-short');
      it.warn.hidden = false;
      it.warn.textContent = '⚠ 表示時間が短すぎます: ' + sec + '秒 / 目安 ' + minSec + '秒以上（'
        + (lines >= 2 ? '2行以上' : '1行') + '）。次の字幕がすぐ始まるため、これ以上延ばせません。'
        + '前後の字幕との結合や、時刻の調整をご検討ください。';
    });
  }
  // 行の追加・削除・並べ替え（再読込・分割・削除の取り消しなど）でも再判定する
  if (list && window.MutationObserver) {
    new MutationObserver(scheduleDurationCheck).observe(list, { childList: true });
  }

  function autosize(ta) {
    ta.style.height = 'auto';
    ta.style.height = (ta.scrollHeight + 2) + 'px';
  }

  // ---- 区間再生 ----
  let stopAt = null;
  function playSegment(startMs, endMs) {
    stopAt = endMs;
    player.currentTime = startMs / 1000;
    player.play();
  }
  player && player.addEventListener('timeupdate', function () {
    const tMs = player.currentTime * 1000;
    if (stopAt !== null && tMs >= stopAt) { player.pause(); stopAt = null; }
    highlightActive(tMs);
  });

  let activeRow = null;
  function highlightActive(tMs) {
    const rows = list.children;
    let found = null;
    for (let i = 0; i < rows.length; i++) {
      const r = rows[i];
      const s = +r.dataset.start, e = +r.dataset.end;
      if (tMs >= s && tMs < e) { found = r; break; }
    }
    if (found !== activeRow) {
      if (activeRow) activeRow.classList.remove('active');
      if (found) found.classList.add('active');
      activeRow = found;
    }
  }

  // ---- セグメント再取得 ----
  function refresh() {
    return fetch(BASE + '/api/videos/' + VIDEO_ID + '/segments?lang=' + encodeURIComponent(curLang),
      { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) { renderRows(res.segments || []); });
  }

  // ---- 画面切替 ----
  function showEditor(segments) {
    if (processingPanel) processingPanel.hidden = true;
    if (editorMain) editorMain.hidden = false;
    var vttDl = document.getElementById('vttDlBtn');
    if (vttDl && (segments && segments.length)) vttDl.hidden = false;
    var srcDl = document.getElementById('sourceDlBtn');
    if (srcDl) srcDl.hidden = false;
    if (segments && segments.length) {
      ['xlsxDlBtn', 'xlsxImportBtn', 'xlsxReplaceWrap'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.hidden = false;
      });
    }
    renderRows(segments);
  }
  function showProcessing(text, sub) {
    if (editorMain) editorMain.hidden = true;
    if (processingPanel) processingPanel.hidden = false;
    if (processingText) processingText.textContent = text;
    if (processingSub) processingSub.textContent = sub || '';
  }
  function setBadge(status) {
    if (!statusBadge) return;
    statusBadge.className = 'badge status-' + status;
    statusBadge.textContent = STATUS_LABELS[status] || status;
  }

  function isEditable(status, count) {
    return count > 0 && PROCESSING.indexOf(status) === -1 && status !== 'error';
  }

  // ---- ポーリング（処理完了/書き出し完了を待つ） ----
  var TRANSCRIBING = 'transcribing', EXTRACTING = 'extracting', TRANSLATING = 'translating', EXPORTING = 'exporting', TRIMMING = 'trimming';
  function updateProgress(v) {
    if (!progressWrap) return;
    var show = [EXTRACTING, TRANSCRIBING, TRANSLATING, EXPORTING, TRIMMING].indexOf(v.status) !== -1;
    progressWrap.hidden = !show;
    if (!show) return;
    var pct = (typeof v.progress === 'number') ? v.progress : null;
    if (pct === null) {
      progressBar.classList.add('indeterminate');
      progressBar.style.width = '40%';
    } else {
      progressBar.classList.remove('indeterminate');
      progressBar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }
    // ステージ情報があれば「1/3 OCR中 25%」の形で表示。無ければ従来のステータス名。
    var head;
    if (v.stage_index && v.stage_total && v.stage_label) {
      head = v.stage_index + '/' + v.stage_total + ' ' + v.stage_label;
    } else {
      head = STATUS_LABELS[v.status] || v.status;
    }
    var pctTxt = (pct !== null) ? ' ' + pct + '%' : '';
    if (progressLabel) progressLabel.textContent = head + pctTxt;
  }

  function startPoll() {
    if (pollTimer) return;
    // ローカル文字起こし/翻訳なら負荷モニタも開始
    if (LOCAL_TRANSCRIBE || LOCAL_TRANSLATE) startLoadMonitor();
    pollTimer = setInterval(function () {
      fetch(BASE + '/api/videos/' + VIDEO_ID, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (v) {
          currentStatus = v.status;
          setBadge(v.status);
          updateProgress(v);
          syncLangTabs(v.langs);
          // 「今この段階がローカル処理か」を判定して負荷モニタを開始/停止
          if (isLocalStage(v.status)) { startLoadMonitor(); updateLoadTitle(v.status); }
          else { stopLoadMonitor(); }
          if (v.status === 'error') {
            stopPoll();
            showProcessing('エラーが発生しました', v.error || '');
            resetExportButton();
            pendingDownload = false;
            return;
          }
          if (v.status === 'exported') {
            stopPoll();
            setExportButtonState(false);
            resetExportButton();
            if (pendingDownload) { pendingDownload = false; triggerDownload(); }
            if (editorMain && editorMain.hidden) refresh().then(function () { showEditorPanelOnly(); });
            return;
          }
          if (v.status === 'ready' && editorMain && editorMain.hidden) {
            stopPoll();
            refresh().then(function () { showEditorPanelOnly(); });
          }
        })
        .catch(function () {});
    }, 2000);
  }

  // ---- サーバー負荷モニタ（ローカル文字起こし/翻訳中） ----
  // 今の段階がローカル処理（負荷パネルを出すべき段階）かどうか
  function isLocalStage(status) {
    if (status === TRANSCRIBING || status === EXTRACTING) return LOCAL_TRANSCRIBE;
    if (status === TRANSLATING) return LOCAL_TRANSLATE;
    return false;
  }
  function updateLoadTitle(status) {
    if (!loadTitle) return;
    if (status === TRANSLATING) loadTitle.textContent = 'サーバー負荷（ローカル翻訳中）';
    else loadTitle.textContent = 'サーバー負荷（ローカル文字起こし中）';
  }
  var loadTimer = null;
  function startLoadMonitor() {
    if (loadTimer || !loadWrap) return;
    loadWrap.hidden = false;
    var tick = function () {
      fetch(BASE + '/api/system-load', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (typeof d.cpu_percent === 'number') {
            cpuBar.style.width = d.cpu_percent + '%';
            cpuVal.textContent = d.cpu_percent + '%';
          }
          if (typeof d.mem_percent === 'number') {
            memBar.style.width = d.mem_percent + '%';
            memVal.textContent = d.mem_percent + '% (' + (d.mem_used_mb || 0) + '/' + (d.mem_total_mb || 0) + 'MB)';
          }
          var bits = [];
          if (d.cores) bits.push(d.cores + ' コア');
          if (typeof d.load1 === 'number') bits.push('ロード平均 ' + d.load1.toFixed(2) + ' / ' + d.load5.toFixed(2) + ' / ' + d.load15.toFixed(2));
          if (loadExtra) loadExtra.textContent = bits.join(' ・ ');
          // プロセス状態（段階に応じて文字起こし/翻訳を出し分け）
          if (procStatus) {
            if (currentStatus === TRANSLATING) {
              if (d.translate && d.translate.running) {
                var tpc = (typeof d.translate.cpu_percent === 'number') ? d.translate.cpu_percent + '%' : '—';
                var tpm = (typeof d.translate.mem_mb === 'number') ? ' ・ ' + d.translate.mem_mb + 'MB' : '';
                procStatus.textContent = '翻訳プロセス: 実行中（CPU ' + tpc + ' / 1コア=100%換算' + tpm + '）';
                procStatus.className = 'muted proc-status running';
              } else {
                // この瞬間はローカル翻訳プロセスが動いていない（バッチ間など）。負荷は上のCPU/メモリで確認できる。
                procStatus.textContent = '翻訳プロセス: 待機中（このタイミングではローカル翻訳は動いていません）';
                procStatus.className = 'muted proc-status idle';
              }
            } else if (d.transcribe && d.transcribe.running) {
              var pc = (typeof d.transcribe.cpu_percent === 'number') ? d.transcribe.cpu_percent + '%' : '—';
              var pm = (typeof d.transcribe.mem_mb === 'number') ? ' ・ ' + d.transcribe.mem_mb + 'MB' : '';
              // CPU%はtop方式（1コア=100%。複数コア使用で100%超もあり得る）
              procStatus.textContent = '文字起こしプロセス: 実行中（CPU ' + pc + ' / 1コア=100%換算' + pm + '）';
              procStatus.className = 'muted proc-status running';
            } else {
              procStatus.textContent = '文字起こしプロセス: 待機中（このタイミングではWhisperは動いていません）';
              procStatus.className = 'muted proc-status idle';
            }
          }
        })
        .catch(function () {});
    };
    tick();
    loadTimer = setInterval(tick, 3000);
  }
  function stopLoadMonitor() {
    if (loadTimer) { clearInterval(loadTimer); loadTimer = null; }
    if (loadWrap) loadWrap.hidden = true;
  }
  function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } stopLoadMonitor(); }
  function showEditorPanelOnly() {
    if (processingPanel) processingPanel.hidden = true;
    if (editorMain) editorMain.hidden = false;
  }

  // ---- 書き出し/ダウンロード（A案: ボタンは常に1つ） ----
  var pendingDownload = false;
  function triggerDownload() {
    var url = BASE + '/videos/' + VIDEO_ID + '/download?lang=' + encodeURIComponent(curLang);
    var a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }
  // needsExport=true: 「焼き込んでDL」/ false: 「焼き込み動画をDL」
  function setExportButtonState(needsExport) {
    if (!exportBtn) return;
    exportBtn.dataset.needsExport = needsExport ? '1' : '0';
    exportBtn.dataset.hasExport = needsExport ? exportBtn.dataset.hasExport : '1';
    exportBtn.textContent = needsExport
      ? TARGET_LABEL + '字幕を焼き込んでDL'
      : TARGET_LABEL + '版をDL';
    exportBtn.title = needsExport
      ? '表示中の言語（' + TARGET_LABEL + '）の字幕を映像に焼き込み、完了したらダウンロードします（数分かかることがあります）'
      : TARGET_LABEL + 'の字幕を焼き込み済みのMP4をダウンロードします';
  }
  function resetExportButton() {
    if (!exportBtn) return;
    exportBtn.disabled = false;
    setExportButtonState(exportBtn.dataset.needsExport === '1');
  }
  function startExport() {
    exportBtn.disabled = true;
    exportBtn.textContent = '書き出し中…';
    pendingDownload = true;
    fetch(BASE + '/api/videos/' + VIDEO_ID + '/export', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ lang: curLang })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) {
          alert(res.j.message || '書き出しを開始できません。');
          resetExportButton();
          pendingDownload = false;
          return;
        }
        setBadge('exporting');
        startPoll();
      })
      .catch(function () { resetExportButton(); pendingDownload = false; });
  }
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var needsExport = exportBtn.dataset.needsExport === '1';
      // 未保存の編集があれば、保存してから最新の字幕で焼き込む（保存=字幕更新なので作り直し）
      if (pendingSave()) {
        saveAllDirty().then(function () { startExport(); });
        return;
      }
      if (needsExport) {
        startExport();          // 未書き出し or 字幕が更新済み → 焼き込み直してDL
      } else {
        triggerDownload();      // 最新の焼き込み動画がある → 即DL
      }
    });
  }

  // ---- 用語集にAIで登録（編集済み字幕から） ----
  var glossaryAiBtn = document.getElementById('glossaryAiBtn');
  if (glossaryAiBtn) {
    function runGlossaryAi() {
      glossaryAiBtn.disabled = true;
      var label = glossaryAiBtn.textContent;
      glossaryAiBtn.textContent = '登録中…';
      fetch(BASE + '/api/videos/' + VIDEO_ID + '/glossary-ai', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ lang: curLang })   // 表示中の言語の訳語で用語集を作る
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          alert((res.j && res.j.message) || (res.ok ? '完了しました。' : '登録に失敗しました。'));
        })
        .catch(function () { alert('通信エラーが発生しました。'); })
        .then(function () { glossaryAiBtn.disabled = false; glossaryAiBtn.textContent = label; });
    }
    glossaryAiBtn.addEventListener('click', function () {
      if (!confirm('編集済みの' + TARGET_LABEL + '字幕からAIで専門用語を抽出し、'
        + TARGET_LABEL + 'の用語集に登録します。\nAPIコストがかかります。実行しますか？')) return;
      // 未保存の編集があれば、先に保存してから最新の訳で抽出する
      if (pendingSave()) { saveAllDirty().then(runGlossaryAi); return; }
      runGlossaryAi();
    });
  }

  // ---- Excel（字幕）取り込み ----
  var xlsxImportBtn = document.getElementById('xlsxImportBtn');
  var xlsxImportFile = document.getElementById('xlsxImportFile');
  var xlsxReplaceChk = document.getElementById('xlsxReplaceChk');
  if (xlsxImportBtn && xlsxImportFile) {
    xlsxImportBtn.addEventListener('click', function () {
      xlsxImportFile.value = '';   // 同じファイルを続けて選んでも change が発火するように
      xlsxImportFile.click();
    });
    xlsxImportFile.addEventListener('change', function () {
      var file = xlsxImportFile.files && xlsxImportFile.files[0];
      if (!file) return;
      var replace = !!(xlsxReplaceChk && xlsxReplaceChk.checked);
      // 完全置換は削除を伴うので、明示的に確認する
      if (replace && !confirm('「完全置換」が有効です。\n取り込むシートに載っていない既存の字幕は削除されます'
        + '（セグメントごと消えるので、他の言語の訳も一緒に消えます）。\n続けますか？')) {
        return;
      }
      var doImport = function () {
        var fd = new FormData();
        fd.append('file', file);
        fd.append('replace', replace ? '1' : '');
        fd.append('lang', curLang);   // 取り込み先は表示中の言語
        xlsxImportBtn.disabled = true;
        var label = xlsxImportBtn.textContent;
        xlsxImportBtn.textContent = '取込中…';
        fetch(BASE + '/videos/' + VIDEO_ID + '/segments/import', {
          method: 'POST', headers: { 'Accept': 'application/json' }, body: fd
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            alert((res.j && res.j.message) || (res.ok ? '取り込みました。' : '取り込みに失敗しました。'));
            if (res.ok) {
              refresh().then(reloadSubs);
              // 字幕が変わったので、既存の焼き込み動画は作り直しが必要
              if (typeof setExportButtonState === 'function') setExportButtonState(true);
            }
          })
          .catch(function () { alert('通信エラーが発生しました。'); })
          .then(function () { xlsxImportBtn.disabled = false; xlsxImportBtn.textContent = label; });
      };
      // 未保存の編集があると取り込みで上書きされてしまうため、先に保存する
      if (pendingSave()) { saveAllDirty().then(doImport); } else { doImport(); }
    });
  }

  // ---- 動画を削除 ----
  var deleteVideoBtn = document.getElementById('deleteVideoBtn');
  if (deleteVideoBtn) {
    deleteVideoBtn.addEventListener('click', function () {
      if (!confirm('この動画と字幕・書き出し物をすべて削除します。元に戻せません。よろしいですか？')) return;
      deleteVideoBtn.disabled = true;
      fetch(BASE + '/api/videos/' + VIDEO_ID, { method: 'DELETE', headers: { 'Accept': 'application/json' } })
        .then(function (r) {
          if (r.ok) { window.removeEventListener('beforeunload', beforeUnloadGuard); window.location.href = BASE + '/'; }
          else { alert('削除に失敗しました。'); deleteVideoBtn.disabled = false; }
        })
        .catch(function () { alert('削除に失敗しました。'); deleteVideoBtn.disabled = false; });
    });
  }

  // ---- 離脱前: 未保存の変更があれば警告 ----
  function beforeUnloadGuard(e) {
    if (pendingSave()) {
      e.preventDefault();
      e.returnValue = '';
    }
  }
  window.addEventListener('beforeunload', beforeUnloadGuard);

  // ---- キーボードショートカット（保存 / 行削除 / 削除の取り消し） ----
  function isTextField(el) {
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    return tag === 'textarea' || tag === 'input' || el.isContentEditable;
  }

  function onGlobalKeydown(e) {
    var mod = e.ctrlKey || e.metaKey;
    if (!mod) return;
    var key = e.key;

    // 削除の取り消し: Ctrl/⌘ + Z
    // 入力欄の中では、文字入力のやり直し（ブラウザ標準のundo）を優先して邪魔しない。
    if (!e.shiftKey && !e.altKey && (key === 'z' || key === 'Z')) {
      if (isTextField(e.target)) return;
      e.preventDefault();
      undoDelete();
      return;
    }

    // 保存: Ctrl/⌘ + S（未保存の全行をまとめて保存）
    if (!e.shiftKey && !e.altKey && (key === 's' || key === 'S')) {
      e.preventDefault();
      if (pendingSave()) {
        saveAllDirty();
      } else if (saveIndicator) {
        saveIndicator.textContent = '変更はありません';
        setTimeout(function () { if (!dirtyRows().length) saveIndicator.textContent = ''; }, 1200);
      }
      return;
    }

    // 行削除: Ctrl/⌘ + Shift + Backspace（操作中の行を削除）
    if (e.shiftKey && (key === 'Backspace' || key === 'Delete')) {
      var row = currentRow || activeRow;
      if (row && document.body.contains(row)) {
        e.preventDefault();
        deleteSegmentRow(row);
      }
      return;
    }
  }
  function setupShortcuts() {
    document.addEventListener('keydown', onGlobalKeydown);
    // 入力欄フォーカスで「操作中の行」を追従（動的に追加される行にも効くよう委譲）
    if (list) {
      list.addEventListener('focusin', function (e) {
        var row = (e.target && e.target.closest) ? e.target.closest('.seg') : null;
        if (row) setCurrentRow(row);
      });
    }
    var kbdHint = document.getElementById('kbdHint');
    if (kbdHint) {
      kbdHint.textContent = 'ショートカット: 保存 ' + MOD + '+S ・ 行削除 ' + MOD
        + '+Shift+Backspace ・ 削除を元に戻す ' + MOD + '+Z';
      kbdHint.title = MOD + '+Z は入力欄の外で押すと、直前に削除したセグメントを復元します'
        + '（入力欄の中では文字入力のやり直しになります）。';
    }
  }
  setupShortcuts();

  // ---- 字幕言語の切り替え・追加・削除 ----
  function switchLang(code) {
    if (!code || code === curLang) return;
    var info = langInfo(code);
    if (info && info.status === 'translating') {
      flashIndicator(info.label + 'はまだ翻訳中です', 2500);
      return;
    }
    var go = function () {
      applyLangMeta(code);
      if (segCount) segCount.textContent = '読み込み中…';
      return refresh().then(function () {
        reloadSubs();
        updateSegCount();
        var i = langInfo(code);
        if (i && i.status === 'pending') {
          flashIndicator(i.label + 'はまだ翻訳されていません', 3000);
        }
      });
    };
    // 未保存の編集は、切り替え前に今の言語で保存する
    if (pendingSave()) { return saveAllDirty().then(go); }
    return go();
  }

  function setupLangTabs() {
    var tabs = document.getElementById('langTabs');
    if (!tabs) return;
    tabs.addEventListener('click', function (e) {
      var tab = e.target && e.target.closest ? e.target.closest('.lang-tab') : null;
      if (tab) switchLang(tab.dataset.lang);
    });

    // 言語を追加（原文は再利用し、その言語へ翻訳する）
    var addBtn = document.getElementById('addLangBtn');
    var addSel = document.getElementById('addLangSelect');
    if (addBtn && addSel) {
      addBtn.addEventListener('click', function () {
        var code = addSel.value;
        if (!code) { flashIndicator('追加する言語を選んでください', 2000); return; }
        var label = addSel.options[addSel.selectedIndex].textContent.trim();
        if (!confirm(label + 'の字幕を追加します。\n文字起こしはそのまま使い、' + label
          + 'へ翻訳します（翻訳のAPIコストがかかります）。\n実行しますか？')) return;
        addBtn.disabled = true;
        fetch(BASE + '/api/videos/' + VIDEO_ID + '/langs', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ target_lang: code })
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok) {
              alert((res.j && res.j.message) || '言語を追加できませんでした。');
              addBtn.disabled = false;
              return;
            }
            // 翻訳が終わるまで進行状況を出し、終わったらその言語で開き直す
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            window.location.href = BASE + '/videos/' + VIDEO_ID + '?lang=' + encodeURIComponent(code);
          })
          .catch(function () { alert('通信エラーが発生しました。'); addBtn.disabled = false; });
      });
    }

    // 表示中の言語を削除（主言語は削除できない）
    var delBtn = document.getElementById('removeLangBtn');
    if (delBtn) {
      delBtn.addEventListener('click', function () {
        var info = langInfo(curLang);
        if (!info || info.primary) { flashIndicator('主言語は削除できません', 2500); return; }
        if (!confirm(info.label + 'の字幕と、その言語で焼き込んだ動画を削除します。\n元に戻せません。よろしいですか？')) return;
        delBtn.disabled = true;
        fetch(BASE + '/api/videos/' + VIDEO_ID + '/langs/' + encodeURIComponent(curLang), {
          method: 'DELETE', headers: { 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok) {
              alert((res.j && res.j.message) || '削除できませんでした。');
              delBtn.disabled = false;
              return;
            }
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            window.location.href = BASE + '/videos/' + VIDEO_ID;
          })
          .catch(function () { alert('通信エラーが発生しました。'); delBtn.disabled = false; });
      });
    }
  }
  setupLangTabs();

  /* ポーリングで受け取った言語一覧をタブへ反映（翻訳の進み具合が変わったとき） */
  function syncLangTabs(langs) {
    if (!Array.isArray(langs) || !langs.length) return;
    var changed = (langs.length !== LANGS.length);
    if (!changed) {
      for (var i = 0; i < langs.length; i++) {
        var prev = langInfo(langs[i].lang);
        if (!prev || prev.status !== langs[i].status) { changed = true; break; }
      }
    }
    LANGS = langs;
    if (!changed) return;
    var tabs = document.getElementById('langTabs');
    if (!tabs) return;
    langs.forEach(function (l) {
      var tab = tabs.querySelector('.lang-tab[data-lang="' + l.lang + '"]');
      if (!tab) return;
      tab.dataset.status = l.status;
      var note = tab.querySelector('.lang-tab-note');
      var text = (l.status === 'translating') ? '翻訳中…'
        : (l.status === 'error') ? '失敗'
        : (l.status === 'pending') ? '未翻訳' : '';
      if (!note && text) {
        note = document.createElement('span');
        note.className = 'lang-tab-note';
        tab.appendChild(note);
      }
      if (note) {
        note.textContent = text;
        note.className = 'lang-tab-note' + (l.status === 'error' ? ' err' : '');
        note.hidden = (text === '');
      }
    });
  }

  // ---- 動画編集（区間カット） ----
  function setupCut() {
    var panel = document.getElementById('cutPanel');
    if (!panel) return;
    var startIn = document.getElementById('cutStart');
    var endIn = document.getElementById('cutEnd');
    var setStart = document.getElementById('cutSetStart');
    var setEnd = document.getElementById('cutSetEnd');
    var previewBtn = document.getElementById('cutPreview');
    var runBtn = document.getElementById('cutRun');
    var info = document.getElementById('cutInfo');

    function curClockMs() {
      return Math.round(((player && player.currentTime) ? player.currentTime : 0) * 1000);
    }
    function parseRange() {
      var s = inputToMs(startIn.value);
      var e = inputToMs(endIn.value);
      return { s: s, e: e, valid: (s !== null && e !== null && e > s) };
    }
    function updateInfo() {
      if (!info) return;
      var r = parseRange();
      if (!r.valid) {
        info.textContent = (startIn.value || endIn.value) ? '開始・終了の時刻を確認してください' : '';
        return;
      }
      info.textContent = '削除する長さ ' + msToInput(r.e - r.s) + '（' + msToInput(r.s) + ' 〜 ' + msToInput(r.e) + '）';
    }

    if (setStart) setStart.addEventListener('click', function () { startIn.value = msToInput(curClockMs()); updateInfo(); });
    if (setEnd) setEnd.addEventListener('click', function () { endIn.value = msToInput(curClockMs()); updateInfo(); });
    startIn.addEventListener('change', updateInfo);
    endIn.addEventListener('change', updateInfo);
    // カット欄クリックでシークしないように
    [startIn, endIn].forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    if (previewBtn) previewBtn.addEventListener('click', function () {
      var r = parseRange();
      if (!r.valid) { alert('開始・終了の時刻を正しく入力してください。'); return; }
      playSegment(r.s, r.e); // 既存の区間再生を流用
    });

    if (runBtn) runBtn.addEventListener('click', function () {
      var r = parseRange();
      if (!r.valid) { alert('開始・終了の時刻を正しく入力してください。'); return; }
      if (!confirm('選択した区間（' + msToInput(r.s) + ' 〜 ' + msToInput(r.e) + '）の映像・音声・字幕を削除します。\nこの操作は元に戻せません。実行しますか？')) return;
      var go = function () { startCut(r.s, r.e); };
      // 未保存の編集は先に保存してからカットする
      if (pendingSave()) { saveAllDirty().then(go); } else { go(); }
    });

    updateInfo();
  }

  function startCut(startMs, endMs) {
    // カット中は離脱警告を外し、処理パネルを表示
    window.removeEventListener('beforeunload', beforeUnloadGuard);
    showProcessing('動画をカットしています…', 'この処理には数分かかることがあります。完了すると自動で再読み込みします。');
    if (progressWrap) {
      progressWrap.hidden = false;
      progressBar.classList.add('indeterminate');
      progressBar.style.width = '40%';
      if (progressLabel) progressLabel.textContent = '動画編集中';
    }
    fetch(BASE + '/api/videos/' + VIDEO_ID + '/cut', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ start_ms: startMs, end_ms: endMs })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) {
          alert((res.j && res.j.message) || 'カットを開始できません。');
          window.location.reload();
          return;
        }
        cutPoll();
      })
      .catch(function () { alert('通信エラーが発生しました。'); window.location.reload(); });
  }

  function cutPoll() {
    var timer = setInterval(function () {
      fetch(BASE + '/api/videos/' + VIDEO_ID, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (v) {
          updateProgress(v);
          if (v.status === 'ready' || v.status === 'exported') {
            clearInterval(timer);
            window.location.reload(); // 新しい動画・字幕で開き直す
          } else if (v.status === 'error') {
            clearInterval(timer);
            showProcessing('エラーが発生しました', v.error || '');
          }
        })
        .catch(function () {});
    }, 2000);
  }

  setupCut();

  // ---- 初期化 ----
  function nudgeWorker() {
    fetch(BASE + '/api/spawn-worker', { method: 'POST', headers: { 'Accept': 'application/json' } }).catch(function () {});
  }
  if (data.video.status === 'catalog') {
    // ファイル未添付: サーバー描画の添付パネルをそのまま表示（ポーリング不要）
    return;
  }
  if (isEditable(data.video.status, data.segments.length)) {
    showEditor(data.segments);
    if (data.video.status === 'exporting') {
      if (exportBtn) { exportBtn.disabled = true; exportBtn.textContent = '書き出し中…'; }
      nudgeWorker();
      startPoll();
    }
  } else if (data.video.status === 'error') {
    showProcessing('エラーが発生しました', data.video.error || '');
  } else {
    showProcessing('処理中です…', STATUS_LABELS[data.video.status] || '');
    nudgeWorker(); // 止まっていても自動再開
    startPoll();
  }
})();
