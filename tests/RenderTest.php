<?php

declare(strict_types=1);

final class RenderTest extends PwTestCase
{
    public function test_compose_full_document(): void
    {
        $out = pw_compose_document('<p>body</p>', 'About', '<meta charset="utf-8">', '<nav>h</nav>', '<footer>f</footer>', 'Site');
        $expected = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title>About</title>\n</head>\n<body>\n<nav>h</nav>\n<p>body</p>\n<footer>f</footer>\n</body>\n</html>\n";
        $this->assertSame($expected, $out);
    }

    public function test_compose_title_falls_back_to_site_title(): void
    {
        $out = pw_compose_document('body', null, '', '', '', 'My Site');
        $this->assertStringContainsString('<title>My Site</title>', $out);
    }

    public function test_compose_page_title_overrides_site_title(): void
    {
        $out = pw_compose_document('body', 'Page', '', '', '', 'Site');
        $this->assertStringContainsString('<title>Page</title>', $out);
        $this->assertStringNotContainsString('<title>Site</title>', $out);
    }

    public function test_compose_empty_title_when_both_missing(): void
    {
        $out = pw_compose_document('body', null, '', '', '', '');
        $this->assertStringContainsString('<title></title>', $out);
    }

    public function test_compose_body_inserted_verbatim(): void
    {
        $out = pw_compose_document('<main id="x">hi <strong>there</strong></main>', null, '', '', '', 'S');
        $this->assertStringContainsString('<main id="x">hi <strong>there</strong></main>', $out);
    }

    public function test_compose_empty_partials_still_valid(): void
    {
        $out = pw_compose_document('b', null, '', '', '', 'S');
        $this->assertStringStartsWith("<!DOCTYPE html>\n<html>\n<head>", $out);
        $this->assertStringEndsWith("</html>\n", $out);
        $this->assertSame(1, substr_count($out, '<head>'));
        $this->assertSame(1, substr_count($out, '</html>'));
    }

    public function test_render_page_reads_partials_and_composes(): void
    {
        $cms = $this->cmsDir();
        pw_write_partial($cms, 'head', '<meta name="x" content="y">');
        pw_write_partial($cms, 'header', '<header>NAV</header>');
        pw_write_partial($cms, 'footer', '<footer>FOOT</footer>');
        pw_create_page($cms, 'about', '<p>about</p>', 'About', null);

        $page = pw_get_page($cms, 'about');
        $out = pw_render_page($cms, $page, 'Site');

        $this->assertStringContainsString('<meta name="x" content="y">', $out);
        $this->assertStringContainsString('<header>NAV</header>', $out);
        $this->assertStringContainsString('<footer>FOOT</footer>', $out);
        $this->assertStringContainsString('<p>about</p>', $out);
        $this->assertStringContainsString('<title>About</title>', $out);
    }

    public function test_render_page_missing_partials_default_empty(): void
    {
        $cms = $this->cmsDir();
        pw_create_page($cms, 'about', 'body', null, null);
        $page = pw_get_page($cms, 'about');
        $out = pw_render_page($cms, $page, 'Site');
        $this->assertStringContainsString('<title>Site</title>', $out);
        $this->assertStringContainsString('body', $out);
    }
}
