<?php

declare(strict_types=1);

/** get_page: read a page's body HTML and metadata. */
function pw_tool_get_page(array $args, array $ctx): array
{
    $path = $args['path'] ?? null;
    if (!is_string($path) || $path === '') {
        return pw_tool_error('path is required');
    }
    $page = pw_get_page($ctx['cmsDir'], $path);
    if ($page === null) {
        return pw_tool_error("page not found: $path");
    }
    return pw_tool_ok([
        'path' => $page['path'],
        'html' => $page['html'],
        'title' => $page['title'],
        'description' => $page['description'],
    ]);
}
