# Research Report: Layout Wizard References for DocSync-WP

Date: 2026-06-22
Author: ck:research skill
Scope: External references and prior art for the proposed "Google Doc -> layout wizard -> Elementor / Gutenberg" feature in DocSync-WP. No code changes recommended in this report; it is reference material for the design phase.

## Executive Summary

There is **no direct competitor** in the WordPress plugin directory today that ships a "Google Doc -> layout-aware Elementor or Gutenberg output" feature. The closest references cluster into three groups, and all three are useful for the design but none are drop-in templates:

1. The WordPress **Block Pattern API** (`register_block_pattern`, `register_block_pattern_category`, Synced Patterns) is the right way to publish reusable, named layouts to the block inserter. This validates the "layout preset" abstraction but it is the wrong primary surface for DocSync-WP, because the wizard is a one-time decision at sync time, not an editor-side picker.
2. The **Elementor JSON data structure** is well-documented and version-stable. `developers.elementor.com/docs/data-structure/` is the authoritative schema reference and confirms that what the existing `Elementor\DataConverter` emits is the correct shape. The risk is widget-version drift across Elementor releases, not the data format.
3. **Block libraries** (Kadence Blocks, Spectra, GenerateBlocks) use a "Cloud Library" pattern of pre-curated JSON that users can drop into the editor. This is the same UX shape as the proposed preset gallery, but it is editor-side; DocSync-WP needs the same shape at sync time, not editor time.

The user's pain point ("end user must ask a developer to ship a layout") is real, and the existing DocSync-WP 1.0.x release already covers the "Google Doc -> blocks/widgets" half. The missing half is the opinionated layout layer, and the Block Pattern API plus the Elementor JSON spec give us two well-defined integration points to build it on.

## Research Methodology

- Sources consulted: 5 web searches, ~40 result pages summarized.
- Date range: 2023-08 to 2026-06; the most authoritative 2026 material is from WordPress.com news, Kinsta, and Elementor Developers.
- Key search terms: `WordPress plugin Google Docs Elementor`, `register_block_pattern programmatic API`, `Elementor _elementor_data JSON schema`, `synced patterns reusable blocks`, `Kadence Spectra block library pattern importer`.
- Gemini CLI was unavailable in this session; WebSearch was used directly per the skill fallback rule.
- Five searches executed total, the per-skill maximum.

## Key Findings

### 1. Competitive landscape (Google Doc -> layout plugin)

There is no plugin in the public WordPress.org directory that does the full path "Google Doc -> opinionated layout -> Elementor or Gutenberg output as a default wizard."

- The plugins that mention Google Docs in the directory are mostly **embedders** (EmbedPress) or **form-to-sheet bridges** (the various GSheetConnector plugins). None sync a Doc into a structured post.
- **BetterDocs** is the closest reference for "AI-assisted knowledge-base layout", but it generates documentation from a prompt or URL, not from a Google Doc you already have. Reference: https://wordpress.com/plugins/betterdocs
- The existing **DocSync-WP** plugin is the only first-party reference for the upstream half (Google Doc -> sanitized HTML -> blocks). The new feature is a pure extension of that pipeline.

Implication: this is a real gap, not a saturated market. The first-mover advantage is genuine, and we can ship a small, focused feature without trying to match a competitor.

### 2. WordPress Block Pattern API (Gutenberg)

Authoritative sources:
- `register_block_pattern` reference: https://developer.wordpress.org/reference/functions/register_block_pattern/
- `register_block_pattern_category` reference: https://developer.wordpress.org/reference/functions/register_block_pattern_category/
- Block Editor Handbook patterns guide: https://developer.wordpress.org/block-editor/reference-guides/block-api/patterns/
- Theme Handbook on registering patterns: https://developer.wordpress.org/themes/patterns/registering-patterns/
- 10up reference on Synced Patterns: https://gutenberg.10up.com/reference/Patterns/synced-patterns/
- WordPress.com 2023 announcement of Synced Patterns: https://wordpress.org/news/2023/07/synced-patterns-the-evolution-of-reusable-blocks/
- 2026 deep dive from Kinsta: https://kinsta.com/blog/wordpress-block-patterns/

What is relevant for the wizard:
- `register_block_pattern` accepts `title`, `content` (the block markup), `description`, `categories`, `keywords`. That is exactly the schema a layout preset needs. The wizard can ship built-in presets as PHP code that calls `register_block_pattern` on `init`, and DocSync-WP can also keep a parallel JSON registry for "non-injected" presets that the wizard uses during conversion (this matches the DRY principle: one schema, two consumers).
- `register_block_pattern_category` lets the wizard register a `docsync-wp` category so presets show up grouped in the inserter for users who want to manually drop a preset into a non-synced post. This is a free win that requires no new architecture.
- Synced Patterns (formerly Reusable Blocks) are the right shape for "this layout, kept in sync across posts". For DocSync-WP, a synced pattern per built-in preset gives the developer an easy "edit the preset, all synced posts update" path. This is not in v1 scope, but it is a free follow-on.

