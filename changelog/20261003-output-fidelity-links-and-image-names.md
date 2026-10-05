Significance: patch
Type: fixed

Synced posts now link straight to the real target instead of Google redirect URLs, and imported images get descriptive file names with alt text. Existing sources with a layout fingerprint re-convert once on their next sync, which replaces WordPress-side edits to synced content as any layout change does. Sources synced before 1.1.0 and legacy Elementor sources pick up the fix when their Doc next changes or on a manual sync.
