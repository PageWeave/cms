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

    public function test_install_page_warns_when_mcp_disabled(): void
    {
        $disabled = pw_install_page_html('example.com', '/mcp', false, 'https://example/src');
        $enabled = pw_install_page_html('example.com', '/mcp', true, 'https://example/src');
        $this->assertStringContainsString('MCP_KEY', $disabled);
        $this->assertStringNotContainsString('MCP disabled', $enabled);
    }

    // ---- run_setup --------------------------------------------------------

    public function test_run_setup_apache_creates_dirs_and_files(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        $res = pw_run_setup($docRoot, $cms, 'Apache/2.4.41', 'example.com', '/mcp', true, 'Site', 'https://src');

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
    }

    public function test_run_setup_nginx_writes_nginx_conf(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        $res = pw_run_setup($docRoot, $cms, 'nginx/1.18', 'example.com', '/mcp', true, 'Site', 'https://src');
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

    public function test_run_setup_idempotent_does_not_clobber_existing_pages(): void
    {
        $docRoot = $this->webroot();
        $cms = $this->cmsDir();
        mkdir($cms . '/pages', 0775, true);
        file_put_contents($cms . '/pages/index.html', 'MY HOMEPAGE');

        pw_run_setup($docRoot, $cms, 'Apache/2.4.41', 'h', '/mcp', true, 'Site', 'https://src');

        // Setup must not overwrite an existing homepage.
        $this->assertSame('MY HOMEPAGE', file_get_contents($cms . '/pages/index.html'));
    }
}