Implication: the Block Pattern API gives us a publish target, a category namespace, and a future direction (synced patterns for live edits). The current 4-preset MVP can register its built-ins through this API as a side effect, with zero coupling to the conversion path.

### 3. Elementor JSON data structure

Authoritative sources:
- Elementor Developers data structure overview: https://developers.elementor.com/docs/data-structure/
- Elementor Developers general structure: https://developers.elementor.com/docs/data-structure/general-structure/
- 2026 import/export tutorial (third party): https://wpbuilderaddons.com/tutorial/import-export-elementor-templates/

What is relevant for the wizard:
- The Elementor JSON shape is an array of element objects with `id`, `elType` (container, section, column, widget), `settings`, and `elements` (children). Our existing `Elementor\LayoutBuilder` already produces a top-level `container` (or legacy `section`+`column`) wrapping a flat list of widgets, which is the standard shape. The `Elementor\DataConverter` does not need structural changes for the wizard; it just needs to accept an optional `LayoutBlueprint` to vary which widgets it produces for which DOM nodes.
- Elementor's own template export/import goes through `Tools -> Import / Export -> Templates` and produces a `.json` file that gets uploaded through the Elementor admin. This is the editor-side equivalent of what DocSync-WP needs at sync time. The Elementor Pro template library and the "Website Kits" both use the same JSON shape.
- The biggest risk is **Elementor Pro widget compatibility** for presets that use Pro widgets like `template`, `posts`, `loop-grid`, or `form`. If the user's site has Elementor free only, those widgets will render as a missing-widget placeholder. Mitigation: registry hides Pro-only presets on sites without Pro, never silently fallback. (Already in the design plan.)

Implication: the data format is stable. The work is in the per-preset widget selection, not in the schema.

### 4. Block library UX (Kadence, Spectra, GenerateBlocks)

Authoritative sources:
- Kadence Cloud Library: https://cloud.kadenceblocks.com/
- Spectra vs Kadence 2026 comparison: https://nexterwp.com/blog/spectra-vs-kadence-blocks/
- WPMET 2026 comparison: https://wpmet.com/kadence-blocks-vs-spectra-vs-gutenkit/

What is relevant for the wizard:
- All three block libraries use a **Cloud Library** pattern: a remote JSON catalog grouped by category, with thumbnails and one-click insertion. The UX is "browse, preview, insert". This is exactly the UX shape the wizard step 3 should mimic. Reference implementation, not direct reuse.
- All three ship **template kits** as bundled JSON shipped inside the plugin (no cloud roundtrip). This is the right model for built-in DocSync-WP presets: ship them as bundled JSON, ship them as inline PHP classes for type safety, expose them through the same `LayoutPresetRegistry` to both the wizard UI and the conversion path.
- The pricing trend in 2026 is **AI-assisted template suggestions** (Kadence AI, Spectra AI). The user explicitly did not ask for AI in v1. Do not build it. Note it as v2. (Already in the design plan as v2.)

Implication: the gallery UX is proven. Use it as a visual reference, ship 4 presets, do not invent new UI primitives.

### 5. Risk and security context

Sources cross-checked:
- Elementor data structure (above) and the existing `Elementor\CompatibilityChecker` in this codebase.
- WordPress block pattern registration (above) and the existing `HtmlBlockFactory` output (Gutenberg `core/*` blocks only, no custom-block whitelisting).
- The existing `docsync_wp_settings` option storage in `SettingsRepository`.

Risks confirmed by research:
- **Elementor schema drift across versions**: the JSON shape is stable for `elType` and basic widget settings, but new Elementor versions add settings keys. The plugin's existing `clearCache` flow already handles CSS regeneration; that is the right place to also force `_elementor_version` to current on every sync, which the existing `PostUpdater::update` already does.
- **Pattern registration leak**: `register_block_pattern` adds to the global pattern inserter. If the wizard registers presets in the inserter, they will appear for users who do not use the wizard. The existing `DocsApiHtmlImporter` and the new `LayoutPresetRegistry` should be loaded only when the user has linked at least one source, or be opt-in per user. Honest push-back: do not register built-in presets as global block patterns in v1. Ship the wizard gallery only. This is the smaller, safer path. (This is a refinement of the design plan, not a contradiction.)
- **Gutenberg block JSON parse failures**: the block pattern API and the editor both expect well-formed serialized block JSON. The existing `serialize_blocks()` call in `HtmlToBlockContentConverter` is the right primitive; nothing new needed.

