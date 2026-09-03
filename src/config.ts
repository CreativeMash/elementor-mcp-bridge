import "dotenv/config";

export interface Config {
  figmaToken: string;
  wordpressUrl: string;
  wordpressUsername: string;
  wordpressAppPassword: string;
  pageTemplate: string;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  return {
    figmaToken: env.FIGMA_ACCESS_TOKEN ?? "",
    wordpressUrl: (env.WORDPRESS_URL ?? "").replace(/\/$/, ""),
    wordpressUsername: env.WORDPRESS_USERNAME ?? "",
    wordpressAppPassword: (env.WORDPRESS_APP_PASSWORD ?? "").replace(/\s/g, ""),
    pageTemplate: env.ELEMENTOR_PAGE_TEMPLATE ?? "elementor_canvas"
  };
}

export function requireFigma(config: Config): void {
  if (!config.figmaToken) throw new Error("FIGMA_ACCESS_TOKEN is not configured.");
}

export function requireWordPress(config: Config): void {
  if (!config.wordpressUrl || !config.wordpressUsername || !config.wordpressAppPassword) {
    throw new Error("WORDPRESS_URL, WORDPRESS_USERNAME and WORDPRESS_APP_PASSWORD must be configured.");
  }
}
