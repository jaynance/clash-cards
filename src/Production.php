<?php
declare(strict_types=1);

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '8.43');
}

final class Production
{
    public const VERSION = APP_VERSION;

    public static function configure(array $appConfig = []): void
    {
        $debug = (bool)($appConfig['debug'] ?? false);

        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        $errorLog = trim((string)($appConfig['error_log'] ?? ''));
        if ($errorLog !== '') {
            $dir = dirname($errorLog);
            if (is_dir($dir) && is_writable($dir)) {
                ini_set('error_log', $errorLog);
            }
        }

        error_reporting(E_ALL);

        if (!$debug) {
            set_exception_handler(static function (Throwable $e): void {
                error_log((string)$e);
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/html; charset=UTF-8');
                }
                echo '<!doctype html><html><head><meta charset="utf-8"><title>Clash Cards</title></head>';
                echo '<body style="font-family:system-ui;padding:2rem">';
                echo '<h1>Something went wrong</h1>';
                echo '<p>The error was logged. Please try again or contact the site administrator.</p>';
                echo '</body></html>';
                exit;
            });
        }
    }

    public static function report(Throwable $e, string $context): void
    {
        error_log($context . ': ' . (string)$e);
    }
}
