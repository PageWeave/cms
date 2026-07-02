<?php

declare(strict_types=1);

/**
 * Build script: compile the modular src/ into a single readable dist/index.php.
 *
 * Strategy: ordered concatenation. For each file, strip the leading `<?php`
 * tag and the file-level `declare(strict_types=1);` (the build re-adds one
 * exactly once at the top). Wrap each file in a separator comment. Prepend a
 * banner with setup instructions (config lives in _cms/config.env, not here).
 * Append the front-controller entry call as the final statement.
 *
 * Usage:  php build.php
 */

const SRC_DIR = __DIR__ . '/src';
const DIST_FILE = __DIR__ . '/dist/index.php';

function pw_build_files(): array
{
    $core = [
        'env.php',
        'config.php',
        'bootstrap.php',
        'storage.php',
        'render.php',
        'jsonrpc.php',
        'registry.php',
    ];
    $tools = array_map(
        static fn($f) => 'tools/' . basename($f),
        glob(SRC_DIR . '/tools/*.php') ?: []
    );
    sort($tools);
    $tail = [
        'transport.php',
        'serve.php',
        'setup.php',
        'router.php',
    ];
    return array_merge($core, $tools, $tail);
}

function pw_strip_php_head(string $content): string
{
    $content = preg_replace('/^\s*<\?php\s*/', '', $content) ?? $content;
    $content = preg_replace('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $content) ?? $content;
    return ltrim($content);
}

function pw_banner(string $version): string
{
    return <<<BANNER
/*
 * ============================================================================
 *  PageWeave CMS {$version}  —  https://pageweave.dev
 * ============================================================================
 *  A self-hostable, single-file PHP CMS with a built-in MCP server.
 *
 *  QUICK START
 *    1. Upload THIS file as `index.php` to your web server's document root.
 *    2. Visit your domain — first run auto-configures routing, scaffolds the
 *       _cms/ data dir, and writes _cms/config.env with a generated MCP key,
 *       then shows the installation page.
 *    3. Open _cms/config.env to view or change settings (MCP key, site title,
 *       source URL, canonical site URL).
 *
 *  TO UPDATE the CMS later: just replace this one file. Your _cms/ data and
 *  config.env are preserved automatically — nothing to reconfigure.
 *
 *  Page serving works out of the box; MCP is ready immediately (a key is
 *  generated on first run — find it in _cms/config.env).
 *
 *  LICENSE: AGPL-3.0-or-later. Running a modified version on a public server
 *  requires offering the source to its users (AGPL §13) — point SOURCE_URL at
 *  your published source. Built from modular source via build.php.
 * ============================================================================
 */

BANNER;
}

$version = is_file(__DIR__ . '/VERSION')
    ? trim((string) file_get_contents(__DIR__ . '/VERSION'))
    : 'dev';

$parts = [];
$parts[] = "<?php\n";
$parts[] = "declare(strict_types=1);\n\n";
$parts[] = pw_banner($version);

// Bake the implementation version (single source: VERSION) so the standalone
// compiled file reports it without needing the VERSION file on the web server.
$parts[] = "define('PW_VERSION', " . var_export($version, true) . ");\n\n";

foreach (pw_build_files() as $relative) {
    $path = SRC_DIR . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing source file: $path\n");
        exit(1);
    }
    $body = pw_strip_php_head((string) file_get_contents($path));
    $parts[] = "/* === src/{$relative} === */\n";
    $parts[] = $body;
    if (!str_ends_with($body, "\n")) {
        $parts[] = "\n";
    }
    $parts[] = "\n";
}

$parts[] = "/* === entry point === */\n";
$parts[] = "pw_router_main();\n";

if (!is_dir(dirname(DIST_FILE))) {
    mkdir(dirname(DIST_FILE), 0775, true);
}

$written = file_put_contents(DIST_FILE, implode('', $parts));
if ($written === false) {
    fwrite(STDERR, "Failed to write " . DIST_FILE . "\n");
    exit(1);
}

echo "Built " . DIST_FILE . " (" . number_format((float) $written) . " bytes).\n";
