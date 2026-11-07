<?php

declare(strict_types=1);

namespace App\Translators;

/**
 * 翻訳プロバイダ共通インターフェース。
 *
 * 入力はプレーンテキストのシステム指示とユーザープロンプト、
 * 出力は { "translations": [ {"id":int,"ja":string}, ... ] } を表す連想配列。
 * 各実装が自分のAPI形式・JSON抽出を吸収する。
 */
interface TranslatorInterface
{
    /**
     * @param string $systemText システム指示（翻訳ルール）
     * @param string $userText   ユーザープロンプト（原文＋用語集＋出力指示）
     * @param int    $maxTokens  応答の最大トークン
     * @param string $modelOverride 空でなければモデルを上書き
     * @return array JSONをデコードした連想配列（失敗時は空配列）
     */
    public function translateJson(string $systemText, string $userText, int $maxTokens, string $modelOverride = ''): array;

    /** このプロバイダが利用可能か（キー等が設定済みか） */
    public function isConfigured(): bool;

    /** 表示用のプロバイダ名 */
    public function name(): string;
}
