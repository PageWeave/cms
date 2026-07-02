<?php

declare(strict_types=1);

final class RouteTest extends PwTestCase
{
    private function mcpKey(): string
    {
        return 'secret';
    }

    private function mcpCtx(): array
    {
        return $this->ctx();
    }

    // ---- MCP route (pw_route_mcp) ----------------------------------------

    public function test_mcp_missing_auth_is_401(): void
    {
        $resp = pw_route_mcp(['HTTP_HOST' => 'x'], '', $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(401, $resp['status']);
    }

    public function test_mcp_empty_key_reports_unauthorized(): void
    {
        $resp = pw_route_mcp(['HTTP_HOST' => 'x'], '{}', '', $this->mcpCtx());
        $this->assertSame(401, $resp['status']);
        $body = json_decode($resp['body'], true);
        $this->assertSame('Unauthorized', $body['error']['message']);
    }

    public function test_mcp_401_body_does_not_distinguish_disabled_from_unauthorized(): void
    {
        // No info leak: empty key and bad token must yield identical bodies.
        $disabled = pw_route_mcp(['HTTP_HOST' => 'x'], '{}', '', $this->mcpCtx());
        $badtoken = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer wrong'], '{}', $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(401, $disabled['status']);
        $this->assertSame(401, $badtoken['status']);
        $this->assertSame($disabled['body'], $badtoken['body']);
        $decoded = json_decode($disabled['body'], true);
        $this->assertSame('Unauthorized', $decoded['error']['message']);
    }

    public function test_mcp_valid_auth_initialize(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(200, $resp['status']);
        $this->assertSame('application/json', $resp['contentType']);
        $json = json_decode($resp['body'], true);
        $this->assertSame('2025-06-18', $json['result']['protocolVersion']);
    }

    public function test_mcp_tools_call(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'b', 'About', null);
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_pages', 'arguments' => []]]);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $json = json_decode($resp['body'], true);
        $this->assertFalse($json['result']['isError']);
    }

    public function test_mcp_notification_returns_202_empty(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(202, $resp['status']);
        $this->assertSame('', $resp['body']);
    }

    public function test_mcp_batch_returns_array(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(200, $resp['status']);
        $arr = json_decode($resp['body'], true);
        $this->assertIsArray($arr);
        $this->assertCount(2, $arr);
    }

