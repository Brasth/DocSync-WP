# UploadValidator wp_tempnam REST 500 fix

## Status
DONE

## Problem
REST PDF render POST returned 500: `Call to undefined function DocSyncWP\Import\wp_tempnam` at `reencodePng` (~line 418). CLI harness loaded admin includes, masking the missing `wp-admin/includes/file.php` load on front/REST.

## Change
File: `src/Import/UploadValidator.php` only.

Before `wp_tempnam( 'docsync-wp-render' )`, load admin file helpers when unavailable:

```php
if ( ! function_exists( 'wp_tempnam' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
```

Matches existing pattern used for `wp_get_image_editor` / `image.php` in the same method. GD reencode path and dimension/resource limits unchanged. No `tempnam` fallback.

## Verification
- Docker PHPCS: `./vendor/bin/phpcs src/Import/UploadValidator.php` → exit 0
- Docker syntax: `php -l .../UploadValidator.php` → No syntax errors detected

## Unresolved
None.
