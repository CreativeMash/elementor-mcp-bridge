import type { ElementorGlobalContext, FigmaDesignSystem, FigmaFrameStyleUsage, FigmaVariable } from "./types.js";

type ColorMapping = {
  source: { name: string; value: string; occurrences?: number };
  action: "match" | "add" | "conflict" | "unsupported";
  target?: { id: string; title: string; value: string };
  reason: string;
};

type TypographyMapping = {
  source: FigmaFrameStyleUsage["typography"][number];
  action: "match" | "add";
  target?: { id: string; title: string; fontFamily: string; fontWeight?: string };
  reason: string;
};

type SemanticColorMapping = ColorMapping & {
  role: string;
  selectionReason: string;
};

type SemanticTypographyMapping = TypographyMapping & {
  role: string;
  selectionReason: string;
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
  if (valueMatch) {
    return {
      source: { name, value, occurrences },
      action: "match",
      target: valueMatch,
      reason: "Matches an existing Elementor global color by value."
    };
  }
  if (nameMatch) {
    return {
      source: { name, value, occurrences },
      action: "conflict",
      target: nameMatch,
      reason: "An Elementor global color has the same semantic name but a different value."
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

function colorProfile(value: string): { brightness: number; chroma: number } | undefined {
  const match = value.match(/^#([0-9a-f]{6})$/i);
  if (!match) return undefined;
  const channels = [0, 2, 4].map((offset) => Number.parseInt(match[1].slice(offset, offset + 2), 16));
  return { brightness: (channels[0] + channels[1] + channels[2]) / (255 * 3), chroma: (Math.max(...channels) - Math.min(...channels)) / 255 };
}

function recommendPalette(usage: FigmaFrameStyleUsage, existing: ReturnType<typeof normalizeColors>) {
  const used = new Set<string>();
  const selected: SemanticColorMapping[] = [];
  const pick = (role: string, selectionReason: string, predicate: (color: FigmaFrameStyleUsage["colors"][number]) => boolean) => {
    const color = usage.colors.find((candidate) => !used.has(candidate.value) && predicate(candidate));
    if (!color) return;
    used.add(color.value);
    selected.push({ ...mapColor(role, color.value, existing, color.occurrences), role, selectionReason });
  };

  // These are recommendations only: all raw values remain available in frame_usage.
  pick("Surface", "Most-used very light neutral used by the frame.", (color) => (colorProfile(color.value)?.brightness ?? 0) >= 0.94 && (colorProfile(color.value)?.chroma ?? 1) <= 0.08);
  pick("Text", "Most-used dark neutral used by the frame.", (color) => (colorProfile(color.value)?.brightness ?? 1) <= 0.22 && (colorProfile(color.value)?.chroma ?? 1) <= 0.18);
  pick("Text muted", "Most-used mid-tone neutral used by the frame.", (color) => {
    const profile = colorProfile(color.value);
    return Boolean(profile && profile.brightness > 0.22 && profile.brightness <= 0.58 && profile.chroma <= 0.18);
  });
  pick("Border", "Most-used light neutral suitable for borders and dividers.", (color) => {
    const profile = colorProfile(color.value);
    return Boolean(profile && profile.brightness > 0.58 && profile.brightness < 0.94 && profile.chroma <= 0.18);
  });

  for (let index = 1; index <= 4; index++) {
    pick(`Accent ${index}`, "Frequently used saturated color; confirm its semantic role before import.", (color) => {
      const profile = colorProfile(color.value);
      return Boolean(profile && profile.chroma >= 0.3 && profile.brightness >= 0.2 && profile.brightness <= 0.85);
    });
  }

  return { selected, deferredCount: usage.colors.length - selected.length };
}

function mapTypography(style: FigmaFrameStyleUsage["typography"][number], existing: ReturnType<typeof normalizeTypography>): TypographyMapping {
  const target = existing.find((candidate) =>
    normalize(candidate.fontFamily) === normalize(style.fontFamily) &&
    (!style.fontWeight || !candidate.fontWeight || candidate.fontWeight === String(style.fontWeight))
  );
  return target
    ? { source: style, action: "match", target, reason: "Matches an existing Elementor global typography style." }
    : { source: style, action: "add", reason: "No matching Elementor global typography style was found." };
}

function recommendTypography(usage: FigmaFrameStyleUsage, existing: ReturnType<typeof normalizeTypography>) {
  const used = new Set<string>();
  const selected: SemanticTypographyMapping[] = [];
  const keyFor = (style: FigmaFrameStyleUsage["typography"][number]) => [style.fontFamily, style.fontWeight ?? "", style.fontSize ?? "", style.lineHeightPx ?? "", style.letterSpacing ?? ""].join("|");
  const pick = (role: string, selectionReason: string, candidates: FigmaFrameStyleUsage["typography"]) => {
    const style = candidates.find((candidate) => !used.has(keyFor(candidate)));
    if (!style) return;
    used.add(keyFor(style));
    selected.push({ ...mapTypography(style, existing), role, selectionReason });
  };
  const bySize = [...usage.typography].sort((a, b) => (b.fontSize ?? 0) - (a.fontSize ?? 0) || b.occurrences - a.occurrences);

  pick("Display", "Largest text style found in the frame.", bySize.filter((style) => (style.fontSize ?? 0) >= 28));
  const headingSized = bySize.filter((style) => (style.fontSize ?? 0) >= 20 && (style.fontSize ?? 0) < 28);
  pick("Heading", "Largest remaining heading-sized text style.", headingSized.length ? headingSized : bySize.filter((style) => (style.fontSize ?? 0) >= 20));
  pick("Body", "Most-used regular-weight text style.", usage.typography.filter((style) => (style.fontWeight ?? 400) <= 400));
  pick("Label", "Most-used compact, strong text style.", usage.typography.filter((style) => (style.fontSize ?? 0) <= 12 && (style.fontWeight ?? 0) >= 600));
  pick("UI emphasis", "Most-used remaining semibold text style.", usage.typography.filter((style) => (style.fontSize ?? 0) >= 13 && (style.fontWeight ?? 0) >= 600));

  return { selected, deferredCount: usage.typography.length - selected.length };
}

function recommendComponents(components: FigmaFrameStyleUsage["components"]) {
  const grouped = new Map<string, { name: string; type: string; occurrences: number }>();
  for (const component of components) {
    const key = `${component.type}|${component.name}`;
    const existing = grouped.get(key);
    grouped.set(key, existing ? { ...existing, occurrences: existing.occurrences + 1 } : { name: component.name, type: component.type, occurrences: 1 });
  }
  return [...grouped.values()].sort((a, b) => b.occurrences - a.occurrences).map((component) => ({
    ...component,
    suggestedRecipe: /button/i.test(component.name)
      ? "Elementor Button widget candidate"
      : /avatar/i.test(component.name)
        ? "Avatar recipe needed: Image widget plus shape and sizing rules"
        : "Custom Elementor recipe needed",
    action: "review"
  }));
}

export function buildStyleGuidePreview(
  source: { fileKey: string; nodeId: string; figmaUrl: string },
  designSystem: FigmaDesignSystem,
  usage: FigmaFrameStyleUsage,
  elementor: ElementorGlobalContext
) {
  const existingColors = normalizeColors(elementor);
  const existingTypography = normalizeTypography(elementor);
  const palette = recommendPalette(usage, existingColors);
  const typography = recommendTypography(usage, existingTypography);
  const components = recommendComponents(usage.components);

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
    recommended_style_guide: {
      palette,
      typography,
      components
    },
    proposed_mappings: {
      colors: [...palette.selected, ...variableColorMappings(designSystem.variables, existingColors)],
      typography: typography.selected,
      components: components.map((component) => ({
        source: component,
        action: "unmapped",
        reason: component.suggestedRecipe
      }))
    },
    warnings: designSystem.warnings
  };
}
