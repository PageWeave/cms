<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  PageWeave CMS — configuration
 * ============================================================================
 *  Edit these constants, then upload this file as `index.php` to your web
 *  server's document root and visit your domain. Page serving works even with
 *  MCP_KEY empty; MCP simply stays disabled until you set it.
 * ============================================================================
 */

// Bearer token that MCP clients must send. Use a long random string.
// Empty string => MCP disabled (site still serves).
const MCP_KEY = '';

// Where your source code lives. Shown as the "Source" link (AGPL §13).
// If you run a modified version, point this at your published source.
const SOURCE_URL = 'https://github.com/pageweave/pageweave-cms';

// Fallback <title> for pages that do not set one.
const SITE_TITLE = 'My Site';

// Where the CMS stores its data. Defaults to a `_cms/` folder next to this
// file (inside the web root, auto-protected). Point this outside the web root
// (an absolute path) for extra safety.
const CMS_DIR = __DIR__ . '/_cms';
