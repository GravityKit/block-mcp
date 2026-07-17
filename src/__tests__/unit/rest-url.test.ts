import { describe, it, expect } from 'vitest';
import { restRouteUrl } from '../../rest-url.js';

// The client and the instructions fetch must reach WordPress on the
// permalink-independent ?rest_route= form. Hardcoding /wp-json/ let the
// connector succeed and then every tool call 404 on plain-permalink sites.
describe('restRouteUrl', () => {
  it('uses the ?rest_route= form, never a hardcoded /wp-json/ path', () => {
    expect(restRouteUrl('https://site.test')).toBe('https://site.test/?rest_route=/gk-block-api/v1');
    expect(restRouteUrl('https://site.test')).not.toContain('/wp-json/');
  });

  it('trims trailing slashes on the site URL', () => {
    expect(restRouteUrl('https://site.test/')).toBe('https://site.test/?rest_route=/gk-block-api/v1');
    expect(restRouteUrl('https://site.test///')).toBe('https://site.test/?rest_route=/gk-block-api/v1');
  });

  it('appends a route suffix after the namespace', () => {
    expect(restRouteUrl('https://site.test', '/instructions')).toBe(
      'https://site.test/?rest_route=/gk-block-api/v1/instructions'
    );
  });
});
