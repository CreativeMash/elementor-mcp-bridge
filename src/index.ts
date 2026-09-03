#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { loadConfig, requireFigma, requireWordPress } from "./config.js";
import { collectImageNodeIds, convertFigmaNode } from "./elementor.js";
import { FigmaClient, parseFigmaUrl } from "./figma.js";
import { importElementorImages, WordPressClient } from "./wordpress.js";

const config = loadConfig();
const server = new McpServer({ name: "elementor-figma-mcp", version: "0.1.0" });
const text = (value: unknown) => ({ content: [{ type: "text" as const, text: JSON.stringify(value, null, 2) }] });
const failure = (error: unknown) => ({ isError: true, content: [{ type: "text" as const, text: error instanceof Error ? error.message : String(error) }] });

async function convert(figmaUrl: string) {
  requireFigma(config);
  const ref = parseFigmaUrl(figmaUrl);
  const figma = new FigmaClient(config.figmaToken);
  const node = await figma.getNode(ref);
  const imageUrls = await figma.getImageUrls(ref.fileKey, collectImageNodeIds(node));
  return convertFigmaNode(node, { fileKey: ref.fileKey, nodeId: ref.nodeId, figmaUrl }, imageUrls);
}

server.tool("figma_inspect_frame", "Inspect a selected Figma frame and report what can be converted to Elementor without changing WordPress.", {
  figmaUrl: z.string().url().describe("Figma design URL containing node-id")
}, async ({ figmaUrl }) => {
  try { const result = await convert(figmaUrl); return text({ title: result.title, source: result.source, stats: result.stats, warnings: result.warnings }); }
  catch (error) { return failure(error); }
});

server.tool("figma_convert_preview", "Convert a selected Figma frame to Elementor JSON for review. This does not change WordPress.", {
  figmaUrl: z.string().url()
}, async ({ figmaUrl }) => {
  try { return text(await convert(figmaUrl)); } catch (error) { return failure(error); }
});

server.tool("elementor_connection_status", "Check the Elementor bridge, WordPress and Elementor versions.", {}, async () => {
  try { requireWordPress(config); return text(await new WordPressClient(config).health()); } catch (error) { return failure(error); }
});

server.tool("elementor_list_pages", "List Elementor pages available to inspect or update.", {
  search: z.string().default("")
}, async ({ search }) => {
  try { requireWordPress(config); return text(await new WordPressClient(config).listPages(search)); } catch (error) { return failure(error); }
});

server.tool("elementor_get_page", "Read the Elementor JSON and page details for one page without changing it.", {
  pageId: z.number().int().positive()
}, async ({ pageId }) => {
  try { requireWordPress(config); return text(await new WordPressClient(config).getPage(pageId)); } catch (error) { return failure(error); }
});

server.tool("figma_create_elementor_draft", "Create a new editable Elementor draft from a Figma frame. Requires confirm=true and never publishes the page.", {
  figmaUrl: z.string().url(),
  title: z.string().min(1).optional(),
  importImages: z.boolean().default(true),
  confirm: z.boolean().default(false).describe("Must be true after the user approves creating the draft")
}, async ({ figmaUrl, title, importImages, confirm }) => {
  if (!confirm) return failure(new Error("No changes made. Set confirm=true only after the user approves creating a WordPress draft."));
  try {
    requireWordPress(config);
    const result = await convert(figmaUrl);
    const wordpress = new WordPressClient(config);
    if (importImages) await importElementorImages(result.content, wordpress);
    const page = await wordpress.createPage(title ?? result.title, result.content, "draft");
    return text({ page, warnings: result.warnings, stats: result.stats });
  } catch (error) { return failure(error); }
});

server.tool("figma_update_elementor_page", "Replace an existing page's Elementor layout from a Figma frame. Requires explicit confirmation. The bridge creates a WordPress revision first.", {
  pageId: z.number().int().positive(),
  figmaUrl: z.string().url(),
  updateTitle: z.boolean().default(false),
  importImages: z.boolean().default(true),
  confirm: z.boolean().default(false)
}, async ({ pageId, figmaUrl, updateTitle, importImages, confirm }) => {
  if (!confirm) return failure(new Error("No changes made. Set confirm=true only after the user approves replacing the page layout."));
  try {
    requireWordPress(config);
    const result = await convert(figmaUrl);
    const wordpress = new WordPressClient(config);
    if (importImages) await importElementorImages(result.content, wordpress);
    const page = await wordpress.updatePage(pageId, result.content, updateTitle ? result.title : undefined);
    return text({ page, warnings: result.warnings, stats: result.stats });
  } catch (error) { return failure(error); }
});

server.tool("elementor_regenerate_css", "Clear and regenerate Elementor CSS/cache after a confirmed page change.", {
  confirm: z.boolean().default(false)
}, async ({ confirm }) => {
  if (!confirm) return failure(new Error("No changes made. Set confirm=true after the user approves regenerating Elementor CSS."));
  try { requireWordPress(config); return text(await new WordPressClient(config).regenerateCss()); } catch (error) { return failure(error); }
});

const transport = new StdioServerTransport();
await server.connect(transport);
