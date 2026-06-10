# Vendored WordPress HTML API

These files are copied from WordPress 6.4 (`src/wp-includes/html-api/`) so the stub-based PHPUnit suite can exercise `WP_HTML_Tag_Processor`. They are required by `tests/bootstrap.php` only when the class is not already available (i.e., outside a real WordPress test environment).

## Files

- `class-wp-html-tag-processor.php`
- `class-wp-html-attribute-token.php`
- `class-wp-html-span.php`
- `class-wp-html-text-replacement.php`

## Source

```
https://github.com/WordPress/wordpress-develop/tree/6.4/src/wp-includes/html-api
```

## Updating

When upgrading the plugin's tested WordPress version, refetch these from the same path on the matching tag. Do not modify them locally — replace wholesale.

## Why vendored

The current PHP test suite uses a hand-rolled stub bootstrap (no Composer, no full WP test framework). Vendoring just these four files unblocks the seven `HtmlTransformerTest` cases that were previously skipped. Issue [#2](https://github.com/GravityKit/block-mcp/issues/2) tracks moving to wp-env-based PHPUnit, which would replace this approach with a real WordPress test environment.
