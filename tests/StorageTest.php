<?php

declare(strict_types=1);

final class StorageTest extends PwTestCase
{
    // ---- slug validation -------------------------------------------------

    public function test_validate_slug_accepts_valid_paths(): void
    {
        $this->assertNull(pw_validate_slug('about'));
        $this->assertNull(pw_validate_slug('blog/post-1'));
        $this->assertNull(pw_validate_slug('index'));
        $this->assertNull(pw_validate_slug('a-b_c.d'));
        $this->assertNull(pw_validate_slug('/about'));
    }

    public function test_validate_slug_rejects_empty_and_slash(): void
    {
        $this->assertNotNull(pw_validate_slug(''));
        $this->assertNotNull(pw_validate_slug('/'));
        $this->assertNotNull(pw_validate_slug('   '));
    }

    public function test_validate_slug_rejects_traversal_and_slashes(): void
    {
        $this->assertNotNull(pw_validate_slug('..'));
        $this->assertNotNull(pw_validate_slug('a/../b'));
        $this->assertNotNull(pw_validate_slug('a//b'));
        $this->assertNotNull(pw_validate_slug('about/'));
        $this->assertNotNull(pw_validate_slug('.hidden'));
    }

    public function test_validate_slug_rejects_reserved(): void
    {
        $this->assertNotNull(pw_validate_slug('mcp'));
        $this->assertNotNull(pw_validate_slug('assets'));
        $this->assertNotNull(pw_validate_slug('__source'));
    }

    public function test_validate_slug_rejects_null_byte_and_backslash(): void
    {
        $this->assertNotNull(pw_validate_slug("a\0b"));
        $this->assertNotNull(pw_validate_slug('a\\b'));
    }

    // ---- page CRUD -------------------------------------------------------

    public function test_create_and_get_page_roundtrip(): void
    {
        $cms = $this->cmsDir();
        $res = pw_create_page($cms, 'about', '<h1>About</h1>', 'About', 'The team');
        $this->assertTrue($res['ok']);

        $page = pw_get_page($cms, 'about');
        $this->assertNotNull($page);
        $this->assertSame('about', $page['path']);
        $this->assertSame('About', $page['title']);
        $this->assertSame('The team', $page['description']);
        $this->assertSame('<h1>About</h1>', $page['html']);
    }

