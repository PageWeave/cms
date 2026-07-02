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

    public function test_mcp_empty_key_reports_disabled(): void
    {
        $resp = pw_route_mcp(['HTTP_HOST' => 'x'], '{}', '', $this->mcpCtx());
        $this->assertSame(401, $resp['status']);
        $body = json_decode($resp['body'], true);
        $this->assertStringContainsString('MCP_KEY', $body['error']['message']);
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
}
