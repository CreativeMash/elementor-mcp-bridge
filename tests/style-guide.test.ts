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
