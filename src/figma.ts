import type { FigmaNode } from "./types.js";

export interface FigmaReference { fileKey: string; nodeId: string; url: string }

export function parseFigmaUrl(url: string): FigmaReference {
  const parsed = new URL(url);
  if (!/(^|\.)figma\.com$/.test(parsed.hostname)) throw new Error("The URL must be a figma.com file or design URL.");
  const match = parsed.pathname.match(/^\/(?:file|design)\/([^/]+)/);
  if (!match) throw new Error("Could not find a Figma file key in the URL.");
  const rawNode = parsed.searchParams.get("node-id");
  if (!rawNode) throw new Error("The Figma URL must include a selected node (node-id).");
  const nodeId = rawNode.replace(/-/g, ":");
  return { fileKey: match[1], nodeId, url };
}

export class FigmaClient {
  constructor(private readonly token: string, private readonly apiBase = "https://api.figma.com/v1") {}

  private async request<T>(path: string): Promise<T> {
    const response = await fetch(`${this.apiBase}${path}`, { headers: { "X-Figma-Token": this.token } });
    if (!response.ok) throw new Error(`Figma API error ${response.status}: ${await response.text()}`);
    return response.json() as Promise<T>;
  }

  async getNode(ref: FigmaReference): Promise<FigmaNode> {
    const data = await this.request<{ nodes: Record<string, { document: FigmaNode } | null> }>(
      `/files/${encodeURIComponent(ref.fileKey)}/nodes?ids=${encodeURIComponent(ref.nodeId)}&geometry=paths`
    );
    const node = data.nodes[ref.nodeId]?.document;
    if (!node) throw new Error(`Figma node ${ref.nodeId} was not found or is inaccessible.`);
    return node;
  }

  async getImageUrls(fileKey: string, nodeIds: string[], scale = 2): Promise<Record<string, string>> {
    if (!nodeIds.length) return {};
    const data = await this.request<{ images: Record<string, string | null> }>(
      `/images/${encodeURIComponent(fileKey)}?ids=${encodeURIComponent(nodeIds.join(","))}&format=png&scale=${scale}`
    );
    return Object.fromEntries(Object.entries(data.images).filter((entry): entry is [string, string] => Boolean(entry[1])));
  }
}
