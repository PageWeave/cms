<?php

declare(strict_types=1);

final class ToolsTest extends PwTestCase
{
    private function decode(array $result): array
    {
        $this->assertFalse($result['isError'] ?? true, 'tool returned isError: ' . ($result['content'][0]['text'] ?? ''));
        return json_decode($result['content'][0]['text'], true);
    }

    // ---- list_pages -------------------------------------------------------

    public function test_list_pages_returns_pages_array(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'b', 'About', null);
        $r = pw_tool_list_pages([], $this->ctx());
        $data = $this->decode($r);
        $this->assertSame([['path' => 'about', 'title' => 'About']], $data['pages']);
    }

    // ---- get_page ---------------------------------------------------------

    public function test_get_page_returns_content(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<p>hi</p>', 'About', 'desc');
        $r = pw_tool_get_page(['path' => 'about'], $this->ctx());
        $data = $this->decode($r);
        $this->assertSame('about', $data['path']);
        $this->assertSame('<p>hi</p>', $data['html']);
        $this->assertSame('About', $data['title']);
        $this->assertSame('desc', $data['description']);
    }

    public function test_get_page_missing_is_error(): void
    {
        $r = pw_tool_get_page(['path' => 'nope'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_get_page_missing_path_is_error(): void
    {
        $r = pw_tool_get_page([], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    // ---- create_page ------------------------------------------------------

    public function test_create_page_writes_and_returns_ok(): void
    {
        $r = pw_tool_create_page(['path' => 'about', 'html' => '<b>x</b>', 'title' => 'A'], $this->ctx());
        $data = $this->decode($r);
        $this->assertTrue($data['ok']);
        $this->assertSame('about', $data['path']);
        $page = pw_get_page($this->cmsDir(), 'about');
        $this->assertSame('<b>x</b>', $page['html']);
        $this->assertSame('A', $page['title']);
    }

    public function test_create_page_missing_html_is_error(): void
    {
        $r = pw_tool_create_page(['path' => 'about'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_create_page_reserved_slug_is_error(): void
    {
        $r = pw_tool_create_page(['path' => 'mcp', 'html' => 'x'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_create_page_duplicate_is_error(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'a', null, null);
        $r = pw_tool_create_page(['path' => 'about', 'html' => 'b'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    // ---- update_page ------------------------------------------------------

    public function test_update_page_overwrite_html(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'old', 'Title', null);
        $r = pw_tool_update_page(['path' => 'about', 'html' => 'new'], $this->ctx());
        $this->assertFalse($r['isError']);
        $page = pw_get_page($this->cmsDir(), 'about');
        $this->assertSame('new', $page['html']);
        $this->assertSame('Title', $page['title']); // preserved
    }

    public function test_update_page_replacements(): void
    {
        pw_create_page($this->cmsDir(), 'about', '<h1>Old</h1>', null, null);
        $r = pw_tool_update_page(['path' => 'about', 'replacements' => [
            ['old_html' => 'Old', 'new_html' => 'New'],
        ]], $this->ctx());
        $this->assertFalse($r['isError']);
        $this->assertSame('<h1>New</h1>', pw_get_page($this->cmsDir(), 'about')['html']);
    }

    public function test_update_page_html_and_replacements_mutually_exclusive(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'x', null, null);
        $r = pw_tool_update_page(['path' => 'about', 'html' => 'y', 'replacements' => []], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_update_page_missing_is_error(): void
    {
        $r = pw_tool_update_page(['path' => 'nope', 'html' => 'x'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_update_page_no_html_or_replacements_is_error(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'x', null, null);
        $r = pw_tool_update_page(['path' => 'about'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_update_page_can_change_title(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'x', 'Old', null);
        pw_tool_update_page(['path' => 'about', 'html' => 'x', 'title' => 'New'], $this->ctx());
        $this->assertSame('New', pw_get_page($this->cmsDir(), 'about')['title']);
    }

    // ---- delete_page ------------------------------------------------------

    public function test_delete_page_removes_file(): void
    {
        pw_create_page($this->cmsDir(), 'about', 'x', null, null);
        $r = pw_tool_delete_page(['path' => 'about'], $this->ctx());
        $this->assertFalse($r['isError']);
        $this->assertFalse(is_file($this->cmsDir() . '/pages/about.html'));
    }

    public function test_delete_page_missing_is_error(): void
    {
        $r = pw_tool_delete_page(['path' => 'nope'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    // ---- components -------------------------------------------------------

    public function test_get_and_update_header_component(): void
    {
        $r = pw_tool_update_component(['component' => 'header', 'html' => '<nav>n</nav>'], $this->ctx());
        $this->assertFalse($r['isError']);
        $r = pw_tool_get_component(['component' => 'header'], $this->ctx());
        $data = $this->decode($r);
        $this->assertSame('<nav>n</nav>', $data['html']);
    }

    public function test_get_and_update_footer_component(): void
    {
        pw_tool_update_component(['component' => 'footer', 'html' => '<f/>'], $this->ctx());
        $this->assertSame('<f/>', $this->decode(pw_tool_get_component(['component' => 'footer'], $this->ctx()))['html']);
    }

    public function test_get_component_invalid_is_error(): void
    {
        $r = pw_tool_get_component(['component' => 'nav'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    public function test_update_component_missing_html_is_error(): void
    {
        $r = pw_tool_update_component(['component' => 'header'], $this->ctx());
        $this->assertTrue($r['isError']);
    }

    // ---- html head --------------------------------------------------------

    public function test_get_html_head_returns_empty_when_unset(): void
    {
        $data = $this->decode(pw_tool_get_html_head([], $this->ctx()));
        $this->assertSame('', $data['html']);
    }

    public function test_update_html_head(): void
    {
        $r = pw_tool_update_html_head(['html' => '<meta charset="utf-8">'], $this->ctx());
        $this->assertFalse($r['isError']);
        $this->assertSame('<meta charset="utf-8">', $this->decode(pw_tool_get_html_head([], $this->ctx()))['html']);
    }

    public function test_update_html_head_missing_html_is_error(): void
    {
        $this->assertTrue(pw_tool_update_html_head([], $this->ctx())['isError']);
    }

    // ---- list_assets ------------------------------------------------------

    public function test_list_assets_tool(): void
    {
        mkdir($this->webroot() . '/assets', 0775, true);
        file_put_contents($this->webroot() . '/assets/logo.png', 'abc');
        $data = $this->decode(pw_tool_list_assets([], $this->ctx()));
        $this->assertCount(1, $data['assets']);
        $this->assertSame('/assets/logo.png', $data['assets'][0]['url']);
    }

    // ---- Tier 3: replacements cap + exception leak ------------------------

    public function test_update_page_replacements_capped_at_ten(): void
    {
        pw_create_page($this->cmsDir(), 'about', str_repeat('x', 200), null, null);
        $replacements = [];
        for ($i = 0; $i < 11; $i++) {
            $replacements[] = ['old_html' => 'x', 'new_html' => 'y'];
        }
        $r = pw_tool_update_page(['path' => 'about', 'replacements' => $replacements], $this->ctx());
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('10', $r['content'][0]['text']);
    }

    public function test_update_page_ten_replacements_allowed(): void
    {
        pw_create_page($this->cmsDir(), 'about', str_repeat('x', 10), null, null);
        $replacements = [];
        for ($i = 0; $i < 10; $i++) {
            $replacements[] = ['old_html' => 'x', 'new_html' => 'y'];
        }
        $r = pw_tool_update_page(['path' => 'about', 'replacements' => $replacements], $this->ctx());
        $this->assertFalse($r['isError']);
    }

    public function test_tool_exception_returns_generic_message_no_path_leak(): void
    {
        // A handler that throws must surface a generic message, never internals.
        $registry = ['boom' => [
            'description' => 'test',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'handler' => 'pw_test_throwing_handler',
        ]];
        $result = pw_mcp_dispatch_tool('boom', [], $this->ctx(), $registry);
        $this->assertTrue($result['isError']);
        $this->assertSame('Tool error', $result['content'][0]['text']);
        $this->assertStringNotContainsString('/var/www', $result['content'][0]['text']);
        $this->assertStringNotContainsString('sensitive', $result['content'][0]['text']);
    }
}

function pw_test_throwing_handler(array $args, array $ctx): array
{
    throw new \RuntimeException('sensitive detail /var/www/secret.php');
}
