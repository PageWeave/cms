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

function pw_router_main(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = pw_path_from_uri($_SERVER['REQUEST_URI'] ?? '/');

    $docRoot = dirname(__FILE__);
    $ctx = [
        'cmsDir' => CMS_DIR,
        'docRoot' => $docRoot,
        'siteTitle' => SITE_TITLE,
    ];

    if ($method === 'POST' && $path === 'mcp') {
        $resp = pw_route_mcp(
            $_SERVER,
            (string) file_get_contents('php://input'),
            MCP_KEY,
            $ctx
        );
    } else {
        $resp = pw_route_get($_SERVER, $docRoot, CMS_DIR, SITE_TITLE, SOURCE_URL, MCP_KEY);
    }

    pw_emit($resp);
}
