<?php

declare(strict_types=1);

/** create_page: create a new page (fails if it already exists). */
function pw_tool_create_page(array $args, array $ctx): array
{
    $path = $args['path'] ?? null;
    $html = $args['html'] ?? null;
    if (!is_string($path) || $path === '') {
        return pw_tool_error('path is required');
    }
    if (!is_string($html) || $html === '') {
        return pw_tool_error('html is required');
    }
    $title = is_string($args['title'] ?? null) ? $args['title'] : null;
    $description = is_string($args['description'] ?? null) ? $args['description'] : null;
    $res = pw_create_page($ctx['cmsDir'], $path, $html, $title, $description);
    if (!$res['ok']) {
        return pw_tool_error($res['error']);
    }
    return pw_tool_ok(['path' => pw_normalize_slug($path), 'ok' => true]);
}
