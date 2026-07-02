<?php

declare(strict_types=1);

/**
 * Tool registry: maps MCP tool names to their description, JSON Schema, and
 * handler. Handlers are flat global functions (one per file under src/tools/).
 */

function pw_tool_ok(mixed $data): array
{
    return [
        'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
        'isError' => false,
    ];
}

function pw_tool_error(string $message): array
{
    return [
        'content' => [['type' => 'text', 'text' => $message]],
        'isError' => true,
    ];
}

function pw_mcp_tool_registry(): array
{
    $pathSchema = ['type' => 'string', 'description' => 'Page path/slug, e.g. "about" or "blog/post-1".'];
    $htmlSchema = ['type' => 'string', 'description' => 'HTML content.'];

    return [
        'list_pages' => [
            'description' => 'List all pages with their path and title.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'handler' => 'pw_tool_list_pages',
        ],
        'get_page' => [
            'description' => 'Read a page: its body HTML, title, and description.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['path' => $pathSchema],
                'required' => ['path'],
            ],
            'handler' => 'pw_tool_get_page',
        ],
        'create_page' => [
            'description' => 'Create a new page. Fails if the path already exists.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => $pathSchema,
                    'html' => $htmlSchema,
                    'title' => ['type' => 'string', 'description' => 'Optional page <title>.'],
                    'description' => ['type' => 'string', 'description' => 'Optional meta description.'],
                ],
                'required' => ['path', 'html'],
            ],
            'handler' => 'pw_tool_create_page',
        ],
        'update_page' => [
            'description' => 'Update an existing page. Provide `html` to overwrite the body, or '
                . '`replacements` (array of {old_html, new_html, mode?}) to patch it. The two are '
                . 'mutually exclusive. `title`/`description` are preserved unless supplied.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => $pathSchema,
                    'html' => $htmlSchema,
                    'replacements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'old_html' => ['type' => 'string'],
                                'new_html' => ['type' => 'string'],
                                'mode' => ['type' => 'string', 'enum' => ['replace', 'replace_all']],
                            ],
                            'required' => ['old_html', 'new_html'],
                        ],
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['path'],
            ],
            'handler' => 'pw_tool_update_page',
        ],
        'delete_page' => [
            'description' => 'Permanently delete a page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['path' => $pathSchema],
                'required' => ['path'],
            ],
            'handler' => 'pw_tool_delete_page',
        ],
        'get_component' => [
            'description' => 'Read the header or footer partial.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['component' => ['type' => 'string', 'enum' => ['header', 'footer']]],
                'required' => ['component'],
            ],
            'handler' => 'pw_tool_get_component',
        ],
        'update_component' => [
            'description' => 'Write the header or footer partial. Changes apply globally to every page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'component' => ['type' => 'string', 'enum' => ['header', 'footer']],
                    'html' => $htmlSchema,
                ],
                'required' => ['component', 'html'],
            ],
            'handler' => 'pw_tool_update_component',
        ],
        'get_html_head' => [
            'description' => 'Read the global <head> partial (meta, favicon, CSS links).',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'handler' => 'pw_tool_get_html_head',
        ],
        'update_html_head' => [
            'description' => 'Write the global <head> partial. A <title> is auto-injected per page, '
                . 'so do not include one here.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['html' => $htmlSchema],
                'required' => ['html'],
            ],
            'handler' => 'pw_tool_update_html_head',
        ],
        'list_assets' => [
            'description' => 'List public static files in the assets/ directory (images, etc.).',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'handler' => 'pw_tool_list_assets',
        ],
    ];
}

/**
 * Dispatch a tool call. Validates required arguments, runs the handler inside
 * a try/catch, and always returns an MCP tool-result array ({content, isError}).
 */
function pw_mcp_dispatch_tool(string $name, array $args, array $ctx): array
{
    $tools = pw_mcp_tool_registry();
    if (!isset($tools[$name])) {
        return pw_tool_error("Unknown tool: $name");
    }
    $tool = $tools[$name];
    foreach ($tool['inputSchema']['required'] ?? [] as $required) {
        if (!array_key_exists($required, $args)) {
            return pw_tool_error("Missing required argument: $required");
        }
    }
    try {
        return ($tool['handler'])($args, $ctx);
    } catch (\Throwable $e) {
        return pw_tool_error('Tool error: ' . $e->getMessage());
    }
}
