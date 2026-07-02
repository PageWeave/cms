# Security Policy

PageWeave CMS is a self-hostable, single-file PHP CMS with a built-in MCP
server. This document covers **reporting vulnerabilities**, the **security
model**, and the **audit history** of the codebase.

## Reporting a vulnerability

Please report security issues **privately** — do not open a public issue.

- **Preferred:** email **security@pageweave.dev**
- **Alternative:** open a GitHub Security Advisory at
  https://github.com/PageWeave/cms/security/advisories/new

Include:
- a description of the issue and its impact,
- the version (`serverInfo.version` from an `initialize` MCP call, or the
  banner at the top of `index.php`),
- a minimal reproduction (request, payload, and observed response).

We aim to acknowledge reports within **3 business days** and to ship a fix or
mitigation within **30 days** for confirmed issues. We support `git` ranges for
credit via GitHub's security advisory process. We will credit reporters by name
unless they prefer otherwise.

Our policy follows coordinated disclosure: please do not publish full details
until a fix has been released, or **90 days** have elapsed since the report.

## Supported versions

Only the latest release line receives security fixes. The version attached to
each GitHub Release is the supported artifact.

## Threat model & security model

PageWeave is designed to run on commodity shared hosting (PHP-FPM, Apache,
LiteSpeed, or nginx). The operator uploads `index.php` and visits their domain;
on first run a random `MCP_KEY` is generated into `_cms/config.env` (which is
protected from direct web access by generated deny rules).

- **Single static bearer secret.** MCP requests authenticate with
  `Authorization: Bearer <MCP_KEY>`. Comparison is constant-time
  (`hash_equals`). An empty `MCP_KEY` (e.g. if the operator blanks it in
  `_cms/config.env`) disables MCP; the site still serves.
- **Stateless, no sessions, no OAuth, no SSE.** Every MCP request is
  independently authenticated. Per the MCP *Security Best Practices*, this
  design is not subject to the confused-deputy, token-passthrough,
  discovery-SSRF, or session-hijack classes of MCP attacks.
- **No database.** Content is flat HTML files under a generated `_cms/`
  directory. Stored HTML is read with `file_get_contents` and echoed — it is
  **never `include`d**, so stored `<?php` tags cannot execute.
- **`_cms/` is access-controlled.** A `.htaccess` (`Require all denied`) is
  written inside `_cms/`, and the generated `.htaccess`/`nginx.conf` deny the
  path. On nginx the operator must apply the generated snippet — the
  application cannot prevent nginx from serving static files directly.
- **Operator-authored HTML is trusted.** Pages/partials are written by the
  authenticated MCP operator and rendered as-is; this is by design for a CMS.

### Operator hardening checklist

- `MCP_KEY` is auto-generated (64 hex chars) on first run; rotate it by editing
  `_cms/config.env` if you suspect compromise.
- Set `SITE_URL` to your canonical URL (avoids trusting the `Host` header).
- Keep the generated `.htaccess`/`nginx.conf` deny rules in place —
  `_cms/config.env` holds your MCP key and must never be web-accessible. The
  `_cms/` data dir is fixed at `<docroot>/_cms` (not relocatable).
- On nginx, copy the generated `nginx.conf` into your server block.
- Serve over HTTPS (consider adding HSTS + a CSP via the head partial).

## Audit history

Findings from the initial security audit (2026-07-02), all remediated. Each row
links conceptually to the fixing commit.

| # | Severity | Finding | Status |
|---|----------|---------|--------|
| 1 | High | Install page persisted the client `Host` header unvalidated → stored XSS + MCP-endpoint poisoning (`json_encode` does not escape `<>` by default, so both the endpoint `<code>` and the snippet `<pre>` were sinks). First requestor could poison `_cms/pages/index.html`. | Fixed `2e80e27` — `SITE_URL` config; `pw_resolve_host()` allowlist-validates `Host`; output `htmlspecialchars`'d; snippet uses `JSON_HEX_TAG\|AMP\|APOS\|QUOT`. |
| 2 | Medium | Unauthenticated read path (`pw_get_page`/`pw_page_exists`) skipped `pw_validate_slug`; encoded traversal (`/..\%2fpartials\%2fheader`) escaped `pages/` and rendered any `.html` under `_cms`. Blocked by default Apache (`AllowEncodedSlashes Off`) but reachable on Apache `On`, nginx, LiteSpeed, PHP dev server. `.html` suffix limited it to HTML files. | Fixed `088c909` — slug validation added to both read functions; per OWASP, validation happens at the app layer. |
| 3 | Low | Tool dispatch catch returned `$e->getMessage()` to MCP clients, leaking internal filesystem paths from exceptions. | Fixed `12cd9bf` — generic `"Tool error"` to client; detail `error_log`'d server-side. |
| 4 | Low | `update_page.replacements` had no cap → authenticated CPU DoS via huge arrays. | Fixed `12cd9bf` — schema `maxItems: 10`, enforced in `pw_apply_replacements`. |
| 5 | Low | MCP 401 responses distinguished "disabled" from "unauthorized", revealing whether MCP was enabled to unauthenticated probes. | Fixed `12cd9bf` — both return an identical `Unauthorized` body. |
| 6 | Info | No default security headers on served pages. | Mitigated `8dbd4e6` — HTML responses emit `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`. Strict CSP/HSTS intentionally omitted (would break operator HTML + install-page inline styles). |
| 7 | Info | Partial name unvalidated at the storage layer (safe due to tool callers hard-coding names). | Mitigated `8dbd4e6` — `pw_partial_file` allowlists `head`/`header`/`footer`. |
| 8 | Info | `MCP_KEY` had no documented strength requirement. | Mitigated `8dbd4e6` — config + banner document ≥ 32 bytes with a generation snippet. |

### Notes for existing installs

- **Pre-`2e80e27` installs** that were first hit by a malicious/scanner
  request may have a poisoned homepage (`_cms/pages/index.html`). After
  upgrading, delete that file and revisit the domain to regenerate a clean
  install page (or set `SITE_URL` and re-create the homepage via MCP).
- Upgrading is a single-file replacement: drop in the new `index.php`. Stored
  `_cms/` content — including `_cms/config.env` — is unaffected.
- **0.1.x → config.env migration:** earlier versions stored `MCP_KEY` (and other
  settings) as constants at the top of `index.php`. After replacing `index.php`,
  those constants are gone; on first run a fresh `_cms/config.env` with a newly
  generated key is written. Copy your old `MCP_KEY` into `_cms/config.env` to
  keep existing MCP clients working.

## Scope

This policy covers the PageWeave CMS codebase in this repository. Dependencies
in third-party dependencies (dev-only: PHPUnit, php-cs-fixer) are out of scope —
report those to their respective maintainers.

## License

PageWeave CMS is licensed AGPL-3.0-or-later. This security policy is provided
as-is without warranty.
