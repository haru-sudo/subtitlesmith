#!/bin/bash
# =====================================================================
# 既存動画を一括で「ブラウザ即再生できる形」に変換するスクリプト
#
#   - moov atom を先頭へ移動（faststart）→ ブラウザで即再生・直接配信に対応
#   - 音声を AAC に変換 → どの環境でも音が鳴る
#   - 映像はコピー（無劣化・高速）
#
# 使い方（SSHでサーバーにログインして実行）:
#   cd /path/to/subtitlesmith      ← 設置場所に合わせて変更
#   bash deploy/faststart_existing.sh storage/videos
#
#   ※ 第1引数で動画ディレクトリを指定（省略時は storage/videos）
#
# 特徴:
#   - すでに faststart 済みの動画は自動スキップ（無駄な再変換をしない）
#   - 変換は一時ファイルで行い、成功時のみ元ファイルと置き換え（失敗しても元は無事）
#   - 中断しても、もう一度実行すれば未処理ぶんだけ続行
# =====================================================================

set -u

VIDEO_DIR="${1:-storage/videos}"

# ffmpeg / ffprobe を探す（共有サーバーでは PATH に無いことがあるので候補を順に探す）
find_bin() {
    local name="$1"
    if command -v "$name" >/dev/null 2>&1; then
        command -v "$name"
        return 0
    fi
    for p in /usr/bin/"$name" /usr/local/bin/"$name" "$HOME"/bin/"$name" /opt/ffmpeg/bin/"$name"; do
        if [ -x "$p" ]; then
            echo "$p"
            return 0
        fi
    done
    return 1
}

FFMPEG="$(find_bin ffmpeg || true)"
FFPROBE="$(find_bin ffprobe || true)"

if [ -z "${FFMPEG:-}" ]; then
    echo "エラー: ffmpeg が見つかりません。フルパスを FFMPEG=/path/to/ffmpeg のように指定して再実行してください。"
    exit 1
fi

if [ ! -d "$VIDEO_DIR" ]; then
    echo "エラー: ディレクトリが見つかりません: $VIDEO_DIR"
    echo "subtitlesmith の設置場所で実行しているか、引数のパスを確認してください。"
    exit 1
fi

echo "対象ディレクトリ: $VIDEO_DIR"
echo "ffmpeg: $FFMPEG"
echo "ffprobe: ${FFPROBE:-(なし: 最適化判定をスキップして全件変換します)}"
echo "----------------------------------------------------------------"

# moov atom が先頭にあるか（faststart 済みか）を判定する。
# ffprobe があれば、先頭付近で moov を読めるかで簡易判定する。
is_faststart() {
    local f="$1"
    [ -z "${FFPROBE:-}" ] && return 1   # ffprobe無しなら「未済」とみなして変換する
    # 先頭1MBだけ読ませて、コンテナ情報が取れる＝moovが先頭側にある、とみなす簡易判定
    "$FFPROBE" -v error -read_intervals "%+#1" \
        -show_entries format=duration -of csv=p=0 "$f" >/dev/null 2>&1
    return $?
}

total=0
converted=0
skipped=0
failed=0

# mp4 / mov / m4v / mkv / webm / avi を対象にする
shopt -s nullglob nocaseglob
for f in "$VIDEO_DIR"/*.mp4 "$VIDEO_DIR"/*.mov "$VIDEO_DIR"/*.m4v "$VIDEO_DIR"/*.mkv "$VIDEO_DIR"/*.webm "$VIDEO_DIR"/*.avi; do
    [ -f "$f" ] || continue
    # 一時ファイルや変換中ファイルは対象外
    case "$f" in
        *.faststart.mp4) continue ;;
    esac

    total=$((total + 1))
    name="$(basename "$f")"

    if is_faststart "$f"; then
        echo "[スキップ] 最適化済み: $name"
        skipped=$((skipped + 1))
        continue
    fi

    tmp="${f}.faststart.mp4"
    echo "[変換中 ] $name ..."

    "$FFMPEG" -y -i "$f" \
        -map 0:v? -map 0:a? \
        -c:v copy -c:a aac -b:a 192k \
        -movflags +faststart \
        "$tmp" >/dev/null 2>&1

    if [ $? -eq 0 ] && [ -s "$tmp" ]; then
        # 成功 → 元を置き換える（拡張子が mp4 以外でも中身はMP4にする方が安全だが、
        #        既存の file_path を壊さないため、ファイル名はそのままにする）
        mv -f "$tmp" "$f"
        echo "[完了   ] $name"
        converted=$((converted + 1))
    else
        rm -f "$tmp"
        echo "[失敗   ] $name （元ファイルはそのままです）"
        failed=$((failed + 1))
    fi
done

echo "----------------------------------------------------------------"
echo "対象: $total 件  /  変換: $converted 件  /  スキップ: $skipped 件  /  失敗: $failed 件"
echo "完了しました。"
