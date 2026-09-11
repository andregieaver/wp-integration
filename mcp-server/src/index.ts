#!/usr/bin/env node
/**
 * MCP server over the Agent Bridge REST API.
 *
 * Deliberately a separate process rather than MCP implemented inside WordPress:
 * this runs next to the agent, on the developer's machine, so the transport is
 * stdio and nothing about the MCP layer is reachable from the internet. The
 * plugin stays a plain REST plugin with one way in.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

import { BridgeClient, BridgeError, configFromEnv } from "./client.js";

const client = new BridgeClient(configFromEnv());

const server = new McpServer({
  name: "wp-agent-bridge",
  version: "0.1.0",
});

type ToolResult = {
  content: Array<{ type: "text"; text: string }>;
  isError?: boolean;
};

function ok(value: unknown): ToolResult {
  return {
    content: [{ type: "text", text: typeof value === "string" ? value : JSON.stringify(value, null, 2) }],
  };
}

/**
 * Bridge errors are the normal way the API says "no" — a stale hash, a parse
 * error, a path outside the allowlist. They are returned as tool errors with the
 * server's own wording, because that wording is what tells the agent how to
 * proceed (re-read and retry, fix the syntax, nominate the plugin).
 */
async function run(fn: () => Promise<unknown>): Promise<ToolResult> {
  try {
    return ok(await fn());
  } catch (error) {
    if (error instanceof BridgeError) {
      const hint =
        error.code === "agent_bridge_stale"
          ? "\n\nRe-read the file with wp_read_file and retry the write with the fresh sha256."
          : error.code === "agent_bridge_not_managed"
            ? "\n\nTick this plugin under Tools → Agent Bridge on the site."
            : error.code === "agent_bridge_no_secret"
              ? "\n\nGenerate a bridge secret under Tools → Agent Bridge and set WP_BRIDGE_SECRET."
              : "";

      return {
        content: [{ type: "text", text: `${error.message}${hint}` }],
        isError: true,
      };
    }

    return {
      content: [{ type: "text", text: error instanceof Error ? error.message : String(error) }],
      isError: true,
    };
  }
}

server.registerTool(
  "wp_status",
  {
    title: "WordPress site status",
    description:
      "Site, PHP and WordPress versions, which plugins the bridge may touch, whether writing is enabled, and whether a debug log is available. Call this first.",
    inputSchema: {},
  },
  async () => run(() => client.get("/status")),
);

server.registerTool(
  "wp_list_plugins",
  {
    title: "List installed plugins",
    description: "Every installed plugin with its slug, version, active state and whether the bridge may read and write it.",
    inputSchema: {},
  },
  async () => run(() => client.get("/plugins")),
);

server.registerTool(
  "wp_tree",
  {
    title: "List a plugin's files",
    description:
      "Recursive file listing for one managed plugin, with sizes and SHA-256 hashes. node_modules, vendor and .git are pruned unless include_vendor is set.",
    inputSchema: {
      plugin: z.string().describe("Plugin folder slug, e.g. 'my-plugin'"),
      include_vendor: z.boolean().optional().describe("Include node_modules, vendor and .git"),
      hashes: z.boolean().optional().describe("Include SHA-256 per file (default true)"),
    },
  },
  async (args) => run(() => client.get("/tree", args)),
);

server.registerTool(
  "wp_read_file",
  {
    title: "Read a plugin file",
    description:
      "Read one file's contents. Returns a sha256 — keep it, because writing the file back requires it.",
    inputSchema: {
      path: z.string().describe("Path relative to the plugins directory, e.g. 'my-plugin/includes/thing.php'"),
    },
  },
  async (args) => run(() => client.get("/file", args)),
);

