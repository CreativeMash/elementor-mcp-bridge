import type { ElementorGlobalContext, FigmaDesignSystem, FigmaFrameStyleUsage, FigmaVariable } from "./types.js";

type ColorMapping = {
  source: { name: string; value: string; occurrences?: number };
  action: "match" | "add" | "unsupported";
  target?: { id: string; title: string; value: string };
  reason: string;
};

type TypographyMapping = {
  source: FigmaFrameStyleUsage["typography"][number];
  action: "match" | "add";
  target?: { id: string; title: string; fontFamily: string; fontWeight?: string };
  reason: string;
};

const normalize = (value: string) => value.trim().toLowerCase();

function colorFromVariable(value: unknown): string | undefined {
  if (!value || typeof value !== "object" || "type" in value) return undefined;
  const color = value as { r?: number; g?: number; b?: number; a?: number };
  if ([color.r, color.g, color.b].some((channel) => typeof channel !== "number")) return undefined;
  const channels = [color.r, color.g, color.b].map((channel) => Math.round((channel ?? 0) * 255));
  return (color.a ?? 1) < 1
    ? `rgba(${channels.join(", ")}, ${Number((color.a ?? 1).toFixed(3))})`
    : `#${channels.map((channel) => channel.toString(16).padStart(2, "0")).join("")}`;
}

function normalizeColors(context: ElementorGlobalContext) {
  return context.colors.flatMap((color) => {
    const id = typeof color._id === "string" ? color._id : "";
    const title = typeof color.title === "string" ? color.title : id;
    const value = typeof color.color === "string" ? color.color : "";
    return id && value ? [{ id, title, value }] : [];
  });
}

function normalizeTypography(context: ElementorGlobalContext) {
  return context.typography.flatMap((style) => {
    const id = typeof style._id === "string" ? style._id : "";
    const title = typeof style.title === "string" ? style.title : id;
    const fontFamily = typeof style.typography_font_family === "string" ? style.typography_font_family : "";
    const fontWeight = typeof style.typography_font_weight === "string" ? style.typography_font_weight : undefined;
    return id && fontFamily ? [{ id, title, fontFamily, fontWeight }] : [];
  });
}

function mapColor(name: string, value: string, existing: ReturnType<typeof normalizeColors>, occurrences?: number): ColorMapping {
  const nameMatch = existing.find((color) => normalize(color.title) === normalize(name));
  const valueMatch = existing.find((color) => normalize(color.value) === normalize(value));
  const target = nameMatch ?? valueMatch;
  if (target) {
    return {
      source: { name, value, occurrences },
      action: "match",
      target,
      reason: nameMatch ? "Matches an existing Elementor global color by name." : "Matches an existing Elementor global color by value."
    };
  }
  return { source: { name, value, occurrences }, action: "add", reason: "No matching Elementor global color was found." };
}

function variableColorMappings(variables: FigmaVariable[], existing: ReturnType<typeof normalizeColors>): ColorMapping[] {
  return variables.flatMap((variable) => {
    if (variable.resolvedType !== "COLOR") return [];
    const modes = Object.entries(variable.valuesByMode);
    if (!modes.length) return [{ source: { name: variable.name, value: "" }, action: "unsupported", reason: "The color variable has no mode value." }];
    return modes.map(([mode, value]) => {
      const color = colorFromVariable(value);
      if (!color) {
        return {
          source: { name: `${variable.name} (${mode})`, value: "" },
          action: "unsupported" as const,
          reason: "This color variable aliases another variable or uses an unsupported value."
        };
      }
      return mapColor(`${variable.name} (${mode})`, color, existing);
    });
  });
}

export function buildStyleGuidePreview(
  source: { fileKey: string; nodeId: string; figmaUrl: string },
  designSystem: FigmaDesignSystem,
  usage: FigmaFrameStyleUsage,
  elementor: ElementorGlobalContext
) {
  const existingColors = normalizeColors(elementor);
  const existingTypography = normalizeTypography(elementor);
  const frameColors = usage.colors.map((color) => mapColor(`Frame color ${color.value}`, color.value, existingColors, color.occurrences));
  const typography: TypographyMapping[] = usage.typography.map((style) => {
    const target = existingTypography.find((candidate) =>
      normalize(candidate.fontFamily) === normalize(style.fontFamily) &&
      (!style.fontWeight || !candidate.fontWeight || candidate.fontWeight === String(style.fontWeight))
    );
    return target
      ? { source: style, action: "match", target, reason: "Matches an existing Elementor global typography style." }
      : { source: style, action: "add", reason: "No matching Elementor global typography style was found." };
  });

  return {
    source,
    figma: {
      published_styles: designSystem.styles,
      variables: {
        collections: designSystem.variableCollections,
        variables: designSystem.variables,
        color_variable_count: designSystem.variables.filter((variable) => variable.resolvedType === "COLOR").length
      },
      frame_usage: usage
    },
    elementor,
    proposed_mappings: {
      colors: [...frameColors, ...variableColorMappings(designSystem.variables, existingColors)],
      typography,
      components: usage.components.map((component) => ({
        source: component,
        action: "unmapped",
        reason: "Component-to-Elementor widget recipes are not implemented yet."
      }))
    },
    warnings: designSystem.warnings
  };
}
