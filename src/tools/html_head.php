<?php

declare(strict_types=1);

/**
 * get_html_head / update_html_head: read or write the contents that are placed
 * inside the CMS-generated <head> element.
 */

const PW_HEAD_FORBIDDEN_TAGS = ['!DOCTYPE', 'html', 'head', 'body', 'title'];

function pw_validate_head_html(string $html): ?string
{
    foreach (PW_HEAD_FORBIDDEN_TAGS as $tag) {
        if (preg_match('/<\/?' . preg_quote($tag, '/') . '\b/i', $html) === 1) {
            return "<{$tag}> is not allowed in the head partial; include only inner <head> children such as <meta>, <link>, <script>, and <style>";
        }
    }
    return null;
}

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
    $error = pw_validate_head_html($html);
    if ($error !== null) {
        return pw_tool_error($error);
    }
    pw_write_partial($ctx['cmsDir'], 'head', $html);
    return pw_tool_ok(['ok' => true]);
}
