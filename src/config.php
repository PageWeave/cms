<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  PageWeave CMS — configuration loader
 * ============================================================================
 *  There are NO user-editable constants here. All operator configuration lives
 *  in `_cms/config.env` (KEY=VALUE), auto-created on first run and preserved
 *  across CMS updates — to update, just replace index.php.
 *
 *  `index.php` is pure code. Edit settings in `_cms/config.env`.
 * ============================================================================
 */

const PW_CONFIG_FILE = 'config.env';

/**
 * Built-in default values for the four operator-editable settings. The single
 * source for defaults: used both when `_cms/config.env` is absent/partial and
 * when seeding the file on first run.
 */
function pw_config_defaults(): array
{
    return [
        'mcpKey' => '',
        'sourceUrl' => 'https://github.com/PageWeave/cms',
        'siteUrl' => '',
        'siteTitle' => 'My Site',
    ];
}

/**
 * Load the resolved configuration by parsing `_cms/config.env` over the
 * built-in defaults. Missing file / missing _cms dir => all defaults (MCP
 * disabled). Never echoes or logs values.
 */
function pw_load_config(string $cmsDir): array
{
    $cfg = pw_config_defaults();
    $env = pw_load_env_file($cmsDir . '/' . PW_CONFIG_FILE);
    if ($env === []) {
        return $cfg;
    }
    $map = [
        'MCP_KEY' => 'mcpKey',
        'SOURCE_URL' => 'sourceUrl',
        'SITE_URL' => 'siteUrl',
        'SITE_TITLE' => 'siteTitle',
    ];
    foreach ($map as $envKey => $cfgKey) {
        if (array_key_exists($envKey, $env)) {
            $cfg[$cfgKey] = $env[$envKey];
        }
    }
    return $cfg;
}

/**
 * Build the contents of `_cms/config.env` from explicit values, with helpful
 * comments. Values are always double-quoted so they round-trip safely through
 * pw_parse_env (handles spaces in SITE_TITLE etc.).
 */
function pw_config_env_content(string $mcpKey, string $sourceUrl, string $siteTitle, string $siteUrl): string
{
    $q = static fn (string $v): string => '"' . str_replace('"', '', $v) . '"';
    return <<<ENV
# PageWeave CMS configuration.
# Edit a value after "=" (quotes optional, required if it contains spaces).
# To update the CMS itself, just replace index.php — this file is preserved.

# Bearer token MCP clients must send. Empty => MCP disabled (site still serves).
# Regenerate: php -r 'echo bin2hex(random_bytes(32));'
MCP_KEY={$q($mcpKey)}

# Where your source lives (AGPL §13 "Source" link). Modified versions must
# point this at your published source.
SOURCE_URL={$q($sourceUrl)}

# Canonical base URL, e.g. "https://example.com". Strongly recommended on
# production so the install page does not trust the client Host header.
SITE_URL={$q($siteUrl)}

# Fallback <title> for pages that do not set one.
SITE_TITLE={$q($siteTitle)}
ENV;
}
