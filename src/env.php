<?php

declare(strict_types=1);

/**
 * Tiny .env-style parser (KEY=VALUE). Hand-rolled — zero runtime deps — so the
 * compiled single file stays free of vendor code. Tolerant: malformed lines are
 * skipped silently rather than fatal, so a bad edit to _cms/config.env never
 * whitescreens the whole site. Values are never echoed or logged.
 */

/**
 * Parse a .env string into an associative array.
 *
 * Rules:
 *  - Blank lines and lines whose first non-whitespace char is "#" are ignored.
 *  - Keys must match [A-Za-z_][A-Za-z0-9_]*; other lines are skipped.
 *  - Values may be double- or single-quoted (preserving inner spaces/"#").
 *  - Unquoted values are trimmed; a " #" sequence introduces an inline comment.
 *  - Carriage returns (CRLF) are stripped.
 *
 * Returns key => value pairs in file order. Missing/empty content => [].
 */
function pw_parse_env(string $content): array
{
    $result = [];
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $raw = $m[2];
        $result[$key] = pw_parse_env_value($raw);
    }
    return $result;
}

/**
 * Resolve a raw value token (already trailing-trimmed) into its final string,
 * honouring quotes and unquoted inline comments.
 */
function pw_parse_env_value(string $raw): string
{
    if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
        $quote = $raw[0];
        $end = strpos($raw, $quote, 1);
        if ($end === false) {
            return substr($raw, 1);
        }
        return substr($raw, 1, $end - 1);
    }
    $hash = strpos($raw, ' #');
    if ($hash !== false) {
        return rtrim(substr($raw, 0, $hash));
    }
    return $raw;
}

/**
 * Load and parse an env file from disk. Missing file or non-file target => [].
 */
function pw_load_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        return [];
    }
    return pw_parse_env($content);
}
