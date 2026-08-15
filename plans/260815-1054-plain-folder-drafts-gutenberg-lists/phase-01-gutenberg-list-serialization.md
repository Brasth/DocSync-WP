---
phase: 1
title: "Gutenberg list serialization"
status: done
priority: P1
dependencies: []
effort: "1-2d"
---

# Phase 1: Gutenberg list serialization

## Context Links

- Example Doc: https://docs.google.com/document/d/18uBD2pW6Xh1JSuVK9VfxGcXv88xYAlVMKbdMnzA32Ts/edit?tab=t.0
- Research: [researcher-01-gutenberg-lists.md](./research/researcher-01-gutenberg-lists.md)
- Core: [list save.js](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-library/src/list/save.js), [list-item save.js](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-library/src/list-item/save.js)
- Current factory: `src/Sync/HtmlBlockFactory.php`
- Current fixture: `tests/fixtures/layout-presets/lists-table-callout/expected.html`

## Overview

Make synced lists native WP 6.4+ blocks. This is the quality gate. Do not start folder UI until Gutenberg opens lists without recovery.

## Key Insights

- Factory writes `core/list` with `innerBlocks=[]` and raw `<ul>/<ol>` innerHTML.
- WP 6.4+ save is `core/list` → only `core/list-item` children. Nested lists live **inside** a list-item.
- Flat markup relies on editor deprecation + client migrate. Multiple lists + GDocs `li` wrappers break that path.
- Example Doc: **0 `<ol>`**, **2 `<ul>`**, 10 `li.li-bullet-0` with `<span>` children. `1.` / `1.1.` are **headings**.
- `wp_kses` list allowlist strips `p` inside `li` but does not unwrap cleanly. Do explicit unwrap.
- Elementor widgets can keep HTML lists. Do not change Elementor converters in this phase.

## Requirements

- Functional: `ul`/`ol` become `core/list` with `core/list-item` inner blocks.
- Functional: sibling lists stay separate blocks (do not merge).
- Functional: one nest: `li` containing `ul`/`ol` → list-item with inner `core/list`.
- Functional: unwrap `li > p` and presentation `span` shells to inline text.
- Functional: numbered heading text stays heading. Zero false `ol`.
- Non-functional: both HTML ZIP and Docs API HTML go through the same list builder.
- Non-functional: fixtures match serialized nested `<!-- wp:list-item -->` comments.
- Non-functional: update `scripts/verify-layout-fixtures.php` `serialize_block()` to walk `innerContent` null slots + `innerBlocks` (today it dumps `innerHTML` only and would emit empty `<ul></ul>`).
<!-- Updated: Validation Session 1 - fixture shim must recurse innerBlocks -->

## Architecture

```text
Sanitized HTML
  → LayoutConversionService / HtmlToBlockContentConverter
    → HtmlBlockFactory::fromElement(ul|ol)
      → new list builder
        → core/list { ordered?, start? }
           innerBlocks: [ core/list-item, ... ]
           innerContent: [ '<ul class="wp-block-list">', null..., '</ul>' ]
```

Docs API `DocsApiParagraphRenderer` still emits `<ul>/<ol>/<li>` HTML. Prefer one nest in HTML (do not close/reopen on every level if avoidable), then same factory. If renderer still emits sibling lists per level, factory must not invent false nests.

Helper shape:

```php
// core/list-item
[ 'blockName' => 'core/list-item', 'attrs' => [], 'innerBlocks' => $nested,
  'innerHTML' => '<li>' . $inline . '</li>',
  'innerContent' => $nested ? [ '<li>' . $inline, null, '</li>' ] : [ '<li>' . $inline . '</li>' ] ]

// core/list
[ 'blockName' => 'core/list', 'attrs' => $ordered ? [ 'ordered' => true ] : [],
  'innerBlocks' => $items,
  'innerHTML' => '<' . $tag . ' class="wp-block-list"></' . $tag . '>',
  'innerContent' => [ '<' . $tag . ' class="wp-block-list">', null, ..., '</' . $tag . '>' ] ]
```

Deeper than one nest: flatten extra levels into the inner list (YAGNI). Ignore `start` unless the `ol` has a real `start` attribute.

## Related Code Files

- Modify: `src/Sync/HtmlBlockFactory.php`
- Create: `src/Sync/HtmlListBlockBuilder.php` (keep factory under 200 lines)
- Modify: `src/Sync/HtmlBlockMarkupSanitizer.php` (unwrap `li>p`; allow list-item inline only)
- Modify if needed: `src/Sync/DocsApiParagraphRenderer.php` (sibling-list vs nest)
- Modify: `tests/fixtures/layout-presets/lists-table-callout/expected.html`
- Create fixtures:
  - `tests/fixtures/layout-presets/seo-article-two-ul-sibling/`
  - `tests/fixtures/layout-presets/seo-article-heading-numbers/`
  - `tests/fixtures/layout-presets/seo-article-li-span-p/`
  - `tests/fixtures/layout-presets/seo-article-nested-ul/`
- Modify: `scripts/verify-layout-fixtures.php` — recurse `innerBlocks` in `serialize_block()`
- Do not modify: Elementor converters, Drive browser, SourceController

## Implementation Steps

1. Add failing fixtures from the example Doc class (two sibling `ul`; H2 `1.` / H3 `1.1.`; `li>span` / `li>p`; one nest).
2. Implement `HtmlListBlockBuilder` that walks a `ul`/`ol` DOM node and returns a `core/list` array.
3. Switch `HtmlBlockFactory` `ul`/`ol` arms to the builder.
4. Unwrap `li > p` and empty presentation spans before list-item content.
5. Update `lists-table-callout` expected markup to nested `wp:list-item`.
6. Update `serialize_block()` in `scripts/verify-layout-fixtures.php` to emit child comments from `innerBlocks` (WordPress `innerContent` null-slot rules). Without this, expected files cannot encode list-items.
7. Run `composer test:layout-fixtures`. Fix until green.
8. If Docs API nesting emits sibling lists for one outline, only fix if a fixture proves it. Do not rewrite the renderer speculatively.

## Todo List

- [x] Failing fixtures committed first
- [x] `HtmlListBlockBuilder` + factory switch
- [x] Unwrap `li>p` / span shells
- [x] Update existing list fixture
- [x] Fixture shim recurses innerBlocks
- [x] `composer test:layout-fixtures` green

## Success Criteria

- [x] Serialized output contains `<!-- wp:list-item -->` per item; every `core/list` has only `core/list-item` children in the block array
- [x] Two sibling `ul` → two `core/list` blocks
- [x] Heading `1. Title` → `core/heading`, not ordered list
- [x] Nested `ul` lives under parent list-item `innerBlocks`
- [x] Existing layout fixtures pass
- [ ] Gutenberg editor on a synced list post shows no "Attempt recovery" (spot-check in Phase 3; Phase 1 at least fixture-valid)

## Risk Assessment

- Old synced posts stay flat until next sync. Correct. Do not migrate stored markup.
- Matching `wp-block-list` class: safer vs current core save. Use it.
- Over-unwrapping spans that wrap links/bold: unwrap only span with no href and no semantic tag; keep `a`/`strong`/`em`.
- Elementor regression: do not touch Elementor list HTML.

## Security Considerations

- List-item inner HTML still goes through `wp_kses` inline allowlist.
- No new REST. No new Google scope.

## Next Steps

Phase 2 after fixtures are green.
