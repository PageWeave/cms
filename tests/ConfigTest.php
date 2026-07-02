<?php

declare(strict_types=1);

final class ConfigTest extends PwTestCase
{
    // ---- defaults ---------------------------------------------------------

    public function test_defaults_has_expected_keys_and_values(): void
    {
        $d = pw_config_defaults();
        $this->assertSame('', $d['mcpKey']);
        $this->assertSame('My Site', $d['siteTitle']);
        $this->assertSame('', $d['siteUrl']);
        $this->assertNotSame('', $d['sourceUrl']);
    }

    public function test_defaults_keys_are_the_four_user_values(): void
    {
        $this->assertSame(
            ['mcpKey', 'sourceUrl', 'siteUrl', 'siteTitle'],
            array_keys(pw_config_defaults())
        );
    }

    // ---- pw_load_config ---------------------------------------------------

    public function test_load_config_missing_file_returns_defaults(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('', $cfg['mcpKey']);
        $this->assertSame('My Site', $cfg['siteTitle']);
    }

    public function test_load_config_reads_config_env_file(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        file_put_contents($this->cmsDir() . '/config.env', "MCP_KEY=secret\nSITE_TITLE=Custom\n");
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('secret', $cfg['mcpKey']);
        $this->assertSame('Custom', $cfg['siteTitle']);
    }

    public function test_load_config_partial_file_falls_back_to_defaults(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        file_put_contents($this->cmsDir() . '/config.env', "MCP_KEY=secret\n");
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('secret', $cfg['mcpKey']);
        $this->assertSame('My Site', $cfg['siteTitle']);
        $this->assertSame('', $cfg['siteUrl']);
    }

    public function test_load_config_maps_all_four_env_keys(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        file_put_contents(
            $this->cmsDir() . '/config.env',
            "MCP_KEY=k\nSOURCE_URL=https://src\nSITE_URL=https://host\nSITE_TITLE=Title\n"
        );
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('k', $cfg['mcpKey']);
        $this->assertSame('https://src', $cfg['sourceUrl']);
        $this->assertSame('https://host', $cfg['siteUrl']);
        $this->assertSame('Title', $cfg['siteTitle']);
    }

    public function test_load_config_reads_exactly_config_env_path(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        // A differently named file must be ignored.
        file_put_contents($this->cmsDir() . '/other.env', "MCP_KEY=wrong\n");
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('', $cfg['mcpKey']);
    }

    public function test_load_config_missing_cms_dir_returns_defaults(): void
    {
        // No _cms dir at all — must not fatal.
        $cfg = pw_load_config($this->cmsDir());
        $this->assertSame('', $cfg['mcpKey']);
        $this->assertSame('My Site', $cfg['siteTitle']);
    }

    public function test_load_config_does_not_echo_values(): void
    {
        mkdir($this->cmsDir(), 0775, true);
        file_put_contents($this->cmsDir() . '/config.env', "MCP_KEY=topsecret\n");
        ob_start();
        $cfg = pw_load_config($this->cmsDir());
        $out = (string) ob_get_clean();
        $this->assertSame('topsecret', $cfg['mcpKey']);
        $this->assertSame('', $out);
    }

    // ---- pw_config_env_content -------------------------------------------

    public function test_env_content_roundtrips_through_parser(): void
    {
        $content = pw_config_env_content(
            '0123abcd',
            'https://example/src',
            'My Cool Site',
            'https://example.com'
        );
        $parsed = pw_parse_env($content);
        $this->assertSame('0123abcd', $parsed['MCP_KEY']);
        $this->assertSame('https://example/src', $parsed['SOURCE_URL']);
        $this->assertSame('My Cool Site', $parsed['SITE_TITLE']);
        $this->assertSame('https://example.com', $parsed['SITE_URL']);
    }

    public function test_env_content_handles_empty_values(): void
    {
        $content = pw_config_env_content('', '', '', '');
        $parsed = pw_parse_env($content);
        $this->assertSame('', $parsed['MCP_KEY']);
        $this->assertSame('', $parsed['SITE_URL']);
    }

    public function test_env_content_contains_provided_key(): void
    {
        $content = pw_config_env_content('MYGENERATEDKEY', '', 'My Site', '');
        $this->assertStringContainsString('MYGENERATEDKEY', $content);
    }
}
