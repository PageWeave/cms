<?php

declare(strict_types=1);

/**
 * update_page: edit an existing page. Either overwrite the body via `html`,
 * or patch the existing body via `replacements` (mutually exclusive).
 * `title`/`description` are preserved unless explicitly provided.
 */
function pw_tool_update_page(array $args, array $ctx): array
{
    $path = $args['path'] ?? null;
    if (!is_string($path) || $path === '') {
        return pw_tool_error('path is required');
    }
    $existing = pw_get_page($ctx['cmsDir'], $path);
    if ($existing === null) {
        return pw_tool_error("page not found: $path");
    }

    $hasHtml = array_key_exists('html', $args) && is_string($args['html']);
    $hasReplacements = array_key_exists('replacements', $args) && is_array($args['replacements']);
    if (!$hasHtml && !$hasReplacements) {
        return pw_tool_error('provide html or replacements');
    }
    if ($hasHtml && $hasReplacements) {
        return pw_tool_error('html and replacements are mutually exclusive');
    }

    if ($hasHtml) {
        $html = $args['html'];
    } else {
        $applied = pw_apply_replacements($existing['html'], $args['replacements']);
        if (!$applied['ok']) {
            return pw_tool_error($applied['error']);
        }
        $html = $applied['html'];
    }

    $title = array_key_exists('title', $args)
        ? (is_string($args['title']) ? $args['title'] : null)
        : $existing['title'];
    $description = array_key_exists('description', $args)
        ? (is_string($args['description']) ? $args['description'] : null)
        : $existing['description'];

    $res = pw_write_page($ctx['cmsDir'], $path, $html, $title, $description);
    if (!$res['ok']) {
        return pw_tool_error($res['error']);
    }
    return pw_tool_ok(['path' => pw_normalize_slug($path), 'ok' => true]);
}
