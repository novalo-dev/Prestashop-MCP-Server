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
settings calls each address for real and says which one fails. It also opens the
authorization endpoint with no request and checks that the module's page
answers: a module that redirects visitors without an account would otherwise
leave the employee unable to approve anything. Opened that way by anyone else,
the endpoint is the shop's "page not found"; the diagnostic carries a pass
signed with `SOMCP_PROBE_KEY`, valid one minute (`SomcpGuard::probe()`).

These addresses opt out of maintenance mode: maintenance hides the storefront
from visitors, and these belong to the merchant.

---

## Connecting: discovery and OAuth

```
client                      shop (somcp)
  |  POST /mcp (no token)  -->|
  |<-- 401 WWW-Authenticate: resource_metadata=".../prm"
  |  GET /prm  ------------->|  -> authorization server
  |  GET /.well-known/...  ->|  -> endpoints, PKCE S256, DCR / CIMD
  |  register (DCR) or present a client id metadata document (CIMD)
  |  GET /authorize  ------->|  404 unless the consent page is on; return
  |                          |  host checked against the allowed list
  |                          |  request parked in the database
  |                          |  same page: employee email and password,
  |                          |  then the code e-mailed to them,
  |                          |  then the scopes and the return host, approve
  |<-- redirect with code (valid 60 s)
  |  POST token (code + PKCE verifier) --> access + refresh token
  |  POST /mcp  Authorization: Bearer ...
```

The pieces, in `classes/`:

- `SomcpMetadata` builds both discovery documents.
- `SomcpOAuthClient` identifies the client, by dynamic registration or by a
  client ID metadata document, and validates its redirect URIs: `https`, or
  `http` on loopback only, and a public host only when it is on the list the
  merchant sets (the known clients by default). The host is checked again on
  every authorization request, and one off the list gets the shop's "page not
  found".
- `SomcpOAuthServer` runs the authorization request and the consent.
- `SomcpOAuthToken` issues codes and tokens. Everything is stored **hashed**.
  Tokens are bound to the resource they were issued for (RFC 8707), so a token
  for one shop is refused by another. Refresh tokens rotate, and presenting an
  already rotated one revokes the whole grant: that is what a stolen token
  looks like.
- `SomcpRandom` mints every secret, and `SomcpScope` defines the core scopes:
  `mcp.access`, `mcp.orders.read`, `mcp.orders.write`, `mcp.catalog.read`,
  `mcp.catalog.write`, `mcp.stock.read`, `mcp.stock.write`, `mcp.customers.read`,
  `mcp.customers.write`, `mcp.seo.read`, `mcp.seo.write`, `mcp.stats.read`,
  `mcp.content.read`, `mcp.modules.read`, `mcp.modules.write`, `mcp.modules.upload`,
  `mcp.appearance.read`, `mcp.appearance.write`, `mcp.system.read`, `mcp.system.write`,
  `mcp.sales.read`, `mcp.sales.write`.

The employee signs in on the authorization page itself, a bare page of the
shop front (`controllers/front/authorize.php`, `views/templates/front/authorize.tpl`)
with no theme script on it. The page checks the email and password with
PrestaShop's own `Employee::getByEmail()`, then the module's required profile
and the employee's shop associations, and records the employee on the pending
request. A 6-digit code is then e-mailed to that employee (`SomcpNotify::code()`,
stored as a hash on the request row, single use, ten minutes, five wrong entries,
three codes at most with 30 and 45 seconds between them, after which the request
is closed); only once it is typed can that account give the consent, with its
own rights. The whole page is the shop's 404 unless `SOMCP_CONSENT_PAGE` is on,
which it is not by default. Every
refusal reads the same on screen and the reason goes to the module's log. No
page or redirect of this flow names the administration folder.

Each granted consent sends an e-mail (`SomcpNotify`, templates in `mails/<iso>/`)
to the employee who approved it and to the shop's address, with the super
administrators in blind copy - `Mail::send()` has no Cc. It is written in the
shop's default language, and `Mail::send()` falls back on the English template
for a language the module does not have.

`SomcpGuard` puts limits on the public addresses: an ALTCHA proof of work on the
sign-in form (the widget is shipped in `views/js/altcha/`, the check made with
the `altcha-org/altcha` library) whose work grows with each failed sign-in from
the address, a block of fifteen minutes after five failed sign-ins from one
address or ten on one account, and quotas on new authorization requests and on
dynamic registrations. The address is not `Tools::getRemoteAddr()`, which takes
the first, visitor-written entry of `X-Forwarded-For`: behind a proxy on the
server's network or Cloudflare, the chain is read from the right and the first
address that is not such a proxy is counted, and an IPv6 address counts as its
/64. The counters use `nikolaposa/rate-limit` with a storage
in the shop's database (`src/Security/DbRateLimiter.php`, table
`somcp_rate_limit`), since a shared hosting offers neither Redis nor APCu.

