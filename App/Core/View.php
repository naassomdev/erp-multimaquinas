<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private static string $viewsPath = '';
    private static string $defaultLayout = 'layouts/default';

    public static function setBasePath(string $path): void
    {
        self::$viewsPath = rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        self::ensureBasePath();
        $content = self::renderFile($template, $data);
        $layout = $layout ?? self::$defaultLayout;
        if ($layout === '') return $content;
        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        self::ensureBasePath();
        return self::renderFile($template, $data);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function ensureBasePath(): void
    {
        if (self::$viewsPath === '') {
            self::$viewsPath = BASE_PATH . '/App/Views';
        }
    }

    private static function renderFile(string $template, array $data): string
    {
        $file = self::$viewsPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View não encontrada: {$template} (procurada em {$file})");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
