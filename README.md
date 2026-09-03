# Elementor Figma MCP

An MCP server that reads a selected Figma frame and creates an editable Elementor page made from native containers and widgets. It includes a small WordPress plugin that exposes a permission-checked REST bridge.

## What the MVP supports

- Figma frames, groups, components and instances → Elementor flex containers
- Auto Layout direction, gap, padding and alignment
- Text → native Heading or Text Editor widgets
- Image/vector layers → rendered PNGs uploaded to the Media Library
- Solid backgrounds, text colours, borders, radius, opacity and drop shadows
- Draft page creation only
- Existing page updates with a WordPress revision created first
- Page listing and inspection
- Elementor CSS/cache regeneration
- Explicit `confirm=true` on every MCP tool that writes to WordPress

## Current limitations

Figma and Elementor use different layout engines, so conversion is not intended to be a guaranteed pixel-perfect one-click export. Auto Layout frames produce the best results. Layers using absolute positioning, masks, complex vectors, gradients, multiple effects, interactions and advanced Elementor Pro widgets may need manual refinement.

The first version produces a fluid desktop layout. Elementor tablet/mobile overrides and mapping to an existing Elementor design system are the natural next stage.

## Requirements

- Node.js 20 or later
- WordPress 6.5 or later
- Elementor with Flexbox Containers enabled
- HTTPS on WordPress
- A Figma personal access token with file read access
- A WordPress user with `edit_pages` and `upload_files`

## 1. Install the WordPress bridge

1. Upload `elementor-mcp-bridge.zip` in **Plugins → Add New → Upload Plugin**.
2. Activate **Elementor MCP Bridge**.
3. In WordPress, open your user profile and create an Application Password named `Elementor Figma MCP`.
4. Use a staging site for initial testing.

The bridge always creates new pages as drafts. It cannot publish through its create endpoint.

## 2. Configure the MCP server

```bash
npm install
cp .env.example .env
```

Fill in `.env`:

```dotenv
FIGMA_ACCESS_TOKEN=figd_your_token
WORDPRESS_URL=https://staging.example.com
WORDPRESS_USERNAME=your-wordpress-username
WORDPRESS_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
ELEMENTOR_PAGE_TEMPLATE=elementor_canvas
```

Do not commit `.env`. The server removes spaces from WordPress application passwords automatically.

Build the server:

```bash
npm run build
```

## 3. Connect an MCP client

Add this server to the MCP configuration used by your client. Replace `/absolute/path` with the real extracted project path.

```json
{
  "mcpServers": {
    "elementor-figma": {
      "command": "node",
      "args": ["/absolute/path/elementor-figma-mcp/dist/src/index.js"],
      "env": {
        "FIGMA_ACCESS_TOKEN": "figd_your_token",
        "WORDPRESS_URL": "https://staging.example.com",
        "WORDPRESS_USERNAME": "your-wordpress-username",
        "WORDPRESS_APP_PASSWORD": "your-application-password"
      }
    }
  }
}
```

Environment variables in the MCP configuration take precedence over `.env`. Restart the MCP client after changing its configuration.

## Suggested first run

Select a top-level Figma frame and copy its URL. The URL must include `node-id`.

Ask the MCP client:

> Check the Elementor connection, then inspect this Figma frame and tell me what will and will not convert. Do not change WordPress: [FIGMA URL]

After reviewing the report:

> Create this as a new Elementor draft called “Homepage test”. Import its images. I approve creating the draft.

The MCP client should call `figma_create_elementor_draft` with `confirm=true` and return the WordPress preview and Elementor edit links.

## Tools

| Tool | Changes WordPress? | Purpose |
| --- | --- | --- |
| `figma_inspect_frame` | No | Reports supported layers, warnings and conversion totals |
| `figma_convert_preview` | No | Returns the generated Elementor JSON |
| `elementor_connection_status` | No | Checks authentication and installed versions |
| `elementor_list_pages` | No | Lists Elementor pages |
| `elementor_get_page` | No | Reads one page's Elementor document |
| `figma_create_elementor_draft` | Yes, with confirmation | Creates a new draft and uploads assets |
| `figma_update_elementor_page` | Yes, with confirmation | Replaces a page layout after saving a revision |
| `elementor_regenerate_css` | Yes, with confirmation | Clears Elementor CSS/data cache |

## Figma preparation guidance

- Use Auto Layout for every section, row and card.
- Give text layers meaningful names containing `Heading`, `Title` or `H1`–`H6` when they should become headings.
- Keep decorative vectors grouped when they should render as one image.
- Use a single selected desktop frame for the MVP.
- Test on staging and review desktop, tablet and mobile before publishing.

## Development

```bash
npm run check
```

The TypeScript compiler and converter tests run without access to Figma or WordPress. Live API testing requires credentials in `.env`.

## Product roadmap

The developer-oriented MCP workflow is the current MVP. The planned user-facing product, onboarding flow, import-plan review, style-token mapping, and safety requirements are documented in [Product Discovery](docs/product-discovery.md).

## Security choices

- Credentials are read only from environment variables or local `.env`.
- Every REST route uses WordPress capability checks.
- New pages are forced to `draft`, regardless of the submitted status.
- Existing page updates require `edit_post` for the exact page.
- A WordPress revision is saved before replacing Elementor data.
- Media import requires HTTPS and WordPress's safe download handling.
- The bridge limits Elementor JSON payloads to 10 MB.
