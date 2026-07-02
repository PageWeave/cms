<?php

declare(strict_types=1);

/** get_html_head / update_html_head: read or write the global <head> partial. */

function pw_tool_get_html_head(array $args, array $ctx): array
{
    return pw_tool_ok(['html' => pw_get_partial($ctx['cmsDir'], 'head')]);
}

function pw_tool_update_html_head(array $args, array $ctx): array
{
    $html = $args['html'] ?? null;
    if (!is_string($html)) {
        return pw_tool_error('html is required');
    }
    pw_write_partial($ctx['cmsDir'], 'head', $html);
    return pw_tool_ok(['ok' => true]);
}
