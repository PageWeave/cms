<?php

declare(strict_types=1);

/**
 * JSON-RPC 2.0 envelope helpers (MCP runs over JSON-RPC).
 *
 * These return plain arrays; the transport layer json_encodes them.
 */

function pw_jsonrpc_success(mixed $id, mixed $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function pw_jsonrpc_error(mixed $id, int $code, string $message, mixed $data = null): array
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

// Standard JSON-RPC error codes used by the transport.
function pw_jsonrpc_parse_error(mixed $id = null): array
{
    return pw_jsonrpc_error($id, -32700, 'Parse error');
}

function pw_jsonrpc_invalid_request(mixed $id = null, string $message = 'Invalid Request'): array
{
    return pw_jsonrpc_error($id, -32600, $message);
}

function pw_jsonrpc_method_not_found(mixed $id): array
{
    return pw_jsonrpc_error($id, -32601, 'Method not found');
}

function pw_jsonrpc_invalid_params(mixed $id, string $message = 'Invalid params'): array
{
    return pw_jsonrpc_error($id, -32602, $message);
}

function pw_jsonrpc_internal_error(mixed $id, string $message = 'Internal error'): array
{
    return pw_jsonrpc_error($id, -32603, $message);
}
