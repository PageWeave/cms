<?php

declare(strict_types=1);

/**
 * MCP Streamable HTTP transport (stateless, JSON-only).
 *
 * Auth helper lives here; the request dispatch (initialize/tools/*) is added
 * in a later module once the tool registry exists.
 */

/**
 * Extract a bearer token from an Authorization header value.
 * Returns null if the header is missing or not a "Bearer <token>" pair.
 */
function pw_extract_bearer(?string $header): ?string
{
    if ($header === null || $header === '') {
        return null;
    }
    $parts = preg_split('/\s+/', $header, 2);
    if ($parts === false || count($parts) !== 2) {
        return null;
    }
    if (strtolower($parts[0]) !== 'bearer') {
        return null;
    }
    $token = trim($parts[1]);
    return $token === '' ? null : $token;
}

/**
 * Validate the Authorization header against the configured MCP key.
 *
 * Empty key => MCP disabled (always false). Comparison is constant-time
 * (hash_equals) so valid-vs-invalid tokens cannot be distinguished by timing.
 */
function pw_check_auth(?string $authHeader, string $mcpKey): bool
{
    if ($mcpKey === '') {
        return false;
    }
    $token = pw_extract_bearer($authHeader);
    if ($token === null) {
        return false;
    }
    return hash_equals($mcpKey, $token);
}

/**
 * Dispatch a single decoded JSON-RPC 2.0 request for the MCP protocol.
 *
 * Returns a response array, or null for notifications (no id) which get no
 * response. $ctx carries cmsDir/docRoot/siteTitle for the tool handlers.
 */
function pw_dispatch_jsonrpc(array $request, array $ctx): ?array
{
    if (($request['jsonrpc'] ?? null) !== '2.0' || !isset($request['method']) || !is_string($request['method'])) {
        return pw_jsonrpc_invalid_request($request['id'] ?? null);
    }

    $isNotification = !array_key_exists('id', $request);
    $id = $request['id'] ?? null;
    $method = $request['method'];
    $params = isset($request['params']) && is_array($request['params']) ? $request['params'] : [];

    switch ($method) {
        case 'initialize':
            return pw_jsonrpc_success($id, [
                'protocolVersion' => '2025-06-18',
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'PageWeave CMS', 'version' => '0.1.0'],
            ]);

        case 'notifications/initialized':
            return null;

        case 'ping':
            return pw_jsonrpc_success($id, new \stdClass());

        case 'tools/list':
            $tools = [];
            foreach (pw_mcp_tool_registry() as $name => $tool) {
                $tools[] = [
                    'name' => $name,
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                ];
            }
            return pw_jsonrpc_success($id, ['tools' => $tools]);

        case 'tools/call':
            $name = $params['name'] ?? null;
            if (!is_string($name) || !isset(pw_mcp_tool_registry()[$name])) {
                return pw_jsonrpc_invalid_params($id, 'Unknown tool: ' . (string) $name);
            }
            $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
            return pw_jsonrpc_success($id, pw_mcp_dispatch_tool($name, $arguments, $ctx));

        default:
            return $isNotification ? null : pw_jsonrpc_method_not_found($id);
    }
}
