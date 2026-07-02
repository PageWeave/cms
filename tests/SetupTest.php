<?php

declare(strict_types=1);

final class SetupTest extends PwTestCase
{
    // ---- server detection ------------------------------------------------

    public function test_detect_server(): void
    {
        $this->assertSame('apache', pw_detect_server('Apache/2.4.41 (Ubuntu)'));
        $this->assertSame('nginx', pw_detect_server('nginx/1.18.0'));
        $this->assertSame('litespeed', pw_detect_server('LiteSpeed'));
        $this->assertSame('litespeed', pw_detect_server('OpenLiteSpeed/1.7'));
        $this->assertSame('unknown', pw_detect_server(''));
    }

    // ---- rewrite configs --------------------------------------------------

    public function test_htaccess_content_has_front_controller(): void
    {
        $c = pw_htaccess_content();
        $this->assertStringContainsString('RewriteEngine', $c);
        $this->assertStringContainsString('RewriteRule ^ index.php', $c);
        $this->assertStringContainsString('REQUEST_FILENAME', $c);
        // protect _cms
        $this->assertStringContainsString('_cms', $c);
    }

    public function test_nginx_content_has_try_files_and_protects_cms(): void
    {
        $c = pw_nginx_content();
        $this->assertStringContainsString('try_files', $c);
        $this->assertStringContainsString('index.php', $c);
        $this->assertStringContainsString('_cms', $c);
    }

    public function test_cms_htaccess_deny_content(): void
    {
        $c = pw_cms_deny_content();
        $this->assertStringContainsString('Require all denied', $c);
    }

    // ---- install page -----------------------------------------------------

    public function test_install_page_contains_endpoint_and_docs_link(): void
    {
        $html = pw_install_page_html('example.com', '/mcp', true, 'https://example/src');
        $this->assertStringContainsString('https://example.com/mcp', $html);
        $this->assertStringContainsString('opencode.ai/docs/mcp-servers', $html);
        $this->assertStringContainsString('"type"', $html); // OpenCode config snippet
        $this->assertStringContainsString('https://example/src', $html); // source link
    }

    public function test_install_page_never_leaks_real_key(): void
    {
        $html = pw_install_page_html('example.com', '/mcp', true, 'https://example/src');
        $this->assertStringContainsString('YOUR_MCP_KEY', $html);
    }

    public function test_install_page_points_at_config_env(): void
    {
        $disabled = pw_install_page_html('example.com', '/mcp', false, 'https://example/src');
        $enabled = pw_install_page_html('example.com', '/mcp', true, 'https://example/src');
        $this->assertStringContainsString('_cms/config.env', $disabled);
        $this->assertStringContainsString('_cms/config.env', $enabled);
        $this->assertStringNotContainsString('disabled', $enabled);
    }

    // ---- run_setup --------------------------------------------------------

    public function test_run_setup_apache_creates_dirs_and_files(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        $res = pw_run_setup($docRoot, $cms, 'Apache/2.4.41', 'example.com', '/mcp');

        $this->assertSame('apache', $res['server']);
        $this->assertTrue($res['wroteHtaccess']);
        $this->assertFalse($res['wroteNginx']); // apache only

        $this->assertTrue(is_dir($cms . '/pages'));
        $this->assertTrue(is_dir($cms . '/partials'));
        $this->assertTrue(is_file($cms . '/.installed'));
        $this->assertTrue(is_file($cms . '/.htaccess')); // deny file inside _cms
        $this->assertTrue(is_file($cms . '/pages/index.html'));
        $this->assertTrue(is_file($docRoot . '/.htaccess'));
        $this->assertSame('Require all denied', trim(file_get_contents($cms . '/.htaccess')));

        // config.env is written with a freshly generated 64-hex MCP_KEY.
        $this->assertTrue(is_file($cms . '/config.env'));
        $env = pw_parse_env((string) file_get_contents($cms . '/config.env'));
        $this->assertTrue(ctype_xdigit($env['MCP_KEY']));
        $this->assertSame(64, strlen($env['MCP_KEY']));
    }

