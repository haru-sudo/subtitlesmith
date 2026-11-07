#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ローカル文字起こし（faster-whisper）。
PHPワーカー(App Transcriber)から exec で呼ばれる。DBには触れない（JSON受け渡しのみ）。

APIコスト0で文字起こしする。サーバーにGPU/CPUと依存関係が必要。

多コアサーバー向け高速化:
  - OCRセグメント境界モードは、各セグメントを「プロセス並列」で文字起こしする
    （各セグメントは元々独立処理＝condition_on_previous_text=False なので結果は同一）。
    --workers でプロセス数を指定。多コア環境では cores/(per-process threads) 程度まで上げられる。
  - 通常モードは --batched 指定時 BatchedInferencePipeline で30秒窓をバッチ並列化（任意）。

依存(本番サーバー/Anaconda env 推奨):
  pip install faster-whisper

使い方:
  python transcribe_local.py --audio /path/to/audio.mp3 [--lang en] \
      [--model large-v3-turbo] [--compute-type float32] [--device cpu] \
      [--cpu-threads 0] [--workers 1] [--beam-size 5]
  python transcribe_local.py --mock          # 依存無しでダミー出力（PHP連携テスト用）

出力(stdout, JSON):
  {"ok": true, "language": "en", "segments": [{"start_ms":0,"end_ms":1200,"text":"..."}, ...]}
  {"ok": false, "error": "..."}
