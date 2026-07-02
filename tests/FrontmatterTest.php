<?php

declare(strict_types=1);

final class FrontmatterTest extends PwTestCase
{
    public function test_no_frontmatter_returns_raw_as_html(): void
    {
        $r = pw_parse_page("<h1>Hello</h1>\n<p>world</p>");
        $this->assertNull($r['title']);
        $this->assertNull($r['description']);
        $this->assertSame("<h1>Hello</h1>\n<p>world</p>", $r['html']);
    }

    public function test_parses_title_and_description(): void
    {
        $raw = "<!--\ntitle: About\ndescription: The team\n-->\n<h1>About</h1>";
        $r = pw_parse_page($raw);
        $this->assertSame('About', $r['title']);
        $this->assertSame('The team', $r['description']);
        $this->assertSame('<h1>About</h1>', $r['html']);
    }

    public function test_parses_title_only(): void
    {
        $r = pw_parse_page("<!--\ntitle: Just Title\n-->\nbody");
        $this->assertSame('Just Title', $r['title']);
        $this->assertNull($r['description']);
        $this->assertSame('body', $r['html']);
    }

    public function test_leading_prose_html_comment_is_body_not_frontmatter(): void
    {
        // A real HTML comment with prose must stay in the body.
        $raw = "<!-- this is a comment -->\n<p>hi</p>";
        $r = pw_parse_page($raw);
        $this->assertNull($r['title']);
        $this->assertNull($r['description']);
        $this->assertSame($raw, $r['html']);
    }

    public function test_comment_with_only_unknown_keys_stays_in_body(): void
    {
        // Only treat as frontmatter if at least one known key is present.
        $raw = "<!--\nfoo: bar\nbaz: qux\n-->\nbody";
        $r = pw_parse_page($raw);
        $this->assertNull($r['title']);
        $this->assertNull($r['description']);
        $this->assertSame($raw, $r['html']);
    }

    public function test_body_starting_with_html_comment_after_frontmatter(): void
    {
        $raw = "<!--\ntitle: T\n-->\n<!-- real comment -->\nbody";
        $r = pw_parse_page($raw);
        $this->assertSame('T', $r['title']);
        $this->assertSame("<!-- real comment -->\nbody", $r['html']);
    }

    public function test_serialize_no_metadata_returns_plain_html(): void
    {
        $this->assertSame('<p>x</p>', pw_serialize_page('<p>x</p>', null, null));
        $this->assertSame('<p>x</p>', pw_serialize_page('<p>x</p>', '', ''));
    }

    public function test_serialize_with_title_and_description(): void
    {
        $out = pw_serialize_page('<b>hi</b>', 'About', 'The team');
        $this->assertSame("<!--\ntitle: About\ndescription: The team\n-->\n<b>hi</b>", $out);
    }

    public function test_serialize_round_trips_through_parse(): void
    {
        $html = "<main>\n  <p>content</p>\n</main>";
        $serialized = pw_serialize_page($html, 'My Page', 'A description');
        $parsed = pw_parse_page($serialized);
        $this->assertSame('My Page', $parsed['title']);
        $this->assertSame('A description', $parsed['description']);
        $this->assertSame($html, $parsed['html']);
    }

    public function test_serialize_flattens_newlines_in_metadata(): void
    {
        $out = pw_serialize_page('b', "line1\nline2", null);
        $this->assertStringNotContainsString("line1\nline2", $out);
        $parsed = pw_parse_page($out);
        $this->assertSame('line1 line2', $parsed['title']);
    }
}
