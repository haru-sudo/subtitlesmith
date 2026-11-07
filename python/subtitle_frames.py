#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
焼き込み字幕の「切り替わり」を画像差分で検出し、各字幕区間の代表フレーム(字幕帯)を
PNGで書き出す。OCRはしない（読み取りは後段でClaude Visionが行う）。

切り替わり検出はOCR精度に依存せず、字幕帯のピクセル差分のみで判定する。

字幕は画面下部に「白文字＋黒縁取り」で1〜数行表示される。スライドの図版や
本文テキストが帯に入り込むと誤検出するため、次の2段で字幕だけを取り出す:
  1) 縁取り白文字マスク + 文字サイズでない大きな塊を連結成分で除去
  2) 下端から続く「字幕行ブロック」だけを自動抽出（行数1〜4でも全行を含め、
     上方に離れた図版は除外）。ブロックは行数に応じて伸縮するので、背の高い
     字幕でも上の行が切れない。

出力(stdout JSON):
  {"ok": true, "duration_ms": int,
   "segments": [{"start_ms": int, "end_ms": int, "image": "/abs/path/seg_0001.png"}, ...]}

使い方:
  python subtitle_frames.py --video in.mp4 --outdir /tmp/subs [--interval 0.5]
        [--crop-top 0.5] [--crop-bottom 1.0] [--diff-threshold 0.28] [--ffmpeg ffmpeg]
