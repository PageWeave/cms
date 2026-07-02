<?php

declare(strict_types=1);

final class EnvTest extends PwTestCase
{
    // ---- pw_parse_env: basic parsing -------------------------------------

    public function test_parse_simple_value(): void
    {
        $this->assertSame(['MCP_KEY' => 'abc'], pw_parse_env('MCP_KEY=abc'));
    }

    public function test_parse_double_quoted_value(): void
    {
        $this->assertSame(['K' => 'a b c'], pw_parse_env('K="a b c"'));
    }

    public function test_parse_single_quoted_value(): void
    {
        $this->assertSame(['K' => 'a b c'], pw_parse_env("K='a b c'"));
    }

    public function test_parse_empty_value(): void
    {
        $this->assertSame(['K' => ''], pw_parse_env('K='));
    }

    public function test_parse_value_containing_equals(): void
    {
        $this->assertSame(['URL' => 'https://x?a=b&c=d'], pw_parse_env('URL=https://x?a=b&c=d'));
    }

    public function test_parse_value_with_spaces_requires_quotes(): void
    {
        // Unquoted value with trailing inline comment: " # note" delimits.
        $this->assertSame(['T' => 'My Site'], pw_parse_env('T=My Site # note'));
    }

    public function test_parse_quoted_value_preserves_hash(): void
    {
        $this->assertSame(['T' => 'My # Site'], pw_parse_env('T="My # Site"'));
    }

    public function test_parse_quoted_value_with_trailing_comment(): void
    {
        $this->assertSame(['T' => 'My Site'], pw_parse_env('T="My Site" # note'));
    }

    public function test_parse_trims_whitespace_around_key_and_value(): void
    {
        $this->assertSame(['K' => 'v'], pw_parse_env('   K   =   v   '));
    }

    // ---- comments & blanks -----------------------------------------------

    public function test_parse_full_line_comment_skipped(): void
    {
        $result = pw_parse_env("# a comment\n#another\nK=v");
        $this->assertSame(['K' => 'v'], $result);
    }

    public function test_parse_blank_lines_skipped(): void
    {
        $result = pw_parse_env("\n\nK=v\n\n");
        $this->assertSame(['K' => 'v'], $result);
    }

    public function test_parse_multiple_keys(): void
    {
        $result = pw_parse_env("A=1\nB=2\nC=3");
        $this->assertSame(['A' => '1', 'B' => '2', 'C' => '3'], $result);
    }

    // ---- malformed tolerance --------------------------------------------

    public function test_parse_line_without_equals_is_skipped(): void
    {
        $this->assertSame([], pw_parse_env('NOTHING_HERE'));
    }

    public function test_parse_invalid_key_chars_skipped(): void
    {
        $this->assertSame([], pw_parse_env('1KEY=v'));
        $this->assertSame([], pw_parse_env('KE-Y=v'));
    }

    public function test_parse_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], pw_parse_env(''));
    }

    public function test_parse_windows_line_endings(): void
    {
        $this->assertSame(['K' => 'v', 'B' => 'w'], pw_parse_env("K=v\r\nB=w\r\n"));
    }

    // ---- pw_load_env_file ------------------------------------------------

    public function test_load_missing_file_returns_empty_array(): void
    {
        $this->assertSame([], pw_load_env_file($this->tmp . '/nope.env'));
    }

    public function test_load_existing_file_returns_parsed(): void
    {
        $path = $this->tmp . '/config.env';
        file_put_contents($path, "MCP_KEY=secret\nSITE_TITLE=Test\n");
        $this->assertSame(['MCP_KEY' => 'secret', 'SITE_TITLE' => 'Test'], pw_load_env_file($path));
    }

    public function test_load_pointed_at_directory_returns_empty_array(): void
    {
        mkdir($this->tmp . '/adir', 0775, true);
        $this->assertSame([], pw_load_env_file($this->tmp . '/adir'));
    }

    // ---- no secret leakage via output ------------------------------------

    public function test_parse_does_not_echo_values(): void
    {
        ob_start();
        $result = @pw_parse_env('MCP_KEY=topsecret');
        $out = ob_get_clean();
        $this->assertSame(['MCP_KEY' => 'topsecret'], $result);
        $this->assertSame('', $out);
        $this->assertStringNotContainsString('topsecret', (string) $out);
    }
}
