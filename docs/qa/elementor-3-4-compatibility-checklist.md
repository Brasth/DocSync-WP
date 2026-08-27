# Elementor 3.x / 4.x compatibility checklist

Use this on staging before a public release. Do not treat JSON golden fixtures as a substitute.

## Matrix

Run the same Doc (heading, intro, image, list, table) on:

1. Latest Elementor 3.x, Atomic Editor off, containers on
2. Latest Elementor 4.x, Atomic Editor off
3. Latest Elementor 4.x, Atomic Editor on (new-site default)

For each cell:

- [ ] Feature Block preset opens in the Elementor editor without a schema error
- [ ] Hero Page preset opens and shows the first heading / intro / image as the hero group
- [ ] Legacy converter (no preset) still opens
- [ ] Frontend matches the editor after `PostUpdater` cache clear
- [ ] Re-sync after a preset change rewrites `_elementor_data` and `_elementor_version`
- [ ] No Pro widgets appear in the generated JSON
- [ ] Images from Media Library keep captions

## Pass / fail

- **Pass:** v3 widgets (`heading`, `text-editor`, `image`, `icon-list`, `divider`, `spacer`, `html`) remain editable on Atomic-on sites.
- **Fail:** editor refuses the JSON, or content is uneditable. File the fixture plus Elementor version. Do not rewrite presets to Atomic elements unless three agency sites hit this.

## Out of scope

Atomic `e-heading` output, Pro widgets, Bricks/Divi, visual polish of hard-coded hero colors.
