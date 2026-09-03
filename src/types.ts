export interface FigmaColor {
  r: number;
  g: number;
  b: number;
  a?: number;
}

export interface FigmaPaint {
  type: string;
  visible?: boolean;
  opacity?: number;
  color?: FigmaColor;
  imageRef?: string;
  gradientStops?: Array<{ position: number; color: FigmaColor }>;
}

export interface FigmaNode {
  id: string;
  name: string;
  type: string;
  visible?: boolean;
  children?: FigmaNode[];
  characters?: string;
  style?: Record<string, unknown> & {
    fontFamily?: string;
    fontWeight?: number;
    fontSize?: number;
    lineHeightPx?: number;
    letterSpacing?: number;
    textAlignHorizontal?: string;
    textDecoration?: string;
  };
  fills?: FigmaPaint[];
  strokes?: FigmaPaint[];
  strokeWeight?: number;
  opacity?: number;
  cornerRadius?: number;
  rectangleCornerRadii?: number[];
  effects?: Array<Record<string, unknown> & { type?: string; visible?: boolean; radius?: number; offset?: { x: number; y: number }; color?: FigmaColor }>;
  layoutMode?: "HORIZONTAL" | "VERTICAL" | "NONE";
  primaryAxisAlignItems?: string;
  counterAxisAlignItems?: string;
  primaryAxisSizingMode?: string;
  counterAxisSizingMode?: string;
  itemSpacing?: number;
  paddingTop?: number;
  paddingRight?: number;
  paddingBottom?: number;
  paddingLeft?: number;
  layoutGrow?: number;
  layoutSizingHorizontal?: string;
  layoutSizingVertical?: string;
  absoluteBoundingBox?: { x: number; y: number; width: number; height: number };
}

export interface ElementorElement {
  id: string;
  elType: "container" | "widget";
  widgetType?: string;
  isInner?: boolean;
  settings: Record<string, unknown>;
  elements: ElementorElement[];
}

export interface ConversionResult {
  title: string;
  source: { fileKey: string; nodeId: string; figmaUrl: string };
  content: ElementorElement[];
  warnings: string[];
  stats: { containers: number; widgets: number; skipped: number };
}
