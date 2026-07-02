<?php

declare(strict_types=1);

/**
 * Front-controller entry point. Decides MCP (POST /mcp) vs page serving (GET),
 * then emits the HTTP response. Defines pw_router_main() only; the compiled
 * single file calls it as its very last statement.
 */

function pw_emit(array $resp): void
{
    if (headers_sent()) {
        echo $resp['body'];
        return;
    }
    http_response_code($resp['status']);
    if (($resp['contentType'] ?? '') !== '') {
        header('Content-Type: ' . $resp['contentType']);
    }
    foreach ($resp['headers'] ?? [] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $resp['body'];
}

function pw_router_dispatch(array $server, string $body, string $docRoot, string $cmsDir): array
{
    $method = $server['REQUEST_METHOD'] ?? 'GET';
    $path = pw_path_from_uri($server['REQUEST_URI'] ?? '/');

    // First run: scaffold _cms/ and write _cms/config.env BEFORE reading
    // config (the file does not exist yet). Subsequent requests skip this.
    if (!pw_is_installed($cmsDir)) {
        $host = pw_resolve_host('', $server['HTTP_HOST'] ?? '');
        // Honour an explicit HTTPS=off; otherwise default to https. The install
        // page is one-shot and modern hosting is overwhelmingly TLS, and a proxy
        // that strips the HTTPS var would otherwise render a misleading http://
        // endpoint. SITE_URL (once set in config.env) overrides display anyway.
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $scheme = $https === 'off' ? 'http' : 'https';
        pw_run_setup($docRoot, $cmsDir, $server['SERVER_SOFTWARE'] ?? '', $host, '/mcp', $scheme);
    }

    $cfg = pw_load_config($cmsDir);
    $ctx = [
        'cmsDir' => $cmsDir,
        'docRoot' => $docRoot,
        'siteTitle' => $cfg['siteTitle'],
    ];

    if ($method === 'POST' && $path === 'mcp') {
        return pw_route_mcp($server, $body, $cfg['mcpKey'], $ctx);
    }
    return pw_route_get($server, $cmsDir, $cfg['siteTitle'], $cfg['sourceUrl']);
}

function pw_router_main(): void
{
    $resp = pw_router_dispatch(
        $_SERVER,
        (string) file_get_contents('php://input'),
        dirname(__FILE__),
        PW_CMS_DIR
    );
    pw_emit($resp);
}
