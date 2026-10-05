---
phase: 2
title: "Phase 2: Output fidelity — links and image names"
status: todo
priority: P1
effort: "1.5d"
dependencies: [1]
---

# Phase 2: Output fidelity — links and image names

## Overview

Synced posts contain the real link targets and images with meaningful filenames and alt text. Existing sources pick up the link fix on their next sync.

## Requirements

- Functional:
  - `https://www.google.com/url?q=<target>&sa=…&usg=…` hrefs become `<target>`.
  - New image uploads named `{post-slug}-{alt-or-index}.{ext}` instead of `image1.png`.
  - Attachment alt meta set from Doc alt text when present.
- Non-functional: no change to already-imported attachments or their URLs; cleaner is pure and fixture-tested.

## Architecture

```
HtmlZipImporter::import()
  extract → HtmlDocumentImageRewriter::rewrite() → HtmlGoogleRedirectLinkCleaner::clean() → html
```

- `HtmlGoogleRedirectLinkCleaner` (new, pure): `preg_replace_callback` over `href="…"` values. Unwrap only when host is `google.com`/`www.google.com`, path is `/url`, and `q` is present. Accept decoded target only for `http`, `https`, `mailto`, `tel`; otherwise leave href unchanged. No DOM re-serialization.
- Image naming: `HtmlDocumentImageRewriter` already holds the `<img>` element → pass alt text as `$name_hint` to `MediaAssetImporter::importImage()`. Importer builds `sanitize_title( post_name ?: post_title ) . '-' . ( sanitize_title( hint, max 60 chars ) ?: index )`. Same hint parameter for `DocsApiImageImporter`.
- Dedupe unchanged: attachments matched by asset hash are reused, never renamed.
- `ConversionPipelineVersion::CURRENT` (new constant class): added to `LayoutBlueprint::getFingerprintSeed()` consumers and Elementor preset fingerprint so unchanged Docs re-convert once. Legacy Elementor sources (empty fingerprint) keep current behaviour.

## Related code files

- Create: `src/Sync/HtmlGoogleRedirectLinkCleaner.php`
- Create: `src/Sync/ConversionPipelineVersion.php`
- Create: `scripts/verify-output-fidelity-fixtures.php`
- Create: `tests/fixtures/output-fidelity/{google-redirect-links,non-google-links,encoded-targets,unsafe-scheme}/`
- Modify: `src/Sync/HtmlZipImporter.php`, `src/Plugin.php` (wiring)
- Modify: `src/Sync/HtmlDocumentImageRewriter.php`, `src/Sync/MediaAssetImporter.php`, `src/Sync/DocsApiImageImporter.php`
- Modify: `src/Sync/Layout/LayoutConversionService.php`, `src/Sync/Elementor/Preset/*` fingerprint
- Modify: `composer.json` (`test:output-fidelity-fixtures`), `.github/workflows/pr-lint.yml`, `.github/workflows/release-validate.yml`
- Modify: `readme.txt`, `changelog/`, `docs/system-architecture.md`

## Implementation steps

1. Write fixtures first: wrapped link, `&amp;`-encoded wrapper, percent-encoded target with query string, non-Google link, `google.com/url` without `q`, `javascript:` target (must stay wrapped).
2. Implement cleaner; write verify script using the existing shim pattern from `scripts/verify-layout-fixtures.php`.
3. Wire cleaner into `HtmlZipImporter` constructor and `import()`.
4. Add `$name_hint` to importer signatures (default `''`); build filename; set `_wp_attachment_image_alt` on new uploads when hint non-empty.
5. Add pipeline version constant; include in both fingerprints; regenerate affected golden fixture fingerprints only if fixtures assert them.
6. Devcontainer check: re-sync Phase 1 Doc; confirm links, filenames, alt meta; confirm an unchanged Doc re-converts exactly once.
7. Update changelog and architecture doc.

## Todo

- [x] Fixtures + verify script
- [x] Link cleaner wired
- [x] Image naming + alt meta
- [x] Pipeline version in fingerprints
- [x] CI steps added
- [x] Devcontainer verification
- [x] Docs + changelog

## Success criteria

- [x] `composer test:output-fidelity-fixtures` passes; existing layout, Elementor, large-doc fixture suites still pass.
- [x] Real Doc sync: zero `google.com/url` hrefs in `post_content`.
- [x] New image upload filename contains post slug; reused attachments keep old names.

## Risk assessment

- One-time re-conversion overwrites WordPress-side edits on every Gutenberg source → same exposure as any preset change today; call out in changelog. Owner confirmed re-converting all sources (2026-10-03).
- Regex misses an href quoting variant → fixtures cover single/double quotes; cleaner leaves anything unrecognized untouched.

## Security considerations

- Scheme allowlist prevents turning a wrapped `javascript:`/`data:` target into a live href. Output still passes existing sanitizer.

## Next steps

Phases 5, 6, 7 build on the cleaned HTML stage.
