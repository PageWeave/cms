<?php

declare(strict_types=1);

/** list_pages: list all pages (path and title). */
function pw_tool_list_pages(array $args, array $ctx): array
{
    return pw_tool_ok(['pages' => pw_list_pages($ctx['cmsDir'])]);
}
