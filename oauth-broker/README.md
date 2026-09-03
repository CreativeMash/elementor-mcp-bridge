# Figma OAuth Broker

This is the only non-WordPress part of the product. It is a small PHP service
whose sole job is to protect the Figma OAuth client secret and exchange a user
authorization code for a short-lived, one-time handoff to their WordPress site.
It does not fetch Figma files, generate Elementor data, or retain Figma tokens
after the handoff is consumed.

## Before deployment

1. Create and configure a Figma OAuth app.
2. Set its redirect URI to `https://your-broker.example/oauth/callback`.
3. Request only `file_content:read` for the first release. Add
   `file_variables:read` only for Enterprise customers who need variables.
4. Serve this application over HTTPS, with `public/` as the web root. On the
   HostGator account used for development, upload the package to
   `/home4/matumatthew/figma-auth-broker` and set the subdomain's document root
   to `/home4/matumatthew/figma-auth-broker/public`.

The included `public/.htaccess` must be deployed. It routes the OAuth callback
and handoff endpoints through `index.php` and disables public directory listings.
5. Put the environment values in the host's secret manager, not in this
   repository. On simple shared hosting without one, copy `config.php.example`
   to `config.php` beside `public/`; it remains outside the document root.
   Generate `BROKER_RECORD_KEY` with a secure password generator.
6. Set `BROKER_STATE_DIR` outside `public/`, owned by the PHP process with
   restrictive permissions.
7. Add each permitted WordPress callback exactly to
   `WORDPRESS_CALLBACK_ALLOWLIST`:
   `https://site.example/wp-admin/admin-post.php?action=elementor_mcp_oauth_callback`.
8. Add the broker URL to the WordPress deployment configuration:
   `define( 'ELEMENTOR_MCP_FIGMA_OAUTH_BROKER_URL', 'https://your-broker.example' );`

The local site must not be configured until the broker has a public HTTPS URL,
the Figma app is registered, and its callback URL has been added to the broker
allowlist. The Figma browser redirect can return to a local site, but Figma's
registered callback is always the broker's HTTPS URL.

## Security properties

- PKCE (`S256`) is used for every Figma authorization request.
- Figma codes are exchanged server-to-server using HTTP Basic authentication
  over verified TLS, within Figma's 30-second limit.
- WordPress callback URLs are exact-match allowlisted; the broker is not an
  open redirector.
- OAuth state lasts 10 minutes. Token handoffs last 60 seconds and are consumed
  exactly once.
- Temporary OAuth records and handoffs are AES-256-GCM encrypted on disk.
- The client secret, OAuth code, access token, and refresh token are never put
  in browser URLs or written to application logs.
- There is deliberately no endpoint for Figma file conversion or asset access.

## Operations still required

Before a public release, use a TTL-backed record store such as Redis rather than
the encrypted file-store reference, then add reverse-proxy rate limiting,
generic-error monitoring only, a secret rotation procedure, and an independently
reviewed deployment configuration.
