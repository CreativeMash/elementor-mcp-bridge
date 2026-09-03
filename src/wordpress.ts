import type { Config } from "./config.js";
import type { ElementorElement, ElementorGlobalContext } from "./types.js";

export class WordPressClient {
  private readonly api: string;
  private readonly authorization: string;

  constructor(private readonly config: Config) {
    this.api = `${config.wordpressUrl}/wp-json/elementor-mcp/v1`;
    this.authorization = `Basic ${Buffer.from(`${config.wordpressUsername}:${config.wordpressAppPassword}`).toString("base64")}`;
  }

  private async request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(`${this.api}${path}`, {
      ...init,
      headers: { Authorization: this.authorization, "Content-Type": "application/json", ...(init.headers ?? {}) }
    });
    if (!response.ok) throw new Error(`WordPress bridge error ${response.status}: ${await response.text()}`);
    return response.json() as Promise<T>;
  }

  health() { return this.request<Record<string, unknown>>("/health"); }
  listPages(search = "") { return this.request<unknown[]>(`/pages?search=${encodeURIComponent(search)}`); }
  getPage(pageId: number) { return this.request<Record<string, unknown>>(`/pages/${pageId}`); }
  getGlobals() { return this.request<ElementorGlobalContext>("/globals"); }
  createPage(title: string, content: ElementorElement[], status = "draft") {
    return this.request<Record<string, unknown>>("/pages", { method: "POST", body: JSON.stringify({ title, content, status, template: this.config.pageTemplate }) });
  }
  updatePage(pageId: number, content: ElementorElement[], title?: string) {
    return this.request<Record<string, unknown>>(`/pages/${pageId}`, { method: "PUT", body: JSON.stringify({ content, title }) });
  }
  importMedia(url: string, alt = "") {
    return this.request<{ id: number; url: string }>("/media/import", { method: "POST", body: JSON.stringify({ url, alt }) });
  }
  regenerateCss() { return this.request<Record<string, unknown>>("/maintenance/regenerate-css", { method: "POST" }); }
}

export async function importElementorImages(elements: ElementorElement[], wordpress: WordPressClient): Promise<void> {
  const visit = async (element: ElementorElement): Promise<void> => {
    if (element.widgetType === "image") {
      const image = element.settings.image as { url?: string; id?: number | string } | undefined;
      if (image?.url && !image.id) {
        const imported = await wordpress.importMedia(image.url, "Imported from Figma");
        element.settings.image = { id: imported.id, url: imported.url };
      }
    }
    for (const child of element.elements) await visit(child);
  };
  for (const element of elements) await visit(element);
}
