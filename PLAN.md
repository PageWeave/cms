# PageWeave CMS — Project Plan

A **self-hostable, single-file PHP CMS** that exposes a website to AI agents via a
**Model Context Protocol (MCP)** HTTP server. Drop one `index.php` on a web server,
set an API key, and your coding agent can read/create/update pages, components, and
the document head. A trimmed-down, open-source, self-hostable sibling of
[PageWeave](https://pageweave.dev/).

- **License:** AGPL-3.0-or-later (strong copyleft; network-use source disclosure, §13)
- **PHP floor:** 8.3
- **Runtime dependencies:** none (no Composer packages shipped; single readable file)

---

## 1. Goals & Non-Goals

### Goals (MVP)
- One drop-in `index.php` that powers both the **website** (GET) and the **MCP server** (POST `/mcp`).
- Manage pages, header/footer components, and the global `<head>` from an AI agent.
- No database. Content stored as flat HTML files.
- Zero-config install: upload the file, set `MCP_KEY`, visit the domain.
- Fully test-driven development (PHPUnit).

### Non-Goals (explicitly deferred)
- Versioning / history
- Multi-website (single site only)
- Analytics
- Forms
- Asset upload tooling (assets are static files the user drops in `assets/`; `list_assets` only)
- Themes / Liquid templating
- Per-instance/per-page complex metadata beyond `title` + `description`

---

## 2. Architecture

### 2.1 Front controller
`index.php` is the single entry point (DirectoryIndex). Web server routes everything to it:

- **Apache / LiteSpeed** — auto-generated `.htaccess`:
  ```apache
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.php [L]
  ```
- **nginx** — generated `nginx.conf` snippet (copy-paste; needs manual reload):
  ```nginx
  location / { try_files $uri $uri/ /index.php?$query_string; }
  ```

`index.php` decides mode by inspecting `$_SERVER['REQUEST_URI']` (preserved by internal
rewrites on both servers) and request method:

| Request | Mode |
|---|---|
| `POST /mcp` | **MCP endpoint** (Bearer auth → JSON-RPC dispatch) |
| `GET *` | **Page render** (resolve slug → compose document → serve) |
| first visit (no `_cms/.installed` marker) | **Auto-setup** |

Real files (`/assets/*`, existing static files) bypass PHP.

### 2.2 MCP transport — hand-rolled, stateless, JSON-only
We do **not** use [`php-mcp/server`](https://github.com/php-mcp/server):
- Its HTTP transports run on **ReactPHP** — a long-lived process binding its own port.
  Incompatible with shared-hosting Apache/nginx + PHP-FPM (our deployment model).
- Its dependency tree (`react/*`, `symfony/finder`, `phpdocumentor/reflection-docblock`,
  `opis/json-schema`, `php-mcp/schema`) cannot be compiled into a *readable* single `.php`
  (only into a PHAR archive).

Instead we implement the **Streamable HTTP** transport (MCP `2025-06-18`) in pure PHP:
- POST a single JSON-RPC 2.0 request → respond `Content-Type: application/json`.
- **Stateless**, no `Mcp-Session-Id`, no SSE streaming.
- The spec explicitly allows this (server *may* return JSON directly; session IDs are optional;
  stateless mode is supported). Fully compliant with modern clients (Claude, Cursor, OpenCode).

JSON-RPC methods handled: `initialize`, `notifications/initialized`, `ping`,
`tools/list`, `tools/call`. (`notifications/initialized` → 202 Accepted, no body.)

### 2.3 Storage (no database)
Everything under a dynamically generated `_cms/` directory (protected from direct access):

```
_cms/
├── .installed              # setup-completed marker
├── partials/
│   ├── head.html           # additional <head> content (meta, favicon, CSS links)
│   ├── header.html         # global header partial
│   └── footer.html         # global footer partial
└── pages/
    ├── index.html          # home page (served at /)
    ├── about.html
    └── ...
```

**Dynamic composition** (chosen over per-page rebuild):
- Each page file stores only its **body HTML** plus an optional HTML-comment frontmatter.
- Partials are stored **once** and composed at GET time → single source of truth,
  instant global updates, no rebuild cascade.

Final document composed at request time:
```html
<!DOCTYPE html><html><head>
{partials/head.html}
<title>{page.title or SITE_TITLE}</title>
</head><body>
{partials/header.html}
{page body}
{partials/footer.html}
</body></html>
```

### 2.4 Page file format — HTML-comment frontmatter
YAML `---` frontmatter would render as visible text if a browser opens the raw `.html`
file directly. Instead, an **HTML comment block** at the top — invisible to browsers and
parseable with a trivial regex (no YAML dependency):

```html
<!--
title: About Us
description: A short page about the team
-->
<h1>About</h1>
<p>...</p>
```

- Optional leading block: file begins with `<!--\n`, lines of `key: value`, ends `-->\n`.
- Recognized keys (MVP): `title`, `description`. Unknown keys ignored.
- **Body** = everything after the closing `-->`. Absent block ⇒ whole file is body, no metadata.
- `create_page` / `update_page` accept optional `title` / `description`; storage rewrites the header.

### 2.5 Auto-setup & install page
On first visit (marker `_cms/.installed` absent), `index.php`:

1. Detects web server via `$_SERVER['SERVER_SOFTWARE']`:
   - `Apache` / `LiteSpeed` → writes `.htaccess`.
   - `nginx` → writes `nginx.conf` (copy-paste + reload instructions; cannot auto-activate).
   - unknown → attempts `.htaccess` (harmless if unused).
2. Creates `_cms/{partials,pages}` + default empty partials.
3. Writes `_cms/pages/index.html` — a real, editable **"Installation successful"** page with:
   - detected server + rewrite-config status (or copy-paste box if not writable),
   - MCP endpoint URL `https://{host}/mcp`,
   - **OpenCode Desktop** setup snippet (`type:"remote"`, `url`, Bearer `headers`),
   - link to https://opencode.ai/docs/mcp-servers/,
   - `MCP_KEY` status notice (if unset → "⚠️ set MCP_KEY in index.php"),
   - AGPL §13 "Source" link → `SOURCE_URL`.
4. Writes the `.installed` marker.

### 2.6 MCP_KEY gating
- `MCP_KEY` unset/placeholder → MCP **disabled**: any `POST /mcp` returns a clear
  `MCP disabled — set MCP_KEY` error. **Page serving still works.**
- `MCP_KEY` set → MCP enabled; Bearer token compared in **constant time**.

### 2.7 Assets
Assets are **public static files** (referenced by URL in HTML), so they live at webroot
`assets/` (served directly by the web server, bypassing PHP), **not** in protected `_cms/`.
- Tool **`list_assets`** enumerates `assets/`, returns `[{path, url, size}]`.
- No `upload_asset` in MVP — user drops files into `assets/` via FTP/SSH.

---

## 3. Configuration constants (top of compiled `index.php`)

| Constant | Purpose | Default |
|---|---|---|
| `MCP_KEY` | Bearer token for `/mcp`. Empty ⇒ MCP disabled. | `''` (must be set) |
| `SOURCE_URL` | AGPL §13 source link target. | upstream repo URL |
| `SITE_TITLE` | Fallback `<title>` when a page has none. | `'My Site'` |
| `CMS_DIR` | `_cms/` location (absolute or webroot-relative). | `__DIR__ . '/_cms'` |

---

## 4. MCP tool set (MVP)

Simpler than PageWeave: body-only pages, no versions/site/lang.

| Tool | Params | Returns |
|---|---|---|
| `ping` | — | `{status: "ok"}` |
| `list_pages` | — | `[{path, title}]` |
| `get_page` | `path` | `{path, html, title, description}` |
| `create_page` | `path, html, title?, description?` | `{path, ok}` |
| `update_page` | `path` + (`html` overwrite **or** `replacements:[{old_html,new_html,mode}]`), `title?`, `description?` | `{path, ok}` |
| `delete_page` | `path` | `{path, ok}` |
| `get_component` | `component: header\|footer` | `{html}` |
| `update_component` | `component, html` | `{ok}` |
| `get_html_head` | — | `{html}` |
| `update_html_head` | `html` | `{ok}` |
| `list_assets` | — | `[{path, url, size}]` |

**Reserved slugs** (rejected at create/update): `mcp`, `assets`, `index`, empty.
Path traversal (`..`, leading slash) rejected; slugs normalized to safe path segments.

---

## 5. Project structure (multi-file dev → single-file build)

```
pageweave_cms/
├── src/
│   ├── config.php        # MCP_KEY, SOURCE_URL, SITE_TITLE, CMS_DIR — TOP of build
│   ├── bootstrap.php     # PHP 8.3 guard, error handling, base path
│   ├── setup.php         # first-run: detect server, write .htaccess/nginx, scaffold _cms/
│   ├── router.php        # REQUEST_URI + method → MCP / setup / page / 404
│   ├── transport.php     # auth + JSON-RPC 2.0 dispatch (stateless, JSON-only)
│   ├── jsonrpc.php       # success()/error() envelopes
│   ├── registry.php      # tool name → [inputSchema, handler]
│   ├── storage.php       # page/partial/asset CRUD (pure-ish; temp-dir testable)
│   ├── render.php        # assemble document: head+title+header+body+footer
│   ├── serve.php         # GET page serving + placeholders + 404
│   └── tools/*.php       # one file per tool (incl. list_assets)
├── tests/                # PHPUnit — storage/frontmatter/render/jsonrpc/tools
├── build.php             # ordered concatenation → dist/index.php (pure PHP)
├── composer.json         # DEV-ONLY: phpunit ^12, php-cs-fixer; no runtime require
├── phpunit.xml
├── LICENSE               # AGPL-3.0
├── README.md
├── AGENTS.md
└── dist/
    └── index.php         # compiled drop-in artifact (the file users upload)
```

### 5.1 Build (`build.php`)
Pure concatenation (no PHAR / scoper needed — zero deps):
1. Read `src/` files in a fixed dependency order.
2. Strip each file's leading `<?php`, trim.
3. Wrap each with a `/* === src/foo.php === */` separator for readability.
4. Prepend a banner (title, version, setup instructions, AGPL notice) + the config block + a single `<?php`.
5. Output `dist/index.php` (~1500 lines, readable).

### 5.2 Testability principle
Core logic is **pure functions taking inputs → returning outputs**, wrapped by a thin I/O shell.
E.g. the JSON-RPC dispatcher takes `(method, params, storage-handle)` and returns a response
array — no `$_SERVER`/`echo` in the unit under test. The HTTP shell (router/transport) is tested
via a fake request struct. Storage tests run against a temp `_cms/` dir.

---

## 6. Development tooling

- **PHP 8.3** pinned via `mise` (mirrors sibling `pageweave` repo).
- **PHPUnit 12** (dev-only; requires PHP ≥ 8.3). TDD: red → green → refactor.
- **php-cs-fixer** (dev-only; never shipped to `dist/`).
- **Manual smoke test:** `php8.3 -S localhost:8000 -t dist` then point an MCP client at `http://localhost:8000/mcp`.
- **Build smoke test:** `php8.3 -l dist/index.php`.

No runtime Composer dependencies — the compiled `dist/index.php` requires nothing but PHP 8.3
with the default extensions (`json`, `mbstring`, `pcre`).

---

## 7. License — AGPL-3.0-or-later

Chosen over MIT/GPL because **AGPL §13** closes the SaaS loophole: anyone running a **modified**
version on a publicly reachable server must offer the Corresponding Source to remote users.
Users running the **unmodified** upstream only need to point to `SOURCE_URL`. A built-in
`SOURCE_URL` constant + `GET /__source` redirect + "Source" link on the install page make
§13 compliance trivial for everyone.

`-or-later` (FSF-recommended): future-proofs against AGPL-4, maximizes combining/linking
compatibility with other AGPL/GPL-3 software.

---

## 8. Implementation order (TDD)

1. Repo scaffolding (`composer.json`, `phpunit.xml`, `LICENSE`, `mise`, dirs).
2. Storage layer (page/partial CRUD + frontmatter parse/serialize).
3. Render (document composition).
4. JSON-RPC envelopes.
5. Transport (auth + dispatch).
6. Tools + registry (one test per tool).
7. Router + serve (GET path).
8. Setup (first-run + `.htaccess`/nginx + install page).
9. `build.php` + `dist/index.php`.
10. Manual smoke test with OpenCode Desktop.

---

## 9. Roadmap (post-MVP)

- `upload_asset` tool
- Per-page richer metadata / sitemap generation
- Forms (mailto-based submission)
- Optional full-page HTML cache (invalidate on any write)
- Theme support
- Multi-site
