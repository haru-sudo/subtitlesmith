#!/bin/bash
# GitHub Pages 用のデモ（docs/）を作り直す。
#   必要なもの: php（pdo_sqlite）, ffmpeg, python3
#   使い方: bash tools/demo/build.sh
#   公開先を変えるとき: DEMO_BASE=/myrepo DEMO_REPO_URL=https://github.com/me/myrepo bash tools/demo/build.sh
# 一時ディレクトリにサンプルの動画・DB・設定を作り、実際のアプリを起動して画面を書き出す。
# リポジトリの config/config.php は一時的に差し替え、終わったら元に戻す。
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$(pwd)
TMP=$(mktemp -d)
PORT=${DEMO_PORT:-8099}
# GitHub Pages の公開パス（https://<user>.github.io/<repo>/ の /<repo>）とリポジトリURL
export DEMO_BASE=${DEMO_BASE:-/subtitlesmith2}
export DEMO_REPO_URL=${DEMO_REPO_URL:-https://github.com/haru-sudo/subtitlesmith2}
CFG=config/config.php
BACKUP=""
cleanup() {
    [ -n "${PID:-}" ] && kill "$PID" 2>/dev/null || true
    if [ -n "$BACKUP" ]; then mv "$BACKUP" "$CFG"; else rm -f "$CFG"; fi
    rm -rf "$TMP"
}
trap cleanup EXIT
if [ -f "$CFG" ]; then BACKUP="$TMP/config.php.bak"; cp "$CFG" "$BACKUP"; fi

mkdir -p "$TMP/storage/videos" "$TMP/storage/thumbs" "$TMP/logs"
ffmpeg -loglevel error -y -f lavfi -i "gradients=s=854x480:d=24:speed=0.015:c0=0x1f4e79:c1=0x2e8b57:c2=0x6a3d9a:nb_colors=3,format=yuv420p" \
    -f lavfi -i "anullsrc=r=44100:cl=stereo" -t 24 -c:v libx264 -crf 32 -preset slow -movflags +faststart -c:a aac -b:a 32k "$TMP/storage/videos/demo1.mp4"
ffmpeg -loglevel error -y -f lavfi -i "gradients=s=854x480:d=18:speed=0.02:c0=0xb5651d:c1=0xd4a017:c2=0x8b0000:nb_colors=3,format=yuv420p" \
    -f lavfi -i "anullsrc=r=44100:cl=stereo" -t 18 -c:v libx264 -crf 32 -preset slow -movflags +faststart -c:a aac -b:a 32k "$TMP/storage/videos/demo2.mp4"
for n in 1 2; do
    ffmpeg -loglevel error -y -ss 2 -i "$TMP/storage/videos/demo$n.mp4" -frames:v 1 -vf scale=480:-2 "$TMP/storage/thumbs/demo$n.jpg"
done

HASH=$(php -r 'echo password_hash("pw", PASSWORD_DEFAULT);')
php -r '
$c = require "config/config.example.php";
$c["base_path"] = getenv("DEMO_BASE");
$c["source_url"] = getenv("DEMO_REPO_URL");
$c["storage_dir"] = $argv[1] . "/storage";
$c["log_dir"] = $argv[1] . "/logs";
$c["db_path"] = $argv[1] . "/storage/app.sqlite";
$c["password_users"] = [["email" => "demo@example.com", "name" => "デモユーザー", "password_hash" => $argv[2]]];
$c["oidc_providers"] = ["google" => ["preset" => "google", "client_id" => "demo", "client_secret" => "demo"]];
$c["operator_name"] = "subtitlesmith";
file_put_contents("config/config.php", "<?php\nreturn " . var_export($c, true) . ";\n");
' "$TMP" "$HASH"

php tools/demo/seed.php
php -S 127.0.0.1:$PORT index.php >"$TMP/server.log" 2>&1 &
PID=$!
sleep 1
DEMO_ORIGIN="http://127.0.0.1:$PORT" python3 tools/demo/build_demo.py "$TMP/storage"