"""

import argparse
import json
import sys
import os

if sys.version_info[0] < 3:
    sys.stderr.write("Python 3 required.\n")
    sys.stdout.write('{"ok": false, "error": "Python 3 required."}')
    sys.exit(1)


def eprint(*a):
    sys.stderr.write(" ".join(str(x) for x in a) + "\n")


# =============================================================================
# プロセス並列ワーカー（OCRセグメント境界モード用）
#   ProcessPoolExecutor から呼ぶため、関数とモデルはモジュール最上位に置く。
#   各子プロセスは初期化時にモデルを1回だけロードして使い回す。
# =============================================================================
_W = {}  # 子プロセスごとのグローバル状態（model, ffmpeg, audio, lang, beam）


def _worker_init(audio, ffmpeg, model_name, compute_type, device, cpu_threads, beam, use_lang):
    # 多数プロセス起動時にBLAS/OpenMPがプロセスごとに大量スレッドを張るのを防ぐ
    # （オーバーサブスクリプションによる速度低下・不安定化の予防）。
    t = str(max(1, int(cpu_threads or 1)))
    for k in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS",
              "NUMEXPR_NUM_THREADS", "VECLIB_MAXIMUM_THREADS"):
        os.environ.setdefault(k, t)
    from faster_whisper import WhisperModel
    kwargs = {"device": device, "compute_type": compute_type}
    if cpu_threads and cpu_threads > 0:
        kwargs["cpu_threads"] = cpu_threads
    _W["model"] = WhisperModel(model_name, **kwargs)
    _W["audio"] = audio
    _W["ffmpeg"] = ffmpeg
    _W["beam"] = beam
    _W["lang"] = use_lang


def _load_audio_slice(ffmpeg, path, start_s, end_s, sr=16000):
    import subprocess
    import numpy as np
    dur = max(0.0, end_s - start_s)
    cmd = [
        ffmpeg, "-nostdin", "-threads", "1",
        "-ss", "%.3f" % start_s, "-i", path, "-t", "%.3f" % dur,
        "-f", "f32le", "-ac", "1", "-ar", str(sr), "-",
    ]
    try:
        proc = subprocess.run(cmd, stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, check=True)
        return np.frombuffer(proc.stdout, dtype=np.float32)
    except Exception:
        return None


def _worker_transcribe_slice(task):
    """task = (index, start_ms, end_ms) -> (index, text)。子プロセスで実行。"""
    idx, start_ms, end_ms = task
    start_s = int(start_ms) / 1000.0
    end_s = int(end_ms) / 1000.0
    if end_s - start_s < 0.2:
        return (idx, "")
    audio = _load_audio_slice(_W["ffmpeg"], _W["audio"], start_s, end_s)
    if audio is None or audio.size == 0:
        return (idx, "")
    try:
        seg_iter, _ = _W["model"].transcribe(
            audio,
            language=_W["lang"],
            beam_size=max(1, _W["beam"]),
            condition_on_previous_text=False,
            vad_filter=False,
        )
        texts = [s.text.strip() for s in seg_iter if (s.text or "").strip()]
        return (idx, " ".join(texts))
    except Exception:
        return (idx, "")


def _run_sequential(args, tasks, use_lang):
    """メインプロセスでモデルを1つだけ使って直列実行（省メモリ・フォールバック用）。"""
    _worker_init(args.audio, args.ffmpeg, args.model, args.compute_type,
                 args.device, args.cpu_threads, args.beam_size, use_lang)
    return [_worker_transcribe_slice(t) for t in tasks]


def _transcribe_ocr_segments(args, ocr_segs, use_lang):
    """OCRセグメントを並列に文字起こしして、順序を保ったまま返す。"""
    from concurrent.futures import ProcessPoolExecutor

    tasks = []
    for i, seg in enumerate(ocr_segs):
        try:
            tasks.append((i, int(seg["start_ms"]), int(seg["end_ms"])))
        except Exception:
            continue

    workers = max(1, int(args.workers))
    total = len(tasks)

    def _emit(done):
        if total > 0:
            sys.stderr.write("@PROGRESS %d\n" % int(min(100, done * 100 // total)))
            sys.stderr.flush()

    # 1セグメントしか無い/並列無効なら直列で実行（プロセス起動コストを避ける）
    if workers <= 1 or len(tasks) <= 1:
        results = []
        _worker_init(args.audio, args.ffmpeg, args.model, args.compute_type,
                     args.device, args.cpu_threads, args.beam_size, use_lang)
        for n, t in enumerate(tasks, 1):
            results.append(_worker_transcribe_slice(t))
            _emit(n)
    else:
        workers = min(workers, len(tasks))
        # スレッド過剰割当を防ぐため、1プロセスあたりのスレッドを総数/プロセス数に按分
        per_proc_threads = max(1, (int(args.cpu_threads) or workers) // workers)
        # Linuxでは fork を明示（importlib/__main__ 由来の関数でも確実にpickle可能・起動も速い）。
        import multiprocessing
        try:
            ctx = multiprocessing.get_context("fork")
        except ValueError:
            ctx = None
        pool_kwargs = dict(
            max_workers=workers,
            initializer=_worker_init,
            initargs=(args.audio, args.ffmpeg, args.model, args.compute_type,
                      args.device, per_proc_threads, args.beam_size, use_lang),
        )
        if ctx is not None:
            pool_kwargs["mp_context"] = ctx
        try:
            results = []
            with ProcessPoolExecutor(**pool_kwargs) as ex:
                for n, r in enumerate(ex.map(_worker_transcribe_slice, tasks, chunksize=1), 1):
                    results.append(r)
                    _emit(n)
        except Exception as e:
            # 子プロセスがメモリ不足等で落ちると BrokenProcessPool になる。
            # 動画を失敗させず、直列にフォールバックして必ず完了させる。
            eprint("並列文字起こしに失敗したため直列にフォールバックします: " + str(e))
            _W.clear()
            results = []
            _worker_init(args.audio, args.ffmpeg, args.model, args.compute_type,
                         args.device, args.cpu_threads, args.beam_size, use_lang)
            for n, t in enumerate(tasks, 1):
                results.append(_worker_transcribe_slice(t))
                _emit(n)

    by_idx = {idx: text for (idx, text) in results}
    segments = []
    for i, seg in enumerate(ocr_segs):
        text = by_idx.get(i, "")
        if text:
            segments.append({
                "start_ms": int(seg["start_ms"]),
                "end_ms": int(seg["end_ms"]),
                "text": text,
            })
    return segments


def _transcribe_full(model, audio, language, beam, condition_previous=False):
    """通常モードの全体文字起こし。

    condition_previous(=condition_on_previous_text) は既定 False。
    True だと長尺で自分の直前出力に引きずられ、誤り・繰り返し・幻聴(ハルシネーション)が
    後半ほど蓄積して、文字起こしとセグメント境界の精度が動画後半で悪化しやすい。
    （翻訳側は前後文脈を別途渡すため、ここを False にしても訳の繋がりは損なわれない。）
    短く綺麗な音声で窓またぎの一貫性を優先したい場合のみ True にする。
    繰り返し抑制(no_repeat_ngram_size)や無音ハルシネーション対策は、
    古い faster-whisper では未対応のため、対応する範囲で段階的に付与する。
    """
    base = dict(
        language=language,
        vad_filter=True,
        beam_size=max(1, beam),
        condition_on_previous_text=bool(condition_previous),
    )
    for extra in (
        dict(no_repeat_ngram_size=3, hallucination_silence_threshold=2.0),
        dict(no_repeat_ngram_size=3),
        dict(),
    ):
        try:
            return model.transcribe(audio, **base, **extra)
        except TypeError:
            continue
    return model.transcribe(audio, **base)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--audio")
    ap.add_argument("--lang", default="auto")
    ap.add_argument("--model", default="large-v3-turbo")
    ap.add_argument("--compute-type", default="float32")
    ap.add_argument("--device", default="cpu")
    ap.add_argument("--cpu-threads", type=int, default=0,
                    help="ctranslate2のスレッド数。並列モードでは1プロセスあたりに按分される。")
    ap.add_argument("--num-workers", type=int, default=1,
                    help="(旧)モデル内ワーカー。通常は --workers を使う。")
    ap.add_argument("--workers", type=int, default=1,
                    help="セグメント並列のプロセス数。多コア環境で大幅高速化。既定1=従来動作。")
    ap.add_argument("--batched", action="store_true",
                    help="通常モードでBatchedInferencePipelineを使う（任意・高速だが窓間文脈は無効）。")
    ap.add_argument("--batch-size", type=int, default=16,
                    help="--batched 時のバッチサイズ。")
    ap.add_argument("--beam-size", type=int, default=5)
    ap.add_argument("--condition-previous", type=int, default=0,
                    help="直前テキストを次の窓のプロンプトにするか(1=する/0=しない)。"
                         "長尺は0推奨(後半の崩れ防止)。短く綺麗な音声の一貫性重視なら1。")
    ap.add_argument("--ocr-segments", type=str, default="")
    ap.add_argument("--ffmpeg", type=str, default="ffmpeg")
    ap.add_argument("--mock", action="store_true")
    args = ap.parse_args()

    if args.mock:
        print(json.dumps({
            "ok": True, "language": "en",
            "segments": [
                {"start_ms": 0, "end_ms": 1500, "text": "This is a mock transcription."},
                {"start_ms": 1500, "end_ms": 3000, "text": "Open the settings page first."},
            ],
        }, ensure_ascii=False))
        return 0

    if not args.audio:
        print(json.dumps({"ok": False, "error": "--audio is required"}))
        return 1

    try:
        from faster_whisper import WhisperModel
    except Exception as e:  # noqa: BLE001
        print(json.dumps({
            "ok": False,
            "error": "faster-whisper が見つかりません。'pip install faster-whisper' を実行してください: " + str(e),
        }, ensure_ascii=False))
        return 1

    try:
        language = None if args.lang in ("auto", "", None) else args.lang

        ocr_segs = []
        if args.ocr_segments:
            try:
                ocr_segs = json.loads(args.ocr_segments)
            except Exception:
                ocr_segs = []

        if ocr_segs:
            # --- OCRセグメント境界モード（プロセス並列） ---
            # 言語自動判定は先頭付近で1回だけ（親プロセスで実施）。
            detected_lang = args.lang
            if language is None:
                kwargs = {"device": args.device, "compute_type": args.compute_type}
                if args.cpu_threads and args.cpu_threads > 0:
                    kwargs["cpu_threads"] = args.cpu_threads
                det_model = WhisperModel(args.model, **kwargs)
                head = _load_audio_slice(args.ffmpeg, args.audio, 0.0, 30.0)
                if head is not None and head.size > 0:
                    try:
                        _, info = det_model.transcribe(head, beam_size=1, vad_filter=False)
                        detected_lang = getattr(info, "language", args.lang) or "auto"
                    except Exception:
                        detected_lang = "auto"
                del det_model
            use_lang = None if detected_lang in ("auto", "", None) else detected_lang

            segments = _transcribe_ocr_segments(args, ocr_segs, use_lang)
            print(json.dumps({"ok": True, "language": detected_lang, "segments": segments},
                             ensure_ascii=False))
            return 0

        # --- 通常モード（OCRセグメントなし） ---
        kwargs = {"device": args.device, "compute_type": args.compute_type}
        if args.cpu_threads and args.cpu_threads > 0:
            kwargs["cpu_threads"] = args.cpu_threads
        if args.num_workers and args.num_workers > 1:
            kwargs["num_workers"] = args.num_workers
        model = WhisperModel(args.model, **kwargs)

        if args.batched:
            try:
                from faster_whisper import BatchedInferencePipeline
                pipe = BatchedInferencePipeline(model=model)
                segments_iter, info = pipe.transcribe(
                    args.audio, language=language,
                    beam_size=max(1, args.beam_size),
                    batch_size=max(1, args.batch_size),
                    vad_filter=True,
                )
            except Exception:
                # 利用不可なら従来のストリーミングにフォールバック
                segments_iter, info = _transcribe_full(model, args.audio, language, args.beam_size, args.condition_previous)
        else:
            segments_iter, info = _transcribe_full(model, args.audio, language, args.beam_size, args.condition_previous)

        raw_segments = []
        _dur = float(getattr(info, "duration", 0) or 0)
        _last = -1
        for seg in segments_iter:
            text = (seg.text or "").strip()
            if _dur > 0:
                p = int(min(99, (float(seg.end) / _dur) * 100))
                if p != _last:
                    _last = p
                    sys.stderr.write("@PROGRESS %d\n" % p)
                    sys.stderr.flush()
            if not text:
                continue
            raw_segments.append({
                "start_ms": int(round(seg.start * 1000)),
                "end_ms": int(round(seg.end * 1000)),
                "text": text,
            })

        def _remove_overlap(prev, cur):
            if not prev or not cur:
                return cur
            pw, cw = prev.split(), cur.split()
            best = 0
            for n in range(1, min(len(pw), len(cw) - 1) + 1):
                if pw[-n:] == cw[:n]:
                    best = n
            return " ".join(cw[best:]) if best > 0 else cur

        segments = []
        for i, seg in enumerate(raw_segments):
            text = seg["text"]
            if i > 0:
                text = _remove_overlap(raw_segments[i - 1]["text"], text)
            if not text:
                continue
            segments.append({"start_ms": seg["start_ms"], "end_ms": seg["end_ms"], "text": text})

        print(json.dumps({
            "ok": True,
            "language": getattr(info, "language", args.lang) or "auto",
            "segments": segments,
        }, ensure_ascii=False))
        return 0
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    sys.exit(main())