server.registerTool(
  "wp_write_file",
  {
    title: "Write a plugin file",
    description:
      "Create or overwrite a file. PHP and JSON are parsed first and refused if invalid. The previous contents are backed up and the backup id is returned. For an existing file you must pass expected_sha from your last read; if it no longer matches, the write is refused rather than clobbering someone's edit.",
    inputSchema: {
      path: z.string().describe("Path relative to the plugins directory"),
      contents: z.string().describe("Full new contents of the file"),
      expected_sha: z
        .string()
        .optional()
        .describe("sha256 from your last read of this file. Required when the file already exists."),
      force: z
        .boolean()
        .optional()
        .describe("Skip the expected_sha check. Only when you intend to discard concurrent edits."),
    },
  },
  async (args) => run(() => client.post("/file", args)),
);

server.registerTool(
  "wp_delete_file",
  {
    title: "Delete a plugin file",
    description: "Delete one file. Backed up first, and requires expected_sha unless force is set.",
    inputSchema: {
      path: z.string().describe("Path relative to the plugins directory"),
      expected_sha: z.string().optional().describe("sha256 from your last read"),
      force: z.boolean().optional(),
    },
  },
  async (args) => run(() => client.delete("/file", args)),
);

server.registerTool(
  "wp_scaffold_plugin",
  {
    title: "Create a new plugin",
    description:
      "Create a new plugin folder with a working entry file, and nominate it so the bridge may edit it. Refuses if the folder already exists.",
    inputSchema: {
      slug: z.string().describe("Lowercase folder slug, e.g. 'kia-leads'"),
      name: z.string().optional().describe("Human-readable plugin name"),
      description: z.string().optional(),
      author: z.string().optional(),
    },
  },
  async (args) => run(() => client.post("/plugins", args)),
);

server.registerTool(
  "wp_activate_plugin",
  {
    title: "Activate a plugin",
    description:
      "Activate a managed plugin. WordPress loads it in a sandbox first, so a fatal is reported back instead of taking the site down.",
    inputSchema: { slug: z.string() },
  },
  async ({ slug }) => run(() => client.post(`/plugins/${encodeURIComponent(slug)}/activate`)),
);

server.registerTool(
  "wp_deactivate_plugin",
  {
    title: "Deactivate a plugin",
    description: "Deactivate a managed plugin.",
    inputSchema: { slug: z.string() },
  },
  async ({ slug }) => run(() => client.post(`/plugins/${encodeURIComponent(slug)}/deactivate`)),
);

server.registerTool(
  "wp_tail_log",
  {
    title: "Tail the debug log",
    description:
      "Last N lines of WordPress's debug.log. Requires WP_DEBUG and WP_DEBUG_LOG in wp-config.php; says so plainly when they are not set.",
    inputSchema: {
      lines: z.number().int().min(1).max(2000).optional().describe("Default 200"),
    },
  },
  async (args) => run(() => client.get("/logs", args)),
);

server.registerTool(
  "wp_list_backups",
  {
    title: "List backups",
    description: "Copies the bridge kept before each overwrite or delete, newest first.",
    inputSchema: {
      plugin: z.string().optional().describe("Limit to one plugin slug"),
      limit: z.number().int().min(1).max(500).optional(),
    },
  },
  async (args) => run(() => client.get("/backups", args)),
);

server.registerTool(
  "wp_restore_backup",
  {
    title: "Restore a backup",
    description:
      "Write a backup's contents back over its original path. The replaced version is itself backed up, so a restore can be undone.",
    inputSchema: { backup_id: z.string().describe("id from wp_list_backups") },
  },
  async (args) => run(() => client.post("/restore", args)),
);

server.registerTool(
  "wp_audit",
  {
    title: "Bridge audit trail",
    description: "What the bridge has done: who, when, from where, which path, and the hashes either side.",
    inputSchema: {
      limit: z.number().int().min(1).max(500).optional(),
      action_filter: z.string().optional().describe("e.g. 'file.write', 'plugin.activate', 'auth.reject'"),
    },
  },
  async (args) => run(() => client.get("/audit", args)),
);

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((error: unknown) => {
  // stderr, never stdout: stdout is the MCP transport and anything written
  // there that is not a framed message corrupts the session.
  process.stderr.write(`wp-agent-bridge-mcp failed to start: ${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
});
