import { createHash } from "node:crypto";
import type { ConversionResult, ElementorElement, FigmaColor, FigmaFrameStyleUsage, FigmaNode, FigmaPaint } from "./types.js";

const rgb = (color?: FigmaColor): string | undefined => {
  if (!color) return undefined;
  const values = [color.r, color.g, color.b].map((value) => Math.round(value * 255));
  return (color.a ?? 1) < 1 ? `rgba(${values.join(", ")}, ${Number((color.a ?? 1).toFixed(3))})` : `#${values.map((v) => v.toString(16).padStart(2, "0")).join("")}`;
};

const solid = (paints?: FigmaPaint[]): string | undefined => rgb(paints?.find((paint) => paint.visible !== false && paint.type === "SOLID")?.color);
const idFor = (value: string): string => createHash("sha1").update(value).digest("hex").slice(0, 7);
const size = (value?: number, unit = "px") => value == null ? undefined : { unit, size: Number(value.toFixed(2)), sizes: [] };
const box = (top = 0, right = top, bottom = top, left = right) => ({ unit: "px", top: String(top), right: String(right), bottom: String(bottom), left: String(left), isLinked: top === right && right === bottom && bottom === left });

function shadow(node: FigmaNode): Record<string, unknown> | undefined {
  const effect = node.effects?.find((item) => item.visible !== false && item.type === "DROP_SHADOW");
  if (!effect) return undefined;
  return { horizontal: effect.offset?.x ?? 0, vertical: effect.offset?.y ?? 4, blur: effect.radius ?? 12, spread: 0, color: rgb(effect.color) ?? "rgba(0,0,0,.15)" };
}

function commonSettings(node: FigmaNode): Record<string, unknown> {
  const settings: Record<string, unknown> = {};
  const background = solid(node.fills);
  const border = solid(node.strokes);
  if (background) { settings.background_background = "classic"; settings.background_color = background; }
  if (border) { settings.border_border = "solid"; settings.border_color = border; settings.border_width = box(node.strokeWeight ?? 1); }
  if (node.opacity != null && node.opacity !== 1) settings.opacity = { unit: "px", size: node.opacity, sizes: [] };
  const radius = node.cornerRadius ?? node.rectangleCornerRadii?.[0];
  if (radius != null) settings.border_radius = box(radius);
  const value = shadow(node);
  if (value) settings.box_shadow_box_shadow = value;
  return settings;
}

function textElement(node: FigmaNode): ElementorElement {
  const fontSize = node.style?.fontSize ?? 16;
  const heading = fontSize >= 24 || /heading|title|h[1-6]/i.test(node.name);
  const settings: Record<string, unknown> = {
    ...commonSettings(node),
    [heading ? "title" : "editor"]: node.characters ?? "",
    text_color: solid(node.fills),
    typography_typography: "custom",
    typography_font_family: node.style?.fontFamily,
    typography_font_size: size(fontSize),
    typography_font_weight: node.style?.fontWeight ? String(node.style.fontWeight) : undefined,
    typography_line_height: size(node.style?.lineHeightPx),
    typography_letter_spacing: size(node.style?.letterSpacing),
    align: String(node.style?.textAlignHorizontal ?? "LEFT").toLowerCase()
  };
  if (heading) settings.header_size = fontSize >= 44 ? "h1" : fontSize >= 32 ? "h2" : "h3";
  return { id: idFor(node.id), elType: "widget", widgetType: heading ? "heading" : "text-editor", settings, elements: [] };
}

function imageElement(node: FigmaNode, imageUrl?: string): ElementorElement {
  return {
    id: idFor(node.id), elType: "widget", widgetType: "image", elements: [],
    settings: { ...commonSettings(node), image: { url: imageUrl ?? "", id: "" }, image_size: "full", _width: size(node.absoluteBoundingBox?.width) }
  };
}

function isImage(node: FigmaNode): boolean {
  return ["RECTANGLE", "ELLIPSE", "VECTOR", "BOOLEAN_OPERATION", "STAR", "POLYGON"].includes(node.type) || Boolean(node.fills?.some((fill) => fill.type === "IMAGE"));
}