    public function test_run_setup_nginx_writes_nginx_conf(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        $res = pw_run_setup($docRoot, $cms, 'nginx/1.18', 'example.com', '/mcp');
        $this->assertSame('nginx', $res['server']);
        $this->assertFalse($res['wroteHtaccess']);
        $this->assertTrue($res['wroteNginx']);
        $this->assertTrue(is_file($docRoot . '/nginx.conf'));
    }

    public function test_is_installed_reflects_marker(): void
    {
        $cms = $this->cmsDir();
        $this->assertFalse(pw_is_installed($cms));
        mkdir($cms, 0775, true);
        file_put_contents($cms . '/.installed', 'x');
        $this->assertTrue(pw_is_installed($cms));
    }

    // ---- host resolution (Tier 1: Host-header XSS hardening) --------------

    public function test_resolve_host_prefers_site_url_host(): void
    {
        $this->assertSame('configured.example', pw_resolve_host('https://configured.example/foo', 'evil.com'));
    }

    public function test_resolve_host_falls_back_to_valid_http_host(): void
    {
        $this->assertSame('example.com', pw_resolve_host('', 'example.com'));
        $this->assertSame('example.com:8080', pw_resolve_host('', 'example.com:8080'));
    }

    public function test_resolve_host_rejects_malicious_http_host(): void
    {
        $this->assertSame('localhost', pw_resolve_host('', '"><script>alert(1)</script>'));
        $this->assertSame('localhost', pw_resolve_host('', "evil.com\r\nX: y"));
        $this->assertSame('localhost', pw_resolve_host('', '<b>x</b>'));
    }

    public function test_install_page_escapes_host_xss_payloads(): void
    {
        // A malicious Host must not appear as live HTML; its escaped form must.
        $html = pw_install_page_html('"><svg onload=alert(1)>', '/mcp', true, 'https://src');
        $this->assertStringNotContainsString('"><svg onload=alert(1)>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;svg', $html);

        $html2 = pw_install_page_html('<script>alert(1)</script>', '/mcp', true, 'https://src');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html2);
        $this->assertStringContainsString('&lt;script&gt;', $html2);
    }

    public function test_install_page_endpoint_uses_site_url_scheme(): void
    {
        $html = pw_install_page_html('example.com', '/mcp', true, 'https://src', 'http');
        $this->assertStringContainsString('http://example.com/mcp', $html);
        $this->assertStringNotContainsString('https://example.com/mcp', $html);
    }

    public function test_run_setup_idempotent_does_not_clobber_existing_data(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        mkdir($cms . '/pages', 0775, true);
        file_put_contents($cms . '/pages/index.html', 'MY HOMEPAGE');
        file_put_contents($cms . '/config.env', "MCP_KEY=MYKEY\n");

        pw_run_setup($docRoot, $cms, 'Apache/2.4.41', 'h', '/mcp');

        // Setup must not overwrite an existing homepage or existing config.
        $this->assertSame('MY HOMEPAGE', file_get_contents($cms . '/pages/index.html'));
        $this->assertSame('MYKEY', pw_parse_env((string) file_get_contents($cms . '/config.env'))['MCP_KEY']);
    }

    // ---- key generation & defense-in-depth deny rules --------------------

    public function test_generate_mcp_key_is_64_hex_chars_and_unique(): void
    {
        $k = pw_generate_mcp_key();
        $this->assertSame(64, strlen($k));
        $this->assertTrue(ctype_xdigit($k));
        $this->assertNotSame($k, pw_generate_mcp_key());
    }

    public function test_htaccess_content_denies_env_files(): void
    {
        $this->assertStringContainsString('.env', pw_htaccess_content());
    }

    public function test_nginx_content_denies_config_env(): void
    {
        $c = pw_nginx_content();
        $this->assertStringContainsString('config\\.env', $c);
        $this->assertStringContainsString('deny all', $c);
    }
}