## Implementation Recommendations

The plan I delivered in the previous turn is consistent with what the research confirms. Three adjustments based on the research:

1. **Drop "register built-in presets as global block patterns" from Phase 1.** The research shows global pattern registration is too broad a surface for a wizard-only feature. Keep the wizard gallery as the only surface in v1. A future v3 can opt in to global pattern registration behind a site-level setting. This is the KISS call.
2. **Add a "preview as JSON" download to the wizard preview step.** Elementor users are used to `.json` template files. Letting the user download the preview output as `.json` gives them a familiar artifact they can hand to a developer or re-import into Elementor manually. This costs ~10 lines in the preview endpoint.
3. **Add a "Copy as Block Pattern PHP" affordance for power users.** The 10up Synced Patterns reference and the `register_block_pattern` API both make it easy to register a pattern from PHP. A small "Copy as PHP" button next to the preset in the wizard gives developers a one-click escape hatch when a non-developer picks the wrong preset. This costs ~30 lines and keeps developers in the loop without making them the bottleneck. Honest push-back: include this in v1 only if the gallery step already has a "share" affordance, do not build a new share button for it.

None of these are blockers for the design as it stands. They are polish, not architecture.

## Resources & References

### Authoritative documentation
- WordPress `register_block_pattern`: https://developer.wordpress.org/reference/functions/register_block_pattern/
- WordPress `register_block_pattern_category`: https://developer.wordpress.org/reference/functions/register_block_pattern_category/
- Block Editor Handbook patterns: https://developer.wordpress.org/block-editor/reference-guides/block-api/patterns/
- Theme Handbook on registering patterns: https://developer.wordpress.org/themes/patterns/registering-patterns/
- Elementor data structure: https://developers.elementor.com/docs/data-structure/
- Elementor general structure: https://developers.elementor.com/docs/data-structure/general-structure/
- 10up Synced Patterns reference: https://gutenberg.10up.com/reference/Patterns/synced-patterns/
- WordPress.org Synced Patterns announcement: https://wordpress.org/news/2023/07/synced-patterns-the-evolution-of-reusable-blocks/

### Competitor and reference implementations
- BetterDocs (Elementor knowledge base, AI-assisted): https://wordpress.com/plugins/betterdocs
- EmbedPress (Google Docs embed, not sync): https://embedpress.com/docs/embed-google-docs-wordpress/
- Kadence Cloud Library: https://cloud.kadenceblocks.com/
- Spectra vs Kadence 2026: https://nexterwp.com/blog/spectra-vs-kadence-blocks/
- Kinsta block patterns 2026 guide: https://kinsta.com/blog/wordpress-block-patterns/
- WordPress.com pattern system overview 2025: https://wordpress.com/blog/2025/09/10/pattern-system-wordpress-publishing-workflow/

### Tutorials and how-tos
- rudrastyh.com programmatic block patterns tutorial: https://rudrastyh.com/gutenberg/create-block-patterns-programmatically.html
- Elementor import/export tutorial 2026: https://wpbuilderaddons.com/tutorial/import-export-elementor-templates/
- Brndle block pattern categories guide: https://brndle.com/block-pattern-categories-wordpress/
- WPRiders best Gutenberg plugins 2026: https://wpriders.com/best-gutenberg-plugins-and-designers/

## Unresolved Questions

- Should the wizard's preset gallery thumbnails be inline SVG (recommended) or PNG (richer, larger bundle)? Final answer should be inline SVG in v1 to keep the bundle small and to avoid committing binary art. Confirm before Phase 3.
- Should built-in presets register as global block patterns in Phase 1? The current recommendation is **no**; revisit in v3 once the wizard UX is stable. Confirm before Phase 1.
- Should the preview endpoint stream or buffer the conversion output? For 1.0.x volumes (a typical blog post, 5-30 blocks) buffering is fine. Document the size cap (recommend 1 MB post-conversion) in the endpoint comment.

## Suggested Next Steps

1. The Phase 0 one-pager for the preset JSON schema should explicitly reference the `register_block_pattern` `keywords` and `categories` arrays as the starting vocabulary, then narrow to the role set proposed (`hero`, `body`, `cover`, `callout`, `feature_list`, `code_block`, `divider`, `cta`).
2. The Phase 1 spike should output a sample preset JSON alongside the `LayoutBlueprint` class, so the spike result is both runnable and reusable as the first preset in Phase 3.
3. Before Phase 3, do a 30-minute UI review of Kadence Cloud Library and one Elementor template kit page to lock the gallery card design. No new code; reference only.
