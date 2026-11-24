<?php
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/routes.php';

ini_set('display_errors', '0');

set_error_handler(function (int $no, string $str, string $file, int $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

try {
    handle_request();
} catch (Throwable $e) {
    error_log('[BadgeNetwork] ' . $e);
    if (!headers_sent()) {
        send_common_headers();
        json_out(500, ['error' => 'Erreur interne']);
    }
}