    public function test_create_page_normalizes_leading_slash(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, '/about', 'body', null, null);
        $this->assertTrue(is_file($cms . '/pages/about.html'));
    }

    public function test_create_page_existing_returns_error(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'about', 'a', null, null);
        $res = pw_create_page($cms, 'about', 'b', null, null);
        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_create_page_invalid_slug_returns_error(): void
    {
        $res = pw_create_page($this->cmsDir(), 'mcp', 'x', null, null);
        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_create_nested_page_creates_dirs(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'blog/2026/hello', 'body', null, null);
        $this->assertTrue(is_file($cms . '/pages/blog/2026/hello.html'));
    }

    public function test_write_page_overwrites_existing(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'about', 'old', 'Old', null);
        pw_write_page($cms, 'about', 'new body', 'New', null);
        $page = pw_get_page($cms, 'about');
        $this->assertSame('new body', $page['html']);
        $this->assertSame('New', $page['title']);
    }

    public function test_get_page_missing_returns_null(): void
    {
        $this->assertNull(pw_get_page($this->cmsDir(), 'nope'));
    }

    public function test_get_page_rejects_traversal_slug(): void
    {
        // pages/ always exists in production; without a read-path guard the
        // escaped path ../partials/header.html resolves and leaks the partial.
        @mkdir($this->cmsDir() . '/pages', 0775, true);
        pw_write_partial($this->cmsDir(), 'header', '<h>HEADER</h>');
        $this->assertNull(pw_get_page($this->cmsDir(), '../partials/header'));
    }

    public function test_page_exists_rejects_traversal_slug(): void
    {
        @mkdir($this->cmsDir() . '/pages', 0775, true);
        pw_write_partial($this->cmsDir(), 'footer', '<f/>');
        $this->assertFalse(pw_page_exists($this->cmsDir(), '../partials/footer'));
    }

    public function test_get_page_rejects_reserved_slugs(): void
    {
        @mkdir($this->cmsDir() . '/pages', 0775, true);
        $this->assertNull(pw_get_page($this->cmsDir(), 'mcp'));
        $this->assertNull(pw_get_page($this->cmsDir(), 'assets'));
    }

    public function test_delete_page(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'about', 'body', null, null);
        $res = pw_delete_page($cms, 'about');
        $this->assertTrue($res['ok']);
        $this->assertFalse(is_file($cms . '/pages/about.html'));
    }

    public function test_delete_page_missing_returns_error(): void
    {
        $res = pw_delete_page($this->cmsDir(), 'nope');
        $this->assertFalse($res['ok']);
    }

    // ---- listing ---------------------------------------------------------

    public function test_list_pages_empty_when_no_dir(): void
    {
        $this->assertSame([], pw_list_pages($this->cmsDir()));
    }

    public function test_list_pages_returns_sorted_with_titles(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'zebra', 'z', 'Z Page', null);
        pw_create_page($cms, 'index', 'h', 'Home', null);
        pw_create_page($cms, 'blog/post', 'p', null, null);

        $list = pw_list_pages($cms);
        $paths = array_column($list, 'path');
        $this->assertSame(['blog/post', 'index', 'zebra'], $paths);

        $byPath = [];
        foreach ($list as $item) {
            $byPath[$item['path']] = $item['title'];
        }
        $this->assertSame('Home', $byPath['index']);
        $this->assertSame('Z Page', $byPath['zebra']);
        $this->assertNull($byPath['blog/post']);
    }

    public function test_list_pages_skips_non_html_files(): void
    {
        $cms = $this->cmsDir();
        mkdir($cms . '/pages', 0775, true);
        file_put_contents($cms . '/pages/about.html', 'x');
        file_put_contents($cms . '/pages/notes.txt', 'y');
        $list = pw_list_pages($cms);
        $this->assertSame(['about'], array_column($list, 'path'));
    }

    // ---- partials --------------------------------------------------------

    public function test_partial_get_returns_empty_when_missing(): void
    {
        $this->assertSame('', pw_get_partial($this->cmsDir(), 'header'));
    }

    public function test_partial_write_then_get(): void
    {
        $cms = $this->cmsDir();
        pw_write_partial($cms, 'header', '<nav>menu</nav>');
        $this->assertSame('<nav>menu</nav>', pw_get_partial($cms, 'header'));
    }

    // ---- assets ----------------------------------------------------------

    public function test_list_assets_empty_when_no_dir(): void
    {
        $this->assertSame([], pw_list_assets($this->webroot()));
    }

    public function test_list_assets_returns_path_url_size(): void
    {
        $root = $this->webroot();
        mkdir($root . '/assets/img', 0775, true);
        file_put_contents($root . '/assets/logo.png', 'abc');
        file_put_contents($root . '/assets/img/photo.jpg', 'de');

        $list = pw_list_assets($root);
        $this->assertCount(2, $list);
        $paths = array_column($list, 'path');
        sort($paths);
        $this->assertSame(['assets/img/photo.jpg', 'assets/logo.png'], $paths);

        $logo = $list[array_search('assets/logo.png', array_column($list, 'path'))];
        $this->assertSame('/assets/logo.png', $logo['url']);
        $this->assertSame(3, $logo['size']);
    }

    // ---- replacements ----------------------------------------------------

    public function test_apply_replacements_replace_first_only(): void
    {
        $r = pw_apply_replacements('aXbXc', [['old_html' => 'X', 'new_html' => 'Y']]);
        $this->assertTrue($r['ok']);
        $this->assertSame('aYbXc', $r['html']);
    }

    public function test_apply_replacements_replace_all(): void
    {
        $r = pw_apply_replacements('aXbXc', [['old_html' => 'X', 'new_html' => 'Y', 'mode' => 'replace_all']]);
        $this->assertSame('aYbYc', $r['html']);
    }

    public function test_apply_replacements_chained(): void
    {
        $r = pw_apply_replacements('hello world', [
            ['old_html' => 'hello', 'new_html' => 'hi'],
            ['old_html' => 'world', 'new_html' => 'earth'],
        ]);
        $this->assertSame('hi earth', $r['html']);
    }

    public function test_apply_replacements_missing_old_html_errors(): void
    {
        $r = pw_apply_replacements('body', [['old_html' => 'zzz', 'new_html' => 'y']]);
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('error', $r);
    }

    public function test_apply_replacements_empty_old_html_errors(): void
    {
        $r = pw_apply_replacements('body', [['old_html' => '', 'new_html' => 'y']]);
        $this->assertFalse($r['ok']);
    }

    // ---- Tier 4: partial-name allowlist (defense in depth) ----------------

    public function test_partial_write_rejects_unknown_name(): void
    {
        pw_write_partial($this->cmsDir(), '../evil', '<x/>');
        $this->assertFileDoesNotExist($this->cmsDir() . '/../evil.html');
        $this->assertFileDoesNotExist($this->cmsDir() . '/partials/../evil.html');
    }

    public function test_partial_get_rejects_unknown_name(): void
    {
        @mkdir($this->cmsDir() . '/partials', 0775, true);
        file_put_contents($this->cmsDir() . '/partials/header.html', '<h>H</h>');
        // Only head/header/footer are allowed; anything else returns ''.
        $this->assertSame('', pw_get_partial($this->cmsDir(), '../evil'));
        $this->assertSame('', pw_get_partial($this->cmsDir(), 'menu'));
        // Allowed name still reads the seeded content.
        $this->assertSame('<h>H</h>', pw_get_partial($this->cmsDir(), 'header'));
    }

    public function test_partial_known_names_still_work(): void
    {
        foreach (['head', 'header', 'footer'] as $name) {
            pw_write_partial($this->cmsDir(), $name, "<$name/>");
            $this->assertSame("<$name/>", pw_get_partial($this->cmsDir(), $name));
        }
    }
}
