<?php

declare(strict_types=1);

/** delete_page: permanently remove a page. */
function pw_tool_delete_page(array $args, array $ctx): array
{
    $path = $args['path'] ?? null;
    if (!is_string($path) || $path === '') {
        return pw_tool_error('path is required');
    }
    $res = pw_delete_page($ctx['cmsDir'], $path);
    if (!$res['ok']) {
        return pw_tool_error($res['error']);
    }
    return pw_tool_ok(['path' => pw_normalize_slug($path), 'ok' => true]);
}
