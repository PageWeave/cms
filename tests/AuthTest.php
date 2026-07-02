<?php

declare(strict_types=1);

final class AuthTest extends PwTestCase
{
    public function test_valid_bearer_token(): void
    {
        $this->assertTrue(pw_check_auth('Bearer s3cr3t', 's3cr3t'));
    }

    public function test_wrong_token_rejected(): void
    {
        $this->assertFalse(pw_check_auth('Bearer wrong', 's3cr3t'));
    }

    public function test_missing_header_rejected(): void
    {
        $this->assertFalse(pw_check_auth(null, 's3cr3t'));
        $this->assertFalse(pw_check_auth('', 's3cr3t'));
    }

    public function test_empty_mcp_key_disables_auth(): void
    {
        // Empty key => MCP disabled entirely, no token can authenticate.
        $this->assertFalse(pw_check_auth('Bearer anything', ''));
        $this->assertFalse(pw_check_auth(null, ''));
    }

    public function test_missing_bearer_prefix_rejected(): void
    {
        $this->assertFalse(pw_check_auth('s3cr3t', 's3cr3t'));
        $this->assertFalse(pw_check_auth('Basic s3cr3t', 's3cr3t'));
    }

    public function test_scheme_is_case_insensitive(): void
    {
        $this->assertTrue(pw_check_auth('bearer s3cr3t', 's3cr3t'));
        $this->assertTrue(pw_check_auth('BEARER s3cr3t', 's3cr3t'));
    }

    public function test_extract_bearer_token(): void
    {
        $this->assertSame('tok', pw_extract_bearer('Bearer tok'));
        $this->assertSame('tok', pw_extract_bearer('bearer tok'));
        $this->assertSame('a b c', pw_extract_bearer('Bearer a b c'));
        $this->assertNull(pw_extract_bearer('tok'));
        $this->assertNull(pw_extract_bearer(null));
        $this->assertNull(pw_extract_bearer('Bearer'));
        $this->assertNull(pw_extract_bearer('Bearer '));
    }

    public function test_constant_time_compare_does_not_leak(): void
    {
        // Sanity: hash_equals used internally; different lengths still reject.
        $this->assertFalse(pw_check_auth('Bearer short', 'a-much-longer-key'));
        $this->assertTrue(pw_check_auth('Bearer a-much-longer-key', 'a-much-longer-key'));
    }
}
