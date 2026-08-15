---
title: Gutenberg list serialization research
status: complete
created: 2026-08-15
---

# Researcher 01: Gutenberg lists

WP 6.4+ `core/list` save requires `core/list-item` inner blocks. DocSync emits flat `<ul><li>` with empty `innerBlocks`. Editor deprecation migrates some cases; multiple GDocs lists + `li>p`/`span` wrappers fail.

Example Doc `18uBD2pW6Xh1JSuVK9VfxGcXv88xYAlVMKbdMnzA32Ts`: 0 ol, 2 ul, 10 li-bullet, numbered outline is H2/H3 text.

Do not convert `"1. Title"` to ordered lists.

Full findings captured in Phase 1. Sources: Gutenberg `list/save.js`, `list-item/save.js`, `list/deprecated.js`.
