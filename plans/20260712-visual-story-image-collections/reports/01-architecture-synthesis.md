# Visual Story Architecture Synthesis

Date: 2026-07-12
Status: Proposed

## Decision

Treat image grouping as an explicit layout-conversion behavior, not a UI gallery feature or automatic heuristic. Keep 1.2.0 frontend-only. Plan Visual Story as a subsequent minor release with a separate acceptance gate.

## Current Boundaries

- `HtmlDocumentImageRewriter` preserves imported image DOM order and rewrites media URLs.
- `HtmlStandaloneImageDetector` accepts one image-only `img`, `a`, `figure`, `p`, or `span`, optionally preserving a figure caption.
- `LayoutConversionService` owns Gutenberg preset behavior.
- `ElementorPresetConversionService` owns explicit Elementor preset behavior; no-preset Elementor sources retain the legacy converter.
- `HtmlBlockFactory` currently emits individual native image blocks.
- `LayoutBuilder` and `WidgetFactory` currently build Elementor Free-compatible containers and image widgets.

## Recommended Contract

An image collection is a maximal run of at least three consecutive significant nodes that each pass the existing standalone-image detector after the relevant converter has flattened its normal structural wrappers. Whitespace does not break a run. Text, headings, tables, lists, dividers, mixed image/text nodes, multi-image wrappers, and unsupported structures stop the run. Ineligible nodes preserve current output and order.

The contract is applied only when the selected preset is `visual_story` or `elementor_visual_story`. It never applies to Clean Article, Documentation, Plain Blocks, Hero Page, Feature Block, or legacy Elementor.

## Renderer Mapping

- Gutenberg: one native `core/gallery` containing nested native `core/image` blocks. Do not add a custom block or plugin stylesheet.
- Elementor: one responsive parent grid/row built from core Elementor containers or legacy sections/columns, containing ordinary Image widgets. Do not use Pro gallery, carousel, loop, or CSS features.

## Safety Rules

- Preserve image URL/media mapping, order, alt text, supported captions, and custom links.
- Include Visual Story policy/version data in the affected layout fingerprint. Selecting or leaving Visual Story forces a safe re-conversion even if Google metadata is unchanged.
- Require ZIP and Docs API fallback fixture parity. Progressive fallback is acceptable only where current empty-draft partial-write behavior already applies; final output must converge.
- Existing sources are never migrated. Defaults remain unchanged.

## Evidence Needed

Use representative editorial, technical, agency, portfolio, image-heavy, captioned, linked, table-contained, and large-document fallback fixtures. Reject the feature if either renderer loses image fidelity, if existing golden fixtures change, or if Elementor needs Pro/unstable settings.

## Unresolved Questions

- Is three images sufficiently conservative for the expected portfolio and product use cases?
- Is legacy Elementor grid output acceptable, or should Visual Story require supported Elementor containers?
