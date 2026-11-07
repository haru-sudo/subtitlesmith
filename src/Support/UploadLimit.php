<?php

declare(strict_types=1);

namespace App\Support;

/**
 * サーバーが実際に許容しているアップロードサイズ上限を取得するユーティリティ。
 * php.ini の upload_max_filesize / post_max_size から、実効上限（小さい方）を求める。
 * 値は決め打ちせず、必ず ini_get() で実行中のPHPから取得する。
 */
final class UploadLimit
{
    /** 実効上限（バイト）。post_max_size と upload_max_filesize の小さい方。 */
    public static function effectiveBytes(): int
    {
        $upload = self::iniToBytes((string) ini_get('upload_max_filesize'));
        $post   = self::iniToBytes((string) ini_get('post_max_size'));

        // post_max_size = 0 は「無制限」を意味する（PHPの仕様）。その場合は upload 側を採用。
        if ($post <= 0) {
            return $upload;
        }
        if ($upload <= 0) {
            return $post;
        }
        return min($upload, $post);
    }

    /** 実効上限を「○○GB」のような日本語の読みやすい表記にする。 */
    public static function effectiveHuman(): string
    {
        return self::humanReadable(self::effectiveBytes());
    }

    /** PHPのini値（例: "50G", "2048M", "512000K", "1000000"）をバイト数に変換する。 */
    public static function iniToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $last = strtolower(substr($value, -1));
        $num = (float) $value;
        switch ($last) {
            case 'g':
                return (int) round($num * 1024 ** 3);
            case 'm':
                return (int) round($num * 1024 ** 2);
            case 'k':
                return (int) round($num * 1024);
            default:
                return (int) round($num);
        }
    }

    /** バイト数を人間が読みやすい単位（GB優先・小数1桁）に整形する。 */
    public static function humanReadable(int $bytes): string
    {
        if ($bytes <= 0) {
            return '無制限';
        }
        $gb = $bytes / (1024 ** 3);
        if ($gb >= 1) {
            $formatted = rtrim(rtrim(number_format($gb, 1), '0'), '.');
            return $formatted . 'GB';
        }
        $mb = $bytes / (1024 ** 2);
        $formatted = rtrim(rtrim(number_format($mb, 1), '0'), '.');
        return $formatted . 'MB';
    }
}