"""

import sys, os, json, argparse

try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass


def emit(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False))
    sys.stdout.flush()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--video")
    ap.add_argument("--outdir", required=False, default="")
    ap.add_argument("--interval", type=float, default=0.5)
    # 解析の「外枠」。字幕は下部に出るので下半分を見る。実際の字幕高は内部で自動検出する。
    ap.add_argument("--crop-top", type=float, default=0.5)
    ap.add_argument("--crop-bottom", type=float, default=1.0,
                    help="解析帯の下端(0-1)。既定は最下部まで。")
    ap.add_argument("--diff-threshold", type=float, default=0.28,
                    help="字幕の相違度(IoU基準,0-1)がこの値を超えたら別字幕とみなす")
    ap.add_argument("--min-duration-ms", type=int, default=1000,
                    help="この時間未満の区間は隣接区間にマージする(割れ過ぎ防止)")
    ap.add_argument("--min-text-ratio", type=float, default=0.0015,
                    help="字幕行ブロックに縁取り白文字がこの割合未満なら『字幕なし』とみなす")
    ap.add_argument("--ffmpeg", default="ffmpeg")
    ap.add_argument("--mock", action="store_true")
    args = ap.parse_args()

    if args.mock:
        emit({"ok": True, "duration_ms": 3000, "segments": []})
        return
    if not args.video:
        emit({"ok": False, "error": "--video が指定されていません。"})
        return

    try:
        import cv2
        import numpy as np
    except Exception as e:
        emit({"ok": False, "error": "必要なライブラリ未導入: %s (pip install opencv-python-headless)" % e})
        return

    outdir = args.outdir or os.path.join(os.path.dirname(args.video), "_subframes")
    os.makedirs(outdir, exist_ok=True)

    cap = cv2.VideoCapture(args.video)
    if not cap.isOpened():
        emit({"ok": False, "error": "動画を開けません: %s" % args.video})
        return
    fps = cap.get(cv2.CAP_PROP_FPS) or 25.0
    frame_count = cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0
    duration_ms = int((frame_count / fps) * 1000) if fps > 0 else 0

    step_ms = max(100, int(args.interval * 1000))

    _glyph_k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 7))

    def band(frame):
        h = frame.shape[0]
        top = max(0, min(h - 1, int(h * args.crop_top)))
        bot = max(top + 1, min(h, int(h * args.crop_bottom)))
        return frame[top:bot, :]

    def outlined_mask(crop):
        """縁取り付き白文字のマスク（白の近傍に黒があるものだけ残す）。"""
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        white = (gray > 200).astype(np.uint8) * 255
        black = (gray < 60).astype(np.uint8) * 255
        black_dil = cv2.dilate(black, _glyph_k, iterations=1)
        return cv2.bitwise_and(white, black_dil)

    def drop_big_blobs(mask):
        """写真・図版・枠線のような文字でない大きな塊を除去し、文字サイズの成分だけ残す。"""
        bh = mask.shape[0]
        n, labels, stats, _ = cv2.connectedComponentsWithStats(mask, connectivity=8)
        if n <= 1:
            return mask
        out = np.zeros_like(mask)
        max_h = max(8, int(bh * 0.28))   # 1文字が帯(下半分)高の28%を超えることはない
        for i in range(1, n):
            x, y, w, h, area = stats[i]
            if area < 3:
                continue
            if h > max_h:                # 縦に大きすぎ → 図版・縦線・人物の輪郭
                continue
            fill = area / float(max(1, w * h))
            if w > bh * 0.8 and fill > 0.55:   # 横長のベタ塗り → 帯・図形
                continue
            out[labels == i] = 255
        return out

    def bottom_text_block(mask):
        """
        下端から続く字幕行ブロックの上端 row を返す。字幕なしなら None。
        - 文字行(密度の高い行が続く塊=line band)を検出する
        - 最下部付近に行があること（下端アンカー）を必須にする
        - 下から、行と行の隙間が「文字サイズに応じた閾値」以下の間だけ連結する。
          行送り程度の隙間は跨ぎ、図版との大きな隙間で止める。
          → 行高(フォント)に自動追従し、1〜数行のどの高さでも全行を含み、上方の図版は除外。
        """
        bh = mask.shape[0]
        rows = (mask > 0).sum(axis=1).astype(np.float32)
        peak = float(rows.max())
        if peak <= 0:
            return None
        line_thr = max(2.0, peak * 0.10)
        is_text = rows >= line_thr

        # 下端アンカー: 最下部 35% に文字行が無ければ字幕なし
        if not is_text[int(bh * 0.65):].any():
            return None

        # 行の塊(line band)を下→上の順で抽出: [(top,bottom), ...]
        bands = []
        y = bh - 1
        while y >= 0:
            if is_text[y]:
                b = y
                while y >= 0 and is_text[y]:
                    y -= 1
                t = y + 1
                bands.append((t, b))
            else:
                y -= 1
        if not bands:
            return None

        # 最下段の塊から上へ、隙間が小さい間だけ連結
        top, _bot = bands[0]
        line_h = bands[0][1] - bands[0][0] + 1
        cnt = 1
        for i in range(1, len(bands)):
            t, b = bands[i]
            gap = top - b - 1                     # 直上の塊との隙間
            avg_h = line_h / cnt
            allow = max(int(bh * 0.025), int(avg_h * 1.4))   # 行送り程度は許容
            if gap <= allow:
                top = t
                line_h += (b - t + 1)
                cnt += 1
            else:
                break                             # 図版など大きな隙間 → ここで止める
        return top

    # 比較用キャンバス。動画内ではフォントサイズが一定なので、全幅を固定倍率で縮小し
    # （アスペクト維持）、下端そろえで固定高キャンバスに貼る。縦を引き伸ばさないので、
    # 上端が数px揺れても全体が再スケールされず、誤検出しない。
    CANVAS_W, CANVAS_H = 640, 220

    def to_canvas(block):
        bh, bw = block.shape[:2]
        if bw <= 0 or bh <= 0:
            return np.zeros((CANVAS_H, CANVAS_W), np.uint8)
        scale = CANVAS_W / float(bw)          # bw=全幅(動画内で一定) → scaleも一定
        nh = max(1, int(round(bh * scale)))
        r = cv2.resize(block, (CANVAS_W, nh), interpolation=cv2.INTER_AREA)
        _, r = cv2.threshold(r, 64, 255, cv2.THRESH_BINARY)
        cv = np.zeros((CANVAS_H, CANVAS_W), np.uint8)
        if nh > CANVAS_H:                     # 想定超の高さは上を切る(下端=字幕末尾を優先)
            r = r[nh - CANVAS_H:, :]
            nh = CANVAS_H
        cv[CANVAS_H - nh:CANVAS_H, :] = r     # 下端そろえ
        return cv

    def analyze(crop):
        """
        1フレーム分の解析。
        戻り: (has_text, canvas_mask(比較用), block_crop(PNG用) or None)
        """
        m = outlined_mask(crop)
        m = drop_big_blobs(m)
        top = bottom_text_block(m)
        if top is None:
            return (False, None, None)
        block = m[top:, :]
        ratio = float(np.count_nonzero(block)) / float(max(1, block.size))
        if ratio < args.min_text_ratio:
            return (False, None, None)
        canvas = to_canvas(block)
        # PNGは少し上に余白を足して字幕全体を確実に含める
        pad = max(2, int(crop.shape[0] * 0.03))
        ctop = max(0, top - pad)
        block_crop = crop[ctop:, :]
        return (True, canvas, block_crop)

    # --- パス1: 全サンプルを解析 ---
    samples = []  # [{"ms","has_text","mask"(small),"crop"(block)}]
    t = 0
    _last_pct = -1
    while t <= duration_ms + step_ms:
        cap.set(cv2.CAP_PROP_POS_MSEC, t)
        ok, frame = cap.read()
        if not ok or frame is None:
            break
        has_text, sm, block_crop = analyze(band(frame))
        samples.append({"ms": t, "has_text": has_text, "mask": sm, "crop": block_crop})
        t += step_ms
        # 進捗をstderrへ（PHPが逐次読んでバー更新）。パス1を0-95%に割当。
        if duration_ms > 0:
            pct = int(min(95, (t / float(duration_ms)) * 95))
            if pct != _last_pct:
                _last_pct = pct
                sys.stderr.write("@PROGRESS %d\n" % pct)
                sys.stderr.flush()

    if not samples:
        cap.release()
        emit({"ok": True, "duration_ms": duration_ms, "segments": []})
        return

    _jit_k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))

    def diff_ratio(a, b):
        if a is None or b is None:
            return 1.0
        ad = cv2.dilate(a, _jit_k, iterations=1)
        bd = cv2.dilate(b, _jit_k, iterations=1)
        only_a = cv2.bitwise_and(a, cv2.bitwise_not(bd))
        only_b = cv2.bitwise_and(b, cv2.bitwise_not(ad))
        diff = np.count_nonzero(only_a) + np.count_nonzero(only_b)
        union = np.count_nonzero(cv2.bitwise_or(a, b))
        if union == 0:
            return 0.0
        return float(diff) / float(union)

    # --- パス2: 切り替わり判定（2サンプル連続で変化したら確定）---
    raw = []
    cur = None
    pending = None

    def close(end_ms):
        nonlocal cur
        if cur is not None:
            raw.append({"start_ms": cur["start_ms"], "end_ms": end_ms, "crop": cur["crop"]})
            cur = None

    for s in samples:
        if not s["has_text"]:
            close(s["ms"])
            pending = None
            continue
        if cur is None:
            cur = {"start_ms": s["ms"], "ref_mask": s["mask"], "crop": s["crop"]}
            pending = None
            continue
        d = diff_ratio(s["mask"], cur["ref_mask"])
        if d > args.diff_threshold:
            if pending is None:
                pending = {"ms": s["ms"], "mask": s["mask"], "crop": s["crop"]}
            else:
                close(pending["ms"])
                cur = {"start_ms": pending["ms"], "ref_mask": pending["mask"], "crop": pending["crop"]}
                pending = None
        else:
            pending = None
    close(samples[-1]["ms"] + step_ms)

    # --- パス3: 最小表示時間に満たない区間を隣接にマージ ---
    min_dur = max(step_ms, int(args.min_duration_ms))
    merged = []
    for seg in raw:
        if merged and (seg["start_ms"] - merged[-1]["end_ms"]) <= step_ms \
                and (merged[-1]["end_ms"] - merged[-1]["start_ms"]) < min_dur:
            merged[-1]["end_ms"] = seg["end_ms"]
        else:
            merged.append(dict(seg))
    if len(merged) >= 2 and (merged[-1]["end_ms"] - merged[-1]["start_ms"]) < min_dur \
            and (merged[-1]["start_ms"] - merged[-2]["end_ms"]) <= step_ms:
        merged[-2]["end_ms"] = merged[-1]["end_ms"]
        merged.pop()

    # --- 書き出し ---
    segments = []
    for idx, seg in enumerate(merged, 1):
        path = os.path.join(outdir, "seg_%04d.png" % idx)
        if seg["crop"] is not None:
            cv2.imwrite(path, seg["crop"])
        segments.append({
            "start_ms": seg["start_ms"],
            "end_ms": seg["end_ms"],
            "image": os.path.abspath(path),
        })

    cap.release()
    emit({"ok": True, "duration_ms": duration_ms, "segments": segments})


if __name__ == "__main__":
    main()
