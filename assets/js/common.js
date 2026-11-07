/* 共通ユーティリティ: ステータス表示・プロバイダ/モデル選択・SVGアイコン */
(function () {
  'use strict';

  var STATUS_LABELS = {
    catalog: '未取込(ファイル待ち)', uploaded: 'アップロード済み', extracting: '音声抽出中',
    transcribing: '文字起こし中', translating: '翻訳中', ready: '編集可能',
    exporting: '書き出し中', exported: '書き出し完了', trimming: '動画編集中', error: 'エラー'
  };

  /* 絵文字の代わりに使うインラインSVGアイコン（環境依存なし） */
  var ICONS = {
    play:   '<svg viewBox="0 0 16 16" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M4 2.5v11l9-5.5z"/></svg>',
    split:  '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 2v12M3 5l3 3-3 3M13 5l-3 3 3 3"/></svg>',
    trash:  '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2.5 4.5h11M5.5 4.5v-2h5v2M4 4.5l.8 9h6.4l.8-9M6.5 7v4M9.5 7v4"/></svg>',
    download: '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 2v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/></svg>',
    folder: '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.2l1.6 1.8H13A1.5 1.5 0 0 1 14.5 5.3v6.2A1.5 1.5 0 0 1 13 13H3a1.5 1.5 0 0 1-1.5-1.5z"/></svg>'
  };

  /* ラジオ群のハイライトを checked のものだけに同期 */
  function syncHighlight(group) {
    if (!group) return;
    Array.prototype.forEach.call(group.querySelectorAll('.model-option'), function (opt) {
      var r = opt.querySelector('input[type=radio]');
      opt.classList.toggle('selected', !!(r && r.checked));
    });
  }

  /* 翻訳プロバイダ + プロバイダ別モデル選択（ハイライト・出し分け・hidden同期） */
  function setupProviderSelect(uid) {
    var provGroup = document.querySelector('.provider-group[data-uid="' + uid + '"]');
    var hidden = document.getElementById(uid + '_model_hidden');
    if (!provGroup) return;

    function currentProvider() {
      var c = provGroup.querySelector('input[name=translate_provider]:checked');
      return c ? c.value : '';
    }
    function modelFields() {
      return document.querySelectorAll('.provider-models[data-uid="' + uid + '"]');
    }
    function syncHidden() {
      if (!hidden) return;
      var f = document.querySelector('.provider-models[data-uid="' + uid + '"][data-provider="' + currentProvider() + '"]');
      var ch = f ? f.querySelector('input[type=radio]:checked') : null;
      hidden.value = ch ? ch.value : '';
    }
    function syncVisibility() {
      var prov = currentProvider();
      Array.prototype.forEach.call(modelFields(), function (field) {
        var active = field.getAttribute('data-provider') === prov;
        field.hidden = !active;
        // 非表示のラジオは disabled にして送信されないように
        Array.prototype.forEach.call(field.querySelectorAll('input[type=radio]'), function (r) { r.disabled = !active; });
        if (active) syncHighlight(field.querySelector('.model-options'));
      });
      syncHidden();
    }

    provGroup.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'translate_provider') { syncHighlight(provGroup); syncVisibility(); }
    });
    Array.prototype.forEach.call(modelFields(), function (field) {
      field.addEventListener('change', function (e) {
        if (e.target && e.target.type === 'radio') { syncHighlight(field.querySelector('.model-options')); syncHidden(); }
      });
    });

    syncHighlight(provGroup);
    syncVisibility();
  }

  /* プロバイダ用以外のラジオ群（文字起こしエンジン等）のハイライト */
  function setupRadioHighlights(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('.model-options'), function (group) {
      if (group.classList.contains('provider-group') || group.closest('.provider-models')) return;
      group.addEventListener('change', function (e) {
        if (e.target && e.target.type === 'radio') syncHighlight(group);
      });
    });
  }

  /* お知らせの未読バッジ:
     最新お知らせのキー(APP_LATEST_NEWS)と、前回見たキー(localStorage)を比べて出し分ける。
     お知らせページ(APP_NEWS_PAGE)を開いたら既読にしてバッジを消す。 */
  function setupNewsBadge() {
    var latest = window.APP_LATEST_NEWS || '';
    var dot = document.getElementById('newsDot');
    var seen = '';
    try { seen = localStorage.getItem('subtitlesmith_news_seen') || ''; } catch (e) {}

    if (window.APP_NEWS_PAGE) {
      try { if (latest) localStorage.setItem('subtitlesmith_news_seen', latest); } catch (e) {}
      if (dot) dot.hidden = true;
      return;
    }
    if (dot && latest && seen !== latest) {
      dot.hidden = false;
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupNewsBadge);
  } else {
    setupNewsBadge();
  }

  window.APP_COMMON = {
    STATUS_LABELS: STATUS_LABELS,
    ICONS: ICONS,
    setupProviderSelect: setupProviderSelect,
    setupRadioHighlights: setupRadioHighlights
  };
})();
