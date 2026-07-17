/**
 * Build a REST URL on the permalink-independent `?rest_route=` form.
 *
 * Hardcoding `/wp-json/` breaks on plain-permalink sites (and any custom
 * `rest_url_prefix`): the connector can complete the handshake, then every tool
 * call 404s because the pretty REST route does not exist. The `?rest_route=`
 * form works on every permalink configuration, matching the connector's own
 * exchange URL (see src/connect.ts).
 *
 * @param siteUrl - WordPress site URL; trailing slashes are trimmed.
 * @param routeSuffix - Route path after the namespace, e.g. `/instructions`.
 *                      Empty (default) yields the axios base URL.
 * @returns e.g. `https://site.test/?rest_route=/gk-block-api/v1/instructions`
 */
export function restRouteUrl(siteUrl: string, routeSuffix = ''): string {
  const trimmed = siteUrl.replace(/\/+$/, '');
  return `${trimmed}/?rest_route=/gk-block-api/v1${routeSuffix}`;
}
