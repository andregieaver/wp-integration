/**
 * Thin HTTP client for the Agent Bridge REST API.
 *
 * Credentials come from the environment and are never logged: an application
 * password in a stack trace is the same as an application password in a paste.
 */

export interface BridgeConfig {
  baseUrl: string;
  user: string;
  appPassword: string;
  secret: string;
  timeoutMs: number;
}

export class BridgeError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
    readonly data?: unknown,
  ) {
    super(message);
    this.name = "BridgeError";
  }
}

export function configFromEnv(env: NodeJS.ProcessEnv = process.env): BridgeConfig {
  const missing: string[] = [];
  const read = (key: string): string => {
    const value = (env[key] ?? "").trim();
    if (!value) missing.push(key);
    return value;
  };

  const rawUrl = read("WP_BRIDGE_URL");
  const user = read("WP_BRIDGE_USER");
  const appPassword = read("WP_BRIDGE_APP_PASSWORD");
  const secret = read("WP_BRIDGE_SECRET");

  if (missing.length > 0) {
    throw new Error(
      `Missing required environment variable(s): ${missing.join(", ")}.\n` +
        `Set them where you register the MCP server, e.g.\n` +
        `  WP_BRIDGE_URL=https://example.com\n` +
        `  WP_BRIDGE_USER=admin\n` +
        `  WP_BRIDGE_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx"\n` +
        `  WP_BRIDGE_SECRET=<from Tools -> Agent Bridge>`,
    );
  }

  const baseUrl = rawUrl.replace(/\/+$/, "");

  if (!/^https?:\/\//.test(baseUrl)) {
    throw new Error(`WP_BRIDGE_URL must start with http:// or https:// (got "${rawUrl}")`);
  }

  if (baseUrl.startsWith("http://") && !/^http:\/\/(localhost|127\.0\.0\.1|\[::1\])/.test(baseUrl)) {
    throw new Error(
      `WP_BRIDGE_URL is plain HTTP against a non-local host. The bridge secret and ` +
        `application password would cross the network in clear text. Use HTTPS.`,
    );
  }

  const timeoutRaw = Number.parseInt(env.WP_BRIDGE_TIMEOUT_MS ?? "", 10);

  return {
    baseUrl,
    user,
    appPassword,
    secret,
    timeoutMs: Number.isFinite(timeoutRaw) && timeoutRaw > 0 ? timeoutRaw : 30_000,
  };
}

export class BridgeClient {
  constructor(private readonly config: BridgeConfig) {}

  private get authHeader(): string {
    const token = Buffer.from(`${this.config.user}:${this.config.appPassword}`, "utf8").toString("base64");
    return `Basic ${token}`;
  }

  async request<T = unknown>(
    method: "GET" | "POST" | "DELETE",
    path: string,
    options: { query?: Record<string, string | number | boolean | undefined>; body?: unknown } = {},
  ): Promise<T> {
    const url = new URL(`${this.config.baseUrl}/wp-json/agent-bridge/v1${path}`);

    for (const [key, value] of Object.entries(options.query ?? {})) {
      if (value !== undefined) url.searchParams.set(key, String(value));
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.config.timeoutMs);

    let response: Response;
    try {
      response = await fetch(url, {
        method,
        headers: {
          Authorization: this.authHeader,
          "X-Bridge-Secret": this.config.secret,
          Accept: "application/json",
          ...(options.body !== undefined ? { "Content-Type": "application/json" } : {}),
        },
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
        signal: controller.signal,
      });
    } catch (cause) {
      if (cause instanceof Error && cause.name === "AbortError") {
        throw new BridgeError(`Request timed out after ${this.config.timeoutMs}ms`, 0, "timeout");
      }
      // The URL is safe to show; the headers are not, which is why the cause is
      // summarised rather than rethrown whole.
      throw new BridgeError(
        `Could not reach ${url.origin} (${cause instanceof Error ? cause.message : String(cause)})`,
        0,
        "network",
      );
    } finally {
      clearTimeout(timer);
    }

    const text = await response.text();
    let payload: unknown;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      throw new BridgeError(
        `Expected JSON from ${url.pathname} but got ${response.status} ${response.statusText}. ` +
          `Is the Agent Bridge plugin active and are permalinks enabled?`,
        response.status,
        "not_json",
      );
    }

    if (!response.ok) {
      const err = payload as { code?: string; message?: string; data?: unknown } | null;
      throw new BridgeError(
        err?.message ?? `${response.status} ${response.statusText}`,
        response.status,
        err?.code,
        err?.data,
      );
    }

    return payload as T;
  }

  get<T = unknown>(path: string, query?: Record<string, string | number | boolean | undefined>) {
    return this.request<T>("GET", path, { query });
  }

  post<T = unknown>(path: string, body?: unknown) {
    return this.request<T>("POST", path, { body });
  }

  delete<T = unknown>(path: string, body?: unknown) {
    return this.request<T>("DELETE", path, { body });
  }
}
