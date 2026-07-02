<?php

declare(strict_types=1);

/**
 * GET request serving: resolve the requested path to a stored page, compose
 * the document, and return a response array {status, contentType, body, headers}.
 *
 * pw_route_get is pure (takes the $_SERVER-like array); the real entry point
 * in router.php emits the headers and body.
 */

function pw_response(int $status, string $contentType, string $body, array $headers = []): array
{
    return ['status' => $status, 'contentType' => $contentType, 'body' => $body, 'headers' => $headers];
}

/**
 * Baseline security headers for rendered HTML responses. Deliberately narrow:
 * no HSTS (HTTPS-only, risky if misconfigured) and no strict CSP (would break
 * operator-authored HTML and the install page's inline styles). The operator
 * can add stronger headers via the head partial.
 */
function pw_security_headers(): array
{
    return [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];
}

function pw_path_from_uri(string $uri): string
{
    $path = $uri;
    $query = strpos($path, '?');
    if ($query !== false) {
        $path = substr($path, 0, $query);
    }
    $path = rawurldecode($path);

    if (str_starts_with($path, '/index.php')) {
        $path = substr($path, strlen('/index.php'));
    }
    $path = ltrim($path, '/');
    $path = rtrim($path, '/');

    return $path === '' ? 'index' : $path;
}

function pw_not_found_html(string $slug, string $siteTitle): string
{
    return "<!DOCTYPE html>\n<html><head><title>Not found" . htmlspecialchars(
        $siteTitle ? ' — ' . $siteTitle : '',
        ENT_QUOTES,
        'UTF-8'
    ) . "</title></head>\n"
        . "<body>\n<h1>404 — Page not found</h1>\n"
        . '<p>The page <code>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . "</code> does not exist.</p>\n"
        . "</body></html>\n";
}

function pw_route_get(
    array $server,
    string $cmsDir,
    string $siteTitle,
    string $sourceUrl
): array {
    $slug = pw_path_from_uri($server['REQUEST_URI'] ?? '/');

    if ($slug === 'mcp') {
        // Streamable HTTP §Listening: a GET to the MCP endpoint must return
        // SSE or 405. We don't stream, so MCP clients (which always send
        // Accept: text/event-stream per the spec) get 405. A browser/curl GET
        // without that Accept keeps the human-friendly info page.
        if (str_contains($server['HTTP_ACCEPT'] ?? '', 'text/event-stream')) {
            return pw_response(405, '', '', ['Allow' => 'POST']);
        }
        return pw_response(200, 'text/plain; charset=utf-8', 'PageWeave CMS MCP endpoint. Send a JSON-RPC POST request to this URL with an Authorization: Bearer header.');
    }
    if ($slug === '__source') {
        return pw_response(302, '', '', ['Location' => $sourceUrl]);
    }

    $page = pw_get_page($cmsDir, $slug);
    if ($page === null) {
        return pw_response(404, 'text/html; charset=utf-8', pw_not_found_html($slug, $siteTitle), pw_security_headers());
    }
    return pw_response(200, 'text/html; charset=utf-8', pw_render_page($cmsDir, $page, $siteTitle), pw_security_headers());
}
