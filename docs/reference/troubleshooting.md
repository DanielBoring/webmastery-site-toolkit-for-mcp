# Troubleshooting MCP connections

An MCP request passes through the hosting/network layer, the MCP Adapter, and
then the requested WordPress ability. A client message such as “server
unavailable” alone does not identify which layer failed. Inspect the HTTP
status, content type, and response body or client logs, and compare a few safe
read-only calls. Redact application passwords, authorization headers, session
IDs, private site data, and other credentials before sharing diagnostics.

## HTTP 403 with a CAPTCHA page

If several unrelated abilities suddenly return HTTP 403 and an HTML CAPTCHA or
“verifying that you are not a robot” page, a host, CDN, firewall, or security
plugin may be challenging the request. This differs from this plugin's
[JSON ability errors](ability-contracts.md#errors-and-response-format): a 403 alone can
also be an ordinary authorization denial. Look at the actual response and ask
the provider which layer generated it. A generic unavailable message alone
is not evidence of a CAPTCHA. If the challenge blocks the request before
WordPress receives it, this plugin cannot fix that request.

[Issue #208](https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues/208)
reports a Hostinger shared-hosting case in which roughly 3,600 requests from
one IP during a media check preceded a 403 reCAPTCHA page across MCP calls.
That is one field observation, not a universal threshold or proof that every
403 has this cause.

1. Stop the burst. Record the time, affected endpoint, client egress IP, status,
   and a redacted description of the response for the host or security provider.
2. Ask the provider to identify the blocking layer and its recovery options.
   Wait for a temporary block to expire if the provider confirms that applies.
   Where the responsible layer supports it, allowlist only the trusted client IP
   with the narrowest practical scope. Do not assume a rule at one layer bypasses
   another. Hostinger documents [IP access rules](https://www.hostinger.com/support/1583474-how-to-allow-or-block-a-specific-ip-address-for-your-website-in-hostinger/)
   and [checking firewall blocks](https://www.hostinger.com/support/blocked-ip-address-hostinger-firewall/),
   but those pages do not establish that an IP Manager rule bypasses every bot
   challenge. Ask Hostinger if the specific protection can be adjusted.
3. Once access is restored, resume gradually. Pace bulk checks and use bounded,
   paginated list abilities where they answer the question instead of making a
   request for each URL. Follow continuation and permission rules in the
   [ability contracts](ability-contracts.md). There is no guaranteed safe rate;
   limits depend on the hosting layer and workload.

## Invalid or expired Adapter session

For a session-based HTTP connection, an Adapter JSON-RPC “Invalid or expired
session” response means the supplied session is not recognized or has expired;
verify the response rather than inferring this from a generic unavailable
message. The Adapter's [HTTP session guidance](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/troubleshooting/common-issues.md#http-session-errors-missing-or-invalid-mcp-session-id)
recommends re-initializing to get a new session. Reconnect or restart the MCP
client using its documented controls; a direct HTTP client must initialize a new
session and use its returned ID and negotiated protocol headers. Session-based
HTTP and sessionless protocol revisions differ; WP-CLI STDIO does not use HTTP
sessions. See the [Adapter architecture](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/architecture/overview.md#protocol-lifecycle).

After reconnecting, retry a read-only call once. The issue reports that an
immediate retry worked in one environment; it does not guarantee automatic
recovery for every client. If the error persists, inspect the client and Adapter
logs and configuration instead of repeatedly retrying.

A confirmed session rejection before execution is different from a timeout or
lost response after a write was sent. If completion is unknown, inspect the
object or batch results and reconcile the current state before deciding whether
to retry a mutation; a blind retry can duplicate or repeat work. A bulk result
can be partially successful, so inspect each item. See the
[migration guide's error handling](3.0-migration.md) for ability-level errors.
