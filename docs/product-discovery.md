# Product Discovery: User-Facing Figma to Elementor Import

## Purpose

Move from the current developer-operated MCP workflow to a WordPress product that lets a non-technical user import a Figma frame into an editable Elementor draft with clear review and safe defaults.

## Product Principle

Do not make users configure a local Node process, copy a Figma personal access token, discover their WordPress URL, or create an application password. The WordPress plugin should guide setup and make import decisions visible before any site content changes.

## Recommended User Journey

1. Install and activate the WordPress plugin.
2. Open an onboarding wizard that verifies WordPress, Elementor, and PHP compatibility.
3. Connect Figma through an authorization flow.
4. Paste a Figma frame URL, including its node ID.
5. Review an import plan before writing anything.
6. Choose how styles and components should be handled.
7. Create an Elementor draft and inspect it in the editor.
8. Publish only through the normal WordPress or Elementor publishing flow.

## Import Plan

The same import-plan engine must power both the MCP tools and the future WordPress UI. It should report:

- The source Figma file, frame, and selected mode.
- Containers, text, images, vectors, and components that can be converted.
- Existing Elementor global styles that match Figma tokens.
- New colors, typography, sizes, shadows, radii, and CSS classes proposed for import.
- Component mappings that are available, ambiguous, or unavailable.
- Assets to be copied into the Media Library.
- Unsupported properties, layout fallbacks, and expected manual refinements.
- A diff showing whether a proposed global-style import adds, updates, or conflicts with existing values.

The initial Figma fixture is the Flow Library frame:

`https://www.figma.com/design/TOR2aJXiYJMuYjITmsNs1p/Flow-Library?node-id=845-244`

## Style and Component Strategy

Figma variables, styles, aliases, and modes should first be normalized to an import-neutral token model. The importer then maps each token to the best Elementor target:

- Colors and typography map to the active Elementor Kit when supported.
- Color, font, and size variables use Elementor's variables support where it is available and stable.
- Reusable styling maps to global classes or scoped generated CSS.
- Components map to named Elementor widget recipes only after the user confirms the mapping.
- Unmatched properties remain editable as scoped CSS instead of being silently discarded.

No global style should be replaced by default. The review screen should present merge, add-as-new, skip, and replace choices. Replace must require an explicit confirmation and create a recoverable revision or snapshot.

## Authentication and Setup

### Figma

The production product should use a Figma authorization flow rather than a personal access token. It must verify that the user can read the selected file and explain access errors in plain language. Tokens should be stored only when necessary, encrypted at rest, scoped to the minimum permissions, and revocable from the plugin settings.

### WordPress

The plugin already runs inside the target WordPress site, so users should not enter a site URL, username, or application password. The current signed-in user and WordPress capabilities should authorize imports. The plugin should verify Elementor and Elementor Pro compatibility during onboarding.

Local-development sites must present a certificate trusted by the conversion runtime. The product must not require users to disable TLS verification to connect to a local WordPress instance; onboarding should detect an untrusted certificate and explain how to trust or replace it.

### Hosted Conversion Service

The current TypeScript converter requires Node.js and should not be a requirement for WordPress users. The preferred production model is a hosted conversion service paired with the WordPress plugin. The plugin and service need a short-lived, revocable authorization handshake rather than a user-managed WordPress application password.

Before committing to this model, validate hosting cost, data retention, Figma OAuth scopes, WordPress-to-service authentication, and privacy requirements. A WordPress-only PHP converter remains an alternative if a hosted service is not acceptable.

## Safety Requirements

- Analyze and preview before writing to WordPress.
- Create new imports as drafts only.
- Keep publishing outside the importer.
- Require explicit confirmation for existing-page replacement and global-style writes.
- Show exactly which assets, styles, and components will be imported.
- Save a revision or snapshot before replacing page or active-Kit data.
- Provide disconnect and token-revocation controls.
- Restrict remote asset downloads to validated Figma delivery URLs and validate file type and size.

## Version One Scope

- Guided onboarding and compatibility check.
- Figma connection and selected-frame URL input.
- Read-only import-plan preview.
- Desktop Auto Layout conversion to Elementor containers and basic widgets.
- Asset import into the Media Library.
- Draft-only page creation.
- Reviewable Figma color and typography mapping to Elementor global styles.

## Deferred Scope

- Pixel-perfect automatic correction.
- Complex vector editing, masks, blend modes, and prototyping interactions.
- Automatic replacement of existing site-wide styles.
- Full responsive inference without desktop, tablet, and mobile Figma variants.
- Arbitrary component inference without user-defined mappings.
- Automatic page publishing.

## Decisions to Validate Before Implementation

1. Hosted service versus a WordPress-only converter.
2. Figma OAuth application ownership, scopes, and token retention.
3. The WordPress authorization protocol used by the hosted service.
4. Which Elementor 4 variables and global-class APIs are stable enough to write.
5. How active-Kit revisions or snapshots are created and restored.
6. The first component recipes to support, such as headings, buttons, cards, and images.
7. The preview format: structured import plan first, visual comparison later.

## First Engineering Milestone

Implement `figma_preview_style_guide` as a read-only MCP tool backed by the import-plan engine. It should fetch the Figma frame's available styles and variables, read the current Elementor global context, and return proposed mappings, conflicts, and unsupported items. It must not write to WordPress.
