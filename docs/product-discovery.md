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

### WordPress-Native Conversion

**Decision: the production converter runs inside the WordPress plugin.** Users must not install Node.js, run a companion service, configure `.env`, provide a WordPress URL, or create an application password.

The current TypeScript MCP server is a developer prototype and a behavioral reference while the converter is ported into the plugin. The production plugin will use WordPress's HTTP API for Figma requests, store the minimum required Figma authorization data securely, and perform analysis, preview, and Elementor JSON generation in PHP.

Figma's OAuth code exchange requires a confidential client secret, which must never be bundled into a distributed WordPress plugin. A narrowly scoped, security-reviewed OAuth broker may therefore be used only to hold that secret, complete the Figma exchange, and hand the result back to the plugin. It must not perform conversion, retain Figma files, or become a dependency for Elementor generation. The plugin's connection client uses a one-time state, a 10-minute expiry, HTTPS-only broker URLs, server-to-server exchange, and encrypted per-user token storage. Refresh, revocation, broker authentication, and key-rotation behavior must be reviewed before release.

The broker contract must use Figma's authorization-code flow with PKCE and the minimum `file_content:read` scope. It must allow only registered WordPress callback URLs, return a signed, one-time handoff valid for no more than 60 seconds, and bind that handoff to the plugin-issued state and exact callback URL. It must never log, return, or persist a user's authorization data outside the exchange required to deliver it to their site.

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

1. Figma OAuth application ownership, scopes, token retention, and revocation.
2. WordPress HTTP API limits, retries, and timeouts for Figma file reads.
3. Which Elementor 4 variables and global-class APIs are stable enough to write.
4. How active-Kit revisions or snapshots are created and restored.
5. The first component recipes to support, such as headings, buttons, cards, and images.
6. The preview format: structured import plan first, visual comparison later.

## First Engineering Milestone

Port the import-plan engine into the WordPress plugin, beginning with a read-only Figma style-guide preview. It should fetch the Figma frame's available styles and variables through WordPress, read the current Elementor global context, and return proposed mappings, conflicts, and unsupported items. It must not write to WordPress.
