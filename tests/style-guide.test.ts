import assert from "node:assert/strict";
import test from "node:test";
import { buildStyleGuidePreview } from "../src/style-guide.js";

test("matches frame colors and typography against Elementor globals", () => {
  const preview = buildStyleGuidePreview(
    { fileKey: "file", nodeId: "1:1", figmaUrl: "https://figma.com" },
    { styles: [], variables: [], variableCollections: [], warnings: [] },
    {
      colors: [{ value: "#112233", occurrences: 2 }],
      typography: [{ fontFamily: "DM Sans", fontWeight: 700, fontSize: 32, occurrences: 1 }],
      components: [{ id: "1:2", name: "Primary button", type: "INSTANCE" }]
    },
    {
      activeKit: { id: 1, title: "Default Kit" },
      colors: [{ _id: "primary", title: "Primary", color: "#112233" }],
      typography: [{ _id: "heading", title: "Heading", typography_font_family: "DM Sans", typography_font_weight: "700" }]
    }
  );

  assert.equal(preview.proposed_mappings.colors[0].action, "match");
  assert.equal(preview.proposed_mappings.typography[0].action, "match");
  assert.equal(preview.proposed_mappings.components[0].action, "unmapped");
  assert.equal(preview.recommended_style_guide.palette.selected[0].role, "Text");
  assert.equal(preview.recommended_style_guide.typography.selected[0].role, "Display");
});

test("reports alias variables as unsupported instead of guessing", () => {
  const preview = buildStyleGuidePreview(
    { fileKey: "file", nodeId: "1:1", figmaUrl: "https://figma.com" },
    {
      styles: [],
      variableCollections: [],
      warnings: [],
      variables: [{ id: "var", name: "Brand/Primary", variableCollectionId: "collection", resolvedType: "COLOR", valuesByMode: { mode: { type: "VARIABLE_ALIAS", id: "other" } } }]
    },
    { colors: [], typography: [], components: [] },
    { activeKit: { id: null, title: null }, colors: [], typography: [] }
  );

  assert.equal(preview.proposed_mappings.colors[0].action, "unsupported");
});

test("groups repeated components and limits style recommendations", () => {
  const preview = buildStyleGuidePreview(
    { fileKey: "file", nodeId: "1:1", figmaUrl: "https://figma.com" },
    { styles: [], variables: [], variableCollections: [], warnings: [] },
    {
      colors: [
        { value: "#ffffff", occurrences: 12 },
        { value: "#0f172a", occurrences: 10 },
        { value: "#64748b", occurrences: 5 },
        { value: "#e2e8f0", occurrences: 4 },
        { value: "#0070ff", occurrences: 3 },
        { value: "#ee5396", occurrences: 2 }
      ],
      typography: [
        { fontFamily: "Inter", fontWeight: 700, fontSize: 32, occurrences: 1 },
        { fontFamily: "Inter", fontWeight: 400, fontSize: 16, occurrences: 8 },
        { fontFamily: "Inter", fontWeight: 700, fontSize: 11, occurrences: 12 }
      ],
      components: [
        { id: "1", name: "Avatar", type: "INSTANCE" },
        { id: "2", name: "Avatar", type: "INSTANCE" },
        { id: "3", name: "Button", type: "INSTANCE" }
      ]
    },
    { activeKit: { id: null, title: null }, colors: [], typography: [] }
  );

  assert.equal(preview.recommended_style_guide.palette.selected.length, 6);
  assert.equal(preview.recommended_style_guide.components.length, 2);
  assert.equal(preview.recommended_style_guide.components[0].occurrences, 2);
});

test("reports name-only color matches as conflicts", () => {
  const preview = buildStyleGuidePreview(
    { fileKey: "file", nodeId: "1:1", figmaUrl: "https://figma.com" },
    { styles: [], variables: [], variableCollections: [], warnings: [] },
    { colors: [{ value: "#0f172a", occurrences: 4 }], typography: [], components: [] },
    { activeKit: { id: 1, title: "Default Kit" }, colors: [{ _id: "text", title: "Text", color: "#7a7a7a" }], typography: [] }
  );

  assert.equal(preview.proposed_mappings.colors[0].action, "conflict");
});
