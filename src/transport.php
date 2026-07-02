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
