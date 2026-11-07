#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ローカルLLM翻訳（CTranslate2 + 小型instructモデル）。
PHP（App\\Translators\\LocalLLMTranslator）から exec で呼ばれる。DBには触れない（JSON受け渡しのみ）。

faster-whisper と同じ CTranslate2 系なので、共有レンタルサーバー（GPU無し・常駐不可）でも
「呼ばれて終わる」一発実行で動く。Ollama 等の常駐サーバーは不要。
instruct LLMなので、用語集・翻訳メモリ・文脈・校正の指示をそのまま理解して
{"translations":[{"id":..,"ja":".."}]} を返す（NMTより高品質）。

依存(本番サーバー/Anaconda env。faster-whisper を入れた env に追加でOK):
  pip install ctranslate2 "transformers[torch]" sentencepiece   # torch は初回のモデル変換で使う

入力(--input で渡すJSONファイル):
  {"system":"システム指示", "user":"原文＋用語集＋出力指示", "max_tokens":8000}

出力(stdout, JSON):
  {"ok": true,  "translations": [{"id": 12, "ja": "..."}, ...]}
  {"ok": false, "error": "..."}

使い方:
  python translate_llm_local.py --input /tmp/in.json \
      [--model Qwen/Qwen2.5-3B-Instruct] [--compute-type int8] \
      [--device cpu] [--cpu-threads 0] [--ct2-cache /path/to/cache]
  python translate_llm_local.py --input /tmp/in.json --mock   # 依存無しのダミー出力（PHP連携テスト用）
