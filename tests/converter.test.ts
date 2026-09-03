import assert from "node:assert/strict";
import test from "node:test";
import { collectImageNodeIds, convertFigmaNode } from "../src/elementor.js";
import { parseFigmaUrl } from "../src/figma.js";
import type { FigmaNode } from "../src/types.js";

test("parses modern Figma URLs", () => {
  assert.deepEqual(parseFigmaUrl("https://www.figma.com/design/abc123/Example?node-id=12-34"), { fileKey: "abc123", nodeId: "12:34", url: "https://www.figma.com/design/abc123/Example?node-id=12-34" });
});

test("converts auto layout and text to native Elementor elements", () => {
  const node: FigmaNode = { id: "1:1", name: "Hero", type: "FRAME", layoutMode: "VERTICAL", itemSpacing: 24, paddingTop: 40, paddingRight: 20, paddingBottom: 40, paddingLeft: 20, children: [
    { id: "1:2", name: "Heading", type: "TEXT", characters: "Hello world", style: { fontFamily: "Inter", fontWeight: 700, fontSize: 48 }, fills: [{ type: "SOLID", color: { r: 0, g: 0, b: 0 } }] }
  ] };
  const result = convertFigmaNode(node, { fileKey: "abc", nodeId: "1:1", figmaUrl: "https://figma.com" });
  assert.equal(result.stats.containers, 1);
  assert.equal(result.stats.widgets, 1);
  assert.equal(result.content[0].elType, "container");
  assert.equal(result.content[0].elements[0].widgetType, "heading");
  assert.equal(result.content[0].elements[0].settings.title, "Hello world");
});

test("collects renderable image layers", () => {
  const node: FigmaNode = { id: "1", name: "Card", type: "FRAME", children: [{ id: "2", name: "Photo", type: "RECTANGLE", fills: [{ type: "IMAGE", imageRef: "x" }] }] };
  assert.deepEqual(collectImageNodeIds(node), ["2"]);
});