    public function test_mcp_invalid_json_is_parse_error(): void
    {
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], '{not json', $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(400, $resp['status']);
        $json = json_decode($resp['body'], true);
        $this->assertSame(-32700, $json['error']['code']);
    }

    public function test_mcp_protocol_version_header_not_required(): void
    {
        // Stateless server does not require Mcp-Protocol-Version; must still work without it.
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(200, $resp['status']);
    }

    public function test_mcp_supported_protocol_version_accepted(): void
    {
        // Both the current version and the spec's backwards-compat fallback MUST be accepted.
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
            $resp = pw_route_mcp(
                ['HTTP_AUTHORIZATION' => 'Bearer secret', 'HTTP_MCP_PROTOCOL_VERSION' => $version],
                $body,
                $this->mcpKey(),
                $this->mcpCtx()
            );
            $this->assertSame(200, $resp['status'], "version $version should be accepted");
        }
    }

    public function test_mcp_unsupported_protocol_version_returns_400(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $resp = pw_route_mcp(
            ['HTTP_AUTHORIZATION' => 'Bearer secret', 'HTTP_MCP_PROTOCOL_VERSION' => '1999-01-01'],
            $body,
            $this->mcpKey(),
            $this->mcpCtx()
        );
        $this->assertSame(400, $resp['status']);
        $json = json_decode($resp['body'], true);
        $this->assertSame(-32600, $json['error']['code']);
    }

    // ---- JSON-RPC batch edge cases (JSON-RPC 2.0 §6) ---------------------

    public function test_mcp_empty_batch_returns_single_invalid_request(): void
    {
        // §6: empty array is not "an Array with at least one value" → single
        // Invalid Request object, never an empty array.
        $resp = pw_route_mcp(
            ['HTTP_AUTHORIZATION' => 'Bearer secret'],
            '[]',
            $this->mcpKey(),
            $this->mcpCtx()
        );
        $this->assertSame(400, $resp['status']);
        $json = json_decode($resp['body'], true);
        $this->assertIsArray($json);
        $this->assertArrayNotHasKey(0, $json, 'must not be an array of errors');
        $this->assertSame('2.0', $json['jsonrpc']);
        $this->assertSame(-32600, $json['error']['code']);
        $this->assertNull($json['id']);
    }

    public function test_mcp_all_notification_batch_returns_202_empty(): void
    {
        // §6: no surviving responses (all notifications) → MUST NOT return an
        // empty array; Streamable HTTP: notification input → 202 with no body.
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'method' => 'something'],
        ]);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(202, $resp['status']);
        $this->assertSame('', $resp['body']);
    }

    public function test_mcp_mixed_batch_returns_only_request_responses(): void
    {
        // A notification in a mixed batch is suppressed; requests get responses.
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertSame(200, $resp['status']);
        $arr = json_decode($resp['body'], true);
        $this->assertSame([1, 2], array_column($arr, 'id'));
    }

    // ---- GET route (pw_route_get) ----------------------------------------

    public function test_router_first_run_runs_setup_and_serves_install_page(): void
    {
        $resp = pw_router_dispatch(
            ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41'],
            '',
            $this->webroot(),
            $this->cmsDir()
        );
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('text/html', $resp['contentType']);
        $this->assertStringContainsString('opencode.ai/docs/mcp-servers', $resp['body']);
        // first-run setup happened, and config.env was created
        $this->assertTrue(is_file($this->cmsDir() . '/.installed'));
        $this->assertTrue(is_file($this->cmsDir() . '/pages/index.html'));
        $this->assertTrue(is_file($this->cmsDir() . '/config.env'));
    }

    public function test_router_first_run_config_env_has_valid_generated_key(): void
    {
        pw_router_dispatch(
            ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41'],
            '',
            $this->webroot(),
            $this->cmsDir()
        );
        $env = pw_parse_env((string) file_get_contents($this->cmsDir() . '/config.env'));
        $this->assertSame(64, strlen($env['MCP_KEY']));
        $this->assertTrue(ctype_xdigit($env['MCP_KEY']));
    }

    public function test_router_mcp_auth_uses_config_env_key(): void
    {
        // Installed site with a known key in config.env.
        $this->markInstalled();
        file_put_contents($this->cmsDir() . '/config.env', "MCP_KEY=KNOWNKEY\n");
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $ok = pw_router_dispatch(
            ['REQUEST_URI' => '/mcp', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer KNOWNKEY'],
            $body,
            $this->webroot(),
            $this->cmsDir()
        );
        $this->assertSame(200, $ok['status']);

        $bad = pw_router_dispatch(
            ['REQUEST_URI' => '/mcp', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer WRONG'],
            $body,
            $this->webroot(),
            $this->cmsDir()
        );
        $this->assertSame(401, $bad['status']);
    }

    public function test_router_replacing_index_does_not_lose_config(): void
    {
        // An "update" reuses the existing config.env (setup does not re-run).
        $this->markInstalled();
        file_put_contents($this->cmsDir() . '/config.env', "MCP_KEY=MYSECRET\nSITE_TITLE=Mine\n");
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $resp = pw_router_dispatch(
            ['REQUEST_URI' => '/mcp', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer MYSECRET'],
            $body,
            $this->webroot(),
            $this->cmsDir()
        );
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('MYSECRET', (string) file_get_contents($this->cmsDir() . '/config.env'));
    }

    // ---- first-run scheme derivation -------------------------------------

    public function test_router_first_run_scheme_https_when_https_on(): void
    {
        pw_router_dispatch(
            ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41', 'HTTPS' => 'on'],
            '',
            $this->webroot(),
            $this->cmsDir()
        );
        $page = (string) file_get_contents($this->cmsDir() . '/pages/index.html');
        $this->assertStringContainsString('https://example.com/mcp', $page);
    }

    public function test_router_first_run_scheme_http_when_https_off(): void
    {
        pw_router_dispatch(
            ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41', 'HTTPS' => 'off'],
            '',
            $this->webroot(),
            $this->cmsDir()
        );
        $page = (string) file_get_contents($this->cmsDir() . '/pages/index.html');
        $this->assertStringContainsString('http://example.com/mcp', $page);
        $this->assertStringNotContainsString('https://example.com/mcp', $page);
    }

    public function test_router_first_run_scheme_defaults_to_https_when_absent(): void
    {
        // Absent HTTPS var (e.g. behind a TLS-terminating proxy) must NOT render
        // a misleading http:// endpoint — default to https.
        pw_router_dispatch(
            ['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41'],
            '',
            $this->webroot(),
            $this->cmsDir()
        );
        $page = (string) file_get_contents($this->cmsDir() . '/pages/index.html');
        $this->assertStringContainsString('https://example.com/mcp', $page);
    }

    // ---- malformed config.env tolerance at the router level --------------

    public function test_router_tolerates_malformed_config_env_lines(): void
    {
        $this->markInstalled();
        file_put_contents(
            $this->cmsDir() . '/config.env',
            "this line is broken\nMCP_KEY=GOODKEY\n# a comment\n=NOKEY\nSITE_TITLE=Still Works\n"
        );
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $resp = pw_router_dispatch(
            ['REQUEST_URI' => '/mcp', 'REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer GOODKEY'],
            $body,
            $this->webroot(),
            $this->cmsDir()
        );
        $this->assertSame(200, $resp['status']);
    }

    public function test_get_serves_existing_page(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<p>hi</p>', 'About', null);
        pw_write_partial($this->cmsDir(), 'header', '<h>H</h>');
        // mark installed so setup doesn't run
        $this->markInstalled();

        $resp = pw_route_get(['REQUEST_URI' => '/about'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('<p>hi</p>', $resp['body']);
        $this->assertStringContainsString('<title>About</title>', $resp['body']);
        $this->assertStringContainsString('<h>H</h>', $resp['body']);
    }

    public function test_get_missing_page_is_404(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/nope'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(404, $resp['status']);
    }

    public function test_get_homepage_served_at_root(): void
    {
        pw_create_page($this->cmsDir(), 'index', '<h1>home</h1>', 'Home', null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('<h1>home</h1>', $resp['body']);
    }

    public function test_get_mcp_path_returns_info(): void
    {
        // A browser/curl GET (no SSE Accept) keeps the human-friendly info page.
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/mcp'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('MCP', $resp['body']);
    }

    public function test_get_mcp_with_sse_accept_returns_405(): void
    {
        // Streamable HTTP §Listening: a GET from an MCP client (which always
        // sends Accept: text/event-stream) MUST get SSE or 405. We don't
        // stream, so 405 with Allow: POST.
        $this->markInstalled();
        $resp = pw_route_get(
            ['REQUEST_URI' => '/mcp', 'HTTP_ACCEPT' => 'text/event-stream'],
            $this->cmsDir(),
            'Site',
            'https://src'
        );
        $this->assertSame(405, $resp['status']);
        $this->assertSame('POST', $resp['headers']['Allow'] ?? null);
    }

    public function test_get_mcp_with_mixed_accept_including_sse_returns_405(): void
    {
        // Accept header may list multiple types; SSE presence triggers 405.
        $this->markInstalled();
        $resp = pw_route_get(
            ['REQUEST_URI' => '/mcp', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            $this->cmsDir(),
            'Site',
            'https://src'
        );
        $this->assertSame(405, $resp['status']);
    }

    public function test_get_source_redirects(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/__source'], $this->cmsDir(), 'Site', 'https://src.example');
        $this->assertSame(302, $resp['status']);
        $this->assertSame('https://src.example', $resp['headers']['Location'] ?? null);
    }

    public function test_get_indexphp_prefix_stripped(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'body', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/index.php/about'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(200, $resp['status']);
    }

    public function test_get_encoded_traversal_is_404(): void
    {
        // pages/ always exists in a real install; create it so the escaped path
        // ../partials/header.html would actually resolve without a guard.
        @mkdir($this->cmsDir() . '/pages', 0775, true);
        pw_write_partial($this->cmsDir(), 'header', '<h>SECRET</h>');
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/..%2fpartials%2fheader'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(404, $resp['status']);
        $this->assertStringNotContainsString('SECRET', $resp['body']);
    }

    public function test_get_valid_nested_page_still_resolves(): void
    {
        pw_create_page($this->cmsDir(), 'blog/post-1', '<p>nested</p>', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/blog/post-1'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('nested', $resp['body']);
    }

    // ---- Tier 4: default security headers ---------------------------------

    public function test_html_page_response_carries_security_headers(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<p>hi</p>', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/about'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame('nosniff', $resp['headers']['X-Content-Type-Options'] ?? null);
        $this->assertSame('SAMEORIGIN', $resp['headers']['X-Frame-Options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $resp['headers']['Referrer-Policy'] ?? null);
    }

    public function test_404_response_carries_security_headers(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/missing'], $this->cmsDir(), 'Site', 'https://src');
        $this->assertSame(404, $resp['status']);
        $this->assertSame('nosniff', $resp['headers']['X-Content-Type-Options'] ?? null);
        $this->assertSame('SAMEORIGIN', $resp['headers']['X-Frame-Options'] ?? null);
    }

    public function test_mcp_json_response_has_no_frame_options(): void
    {
        // Security headers target rendered HTML; JSON API responses need not set them.
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $resp = pw_route_mcp(['HTTP_AUTHORIZATION' => 'Bearer secret'], $body, $this->mcpKey(), $this->mcpCtx());
        $this->assertArrayNotHasKey('X-Frame-Options', $resp['headers'] ?? []);
    }
}
