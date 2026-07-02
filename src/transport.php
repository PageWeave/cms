<?php

declare(strict_types=1);

/**
 * MCP Streamable HTTP transport (stateless, JSON-only).
 *
 * Auth helper lives here; the request dispatch (initialize/tools/*) is added
 * in a later module once the tool registry exists.
 */

/**
 * The MCP protocol/contract version this server implements. Decoupled from the
 * implementation version (PW_VERSION): it only changes when we adopt a newer
 * MCP spec revision. This is the "interface version" clients negotiate against.
 */
const MCP_PROTOCOL_VERSION = '2025-06-18';

/**
 * Protocol versions this server accepts via the MCP-Protocol-Version header.
 * Includes 2025-03-26 (the spec's stated backwards-compat fallback) so older
 * clients are not rejected. Any other value → 400 Bad Request.
 */
const MCP_SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26'];

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

    // JSON-RPC §4.2: params, if present, MUST be a Structured value (Array or
    // Object). Reject primitives; treat explicit null like an omitted params.
    if (array_key_exists('params', $request) && $request['params'] !== null && !is_array($request['params'])) {
        return pw_jsonrpc_invalid_params($id, 'params must be an object or array');
    }
    $params = isset($request['params']) && is_array($request['params']) ? $request['params'] : [];

    switch ($method) {
        case 'initialize':
            return pw_jsonrpc_success($id, [
                'protocolVersion' => MCP_PROTOCOL_VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => [
                    'name' => 'PageWeave CMS',
                    'title' => 'PageWeave CMS',
                    'version' => pw_version(),
                ],
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

/**
 * MCP HTTP shell: authenticate, decode the JSON-RPC body, dispatch (handling
 * batches and notifications), and return a response array.
 *
 * Takes the $_SERVER-like array and the raw body explicitly so it is testable
 * without real superglobals. $ctx carries cmsDir/docRoot/siteTitle.
 */
function pw_route_mcp(array $server, string $body, string $mcpKey, array $ctx): array
{
    if ($mcpKey === '' || !pw_check_auth($server['HTTP_AUTHORIZATION'] ?? null, $mcpKey)) {
        // Identical response for "disabled" and "bad token": the install page
        // already communicates MCP_KEY status to the operator, so a probing
        // client must not learn whether MCP is enabled.
        return pw_response(401, 'application/json', json_encode(pw_jsonrpc_error(null, -32001, 'Unauthorized')));
    }

    // Streamable HTTP §Protocol Version Header: an invalid/unsupported
    // MCP-Protocol-Version MUST yield 400. Absent header is allowed (stateless
    // server; initialize carries the negotiated version).
    $protoVersion = $server['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
    if ($protoVersion !== null && !in_array($protoVersion, MCP_SUPPORTED_VERSIONS, true)) {
        return pw_response(400, 'application/json', json_encode(
            pw_jsonrpc_invalid_request(null, 'Unsupported MCP protocol version')
        ));
    }

    $decoded = json_decode($body, true);
    if ($decoded === null && trim($body) !== '') {
        return pw_response(400, 'application/json', json_encode(pw_jsonrpc_parse_error()));
    }

    // JSON-RPC batch request.
    if (is_array($decoded) && array_is_list($decoded)) {
        // §6: an empty array is not "an Array with at least one value" → MUST
        // return a single Invalid Request object, never an empty array.
        if ($decoded === []) {
            return pw_response(400, 'application/json', json_encode(pw_jsonrpc_invalid_request()));
        }
        $responses = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                $responses[] = pw_jsonrpc_invalid_request();
                continue;
            }
            $resp = pw_dispatch_jsonrpc($item, $ctx);
            if ($resp !== null) {
                $responses[] = $resp;
            }
        }
        // §6: if no responses survive (e.g. all-notifications batch) the server
        // MUST NOT return an empty array. Streamable HTTP maps notification
        // input to 202 Accepted with no body.
        if ($responses === []) {
            return pw_response(202, '', '');
        }
        return pw_response(200, 'application/json', json_encode($responses));
    }

    if (!is_array($decoded) || !isset($decoded['jsonrpc'])) {
        return pw_response(400, 'application/json', json_encode(pw_jsonrpc_invalid_request()));
    }

    $resp = pw_dispatch_jsonrpc($decoded, $ctx);
    if ($resp === null) {
        // Notification — accepted, no response body.
        return pw_response(202, '', '');
    }
    return pw_response(200, 'application/json', json_encode($resp));
}
