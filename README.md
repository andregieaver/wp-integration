# wp-integration

Two pieces that let an AI coding agent develop WordPress plugins on a live site:

- **`plugin/wp-agent-bridge`** — a WordPress plugin exposing a guarded REST API
  over the source of plugins you nominate.
- **`mcp-server`** — an MCP server that wraps that API, so the agent sees it as
  a set of tools rather than as HTTP.

## Why it is shaped this way

The core REST API cannot reach plugin source, so an agent asked to fix a bug has
no way to see the code. This fills that gap — and in doing so becomes a remote
code execution path into the site, which is what every design decision below is
about.

MCP lives outside WordPress, in a process running next to the agent over stdio.
The plugin stays a plain REST plugin with one way in, and nothing about the MCP
layer is reachable from the internet.

## What guards it

| Guard | What it stops |
| --- | --- |
| Nominated plugins only | The API cannot see core, themes, or any plugin you did not tick. |
| Two credentials | An application password **and** a separate `X-Bridge-Secret`. A leaked application password alone opens nothing. |
| `realpath` confinement | Traversal and symlinks pointing out of the plugin folder. Tested in `tests/`. |
| Extension allowlist | `.htaccess`, `.user.ini`, shell scripts, binaries. |
| Syntax check before write | A PHP fatal that white-screens the site — including the admin screen you would need to fix it. |
| `expected_sha` on writes | Silently clobbering an edit someone made in the plugin editor since you read the file. |
| Backup before overwrite | Anything. Every write and delete is undoable. |
| Audit log | Not knowing what changed, when, and from where. |
| Self-editing refused | The bridge rewriting the file currently serving the request. |

TLS is required. The bridge refuses plain HTTP unless the site is a local
environment, because the secret is worthless once it crosses the wire in clear.

## Setup

### 1. Install the plugin

Copy `plugin/wp-agent-bridge/` into `wp-content/plugins/` on the site and
activate it. Then:

1. **Tools → Agent Bridge → Generate secret.** Copy it; it is shown once and
   only its hash is stored.
2. Tick the plugins the bridge may read and write. Nothing is ticked by default,
   and the bridge itself can never be ticked.
3. **Users → Profile → Application Passwords** — create one for your admin
   account.

### 2. Build the MCP server

```bash
cd mcp-server
npm install
npm run build
```

### 3. Register it with Claude Code

```bash
claude mcp add wordpress \
  --env WP_BRIDGE_URL=https://your-site.com \
  --env WP_BRIDGE_USER=your-admin-login \
  --env WP_BRIDGE_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx" \
  --env WP_BRIDGE_SECRET=the-secret-from-step-1 \
  -- node /absolute/path/to/mcp-server/dist/index.js
```

Verify with `/mcp` in Claude Code, or ask it to call `wp_status`.

## Tools

| Tool | Does |
| --- | --- |
| `wp_status` | Versions, managed plugins, whether writing is on, debug log state |
| `wp_list_plugins` | Everything installed, with active and managed state |
| `wp_tree` | Recursive file listing with hashes |
| `wp_read_file` | One file's contents + `sha256` |
| `wp_write_file` | Create or overwrite; lints, backs up, requires `expected_sha` |
| `wp_delete_file` | Delete, with a backup |
| `wp_scaffold_plugin` | New plugin folder with a working entry file |
| `wp_activate_plugin` / `wp_deactivate_plugin` | Toggle a managed plugin |
| `wp_tail_log` | Last N lines of `debug.log` |
| `wp_list_backups` / `wp_restore_backup` | Undo |
| `wp_audit` | What the bridge has done |

## Turning it down or off

Both are `wp-config.php` constants, deliberately — a stolen application password
cannot switch them off through the API it just got into.

```php
define( 'AGENT_BRIDGE_READONLY', true );  // reads work, writes refuse
define( 'AGENT_BRIDGE_DISABLE', true );   // no routes registered at all
```

Revoking the secret under Tools → Agent Bridge also closes the API immediately,
as does deleting the application password.

## Recommended posture

Run it wide open on staging and local, read-only on production. Set
`WP_DEBUG_LOG` on the site you develop against — `wp_tail_log` is most of the
value of having a bridge at all, and it returns nothing without it.

## Tests

```bash
php tests/run-tests.php     # path confinement and the syntax checker
cd mcp-server && npm run typecheck
```

`tests/run-tests.php` stubs just enough WordPress to exercise `Paths::resolve`
against the attacks it exists to stop — traversal, backslash traversal, symlink
escape, unmanaged plugins, denied extensions and names — plus the linter.
