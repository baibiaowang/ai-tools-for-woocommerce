---
name: ai-tools
description: Operate a WooCommerce store's infrastructure through the AI Tools MCP server (tools prefixed ai-tools-). Use to run/inspect backups, check or send test email via the store's API mailer, read cookie-consent / CPRA / GPC settings, check consent-gated ad pixels and server-side conversions (Meta CAPI / TikTok / GA4), inspect or purge the page cache, read the Google Merchant feed status, and check compliance settings (age gate, Prop 65, accessibility).
---

# Store infrastructure with AI Tools

## Connect
- Same machine: `wp mcp-adapter serve --server=ai-tools --user=<admin>` (STDIO).
- Remote (HTTP): `https://SITE/wp-json/ai-tools/mcp`, Basic auth with an Application Password.
- These tools are **owner-level** (a backup can touch the whole database; mail can send real email). Prefer an admin user.

## Tools
- **`ai-tools-maintenance`** — one entry point, `action` selects the module:
  - `backup_list` (sets, newest first) · `backup_verify {name}` (sha256 + gzip/zip integrity + table count) · **`backup_run`** (starts a db+uploads backup — needs confirmation, uses disk + time)
  - `mail_status` (which API mailer is active; never shows keys) · `mail_log` · `mail_test {to}` (sends one real test email)
  - `consent_status` (cookie banner / CPRA / GPC) · `followup_status` · `cache_status` · `cache_purge`
  - `ads_status` (pixel IDs, whether server-side tokens are set — never the tokens —, recent server-side sends, catalog feed URLs)
- **`ai-tools-notifications`** — email-notification switches.
- **`ai-tools-compliance`** — age gate / Prop 65 / accessibility settings.

## Rules
1. **Secrets are owner-only.** Mail API keys and Meta/TikTok/GA4 tokens are set in wp-admin or `wp-config`; the tools only report whether they are set. Never ask the user to paste a key into chat — point them at the admin screen.
2. **Backups cannot be deleted through MCP.** Retention pruning removes old sets automatically and always keeps at least one.
3. `backup_run` is a real, heavy operation — confirm with the user first (it returns `CONFIRMATION_REQUIRED`).
4. `mail_test` sends a **real email** to the address given — only use it with the user's own address.
5. Schedules, retention, mail keys and cache on/off are set by the owner in WP-CLI or wp-admin, not through these tools.
