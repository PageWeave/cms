<?php

declare(strict_types=1);

/**
 * Bootstrap: PHP version guard, error handling, version constant.
 */

const PW_VERSION_FILE = __DIR__ . '/../VERSION';

// VERSION is the single source of truth for the implementation version.
// In dev/tests this reads the repo's VERSION file. The compiled single file
// pre-defines PW_VERSION with the literal (baked by build.php), so this guard
// is a no-op there.
if (!defined('PW_VERSION')) {
    define('PW_VERSION', trim((string) @file_get_contents(PW_VERSION_FILE)) ?: 'dev');
}

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PageWeave CMS requires PHP 8.3 or newer (running ' . PHP_VERSION . ").\n";
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function pw_version(): string
{
    return PW_VERSION;
}
