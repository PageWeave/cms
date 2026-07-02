<?php

declare(strict_types=1);

final class DispatchTest extends PwTestCase
{
    private function dispatch(array $req): ?array
    {
        return pw_dispatch_jsonrpc($req, $this->ctx());
    }

    public function test_initialize_returns_protocol_and_capabilities(): void
    {
        $resp = $this->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->assertSame('2.0', $resp['jsonrpc']);
        $this->assertSame(1, $resp['id']);
        $this->assertSame('2025-06-18', $resp['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', (array) $resp['result']['capabilities']);
        $this->assertSame('PageWeave CMS', $resp['result']['serverInfo']['name']);
        $this->assertSame('PageWeave CMS', $resp['result']['serverInfo']['title']);
        $this->assertSame(pw_version(), $resp['result']['serverInfo']['version']);
    }

    public function test_ping_returns_empty_result(): void
    {
        $resp = $this->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
        $this->assertSame(2, $resp['id']);
        $this->assertInstanceOf(\stdClass::class, $resp['result']);
    }

    public function test_initialized_notification_returns_null(): void
    {
        $this->assertNull($this->dispatch(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function test_unknown_method_is_method_not_found(): void
    {
        $resp = $this->dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope']);
        $this->assertSame(-32601, $resp['error']['code']);
    }

    public function test_invalid_request_missing_method(): void
    {
        $resp = $this->dispatch(['jsonrpc' => '2.0', 'id' => 4]);
        $this->assertSame(-32600, $resp['error']['code']);
    }

    public function test_tools_list_includes_registered_tools(): void
    {
        $resp = $this->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']);
        $names = array_column($resp['result']['tools'], 'name');
        $this->assertContains('get_page', $names);
        $this->assertContains('create_page', $names);
        $this->assertContains('list_assets', $names);
        // Each tool carries schema + description.
        foreach ($resp['result']['tools'] as $tool) {
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertArrayHasKey('description', $tool);
        }
    }

    public function test_tools_call_dispatches_tool(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'body', 'About', null);
        $resp = $this->dispatch([
            'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'list_pages', 'arguments' => []],
        ]);
        $this->assertSame(6, $resp['id']);
        $this->assertFalse($resp['result']['isError']);
        $data = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertSame([['path' => 'about', 'title' => 'About']], $data['pages']);
    }

    public function test_tools_call_unknown_tool_is_invalid_params(): void
    {
        $resp = $this->dispatch([
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'bogus', 'arguments' => []],
        ]);
        $this->assertSame(-32602, $resp['error']['code']);
    }

    public function test_tools_call_missing_required_arg_returns_iserror(): void
    {
        $resp = $this->dispatch([
            'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
            'params' => ['name' => 'get_page', 'arguments' => []],
        ]);
        // required-arg check reports as a tool error result (isError content), not an RPC error
        $this->assertTrue($resp['result']['isError']);
        $this->assertNotEmpty($resp['result']['content']);
    }

    public function test_notification_with_unknown_method_returns_null(): void
    {
        $this->assertNull($this->dispatch(['jsonrpc' => '2.0', 'method' => 'something']));
    }
}