The file deposit page is still a back office page. PrestaShop 8 accepts a legacy
back office address with a computed token. PrestaShop 9 demands a Symfony CSRF
token that a front controller cannot produce, so somcp enters through a Symfony
route of its own (`src/Controller/UploadEntryController.php`, route
`api_somcp_upload`), which PrestaShop authenticates natively and which then
forwards with both tokens. `SomcpBackOffice` builds this address.

`config/services.yml` is compiled on PrestaShop 8 too, even though the route is
only used on 9. The controller therefore takes nothing in its constructor and
looks the PrestaShop 9 token up at run time: a constructor asking for a class
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
- the tool must not be switched off by the merchant, neither on its own nor
  with its group: a scope switched off holds back every tool needing it;
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
  `actionSomcpRegisterPrompts`. A prompt also names its icon, drawn by the
  built-in chat from `PROMPT_ICONS` in `views/js/assistant.js`, and its back
  office page comes from the permission it requires. The chat lists the prompts
  the employee may use (`SomcpAssistantChat::prompts()`), suggests those of the
  page open first, and sends a picked prompt's guide in the same message as the
  employee's words, so only the conversations that use a guide pay for it.
- The built-in assistant does not send every tool to the model: with all of
  them described, a plain hello cost tens of thousands of tokens. Each step
  offers `somcp_ping`, the chat's own tools where they apply, the finder
  (`somcp_find_tools`) and the shop tools this conversation loaded
  (`SomcpAssistantChat::tools()`). The finder searches the registry by
  keywords, one line per tool, and loads the ones the model picks; they are
  kept in the `tools` column of `somcp_assistant_thread` (at most 15, the
  oldest let go), not in the messages, because older messages are summarized.
  The read tools of the page the chat is open on (`SomcpToolRegistry::forPage()`:
  every permission on that controller, `readOnlyHint` true) are offered from
  the first message beside them, without being stored or counted in the 15;
  the writes of that page stay behind the finder, and a delegated assistant
  gets none. A prompt picked from the + menu loads the tools its guide names.
  Search, load and call all go through the same
  `SomcpToolRegistry::isEnabled()` and `isAvailableTo()` as the Tools and
  prompts tab, so the finder never offers a tool the employee could not call,
  and a write still asks for approval. The keyword search itself is
  `SomcpToolRegistry::search()`, shared with compact mode below.
- Compact mode (`SomcpCompact`, setting `SOMCP_TOOLS_COMPACT`) does the same
  for MCP clients without keeping anything between calls, since the current
  revision has no session. `tools/list` carries `somcp_ping` and three tools:
  `somcp_search_tools` returns the tools the token may use with their schemas,
  and `somcp_run_read_tool` / `somcp_run_write_tool` name the tool to run. The
  endpoint unwraps a runner's call into the call of the tool it names before
  any check, so that tool meets the switch, scope and permission checks of a
  direct call, and a question it asks is sealed under its own name. Each
  runner refuses the tools of the other kind (`SomcpToolRegistry::writes()`),
  because a client reads the runner's flags to decide whether to ask the
  merchant first. `prompts/get` puts a block in front of a guide saying which
  runner each of its tools goes through. The four tools are answered whatever
  the mode, so a client holding the other list until it expires still works.
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

A private file goes out the same way, through `SomcpDownload` and
`AdminSomcpDownloadController`, the outgoing counterpart of the deposit page. A
tool that produces a private file never returns the bytes: it hands out a
signed link to that back office page. The link names the file by its kind and
its identifier, never by a path, and carries only `kind`, `id`, `e` (expiry)
and `s` (signature); the employee and shop it was signed for are not in the
address but folded into the signature, which reuses the key that signs the chat
file links. The page requires the back office session of that same employee, so
a leaked link is useless, checks the **view** right of the screen the document
belongs to (`AdminOrders`), and builds the file on demand with the core `PDF`
class, so nothing is written to disk. Links expire after fifteen minutes, like a
deposit.

Like the deposit page's, the download link is the one place a tool answer holds
the back office address, administration folder included: it is opened in the
merchant's own browser, where the folder is already known. `somcp_get_order_document`
is the tool that hands it out; no other answer, front page or redirect names the
folder.

---

## Data

Seventeen tables, created by `sql/install.sql` and dropped by
`sql/uninstall.sql`: OAuth clients, pending requests, codes, access tokens,
refresh tokens, consents, the security log, deposits and deposited files, the
built-in assistant's own (assistants, relays and their jobs, usage, threads,
messages, runs) and the counters of the public addresses.

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
metadata documents, allowed redirect hosts (the known clients by default; an
empty list refuses every public host), the profile allowed to grant
access, the log, the tool list cache lifetime, the tools and prompts the
merchant switched off, and the scopes switched off as whole groups - each of
these three lists twice, once for the assistants connected over MCP and once
for the built-in assistant.

---

## Translations

Four languages: `en-US`, `fr-FR`, `es-ES`, `de-DE`, in `translations/`, domain
`Modules.Somcp.Admin`. The catalogues are generated from one table in
[`dev/translations.py`](../dev/translations.py), so a string cannot exist in one
language and be missing from another. CI checks that every string the merchant
can see is in all four.