"""

import argparse
import json
import os
import re
import sys

if sys.version_info[0] < 3:
    sys.stdout.write(
        '{"ok": false, "error": "Python 3 required but Python 2 was used. '
        'config.php の python 設定を Python 3 のパスに変更してください。"}'
    )
    sys.exit(1)


def eprint(*a):
    sys.stderr.write(" ".join(str(x) for x in a) + "\n")


def extract_json_object(text):
    """生成テキストから最初の { ... } または [ ... ] を取り出して dict/list にする。失敗時 None。"""
    text = (text or "").strip()
    if not text:
        return None
    # コードフェンス除去
    text = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", text, flags=re.MULTILINE).strip()
    # まずそのままパース
    try:
        return json.loads(text)
    except Exception:
        pass
    # { か [ の最初の出現から対応する閉じ括弧まで切り出す
    open_chars = {"{": "}", "[": "]"}
    start = -1
    open_char = None
    for i, ch in enumerate(text):
        if ch in open_chars:
            start = i
            open_char = ch
            break
    if start < 0:
        return None
    close_char = open_chars[open_char]
    depth = 0
    in_str = False
    esc = False
    for i in range(start, len(text)):
        ch = text[i]
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue
        if ch == '"':
            in_str = True
        elif ch == open_char:
            depth += 1
        elif ch == close_char:
            depth -= 1
            if depth == 0:
                frag = text[start:i + 1]
                try:
                    return json.loads(frag)
                except Exception:
                    return None
    return None


def normalize_translations(obj):
    out = []
    # {"translations": [...]} 形式
    if isinstance(obj, dict) and isinstance(obj.get("translations"), list):
        rows = obj["translations"]
    # [{id, ja}, ...] の配列直接形式
    elif isinstance(obj, list):
        rows = obj
    else:
        return out
    for t in rows:
        if isinstance(t, dict) and "id" in t:
            # 訳文のキーは "ja" 固定だが（日本語に限らず「訳文」の意味）、
            # 訳出先が日本語以外だとモデルが text/target/translation で返すことがあるので拾う。
            text = ""
            for key in ("ja", "text", "target", "translation"):
                v = t.get(key)
                if isinstance(v, str) and v.strip():
                    text = v.strip()
                    break
            out.append({"id": int(t["id"]), "ja": text})
    return out


def mock_items_from_user(user_text):
    """mock用: ユーザープロンプト末尾の [{id,text},...] を拾う。"""
    pos = user_text.find("# 翻訳対象")
    scan = user_text[pos:] if pos >= 0 else user_text
    start = scan.find("[")
    if start < 0:
        return []
    depth = 0
    in_str = False
    esc = False
    end = -1
    for i in range(start, len(scan)):
        ch = scan[i]
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue
        if ch == '"':
            in_str = True
        elif ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                end = i
                break
    if end < 0:
        return []
    try:
        arr = json.loads(scan[start:end + 1])
    except Exception:
        return []
    return [{"id": int(x.get("id", 0)), "text": str(x.get("text", ""))} for x in arr if isinstance(x, dict) and "id" in x]


def ensure_ct2_model(model_name, ct2_cache, compute_type):
    """HFのLLMをCTranslate2形式へ変換してキャッシュ（初回のみ）。変換済みディレクトリを返す。"""
    import ctranslate2  # noqa: F401
    safe = re.sub(r"[^0-9A-Za-z._-]", "_", model_name)
    out_dir = os.path.join(ct2_cache, safe + "-" + compute_type)
    if os.path.isfile(os.path.join(out_dir, "model.bin")):
        return out_dir
    os.makedirs(ct2_cache, exist_ok=True)
    from ctranslate2.converters import TransformersConverter
    quant = compute_type if compute_type in (
        "int8", "int8_float16", "int8_float32", "float16", "bfloat16", "float32"
    ) else "int8"
    TransformersConverter(model_name).convert(out_dir, quantization=quant, force=True)
    return out_dir


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", help="入力JSONファイルの絶対パス")
    ap.add_argument("--model", default="Qwen/Qwen2.5-3B-Instruct",
                    help="instruct LLMのHF名。速度優先 Qwen/Qwen2.5-1.5B-Instruct、"
                         "品質優先 Qwen/Qwen2.5-7B-Instruct / Qwen/Qwen2.5-14B-Instruct。")
    ap.add_argument("--compute-type", default="int8",
                    help="計算精度。CPUは int8（省メモリ・高速）。メモリに余裕があれば float32 も可。GPUは float16。")
    ap.add_argument("--device", default="cpu", help="cpu / cuda / auto")
    ap.add_argument("--cpu-threads", type=int, default=0,
                    help="1翻訳に使うスレッド数(intra_threads)。0で自動。inter×intra≦総コア数 が目安。")
    ap.add_argument("--inter-threads", type=int, default=1,
                    help="同時に動かす翻訳の数(inter_threads)。その数だけモデルをメモリに載せる。")
    ap.add_argument("--ct2-cache", default="", help="CTranslate2 変換済みモデルの保存先ディレクトリ")
    ap.add_argument("--max-new-tokens", type=int, default=2048, help="生成トークン上限の絶対上限")
    ap.add_argument("--mock", action="store_true", help="依存無しのダミー出力")
    args = ap.parse_args()

    if not args.input or not os.path.isfile(args.input):
        print(json.dumps({"ok": False, "error": "--input のJSONファイルが見つかりません"}, ensure_ascii=False))
        return 1
    try:
        with open(args.input, "r", encoding="utf-8") as f:
            payload = json.load(f)
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": "入力JSONの読み込みに失敗: " + str(e)}, ensure_ascii=False))
        return 1

    system_text = str(payload.get("system", ""))
    user_text = str(payload.get("user", ""))
    max_tokens = int(payload.get("max_tokens", 2048) or 2048)
    max_tokens = max(64, min(max_tokens, args.max_new_tokens))

    if args.mock:
        items = mock_items_from_user(user_text)
        res = [{"id": it["id"], "ja": "【ローカルLLM訳】" + it["text"]} for it in items]
        print(json.dumps({"ok": True, "translations": res}, ensure_ascii=False))
        return 0

    try:
        import ctranslate2
        from transformers import AutoTokenizer
    except Exception as e:  # noqa: BLE001
        print(json.dumps({
            "ok": False,
            "error": "ローカルLLMの依存が見つかりません。"
                     "'pip install ctranslate2 transformers[torch] sentencepiece' を実行してください: " + str(e),
        }, ensure_ascii=False))
        return 1

    try:
        ct2_cache = args.ct2_cache or os.path.join(os.path.dirname(os.path.abspath(args.input)), "ct2_llm")
        model_dir = ensure_ct2_model(args.model, ct2_cache, args.compute_type)

        gen_kwargs = {"device": args.device, "compute_type": args.compute_type}
        if args.inter_threads and args.inter_threads > 0:
            gen_kwargs["inter_threads"] = args.inter_threads
        if args.cpu_threads and args.cpu_threads > 0:
            gen_kwargs["intra_threads"] = args.cpu_threads
        generator = ctranslate2.Generator(model_dir, **gen_kwargs)
        tokenizer = AutoTokenizer.from_pretrained(args.model, use_fast=True)

        messages = []
        if system_text:
            messages.append({"role": "system", "content": system_text})
        messages.append({"role": "user", "content": user_text})

        # tokenize=False でプレーンテキストに展開してから encode する。
        # apply_chat_template のデフォルト(tokenize=True)は一部バージョンで
        # torch をインポートしようとするため、torch 不要の経路を使う。
        prompt_text = tokenizer.apply_chat_template(
            messages,
            tokenize=False,
            add_generation_prompt=True,
        )
        input_ids = tokenizer.encode(prompt_text)
        tokens = tokenizer.convert_ids_to_tokens(input_ids)

        # 停止トークン（eos と、あればチャット終端）
        end_tokens = []
        if tokenizer.eos_token:
            end_tokens.append(tokenizer.eos_token)
        for t in ("<|im_end|>", "<|eot_id|>"):
            if t in tokenizer.get_vocab():
                end_tokens.append(t)

        results = generator.generate_batch(
            [tokens],
            max_length=max_tokens,
            include_prompt_in_result=False,
            sampling_temperature=0.3,
            sampling_topk=20,
            end_token=end_tokens or None,
        )
        out_tokens = results[0].sequences[0]
        out_ids = tokenizer.convert_tokens_to_ids(out_tokens)
        text = tokenizer.decode(out_ids, skip_special_tokens=True)

        obj = extract_json_object(text)
        translations = normalize_translations(obj)
        if not translations:
            print(json.dumps({
                "ok": False,
                "error": "LLM出力からJSONを抽出できませんでした。出力先頭: " + text[:200],
            }, ensure_ascii=False))
            return 1

        print(json.dumps({"ok": True, "translations": translations}, ensure_ascii=False))
        return 0
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    sys.exit(main())
