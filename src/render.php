<?php

declare(strict_types=1);

/**
 * Document composition: assemble a full HTML document from the global
 * partials (head, header, footer) and a page body.
 *
 * The `<title>` is auto-injected from the page title, falling back to the
 * configured site title. The head partial should contain meta, favicon, and
 * CSS link tags — not a <title> (that would duplicate the injected one).
 */

function pw_compose_document(
    string $body,
    ?string $title,
    string $head,
    string $header,
    string $footer,
    string $siteTitle
): string {
    $resolvedTitle = $title ?? $siteTitle;
    return "<!DOCTYPE html>\n"
        . "<html>\n"
        . "<head>\n"
        . $head . ($head !== '' && !str_ends_with($head, "\n") ? "\n" : '')
        . '<title>' . htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') . "</title>\n"
        . "</head>\n"
        . "<body>\n"
        . $header . ($header !== '' && !str_ends_with($header, "\n") ? "\n" : '')
        . $body . "\n"
        . $footer . ($footer !== '' && !str_ends_with($footer, "\n") ? "\n" : '')
        . "</body>\n"
        . "</html>\n";
}

function pw_render_page(string $base, array $page, string $siteTitle): string
{
    return pw_compose_document(
        $page['html'] ?? '',
        $page['title'] ?? null,
        pw_get_partial($base, 'head'),
        pw_get_partial($base, 'header'),
        pw_get_partial($base, 'footer'),
        $siteTitle
    );
}
