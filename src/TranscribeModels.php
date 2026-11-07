<?php

declare(strict_types=1);

namespace App;

/**
 * 文字起こし(STT)に使えるエンジンの一覧（コスト・精度の表示用メタ付き）。
 * 料金は音声1分あたりの米ドル。2026年時点の公開価格。
 *
 * - api系: OpenAIの /audio/transcriptions を使う（要 OpenAI APIキー）
 * - local: サーバー上の faster-whisper をPythonで実行（APIコスト0、要セットアップ）
 */
final class TranscribeModels
{
    /**
     * @return array<int,array{
     *   id:string, name:string, kind:string, cost_per_min:?float,
     *   accuracy:string, recommended:bool, note:string
     * }>
     */
    public static function list(): array
    {
        return [
            [
                'id' => 'deepgram-nova-3',
                'name' => 'Deepgram Nova-3',
                'kind' => 'deepgram',
                'cost_per_min' => 0.0043,
                'accuracy' => '最高',
                'recommended' => true,
                'note' => '既定。英語に強く、Whisper系のような無音での幻聴が出にくい。時刻つきで返り字幕向き。要 Deepgram APIキー。',
            ],
            [
                'id' => 'whisper-1',
                'name' => 'Whisper (whisper-1)',
                'kind' => 'api',
                'cost_per_min' => 0.006,
                'accuracy' => '高',
                'recommended' => false,
                'note' => 'OpenAIで唯一セグメント時刻を返すモデル。字幕のタイミングが音声と合う。',
            ],
            [
                'id' => 'gpt-4o-transcribe',
                'name' => 'GPT-4o Transcribe',
                'kind' => 'api',
                'cost_per_min' => 0.006,
                'accuracy' => '最高',
                'recommended' => false,
                'note' => '文字の精度は最高だがセグメント時刻を返さないため、字幕用途では whisper-1 で実行されます。',
            ],
            [
                'id' => 'gpt-4o-mini-transcribe',
                'name' => 'GPT-4o mini Transcribe',
                'kind' => 'api',
                'cost_per_min' => 0.003,
                'accuracy' => '高',
                'recommended' => false,
                'note' => '上記の半額。同じくセグメント時刻を返さないため、字幕用途では whisper-1 で実行されます。',
            ],
            [
                'id' => 'local-whisper',
                'name' => 'ローカルWhisper（コスト0）',
                'kind' => 'local',
                'cost_per_min' => 0.0,
                'accuracy' => '高',
                'recommended' => false,
                'note' => 'サーバー上で large-v3（最高精度）を実行しAPIコストはゼロ。GPU不要(多コアCPU向けに最適化済み)。要セットアップ(faster-whisper)。',
            ],
        ];
    }

    /** 既定の文字起こしエンジン（設定があればそれ、無ければ Deepgram Nova-3）。
     *  既定は時刻つきで返るエンジンにしておく。時刻を返さないエンジンだと
     *  字幕のタイミングが概算になり、動画の後半ほど音声とずれる。 */
    public static function default(): string
    {
        $fromSettings = (string) Settings::get('transcribe_model', '');
        if ($fromSettings !== '' && self::isValid($fromSettings)) {
            return $fromSettings;
        }
        return 'deepgram-nova-3';
    }

    public static function isValid(string $id): bool
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    public static function isLocal(string $id): bool
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return $m['kind'] === 'local';
            }
        }
        return false;
    }

    /** エンジンの種別（api / local / deepgram）を返す */
    public static function kind(string $id): string
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return $m['kind'];
            }
        }
        return 'api';
    }

    public static function label(string $id): string
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return $m['name'];
            }
        }
        return $id;
    }
}
