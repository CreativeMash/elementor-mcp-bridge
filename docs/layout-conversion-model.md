# Layout Conversion Model

## Purpose

The plugin first converts a Figma frame into a versioned, renderer-neutral layout model. A renderer can then target Elementor, HTML/CSS, React, or another system without repeating Figma-specific parsing rules.

This is deliberately separate from the current Elementor renderer. The model is introduced as read-only analysis so we can validate it against real frames before switching draft generation to depend on it.

## Model v1

Each Figma node records:

- `source`: Figma ID, layer name, type, and bounds.
- `kind`: `section`, `container`, `text`, `asset`, `component`, or `unknown`.
- `content`: text content when applicable.
- `layout`: strategy, direction, gap, padding, sizing, alignment, and wrapping.
- `recognition`: a conservative component recipe only when confidence is high.
- `children`: nested layout nodes.

`layout.strategy` is one of:

- `native-flow`: Figma Auto Layout maps predictably to a flex/container renderer.
- `coordinate-fallback`: a node with children has no Auto Layout, so a renderer must retain its geometry or surface it as a manual-review item.
- `none`: a leaf node has no child-layout responsibility.

## Safety Rules

- Auto Layout is preferred, but non-Auto Layout layers are not silently guessed as responsive flow.
- Component recognition is deliberately conservative. Version 1 recognizes a component named `Button` as a future button-widget candidate; all other components remain editable structures.
- Analysis never writes WordPress content, Elementor data, global styles, or Figma.
- The preview stores only a compact model summary. The Figma document remains the canonical source for the current import session.

## Rollout

1. Analyze frames and display native-flow versus coordinate-fallback counts.
2. Validate model output against real imports and visual comparisons.
3. Introduce renderer recipes one at a time, beginning with high-confidence components.
4. Keep coordinate fallback for ambiguous layouts and make any manual refinement visible in the import plan.
