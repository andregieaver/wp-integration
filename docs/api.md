# Agent Bridge REST API

Base: `https://<site>/wp-json/agent-bridge/v1`

Every request needs both:

```
Authorization: Basic base64(<admin-login>:<application-password>)
X-Bridge-Secret: <secret from Tools → Agent Bridge>
```

Paths are relative to `wp-content/plugins/` and must begin with the folder slug
of a nominated plugin: `my-plugin/includes/thing.php`.

## GET /status

Site and bridge state. Call it first; it reports `bridge.write_enabled`,
`bridge.managed` and whether a debug log is readable.

## GET /plugins

Every installed plugin: `file`, `slug`, `name`, `version`, `active`, `managed`.

## POST /plugins

`{ "slug": "my-plugin", "name": "My Plugin", "description": "", "author": "" }`

Creates the folder and a working entry file, and nominates it. Refuses if the
folder exists — the one write that does not require the target to be managed
already, since a plugin that does not exist cannot have been nominated.

## POST /plugins/{slug}/activate · /deactivate

Activation goes through `activate_plugin()`, which loads the file in a sandbox
and returns the fatal instead of dying on it. The bridge refuses to deactivate
itself.

## GET /tree?plugin={slug}

`include_vendor=1` to walk `node_modules`, `vendor` and `.git`; `hashes=0` to
skip per-file SHA-256.

## GET /file?path={path}

Returns `contents`, `sha256`, `bytes`, `mtime`. Binary files and anything over
the size limit are refused.

## POST /file

```json
{ "path": "my-plugin/includes/thing.php",
  "contents": "<?php ...",
  "expected_sha": "9f86d0…" }
```

- `expected_sha` is **required when the file exists**. Send the hash from your
  last read. A mismatch is `409` with `data.current_sha` — re-read and retry.
- PHP and JSON are parsed first; invalid source is `422` and nothing is written.
- Existing contents are backed up; the response carries `backup_id`.
- `force: true` skips the hash check and discards concurrent edits.

`201` when created, `200` when updated.

## DELETE /file

`{ "path": "...", "expected_sha": "..." }`. Backed up first.

## GET /backups?plugin={slug}&limit=100

Newest first.

## POST /restore

`{ "backup_id": "20260911-143000-a1b2c3d4" }`. The version it replaces is itself
backed up, so a restore can be undone.

## GET /logs?lines=200

Tail of `debug.log`, read from the end. Returns `available: false` with a reason
rather than an error when `WP_DEBUG_LOG` is unset.

## GET /audit?limit=50&action_filter=file.write

Actions: `file.write`, `file.delete`, `file.restore`, `plugin.scaffold`,
`plugin.activate`, `plugin.deactivate`, `settings.save`, `backups.purge`,
`auth.reject`, `auth.rotate`, `auth.revoke`.

## Error codes

| HTTP | `code` | Meaning |
| --- | --- | --- |
| 401 | `agent_bridge_unauthorized` | No authenticated user |
| 403 | `agent_bridge_bad_secret` | Header missing or wrong |
| 403 | `agent_bridge_not_managed` | Plugin not nominated |
| 403 | `agent_bridge_readonly` | `AGENT_BRIDGE_READONLY` is set |
| 403 | `agent_bridge_insecure` | Plain HTTP on a non-local site |
| 409 | `agent_bridge_stale` | `expected_sha` mismatch |
| 413 | `agent_bridge_too_large` | Over the size limit |
| 422 | `agent_bridge_parse_error` | Source does not parse; nothing written |
| 428 | `agent_bridge_sha_required` | File exists and no `expected_sha` sent |
| 503 | `agent_bridge_no_secret` | No secret generated yet |
