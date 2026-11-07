/* ダッシュボード: アップロードモーダル + 状況ポーリング */
(function () {
  'use strict';

  var BASE = window.APP_BASE || '';
  const PROCESSING = ['uploaded', 'extracting', 'transcribing', 'translating', 'exporting'];

  // ---- モーダル ----
  const modal = document.getElementById('uploadModal');
  const newBtn = document.getElementById('newBtn');
  const cancelBtn = document.getElementById('cancelUpload');
  const form = document.getElementById('uploadForm');
  const submitBtn = document.getElementById('submitUpload');
  const progress = document.getElementById('uploadProgress');
  const bar = document.getElementById('uploadBar');
  const errorBox = document.getElementById('uploadError');

  function openModal() {
    if (modal) modal.hidden = false;
  }
  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }
    if (progress) progress.hidden = true;
    if (bar) bar.style.width = '0';
    if (typeof countBox !== 'undefined' && countBox) { countBox.hidden = true; countBox.textContent = ''; }
    if (form) form.reset();
    if (submitBtn) submitBtn.disabled = false;
  }

  if (newBtn) newBtn.addEventListener('click', openModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
  }

  // ---- プロバイダ/モデル選択・文字起こしエンジンのハイライト（共通処理） ----
  if (window.APP_COMMON) {
    window.APP_COMMON.setupProviderSelect('upload');
    window.APP_COMMON.setupRadioHighlights(document);
  }

  // ---- フォルダ: 新規作成 ----
  var newFolderBtn = document.getElementById('newFolderBtn');
  if (newFolderBtn) {
    newFolderBtn.addEventListener('click', function () {
      var name = prompt('フォルダ名を入力してください');
      if (name && name.trim() !== '') {
        var f = document.getElementById('newFolderForm');
        f.name.value = name.trim();
        f.submit();
      }
    });
  }

  // ---- フォルダ: 名前変更 ----
  Array.prototype.forEach.call(document.querySelectorAll('[data-rename]'), function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-rename');
      var cur = btn.getAttribute('data-name') || '';
      var name = prompt('新しいフォルダ名', cur);
      if (name && name.trim() !== '') {
        var f = document.getElementById('renameForm');
        f.action = BASE + '/folders/' + id + '/rename';
        f.name.value = name.trim();
        f.submit();
      }
    });
  });

  // ---- 動画を削除 ----
  Array.prototype.forEach.call(document.querySelectorAll('.card-del'), function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var id = btn.getAttribute('data-video-id');
      var title = btn.getAttribute('data-title') || '';
      if (!confirm('「' + title + '」を削除します。元に戻せません。よろしいですか？')) return;
      fetch(BASE + '/api/videos/' + id, { method: 'DELETE', headers: { 'Accept': 'application/json' } })
        .then(function (r) {
          if (r.ok) {
            var card = document.querySelector('.card[data-video-id="' + id + '"]');
            if (card) card.remove();
          } else { alert('削除に失敗しました。'); }
        });
    });
  });

  // ---- 動画をフォルダへ移動 ----
  Array.prototype.forEach.call(document.querySelectorAll('.move-select'), function (sel) {
    sel.addEventListener('change', function () {
      var id = sel.getAttribute('data-video-id');
      var fid = sel.value;
      fetch(BASE + '/api/videos/' + id + '/folder', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ folder_id: fid === '' ? null : parseInt(fid, 10) })
      }).then(function (r) {
        if (r.ok) { window.location.reload(); }
        else { alert('移動できませんでした。'); }
      });
    });
  });

  // ---- アップロード（複数ファイルを1件ずつXHRで送信・進捗表示） ----
  const countBox = document.getElementById('uploadCount');

  function stripExt(name) {
    return String(name || '').replace(/\.[^/.]+$/, '');
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }

      const fileInput = form.querySelector('input[type="file"][name="video"]');
      const files = fileInput && fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
      if (!files.length) {
        showError('動画ファイルを選択してください。');
        return;
      }

      // ファイル以外の共通フィールドを一度だけ収集（各リクエストで使い回す）
      const baseEntries = [];
      new FormData(form).forEach(function (val, key) {
        if (key !== 'video') baseEntries.push([key, val]);
      });
      const typedTitle = (function () {
        for (var i = 0; i < baseEntries.length; i++) {
          if (baseEntries[i][0] === 'title') return String(baseEntries[i][1] || '').trim();
        }
        return '';
      })();
      const multiple = files.length > 1;

      if (submitBtn) submitBtn.disabled = true;
      if (progress) progress.hidden = false;

      let index = 0;
      const failures = [];

      // 分割アップロードのチャンクサイズ（512MB）。
      // 共用サーバーのリクエストサイズ上限を避けるため、1リクエストをこのサイズ以下に保つ。
      // 413が出る場合はこの値をさらに下げる（例: 100 * 1024 * 1024 = 100MB）。
      const CHUNK_SIZE = 512 * 1024 * 1024;

      function setCount(state) {
        if (!countBox) return;
        countBox.hidden = false;
        countBox.textContent = files.length > 1
          ? (files.length + '件中 ' + Math.min(index + 1, files.length) + '件目を' + state)
          : state;
      }

      function finish() {
        if (failures.length) {
          showError(failures.length + '件のアップロードに失敗しました：' + failures.join('、'));
          if (countBox) countBox.hidden = true;
          return;
        }
        // すべて成功 → 新しい処理中カードを見せるため再読込
        window.location.reload();
      }

      // ファイルごとに一意なアップロードIDを作る（サーバー側の一時ファイル名に使う）
      function makeUploadId() {
        return 'up_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
      }

      function uploadNext() {
        if (index >= files.length) { finish(); return; }
        const file = files[index];

        const uploadId = makeUploadId();
        const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        const titleForFile = (multiple || typedTitle === '') ? stripExt(file.name) : null;

        if (bar) bar.style.width = '0';

        // このファイルのチャンクを 0 から順番に送る（前チャンク成功後に次へ）
        function sendChunk(chunkIndex) {
          const start = chunkIndex * CHUNK_SIZE;
          const end = Math.min(file.size, start + CHUNK_SIZE);
          const blob = file.slice(start, end);
          const isLast = chunkIndex === totalChunks - 1;

          const fd = new FormData();
          fd.append('upload_id', uploadId);
          fd.append('chunk_index', String(chunkIndex));
          fd.append('chunk_total', String(totalChunks));
          fd.append('file_name', file.name);
          fd.append('chunk', blob, file.name);

          // 最終チャンク送信時に、動画作成に必要なメタ情報を一緒に送る
          if (isLast) {
            baseEntries.forEach(function (pair) { fd.append(pair[0], pair[1]); });
            if (titleForFile !== null) {
              fd.set('title', titleForFile);
            }
          }

          const xhr = new XMLHttpRequest();
          xhr.open('POST', BASE + '/videos/chunk', true);
          xhr.setRequestHeader('Accept', 'application/json');

          setCount('アップロード中…（' + (chunkIndex + 1) + '/' + totalChunks + '）');

          xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable && bar) {
              // ファイル全体に対する進捗（送信済みチャンク＋現在チャンクの進捗）
              const sent = start + ev.loaded;
              bar.style.width = Math.round((sent / file.size) * 100) + '%';
            }
          });
          xhr.addEventListener('load', function () {
            let res = {};
            try { res = JSON.parse(xhr.responseText); } catch (_) {}
            if (xhr.status >= 200 && xhr.status < 300 && res.ok) {
              if (isLast) {
                index++;
                uploadNext();
              } else {
                sendChunk(chunkIndex + 1);
              }
            } else {
              failures.push(file.name + (res.message ? '（' + res.message + '）' : ''));
              index++;
              uploadNext();
            }
          });
          xhr.addEventListener('error', function () {
            failures.push(file.name + '（通信エラー）');
            index++;
            uploadNext();
          });
          xhr.send(fd);
        }

        sendChunk(0);
      }

      uploadNext();
    });
  }

  function showError(msg) {
    if (submitBtn) submitBtn.disabled = false;
    if (progress) progress.hidden = true;
    if (bar) bar.style.width = '0';
    if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; }
  }

  // ---- 状況ポーリング ----
  const STATUS_LABELS = (window.APP_COMMON && window.APP_COMMON.STATUS_LABELS) || {};

  function currentStatus(badge) {
    const m = (badge.className.match(/status-([a-z_]+)/) || [])[1];
    return m || '';
  }

  function updateBadges(videos) {
    videos.forEach(function (v) {
      const card = document.querySelector('.card[data-video-id="' + v.id + '"]');
      if (!card) return;
      const badge = card.querySelector('[data-status]');
      if (!badge) return;
      const processing = PROCESSING.indexOf(v.status) !== -1;
      badge.className = 'badge status-' + v.status;
      badge.setAttribute('data-status', '');
      if (processing && v.stage_index && v.stage_total && v.stage_label) {
        // 処理中は「1/3 OCR中 25%」のようにステージと進捗を表示
        var pctTxt = (typeof v.progress === 'number') ? ' ' + v.progress + '%' : '';
        badge.textContent = v.stage_index + '/' + v.stage_total + ' ' + v.stage_label + pctTxt;
      } else {
        badge.textContent = STATUS_LABELS[v.status] || v.status;
      }
      if (v.status === 'error' && v.error) badge.title = v.error;
    });
  }

  let timer = null;
  function poll() {
    fetch(BASE + '/api/videos', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        updateBadges(data.videos || []);
        const stillProcessing = (data.videos || []).some(function (v) {
          return PROCESSING.indexOf(v.status) !== -1;
        });
        if (!stillProcessing && timer) {
          clearInterval(timer);
          timer = null;
        }
      })
      .catch(function () { /* 一時的なエラーは無視 */ });
  }

  // 処理中カードがあるときだけポーリング開始
  const anyProcessing = Array.from(document.querySelectorAll('.card [data-status]')).some(function (el) {
    return PROCESSING.indexOf(currentStatus(el)) !== -1;
  });
  if (anyProcessing) {
    // 万一ワーカーが止まっていても自動再開（冪等・ロックで二重起動防止）
    fetch(BASE + '/api/spawn-worker', { method: 'POST', headers: { 'Accept': 'application/json' } }).catch(function () {});
    timer = setInterval(poll, 2000);
  }
})();
