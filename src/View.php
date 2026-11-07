<?php

declare(strict_types=1);

namespace App;

/**
 * ネイティブPHPテンプレートのレンダラ。
 */
final class View
{
    /** templates/<name>.php をレイアウトに包んで文字列で返す */
    public static function render(string $name, array $data = [], ?string $title = null, string $containerClass = 'container'): string
    {
        $content = self::renderPartial($name, $data);
        $title = $title ?? (string) App::config('app_name');
        $authenticated = Auth::check();

        ob_start();
        require APP_BASE_PATH . '/templates/layout.php';
        return (string) ob_get_clean();
    }

    /** レイアウト無しで部分テンプレートを描画 */
    public static function renderPartial(string $name, array $data = []): string
    {
        $file = APP_BASE_PATH . '/templates/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("テンプレートがありません: $name");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
