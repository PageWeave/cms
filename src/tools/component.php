<?php

declare(strict_types=1);

/** get_component / update_component: read or write the header or footer partial. */

function pw_tool_get_component(array $args, array $ctx): array
{
    $component = $args['component'] ?? null;
    if (!in_array($component, ['header', 'footer'], true)) {
        return pw_tool_error("component must be 'header' or 'footer'");
    }
    return pw_tool_ok(['component' => $component, 'html' => pw_get_partial($ctx['cmsDir'], $component)]);
}

function pw_tool_update_component(array $args, array $ctx): array
{
    $component = $args['component'] ?? null;
    if (!in_array($component, ['header', 'footer'], true)) {
        return pw_tool_error("component must be 'header' or 'footer'");
    }
    $html = $args['html'] ?? null;
    if (!is_string($html)) {
        return pw_tool_error('html is required');
    }
    pw_write_partial($ctx['cmsDir'], $component, $html);
    return pw_tool_ok(['component' => $component, 'ok' => true]);
}
