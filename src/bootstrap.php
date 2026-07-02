<?php

declare(strict_types=1);

/**
 * Bootstrap: PHP version guard, error handling, version constant.
 */

const PW_VERSION = '0.1.0';

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
