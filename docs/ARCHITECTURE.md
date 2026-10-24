# Architecture

How a request travels through somcp, and where each part lives. Read this before
changing anything outside `tools/`.

The module is one PrestaShop module, no external service and no dependency
beyond PrestaShop itself. It is two servers in one: an **OAuth 2.1
authorization server** that issues tokens, and an **MCP server** that accepts
them.

---

## The addresses

Everything is served by the module's front controllers, under the shop's own
domain. On a multistore install each shop has its own set, built from its own
domain.

| Address | Controller | What it is |
|---|---|---|
| `/module/somcp/mcp` | `controllers/front/mcp.php` | The MCP endpoint, the address you give to a client. |
| `/module/somcp/prm` | `controllers/front/prm.php` | Protected resource metadata (RFC 9728). |
| `/.well-known/oauth-authorization-server/module/somcp` | `controllers/front/wellknown.php` | Authorization server metadata (RFC 8414). |
| `/module/somcp/authorize` | `controllers/front/authorize.php` | Authorization endpoint. |
| `/module/somcp/oauth?a=token`, `a=register`, `a=revoke` | `controllers/front/oauth.php` | Token, dynamic registration, revocation. |

RFC 8414 anchors the authorization server document at the **domain root**. somcp
puts it there through the `moduleRoutes` hook, which needs friendly URLs, and a
shop installed in a subfolder cannot serve it at all. The diagnostic in the
settings calls each address for real and says which one fails.

These addresses opt out of maintenance mode: maintenance hides the storefront
from visitors, and these belong to the merchant.

---

## Connecting: discovery and OAuth

```
client                      shop (somcp)                        back office
  |  POST /mcp (no token)  -->|
  |<-- 401 WWW-Authenticate: resource_metadata=".../prm"
  |  GET /prm  ------------->|  -> authorization server
  |  GET /.well-known/...  ->|  -> endpoints, PKCE S256, DCR / CIMD
  |  register (DCR) or present a client id metadata document (CIMD)
  |  GET /authorize  ------->|  request parked in the database
  |                          |-------------------------------->  consent screen
  |                          |   employee signs in, reads the scopes, approves
  |<-- redirect with code (valid 60 s)
  |  POST token (code + PKCE verifier) --> access + refresh token
  |  POST /mcp  Authorization: Bearer ...
```

The pieces, in `classes/`:

- `SomcpMetadata` builds both discovery documents.
- `SomcpOAuthClient` identifies the client, by dynamic registration or by a
  client ID metadata document, and validates its redirect URIs: `https`, or
  `http` on loopback only, optionally restricted to a list of hosts the
  merchant sets.
- `SomcpOAuthServer` runs the authorization request and the consent.
- `SomcpOAuthToken` issues codes and tokens. Everything is stored **hashed**.
  Tokens are bound to the resource they were issued for (RFC 8707), so a token
  for one shop is refused by another. Refresh tokens rotate, and presenting an
  already rotated one revokes the whole grant: that is what a stolen token
  looks like.
- `SomcpRandom` mints every secret, and `SomcpScope` defines the core scopes:
  `mcp.access`, `mcp.orders.read`, `mcp.orders.write`, `mcp.catalog.read`,
  `mcp.catalog.write`.

Reaching the consent screen from a front controller is its own problem.
PrestaShop 8 accepts a legacy back office address with a computed token.
PrestaShop 9 demands a Symfony CSRF token that a front controller cannot
produce, so somcp enters through a Symfony route of its own
(`src/Controller/ConsentEntryController.php`, route `api_somcp_consent`), which
PrestaShop authenticates natively and which then forwards with both tokens.
`SomcpBackOffice` builds these addresses.

`config/services.yml` is compiled on PrestaShop 8 too, even though the route is
only used on 9. Those controllers therefore take nothing in their constructor
and look the PrestaShop 9 token up at run time: a constructor asking for a class
that only exists on 9 stops Symfony from building its container on 8, and the
whole back office answers 500. CI compiles the container on both.

---

## One MCP request

`controllers/front/mcp.php`, in order:

1. **Origin check.** A browser request from another origin is refused, which is
   what blocks DNS rebinding.
2. **Token.** Missing or invalid: `401` with the `WWW-Authenticate` challenge
   that starts discovery. The employee behind the token is checked on every
   call: an account disabled this morning stops working this morning.
