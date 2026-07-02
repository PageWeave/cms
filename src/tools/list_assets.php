<?php

declare(strict_types=1);

/** list_assets: enumerate public static files under the webroot assets/ dir. */
function pw_tool_list_assets(array $args, array $ctx): array
{
    return pw_tool_ok(['assets' => pw_list_assets($ctx['docRoot'])]);
}
