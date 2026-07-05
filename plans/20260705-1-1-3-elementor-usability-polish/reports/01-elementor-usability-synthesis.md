# Elementor Usability Synthesis

Date: 2026-07-05

## Context

Version 1.1.2 added Elementor Hero Page and Feature Block presets. Local fixture verification proves valid Elementor JSON for narrow examples, but product feedback shows the behavior does not match end-user expectation.

## Key Findings

- End users interpret "Elementor support" as a visible choice that produces Elementor layouts.
- Current behavior depends on source/post flags and whether an explicit Elementor preset exists.
- Existing Elementor sources without a preset intentionally stay on legacy output.
- New synced drafts are not clearly routed to Elementor.
- Preset fixtures validate JSON shape, not real frontend publishability.
- Large-doc fallback can bypass Elementor presets and use legacy conversion.

## Product Direction

- Make output type explicit at link time.
- Remember choice per source.
- Avoid silent migration of live Elementor sources.
- Present legacy output as a compatibility state, not a normal product path.
- Improve Elementor preset output until it is acceptable without developer edits for simple agency docs.

## Recommended Release Framing

1.1.3 should be "Elementor usability and output polish", not only "logging". It still fits roadmap goal "manual QA publishable output: 0 developer edits for fixture set".

## Risks

- Silent migration can damage live client pages.
- Asking on every sync would annoy repeat users.
- Overbuilding a preset gallery now duplicates 1.2.0 roadmap work.
- Using Pro widgets would break free Elementor users.

## Unresolved Questions

- Bulk upgrade can wait unless staging has many legacy Elementor sources.
- Preview UI remains 1.2.x unless current user testing proves it blocks adoption.
