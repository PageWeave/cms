# PageWeave CMS

A **self-hostable, single-file PHP CMS** that exposes your website to AI agents over the
[Model Context Protocol (MCP)](https://modelcontextprotocol.io/). Drop one `index.php` on
your web server, set an API key, and let your coding agent build and edit pages, components,
and the document head.

A trimmed-down, open-source, self-hostable sibling of [PageWeave](https://pageweave.dev/) —
no database, no runtime dependencies, no build step required to use it.

[**Read the full plan →**](./PLAN.md)

---

## Features

- **One file.** Upload `dist/index.php` as `index.php` and you're running.
- **MCP server built in.** A `/mcp` endpoint lets agents manage your site (pages, header/footer, `<head>`).
- **No database.** Content is stored as flat HTML files under a generated `_cms/` directory.
- **Zero-config install.** First visit auto-detects your web server and writes the routing config.
- **Zero runtime dependencies.** Needs only PHP 8.3 with default extensions (`json`, `mbstring`, `pcre`).
- **Open source.** AGPL-3.0-or-later.

### MVP tools exposed to agents

`ping`, `list_pages`, `get_page`, `create_page`, `update_page`, `delete_page`,
`get_component` / `update_component` (header, footer), `get_html_head` / `update_html_head`,
`list_assets`.

---

## Quick start (user)

1. **Get the file.** Download the latest `index.php` from [Releases](../../releases), or build it
   yourself (see [Development](#development)).
2. **Upload it** to your web server's document root **as `index.php`**. No editing required.
3. **Visit your domain.** On first load the CMS auto-detects your server (Apache/LiteSpeed/nginx),
   writes the routing config, scaffolds `_cms/`, generates a random MCP key into `_cms/config.env`,
   and shows an **"Installation successful"** page with your MCP endpoint URL and agent setup
   instructions.
4. **Open `_cms/config.env`** to view your generated MCP key (and tweak settings like site title).

That's it. Page serving works out of the box; MCP is ready immediately (a key is generated on first
run). To change settings later — including the MCP key — edit `_cms/config.env`.

### Updating the CMS

To upgrade, just **replace `index.php`** with the new version. Your `_cms/` data and
`_cms/config.env` are preserved automatically — nothing to reconfigure. `index.php` is pure code;
all your settings live in `_cms/config.env`.

### Web server notes

- **Apache / LiteSpeed** — `.htaccess` is written automatically (needs `AllowOverride FileInfo`).
- **nginx** — a `nginx.conf` snippet is generated next to `index.php`; copy it into your server
  block and reload. (`/assets/*` and other real files bypass PHP; everything else routes to `index.php`.)
- **Any server, no rewriting** — the MCP client URL `https://your-host/index.php/mcp` works via PATH_INFO.

---

## Connect your agent (OpenCode Desktop)

Add the CMS as a remote MCP server in your OpenCode config ([docs](https://opencode.ai/docs/mcp-servers/)):

```jsonc
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "pageweave-cms": {
      "type": "remote",
      "url": "https://your-host/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_KEY"
      }
    }
  }
}
```

Then prompt: *"Use pageweave-cms to create a homepage."* Other MCP-compatible clients
(Claude, Cursor, etc.) work the same way — point them at `https://your-host/mcp` with a
`Authorization: Bearer <MCP_KEY>` header.

---

## Configuration

All settings live in **`_cms/config.env`** — a simple `KEY=VALUE` file, auto-created on first run
with a generated MCP key. `index.php` is pure code (edit it only to upgrade); to change a value,
edit `_cms/config.env` and reload — no restart needed.

| Key | Purpose | Default |
|---|---|---|
| `MCP_KEY` | Bearer token for `/mcp`. Auto-generated on first run. Empty ⇒ MCP disabled (site still serves). | generated (64 hex chars) |
| `SITE_URL` | Canonical base URL (e.g. `https://example.com`). Used for the install-page MCP endpoint instead of the `Host` header. **Strongly recommended on production** to avoid trusting a client-controlled header. | `''` |
| `SOURCE_URL` | Where your source lives (AGPL §13 "Source" link). | upstream repo |
| `SITE_TITLE` | Fallback `<title>` for pages without one. | `My Site` |

The `_cms/` data directory is fixed at `<docroot>/_cms`. It is protected from direct web access by
generated `.htaccess`/nginx rules, so `_cms/config.env` (which holds your MCP key) is never served
over HTTP.

---

## How content is stored

```
_cms/
├── config.env                            # your settings: MCP key, site title, source URL, …
├── partials/{head,header,footer}.html   # composed around every page
└── pages/<slug>.html                     # page body + optional frontmatter
assets/                                   # public static files you add (images, etc.)
```

Pages are **body HTML** with an optional HTML-comment frontmatter for `title`/`description`:

```html
<!--
title: About Us
description: A short page about the team
-->
<h1>About</h1>
```

At request time the CMS composes a full document: `<head>` partial + `<title>` +
header partial + page body + footer partial. Editing a partial updates every page instantly.

---

## Development

This repository develops the CMS as **modular source** under `src/` and **compiles** it down to
the single shipped `dist/index.php`. See [AGENTS.md](./AGENTS.md) and [PLAN.md](./PLAN.md) for
the full architecture.

```bash
# Requires PHP 8.3 (pinned via mise)
mise install
composer install
vendor/bin/phpunit        # run tests
php build.php             # build dist/index.php
php -l dist/index.php     # syntax-check the build
php -S localhost:8000 -t dist   # local smoke test → http://localhost:8000/mcp
```

TDD is the working rhythm: red → green → refactor. No runtime Composer dependencies — only dev
tooling (PHPUnit 12, php-cs-fixer).

---

## Security

Found a vulnerability? Please report it **privately** to
**security@pageweave.dev** (or via [GitHub Security
Advisories](https://github.com/PageWeave/cms/security/advisories/new)) — do not
open a public issue. See [SECURITY.md](./SECURITY.md) for the full policy,
threat model, and audit history.

## License

Copyright © 2026 PageWeave CMS contributors.

Licensed under the [GNU Affero General Public License v3.0 or later](./LICENSE) (AGPL-3.0-or-later).
Running a **modified** version on a publicly reachable server requires you to offer the
Corresponding Source to its users (AGPL §13). Running it unmodified only requires pointing to
the upstream `SOURCE_URL`.
