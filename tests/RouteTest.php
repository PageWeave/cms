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

    public function test_get_first_run_runs_setup_and_serves_install_page(): void
    {
        $resp = pw_route_get(
            ['REQUEST_URI' => '/', 'HTTP_HOST' => 'example.com', 'SERVER_SOFTWARE' => 'Apache/2.4.41'],
            $this->webroot(),
            $this->cmsDir(),
            'Site',
            'https://src',
            'secret'
        );
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('text/html', $resp['contentType']);
        $this->assertStringContainsString('opencode.ai/docs/mcp-servers', $resp['body']);
        // setup happened
        $this->assertTrue(is_file($this->cmsDir() . '/.installed'));
        $this->assertTrue(is_file($this->cmsDir() . '/pages/index.html'));
    }

    public function test_get_serves_existing_page(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<p>hi</p>', 'About', null);
        pw_write_partial($this->cmsDir(), 'header', '<h>H</h>');
        // mark installed so setup doesn't run
        $this->markInstalled();

        $resp = pw_route_get(['REQUEST_URI' => '/about'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('<p>hi</p>', $resp['body']);
        $this->assertStringContainsString('<title>About</title>', $resp['body']);
        $this->assertStringContainsString('<h>H</h>', $resp['body']);
    }

    public function test_get_missing_page_is_404(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/nope'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(404, $resp['status']);
    }

    public function test_get_homepage_served_at_root(): void
    {
        pw_create_page($this->cmsDir(), 'index', '<h1>home</h1>', 'Home', null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('<h1>home</h1>', $resp['body']);
    }

    public function test_get_mcp_path_returns_info(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/mcp'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('MCP', $resp['body']);
    }

    public function test_get_source_redirects(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/__source'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src.example', 'secret');
        $this->assertSame(302, $resp['status']);
        $this->assertSame('https://src.example', $resp['headers']['Location'] ?? null);
    }

    public function test_get_indexphp_prefix_stripped(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'body', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/index.php/about'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(200, $resp['status']);
    }

    public function test_get_encoded_traversal_is_404(): void
    {
        // pages/ always exists in a real install; create it so the escaped path
        // ../partials/header.html would actually resolve without a guard.
        @mkdir($this->cmsDir() . '/pages', 0775, true);
        pw_write_partial($this->cmsDir(), 'header', '<h>SECRET</h>');
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/..%2fpartials%2fheader'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(404, $resp['status']);
        $this->assertStringNotContainsString('SECRET', $resp['body']);
    }

    public function test_get_valid_nested_page_still_resolves(): void
    {
        pw_create_page($this->cmsDir(), 'blog/post-1', '<p>nested</p>', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/blog/post-1'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame(200, $resp['status']);
        $this->assertStringContainsString('nested', $resp['body']);
    }

    // ---- Tier 4: default security headers ---------------------------------

    public function test_html_page_response_carries_security_headers(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<p>hi</p>', null, null);
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/about'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
        $this->assertSame('nosniff', $resp['headers']['X-Content-Type-Options'] ?? null);
        $this->assertSame('SAMEORIGIN', $resp['headers']['X-Frame-Options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $resp['headers']['Referrer-Policy'] ?? null);
    }

    public function test_404_response_carries_security_headers(): void
    {
        $this->markInstalled();
        $resp = pw_route_get(['REQUEST_URI' => '/missing'], $this->webroot(), $this->cmsDir(), 'Site', 'https://src', 'secret');
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