3. **Protocol era.** `SomcpProtocol` works out which revision the client
   speaks. The current one, `2026-07-28`, is stateless and starts with
   `server/discover`. The older ones (`2025-11-25`, `2025-06-18`,
   `2025-03-26`) start with `initialize`. Both are served from the same
   endpoint.
4. **Dispatch.** `ping`, `server/discover` / `initialize`, `tools/list`,
   `tools/call`, `prompts/list`, `prompts/get`.

For `tools/call`:

- the token must hold the tool's scopes, otherwise `403 insufficient_scope`
  with the scopes missing, so the client can ask the merchant for more;
- the employee must hold the tool's back office right (`SomcpPermission`), and
  on multistore be attached to the shop (`Employee::hasAuthOnShop()`);
- the tool must not be switched off by the merchant;
- the handler runs with a context that carries the shop, the employee and the
  language. The shop always comes from the token, never from the arguments.

Lists (`tools/list`, `prompts/list`) are filtered by the same rules: a token
only sees what it could actually call. Answers in the current revision carry a
cache lifetime (a setting, five minutes by default) and `cacheScope: private`.

---

## Tools and prompts

- `SomcpTool` is one tool: name, schema, scopes, back office right, handler.
  `SomcpTool::acts($action, $controller)` derives both the permission and the
  hints given to the assistant (`readOnlyHint`, `destructiveHint`,
  `idempotentHint`) from one PrestaShop right.
- `SomcpToolRegistry` loads the module's own tools, one file per tool in
  `tools/`, then fires `actionSomcpRegisterTools` module by module so each name
  can be prefixed with the module that brought it. It repairs or refuses
  schemas so that one broken module cannot empty `tools/list` for everyone.
- `SomcpPrompt` and `SomcpPromptRegistry` do the same for prompts, from
  `prompts/<name>/prompt.md` + `prompt.json`, and from
  `actionSomcpRegisterPrompts`.
- `SomcpContract` is the translation layer for other modules: they return
  arrays from the hooks and from their handlers, and it turns those into tools,
  prompts and results. See [EXTENDING.md](EXTENDING.md).

The tools themselves, and the conventions every tool follows, are in
[TOOLS.md](TOOLS.md).

---

## Questions in the middle of a call (MRTR)

A tool that needs something from the merchant does not fail and does not guess.
In the `2026-07-28` revision it answers `input_required` with a question and an
opaque `requestState`; the client asks, then replays the same call with the
answer.

Nothing is stored between the two halves. The state travels through the client,
so the specification says to treat it as attacker-controlled input.
`SomcpRequestState` seals it with an HMAC and checks four things on the way
back: the employee, the shop, a fifteen-minute expiry, and a fingerprint of the
original call (tool name and arguments). `SomcpElicitation` builds the
questions, and never sends a kind of question the client did not declare it
can answer.

---

## Files from the merchant

`SomcpUpload` opens a deposit: a page of the merchant's own back office
(`AdminSomcpUploadController`) that only the employee the call was made for can
use. Files are checked against their actual bytes, not only their extension,
and stored under `var/somcp/uploads/`, outside the module folder. A reference
works once.

The address reaches the merchant one of two ways: as a URL question for a
client that declared it can open one, or as text the assistant shows. Because a
client can declare the first and not do it, a call that comes back to a deposit
that is still empty gets the address again as text.

---

## Data

Nine tables, created by `sql/install.sql` and dropped by `sql/uninstall.sql`:
OAuth clients, pending requests, codes, access tokens, refresh tokens, consents,
the security log, deposits and deposited files.

The security log (`SomcpLogger`, table `somcp_log`) records connections,
refusals, tool calls and configuration problems. It never contains a token, a
code or a secret.

Uninstalling removes the tables, the hidden back office tabs, the settings, the
deposit folder, and the two hooks somcp declared, unless another module is
still attached to one of them.

---

## Settings

Stored in `ps_configuration`, per shop with the usual multistore inheritance:
module on/off, OAuth on/off, access and refresh token lifetimes (one hour and
thirty days by default), refresh rotation, dynamic registration, client ID
metadata documents, allowed redirect hosts, the profile allowed to grant
access, the log, the tool list cache lifetime, and the tools and prompts the
merchant switched off.

---

## Translations

Four languages: `en-US`, `fr-FR`, `es-ES`, `de-DE`, in `translations/`, domain
`Modules.Somcp.Admin`. The catalogues are generated from one table in
[`dev/translations.py`](../dev/translations.py), so a string cannot exist in one
language and be missing from another. CI checks that every string the merchant
can see is in all four.
