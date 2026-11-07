<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 外部API呼び出し用の薄いcurlラッパ。
 */
final class HttpClient
{
    /**
     * JSONボディでリクエストし、[status, decodedArray] を返す。
     *
     * @param array<string,string> $headers ヘッダ（"Key: Value" ではなく ['Key'=>'Value']）
     */
    public static function requestJson(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 300): array
    {
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP接続エラー: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : ['raw' => $raw]];
    }

    /**
     * application/x-www-form-urlencoded でPOSTし [status, decodedArray] を返す。
     * @param array<string,string> $headers
     * @param array<string,scalar> $fields
     */
    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 60): array
    {
        $headerLines = ['Content-Type: application/x-www-form-urlencoded'];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP接続エラー: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : ['raw' => $raw]];
    }

    /**
     * multipart/form-data でPOST。ファイルは CURLFile を値に入れる。
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $fields  値に \CURLFile を含められる
     */
    public static function postMultipart(string $url, array $headers, array $fields, int $timeout = 600): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_POSTFIELDS     => $fields, // 連想配列 → multipart
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP接続エラー: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : ['raw' => $raw]];
    }

    /**
     * 生のバイナリボディ（音声など）を指定Content-TypeでPOSTし [status, decodedArray] を返す。
     * @param array<string,string> $headers
     */
    public static function postRaw(string $url, array $headers, string $body, string $contentType, int $timeout = 600): array
    {
        $headerLines = ['Content-Type: ' . $contentType];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP接続エラー: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : ['raw' => $raw]];
    }
}
