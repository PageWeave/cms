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
    string $docRoot,
    string $cmsDir,
    string $siteTitle,
    string $sourceUrl,
    string $mcpKey,
    string $siteUrl = ''
): array {
    if (!pw_is_installed($cmsDir)) {
        $httpHost = $server['HTTP_HOST'] ?? '';
        $scheme = ($siteUrl !== '' && is_string(parse_url($siteUrl, PHP_URL_SCHEME)))
            ? (string) parse_url($siteUrl, PHP_URL_SCHEME)
            : 'https';
        $host = pw_resolve_host($siteUrl, $httpHost);
        $software = $server['SERVER_SOFTWARE'] ?? '';
        pw_run_setup($docRoot, $cmsDir, $software, $host, '/mcp', $mcpKey !== '', $siteTitle, $sourceUrl, $scheme);
    }

    $slug = pw_path_from_uri($server['REQUEST_URI'] ?? '/');

    if ($slug === 'mcp') {
        return pw_response(200, 'text/plain; charset=utf-8', 'PageWeave CMS MCP endpoint. Send a JSON-RPC POST request to this URL with an Authorization: Bearer header.');
    }
    if ($slug === '__source') {
        return pw_response(302, '', '', ['Location' => $sourceUrl]);
    }

    $page = pw_get_page($cmsDir, $slug);
    if ($page === null) {
        return pw_response(404, 'text/html; charset=utf-8', pw_not_found_html($slug, $siteTitle));
    }
    return pw_response(200, 'text/html; charset=utf-8', pw_render_page($cmsDir, $page, $siteTitle));
}
