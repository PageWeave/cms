<?php

declare(strict_types=1);

/**
 * Storage layer: flat-file CRUD for pages, partials, and assets, plus
 * HTML-comment frontmatter parse/serialize and find-and-replace utilities.
 *
 * Pure-ish functions: every function takes its base directory as an argument
 * (never reads a global), so storage is fully testable against a temp dir.
 */

function pw_normalize_slug(string $slug): string
{
    return ltrim(trim($slug, " \t"), '/');
}

function pw_validate_slug(string $slug): ?string
{
    $slug = pw_normalize_slug($slug);
    if ($slug === '') {
        return 'path must not be empty';
    }
    if (str_contains($slug, "\0") || str_contains($slug, '\\')) {
        return 'invalid path';
    }
    if (str_contains($slug, '..') || str_contains($slug, '//') || str_ends_with($slug, '/')) {
        return 'invalid path';
    }
    if (str_starts_with($slug, '__')) {
        return 'path reserved';
    }
    if (in_array($slug, ['mcp', 'assets'], true)) {
        return 'path reserved';
    }
    foreach (explode('/', $slug) as $segment) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment)) {
            return 'invalid path';
        }
    }
    return null;
}

function pw_slug_to_file(string $base, string $slug): string
{
    return $base . '/pages/' . pw_normalize_slug($slug) . '.html';
}

// ---- frontmatter ---------------------------------------------------------

function pw_parse_page(string $raw): array
{
    $title = null;
    $description = null;
    $body = $raw;

    if (preg_match('/\A\s*<!--\s*\n(.*?)\n-->\r?\n?(.*)\z/s', $raw, $m)) {
        $parsed = [];
        $isFrontmatter = true;
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*?)\s*$/', $line, $kv)) {
                $parsed[$kv[1]] = $kv[2];
            } else {
                $isFrontmatter = false;
                break;
            }
        }
        if ($isFrontmatter && (array_key_exists('title', $parsed) || array_key_exists('description', $parsed))) {
            $title = $parsed['title'] ?? null;
            $description = $parsed['description'] ?? null;
            $body = $m[2];
        }
    }

    return ['title' => $title, 'description' => $description, 'html' => $body];
}

function pw_serialize_page(string $html, ?string $title, ?string $description): string
{
    $title = $title === '' ? null : $title;
    $description = $description === '' ? null : $description;
    if ($title === null && $description === null) {
        return $html;
    }
    $header = "<!--\n";
    if ($title !== null) {
        $header .= 'title: ' . str_replace(["\r\n", "\n", "\r"], ' ', $title) . "\n";
    }
    if ($description !== null) {
        $header .= 'description: ' . str_replace(["\r\n", "\n", "\r"], ' ', $description) . "\n";
    }
    $header .= "-->\n";
    return $header . $html;
}

// ---- page CRUD -----------------------------------------------------------

function pw_page_exists(string $base, string $slug): bool
{
    return is_file(pw_slug_to_file($base, $slug));
}

function pw_get_page(string $base, string $slug): ?array
{
    $file = pw_slug_to_file($base, $slug);
    if (!is_file($file)) {
        return null;
    }
    $parsed = pw_parse_page((string) file_get_contents($file));
    return [
        'path' => pw_normalize_slug($slug),
        'title' => $parsed['title'],
        'description' => $parsed['description'],
        'html' => $parsed['html'],
    ];
}

function pw_write_page(string $base, string $slug, string $html, ?string $title, ?string $description): array
{
    $error = pw_validate_slug($slug);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    $file = pw_slug_to_file($base, $slug);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $written = file_put_contents($file, pw_serialize_page($html, $title, $description), LOCK_EX);
    return $written === false
        ? ['ok' => false, 'error' => 'failed to write page']
        : ['ok' => true];
}

function pw_create_page(string $base, string $slug, string $html, ?string $title, ?string $description): array
{
    $error = pw_validate_slug($slug);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    if (pw_page_exists($base, $slug)) {
        return ['ok' => false, 'error' => "page '" . pw_normalize_slug($slug) . "' already exists"];
    }
    return pw_write_page($base, $slug, $html, $title, $description);
}

function pw_delete_page(string $base, string $slug): array
{
    $error = pw_validate_slug($slug);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    $file = pw_slug_to_file($base, $slug);
    if (!is_file($file)) {
        return ['ok' => false, 'error' => "page '" . pw_normalize_slug($slug) . "' not found"];
    }
    return unlink($file)
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'failed to delete page'];
}

function pw_list_pages(string $base): array
{
    $dir = $base . '/pages';
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'html') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($dir) + 1);
        $slug = str_replace(DIRECTORY_SEPARATOR, '/', preg_replace('/\.html$/', '', $relative));
        $parsed = pw_parse_page((string) file_get_contents($file->getPathname()));
        $out[] = ['path' => $slug, 'title' => $parsed['title']];
    }
    usort($out, static fn ($a, $b) => strcmp($a['path'], $b['path']));
    return $out;
}

// ---- partials ------------------------------------------------------------

function pw_partial_file(string $base, string $name): string
{
    return $base . '/partials/' . $name . '.html';
}

function pw_get_partial(string $base, string $name): string
{
    $file = pw_partial_file($base, $name);
    return is_file($file) ? (string) file_get_contents($file) : '';
}

function pw_write_partial(string $base, string $name, string $html): void
{
    $dir = $base . '/partials';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(pw_partial_file($base, $name), $html, LOCK_EX);
}

// ---- assets --------------------------------------------------------------

function pw_list_assets(string $docRoot): array
{
    $dir = rtrim($docRoot, '/') . '/assets';
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($dir) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $out[] = [
            'path' => 'assets/' . $relative,
            'url' => '/assets/' . $relative,
            'size' => (int) $file->getSize(),
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($a['path'], $b['path']));
    return $out;
}

// ---- find-and-replace ----------------------------------------------------

function pw_apply_replacements(string $html, array $replacements): array
{
    foreach ($replacements as $index => $replacement) {
        $old = $replacement['old_html'] ?? null;
        $new = $replacement['new_html'] ?? '';
        $mode = $replacement['mode'] ?? 'replace';
        if ($old === null || $old === '') {
            return ['ok' => false, 'error' => "replacement #$index missing old_html"];
        }
        if ($mode === 'replace_all') {
            if (!str_contains($html, $old)) {
                return ['ok' => false, 'error' => "old_html not found (replacement #$index)"];
            }
            $html = str_replace($old, $new, $html);
        } else {
            $position = strpos($html, $old);
            if ($position === false) {
                return ['ok' => false, 'error' => "old_html not found (replacement #$index)"];
            }
            $html = substr_replace($html, $new, $position, strlen($old));
        }
    }
    return ['ok' => true, 'html' => $html];
}
