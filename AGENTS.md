# AGENTS.md

A guide for AI coding agents working on **PageWeave CMS**. Read this before editing.
The human-facing overview lives in [README.md](./README.md); the full design in [PLAN.md](./PLAN.md).

## Project overview

A **self-hostable, single-file PHP CMS** with a built-in **MCP** (`/mcp`) HTTP server. Developed
as modular source under `src/` and **compiled** by `build.php` into one readable `dist/index.php`
that users drop on a web server. PHP **8.3**, zero runtime Composer dependencies, no database
(content = flat HTML files under a generated `_cms/` directory). License: AGPL-3.0-or-later.

## Stack & constraints

- **PHP 8.3** (pinned via `mise`; see `.mise.toml`). Never use syntax/features from 8.4+.
- **No runtime dependencies.** The compiled `dist/index.php` must work with only the default
  extensions (`json`, `mbstring`, `pcre`). Do not introduce `require` of any vendor package into
  runtime code. Composer is **dev-only** (PHPUnit 12, php-cs-fixer).
- **Flat global functions, no namespace.** Keeps the concatenated single file trivially readable.
  Prefix function names to avoid collisions (e.g. `pw_*`).
- **Pure-function core.** I/O-touching code (`$_SERVER`, `header()`, `echo`, `file_*`) belongs in
  a thin shell (`router.php`, `transport.php`, `serve.php`, `setup.php`). Everything testable
  (storage, frontmatter, render, jsonrpc, tools) takes inputs and returns outputs — no superglobals.

## Setup commands

```bash
mise install                 # install PHP 8.3 for the project
composer install             # dev tooling only
vendor/bin/phpunit           # run the test suite
vendor/bin/php-cs-fixer fix  # fix style
php build.php                # compile src/ → dist/index.php
php -l dist/index.php        # syntax-check the build (always run after building)
php -S localhost:8000 -t dist # local server for manual smoke testing
```

## Testing instructions

- **TDD is mandatory.** Write the failing test first, implement to green, then refactor.
- `phpunit.xml` is preconfigured. Tests live in `tests/`; name them `<Thing>Test.php`.
- **Storage/frontmatter/render tests** operate against a fresh temp `_cms/` dir per test
  (use `tmpdir()` helpers / `tearDown` cleanup) — never touch the repo's real filesystem.
- **Do not push if tests fail.** Fix until the whole suite is green before committing.
- After **building**, always run `php -l dist/index.php`.
- Add or update tests for any code you change, even if not asked.

## Architecture (where things live)

```
src/env.php         # .env parser (KEY=VALUE), tolerant, zero runtime deps
src/config.php      # config loader: parses _cms/config.env over defaults (NO user-editable consts)
src/bootstrap.php   # PHP 8.3 guard, error handling, PW_CMS_DIR (fixed _cms/ path)
src/setup.php       # first-run: SERVER_SOFTWARE detect → write .htaccess/nginx → scaffold _cms/ + config.env
src/router.php      # REQUEST_URI + method → first-run setup → load config → MCP / page / 404
src/transport.php   # /mcp: Bearer auth + stateless JSON-only Streamable HTTP JSON-RPC dispatch
src/jsonrpc.php     # success()/error() envelopes (JSON-RPC 2.0)
src/registry.php    # tool name → [inputSchema, handler]
src/storage.php     # page/partial/asset CRUD + frontmatter parse/serialize
src/render.php      # compose document: <head>+<title>+header+body+footer
src/serve.php       # GET page serving + install/placeholder + 404 (pure; setup is in router)
src/tools/*.php     # one file per MCP tool
build.php           # ordered concatenation → dist/index.php (pure PHP)
```

**Configuration lives in `_cms/config.env`** (KEY=VALUE), auto-created on first run with a
generated `MCP_KEY` and preserved across updates. `index.php` is pure code — to upgrade, replace
it; to change settings, edit `_cms/config.env`. There are NO user-editable constants in the build.

**Build is ordered concatenation:** `build.php` reads `src/` in a fixed dependency order, strips
each file's leading `<?php`, wraps each in a `/* === src/foo.php === */` separator, and prepends a
banner + a single `<?php`. Output: `dist/index.php`.

## Key design decisions (do not regress)

- **Hand-rolled MCP transport**, stateless + JSON-only, no SSE/sessions. Do **not** adopt
  `php-mcp/server` (ReactPHP event-loop model is incompatible with PHP-FPM shared hosting, and its
  deps can't compile to a readable single file). See PLAN.md §2.2.
- **Dynamic composition**: pages store body only; partials stored once, composed at GET time.
  Never rebuild-all-pages on a partial edit.
- **HTML-comment frontmatter** (`title`, `description`) — not YAML `---` (that would render as text
  if a raw `.html` page file is opened in a browser).
- **Front controller** with catch-all rewrite; `index.php` distinguishes `POST /mcp` vs `GET` page
  by `$_SERVER['REQUEST_URI']` (preserved by internal rewrites on Apache & nginx).
- **Auto-setup** writes `.htaccess`/`nginx.conf` + `_cms/` + `_cms/pages/index.html` (real editable
  install page) on first visit; gated by the `_cms/.installed` marker.

## Security gotchas

- **`MCP_KEY` comparison must be constant-time** (`hash_equals`). Empty key ⇒ MCP disabled,
  site still serves.
- **Slug validation** in `storage.php`: reject `mcp`, `assets`, `index`, empty, and any path
  traversal (`..`, leading `/`, NUL). Normalize to safe path segments before any `file_*` call.
- **`_cms/` must be protected** from direct access: `.htaccess` `Deny from all` inside `_cms/`,
  and the generated nginx config denies it. Never serve `_cms/` contents directly.
- Never log or echo secrets. Bearer tokens only ever compared, never persisted.

## Style & conventions

- Match the existing style in neighboring files. Run `vendor/bin/php-cs-fixer fix` before committing.
- No comments unless they explain *why* (non-obvious) — the code should be self-documenting.
- One tool per file under `src/tools/`; register it in `src/registry.php`.
- Error responses to MCP use the JSON-RPC error envelope; user-facing tool errors return
  `isError: true` content, not HTTP 5xx (except auth/validation at the transport layer).

## Commit conventions

- Conventional Commits: `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`.
- Subject ≤ 72 chars, imperative mood. Body only when the *why* isn't obvious.
- Each commit keeps the test suite green (and the build syntax-clean if `build.php` was touched).
- Never commit `dist/`, `vendor/`, or `_cms/` runtime data (all gitignored).
