<?php

declare(strict_types=1);

final class JsonRpcTest extends PwTestCase
{
    public function test_success_envelope(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['x' => 1]],
            pw_jsonrpc_success(1, ['x' => 1])
        );
    }

    public function test_success_envelope_null_id(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'result' => ['ok' => true]],
            pw_jsonrpc_success(null, ['ok' => true])
        );
    }

    public function test_success_preserves_string_id(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 'abc', 'result' => null],
            pw_jsonrpc_success('abc', null)
        );
    }

    public function test_error_envelope(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 5, 'error' => ['code' => -32601, 'message' => 'Method not found']],
            pw_jsonrpc_error(5, -32601, 'Method not found')
        );
    }

    public function test_error_envelope_with_data(): void
    {
        $out = pw_jsonrpc_error(5, -32602, 'Invalid params', ['field' => 'path']);
        $this->assertSame(-32602, $out['error']['code']);
        $this->assertSame(['field' => 'path'], $out['error']['data']);
    }

    public function test_error_envelope_null_id(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']],
            pw_jsonrpc_error(null, -32700, 'Parse error')
        );
    }
}
