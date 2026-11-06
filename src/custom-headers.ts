/**
 * Extra HTTP headers for sites behind a login gateway such as Cloudflare Access,
 * which needs a service token on every request before WordPress sees it.
 *
 * The values are usually secrets, so nothing here repeats one in an error message.
 */

/** Holds a JSON object of header names and values. */
export const CUSTOM_HEADERS_ENV = 'WORDPRESS_CUSTOM_HEADERS';

export type CustomHeaders = Record<string, string>;

export type CustomHeadersResult =
  | { ok: true; headers: CustomHeaders }
  | { ok: false; message: string };

/** Headers Block MCP sets itself. Replacing one would break auth or the request body. */
const RESERVED_HEADERS = new Set([
  'accept',
  'authorization',
  'connection',
  'content-length',
  'content-type',
  'host',
  'transfer-encoding',
  'x-http-method-override',
]);

/** RFC 9110 token: the characters allowed in a header name. */
const VALID_HEADER_NAME = /^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/;

/** No control characters, so a value can't smuggle in a second header. */
const VALID_HEADER_VALUE = /^[\t\x20-\x7e\x80-\xff]+$/;

/**
 * Parse the custom headers setting. A missing or blank setting means no headers.
 */
export function parseCustomHeaders(raw: string | undefined): CustomHeadersResult {
  const isUnset = raw === undefined || raw.trim() === '';
  if (isUnset) {
    return { ok: true, headers: {} };
  }

  const fail = (reason: string): CustomHeadersResult => ({
    ok: false,
    message: `${CUSTOM_HEADERS_ENV} ${reason} Expected a JSON object, such as {"CF-Access-Client-Id":"…","CF-Access-Client-Secret":"…"}.`,
  });

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    // JSON.parse quotes the input in its own message, which could expose a secret.
    return fail('is not valid JSON.');
  }

  const isPlainObject = typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed);
  if (!isPlainObject) {
    return fail('is not a JSON object.');
  }

  const headers: CustomHeaders = {};
  for (const [name, value] of Object.entries(parsed as Record<string, unknown>)) {
    if (!VALID_HEADER_NAME.test(name)) {
      return fail('has a header name with characters a header name cannot contain.');
    }
    if (RESERVED_HEADERS.has(name.toLowerCase())) {
      return fail(`cannot set "${name}", because Block MCP sets that header itself.`);
    }
    const isUsableValue = typeof value === 'string' && VALID_HEADER_VALUE.test(value);
    if (!isUsableValue) {
      return fail(`has an empty or invalid value for "${name}".`);
    }
    headers[name] = value as string;
  }

  return { ok: true, headers };
}

/**
 * Whether a 401 or 403 came from a gateway in front of WordPress. WordPress REST
 * errors always have a string `code`, including ones that pass through a gateway,
 * so a refusal without one never reached WordPress. Cloudflare Access answers this
 * way when a request asks for JSON.
 */
export function isGatewayRefusal(status: number, body: unknown): boolean {
  const isRefusal = status === 401 || status === 403;
  const hasWordPressCode =
    typeof body === 'object' && body !== null && typeof (body as { code?: unknown }).code === 'string';
  return isRefusal && !hasWordPressCode;
}

/**
 * Warn when a secret header would cross the network in clear text. Stays quiet for
 * the local machine, which never leaves it, and returns whether it warned.
 */
export function warnIfHeadersUnencrypted(siteUrl: string, customHeaders: CustomHeaders): boolean {
  const hasCustomHeaders = Object.keys(customHeaders).length > 0;
  let isUnencrypted = false;
  try {
    const { protocol, hostname } = new URL(siteUrl);
    const isLocal = ['localhost', '127.0.0.1', '[::1]'].includes(hostname) || hostname.endsWith('.local');
    isUnencrypted = protocol === 'http:' && !isLocal;
  } catch {
    return false;
  }
  const shouldWarn = hasCustomHeaders && isUnencrypted;
  if (shouldWarn) {
    console.error(`[block-mcp] ${CUSTOM_HEADERS_ENV} is set, but ${siteUrl} uses http://, so these headers are sent unencrypted. Use https://.`);
  }
  return shouldWarn;
}

/**
 * Whether a redirect stays on the site: the same origin, or the same host moving
 * from http to https on the standard port. A site that forces HTTPS is a security
 * upgrade, not an off-site leak. Any other change, including an https to http
 * downgrade or a different port, counts as leaving the site.
 */
export function isSameSiteRedirect(current: URL, next: URL): boolean {
  const usesStandardHttpsPort = next.port === '' || next.port === '443';
  const isHttpsUpgrade =
    current.protocol === 'http:' &&
    next.protocol === 'https:' &&
    next.hostname === current.hostname &&
    usesStandardHttpsPort;
  return next.origin === current.origin || isHttpsUpgrade;
}

/** The parts of a redirect the HTTP client passes to its redirect hook. */
export interface RedirectOptions {
  /** The real destination. With an HTTP proxy, `hostname` and `port` hold the proxy's address instead. */
  href?: string;
  headers?: Record<string, unknown>;
}

/** Whether a redirect target stays on the site. A target that can't be read counts as leaving it. */
function redirectStaysOnSite(options: RedirectOptions, siteUrl: string): boolean {
  try {
    return isSameSiteRedirect(new URL(siteUrl), new URL(options.href ?? ''));
  } catch {
    return false;
  }
}

/**
 * Remove custom headers from a redirected request that is leaving the site.
 *
 * The HTTP client drops Authorization and Cookie on its own when a redirect
 * changes domain, but it keeps every other header. Without this, a site or
 * gateway that redirects elsewhere would receive the service token.
 */
export function stripHeadersOffSite(
  options: RedirectOptions,
  siteUrl: string,
  customHeaders: CustomHeaders
): void {
  const hasCustomHeaders = Object.keys(customHeaders).length > 0;
  if (!hasCustomHeaders || !options.headers) {
    return;
  }
  if (redirectStaysOnSite(options, siteUrl)) {
    return;
  }

  const names = new Set(Object.keys(customHeaders).map((name) => name.toLowerCase()));
  for (const name of Object.keys(options.headers)) {
    if (names.has(name.toLowerCase())) {
      delete options.headers[name];
    }
  }
}