function container(node: FigmaNode, children: ElementorElement[]): ElementorElement {
  const horizontal = node.layoutMode === "HORIZONTAL";
  const settings: Record<string, unknown> = {
    ...commonSettings(node),
    content_width: "full",
    flex_direction: horizontal ? "row" : "column",
    flex_justify_content: ({ MIN: "flex-start", CENTER: "center", MAX: "flex-end", SPACE_BETWEEN: "space-between" } as Record<string, string>)[node.primaryAxisAlignItems ?? ""] ?? "flex-start",
    flex_align_items: ({ MIN: "flex-start", CENTER: "center", MAX: "flex-end", BASELINE: "baseline" } as Record<string, string>)[node.counterAxisAlignItems ?? ""] ?? "stretch",
    flex_gap: node.itemSpacing != null ? { column: String(node.itemSpacing), row: String(node.itemSpacing), isLinked: true, unit: "px", size: node.itemSpacing } : undefined,
    padding: box(node.paddingTop, node.paddingRight, node.paddingBottom, node.paddingLeft),
    width: node.layoutGrow === 1 || node.layoutSizingHorizontal === "FILL" ? { unit: "%", size: 100, sizes: [] } : size(node.absoluteBoundingBox?.width),
    min_height: node.layoutSizingVertical === "FIXED" ? size(node.absoluteBoundingBox?.height) : undefined
  };
  return { id: idFor(node.id), elType: "container", isInner: true, settings, elements: children };
}

export function collectImageNodeIds(node: FigmaNode): string[] {
  const found: string[] = [];
  const visit = (current: FigmaNode) => {
    if (current.visible !== false && isImage(current)) found.push(current.id);
    current.children?.forEach(visit);
  };
  visit(node);
  return found;
}

export function collectFrameStyleUsage(node: FigmaNode): FigmaFrameStyleUsage {
  const colors = new Map<string, number>();
  const typography = new Map<string, FigmaFrameStyleUsage["typography"][number]>();
  const components: FigmaFrameStyleUsage["components"] = [];

  const countColor = (paint?: FigmaPaint) => {
    if (!paint || paint.visible === false || paint.type !== "SOLID") return;
    const value = rgb(paint.color);
    if (value) colors.set(value, (colors.get(value) ?? 0) + 1);
  };

  const visit = (current: FigmaNode) => {
    current.fills?.forEach(countColor);
    current.strokes?.forEach(countColor);

    if (current.type === "TEXT" && current.style?.fontFamily) {
      const text = {
        fontFamily: current.style.fontFamily,
        fontWeight: current.style.fontWeight,
        fontSize: current.style.fontSize,
        lineHeightPx: current.style.lineHeightPx,
        letterSpacing: current.style.letterSpacing,
        occurrences: 1
      };
      const key = [text.fontFamily, text.fontWeight ?? "", text.fontSize ?? "", text.lineHeightPx ?? "", text.letterSpacing ?? ""].join("|");
      const existing = typography.get(key);
      typography.set(key, existing ? { ...existing, occurrences: existing.occurrences + 1 } : text);
    }

    if (current.type === "COMPONENT" || current.type === "INSTANCE") {
      components.push({ id: current.id, name: current.name, type: current.type });
    }

    current.children?.forEach(visit);
  };

  visit(node);
  return {
    colors: [...colors.entries()].map(([value, occurrences]) => ({ value, occurrences })).sort((a, b) => b.occurrences - a.occurrences),
    typography: [...typography.values()].sort((a, b) => b.occurrences - a.occurrences),
    components
  };
}

export function convertFigmaNode(node: FigmaNode, source: ConversionResult["source"], imageUrls: Record<string, string> = {}): ConversionResult {
  const warnings: string[] = [];
  const stats = { containers: 0, widgets: 0, skipped: 0 };
  const walk = (current: FigmaNode): ElementorElement | null => {
    if (current.visible === false) { stats.skipped++; return null; }
    if (current.type === "TEXT") { stats.widgets++; return textElement(current); }
    if (isImage(current) && !current.children?.length) { stats.widgets++; return imageElement(current, imageUrls[current.id]); }
    if (current.children || ["FRAME", "GROUP", "COMPONENT", "INSTANCE", "SECTION"].includes(current.type)) {
      const children = (current.children ?? []).map(walk).filter((item): item is ElementorElement => item !== null);
      stats.containers++;
      if (!current.layoutMode || current.layoutMode === "NONE") warnings.push(`“${current.name}” has no Auto Layout; it was converted to a vertical container.`);
      return container(current, children);
    }
    stats.skipped++;
    warnings.push(`Skipped unsupported Figma layer “${current.name}” (${current.type}).`);
    return null;
  };
  const root = walk(node);
  return { title: node.name || "Figma design", source, content: root ? [root] : [], warnings: [...new Set(warnings)], stats };
}
