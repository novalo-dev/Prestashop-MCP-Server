# Tools

This file describes the module's MCP tools: what they do, what they require and
what they return. It is written by hand and it is where a tool is decided - a
tool is described here before it is written in `tools/`.

Sections follow **what the data is**, not where PrestaShop put it in its menu. An
order uses a status, it does not contain one, so the two are not in the same
section.

---

## Shared conventions

These hold for every tool. Do not repeat them in a tool's entry.

**Shop.** Everything is bound to the shop the token belongs to. No tool takes a
shop identifier: the boundary comes from the token, never from what the client
asks for.

**Language.** One rule for every tool, through one shared function.
- Reading: a `language` parameter takes the ISO code of one of the shop's active
  languages, compared without case. Left out, the shop's default language
  (`PS_LANG_DEFAULT` of the token's shop), never the language of the client's
  browser or of the employee. Any other value is refused before anything is
  read, by `SomcpTool::readLanguage()` and `SomcpTool::languageRefused()`, with
  the text that names the shop's languages. Every answer holding a translated
  text carries `language`, the ISO code it was read in. The SEO audits, which
  read every language when `language` is left out, use the same refusal with
  its other ending. A tool that lists every problem of a call before answering
  puts the same refusal among them as one line, `SomcpTool::languageProblem()`
  (`language: not one of the shop's languages, which are <codes> - leave
  language out for the shop default`, or `for all of them` in the SEO audits),
  and keeps `languageRefused()` for a shop whose default language cannot be
  read.
- Writing: `languages` lists the ISO codes being filled, among the shop's
  active languages, all of them by default; each text is an object keyed by
  those codes and given in each of them, a plain string being accepted when one
  language is filled (`SomcpToolCreateProduct::languages()` and `texts()`, for
  every write tool). A name given back in a write answer is in the shop's
  default language, with `language` beside it.
- The codes, with the default and the enabled languages, are listed by
  `somcp_list_languages`.

**Dates.** ISO 8601 in and out. A date and time a tool returns carries the
shop's offset (`2026-11-05T08:00:00+01:00`) and is `null` when the field is
empty, never `0000-00-00`; every tool gives them through `SomcpTool::isoDate()`.
A day taken as a filter is `YYYY-MM-DD`, read by `SomcpTool::day()`. Relative
periods are worked out by the assistant, which already knows the shop's time
zone.

**Amounts.** Always with their currency code, and always with tax or without
said in the field name. Never a bare `total`. A price ends in `_tax_excl` or
`_tax_incl`; the total paid of an order or an invoice is `total_paid_tax_excl` /
`total_paid_tax_incl`.

**Files out.** No tool gives the address of a file of the shop. A tool that
finds one - a product photo, a logo, a texture, an order status icon, an
attachment, the file of a virtual product, an invoice - answers with a
`download_ref` (`SomcpDownload::offer()`, `{"download_ref": "..."}`, `null`
when there is no file), and `somcp_download_file` turns it into the file:
inside its answer (an image as an image), at a signed address the assistant
fetches itself, and on a back office page for the merchant. Who may have a
file is who may run the tool that reads that kind of file
(`SomcpDownload::KINDS`), with its scopes and back office rights. A new kind of
file is one entry of `KINDS` and of `SomcpDownload::stored()`, never a link
built in the tool; a file the shop builds for one download (the
`translation_export` pack of `somcp_export_translations`) is kept by
`SomcpDownload::keepExport()` in `var/somcp/exports/` for 15 minutes. The
Tools and prompts check refuses a file of `tools/` that calls
`getMediaLink()`, `getImageLink()` or the other link builders of files.

**Files in.** A tool that takes a file declares the field in `files:` and reads
it with `SomcpUpload::source()`: a public `url` the shop downloads, or the
`upload_ref` of a file on the shop. `somcp_upload_file` turns any other way an
assistant has of handing a file over into an `upload_ref`: the bytes as a data
URI, a file its client passes from the conversation (ChatGPT's file
parameters), a signed address it posts to, the deposit page of the back office.
Each way is one entry of `SomcpUpload::ENTRIES`, and every one ends in the same
check, `admit()`: a client with a way of its own is one more entry, never a tool
of its own.

**Files given back.** What a get returns for a file is given back to the
matching update under the same name and, when it is unchanged, changes nothing:
its `download_ref` (bare or `{"download_ref": ...}`) naming the stored file, or
`null` when there is none, is neither downloaded, written nor listed in
`changes` (`SomcpUpload::same()`, which reads the kind and the identifier the
reference names). Removing a file stays the `remove_*` field's job; an address
or an `upload_ref` is a new file, and a `download_ref` of another file is
refused. So far the brand and supplier logos (`somcp_update_manufacturer`,
`somcp_update_supplier`), the category cover image and menu thumbnails
(`somcp_update_category`, where the thumbnails, replaced as one list, are left
alone when the whole list is the one read, in its order), the product images
(`somcp_update_product`, where the `download_ref` read beside an `id_image` is
ignored), the file of a virtual product (`virtual_file.file` of
`somcp_update_product`), the icon of an order status
(`somcp_update_order_status`) and the attribute value texture
(`somcp_update_attribute_value`, where a `null` given back for a value that has
a texture is refused: `remove_texture` removes it). A list of objects read back
is taken where the write takes their identifiers, and the schema says so: the
`groups` of `somcp_get_category` go back to the category writes as they are.

**Paging.** `limit` defaults to 20 and caps at 100, `offset` for the rest. Every
list returns `total_count`, so an assistant knows there is more instead of
concluding from one page.

**Errors.** A call refused before anything is read or written answers one
error text, built by `SomcpTool::refused()`: a first line saying what did not
happen (`Nothing was read.`, `created`, `changed`, `deleted`, `sent`,
`recorded`), then `Correct these and call again:` and one line per problem,
`- <path>: <problem>`, every problem found at once. `<path>` is the argument as
the schema spells it, dotted when nested (`seo.meta_keywords`,
`combinations[2].available_now`). Four kinds of problem, four wordings, each
from one shared function: an unknown identifier, `no <thing> of this shop has
the identifier <n> - find it with <tool>`, or `the <technical name, reference>
<value>` for an object found by name (`notFound()`); a field the tool does
not take, `not a field of this tool`, or one the shop works out, `worked out by
the shop, so it is not written here`, or a name the tool took before,
`renamed <new name>`, so a client built on the published names is told what
to send (`unknownFields()`, read tools included);
a field the running PrestaShop does not have, `not on PrestaShop <version>,
which has no <thing>; leave the field out` (`notOnThisVersion()`), where the
`null` a get tool returns for that field on that version is skipped, so its
answer can be given back to the update - and so is the `null` it returns for a
field the object or the shop does not use (`color` and `texture` outside a
group of type color, the faceted search fields without that module), a value
there being refused as before; an invalid value, `one of <a, b, c>`
(`oneOf()`), `true or false`, `a whole number`. An invalid value is never
replaced by a default nor ignored: the default applies only to an argument left
out.

**Personal data.** A list stays plain. A detail gives everything, because it was
asked for one named object. What leaves goes to the assistant's vendor: that is
the line to hold.

**Reading.** Core methods first. For a list, `DbQuery` or `PrestaShopCollection`
are just as native and avoid loading hundreds of objects.

**Writing.** Always through core objects, never raw SQL, so that hooks fire and
other modules follow. The token's employee is carried on the object being
written, so the record keeps their name rather than an anonymous line - on an
order that is `OrderHistory::$id_employee`, which the core reads before falling
back to the context.

**One object per write.** A write tool changes one thing per call. The back
office can act on a selection, but a batch that half fails cannot be reported
honestly, and a destructive call is confirmed by the client once - one order is
one question, thirty orders is one click. An assistant with several to change
loops.

**Writes are not replayable.** No write tool may be retried on a lost answer.
Each one says so in its own description and names the read tool that tells the
assistant whether the change went through.

**Links between tools.** A tool name cited in a description or an error must be
a tool the module ships, and every write names at least one read. A link to a
tool not written yet is left out, and added by the pull request that writes
it. `check-tools.php` fails on either.

**Descriptions.** Every tool is sent to the model on each step, so its text is
kept short: 600 characters at most for a tool description, 200 for a property
description. A tool description says, one sentence each where possible: what
it does, with the words and the place of the screen; which tool to use instead,
by name; and for a write, where its identifiers come from, then the
not-to-be-retried sentence with the read that checks it. Types, formats and
defaults belong to the property, once; how the tool works inside belongs here
and in the code comments; an error the tool returns with what to do next does
not need to be announced. What never leaves: the not-to-be-retried sentence,
"never guess" where an identifier is asked, an email sent to the customer, and
one object per call. A parameter that takes an identifier names the tool that
gives it. `php dev/tools/measure.php .` prints the size of each tool and page
and the texts above the budget. `check-tools.php` refuses a text above it,
except the ones written before the budget and listed in that script until
they are cut; a listed text cut within the budget must leave the list.

**Nature and permission.** `SomcpTool::acts($action, $controller)`: one
declaration, from which the module takes the permission required and what the
assistant is told about the call. `view` is read-only and repeatable; `add`,
`edit` and `delete` ask the merchant first and are never retried.

**Tools from other modules.** Another module publishes its own through the
`actionSomcpRegisterTools` hook, which is fired once per module so the registry
knows who it is serving. The name that module chose is prefixed with its folder
- `mymodule-get_tracking` - and it cannot opt out: the prefix is what stops
two modules that both thought of `get_tracking` from cancelling each other out,
and what stops a third party from publishing `somcp_get_order`. A name over 64
characters is refused rather than cut, so two tools can never land on the same
stub.

**Permissions from other modules.** A module publishing tools of its own
declares the permission that goes with them, in the same hook as the tools
that need it. Written against the array contract, which is the way to do it
(see [EXTENDING.md](EXTENDING.md)):

```php
return [
    'scopes' => ['tickets.read' => $this->trans('Read support tickets', [], 'Modules.Mymodule.Admin')],
    'tools' => [/* ... */],
];
```

It comes back prefixed - `mcp.mymodule.tickets.read` - like a tool name and for
the same reason. The label is what the merchant reads on the consent screen, so
the module passes it already translated: it knows what its tools do and this one
does not.

Borrowing one of ours instead would be lying to the merchant. Someone who let an
assistant read their orders has not let it read their customers' complaints, and
the consent screen has to be able to ask the two questions apart. The back office
permission follows the same rule: a tool declares the page where the merchant
would do the same thing by hand, which for another module's feature is that
module's own page, not `AdminOrders`.

A tool asking for a scope nobody declared is **refused at registration** and the
refusal written to the log. It used to be accepted and then unreachable forever
- never offered at consent, so never in a token, so never listed - which is the
worst of both.

**Reaching one client's own feature.** A tool may carry `_meta`, the place the
protocol keeps for what it has not defined itself - for instance
`openai/fileParams`, without which ChatGPT never fills a file parameter. A
client that does not know a key ignores it, so a key meant for one assistant
costs the others nothing.

The module publishes nothing there on its own; it carries what a tool declares,
as the eighth argument of the constructor. Two keys are refused and logged: one
that does not match the protocol's key format, and one whose prefix is reserved
for the protocol - a module writing under `io.modelcontextprotocol/` or
`dev.mcp/` would be putting words in a client's mouth from inside a tool
description.

**Prompts.** A tool returns data; a prompt is a guided task the merchant
starts from the assistant's menu, and it hands the assistant written
instructions for using the tools. They live one folder per prompt under
`prompts/`, each with a `prompt.md` and a `prompt.json` giving the title shown
in the menu, the description, the icon the built-in chat draws beside it, the
arguments the merchant may fill in, the scopes and back office permission it
needs, and the tools it talks about. Adding a prompt is adding a folder.

They replaced skills. The Skills extension was implemented and no assistant
tested ever read it; putting the same text in the server instructions would
have charged every conversation for it. A prompt costs nothing until the
merchant picks it. What it gives up is initiative: it never starts on its own.

A prompt is filtered exactly like the tools it describes, and for the same
reason: guidance on changing an order status is a promise the shop will refuse
to keep for an account that may not change one. The same filters are applied
again on `prompts/get`, so one switched off this morning cannot be fetched from
a listing a client kept.

What the merchant types goes below the guide under its own heading, line
breaks removed, never spliced into the text: the guide is written by a module
author, the values by a person, and mixing the two lets a value rewrite the
instructions around it.

Another module publishes its own by returning them from
`actionSomcpRegisterPrompts`, as arrays - see `SomcpContract`. The file must
sit inside that module's own folder.

**Switching a tool off.** The merchant can switch off any tool from the module
settings, per shop, with the usual inheritance. What is stored is the list of
tools that are off, not the ones that are on: a tool added by an update works
without anyone going to allow it, and one switched off stays off even if the
module publishing it is reinstalled. A tool that is off is left out of the
listing, refused if called anyway, and stops making its scope worth granting.
There are two such lists, one per column of the Tools and prompts tab: one for
the assistants connected over MCP, one for the built-in assistant. A tool can
be kept for the assistant that asks before every change and hidden from the
ones that connect from outside, or the other way round. Prompts work the same.

**Switching a group off.** The tab lists the tools grouped by the scope they
need (`SomcpScope::groupOf()`, the first one besides `mcp.access`), and each
group has the same two switches, stored as two more lists of what is off
(`SOMCP_SCOPES_OFF`, `SOMCP_SCOPES_OFF_CHAT`). A tool is offered when its own
switch is on and none of its scopes is off (`SomcpToolRegistry::isEnabled()`);
`isSwitchedOn()` is its own switch alone, the one its row shows, so that saving
the page never writes a group's state into the switches of its tools. A group
off therefore holds back a tool an update adds to it later, and switching it
back on gives each tool the switch it had. A prompt is held back where any
scope it needs is off. `mcp.access` is never a group anyone can switch off. The
prompts list is grouped the same way; its group switches only set the prompt
switches of the group in one go and are not stored.

**Shop, and the employee behind the token.** Everything is bound to the shop
the token belongs to, and the account is checked on every call rather than once
when the token was issued: an account disabled this morning stops working this
morning.

On a multistore installation the account must also be **attached to that
shop**. Back office permissions belong to the profile and are identical
everywhere, so without this an employee of one store could point an assistant
at another store's address and be handed its orders. The check is
`Employee::hasAuthOnShop()`, PrestaShop's own, and a super administrator passes
everywhere. It applies at the consent screen, on every tool call and on the
deposit page; on a single-shop installation it is skipped entirely.

**The shape of `inputSchema`.** `tools/call` sends named arguments, so the root
is always an object. Declared the natural PHP way, an empty `properties => []`
serialises to `[]` and a client refuses **the whole tools/list answer** - every
module's tools disappear at once over one module's habit.

somcp therefore repairs what it can at registration: a missing `type`, and an
empty array wherever an object belongs, at the root and one level down. What it
cannot repair - a root that is not an object, arguments with no names - refuses
that one tool and says which and why in the journal, as `tool_schema_refused`.
The other modules keep theirs.

Declare a parameterless tool like this:

```php
[
    'type' => 'object',
    'properties' => new stdClass(),
    'additionalProperties' => false,
]
```

**Asking the merchant something mid-call.** A tool that is missing something
does not fail and does not guess: it returns `SomcpTool::needsInput()` with a
question built by `SomcpElicitation`, and the endpoint turns that into the
`input_required` result of the 2026-07-28 revision. The client fetches the
answer, replays the same call, and the tool reads what it asked for under
`resumed` in its context. Nothing is kept on the server between the two halves -
the state travels through the client, sealed, and is refused unless it comes
back on the same tool, the same arguments, the same employee, the same shop and
within fifteen minutes.

Two limits, both from the protocol. It exists in the modern revision only, and a
question is never sent to a client that has not declared it can answer that kind
- the specification says MUST NOT. When either is true the merchant is told, in
the tool's answer, that the assistant cannot carry the question.

**Getting a file from the merchant.** There is no attachment channel: a tool
argument is JSON, so a file put there is bytes the model writes out one token at
a time. `SomcpUpload::request()` instead opens a deposit and asks the client to
open a page of the merchant's own back office; `SomcpUpload::take()` reads the
file back on the second half of the call. The bytes never pass through the
assistant. One file or several, the extension checked against the actual
bytes, a name of the module's own on disk, and the row deleted as the file is
handed over - so a reference works exactly once. The page refuses anyone but the
employee the request was opened for, which is the identity check a URL-mode
elicitation requires.

The tool says what it wants: which extensions (or a shorthand - `image`,
`document`, `data`, `archive`), how many files, how large each may be. The
module's own limits are an outer edge - 20 files, 64 MiB - and the real
ceiling is read from `upload_max_filesize` and `post_max_size`, because beyond
those PHP truncates without telling anybody. What a tool cannot do is widen the
list of formats that exist at all: that list is closed, every entry says how
its bytes are verified, and an upload endpoint in a back office that accepts
any extension is the oldest hole there is.

Which of two paths is taken depends on the client, not on the tool. One that
declared it can open an address gets the multi-round question. One that did not
is handed directions as plain text, each step only if the one before failed:
post the file itself to the deposit's direct address when it can make an HTTP
request, telling the person which domain to allow when its own environment
blocks that; hand it over with `somcp_upload_file`; and only then show the
merchant the deposit page, which comes once the shop sees a failure. Then it
calls the same tool again with `upload_ref`.

**Files for the tools of the module.** A tool of the module that takes a file
declares its fields and what each accepts (`files:` of its `SomcpTool`), and
takes an object with `url` or `upload_ref` there. `somcp_upload_file` opens a
deposit for one of those fields, with its rules, and answers with the
`upload_ref`; the file gets in by an address the shop downloads, by the direct
address, or by the deposit page, all judged by the same check. A file leaves
the shop the other way: the tool that finds it answers with a `download_ref`,
and `somcp_download_file` hands the file over.

Declaring is not the same as doing. In production a client declared that it
could open an address, took the question, never showed the page and replayed
the call at once. So a call that comes back to a deposit that is still open
and empty is answered with the same address as text, whichever path it took,
and a reference that leads nowhere - invented, expired, already used - is
treated as a first call and opens a new deposit. A tool written against the
array contract (see EXTENDING.md) gets all of this without doing anything; one
written against the objects declares `upload_ref` as an optional string and
calls `request()` and `take()` itself.

The bytes are written under `var/somcp/uploads/`, not inside the module. A
module folder has to stay partly servable - its scripts, styles and images are
fetched over HTTP - so a deposit kept there relies on a rule nobody wrote for
it; the `.htaccess` the module writes covers Apache and is ignored by nginx.
Nothing under `var/` is linked to, and the configurations that deny a directory
outright already deny that one.

---

## Entry template

Copy this for every tool.

### `tool_name`
**State**: draft · to write · written · tested
**Nature**: `acts(VIEW, 'AdminXxx')` · **Scope**: `mcp.xxx.read`

**Does** - one sentence.

**Do not use for** - the cases where the assistant should reach for another
tool, named.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| | | | |

**Returns** - the fields, and only those.

**Notes** - multistore, language, side effects, known traps.

---

## Sections

An entry marked **draft** is not open yet: the maintainer still has to approve
its design, and its issue carries the `draft` label. Do not start it;
comment on the issue if you have something to say about the design.

An entry marked **to write** is approved and is the specification: pick its
issue, write the tool to match, and change the state in the same pull request.
If something in the entry turns out wrong while writing it, say so in the issue
first.

The scopes of entries marked draft or to write do not exist yet. The first tool of a
section adds its scopes to `SomcpScope`, with the label the merchant reads on
the consent screen, in the four languages.

### Orders

The order and what belongs to it: its lines, its history, its messages, its
documents, its addresses.

**Not here**: order statuses (→ Configuration), invoices and credit slips
(→ Billing), carts that never became an order (→ Carts), customers
(→ Customers).

#### `somcp_search_orders`
**State**: written
**Nature**: `acts(VIEW, 'AdminOrders')` · **Scope**: `mcp.orders.read`

**Does** - finds orders by reference, customer, date, status, amount, payment
method, delivery country, company or first order, and returns one short line
each.

**Do not use for** - reading an order already identified (→ `somcp_get_order`),
or carts that were never paid for (→ Carts).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | no | exact identifier, a whole number above 0 |
| `reference` | string | no | whole or partial reference |
| `customer_name` | string | no | first name, last name or both, in any order |
| `customer_email` | string | no | exact match, more reliable than the name |
| `date_from` / `date_to` | string | no | `YYYY-MM-DD`, both days included |
| `status_id` | integer | no | shop-specific, never guessed |
| `total_min` / `total_max` | number | no | amount paid, tax included |
| `payment_method` | string | no | whole or partial |
| `new_client` | boolean | no | `true`: the customer had no earlier order, in any status (New client column); `false`: returning customers |
| `delivery_country` | string | no | ISO code of the country of the delivery address; an unknown code is one more line of the refusal |
| `company` | string | no | customer's company, whole or partial |
| `only_valid` | boolean | no | paid orders only |
| `sort` | enum | no | `date_desc` (default), `date_asc`, `total_desc`, `total_asc` |
| `language` | string | no | ISO code of one of the shop's active languages, for the country and status names, refused otherwise; the shop default when left out; given back as `language` in the answer |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per order: `id_order`, `reference`, `date_add` (ISO 8601 with
the shop's offset, the name `somcp_get_order` uses) and `date` (the same value,
the name kept for the clients built on it), `customer_name`,
`new_client`, `company`, `phone`, `delivery_country` (name in the language
read), `delivery_country_iso`, `total_paid_tax_incl`, `currency`,
`payment_method`, `status`, `status_id`, `status_color`. Plus `returned`,
`total_count`, `offset` and `language`.

**Notes** - read through a query, not through Order objects: a page would
otherwise load twenty orders with their customers and addresses. The phone is
the delivery address one, mobile first, because that is what carriers use. The
list stays plain on purpose; the address book of a shop does not belong in an
answer about what came in yesterday: the delivery country and the company are
what the back office list shows, nothing more.

`new_client` uses the subquery of the core's order grid (`OrderQueryBuilder`),
an earlier order of the same customer in any status, so it matches the New
client column. The total and its filters read `total_paid_tax_incl`, the Total
column. The company is returned whatever `PS_B2B_ENABLE` says, empty when the
customer has none. The count joins the same tables as the list, so
`total_count` follows every filter.

A field the tool does not take, a `sort` outside its list, a `new_client` that
is not true or false, a `total_min` or `total_max` that is not a number, a
`date_from` or `date_to` that is not `YYYY-MM-DD`, an `id_order` that is not a
whole number above 0 (`id_order: a whole number above 0`; one no order of the
shop has answers an empty list), or a `status_id` that is not
a whole number above 0 or matches no order status, a `language` the shop does
not have, or a `delivery_country` that is no country's ISO code
(`delivery_country: an ISO code of a country, for example FR`, the line of
`SomcpToolSearchOrders::deliveryCountry()` that `somcp_get_sales_summary` gives
too) is refused in one answer, `Nothing was read`, before anything is read:
never ignored, never replaced by the default order.

#### `somcp_get_order`
**State**: written
**Nature**: `acts(VIEW, 'AdminOrders')` · **Scope**: `mcp.orders.read`

**Does** - returns everything about one order.

**Do not use for** - looking for orders (→ `somcp_search_orders`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |

**Returns** - `order`, `customer`, `addresses` (delivery and invoice),
`products`, `discounts`, `shipping`, `totals`, `payments`, `history`,
`documents`, `messages`. Every date (`order.date_add`, `customer.date_add`,
the `date` of a shipment, payment, history step, invoice, delivery slip, credit
slip or message) is ISO 8601 with the shop's offset, `null` when empty. Among
them, what the order page shows:

- `order`: `language` (the ISO code of the order's language, the customer's,
  which the names of the order are read in), `payment_module` (the technical
  name of the payment module, the one `somcp_create_order` takes, beside
  `payment_method`, the name shown to the customer), `status` (`status_id`, the
  identifier `somcp_set_order_status` takes, `name`, `paid`, `shipped`), `note` (Order note / Order private note),
  `id_cart`, `gift`, `gift_message`, `recycled_packaging`;
- `customer`: `title` (Mr. / Mrs.), `is_guest`, `valid_orders_count`,
  `total_spent` with `total_spent_currency`, `private_note` (read only);
- `addresses.delivery` and `addresses.invoice`: `id_address`, `alias`,
  `state` (the region), `dni` (empty when not set);
- `products`, per line: `id_order_detail`, `id_order_invoice` (the invoice
  the line is on, the one `somcp_update_order_product` takes, 0 when on none),
  `supplier_reference`, `ean13`, `isbn`, `upc`, `mpn`
  (strings), `tax_name`, `tax_rate`, `stock_location`, `quantity_in_stock_now`,
  `quantity_refunded`, `refunded_tax_excl`, `refunded_tax_incl`,
  `quantity_returned`, `customizations` (`id_customization`, `type` text or
  file, `name`, `value`), `pack_items` (`id_product`, `id_product_attribute`,
  `name`, `reference`, `quantity`, `quantity_in_stock_now`; empty when the
  product is not a pack);
- `discounts`, each with `id_order_cart_rule`, `id_cart_rule` (the voucher, as
  `somcp_list_cart_rules` names it), `id_order_invoice` and `free_shipping`;
- `history`, each step with `id_order_history`;
- `shipping.shipments`, one per shipment: `id_order_carrier`, `date`,
  `id_carrier`, `carrier`, `weight`, `shipping_cost_tax_excl`,
  `shipping_cost_tax_incl`, `tracking_number`, `tracking_url`;
- `totals`: `taxes`, `wrapping_tax_excl` and `wrapping_tax_incl`, and the total
  paid as `total_paid_tax_excl` and `total_paid_tax_incl`, as `somcp_search_orders`
  gives it;
- `payments`, each with `id_order_payment` (the identifier
  `somcp_add_order_payment` answers), `id_order_invoice` (0 when not tied to an
  invoice), `currency`, `invoice_number` (empty when not tied to an invoice),
  `id_employee` and `employee` (who recorded it, PrestaShop 9 only,
  `null` and empty on 8);
- `documents`: `invoices` (with `id_order_invoice` and `note`), `delivery_slips`
  (`id_order_invoice`, `number` with the shop's prefix, `date`,
  `total_tax_incl`) and `credit_slips` (`id_order_slip`, `number` with the
  shop's credit slip prefix in the order's language, `date`, `total_tax_excl`,
  `total_tax_incl`); each invoice gives its total as `total_paid_tax_excl` and
  `total_paid_tax_incl`;
- `messages`, each with `from` (`employee` or `customer`) and `from_name`.

**Errors** - every problem at once, nothing read, each under "Nothing was read.
Correct these and call again:": a field the tool does not take; `id_order: give
id_order or reference - find the order with somcp_search_orders` when both are
missing; `id_order: no order of this shop has the identifier <n> - find it with
somcp_search_orders`, or the same line on `reference`, when the order is not
found; a reference shared by several orders is refused with their identifiers.
This lookup is shared by every order and billing tool, so the same wording
answers a wrong order wherever it is read.

**Notes** - takes no search filter on purpose: given "Dupont's March orders" it
would have to pick one of twelve. Finding is one tool, reading is another. Read
through core objects, which one order can afford.

The customer figures come from `Customer::getStats()`, as on the order page,
converted into the order's currency. Stock location and quantity in stock now
come from `Order::getProducts()`, not from `product_quantity_in_stock`, which is
the quantity at the time of the order. Pack items are read as `somcp_get_product` reads them, the pack as it is now:
the order keeps no copy of its content. Shipments are read from the order's
`OrderCarrier` rows, not from `Order::getShipping()`, which groups them by
carrier. `order_payment.id_employee` exists on 9.0.0 only. Card number, holder
and expiry are never returned: the page does not show them.

#### `somcp_set_order_status`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - changes the status of one order, with every effect the shop attaches
to that status: the customer email, the stock movement, the invoice, the refund.

**Do not use for** - reading what the statuses are (→
`somcp_list_order_statuses`, which must be called first), or changing several
orders in one call, which it refuses by design.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `status_id` | integer | yes | from `somcp_list_order_statuses`, never guessed |

**Returns** - `changed`, `order`, `previous_status` and `status` (each
`status_id`, `name`), `status_sends_email`, `email_sent`, `warning`, `changed_by_employee_id`. When the
order already has that status: `changed: false` with a `reason`, and nothing
happens.

**Errors** - every problem at once, nothing changed and no email sent when one
check fails, each under "Nothing was changed. Correct these and call again:": a
field the tool does not take; the order not found or a reference shared by
several orders; `status_id: a whole number above 0`, or `status_id: no order
status of this shop has the identifier <n> - find it with
somcp_list_order_statuses` for a status the shop does not have or deleted.

**Notes** - the sequence is the core's own, read from `UpdateOrderStatusHandler`
on 8.0.0 and 9.1.5 rather than reinvented: refuse a redundant status, set
`id_employee`, `changeIdOrderState()` with the payments carried over when there
is no invoice yet, then `addWithemail()`.

Two inputs the **Update status** button can never send are refused, with
nothing changed: a reference shared by several orders (a split cart), answered
with the list of its orders through the lookup every order tool shares
(`SomcpToolGetOrder::find()`), and a status deleted on **Shop Parameters >
Order Settings > Statuses** (`order_state.deleted` = 1, which the order page
leaves out through `OrderState::getOrderStates()`), refused as an unknown
status.

Refusing a status the order already has is not politeness, it is the guard
against the likeliest harm this tool can do - a retried call mailing the
customer twice. The core refuses it as well.

`addWithemail()` returns false both when nothing was written and when the status
changed but the mail failed. Those are reported apart, on whether the history row
got an id: an assistant told only "it failed" would retry a change that already
happened.

Advanced stock management is synchronised on PrestaShop 8 only, guarded by
`method_exists`: `StockAvailable::dependsOnStock()` no longer exists in 9.

#### `somcp_set_order_tracking`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - sets the tracking number of one shipment of one order, like the
**Tracking number** field of the **Edit shipping details** window, on the
**Carriers** tab of the order page.

**Do not use for** - changing the order status to shipped (→
`somcp_set_order_status`), which is a separate decision, or changing the carrier
of a shipment, which this tool never does.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; a reference shared by several orders (a split cart) is refused with their identifiers |
| `tracking_number` | string | yes | 64 characters at most, checked with `Validate::isTrackingNumber()`; `""` clears the number |
| `id_order_carrier` | integer | no | which shipment, from `shipping.shipments` of `somcp_get_order`; required only when the order has more than one, refused with the list of shipments otherwise |

**Returns** - `changed`, `order` (`id_order` and `reference`),
`id_order_carrier`, `carrier` (name), `previous_tracking_number`,
`tracking_number`, `tracking_url` (built from the carrier's `url`, `@` standing
for the number, empty when the carrier has none), `customer_notified`,
`warning` when the number was saved but the email failed. When the shipment
already has that number: `changed: false` with a `reason`, and nothing happens.

**Errors** - every problem at once, nothing changed and no email sent when one
check fails, each under "Nothing was changed. Correct these and call again:": a
field the tool does not take; `tracking_number` not a string, longer than 64
characters or holding a character PrestaShop refuses; the order not found or a
reference shared by several orders; an order with no shipment;
`id_order_carrier` left out on an order with several shipments, not a whole
number, or `id_order_carrier: no shipment of this order has the identifier
<n> - find it with somcp_get_order`, followed by the shipments.

**Notes** - the number lives on `order_carrier.tracking_number` (`varchar(64)`
on 8 and 9), one row per shipment, read the way `somcp_get_order` reads them.
The sequence is the core's own, read from `UpdateOrderShippingDetailsHandler`,
identical on 8.0.1 and 9.0.0: check with `Validate::isTrackingNumber()` (the
core has no format per carrier), save the `OrderCarrier`, then, only when the
number changed and is not empty, `OrderCarrier::sendInTransitEmail()` (the
`in_transit` email, sent every time, not a shop setting) and, once it has left,
`actionAdminOrdersTrackingNumberUpdate` with `order`, `customer` and `carrier`
for the order's shop. The context language is the order's for the length of the
change, as in the core.

The same number again is `changed: false`, with no write, no email and no hook:
the guard against a retried call mailing the customer twice. An empty number
clears it without any email, as in the back office. Spaces around the number
are removed before the comparison.

`customer_notified` is false when the order's carrier has no tracking address:
`sendInTransitEmail()` then answers true without sending anything. A failed
email is reported in `warning`, apart from the change, which did happen.

The carrier field of the same window is left out: changing it recalculates the
shipping cost and the order totals (`OrderAmountUpdater`), a different decision
with a different risk.

The order is found by the shared lookup of `somcp_get_order`
(`SomcpToolGetOrder::find()`), always within the token's shop. A cart split
across carriers gives its orders one reference, and each has its own page and
its own **Edit shipping details** window: a reference shared by several orders
is refused with each `id_order`, its current status and its carrier, before
anything is written or sent. An unknown reference, or neither `id_order` nor
`reference`, is refused with a pointer to `somcp_search_orders`. The number of
each shipment is read back from `shipping.shipments` of `somcp_get_order`.

#### `somcp_add_order_message`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - adds one message to one order, like the **Messages** block of the
order page: shown to the customer and emailed to them, or kept private for the
shop.

**Do not use for** - answering a customer service thread that is not tied to an
order (Customer Service page), or noting something for the shop only on the
order itself - that is the order note (→ `somcp_set_order_note`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `message` | string | yes | 1200 characters at most, plain text, refused if it holds HTML scripts or events |
| `display_to_customer` | boolean | yes | the **Display to customer?** checkbox. No default: the assistant must decide whether the customer sees and receives it |

**Returns** - `added`, `order` (`id_order` and `reference`),
`id_customer_thread`, `id_customer_message`, `display_to_customer`,
`email_sent`, `warning` when the message was saved but the email failed -
reported apart, so the assistant does not add it twice.

**Errors** - every problem at once, nothing sent when one check fails, each
under "Nothing was sent. Correct these and call again:": a field the tool does
not take; `display_to_customer: true or false`; `message` not a string, empty,
longer than 1200 characters or holding a script or an event attribute; the
order not found or a reference shared by several orders; an order whose
customer was deleted.

**Notes** - the block holds a select **Choose your order message** (the
predefined messages of **Customer Service > Order Messages**), the checkbox
**Display to customer?**, the field **Message** (1200 characters, counter on
screen) and **Send message**, the same on 8.0.1 and 9.0.0. The predefined
messages are not a parameter: they only fill the text field on screen, and
reading them belongs to a tool for Order Messages.

The sequence is the core's own, read from `AddOrderCustomerMessageHandler`,
identical on 8.0.1 and 9.0.0, rather than the command bus, whose handler is
built with context ids the module does not have outside the back office:

1. the message is refused if it holds a script or an event attribute
   (`Validate::isCleanHtml()`, the check behind the `CleanHtml` constraint);
2. the order's thread is found by
   `CustomerThread::getIdCustomerThreadByEmailAndIdOrder()`, or opened
   (`id_contact` 0, `status` open, the order's customer, shop and language);
3. a `CustomerMessage` is added with `id_employee` = the token's employee, so
   the message shows who wrote it, and `private` = not `display_to_customer`;
4. when it is not private, the customer is emailed the `order_merchant_comment`
   template ("New message regarding your order") in the order's language,
   escaped and with its line breaks kept unless the shop sends text emails; a
   private message sends nothing.

`display_to_customer` must be a real boolean: the string `"false"` is refused
rather than read as true. Spaces around the message are removed, and a message
of spaces only is refused. Guest orders have a customer row too; an order whose
customer was deleted is refused, as the core does.

Nothing refuses a repeat: two identical messages are a legitimate thing to
write. Not replayable: a lost answer is checked with `somcp_get_order`, whose
`messages` list shows the new one.

#### `somcp_set_order_note`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - sets the private note of one order, the text only the shop sees,
like the **Order note** text area (**Order private note** on 9.0.0) of the
**Status** tab of the order page.

**Do not use for** - writing to the customer (→ `somcp_add_order_message`), or
a note about the customer rather than this order (→ the customer tools): the
**Private note** / **Customer private note** of the Customer block on the same
page is `customer.note`.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `note` | string | yes | the whole new note; an empty string clears it |

**Returns** - `changed`, `order` (`id_order` and `reference`),
`previous_note`, `note`. When the order already has that note:
`changed: false` with a `reason`, and nothing is written.

**Notes** - the note lives in `orders.note` (`text` on 8.0.1, `mediumtext` on
9.0.0). The sequence is the core's own, read from `SetInternalOrderNoteHandler`,
identical on 8.0.1 and 9.0.0: set `Order::$note`, `validateFields(false)`,
`update()`, so `actionObjectOrderUpdateBefore/After` fire as from the back
office. Nothing else happens: no email, no history line.

The note replaces the previous one, exactly as the screen does: there is no
append, and the description tells the assistant to read the current note with
`somcp_get_order` (`order.note`) first when it means to add to it. Spaces around
the note are removed, as the back office form does, before the comparison.

The size limit differs: on 9.0.0 the class declares the mediumtext limit and
`validateFields()` checks it; on 8.0.1 the class declares none and the `text`
column holds 65535 bytes, so a longer note is refused rather than cut by MySQL.
The limit is read from `Order::$definition` and, when it declares none, from
the column itself (`SomcpTool::overTextColumn()`), not from the version.

Not replayable: a lost answer is checked with `somcp_get_order`, whose
`order.note` shows the saved text.

#### `somcp_add_order_payment`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - records one payment received for one order - a bank wire or a cheque
that arrived, a part payment - as the **Payment** block of the order page does.

**Do not use for** - refunds (credit slips, a later Billing tool), or marking the
order paid (→ `somcp_set_order_status`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `amount` | number | yes | greater than 0, in `currency` |
| `payment_method` | string | yes | best given as the **Payment** block suggests it: the name of the shop's payment module, for example "Bank transfer", "Payments by check" or "Cash on delivery (COD)", so the Payment table and the order read the same method; 255 characters at most, without `< > = { }` |
| `currency` | string | no | ISO code; the order's currency by default |
| `payment_date` | string | no | ISO 8601; now by default |
| `transaction_id` | string | no | the bank's or the processor's reference, 254 characters at most |
| `id_order_invoice` | integer | when the order has an invoice | from `somcp_get_order` (`documents.invoices`), never guessed |

**Returns** - `added`, `order` (`id_order` and `reference`), `id_order_payment`,
`amount`, `currency`, `payment_method`, `transaction_id`, `payment_date` (ISO
8601 with the shop's offset, as it was recorded in the shop's time), `id_employee` (who recorded it, as the **Employee** column of
9.0.0; `null` on 8.0.1, which has neither the column nor the argument, and
matches `id_employee` of `payments[]` in `somcp_get_order`), `total_paid_real`
after the payment, `total_paid_tax_incl` of the order, `order_currency`,
`remaining` (what is still due, never below zero), and `warning` when the
payment row was added but the order or its invoice could not be fully updated.

**Errors** - every problem at once, nothing recorded when one check fails, each
under "Nothing was recorded. Correct these and call again:": a field the tool
does not take; `amount: a number above 0`; `payment_method` missing, too long or
holding < > = { }; `transaction_id` not a string or too long; `payment_date: a
date, ISO 8601`; the order not found or a reference shared by several orders;
then, once the order is found, a currency the shop does not have and, on an
invoiced order, `id_order_invoice` left out or `id_order_invoice: no invoice of
this order has the identifier <n> - find it with somcp_get_order`, followed by
the invoices.

**Notes** - the block holds a table (Date, Payment method, Transaction ID,
Amount, Invoice, and **Employee** on 9.0.0 only) and a line to add one: date,
payment method, transaction ID, amount, currency, and the invoice when the order
has one, then **Add**.

The checks are those of `AddPaymentCommand`, identical on 8.0.1 and 9.0.0: the
payment method is required and must not hold the characters of
`INVALID_CHARACTERS_NAME` (`< > = { }`, the rule of `Validate::isGenericName()`);
the amount must not be negative (`NegativePaymentAmountException`), and zero is
refused too, since a zero payment records nothing useful; the currency must
exist in the order's shop. When the order has an invoice, an
`id_order_invoice` of this order is required - the core only checks that it
exists, this tool also checks that it belongs to the order - and the call is
refused with the list of invoices otherwise; when the order has none, it is
ignored. Then `Order::addOrderPayment()`, as `AddPaymentHandler` calls it, adds
the `order_payment` row, converts the amount into the order's currency when the
currencies differ and raises `orders.total_paid_real`. It does **not** change
the order status: marking the order paid stays a separate call to
`somcp_set_order_status`.

**Difference between versions** - on 9.0.0 `addOrderPayment()` takes the
employee as a seventh argument and stores it in `order_payment.id_employee`;
that argument and that column do not exist on 8.0.1. The token's employee is
passed only when the method's signature has that argument, read by reflection,
not from the version.

`addOrderPayment()` does not return the new row: `id_order_payment` is the
highest payment id of the order's reference after the call, compared with the
one before. A date given as a day alone gets the time of the call, as the core
does; relative dates ("tomorrow") and impossible ones (February 31st) are
refused.

One payment per call. Not replayable: a payment added twice is money counted
twice. A lost answer is checked with `somcp_get_order`, whose `payments` lists
it with its `id_order_payment`; `payment_date` is the `date` of that row. The card fields of `order_payment` (`card_number`, `card_brand`,
`card_expiration`, `card_holder`) are not on the screen and are not taken.

#### `somcp_issue_partial_refund`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - issues a partial refund on one order - chosen quantities and amounts
of some lines, and part of the shipping - as the **Partial refund** button of
the order page does, with a credit slip, a voucher or both.

**Do not use for** - refunding whole lines of a paid order not shipped yet
(→ `somcp_issue_standard_refund`), products sent back by the customer after
delivery (→ `somcp_return_order_products`), removing products from an unpaid
order (→ `somcp_cancel_order_products`), or recording money received
(→ `somcp_add_order_payment`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `lines` | array | yes, unless only shipping is refunded | one entry per line: `id_order_detail` (from `products[].id_order_detail` of `somcp_get_order`), `quantity` (at least 1, at most what is left to refund), and the amount in the tax mode the page shows for this order, `amount_tax_excl` **or** `amount_tax_incl` (greater than 0, at most quantity times the unit price); the other one is refused with the right name; the former names `amount_tax_excluded` and `amount_tax_included` are refused with the current one (`lines[0].amount_tax_excluded: renamed amount_tax_excl`) |
| `shipping_amount_tax_excl` **or** `shipping_amount_tax_incl` | number | no | part of the shipping to refund, in the tax mode the page shows for this order, at most what is left of it; the other one is refused with the right name; 0 by default; the former names `shipping_amount_tax_excluded` and `shipping_amount_tax_included` are refused with the current one |
| `restock` | boolean | no | **Re-stock products**; only matters once the order is delivered - before that the core always puts the quantities back. False by default |
| `credit_slip` | boolean | no | **Generate a credit slip**; true by default, as the box is ticked when the form opens |
| `voucher` | boolean | no | **Generate a voucher**; false by default |
| `voucher_refund_type` | enum | no | only for an order partly paid with a voucher: `product_prices` (**Product(s) price:**) or `product_prices_excluding_voucher` (**Product(s) price, excluding amount of initial voucher:**, the default the page pre-selects); refused on an order without voucher |

At least one of `credit_slip` and `voucher` must be true, as on screen.

**Returns** - `refunded`, `order` (`id_order` and `reference`), `lines` (per
line: `id_order_detail`, `product_name`, `quantity`, `amount_tax_excl`,
`amount_tax_incl`), `shipping_refunded_tax_excl`,
`shipping_refunded_tax_incl`, `total_refunded_tax_excl`,
`total_refunded_tax_incl`, `currency`,
`restocked`, `credit_slip` (`id_order_slip` and its totals, or `null`),
`voucher` (`id_cart_rule`, `code`, `amount`, `tax_included`, or `null`), and
`warning` when the refund was recorded but the slip or the voucher cannot be
found afterwards.

**Errors** - every problem at once, nothing recorded when one check fails,
each as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields of a
line; the order not found or a reference shared by several orders; a line that
is not one of this order's; a quantity below 1 or above what is left; an amount
missing, not above 0, above the line's maximum, or in the wrong tax mode;
the shipping negative or above what is left; `credit_slip` and `voucher` both
false; `voucher_refund_type` on an order without voucher; an order neither paid
nor with payments. The core's own refusals are put in the same shape.

**Notes** - the button is shown when the order has been paid or has payments
(`hasBeenPaid()` or `hasPayments()`), and turns the **Products** table into the
`cancel_product` form: per line **Quantity** and **Amount**, under the totals
**Shipping**, then **Re-stock products**, **Generate a credit slip** (ticked),
**Generate a voucher** and the voucher choice. The Amount label follows the
customer group of the order (`Group::getPriceDisplayMethod()` of its default
group), and so does the field the tool takes.

The core's `IssuePartialRefundHandler` does the work, built with `new` with its
services (`OrderRefundCalculator`, `OrderSlipCreator`, `VoucherGenerator`,
`OrderRefundUpdater`, `ContextStateManager`), whose constructors are the same
on 8.0.1 and 9.0.0: stock put back when the order is not delivered or with
`restock`, `actionProductCancel` per line, the carrier weight, the credit
slip, the refund data of the lines, and the voucher. The handler does not check
that a line belongs to the order, and lowers an amount above the line's maximum
instead of refusing it: the tool checks both first. The shipping amount is read
in the order's tax mode, as the form sends it, and capped like the core caps it
(the order's shipping minus what earlier slips refunded).

**Differences between 8.0.1 and 9.0.0** - none in the handler or the form.
`OrderSlipCreator` keeps the slip it created on 9.0.0 only: the new slip is
found as the highest `id_order_slip` of the order after the call, and the
voucher as the highest cart rule of the customer.

Not replayable: a refund issued twice is money paid back twice. A lost answer
is checked with `somcp_get_order` (`products[].quantity_refunded`,
`documents.credit_slips`).

#### `somcp_issue_standard_refund`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - issues a standard refund on one paid order that is not delivered
yet - whole quantities of some lines, the shipping if asked - as the
**Standard refund** button of the order page does, with a credit slip, a
voucher or both. The refunded quantities go back in stock.

**Do not use for** - a chosen amount rather than whole lines, or a shop with
merchandise returns off (→ `somcp_issue_partial_refund`), a delivered order
(→ `somcp_return_order_products`), an unpaid order
(→ `somcp_cancel_order_products`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `lines` | array | yes | one entry per line: `id_order_detail` (from `products[].id_order_detail` of `somcp_get_order`) and `quantity` (at least 1, at most what is left to refund); no amount, the line's price is refunded |
| `refund_shipping` | boolean | no | the **Shipping** checkbox: what is left of the order's shipping; false by default |
| `credit_slip` | boolean | no | **Generate a credit slip**; true by default, as the box is ticked when the form opens |
| `voucher` | boolean | no | **Generate a voucher**; false by default, as on screen |
| `voucher_refund_type` | enum | no | only for an order partly paid with a voucher: `product_prices` (**Product(s) price:**) or `product_prices_excluding_voucher` (**Product(s) price, excluding amount of initial voucher:**, the default the page pre-selects); refused on an order without voucher |

At least one of `credit_slip` and `voucher` must be true, as on screen.

**Returns** - the answer of `somcp_issue_partial_refund`: `refunded`, `order`
(`id_order` and `reference`), `lines` (per line: `id_order_detail`,
`product_name`, `quantity`, `amount_tax_excl`, `amount_tax_incl`),
`shipping_refunded_tax_excl`, `shipping_refunded_tax_incl`,
`total_refunded_tax_excl`,
`total_refunded_tax_incl`, `currency`, `restocked`, `credit_slip`
(`id_order_slip` and its totals, or `null`), `voucher` (`id_cart_rule`,
`code`, `amount`, `tax_included`, or `null`), and `warning` when the refund was
recorded but the slip or the voucher cannot be found afterwards.

**Errors** - every problem at once, nothing recorded when one check fails,
each as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields of a
line; no lines; the order not found or a reference shared by several orders; a
line that is not one of this order's; a quantity below 1 or above what is
left; `credit_slip` and `voucher` both false; `voucher_refund_type` on an order
without voucher; merchandise returns off (naming **Customer Service >
Merchandise Returns** and `somcp_issue_partial_refund`); an order neither paid
nor with payments; an order already delivered (naming
`somcp_return_order_products` and `somcp_issue_partial_refund`).
The core's own refusals are put in the same shape.

**Notes** - the button is shown only when merchandise returns are on
(`PS_ORDER_RETURN`), the order is not delivered, and it has been paid or has
payments; otherwise the same place shows **Return products** (delivered) or
**Cancel products** (not paid). The form is `cancel_product`: per line a
checkbox and **Quantity**, the **Shipping** checkbox, **Generate a credit
slip** (ticked), **Generate a voucher** and the voucher choice.

The core's `IssueStandardRefundHandler` does the work, built with `new` with
the same services as the partial refund, whose constructors are the same on
8.0.1 and 9.0.0: stock put back for every refunded quantity,
`actionProductCancel` per line with the standard refund action, the carrier
weight, the credit slip, the refund data of the lines, and the voucher. The
handler does not check that a line belongs to the order: the tool does. The
shipping refunded is the order's whole shipping tax included, which the core
caps to what earlier slips left. The checks, the refusals and the answer are
shared with `somcp_issue_partial_refund`.

**Differences between 8.0.1 and 9.0.0** - none in the handler or the form.
The new slip is found as the highest `id_order_slip` of the order after the
call, and the voucher as the highest cart rule of the customer.

Not replayable: a refund issued twice is money paid back twice. A lost answer
is checked with `somcp_get_order` (`products[].quantity_refunded`,
`documents.credit_slips`).

#### `somcp_return_order_products`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - records products sent back by the customer on one delivered order
and refunds them - whole quantities of some lines, the shipping if asked - as
the **Return products** button of the order page does, with a credit slip, a
voucher or both. The quantities count as returned, and go back in stock only
when asked.

**Do not use for** - an order not delivered yet
(→ `somcp_issue_standard_refund`), a chosen amount rather than whole lines
(→ `somcp_issue_partial_refund`), removing products from an unpaid order
(→ `somcp_cancel_order_products`), the return requests customers send from
their account (**Customer Service > Merchandise Returns**, another page, no
tool yet).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `lines` | array | yes | one entry per line: `id_order_detail` (from `products[].id_order_detail` of `somcp_get_order`) and `quantity` (at least 1, at most what is left to refund); no amount, the line's price is refunded |
| `restock` | boolean | yes | **Re-stock products**: no default, although the box opens unticked, because the merchant must be asked whether the returned goods can be sold again |
| `refund_shipping` | boolean | no | the **Shipping** checkbox: what is left of the order's shipping; false by default |
| `credit_slip` | boolean | no | **Generate a credit slip**; true by default, as the box is ticked when the form opens |
| `voucher` | boolean | no | **Generate a voucher**; false by default, as on screen |
| `voucher_refund_type` | enum | no | only for an order partly paid with a voucher: `product_prices` (**Product(s) price:**) or `product_prices_excluding_voucher` (**Product(s) price, excluding amount of initial voucher:**, the default the page pre-selects); refused on an order without voucher |

At least one of `credit_slip` and `voucher` must be true, as on screen.

**Returns** - the answer of `somcp_issue_partial_refund`: `refunded`, `order`
(`id_order` and `reference`), `lines` (per line: `id_order_detail`,
`product_name`, `quantity`, `amount_tax_excl`, `amount_tax_incl`),
`shipping_refunded_tax_excl`, `shipping_refunded_tax_incl`, `total_refunded_tax_excl`,
`total_refunded_tax_incl`, `currency`, `restocked` (what `restock` said),
`credit_slip` (`id_order_slip` and its totals, or `null`), `voucher`
(`id_cart_rule`, `code`, `amount`, `tax_included`, or `null`), and `warning`
when the refund was recorded but the slip or the voucher cannot be found
afterwards.

**Errors** - every problem at once, nothing recorded when one check fails,
each as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields of a
line; no lines; `restock` missing; the order not found or a reference shared
by several orders; a line that is not one of this order's; a quantity below 1
or above what is left; `credit_slip` and `voucher` both false;
`voucher_refund_type` on an order without voucher; merchandise returns off
(naming **Customer Service > Merchandise Returns** and
`somcp_issue_partial_refund`); an order not delivered yet (naming
`somcp_issue_standard_refund`). The core's own refusals are put in the same
shape.

**Notes** - the button is shown only when merchandise returns are on
(`PS_ORDER_RETURN`) and the order is delivered; otherwise the same place shows
**Standard refund** (paid, not delivered) or **Cancel products** (not paid).
The form is `cancel_product`: per line a checkbox and **Quantity**, then
**Re-stock products**, the **Shipping** checkbox, **Generate a credit slip**
(ticked), **Generate a voucher** and the voucher choice.

The core's `IssueReturnProductHandler` does the work, built with `new` with
the same services as the other refunds, whose constructors are the same on
8.0.1 and 9.0.0: stock put back only when `restock`, `actionProductCancel` per
line with the return action, the carrier weight, the credit slip, the refund
data of the lines counted as returned (`product_quantity_return`), and the
voucher. The handler does not check that a line belongs to the order: the tool
does. The lines are read and checked as by `somcp_issue_standard_refund`; the
refusals and the answer are shared with `somcp_issue_partial_refund`.

**Differences between 8.0.1 and 9.0.0** - none in the handler or the form.
The new slip is found as the highest `id_order_slip` of the order after the
call, and the voucher as the highest cart rule of the customer.

Not replayable: a refund issued twice is money paid back twice. A lost answer
is checked with `somcp_get_order` (`products[].quantity_refunded`,
`documents.credit_slips`).

#### `somcp_cancel_order_products`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - removes quantities of some lines from one order that is not paid
yet, as the **Cancel products** button of the order page does: the order
totals and the stock follow. When no line is left, the order goes to
**Canceled** and the customer is emailed.

**Do not use for** - a paid order not delivered
(→ `somcp_issue_standard_refund`, or `somcp_issue_partial_refund` for a chosen
amount), a delivered order (→ `somcp_return_order_products`), cancelling the
whole order with its status (→ `somcp_set_order_status`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `lines` | array | yes | one entry per line: `id_order_detail` (from `products[].id_order_detail` of `somcp_get_order`) and `quantity` (at least 1, at most what is left on the line) |

**Returns** - `cancelled`, `order` (`id_order` and `reference`), `lines` (per
line: `id_order_detail`, `product_name`, `quantity_cancelled`,
`quantity_left`), the order totals after the change (`totals`, in the shape of `totals` of
`somcp_get_order`, and `currency`), `order_cancelled` (true when no line was
left and the status changed), `status` after the call (`id`, `name`), and
`warning` when the order was cancelled but the customer email failed.

**Errors** - every problem at once, nothing recorded when one check fails,
each as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields of a
line; no lines; the order not found or a reference shared by several orders; a
line that is not one of this order's (naming `somcp_get_order`); a quantity
below 1 or above what is left (giving that quantity); merchandise returns off
(naming **Customer Service > Merchandise Returns** and
`somcp_set_order_status`); an order delivered (naming
`somcp_return_order_products`); an order paid or with payments (naming
`somcp_issue_standard_refund` and `somcp_issue_partial_refund`). The core's own
refusals are put in the same shape.

**Notes** - the button is shown only when merchandise returns are on
(`PS_ORDER_RETURN`) and the order is neither delivered nor paid nor with
payments; otherwise the same place shows **Standard refund** or **Return
products**. The form is `cancel_product`: per line a checkbox and
**Quantity**, nothing else - no credit slip, no voucher, since nothing was
paid.

The core's `CancelOrderProductHandler` does the work, built with `new` with
`OrderProductQuantityUpdater` (itself built from `OrderAmountUpdater`,
`OrderDetailUpdater`, `OrderProductRemover` and `ContextStateManager`), whose
constructors are the same on 8.0.1 and 9.0.0: per line the new quantity, the
recomputed totals and stock, `actionProductCancel` with the cancel action, and
when no line is left the status **Canceled** with its email, the token's
employee on the history row. The handler neither checks that a line belongs to
the order nor looks at merchandise returns: the tool does both. The lines are
read and checked as by `somcp_issue_standard_refund`.

**Differences between 8.0.1 and 9.0.0** - none in the handler or the form.

Not replayable: a lost answer is checked with `somcp_get_order`
(`products[].quantity`, `order.status`).

#### `somcp_add_order_product`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - adds one product, or one combination of a product, to one order
that has not been shipped, as **Add a product** in the **Products** block of
the order page does, and recomputes the order totals.

**Do not use for** - changing the quantity or price of a line already in the
order (→ `somcp_update_order_product`), removing one
(→ `somcp_delete_order_product`), creating an order (→ `somcp_create_order`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_order` | - | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | - | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_product` | **Search for a product** | integer | yes | from `somcp_search_products` |
| `id_product_attribute` | combination select | integer | when the product has combinations | from `combinations[].id_product_attribute` of `somcp_get_product`; refused when it is not of the product |
| `quantity` | **Quantity** | integer | yes | from 1 |
| `unit_price_tax_excl` | **tax excl.** | number | no | left out with the other price, the product's price for the order's customer, currency and delivery address |
| `unit_price_tax_incl` | **tax incl.** | number | no | given alone, the price tax excluded is worked out with the line's tax rate; both given, they must agree with it to the currency's precision; the former names `unit_price_tax_excluded` and `unit_price_tax_included` are refused as `renamed unit_price_tax_excl` / `renamed unit_price_tax_incl` |
| `id_order_invoice` | **Invoice** | integer | no | an invoice of the order, from `documents.invoices[].id_order_invoice` of `somcp_get_order`; left out on an invoiced order, a new invoice is made |
| `free_shipping` | **Free shipping** | boolean | no | only with a new invoice |

Prices are in the order's currency, as on the screen.

**Returns** - `added`, `order` (`id_order`, `reference`), `line` (the new
line exactly as `somcp_get_order` returns one of `products[]`), `invoice`
(`id_order_invoice` when the line went to one, `null` otherwise), `totals`
(exactly as `somcp_get_order` returns them) and `currency`.

**Errors** - every problem at once, nothing recorded when one check fails,
each as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields; the
order not found or a reference shared by several orders; `id_product`
missing or unknown in the shop; `id_product` or `id_product_attribute` given
but not a whole number above 0 (`<field>: a whole number above 0`, as in every
tool); `id_product_attribute` missing, unknown or not of the product;
`quantity` below 1; prices not numbers, below 0, or not agreeing with the tax
rate; `id_order_invoice` not an invoice of the order; `free_shipping` without
a new invoice; a shipped order; a product already in the order or in the
invoice (that line is changed instead); PrestaShop 9.2 or later, where the
order page adds the product together with its shipments (after the other
checks, pointing to **Add a product**). The core's refusals (not enough
stock with orders denied out of stock, minimum quantity) are put in the same
shape.

**Notes** - the core's `AddProductToOrderHandler` does the work, built with
`new` with `OrderAmountUpdater`, `OrderProductQuantityUpdater` and
`OrderDetailUpdater` (from `ContextStateManager`, `OrderProductRemover` and the
configuration), whose constructors are the same on 8.0.1 and 9.0.0: the cart,
the invoice and its free shipping cart rule, the order line, the prices given,
the totals and `actionOrderEdited`, the token's employee on the context. Their
internals differ between the versions, which is why they are called and never
copied.

**Differences between 8.0.1 and 9.0.0** - none in the form or the handler
sequence. PrestaShop 9.2 and later: refused, the handler there takes services
for the shipments that only the back office builds.

Not replayable: a second call adds the quantity again or is refused as a
duplicate. A lost answer is checked with `somcp_get_order` (`products`).

#### `somcp_update_order_product`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - changes the unit price, the quantity or the invoice of one product
line of an order that has not been delivered, as the **Edit** action of a line
in the **Products** block of the order page does, and recomputes the order
totals. A field left out keeps its current value; the values are absolute, not
a delta.

**Do not use for** - adding a product (→ `somcp_add_order_product`), removing a
line (→ `somcp_delete_order_product`), refunding or cancelling a quantity the
customer will not receive (→ `somcp_issue_partial_refund`,
`somcp_cancel_order_products`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_order` | - | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | - | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_order_detail` | the line | integer | yes | from `products[].id_order_detail` of `somcp_get_order`; must belong to the order |
| `quantity` | **Quantity** | integer | no | from 1; left out, unchanged |
| `unit_price_tax_excl` | **tax excl.** | number | no | left out, unchanged |
| `unit_price_tax_incl` | **tax incl.** | number | no | given alone, the price tax excluded is worked out with the line's tax rate; both given, they must agree with it to the currency's precision; the former names `unit_price_tax_excluded` and `unit_price_tax_included` are refused as `renamed unit_price_tax_excl` / `renamed unit_price_tax_incl` |
| `id_order_invoice` | **Invoice** | integer | no | moves the line to another invoice of the order, from `documents.invoices[].id_order_invoice` of `somcp_get_order`; left out, unchanged |

At least one of `quantity`, a price and `id_order_invoice`; a call that changes
nothing writes nothing and answers `changed: false` with a `reason`. Prices are
in the order's currency, as on the screen.

**Returns** - `changed`, `order` (`id_order`, `reference`), `changes` (one
entry per field changed: `field`, under the parameter name, its `old` and `new`
value, as the other update tools give them), `line`
(the edited line exactly as `somcp_get_order` returns one of `products[]`),
`totals` (exactly as `somcp_get_order` returns them) and `currency`.

**Errors** - every problem at once, nothing recorded when one check fails, each
as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields; the order
not found or a reference shared by several orders; `id_order_detail` missing or
not a line of the order; `quantity` 0 (pointing to `somcp_delete_order_product`)
or below; prices not numbers, below 0, or not agreeing with the tax rate;
`id_order_invoice` not an invoice of the order; no field to change given;
PrestaShop 9.2 or later, where the order page edits the line together with its
shipments (after the other checks, pointing to the **Edit** action of the order
page). Then the core's refusals, each in plain English: a delivered order, a
product no longer in the catalogue, not enough stock with orders denied out of
stock, the same product already in the target invoice.

**Differences between 8.0.1 and 9.0.0** - none in the form or the handler
sequence; the internals of `OrderProductQuantityUpdater` differ, which is why
they are called and never copied. PrestaShop 9.2 and later: refused, the
handler there takes services for the shipments that only the back office
builds.

Not replayable in general: the values are absolute, so the same call again
changes nothing. A lost answer is checked with `somcp_get_order` (`products`)
before anything else.

#### `somcp_delete_order_product`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - removes one product line from one order that has not been
delivered, as the **Delete** action of a line in the **Products** block of the
order page does, and recomputes the order totals. It is neither a cancellation
nor a refund: the line goes, with no credit slip and no voucher.

**Do not use for** - cancelling a quantity the customer paid for
(→ `somcp_cancel_order_products`), refunding (→ `somcp_issue_partial_refund`,
`somcp_issue_standard_refund`), changing a quantity
(→ `somcp_update_order_product`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_order` | - | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | - | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_order_detail` | the line, **Delete** | integer | yes | from `products[].id_order_detail` of `somcp_get_order`; must belong to the order |

**Returns** - `deleted`, `order` (`id_order`, `reference`), `line` (the line as
it was before the removal, exactly as `somcp_get_order` returns one of
`products[]`), `totals` (after the change, exactly as `somcp_get_order` returns
them) and `currency`.

**Errors** - every problem at once, nothing recorded when one check fails, each
as `<path>: <problem>` under "Nothing was recorded. Correct these and call again:": unknown fields; the order
not found or a reference shared by several orders; `id_order_detail` missing or
not a line of the order (`id_order_detail: no line of this order has the
identifier <n> - find it with somcp_get_order`). Then, each with its reason: the
last line of the order (the back office lets it go and leaves an order with no
product; pointing to `somcp_set_order_status` to cancel it), a delivered order,
PrestaShop 9.2 or later (after the other checks, pointing to the **Delete**
action of the order page, as the other order line tools do).

**Notes** - the core's `DeleteProductFromOrderHandler` does the work, built
with `new` with `ContextStateManager` and `OrderProductQuantityUpdater`, from
the same builder as the other order line tools
(`SomcpToolUpdateOrderProduct::services()`): the context set to the order, the
quantity brought to 0 on the line's invoice, the amounts and
`actionOrderEdited`, the token's employee on the context.

**Differences between 8.0.1 and 9.0.0** - none in the handler; the internals
of `OrderProductQuantityUpdater` differ, which is why they are called and never
copied. PrestaShop 9.2 and later: refused, like the other order line tools.

Not replayable: a second call on the same line finds nothing and says so. A
lost answer is checked with `somcp_get_order` (`products`).

#### `somcp_add_order_discount`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - adds one discount to one order - a percentage, an amount tax
included or free shipping - as **Add a discount** on the order page does, and
recomputes the order totals. It lowers what the customer owes.

**Do not use for** - a voucher a customer types at checkout
(→ `somcp_create_cart_rule`), removing a discount
(→ `somcp_delete_order_discount`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `name` | string | yes | the discount name the customer reads on the invoice; one plain text, stored in the shop's default language, not an object per ISO code; at most the length `CartRule` allows (254 on 8.0.1, the setting on 9.0.0) |
| `type` | string | yes | `percent`, `amount_tax_incl` (the amount includes taxes, as the screen says) or `free_shipping`; the former value `amount_tax_included` is refused with `type: renamed amount_tax_incl` |
| `value` | number | for `percent` and `amount_tax_incl` | the percentage (1 to 100) or the amount tax included; refused for `free_shipping` |
| `id_order_invoice` | integer | no | **Invoice**, only when the order has invoices; from `documents.invoices[].id_order_invoice` of `somcp_get_order`; must be one of this order's |

**Returns** - `added`, `order` (`id_order` and `reference`), `discount`
(`id_order_cart_rule`, `id_cart_rule`, `id_order_invoice`, `name`,
`value_tax_excl`, `value_tax_incl`, `free_shipping`), and the order
totals after the change under `totals` (as `somcp_get_order` gives them, with
`currency` beside, like the other order write tools). `discount` carries the keys of `discounts[]`
of `somcp_get_order` plus `id_cart_rule`, so the same discount reads the same
in both answers, and its `id_order_cart_rule` is what
`somcp_delete_order_discount` takes.

**Errors** - every problem at once, nothing recorded when one check fails, each
under "Nothing was recorded. Correct these and call again:": `name` missing, empty, too long or not clean;
`type` not one of the three; `value` missing for `percent` /
`amount_tax_incl`, given for `free_shipping`, at or below 0, above 100 for a
percent, or above the total for an amount; the order not
found or a reference shared by several orders; `id_order_invoice` given on an
order without invoices, or not an invoice of this order (naming
`somcp_get_order`). The core's own refusals are put in the same shape, with
what the core compared: an amount above the total tax included of the invoice
given, of the smallest invoice of the order when none is given (the amount must
fit each invoice), or of the order when it has no invoice, naming that invoice
by its `documents.invoices[].number` and giving its total; free shipping on an
invoice or an order whose shipping tax included is above its total tax
included, `type: free shipping cannot be added - the shipping of <the invoice
/ the order> is above its total tax included`. A failure inside the core,
which saves the cart rule, the cart and the order amounts with no transaction,
says that part of the change may have been saved and to read `discounts` with
`somcp_get_order` before trying again.

**Notes** - the discount is one line of **Add new cart rule**
(`add_order_cart_rule`): **Name**, **Type** (Percent, Amount, Free shipping),
**Value**, and **Invoice** when the order has invoices. The core's
`AddCartRuleToOrderHandler` does the work, built with `new` with
`OrderAmountUpdater` (itself from `Configuration`, `ContextStateManager`,
`OrderDetailUpdater` and `OrderProductRemover`), whose constructors are the
same on 8.0.1 and 9.0.0: a one-time inactive `CartRule` in the order's
currency, added to the order's cart, then the order, its lines and its invoice
recomputed on the order's context. The handler owns the amount ceiling against
the invoices, so the tool does not keep a second copy of it.

**Differences between 8.0.1 and 9.0.0** - none in the handler or the form.

Not replayable: two identical discounts are both applied. A lost answer is
checked with `somcp_get_order` (`discounts`).

#### `somcp_delete_order_discount`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - removes one discount from one order, as the delete action of the
discounts table (**Name**, **Value**, **Actions**) of the order page does, and
recomputes the order totals. It raises what the customer owes.

**Do not use for** - switching off a voucher for every customer
(→ `somcp_set_cart_rule_active`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_order_cart_rule` | integer | yes | from `discounts[].id_order_cart_rule` of `somcp_get_order`, or from the answer of `somcp_add_order_discount`; never guessed |

**Returns** - `deleted`, `order` (`id_order` and `reference`), `discount`
with the keys of `discounts[]` of `somcp_get_order` (`id_order_cart_rule`,
`id_order_invoice`, `name`, `value_tax_incl`, `value_tax_excl`,
`free_shipping`), `gift_line_updated` (the discount gave a gift product and
its line lost one unit), and the order totals after the change under
`totals` (as `somcp_get_order` gives them, with `currency` beside), as
`somcp_add_order_discount` returns them. A discount already
removed is `deleted: false` with a `reason`, and nothing happens.

**Errors** - every problem at once, nothing deleted when one check fails, each
under "Nothing was deleted. Correct these and call again:": a field the tool does not take; the order not
found or a reference shared by several orders; `id_order_cart_rule` missing or
not a whole number; `id_order_cart_rule: no discount of this order has the
identifier <n> - find it with somcp_get_order` when the row does not exist or
belongs to another order. The core's exceptions are put in the same shape.

**Notes** - the route (`admin_orders_remove_cart_rule`) asks for update on
`AdminOrders`, not delete: the discount is part of the order being edited. The
core's `DeleteCartRuleFromOrderHandler` does the work, built with `new` with
`OrderAmountUpdater` and `OrderProductQuantityUpdater`, whose constructors are
the same on 8.0.1 and 9.0.0: on the order's context, the `order_cart_rule` row
is soft-deleted, the rule leaves the cart, and either the gift line loses one
unit (which recomputes the order) or the amounts are recomputed.

**Differences between 8.0.1 and 9.0.0** - none.

Not replayable: a lost answer is checked with `somcp_get_order` (`discounts`):
`Order::getCartRules()` skips removed discounts, so one missing from
`discounts[]` was removed.

#### `somcp_set_order_address`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - points the delivery or the invoice address of one order to another
address of its customer, as **Select another address** of the **Shipping
address** and **Invoice address** blocks of the order page does, and lets the
core recompute the taxes and the totals. The country can change the tax, so the
totals before and after the change are reported.

**Do not use for** - correcting the content of an address (→ the address and
customer tools), or changing the carrier of the order.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `address_type` | enum | yes | `delivery` (**Shipping address**) or `invoice` (**Invoice address**), the two keys of `addresses` in `somcp_get_order` |
| `id_address` | integer | yes | an address of the order's customer, from `addresses[].id_address` of `somcp_get_customer`, or the list a refused call returns; never guessed |

**Returns** - `changed`, `order` (`id_order` and `reference`), `address_type`,
`previous_address` and `address`, each in the shape of `addresses.delivery` of
`somcp_get_order` built by the same function (`id_address`, `alias`,
`firstname`, `lastname`, `company`, `address1`, `address2`, `postcode`, `city`,
`state`, `country`, ...), `previous_totals` and `totals`, the order totals
before and after the change, each exactly as `somcp_get_order` returns `totals`
(built by the same function), and `currency`. The same address as now is
`changed: false` with a `reason`, and nothing happens.

**Errors** - every problem at once, nothing changed when one check fails, each
under "Nothing was changed. Correct these and call again:": a field the tool does not take; the order not
found or a reference shared by several orders; `address_type: one of delivery,
invoice`; `id_address` missing or not a whole number; `id_address: no address
of this customer has the identifier <n> - find it with somcp_get_customer` for
an unknown, deleted or other customer's address, followed in the same answer by
the list of the customer's addresses (`id_address`, `alias`, `address1`,
`postcode`, `city`, `country`). A failure inside the core is reported without
its raw message as "The address could not be changed. Read addresses with
somcp_get_order before trying again: part of the change may have been saved.":
both handlers save the cart before the order.

**Notes** - the delivery address is moved by the core's
`ChangeOrderDeliveryAddressHandler` (it also keeps the carrier's delivery
option and synchronises the gift lines), the invoice address by the lighter
`ChangeOrderInvoiceAddressHandler`; both are built with `new`, whose
constructors are the same on 8.0.1 and 9.0.0, and run on the order's context.

**Differences between 8.0.1 and 9.0.0** - none in the handlers; only the
wording of the options on screen (9.0.0 adds the alias).

Not replayable: a lost answer is checked with `somcp_get_order`
(`addresses.delivery.id_address`, `addresses.invoice.id_address`).

#### `somcp_set_order_carrier`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - changes the carrier of one shipment of one order, as the **Carrier**
field of the **Edit shipping details** window (Carriers tab of the order page)
does, and lets the core recompute the shipping cost and the order totals.

**Do not use for** - the tracking number (→ `somcp_set_order_tracking`), or the
address (→ `somcp_set_order_address`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_carrier` | integer | yes | one of the carriers the order may use, from `somcp_list_carriers` or the list a refused call returns; never guessed |
| `id_order_carrier` | integer | no | which shipment, `shipping.shipments[].id_order_carrier` of `somcp_get_order`; required only when the order has more than one |

**Returns** - `changed`, `order` (`id_order` and `reference`),
`previous_carrier` and `carrier` (`id_carrier`, `name`, as
`somcp_list_carriers` names them), `previous_shipment` and `shipment`, each in
the shape of one entry of `shipping.shipments` of `somcp_get_order` built by
the same function (its `date` ISO 8601 with the shop's offset), then
`previous_totals` and `totals`, the order totals before and after the change,
each exactly as `somcp_get_order` returns `totals` (built by the same
function), and `currency`. The carrier already in place is `changed: false` with a `reason`,
`carrier` and `shipment`, and nothing happens.

**Errors** - every problem at once, nothing changed when one check fails, each
under "Nothing was changed. Correct these and call again:": a field the tool does not take; the order not
found or a reference shared by several orders; `id_carrier` missing or not a
whole number; `id_order_carrier: required, this order has <n> shipments` or
`id_order_carrier: not a shipment of this order`, followed by the shipments
(`id_order_carrier`, `date`, `carrier`, `weight`, `tracking_number`);
`id_carrier: not a carrier this order can use - find one with
somcp_list_carriers`, followed by the carriers the order may use
(`id_carrier`, `name`, `delay`). A failure inside the core is reported without
its raw message, with the advice to read the order before trying again.

**Notes** - the core's `UpdateOrderShippingDetailsHandler` also saves the
tracking number of the form and may email the customer; the tool runs only its
carrier part, in the same steps (the cart's delivery option, the
`OrderCarrier`, the `Order`, then `OrderAmountUpdater::update()`) on the
order's language, and sends no email. The handler accepts any carrier; the
choices on screen come from `Carrier::getCarriersForOrder()` for the delivery
zone and the customer's groups, and the tool refuses any other.

**Differences between 8.0.1 and 9.0.0** - none in the handler; the option label
on screen adds the delay on 8.0.1 only.

Not replayable: a lost answer is checked with `somcp_get_order`
(`shipping.shipments[].id_carrier`).

#### `somcp_set_order_currency`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - changes the currency of one order that is not validated yet,
converting every amount, as **Change currency** in the **Payment** block of the
order page does.

**Do not use for** - a validated order, which the back office refuses too; the
exchange rates of the shop (**International > Localization > Currencies**, no
tool yet).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_order` | - | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | **Reference** | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `currency` | **Change currency** | string | yes | ISO code of an active currency of the shop, as `somcp_get_order` returns `currency`; refused otherwise, with the shop's active currencies |

**Returns** - `changed`, `order` (`id_order`, `reference`), `old_currency` and
`new_currency` (ISO codes), and `previous_totals` / `totals`, each exactly
as `somcp_get_order` returns `totals` (built by the same function).

**Errors** - every problem at once, nothing changed when one check fails, each
under "Nothing was changed. Correct these and call again:": a field the tool does not take; the order not
found or a reference shared by several orders (the text of
`SomcpToolGetOrder::find()`); `currency` missing, unknown or inactive, followed
by the shop's active currencies; a validated order, with the core's sentence
"You cannot change the currency." and the reason (its amounts are final). A
failure inside the conversion is reported without its raw message, with the
advice to read the order.

**Notes** - the tool runs the sequence of `ChangeOrderCurrencyHandler` (the
order lines, the order carrier, each invoice, the cart, then the order with its
`conversion_rate`), every amount converted with `Tools::convertPriceFull()` at
the shop's rates of today. The core lists `reduction_amount` twice and so
converts it twice; the tool converts it once. No hook is fired, as in the core.

**Differences between 8.0.1 and 9.0.0** - none in the handler nor in the form;
only the template line moves (`payments.html.twig` 128 / 131). The block shows
only when the order is not validated and the shop has another currency.

Replayable: the currency the order already has writes nothing and answers
`changed: false`, where the core refuses it. A lost answer is checked with
`somcp_get_order` (`currency`).

#### `somcp_resend_order_status_email`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - sends again the email of one past status of an order, as **Resend
email** on a line of the **Status** tab of the order page does. The status line
is taken from `history` of `somcp_get_order`; the status is read from the line
and the email is the one that status sends.

**Do not use for** - changing the status (→ `somcp_set_order_status`), or
writing to the customer (→ `somcp_add_order_message`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_order_history` | integer | yes | which status line to resend, `history[].id_order_history` of `somcp_get_order`; never guessed |

**Returns** - `sent`, `order` (`id_order` and `reference`), `history_line` in
the shape of one entry of `history` of `somcp_get_order` (`id_order_history`,
`date`, `status`, `status_id`, `employee`), and `template` (the status's email
template). A send that fails is `sent: false` with a `warning` and nothing
changed, and a `reason` that says where to look: the shop's email settings in
**Advanced Parameters > E-mail**, and the customer or the status's email
template when they could not be loaded. The core's own message never reaches
the answer: it goes to the module's log only.

**Errors** - every problem at once, nothing sent when one check fails, each
under "Nothing was sent. Correct these and call again:": a field the tool does not take; the order not found
or a reference shared by several orders; `id_order_history` missing or not a
whole number; `id_order_history: not a status line of this order - find it in
history of somcp_get_order`; `id_order_history: the status <name> sends no
email` when the status's `send_email` is 0.

**Notes** - the core path (`ResendOrderEmailCommand` then
`ResendOrderEmailHandler`) does not check that the history line belongs to the
order nor that its status matches; the tool takes the line only, checks it
belongs to the order and reads the status from it. When the status is
`PS_OS_SHIPPING` and the order carries a shipping number, `{followup}` is the
carrier URL with the number in place of the `@`, as the back office adds it.

**Differences between 8.0.1 and 9.0.0** - none.

Not replayable: every call emails the customer and the order records nothing;
the description tells the assistant to call it once.

#### `somcp_create_order`
**State**: written
**Nature**: `acts(ADD, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - creates one order for one existing customer, as **Orders > Orders >
Add new order** then **Create order** do: the cart lines, the **Delivery** and
**Invoice** addresses, the **Delivery option**, the **Shipping** options, the
**Currency** and **Language**, the **Order message**, the **Payment** and the
**Order status**. One call builds a new cart and validates it.

**Do not use for** - adding a product to an existing order (→
`somcp_add_order_product`), changing its status (→ `somcp_set_order_status`),
recording a payment on it (→ `somcp_add_order_payment`), creating a customer
(→ `somcp_create_customer`), creating a voucher (→ `somcp_create_cart_rule`),
a discount on an order already placed (→ `somcp_add_order_discount`), a price
for a customer or group on every order (→ `specific_prices` of
`somcp_update_product`), changing the price of a line of an existing order (→
`somcp_update_order_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_customer` | integer | yes | **Customer**, from `somcp_search_customers`; a customer of the token's shop, not deleted |
| `products` | array, 1 or more | yes | **Cart**: each line `id_product` (from `somcp_search_products`), `id_product_attribute` (from `combinations[].id_product_attribute` of `somcp_get_product`, required when the product has combinations) `quantity` (from 1, at least the minimal quantity) and `unit_price_tax_excl` (**Price**, from 0, tax excluded as the screen says, in the cart's currency; left out, the price the shop computes; the suffix of every other price, the former name `unit_price_tax_excluded` refused as `renamed unit_price_tax_excl`); the same product and combination twice is refused |
| `id_address_delivery` | integer | yes | **Addresses > Delivery**, from `addresses[].id_address` of `somcp_get_customer`; an address of this customer |
| `id_address_invoice` | integer | no | **Addresses > Invoice**, same; the delivery address when left out |
| `id_carrier` | integer | yes unless every product is virtual | **Shipping > Delivery option**: one of the carriers the cart is offered for this address and these products; refused otherwise, with those offered (`id_carrier`, `name`, `shipping_tax_incl`) |
| `free_shipping` | boolean | no, default false | **Free shipping**: the cart rule the screen creates (`CartRule::BO_ORDER_CODE_PREFIX` + cart id) |
| `recycled_packaging` | boolean | no, default false | **Recycled packaging** |
| `gift` | boolean | no, default false | **Gift** |
| `gift_message` | string | no | **Gift message**, only with `gift: true`; plain text |
| `currency` | string | no | **Currency**: ISO code of an active currency; the shop default when left out |
| `language` | string | no | **Language**: ISO code of one of the shop's active languages, refused otherwise; the shop default when left out. The order emails go out in it |
| `message` | string | no | **Order message**; plain text |
| `payment_module` | string | yes | **Payment**: technical name of an installed, active payment module; refused otherwise, with the choices as `name` and `display_name` |
| `status_id` | integer | yes | **Order status**, from `somcp_list_order_statuses`; a deleted status is refused |
| `cart_rules` | array of integers | no | **Vouchers**: `id_cart_rule` of each voucher, from `somcp_list_cart_rules` or `somcp_get_cart_rule`; each checked with `CartRule::checkValidity()` once the products, addresses and carrier are on the cart, as the screen checks it then, and refused with the shop's reason when it does not apply; the same voucher twice refused |

**Returns** - `created: true`, `id_order`, `reference`, `id_cart`, `status`
(`status_id`, `name`), `payment` (the payment name the order records),
`products`, the lines as the order records them in the keys of `products[]` of
`somcp_get_order` (`unit_price_tax_excl`, `unit_price_tax_incl`,
`total_tax_excl` ...), `totals` in the shape of `totals` of
`somcp_get_order` (the vouchers in
`discounts_tax_incl` and `discounts_tax_excl`), `discounts`, each
voucher applied in the keys of `discounts[]` of `somcp_get_order` (the free
shipping cart rule included), and `currency`.

**Errors** - under "Nothing was recorded. Correct these and call again:", every problem at once, each named
with its field (`products[1].quantity: ...`): a field the tool does not take;
the customer; each line (product of the shop, available for order, required
customization fields, `id_product` or `id_product_attribute` given but not a
whole number above 0 (0 is no combination), combination, minimal quantity,
stock when the product
cannot be ordered out of stock, `unit_price_tax_excl` not a number of 0 or
more); the addresses (with the customer's addresses
listed) and their country; the currency; the language; the messages; the
payment module; the status; `cart_rules` not a list, an entry that is not an
identifier, a voucher the shop does not have, the same voucher twice. Then,
once the cart exists: a quantity the cart refuses, a line price the shop
refuses, the carrier, and each
voucher that does not apply, `cart_rules[<n>]: <the shop's reason>` in the
order's language, and a free shipping the shop could not save. A call refused at that point deletes the cart, the prices
set for its lines and the free shipping cart rule it made.

**Notes** - a new cart every call, never the customer's last empty cart as the
screen takes: it may be open on the storefront. The screen's handlers need the
Symfony container, so the tool runs their sequence with the legacy calls they
wrap (`Cart::updateQty()`, `Cart::setDeliveryOption()`, `CartRule`, `Message`,
the `SpecificPrice` of `UpdateProductPriceInCartHandler` for a line given a
price,
`PaymentModule::validateOrder()`), the context set to the cart and restored
afterwards. The employee of the token goes into the `Manual order -- Employee:`
message, as the button does. In catalog mode the order is recorded through the
core's back office payment, as the handler does. `validateOrder()` sends the
emails a new order sends for that status. Multistore: the customer, addresses,
products and combinations, carrier and payment module are checked against the
token's shop. Prices are the ones the cart
computes for this customer, currency and address, except a line given
`unit_price_tax_excl`: as the **Price** field of the **Cart** block, it is
a specific price tied to the cart (`id_cart`) and its customer, for every shop,
currency, country and group, written after the line is on the cart and before
the carrier and the vouchers are weighed. A price above the shop's price is
allowed, as on the screen.

**Differences between 8.0.1 and 9.0.0** - none for the tool: same blocks and
handler sequence; the page is titled **Create order** on 8.0.1 and **New
order** on 9.0.0, and the **Payment** and **Order status** choices depend on the
shop.

Not replayable: a second call creates a second order. On a lost answer, look
for the order with `somcp_search_orders` (this customer, today), then
`somcp_get_order`.

#### `somcp_list_return_statuses`
**State**: written
**Nature**: `acts(VIEW, 'AdminStatuses')` · **Scope**: `mcp.orders.read`

**Does** - lists the return statuses of this shop: the steps a merchandise
return goes through, each with its identifier, its name, its colour and
whether it can be removed.

**Do not use for** - order statuses (→ `somcp_list_order_statuses`): a return
status is not an order status, and its identifiers are never order status
identifiers.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - per status: `id_order_return_state`, `name`, `color`, `removable`
(false for the five built-in statuses, 1 to 5). Plus `count` and `language`
(the ISO code the names are in).

**Notes** - read with `OrderReturnState::getOrderReturnStates($idLang)`. The
five statuses every shop starts with cannot be deleted, on PrestaShop 8 or 9:
8.0.1 skips Delete on 1 to 5, 9.0.0 computes the same rows as unremovable.
Same table and same rows on both versions.

#### `somcp_create_return_status`
**State**: written
**Nature**: `acts(ADD, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - creates one return status: a step a merchandise return goes
through, with its name in the languages being filled and its colour.

**Do not use for** - the statuses of an order (→ `somcp_list_order_statuses`
lists them, `somcp_create_order_status` creates one):
a return status is not an order status, and its identifiers are never order
status identifiers.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `languages` | array of strings | no | ISO codes of the languages to fill; all the shop's active languages by default |
| `name` | object by ISO code | yes | the name of the step, one text per language being filled; a language of the shop left out of `languages` takes the default language's text |
| `color` | string | no | the colour the status is highlighted in, a hex code `#RRGGBB` |

**Returns** - `created`, `id_order_return_state`, and `status`, the status as
`somcp_list_return_statuses` lists it.

**Notes** - the **Add new return status** form of **Shop Parameters > Order
Settings > Statuses**, read in full on 8.0.1 and 9.0.0: two fields, the name
and the colour, and no difference between the versions. The name follows the
rules of `somcp_create_feature`: required in the default language - every
language being filled - `Validate::isGenericName()`, 64 characters at most.
A name another return status already has in that language is refused, so the
merchant can tell the steps apart; PrestaShop itself accepts the duplicate.
The colour is checked as `somcp_create_attribute_value` checks a swatch -
`#RRGGBB` only, because `Validate::isColor()` alone lets a bare word
through; left out, the status is stored without a colour, as the form
allows. Written through `OrderReturnState::add()`, as 8.0.1
(`AdminStatusesController`) and 9.0.0 (`AddOrderReturnStateHandler`) do.
Return statuses are not tied to a shop: the table has no shop column. Every
problem is reported at once, before anything is written. Not replayable: a
lost answer is checked with `somcp_list_return_statuses`.

#### `somcp_update_return_status`
**State**: written
**Nature**: `acts(EDIT, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - changes the name or the colour of one return status, as its **Edit** form on **Shop Parameters > Order Settings > Statuses** does. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_return_status`), or the statuses of an order: a return status is not an order status, and its identifiers are never order status identifiers.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order_return_state` | integer | yes | from `somcp_list_return_statuses`, never guessed |
| `languages` | array of strings | no | ISO codes of the languages to fill; all the shop's active languages by default |
| `name` | object by ISO code | no | the new name of the step, one text per language being filled; a language left out of `languages` keeps its text |
| `color` | string | no | the colour the status is highlighted in, a hex code `#RRGGBB`; `""`, as `somcp_list_return_statuses` reads a status without one, is accepted on such a status and changes nothing, and still refused on a status that has a colour (the form cannot remove one) |
| `removable` | boolean | no | as `somcp_list_return_statuses` returns it, accepted unchanged so a row read there can be given back whole; a different value is refused as `removable: worked out by the shop - the five statuses every shop starts with cannot be deleted` |

**Returns** - `changed`, `id_order_return_state`, `changes` (per field its old and new value, a name per language: `name (en)`), and `status`, the status as `somcp_list_return_statuses` lists it. A call that would change nothing writes nothing and answers `changed: false`, with empty `changes` and a `reason`.

**Notes** - the **Edit** form of a return status, read in full on 8.0.1 and 9.0.0: the same two fields as the **Add new return status** form, and no difference between the versions. The name follows the rules of `somcp_create_return_status`: required in every language being filled, `Validate::isGenericName()`, 64 characters at most. A name another return status already has in that language is refused, so the merchant can tell the steps apart; the status's own name is not a duplicate. The colour is checked as at creation, by the same function as the order status and attribute value colours - `#RRGGBB` only. Every return status can be edited, the five built-in ones (1 to 5) included, on both versions. Written through `OrderReturnState::update()`, as the back office edit does. Return statuses are not tied to a shop: the table has no shop column. Every problem is reported at once, before anything is written. Not replayable: a lost answer is checked with `somcp_list_return_statuses`.

#### `somcp_delete_return_status`
**State**: written
**Nature**: `acts(DELETE, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - deletes one return status the merchant created, as the **Delete** action of the return status list of **Shop Parameters > Order Settings > Statuses** does.

**Do not use for** - changing one (→ `somcp_update_return_status`), or the statuses of an order: a return status is not an order status, and its identifiers are never order status identifiers.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order_return_state` | integer | yes | from `somcp_list_return_statuses`, never guessed |

**Returns** - `deleted`, `id_order_return_state`, `name`, the name in the default language of the token's shop, and `language`, its ISO code.

**Notes**
- Irreversible: the description tells the assistant to read the status with `somcp_list_return_statuses` and have the merchant confirm it by name before the call.
- The row is really deleted on both versions (`OrderReturnState::delete()`: 8.0.1 `AdminStatusesController.php` line 602; 9.0.0 `DeleteOrderReturnStateHandler`), unlike an order status.
- The five statuses every shop starts with (1 to 5) are refused: neither back office offers **Delete** on them (8.0.1 `addRowActionSkipList('delete', [1, 2, 3, 4, 5])`; 9.0.0 `DeleteOrderReturnStatesAccessibilityChecker` reading `unremovable`), and neither handler checks it, so the tool refuses them itself, before the question of use.
- A status a merchandise return still stands on (`order_return.state`) is refused, with the number of returns: deleting it would leave those returns with a step that no longer exists, which neither version checks.
- Permission `delete` on `AdminStatuses`: the right the back office checks on its delete action for a return status.
- Only a whole number is an identifier. An unknown identifier is refused like in the other tools, pointing to `somcp_list_return_statuses`. Whether the status is gone is read back rather than inferred from `delete()`: a second call is refused, never read as a success.
- Not replayable: the read tool to check is `somcp_list_return_statuses`.

### Billing

The accounting records an order produces: invoices, credit slips, delivery
slips. They follow from an order without being the order.

**Not here**: the amounts of an order, which belong to the order itself
(→ Orders).

#### `somcp_generate_order_invoice`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - generates the invoice of one order that has none yet, as the
**Generate invoice** button of the **Documents** tab of the order page does:
the invoice takes the shop's next number and prefix, and the payments without
invoice are tied to it. Nothing is emailed.

**Do not use for** - reading invoices (→ `somcp_get_order`, `documents.invoices`),
or sending one to the customer (→ `somcp_set_order_status` with a status that
attaches the invoice).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |

**Returns** - `generated`, `order` (`id_order` and `reference`), `invoice`
(`id_order_invoice`, `number` formatted with the prefix for the order's shop as
`payments[].invoice_number` of `somcp_get_order`, `date` in ISO 8601 with the
shop's offset, `total_paid_tax_excl`,
`total_paid_tax_incl`, `currency`). When the order already has an invoice:
`generated: false` with a `reason` and the existing invoice, and nothing
happens.

**Errors** - every problem at once, nothing generated when one check fails,
each as `<path>: <problem>` under "Nothing was generated. Correct these and call again:": unknown fields;
neither `id_order` nor `reference`, the order not found, or a reference shared
by several orders; invoices off (naming **Orders > Invoices**, **Invoice
options > Enable invoices**). An exception of the core is put in the same
shape, never sent raw.

**Notes**
- The core path is the same on 8.0.1 and 9.0.0: `GenerateInvoiceHandler` refuses when invoices are off for the order's shop (`PS_INVOICE`) or the order already has one (`Order::hasInvoice()`), then calls `Order::setInvoice(true)`. The tool makes the same two checks, as readable refusals, then the same call.
- The button only shows on an order with no document at all (no invoice, no delivery slip, no credit slip) and invoices on (`documents.html.twig`); the handler only refuses an order that already has an invoice. The tool follows the handler, so it can generate the invoice of an order that has a credit slip but no invoice, which the button does not offer.
- Not replayable, even with the guard: a lost answer is checked with `somcp_get_order` (`documents.invoices`).
- No difference between 8.0.1 and 9.0.0.

#### `somcp_set_invoice_note`
**State**: written
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - sets the note printed on one invoice of an order, as **Add note** /
**Edit note** on an invoice line of the **Documents** tab of the order page
does. The note is on the invoice PDF the customer receives. An empty note
clears it.

**Do not use for** - the order's private note the shop alone reads
(→ `somcp_set_order_note`), a message to the customer (→ `somcp_add_order_message`),
or generating the invoice (→ `somcp_generate_order_invoice`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `id_order_invoice` | integer | yes | the invoice, from `documents.invoices[].id_order_invoice` of `somcp_get_order` |
| `note` | string | yes | the whole note, trimmed; `""` clears it; no HTML; the length limit is read from the model, when the version has one |

**Returns** - `changed`, `order` (`id_order`, `reference`), `invoice`
(`id_order_invoice`, `number` as `documents.invoices[].number` of
`somcp_get_order`), `old` and `new` note. The same note again writes nothing
and answers `changed: false`.

**Errors** - every problem at once, nothing changed when one check fails, each
as `<path>: <problem>` under "Nothing was generated. Correct these and call again:": unknown fields; neither
`id_order` nor `reference`, or the order not found; `id_order_invoice` missing
or not an invoice of this order; `note` missing, holding HTML (`isCleanHtml`),
or longer than the limit.

**Notes**
- The core path is the same on 8.0.1 and 9.0.0 (`UpdateInvoiceNoteHandler`: load the `OrderInvoice`, set `note`, `save()`). The core guard (`!isLoadedObject() && isCleanHtml()`) lets an unknown invoice with a dirty note through, so the tool checks instead that the invoice exists and belongs to the order, and that the note passes `Validate::isCleanHtml()`.
- The length limit is read from `OrderInvoice::$definition['fields']['note']['size']`: present on 9.0.0 (`LIMIT_MEDIUMTEXT_UTF8_MB4`, 4194303), absent on 8.0.1, where no limit is checked. The constant is never named in the code.
- `somcp_get_order` returns the note of each invoice under `documents.invoices[].note`, so the change can be read back.
- Not replayable: a lost answer is checked with `somcp_get_order` (`documents.invoices[].note`).

#### `somcp_get_order_document`
**State**: written
**Nature**: `acts(VIEW, 'AdminOrders')` · **Scope**: `mcp.orders.read`

**Does** - finds one PDF of an order - an invoice, a delivery slip or a credit
slip - as **View invoice**, **View delivery slip** and the document lines of the
**Documents** tab of the order page open it, and answers with its
`download_ref`. The tool builds no file and no link: `somcp_download_file`
turns the reference into the file, or a page for the merchant.

**Do not use for** - generating an invoice that does not exist (→
`somcp_generate_order_invoice`), emailing a document (a status that attaches
it, → `somcp_set_order_status`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier, from `somcp_search_orders` or `somcp_get_order` |
| `reference` | string | one of the two | reference; one shared by several orders is refused with their identifiers |
| `document` | enum | yes | `invoice`, `delivery_slip`, `credit_slip` |
| `id_order_invoice` | integer | no | which invoice or delivery slip, from `documents.invoices` or `documents.delivery_slips` of `somcp_get_order`; may be left out when the order has only one |
| `id_order_slip` | integer | no | which credit slip, from `documents.credit_slips` of `somcp_get_order`; may be left out when the order has only one |

**Returns** - `order` (`id_order`, `reference`), `document`, `id_order_invoice`
(invoice and delivery slip) or `id_order_slip` (credit slip) with the value
`somcp_get_order` gives, `number` and `date` as `somcp_get_order` gives them,
`download_ref`, `expires_at` (when the reference stops working, ISO 8601 with
the shop's offset) and `then` (`somcp_download_file`).

**Errors** - under "Nothing was read.", every problem at once, each named with
its field: a field the tool does not take; `document` missing or unknown;
`id_order_slip` with an invoice or a delivery slip, `id_order_invoice` with a
credit slip; the order not found or a reference shared by several orders. Then:
a kind the order does not have (`Order 12 has no delivery slip. Its documents:
invoice #FA000003 (id_order_invoice 3). Read them with somcp_get_order.`), a
document of another order or kind, and several documents of the kind with none
chosen (`Give id_order_invoice to choose one.`).

**Notes** - the reference comes from `SomcpDownload::reference()`, signed for
the token's employee and shop, and expires after 15 minutes. Nothing is built
until `somcp_download_file` is called with it.

**Differences between 8.0.1 and 9.0.0** - none for the tool: the order lookup
and the document objects are the same.

### Carts

What is not an order yet, abandoned carts included.

**Not here**: placed orders (→ Orders).

_No tool described yet._

### Configuration

What a tool needs so it does not have to guess an identifier: statuses,
carriers, tax rules, customer groups. Currencies, languages and countries go
by their ISO code, which the merchant already knows.

These are called **before** a write, never for their own sake. Identifiers differ
from one shop to the next, and an assistant that guesses gets the most dangerous
action wrong.

#### `somcp_list_order_statuses`
**State**: written
**Nature**: `acts(VIEW, 'AdminStatuses')` · **Scope**: `mcp.orders.read`

**Does** - lists the order statuses of this shop with their identifiers, the role
each one plays in the shop, and what each one sets off: paid, shipped, in transit,
invoice, whether it emails the customer and what that email carries.

**Do not use for** - reading orders, or counting them by status (→
`somcp_search_orders`, which takes a `status_id` and returns `total_count`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise; the shop default when left out; given back as `language` in the answer |

**Returns** - per status: `status_id`, `name`, `roles`, `paid`, `shipped`,
`in_transit`, `sends_email`, `invoice`, `counts_as_valid`, `hidden_from_customer`,
`attaches_invoice_pdf`, `attaches_delivery_slip_pdf`, `built_in`,
`email_template`, `payment_module`, `color`, `icon`. Plus `count` and `language`
(the ISO code used). `in_transit` is the box 9.0.0 labels **Set the order as in
transit** and 8.0.1 **Show delivery PDF**; it is not the `delivered` role.
`icon` is the status icon (`img/os/<status_id>.gif`) as a `download_ref` for
`somcp_download_file`, `null` when there is none. An unknown `language` is refused (#149).

**Notes** - in Configuration, not in Orders: an order uses a status without
containing one. Every field is a real column of `order_state`, checked against
the class after a first draft invented a `refunds` one.

`roles` is the point of the tool. PrestaShop records which status plays which
part in its own configuration - fourteen keys, the same on 8.0.0 and 9.1.5 - and
that is the only reliable way to find one: the name is translated and the
merchant can rename it. Without it, an assistant asked to mark an order shipped
guesses from a label. A list rather than one value, because
`PS_OS_OUTOFSTOCK` and `PS_OS_OUTOFSTOCK_UNPAID` land on the same status in a
default install. Empty for a status the merchant or a module created (a
`ps_checkout` status has no `PS_OS_*` key; `payment_module` says which module).

No count of orders per status here, on purpose. It would hand order data to an
account holding only the right to see the statuses page, and the count already
exists in `somcp_search_orders`, where the permission is the right one.

`deleted` was returned and always false: `OrderState::getOrderStates()` filters
them out before we see them. Removed rather than left lying.

#### `somcp_create_order_status`
**State**: written
**Nature**: `acts(ADD, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - creates one order status, with every field of the **Add new order
status** form: the name and the email template per language, the colour, the
GIF icon, and the nine boxes that say what reaching the status sets off.

**Do not use for** - changing a status (→ `somcp_update_order_status`), giving
an order a status (→ `somcp_set_order_status`), or the steps of a merchandise
return (→ `somcp_create_return_status`): a return status is not an order
status.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `languages` | array of strings | no | ISO codes of the languages to fill; all the shop's active languages by default |
| `name` | object by ISO code | yes | the name of the status, one text per language being filled; a language of the shop left out takes the default language's text |
| `color` | string | no | the colour the status is highlighted in, a hex code `#RRGGBB` |
| `icon` | object | no | the icon, a GIF: `url` (a public https address the shop downloads) or `upload_ref` (a file already on the shop), one of the two; a refused icon leaves the status created without one |
| `counts_as_valid` | boolean | no | **Consider the associated order as validated.**; off by default |
| `invoice` | boolean | no | lets the customer download and view PDF versions of their invoices; off by default |
| `hidden_from_customer` | boolean | no | **Hide this status in all customer orders.**; off by default |
| `sends_email` | boolean | no | emails the customer when their order reaches this status; off by default |
| `attaches_invoice_pdf` | boolean | no | attaches the invoice PDF to that email; off by default |
| `attaches_delivery_slip_pdf` | boolean | no | attaches the delivery slip PDF to that email; off by default |
| `shipped` | boolean | no | sets the order as shipped; off by default |
| `paid` | boolean | no | sets the order as paid; off by default |
| `in_transit` | boolean | no | sets the order as in transit - the box 8.0.1 labels **Show delivery PDF**; off by default |
| `email_template` | object by ISO code | no | the mail template of the status email, one per language being filled; only stored with `sends_email` |

The boxes and the template take the names `somcp_list_order_statuses`
returns, so a status read there can be given back. The former names
(`logable`, `hidden`, `send_email`, `pdf_invoice`, `pdf_delivery`,
`template`) are still accepted; a former name next to its current one is
refused.

**Returns** - `created`, `status_id`, `status` (the status built by the
row function of `somcp_list_order_statuses`, `status_id` and `icon`
included) and, when an icon was given, `icon`: its `url` or `upload_ref`,
with `stored: true` or `refused: <reason>`.

**Notes** - the **Add new order status** form of **Shop Parameters > Order
Settings > Statuses**, read in full on 8.0.1 and 9.0.0. The name follows the
rules of `somcp_create_feature`: required in the default language - every
language being filled - `Validate::isGenericName()`, 64 characters at most.
A name another order status already has in that language is refused, so two
statuses can never wear one label and be mistaken by the merchant or by
`somcp_set_order_status` called with a name: 8.0.1 refuses it
(`OrderState::existsLocalizedNameInDatabase()`), the 9.0.0 handler does not
call the check, and the tool refuses it on both. The colour is checked as
`somcp_create_attribute_value` checks a swatch. A template must be an email
the status can send in its language: `OrderHistory::sendEmail()` sends it
with no module, and `Mail::getTemplateBasePath()` looks for
`<iso>/<template>.html` or `.txt` in the theme's `mails/`, the parent theme's,
then the shop's `mails/`, the same on both versions - the tool reads those
three folders. The emails of modules are refused with the reason: a status
set to one would send the customer nothing. With `sends_email` off the
templates are stored empty, as 8.0.1 blanks them.
The icon is a GIF only, as the form says, fetched as the product images are -
`url` downloaded by the shop with its guards, `upload_ref` redeemed from the
shop's deposits - and stored as `img/os/<id>.gif` as it arrived, as the 9.0.0
`OrderStateFileUploader` moves it. It is written after the status exists, so
a refused icon leaves the status without one and says so. `module_name`,
`unremovable` and `deleted` are not on the form and are never written.
Written through `OrderState::add()`. Order statuses are not tied to a shop:
the table has no shop column. Every problem is reported at once, before
anything is written. Not replayable: a lost answer is checked with
`somcp_list_order_statuses`, and a second call would be refused on the name
anyway.

**Differences between 8.0.1 and 9.0.0** - the **Template** list of the form:
8.0.1 lists `mails/<iso>/` and the theme's folder, 9.0.0 the layouts of the
mail theme, the emails of modules included (41 against 34 files in
`mails/en/`). The tool accepts the same templates on both, the ones the
status email can send, and refuses the module emails 9.0.0 lists. The empty
9.0.0 form fills in `#ffffff` for the colour where 8.0.1 leaves it empty; the
tool stores an empty colour on both when none is given.

#### `somcp_update_order_status`
**State**: written · [issue #142](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/142)
**Nature**: `acts(EDIT, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - changes one order status: any field of its **Edit** form on
**Shop Parameters > Order Settings > Statuses**, under the names and rules of
`somcp_create_order_status`. Only what is given changes.

**Do not use for** - giving an order a status (→ `somcp_set_order_status`),
creating one (→ `somcp_create_order_status`), deleting one
(→ `somcp_delete_order_status`), or a return status
(→ `somcp_update_return_status`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `status_id` | integer | yes | from `somcp_list_order_statuses`, never guessed |
| `languages` | array of strings | no | ISO codes of the languages whose texts are given; all the shop's active languages by default; a language left out keeps its texts |
| `name` | object by ISO code | no | the name of the status, one text per language being filled |
| `color` | string | no | a hex code `#RRGGBB` |
| `icon` | object | no | a GIF replacing the current icon: `url` or `upload_ref`, one of the two; not with `remove_icon` |
| `remove_icon` | boolean | no | `true` deletes `img/os/<id>.gif`; not with `icon` |
| `counts_as_valid`, `invoice`, `hidden_from_customer`, `sends_email`, `attaches_invoice_pdf`, `attaches_delivery_slip_pdf`, `shipped`, `paid`, `in_transit` | boolean | no | the nine boxes of the form, as in `somcp_create_order_status` |
| `email_template` | object by ISO code | no | the mail template per language; only when `sends_email` is or becomes true |

The former names `somcp_create_order_status` still accepts (`logable`,
`hidden`, `send_email`, `pdf_invoice`, `pdf_delivery`, `template`) are
accepted here too.

`roles`, `built_in` and `payment_module`, read with
`somcp_list_order_statuses`, are worked out by the shop and never written:
given with the values the list gives for this status they are no change, so
a row read there can be given back whole; a different value is refused with
`<field>: worked out by the shop, so it is not written here`.
`somcp_create_order_status` refuses the three whatever their value.
A whole row of the list can therefore be given back unchanged: an empty
`color` is no change on a status that has no colour (refused on one that
has), and `name` or `email_template` given as one text, as the list gives
them, is no change when it is the stored text of one of the shop's
languages; a different single text stays refused on a shop with several
languages, which needs one object keyed by ISO code. The test scenario
changes built-in status 2 rather than a status it creates, since a created
status gets a different identifier on 8 and 9.

**Returns** - `changed`, `status_id`, `changes` (each field with `old` and
`new`; an icon is recorded `old: "stored"` when it replaced one, `null`
when the status had none), `icon` when one was given or removed (`stored:
true`, `unchanged: true`, `removed: true` or `refused: <reason>`), then
`status`, the status as
`somcp_list_order_statuses` lists it, built by its row function. A call that
would change nothing writes nothing and answers `changed: false` with
`reason` and `status`.

**Notes** - the **Edit** form of a status, read in full on 8.0.1 and 9.0.0,
the same form as **Add new order status**. Every status can be edited,
including the 14 of PrestaShop and those of modules: `unremovable` only
forbids deleting them. 8.0.1 edits the row in place, so the identifier does
not change. The tool extends `somcp_create_order_status` and calls its
checks: the name rules (`Validate::isGenericName()`, 64 characters at most)
and the name another status already has in that language, refused on both
versions as at creation - the status's own name is no clash; the colour; the
icon, a GIF stored as `img/os/<id>.gif`; the templates, an email the status
can send in its language. A template given while `sends_email` stays off is
refused; turning `sends_email` off empties the templates in every language,
as 8.0.1 blanks them. A deleted status (`deleted` = 1) is refused like an
unknown one, by the same test as `somcp_delete_order_status`. A given icon
is compared with the stored `img/os/<id>.gif` first: the same file is no
change, nothing is written and nothing is recorded; another one is written
after the fields, and a refused icon leaves the current one and says so. `module_name`, `unremovable` and `deleted` are never written.
Written through `OrderState::update()`. Every problem is reported at once,
before anything is written. Not replayable: a lost answer is checked with
`somcp_list_order_statuses`.

**Differences between 8.0.1 and 9.0.0** - as for creation. 8.0.1 refuses a
name another status already has ("This name already exists."), the 9.0.0
handler does not: the tool refuses it on both. Changing **Set the order as
paid.** or **Set the order as shipped.** on a status already used changes no
past order on either version.

#### `somcp_delete_order_status`
**State**: written
**Nature**: `acts(DELETE, 'AdminStatuses')` · **Scope**: `mcp.orders.write`

**Does** - deletes one order status the merchant created, as the **Delete** action of the order status list of **Shop Parameters > Order Settings > Statuses** does.

**Do not use for** - the statuses of a return (→ `somcp_delete_return_status`): a return status is not an order status, and its identifiers are never order status identifiers; hiding a status from customers (→ `somcp_update_order_status` with the **Hide this status in all customer orders.** box, `hidden_from_customer`): the status stays in the list and on the orders, only the customer stops seeing it.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `status_id` | integer | yes | from `somcp_list_order_statuses`, never guessed |

**Returns** - `deleted`, `status_id`, `name` (the name in the shop's default language, read before the deletion), `language` (the ISO code of that name) and `orders_with_it`, how many orders of the shop still have it as their current status.

**Notes**
- The deletion is soft on both versions, unlike a return status: the row stays, `deleted` is set to 1 through `OrderState::update()` (as the 9.0.0 `DeleteOrderStateHandler` does), so the orders that had it keep their history and their current status, and `somcp_get_order` still shows its name in `history[]`. The icon file is kept.
- Once deleted it leaves `OrderState::getOrderStates()`, so it disappears from the list and from the choices of `somcp_set_order_status`.
- The 14 statuses of PrestaShop and the ones modules add are `unremovable`, and neither back office offers **Delete** on them (the rows `somcp_list_order_statuses` reports with `built_in: true`); the tool refuses them itself, before the question of use, as one `status_id` line of the shared refusal with the status name and the 8.0.1 back office sentence: `- status_id: order status <n> (<name>) is a built-in status of PrestaShop or of a module. For security reasons, you cannot delete default order statuses.`
- Permission `delete` on `AdminStatuses`: the right the back office checks on its delete action for an order status; edit alone does not allow it.
- Only a whole number is an identifier (`SomcpTool::id()`). An unknown identifier, or one already deleted, is refused pointing to `somcp_list_order_statuses`, never read as a success. Whether the status is gone is read back rather than inferred from `update()`.
- Not replayable: the read tool to check is `somcp_list_order_statuses`.
- A working deletion is checked by `dev/shop-tests/run.php` rather than the JSON scenario: the status it deletes is created first by `somcp_create_order_status`, and its identifier differs from one version to the other.

**Differences between 8.0.1 and 9.0.0** - 8.0.1 deletes through `AdminStatusesController::postProcess()` and refuses a default status with "For security reasons, you cannot delete default order statuses."; 9.0.0 deletes through `DeleteOrderStateHandler`, without that sentence. The tool gives the sentence on both, and the result (`deleted` set to 1) is the same.

#### `somcp_list_carriers`
**State**: written · [issue #20](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/20)
**Nature**: `acts(VIEW, 'AdminCarriers')` · **Scope**: `mcp.orders.read`

**Does** - lists the carriers of this shop as **Shipping > Carriers** shows them, enabled or not, with the tracking URL of each.

**Do not use for** - the carrier and tracking number of one order (→ `somcp_get_order`), changing a tracking number (→ `somcp_set_order_tracking`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | the **Status** filter; left out, every carrier, since a disabled one can still be tied to a product |
| `is_free` | boolean | no | the **Free shipping** filter; left out, both |
| `name` | string | no | part of the carrier name (the **Name** filter of the list), case-insensitive; left out, every carrier |
| `language` | string | no | for `delay`, which is translated: ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

**Returns** - per carrier, in the order of the list (`position`, then `id_carrier`): `id_carrier` (**ID**, what `shipping.carriers` of the product tools takes), `id_reference`, `name` (**Name**; the carrier stored as `0` is shown under the shop name, as `somcp_get_product` names it, through one shared function), `delay` (**Delay** on the list, **Transit time** on the form, in `language` for the token's shop), `active` (**Status**), `is_free` (**Free shipping**), `position` (from 0; the list shows it from 1), `logo` (`img/s/<id_carrier>.jpg` as a `download_ref`, `null` when there is none), `tracking_url` (as stored, `@` where the tracking number goes, empty when none), `external_module` (the module that computes its costs when a module created it, `null` otherwise). Plus `count` and `language` (the ISO code `delay` was read in).

**Errors** - in the envelope `Nothing was read. Correct these and call again:`, with the other problems of the call: a language the shop does not have, named by `SomcpTool::languageProblem()` with the ones it has. An empty list is `carriers: []`, `count: 0`.

**Notes** - PrestaShop gives a carrier a new `id_carrier` each time it is edited and keeps the old row as deleted; `id_reference` does not change. Orders keep the `id_carrier` they were placed with, products are tied to the reference (the product tools turn the `id_carrier` they are given into it). Only the carriers of the token's shop (`carrier_shop`), not deleted; disabled ones are listed. Zones, groups, tax, handling costs, billing, sizes and weight of one carrier are read by `somcp_get_carrier`. In Configuration with `somcp_list_order_statuses`: an order uses a carrier without containing one, so the scope is `mcp.orders.read`.

On 8.0.1 the filter label is **Free Shipping**, on 9.0.0 **Free shipping**; the edit page is the wizard `AdminCarrierWizard` on 8.0.1 and a Symfony form on 9.0.0. The columns read are the same on both.

#### `somcp_get_carrier`
**State**: written · [issue #316](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/316)
**Nature**: `acts(VIEW, 'AdminCarriers')` · **Scope**: `mcp.orders.read`

**Does** - reads one carrier of **Shipping > Carriers** as its edit page shows it: name, **Transit time**, **Speed grade**, logo, **Tracking URL**, **Group access**, **Zones**, **Free shipping**, **Tax**, handling costs, billing by price or by weight, the **Ranges** with the price of each per zone, **Out-of-range behavior** and the four size and weight limits.

**Do not use for** - listing the carriers (→ `somcp_list_carriers`); the carrier of one order (→ `somcp_get_order`); the shipping cost of one cart, to which the shop adds handling costs, free shipping thresholds and the carrier tax.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_carrier` | **ID** | integer | yes | from `somcp_list_carriers`; read through `SomcpTool::id()` |
| `language` | | string | no | ISO code for `delay` and the group names, through `SomcpTool::readLanguage()` and listed with `SomcpTool::languageProblem()`; the shop default when left out |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - one object. The fields `somcp_list_carriers` gives (`id_carrier`, `id_reference`, `name`, `delay`, `active`, `is_free`, `position`, `logo`, `tracking_url`, `external_module`), read by the same query and shaped by the same function (`SomcpToolListCarriers::query()` and `entry()`), plus:
- `grade`: **Speed grade**;
- `group_access`: `[{id_group, name}]` from `carrier_group`, among the groups of the shop, `name` in `language`, the `id_group` of `somcp_list_customer_groups`;
- `zones`: `[{id_zone, name}]` from `carrier_zone`, sorted by name as the screen sorts them;
- `id_tax_rules_group`: **Tax**, from `carrier_tax_rules_group_shop` for the shop of the token, `0` for **No tax**; `tax_rule`: its name through `SomcpToolGetTaxRule::existingRule()`, `null` for 0 or a rule deleted since (then a `warning`);
- `handling_costs` (**Add handling costs** on 8.0.1, **Handling costs** on 9.0.0); when true, `handling_cost_tax_excl`: `PS_SHIPPING_HANDLING` for the shop and group of the token, set on **Shipping > Preferences** and read with the other preferences by `somcp_get_shipping_preferences`;
- `billing`: `"price"` or `"weight"`, as `Carrier::getShippingMethod()` decides (a carrier stored with `shipping_method` 0 follows `PS_SHIPPING_METHOD`), `null` for a free carrier;
- `out_of_range`: `"highest_range"` (**Apply the cost of the highest defined range**) or `"disable_carrier"` (**Disable carrier**);
- `ranges`: the ranges of the kind `billing` names (`range_price` for `"price"`, `range_weight` for `"weight"`), sorted by `from`, each `{from, to, prices}`, `from` included and `to` excluded as the 8.0.1 header says (**>=**, **<**); `prices` is `[{id_zone, zone, price_tax_excl}]`, one per zone of `zones`, from `delivery`, the row of the shop of the token, else of its group, else the global one, as `Carrier::sqlDeliveryRangeShop()` picks it; a zone with no row has `price_tax_excl: null` and a `warning`, since the shop does not offer the carrier there. The rows of the other kind (a carrier stored with both, like demo carrier 2) are left out: the form does not show them and the core does not charge them. `[]` for a free carrier, whose form shows no ranges;
- `range_unit`: the ISO code of the default currency (`PS_CURRENCY_DEFAULT`) for price ranges, `PS_WEIGHT_UNIT` for weight ranges, `null` for a free carrier;
- `max_width`, `max_height`, `max_depth` (integers), `max_weight` (number), `0` for no limit, with `dimension_unit` (`PS_DIMENSION_UNIT`) and `weight_unit` (`PS_WEIGHT_UNIT`);
- `warning` when there is one (a deleted tax rule, a zone without a price, costs computed by a module), and `language`.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`, every problem at once: a missing or non-integer `id_carrier` (`SomcpTool::notAnIdentifier()`), a carrier not found (`SomcpTool::notFound('id_carrier', 'carrier', $id, 'somcp_list_carriers')`; for an older version of a carrier, left `deleted` by an edit, the line adds the `id_reference` to find the current one by), an unknown argument, a language the shop does not have.

**Notes** - only a carrier of the shop of the token (`carrier_shop`), not deleted. A carrier made by a module is read the same way, with a `warning` that its costs are computed by that module. The logo is a `download_ref` through `SomcpDownload::offer('carrier_logo', ...)`, as `somcp_list_carriers` gives it. `SomcpToolGetCarrier::carrier()` is public for the tools that answer with a carrier, and `notFound()` for the line they refuse an old version with; its answer can be given back to `somcp_update_carrier` as it is. Read only, replayable.

**Differences between 8.0.1 and 9.0.0** - the page: the wizard `AdminCarrierWizard` on 8.0.1 (**Enabled** in **Summary**, **Group access** in step 3, the zones as rows of the ranges table), a Symfony form on 9.0.0 (**Active** and **Group access** in **General settings**, **Zones** a multiple select), and their labels (**Billing** / **Shipping costs**, **Maximum package width (cm)** / **Max order width**); the columns and the answer are the same.

#### `somcp_list_zones`
**State**: written · [issue #335](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/335)
**Nature**: `acts(VIEW, 'AdminZones')` · **Scope**: `mcp.orders.read`

**Does** - lists the zones of **International > Locations > Zones** with the
columns of that list: **ID**, **Zone**, **Enabled**, and the number of
countries in each. It is the source of the `id_zone` that the zones of a
carrier take and that every country belongs to: no other tool gives one.
Read only.

**Do not use for** - the zones a carrier serves and its prices per zone
(→ `somcp_get_carrier`); the countries themselves; creating a zone
(→ `somcp_create_zone`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_zone` | **ID** filter | integer | no | one zone, exact, read with `SomcpTool::id()`; an identifier the shop does not have gives an empty list, as the screen and `somcp_list_cms_pages` do |
| `name` | **Zone** filter | string | no | part of the name, case-insensitive; left out, every zone |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both (**All**, the default of the list) |

No paging and no `language`: a shop holds a handful of zones, and their names
are not translated (there is no `zone_lang`).

**Returns** - `zones`, sorted by `id_zone` as the list: `id_zone` (**ID**),
`name` (**Zone**), `active` (**Enabled**), `countries` (the number of countries
of the zone, `country.id_zone`, so the assistant sees an empty zone before
giving it to a carrier). Plus `count`. The keys `id_zone` and `name` are those
of the `zones` of `somcp_get_carrier`, so a zone read here is given to the
carrier tools under the same names.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_zone` that is not an identifier (`SomcpTool::notAnIdentifier()`), an
`active` that is not a boolean (`active: true or false`, the line of
`somcp_list_taxes`). An empty list is `zones: []`, `count: 0`.

**Notes**
- Read through a query on `zone` joined to `zone_shop` on the shop of the token, rather than `Zone::getZones()`, which reads every zone of the installation without the shop and has no name filter.
- `name` filters as the **Name** filter of `somcp_list_taxes` and `somcp_list_carriers` do.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - none in the list, its columns, its filters, its labels or the tables (`zone`, `zone_shop`).

#### `somcp_create_zone`
**State**: written · [issue #348](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/348)
**Nature**: `acts(ADD, 'AdminZones')` · **Scope**: `mcp.orders.write`

**Does** - creates one zone of **International > Locations > Zones**, as
**Add new zone** then **Save** do: **Name** and **Active**. A zone groups the
countries a carrier serves and its prices per zone (`somcp_update_carrier`,
`zones` and `ranges`): without it, only the zones the install left can be used.

**Do not use for** - the zones a carrier serves and its prices per zone
(→ `somcp_update_carrier`); renaming or disabling a zone (→ `somcp_update_zone`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `name` | **Name** | string | yes | trimmed; empty refused `name: required`; at most the size of `zone.name` (`SomcpTool::columnSize()`, 64 as fallback): `name: 64 characters at most, 70 given`; checked with `validateField('name', ...)` on a `new Zone()`: `name: a character PrestaShop refuses in a zone name (< > = { }) - ...` |
| `active` | **Active** | boolean | no | left out, `true`, the default of the form; not a boolean: `active: true or false` |

Unknown arguments through `SomcpTool::unknownFields()`, with `id_zone` and
`countries` named as worked out by the shop, so a row of `somcp_list_zones`
given back is told why.

**Returns** - `created: true`, `id_zone`, `warning`, and `zone`, the row of
`somcp_list_zones` for that zone (`id_zone`, `name`, `active`, `countries`,
here 0), read back through `SomcpToolListZones::zones()`. The `warning` always
says the zone has no country yet, and adds, when another zone of the shop has
the same name (case-insensitive), `Zone <id> already has this name: the
merchant sees two zones named <name> in the carrier form.` - the form takes it,
so it is not refused.

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`, every problem at once. A failed `add()` is reported with a hint to
read `somcp_list_zones` before calling again.

**Notes**
- The zone is tied to the shop of the token only (`id_shop_list`, `zone_shop`), as the form does with **Shop association** on that shop.
- Not replayable: the same call twice creates two zones, as the form does; the description says to read `somcp_list_zones` first.
- **Active** left out is `true`, the form's default: a zone does nothing on its own until countries and carriers are given to it.
- Logged as `zone_created`. The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none in the fields, the checks or the tables; only the card title (**Zones** / **Zone**) and the multistore label (**Shop association** / **Store association**).

#### `somcp_update_zone`
**State**: written · [issue #349](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/349)
**Nature**: `acts(EDIT, 'AdminZones')` · **Scope**: `mcp.orders.write`

**Does** - changes one zone of **International > Locations > Zones**, as its
edit form then **Save** do (**Name**, **Active**), or as the **Enabled** switch
of the list and the bulk actions **Enable selection** / **Disable selection**
do for one zone.

**Do not use for** - the zones a carrier serves (→ `somcp_update_carrier`,
`zones`); the zone of a country (a later tool); creating a zone
(→ `somcp_create_zone`); deleting one (→ `somcp_delete_zone`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_zone` | **ID** | integer | yes | read with `SomcpTool::id()`; a zone the shop of the token does not have (`zone_shop`) refused with `SomcpTool::notFound('id_zone', 'zone', $id, 'somcp_list_zones')` |
| `name` | **Name** | string | no | the checks of `somcp_create_zone`, through `SomcpToolCreateZone::name()`: required when given, 64 characters at most, `validateField('name', ...)` |
| `active` | **Active** (form), **Enabled** (list) | boolean | no | not a boolean: `active: true or false` |
| `countries` | | integer | no | accepted only as `somcp_list_zones` gives it back, so a row of the list can be given back whole; another value refused: `countries: worked out by the shop - move countries with the countries tools` |

At least one of `name` and `active`, otherwise `name or active: give at least
one field to change`. Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - the shape of every update tool (#210): something written,
`changed: true`, `id_zone` and `changes`, one entry per field written, each
`field`, `old` and `new`; nothing differed, `changed: false`, `id_zone` and
`reason` (`The zone already holds every value given. Nothing was written.`),
and nothing is written. In both cases `zone`, the row of `somcp_list_zones`
read back (`SomcpToolListZones::zone()`). When something was written, a
`warning` when:
- `active` set to `false` on a zone that carriers serve (`carrier_zone`): `Shipping is now disallowed to zone <id>: the <n> carriers that serve it (<ids>) are no longer offered to addresses in its <m> countries.`;
- the new `name` is taken by another zone of the shop, the warning of `somcp_create_zone`;
- the zone is also in another shop (`zone_shop`): `also_in_other_shops: <n>`, since a zone has one row in `zone` and the change reaches every shop of it (`SomcpToolUpdateZone::otherShops()`).

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once.

**Notes**
- Replayable: the same call twice writes once, the second answers `changed: false`.
- Only the fields given and different from the stored zone are written (`Zone::update()`). Logged as `zone_updated` with the fields changed.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none in the fields, the checks or the tables; only the titles of the edit page (**Edit: Asia** / **Editing zone Asia**).

#### `somcp_delete_zone`
**State**: written · [issue #350](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/350)
**Nature**: `acts(DELETE, 'AdminZones')` · **Scope**: `mcp.orders.write`

**Does** - deletes one zone of **International > Locations > Zones**, as the
**Delete** of its row does, once it holds no country and no state.

**Do not use for** - removing a zone from one carrier (→ `somcp_update_carrier`,
`zones`); disabling a zone for a while (→ `somcp_update_zone`, `active`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_zone` | **ID** | integer | yes | read with `SomcpTool::id()`; a zone the shop of the token does not have refused with `SomcpTool::notFound('id_zone', 'zone', $id, 'somcp_list_zones')` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Refused**, every reason at once, under `Nothing was deleted.`:
- the zone still holds countries (`country.id_zone`): `id_zone: zone <id> holds <n> countries - move them to another zone first`;
- the zone still holds states (`state.id_zone`): `id_zone: zone <id> holds <n> states - move them to another zone first`;
- the zone is also in another shop (`zone_shop`, `SomcpToolUpdateZone::otherShops()`): `id_zone: zone <id> is also used by <n> other shops - deleting it would remove it there too`.

Decision (countries and states): refused, where the back office deletes.
`Zone::delete()` sets `id_zone = 0` on every country and state of the zone:
those countries then belong to no zone, no carrier is offered to their
addresses and no tax rule by zone reaches them. The other shops: refused, as
`somcp_delete_feature` refuses a shared feature, since the row of `zone` is
shared.

**Returns** - `deleted: true`, `id_zone`, `name` (as it was), and
`carriers_detached`, the `id_carrier` of the carriers in use (not an older
version of a changed carrier) that served the zone (`[]` when none), read
through `SomcpToolUpdateZone::carriers()`, the list `somcp_update_zone` warns
of, before `Zone::delete()` removes their `carrier_zone` and `delivery` rows
(those of older versions too); when not empty, a `warning`: `The <n> carriers (<ids>) no
longer serve this zone, and their prices for it are deleted: check their zones
with somcp_get_carrier.`

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`, every problem at once.

**Notes**
- Not undoable: the answer gives the name so the assistant can recreate the zone with `somcp_create_zone`, not its carrier prices. The description says to read `somcp_list_zones` (its `countries`) and `somcp_get_carrier` first.
- A second call on the same identifier is refused as an unknown zone.
- Logged as `zone_deleted` with the zone name and the carriers detached. The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none: `Zone::delete()` and `DeleteZoneHandler` are the same on both.

#### `somcp_list_countries`
**State**: written · [issue #338](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/338)
**Nature**: `acts(VIEW, 'AdminCountries')` · **Scope**: `mcp.orders.read`

**Does** - lists the countries of **International > Locations > Countries**
with the columns of that list: **ID**, **Country**, **ISO code**, **Call
prefix**, **Zone**, **Enabled**, and what an address of the country must
hold. It is the source of the country ISO code that every other tool takes
as `country` or `delivery_country`, and it shows which countries the shop
sells to. Read only.

**Do not use for** - the zones (→ `somcp_list_zones`); the tax rows of a
country (→ `somcp_get_tax_rule`); every field of one country (→
`somcp_get_country`); enabling or changing a country (→ `somcp_update_country`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_country` | **ID** filter | integer | no | one country, exact, read with `SomcpTool::id()`; an identifier the shop does not have gives an empty list, as the screen and `somcp_list_zones` do |
| `name` | **Country** filter | string | no | part of the name in `language`, case-insensitive |
| `iso_code` | **ISO code** filter | string | no | exact, case-insensitive (`fr` finds **France**) |
| `id_zone` | **Zone** filter | integer | no | the countries of one zone, from `somcp_list_zones`; a zone the shop does not have: `SomcpTool::notFound('id_zone', 'zone', $id, 'somcp_list_zones')` |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both |
| `language` | - | string | no | ISO code of one of the shop's active languages, for the names; a code the shop does not have is refused; the shop default language when left out, as `somcp_list_taxes` |
| `limit` | **Display** | integer | no | how many countries to return, 50 by default (the default of the screen) and 300 at most |
| `offset` | the pages of the list | integer | no | how many to skip, to read the next page |

No **Call prefix** filter: the answer gives every prefix.

**Returns** - `countries`, sorted by `name` as the list: `id_country` (**ID**),
`name` (**Country**), `iso_code` (**ISO code**), `call_prefix` (**Call
prefix**, an integer, `0` where the screen shows `-`), `id_zone` and `zone`
(**Zone**, the name as `somcp_list_zones` gives it), `active` (**Enabled**),
`contains_states` (**Contains states**), `need_zip_code` (**Does it need
Zip/Postal code?**), `zip_code_format` (**Zip/Postal code format**, as stored,
for example `NNNNN`, `""` when none), `need_identification_number` (**Do you
need a tax identification number?**, the **DNI** an address there must give).
Then `returned`, `total_count` (the matches before `limit`), `offset` and
`language`, the paging keys of `somcp_search_customers`. **Default currency**,
**Address format** and **Display tax label** are settings of the country, read
with `somcp_get_country`.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_country` or `id_zone` that is not an identifier
(`SomcpTool::notAnIdentifier()`), a zone the shop does not have, an `active`
that is not a boolean (`active: true or false`, the line of
`somcp_list_taxes`), a language the shop does not have
(`SomcpTool::languageProblem()`). An empty list is `countries: []`,
`returned: 0`.

**Notes**
- Read through a query on `country` joined to `country_shop` on the shop of the token and to `country_lang` in the language asked, rather than `Country::getCountries()`, which has no name or ISO filter and no paging.
- The back-office address of the page is never part of the answer.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - none in the list, its columns, its filters, its labels or the tables (`country`, `country_lang`, `country_shop`); the edit form is legacy on 8.0.1 and Symfony on 9.0.0, with **Zip/Postal** written **ZIP/Postal**.

#### `somcp_get_country`
**State**: written · [issue #359](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/359)
**Nature**: `acts(VIEW, 'AdminCountries')` · **Scope**: `mcp.orders.read`

**Does** - reads one country of **International > Locations > Countries** as
its **Edit** page shows it: every field of the form, the three that
`somcp_list_countries` leaves out included (**Default currency**, **Address
format**, **Display tax label**). It is the read before the change of a
country. Read only.

**Do not use for** - many countries (→ `somcp_list_countries`); the states of
a country (→ `somcp_list_states`); changing a country (→ `somcp_update_country`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_country` | - | integer | yes | the country, from `somcp_list_countries`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_country')`; not a country of the shop (`country_shop`, as the list filters it): `SomcpTool::notFound('id_country', 'country', $id, 'somcp_list_countries')` |
| `language` | - | string | no | the ISO code of the language of **Country**, through `SomcpTool::readLanguage()`; refused with `SomcpTool::languageProblem()`; the shop default language when left out |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - `language`, then the keys of the row of `somcp_list_countries`
(`id_country`, `name`, `iso_code`, `call_prefix`, `id_zone`, `zone`,
`active`, `contains_states`, `need_zip_code`, `zip_code_format`,
`need_identification_number`), built by `SomcpToolListCountries::row()` and
read through `SomcpToolListCountries::country()`, the query of the list; then:
- `id_currency` (**Default currency**): `0` for **Default store currency**, as stored; `currency`: the ISO code of that currency (`Currency::getIsoCodeById()`), or `null` for the default;
- `address_format` (**Address format**): the stored format as a list of lines (the `format` column of `address_format` split on new lines, empty lines dropped), or `null` when the country has no row; `address_format_used`: the lines PrestaShop applies (`AddressFormat::getAddressCountryFormat()`, the format of the shop's default country when the country has none, and the `dni` line when it needs one);
- `display_tax_label` (**Display tax label (e.g. "Tax incl.")**): boolean;
- `states`: the number of states of the country, so the assistant knows whether `somcp_list_states` has something to show.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once.

**Notes**
- The **Address format** is read from the table on both versions: the 9.0.0 form shows the field empty, `GetCountryForEditingHandler` leaving the reading of the format commented out.
- The back-office address of the page is never part of the answer.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony on 9.0.0), some labels (**Does it need Zip/Postal code?** on 8.0.1, **Does it need a ZIP/Postal code?** on 9.0.0), the empty **Address format** of the 9.0.0 form; the class, the tables and the stored values are the same.

#### `somcp_update_country`
**State**: written · [issue #360](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/360)
**Nature**: `acts(EDIT, 'AdminCountries')` · **Scope**: `mcp.orders.write`

**Does** - changes one country of **International > Locations > Countries**,
as **Edit** then **Save** do, and as the **Enabled** switch and the bulk
action **Assign to a new zone** do for one country: **Country** (per
language), **ISO code**, **Call prefix**, **Default currency**, **Zone**,
**Does it need Zip/Postal code?**, **Zip/Postal code format**, **Address
format**, **Active**, **Contains states**, **Do you need a tax
identification number?**, **Display tax label (e.g. "Tax incl.")**. Only the
fields that differ are written.

**Do not use for** - one state (→ `somcp_update_state`); the tax of a country
(→ `somcp_add_country_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_country` | - | integer | yes | the country, from `somcp_list_countries`; a country of the shop (`country_shop`), found as `somcp_get_country` finds it |
| `languages`, `name` | **Country** | object | no | one text per language keyed by ISO code, the rule of #149 (`SomcpToolCreateProduct::languages()` and `texts()`); a plain text equal to a stored name is no change (`givenBackAsRead()`); at most the size of `name` in `Country::$definition` (64), `isGenericName` |
| `iso_code` | **ISO code** | string | no | trimmed, upper-cased, `Validate::isLanguageIsoCode()`: `iso_code: 2 or 3 letters, for example FR`; another country has it (`Country::getByIso()`): `iso_code: country <id> (<name>) already has the ISO code <code>` |
| `call_prefix` | **Call prefix** | integer | no | 0 or more: `call_prefix: a whole number, 0 for none (the list shows -)` |
| `id_currency` | **Default currency** | integer | no | `0` for **Default store currency**, or a currency `currency_shop` ties to the shop, as `somcp_list_currencies` lists them; otherwise `SomcpTool::notFound('id_currency', 'currency', $id, 'somcp_list_currencies')` |
| `id_zone` | **Zone** | integer | no | a zone of the shop (`SomcpToolListCountries::zoneOfShop()`), the message of `somcp_create_state` |
| `need_zip_code` | **Does it need Zip/Postal code?** | boolean | no | `need_zip_code: true or false` |
| `zip_code_format` | **Zip/Postal code format** | string | no | trimmed, upper-cased; `""` for no check; `Validate::isZipCodeFormat()`: `zip_code_format: L for a letter, N for a digit, C for the ISO code, digits, spaces and dashes, for example NNNNN or LNL NLN`; at most the size of the column (`SomcpTool::columnSize()`, 12 as fallback) |
| `address_format` | **Address format** | array of strings | no | the lines as `somcp_get_country` gives them, empty lines dropped, joined with new lines, at most 255 characters; checked by `AddressFormat::checkFormatFields()` on a new `AddressFormat`, every message of `getErrorList()` given as `address_format: <message>`; an empty list: `address_format: at least one line - PrestaShop refuses an empty format` |
| `active` | **Active** | boolean | no | `active: true or false` |
| `contains_states` | **Contains states** | boolean | no | the same |
| `need_identification_number` | **Do you need a tax identification number?** | boolean | no | the same |
| `display_tax_label` | **Display tax label (e.g. "Tax incl.")** | boolean | no | the same |

A row of `somcp_get_country` can be given back whole: `language` (any
language of the shop), `zone`, `currency`, `states` and `address_format_used`
are taken only when they equal the stored values, otherwise `<field>: worked
out by the shop - change <the field it comes from>`; `need_zip_code`,
`zip_code_format` and `address_format` equal to the stored values write
nothing. Unknown
arguments through `SomcpTool::unknownFields()`. Before the write, the whole
object goes through `validateFields()` and `validateFieldsLang()`.

**Then** - the fields that differ are set on the `Country` loaded in every
language, then `update()`. When the zone changed, the states of the country
whose zone was the old one get the new one, each through `State::update()`,
as 8.0.1 does on **Save**; a state the merchant put in another zone is not
touched. A new **Address format** is then saved through `AddressFormat`
(`new AddressFormat($idCountry)`, `format`, `id_country` when the row is new,
`save()`), as the 8.0.1 form does after the country; its failure is reported
as a problem of `address_format`, naming the fields of the country already
written. Nothing differs: no write.

**Returns** - the shape of every update tool: `changed: true`, `id_country`,
`changes` (`field`, `old`, `new`; a name as `name (<iso>)`), `states_moved`
(the `id_state` list, only when the zone changed), and `country`, the answer
of `somcp_get_country` read back in the shop default language; or `changed:
false`, `id_country`, `reason: The country already holds every value given.
Nothing was written.` and `country`. When the installation has more than one
shop, `also_in_other_shops` and a warning: the `country` row is shared by
every shop. Logged as `country_updated` with the field names, or
`country_update_failed`.

Warnings, in `warning`:
- **Active** set to `true` with no `module_country` row for the country and the shop: `No payment module is offered to <name> in this shop: its customers cannot pay until one is allowed for it in Payment > Preferences (somcp_update_payment_restrictions).`
- **Active** set to `true` in a zone no carrier serves (`SomcpToolUpdateZone::carriers()`): `No carrier serves zone <id> (<name>): customers of <name> are offered no carrier until one serves it - see somcp_update_carrier.`
- **Contains states** set to `false` while the country has states: `The <n> states of <name> are no longer offered in its address form; they are kept.`; set to `true` with none: `<name> has no state yet: add them with somcp_create_state.`
- **Do you need a tax identification number?** set to `true`: `New addresses in <name> must now give an identification number; the existing ones are not checked.`
- **Zip/Postal code format** changed to a format: `New addresses in <name> must now match <format>; the existing ones are not checked again.`
- **Address format** without `dni` while the country needs an identification number: `PrestaShop adds the identification number (dni) line when it prints an address of <name>.`

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once (`SomcpTool::refused('changed', $problems)`).

**Notes**
- Replayable: a second identical call answers `changed: false`.
- The payment restrictions (**Payment > Preferences**) and the shop association are never written here: the 8.0.1 list switch adds every payment module to a country it enables, which the edit form of both versions does not.
- The **Address format** is written on both versions, as 8.0.1 saves it: the 9.0.0 form shows the field empty and `EditCountryHandler` never writes it, yet the storefront, the invoices and the address forms of 9.0.0 still read the stored format.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony on 9.0.0), some labels, the states moved with the zone (8.0.1 only in the back office, both here), the refusal of an ISO code another country has (8.0.1 only in the back office, both here), **Country options** (8.0.1 only, not part of this tool), the **Address format** (saved by the 8.0.1 form only, by this tool on both); the checks (`isZipCodeFormat`, `checkFormatFields`), the class and the tables are the same.

#### `somcp_get_country_options`
**State**: written · [issue #374](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/374)
**Nature**: `acts(VIEW, 'AdminCountries')` · **Scope**: `mcp.orders.read`

**Does** - reads **Country options** of **International > Locations >
Countries**: **Restrict country selections in front office to those covered by
active carriers**. When it is on, the storefront offers in an address only the
countries an active carrier delivers (`Carrier::getDeliveredCountries()` in
`FrontController::makeAddressForm()`), every active country otherwise. Read
only; the read before somcp_update_country_options, which takes back what
this one gives.

**Do not use for** - the countries themselves (→ `somcp_list_countries`,
`somcp_get_country`); the zones a carrier delivers (→ `somcp_get_carrier`);
changing the option (→ `somcp_update_country_options`).

**Parameters** - none. Unknown arguments through `SomcpTool::unknownFields()`.

**Returns**

| Key | On screen | Type |
|---|---|---|
| `restrict_to_carriers` | **Restrict country selections in front office to those covered by active carriers** | boolean, `Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES', null, $idShopGroup, $idShop)` for the shop of the token, as `somcp_get_tax_options` reads its keys |

Built in the public `SomcpToolGetCountryOptions::read()`, which the update
calls. On 9.0.0, beside the answer: `note`: `PrestaShop 9 no longer shows this
option, but the storefront still applies it.`

**Errors** - in the envelope `Nothing was read. Correct these and call
again:`, an unknown argument.

**Notes**
- A key the shop never stored (a fresh installation) is read as `false`, what the storefront applies.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - the switch is on the 8.0.1 page only (legacy `AdminCountries`, panel **Country options**, button **Save**); the key and the storefront rule are the same.

#### `somcp_update_country_options`
**State**: written · [issue #375](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/375)
**Nature**: `acts(EDIT, 'AdminCountries')` · **Scope**: `mcp.orders.write`

**Does** - changes **Country options** of **International > Locations >
Countries**, as its **Save** (`submitOptionscountry`): **Restrict country
selections in front office to those covered by active carriers**. A shop
that ships to a few countries otherwise lets customers pick any active
country in their address, then finds no carrier at checkout.

**Do not use for** - switching a country on or off (→ `somcp_update_country`);
the zones a carrier serves (→ `somcp_update_carrier`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `restrict_to_carriers` | **Restrict country selections in front office to those covered by active carriers** | boolean | no | `true` or `false`, otherwise `restrict_to_carriers: true or false` |

The answer of `somcp_get_country_options` given back whole writes nothing: a
value equal to the stored one is no change, and `note` is ignored when it is
the line the read gives. Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - when it differs:
`Configuration::updateValue('PS_RESTRICT_DELIVERED_COUNTRIES', (int) $value, false, $idShopGroup, $idShop)`
for the shop of the token, as `somcp_update_tax_options` saves its keys. Log
lines `country_options_updated` and `country_options_update_failed`.

**Returns** - the shape of every update tool (#356): `changed` (boolean),
`changes` (`field`, `old`, `new`), `reason` when nothing differs, and
`options` read back through `SomcpToolGetCountryOptions::read()`.

Warnings, in `warning` beside the answer:
- set to `true` with no active carrier serving an active country (`Carrier::getDeliveredCountries(<default language>, true, true)` empty): `No active carrier delivers to an active country: customers can no longer choose any country in their address.`;
- on 9.0.0: `PrestaShop 9 no longer shows this option, but the storefront still applies it.`;
- on an installation of several shops: `The option changed for this shop only, as the back office saves it in a shop context.`

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once (`SomcpTool::refused('changed', $problems)`).

**Notes**
- Replayable: a second identical call answers `changed: false`.
- Written on 9.0.0 too, as `somcp_update_country` writes **Address format** there: the storefront applies the key, so this is the only way left to change it.

**Differences between 8.0.1 and 9.0.0** - the switch is on the 8.0.1 page only; the key and the storefront rule are the same.

#### `somcp_list_states`
**State**: written · [issue #341](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/341)
**Nature**: `acts(VIEW, 'AdminStates')` · **Scope**: `mcp.orders.read`

**Does** - lists the states of **International > Locations > States** with
the columns of that list: **ID**, **Name**, **ISO code**, **Zone**,
**Country**, **Enabled**. It is the source of the state ISO code that `state`
and `states` of `somcp_add_country_rule` and `somcp_update_country_rule`, and
`state` of `somcp_create_supplier`, `somcp_update_supplier` and
`somcp_update_manufacturer_address`, take within its country. Read only.

**Do not use for** - the countries (→ `somcp_list_countries`); the zones (→
`somcp_list_zones`); the tax rows of a state (→ `somcp_get_tax_rule`);
creating a state (→ `somcp_create_state`); enabling a state or changing its
zone (→ `somcp_update_state`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_state` | **ID** filter | integer | no | one state, exact, read with `SomcpTool::id()`; an identifier the shop does not have gives an empty list, as the screen and `somcp_list_zones` do |
| `name` | **Name** filter | string | no | part of the name, case-insensitive |
| `iso_code` | **ISO code** filter | string | no | exact, case-insensitive (`ca` finds **California**, and the states of other countries with the same code); a value that is not a text is refused with `iso_code: the ISO code of a state, for example CA` |
| `country` | **Country** filter | string | no | the ISO code of the country, as `country` of the country rule and supplier tools and `iso_code` of `somcp_list_countries` give it, for example `US`, read through `SomcpToolCreateSupplier::shopCountries()`; a code the shop does not have: `SomcpTool::notFound('country', 'country', $code, 'somcp_list_countries', 'ISO code')`; a list or an object is a mistake in the call, refused with `country: an ISO code of 2 letters, for example US` |
| `id_zone` | **Zone** filter | integer | no | the states of one zone, from `somcp_list_zones`; a zone the shop does not have: `SomcpTool::notFound('id_zone', 'zone', $id, 'somcp_list_zones')`, checked by `SomcpToolListCountries::zoneOfShop()` |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both |
| `limit` | **Display** / **Items per page** | integer | no | how many states to return, 50 by default (the default of both screens) and 300 at most |
| `offset` | the pages of the list | integer | no | how many to skip, to read the next page |

The country filter takes the ISO code, not `id_country`: every tool that takes
a state takes its country by ISO code, so the assistant gives this tool the
value it gives the next one. No `language`: a state name is not translated.

**Returns** - `states`, sorted by `id_state` as the list: `id_state` (**ID**),
`name` (**Name**), `iso_code` (**ISO code**, the value `state` and `states` of
the other tools take), `id_country`, `country` (the ISO code of the country,
the value `country` of the other tools takes) and `country_name` (**Country**,
in the shop default language, as `somcp_get_tax_rule` names it), `id_zone`
and `zone` (**Zone**, the name as `somcp_list_zones` gives it, the state's own
zone, not always that of its country), `active` (**Enabled**). Then
`returned`, `total_count` (the matches before `limit`) and `offset`, the
paging keys of `somcp_search_customers` and `somcp_list_countries`.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_state` or `id_zone` that is not an identifier
(`SomcpTool::notAnIdentifier()`), a country or a zone the shop does not have,
an `active` that is not a boolean (`active: true or false`, the line of
`somcp_list_taxes`). An empty list is `states: []`, `returned: 0`.

**Notes**
- Read through a query on `state` joined to `country` (for its ISO code), to `country_shop` on the shop of the token, to `country_lang` in the shop default language and to `zone`, rather than `State::getStates()`, which has no filter and no paging. A state belongs to no shop (no `state_shop` table): those of the shop are the states of its countries.
- The back-office address of the page is never part of the answer.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - the list itself (a legacy list on 8.0.1, a Symfony grid on 9.0.0, the **Enabled** icon against a switch, the paging choices, **Assign to a new zone** on 8.0.1 only); the columns, the filters, their labels and the table (`state`) are the same.

#### `somcp_create_state`
**State**: written · [issue #353](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/353)
**Nature**: `acts(ADD, 'AdminStates')` · **Scope**: `mcp.orders.write`

**Does** - creates one state of **International > Locations > States**, as
**Add new state** then **Save** do: **Name**, **ISO code**, **Country**,
**Zone** and **Status**. The tax rule rows and the addresses take a state by
its ISO code within its country (`somcp_list_states`): without it, only the
states the install left can be used.

**Do not use for** - the zone of a whole country (a later tool); the tax of a
state (→ `somcp_add_country_rule`, which takes the state by its ISO code);
changing or enabling a state (→ `somcp_update_state`); deleting one (→
`somcp_delete_state`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `country` | **Country** | string | yes | the ISO code of the country, as `somcp_list_states` takes it, through `SomcpToolCreateSupplier::shopCountries()`; not a string: `country: an ISO code of 2 letters, for example US`; not a country of the shop: `SomcpTool::notFound('country', 'country', $code, 'somcp_list_countries', 'ISO code')`; a country without `contains_states`: `country: <name> does not use states - only the countries with "contains states" take one` |
| `name` | **Name** | string | yes | trimmed, at most the size of `state.name` (`SomcpTool::columnSize()`, 80 as fallback), checked with `validateField('name', ...)` on a `new State()`: `name: a character PrestaShop refuses in a state name (< > = { }) - ...` |
| `iso_code` | **ISO code** | string | yes | not a text: `iso_code: the ISO code of a state, for example CA`; trimmed and upper-cased, checked with `Validate::isStateIsoCode()`: `iso_code: 1 to 4 letters or digits, optionally a dash and 1 to 4 more, for example CA or US-CA`; taken by a state of the same country (`State::getIdByIso($code, $idCountry)`): `iso_code: state <id> of <country> already has the ISO code <code>` |
| `id_zone` | **Zone** | integer | yes | read with `SomcpTool::id()`; a zone of the shop through `SomcpToolListCountries::zoneOfShop()`: `SomcpTool::notFound('id_zone', 'zone', $id, 'somcp_list_zones')` |
| `active` | **Status** | boolean | no | left out, `true`, the default of both forms; not a boolean: `active: true or false` |

Unknown arguments through `SomcpTool::unknownFields()`, with `id_state`,
`id_country`, `country_name` and `zone` named as worked out by the shop, so a
row of `somcp_list_states` given back is told why.

The **Zone** has no default from the zone of the country, as the form has
none: the state's own zone is what the carrier prices read for its addresses.
The description says to read the zone of the country with
`somcp_list_countries` and propose it.

The **ISO code** is refused when a state of the same country has it, on both
versions: 9.0.0 checks it against every state of the installation, but the
demo data itself gives the same code to states of several countries (`AL` in
Italy and the United States), and every tool takes a state within its country.

**Returns** - `created: true`, `id_state`, and `state`, the row of
`somcp_list_states` for that state (`id_state`, `name`, `iso_code`,
`id_country`, `country`, `country_name`, `id_zone`, `zone`, `active`), read back
through `SomcpToolListStates::state()` and `row()`, the functions the list
uses. When the installation has more than one shop, `also_in_other_shops` (as
the tax tools count it, `SomcpToolCreateTax::otherShops()`) and a `warning`: a
state has no shop table.

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`, every problem at once. A failed `add()` is reported with a hint to
read `somcp_list_states` before calling again.

**Notes**
- Not replayable, but a second identical call is refused by the ISO code test, so it creates nothing twice.
- `tax_behavior` is not on the form and is left at its default.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony on 9.0.0), the help texts (9.0.0 only) and the ISO code test (one country on 8.0.1, every country on 9.0.0, see above); the fields, the class and the table (`state`) are the same.

#### `somcp_update_state`
**State**: written · [issue #357](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/357)
**Nature**: `acts(EDIT, 'AdminStates')` · **Scope**: `mcp.orders.write`

**Does** - changes one state of **International > Locations > States**, as
**Edit** then **Save** do, and as the list's **Enabled** switch and the 8.0.1
bulk action **Assign to a new zone** do for one state: **Name**, **ISO code**,
**Country**, **Zone** and **Status**. Only the fields given change.

**Do not use for** - creating a state (→ `somcp_create_state`); deleting one
(→ `somcp_delete_state`); the zone of a whole country (a later tool); the tax of a state
(→ `somcp_update_country_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_state` | - | integer | yes | the state to change, from `somcp_list_states`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_state')`; unknown: `SomcpTool::notFound('id_state', 'state', $id, 'somcp_list_states')`, through `SomcpToolListStates::state()`, so only a state of a country of the shop is found |
| `name` | **Name** | string | no | the check of `somcp_create_state`, `SomcpToolCreateState::name()` |
| `iso_code` | **ISO code** | string | no | the check of `somcp_create_state`, `SomcpToolCreateState::isoCode()`, within the country the state ends up in, the state itself excepted: `iso_code: state <id> of <country> already has the ISO code <code>`; when only the country changes, the code kept is checked in the new country |
| `country` | **Country** | string | no | the ISO code of the country, the check of `SomcpToolCreateState::country()`: a country of the shop that uses states; refused while the state is used: `country: state <id> is used by <n> addresses and <m> tax rule rows - a state of another country would no longer match them` |
| `id_zone` | **Zone** | integer | no | the check of `somcp_create_state` (`SomcpToolListCountries::zoneOfShop()`) |
| `active` | **Status** | boolean | no | not a boolean: `active: true or false` |

A row of `somcp_list_states` can be given back whole: `id_country`,
`country_name` and `zone` are taken only when they equal the stored values,
otherwise `<field>: worked out by the shop - change country or id_zone`.
Unknown arguments through `SomcpTool::unknownFields()`.

The **Country** of a state used by an address or a tax rule row is refused,
while both back offices move it: the addresses keep their own `id_country`
and the tax rule rows their own pair, so they would name a state of another
country and match no row. The merchant moves them first, or creates a new
state with `somcp_create_state`.

**Returns** - the shape of every update tool: `changed: true`, `id_state`,
`changes` (`field`, `old`, `new`, in the order of the form, the old value as
`somcp_list_states` gives it), and `state`, the row of `somcp_list_states`
read back with `SomcpToolListStates::state()`; or `changed: false`,
`id_state`, `reason: The state already holds every value given. Nothing was
written.` and `state`. When the installation has more than one shop,
`also_in_other_shops` (`SomcpToolCreateTax::otherShops()`) and a `warning`: a
state has no shop table. Also in `warning`: a **Zone** no carrier serves
(`SomcpToolUpdateZone::carriers()` empty), and a **Status** set to `false`,
with the number of addresses that keep the state (`State::countUsed()`).

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once. A failed `update()` is reported with a hint to
read `somcp_list_states` before calling again.

**Notes**
- Replayable: a second identical call answers `changed: false`.
- `tax_behavior` is not on the form and is never written.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony on 9.0.0), the help texts (9.0.0 only), **Assign to a new zone** (8.0.1 only, a bulk action) and the ISO code test (one country on 8.0.1, every country on 9.0.0, see `somcp_create_state`); the fields, the class and the table (`state`) are the same.

#### `somcp_delete_state`
**State**: written · [issue #358](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/358)
**Nature**: `acts(DELETE, 'AdminStates')` · **Scope**: `mcp.orders.write`

**Does** - deletes one state of **International > Locations > States**, as
the **Delete** of its row does (and **Delete selected** for one state). A
state created by mistake with `somcp_create_state`, or one the merchant never
sells to, no longer has to be removed in the back office.

**Do not use for** - switching a state off for a while (→
`somcp_update_state`, `active: false`); a tax rule row (→
`somcp_delete_country_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_state` | - | integer | yes | the state to delete, from `somcp_list_states`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_state')`; unknown: `SomcpTool::notFound('id_state', 'state', $id, 'somcp_list_states')`, through `SomcpToolListStates::state()` |

Unknown arguments through `SomcpTool::unknownFields()`. Refused, every
problem at once:
- used by addresses (`State::countUsed()`, every `address` row, deleted ones and those of suppliers, brands and warehouses included): `id_state: state <id> is used by <n> addresses - PrestaShop keeps a state an address uses`, the words of the 8.0.1 message;
- used by tax rule rows: `id_state: state <id> is used by <n> tax rule rows (tax rules <id_tax_rules_group>, ...) - delete them first with somcp_delete_country_rule`;
- when the installation has more than one shop (`SomcpToolCreateTax::otherShops()`): `id_state: a state is not tied to a shop - deleting it here would remove it from the <n> other shops of this installation too`, the rule of `somcp_delete_zone`.

The **tax rule rows** are refused while both back offices delete the state
and leave them behind: such a row applies to no address and
`somcp_get_tax_rule` could only list it with an empty `state`.

**Returns** - `deleted: true`, `id_state`, and `state`, the row of
`somcp_list_states` read before the deletion, so the assistant can recreate
it with `somcp_create_state`.

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`. A `State::delete()` that answers `false` (an address added between
the check and the call) is reported with a hint to read `somcp_list_states`
before calling again.

**Notes**
- Not replayable: a second call answers that the state is unknown.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony on 9.0.0) and the message of a used state (a sentence on 8.0.1, the generic error of `DeleteStateException` on 9.0.0); the class, the test of the addresses and the table (`state`) are the same.

#### `somcp_update_carrier`
**State**: written · [issue #320](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/320), position [#322](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/322), shipping costs settings [#323](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/323), size and weight limits [#330](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/330), logo [#331](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/331), zones [#336](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/336), ranges and prices [#337](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/337)
**Nature**: `acts(EDIT, 'AdminCarriers')` · **Scope**: `mcp.orders.write`

**Does** - changes the **General settings** of one carrier of **Shipping > Carriers**, as its edit page saves them: **Carrier name**, **Transit time** per language, **Speed grade**, **Logo**, **Tracking URL**, **Enabled** (8.0.1) / **Active** (9.0.0) and **Group access**, the **Zones** it delivers to (the zone boxes of **Shipping locations and costs**), its place in the list (the **Position** column, dragged on 8.0.1, **Rearrange** on 9.0.0), the ranges and their price in each zone (the **Ranges** table of **Shipping locations and costs** on 8.0.1, **Set ranges and prices** with **Manage ranges** on 9.0.0), and the other settings of **Shipping locations and costs**: **Free shipping**, **Add handling costs** / **Handling costs**, **Billing** / **Shipping costs**, **Tax** and **Out-of-range behavior** / **Out of range behavior**, and the four limits of **Size, weight, and group access** (8.0.1) / **Size and weight** (9.0.0). Only the fields given change.

**Do not use for** - the carrier of one order (→ `somcp_set_order_carrier`); the handling amount (**Shipping > Preferences**, `PS_SHIPPING_HANDLING`, for every carrier); the tax rule itself (→ `somcp_update_tax_rule`); the units of the limits (`dimension_unit`, `weight_unit`, shop settings of **International > Localization**); the zones themselves (**International > Locations > Zones**, → `somcp_list_zones` to read them); deleting a carrier (→ `somcp_delete_carrier`).

**Parameters** - the names of the answer of `somcp_get_carrier`, so a read can be given back as it is.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_carrier` | **ID** | integer | yes | from `somcp_list_carriers` or `somcp_get_carrier`; read through `SomcpTool::id()`; unknown or an older version: the line of `SomcpToolGetCarrier::notFound()`, with the `id_reference` of the current version |
| `name` | **Carrier name** | string | no | 64 characters at most, `Validate::isCarrierName` (`letters, digits and spaces, without < > ; = # { }`); the carrier stored as `0` and shown under the shop name keeps `0` when that name is given back |
| `delay` | **Transit time** | object | no | keyed by ISO code, through `SomcpToolCreateProduct::languages()` and `texts()`, required in every language filled, 512 characters at most, `Validate::isGenericName`; a language left out keeps its text; the one text `somcp_get_carrier` answers is passed when it is the stored text of one language |
| `languages` | | array | no | the languages `delay` is given in, as the other writes |
| `grade` | **Speed grade** | integer | no | `grade: an integer from 0 to 9` |
| `tracking_url` | **Tracking URL** | string | no | `""` empties it; otherwise an absolute URL of 255 characters at most, `@` where the tracking number goes |
| `active` | **Enabled** / **Active** | boolean | no | `active: true or false` |
| `group_access` | **Group access** | array | no | the full list after the change: the objects `somcp_get_carrier` answers (`{id_group, name}`, `name` ignored) or bare `id_group` integers from `somcp_list_customer_groups`; empty: `group_access: at least one customer group`; a group the shop of the token does not have: `SomcpTool::notFound('group_access', 'customer group', $id, 'somcp_list_customer_groups')` |
| `zones` | the zone boxes of **Shipping locations and costs** | array | no | the full list of zones the carrier serves after the call: the objects `somcp_get_carrier` answers (`{id_zone, name}`) or bare `id_zone` integers from `somcp_list_zones`, compared as a set (the order does not count); each `id_zone` read with `SomcpTool::id()`, unknown or not in the shop of the token (`zone_shop`): `SomcpTool::notFound('zones[<i>].id_zone', 'zone', $id, 'somcp_list_zones')`; `name` may be left out, and passes when it is the zone's name, otherwise `zones[<i>].name: the name of a zone is not changed here - leave it out or give it as somcp_list_zones answers it`; a zone given twice: `zones[<i>]: id_zone <id> is already in the list`; empty: `zones: at least one zone - turn the carrier off with active instead`, since a carrier with no zone is offered nowhere |
| `is_free` | **Free shipping** | boolean | no | `is_free: true or false`; given `true` alone, it turns `handling_costs` off, as 9.0.0 does |
| `handling_costs` | **Add handling costs** (8.0.1) / **Handling costs** (9.0.0) | boolean | no | `handling_costs: true or false`; given `true` alone, it turns `is_free` off; both given `true`: `handling_costs: a free carrier adds no handling costs - set is_free false in the same call` |
| `billing` | **Billing**: **According to total price.** / **According to total weight.** (8.0.1); **Shipping costs**: **Based on the order's total price** / **Based on the order's total weight** (9.0.0) | string | no | `"price"` (stored 2) or `"weight"` (stored 1), otherwise `billing: price or weight`; compared with the billing the read answers (`SomcpToolGetCarrier::method()`, the default method resolved), so a carrier stored with 0 given back its own `billing` keeps 0; `null`, as a free carrier is read, passes when the read says so |
| `id_tax_rules_group` | **Tax** | integer | no | `0` (**No tax**) or a tax rule from `somcp_list_tax_rules`, checked with `SomcpToolGetTaxRule::existingRule()`; unknown: `SomcpTool::notFound('id_tax_rules_group', 'tax rule', $id, 'somcp_list_tax_rules')`; written for the shop of the token only |
| `out_of_range` | **Out-of-range behavior** / **Out of range behavior** | string | no | `"highest_range"` (**Apply the cost of the highest defined range**, 0) or `"disable_carrier"` (**Disable carrier**, 1), otherwise `out_of_range: highest_range or disable_carrier` |
| `max_width` | **Maximum package width** (8.0.1) / **Max order width** (9.0.0) | integer | no | `0` for no limit; a whole number from 0, in `PS_DIMENSION_UNIT`, otherwise `max_width: a whole number from 0, in cm, 0 for no limit`; `12.5` or `"12"` is refused, not rounded |
| `max_height` | **Maximum package height** / **Max order height** | integer | no | the same, `max_height:` |
| `max_depth` | **Maximum package depth** / **Max order depth** | integer | no | the same, `max_depth:` |
| `max_weight` | **Maximum package weight** / **Max order weight** | number | no | `0` for no limit; a number from 0 (a string of digits read as one, through `SomcpToolCreateProduct::float()`), in `PS_WEIGHT_UNIT`, otherwise `max_weight: a number from 0, in kg, 0 for no limit`; a negative weight is refused on both versions, as the 9.0.0 form does (`PositiveOrZero`), since it would match no cart and hide the carrier; compared rounded to 6 decimals, so `5` and `5.000000` are equal |
| `logo` | **Logo** | object | no | merchant: a new logo, which replaces the current one, `{url}` or `{upload_ref}` through `SomcpUpload::schema()` and `argument()`, declared with `files: ['logo' => SomcpUpload::images()]`, the rules of `somcp_update_manufacturer`; the `download_ref` `somcp_get_carrier` answers for this carrier (or `null` when it has none) is no change, recognised by the file it names through `SomcpUpload::same($logo, 'carrier_logo', $id)`, and nothing is downloaded |
| `remove_logo` | the **Delete** of **Logo** | boolean | no | merchant: `true` removes the logo, a change only when the carrier has one; `remove_logo: true or false`; with `logo`: `remove_logo: not with logo, which already replaces the current one` |
| `ranges` | **Ranges** (8.0.1) / **Set ranges and prices** (9.0.0) | array | no | the whole list after the call, in the shape `somcp_get_carrier` answers: `{from, to, prices: [{id_zone, zone, price_tax_excl}]}`, `from` the **>=** / **Minimum** bound (included), `to` the **<** / **Maximum** bound (excluded), in `range_unit`; `price_tax_excl` a number from 0, or `null` (or the zone left out) for no price in that zone; `zone` is read back only. Every problem in the same list: not a list: `ranges: a list of {from, to, prices} as somcp_get_carrier answers it`; `ranges[<i>]: from and to are numbers from 0, to above from`; `ranges[<i>]: starts below the end of the range before it - the maximum of a range is the minimum of the next` (once sorted by `from`, the rule of the alert of 9.0.0); `ranges[<i>].prices: id_zone <id> is not served by this carrier - serve the zone first` (the zones after the call, `zones` given or stored); `ranges[<i>].prices: id_zone <id> is already in this range`; `ranges[<i>].prices: price_tax_excl of zone <id> is a number from 0, or null for no price`; a `zone` that is not the zone's name: `ranges[<i>].prices: the name of zone <id> is not changed here - leave it out or give it as somcp_get_carrier answers it`; a non-empty list on a carrier with no billing by price or weight after the call (a free one): `ranges: the carrier has no billing by price or weight - set billing in the same call` (the empty list a free carrier is read with passes) |
| `position` | **Position** (shown plus 1) | integer | no | merchant: the place among the carriers, from 0 as `somcp_list_carriers` and `somcp_get_carrier` give it; the others shift. Refused outside 0 to the number of carriers not deleted minus 1: `position: a whole number from 0 to <last>, the place among the carriers`; left out, the carrier keeps its place |

Unknown arguments through `SomcpTool::unknownFields()`. The other keys of the answer of `somcp_get_carrier` (`id_reference`, `external_module`, `tax_rule`, `handling_cost_tax_excl`, `range_unit`, `dimension_unit`, `weight_unit`) pass silently when they hold what the shop holds (numbers compared as numbers; `range_unit`, `tax_rule` and `handling_cost_tax_excl` with what the carrier shows once the call is done, through the public `SomcpToolGetCarrier::rangeUnit()`, so a read taken before a change of billing, tax or handling costs can come back with it), and are refused as `changed by another tool, not this one` when they differ; `language` and `warning` always pass.

**Errors** - in the envelope `Nothing was changed. Correct these and call again:` (`SomcpTool::refused('changed', $problems)`), every problem at once. A failed save after a new version was made names both identifiers, with the hint to read the new one with `somcp_get_carrier` before calling again. A failed move answers `The carrier could not be moved to position <n>: <reason>. The other changes of this call may be saved. Read the carrier again with somcp_get_carrier, tell the merchant what it holds now, and do not call again before.`, logged as the other failures. A logo the shop refuses (an `upload_ref` the account does not hold or that expired, an address that answers no image, a file that is not an image) with nothing else to change is refused in the same envelope, `- logo: <reason>` (for example `- logo: no file of this account matches this upload_ref, or it expired or was already used`), and nothing is written: no new version, no hook. With other fields to change, the logo leaves the call, the other fields are written, and the answer gives `logo` with its `refused` reason and the `warning` `The other changes were saved, but the logo was not: <reason>.`, as for a logo that could not be stored after the other fields were saved.

**Then** - every field given is compared with the stored value first (`delay` language by language, `group_access` and `zones` as sorted lists). Nothing differs: `changed: false` and `The carrier already holds every value given. Nothing was written.`, nothing saved, no new version. Something differs: while no order uses the carrier (`order_carrier`, counted as `CarrierRepository::getOrdersCount()` of 9.0.0), it is changed in place; once one does, a new version is made as 9.0.0 makes it (`duplicateObject()`, the old one `deleted`, `Carrier::copyCarrierData()`, the old groups), then `actionCarrierUpdate` is fired with the old `id_carrier` and the new carrier, as the 8.0.1 wizard does. `name`, `grade`, `url`, `active` and `delay` are set on the object and saved with `update()` for the shop of the token; `setGroups()` only when `group_access` changes. The zones are written on the carrier now in use, after `update()` (and after `copyCarrierData()` for a new version, which copies the old ones), as 9.0.0 does in `CarrierRepository::updateAssociatedZones()`: the `carrier_zone` rows of the carrier deleted, then one row per zone given, with `Db::delete()` and `Db::insert()`, since no object writes that table alone. The `delivery` rows are left as they are on both versions: `Carrier::addZone()` of 8.0.1 would add a price of 0 in every range, making the carrier free in a zone the merchant never priced, and `deleteZone()` would drop prices no tool gives back; a failed zone write names what was saved, through the same failure answer. `changes` records `zones` with `old` and `new` the sorted lists of `id_zone`. `is_free`, `shipping_handling`, `shipping_method`, `range_behavior` and the four limits go in the same `update()` (`max_weight` through `hydrate()`, since 8.0 declares the property an int while the column keeps a decimal); the prices of the ranges are kept when the carrier turns free, as 9.0.0 does, rather than deleted as the 8.0.1 wizard does, since a free carrier charges nothing whatever they hold and they would be lost once it is paid again. The tax is written after, with `Carrier::setTaxRulesGroup($id, false)` on the carrier now in use, for the shop of the token, so a call that changes both makes one version; a failed tax write names what was saved, through the same failure answer. `position` is written last with `Carrier::updatePosition()`, where the drag and drop of the 8.0.1 list ends and the same move as the 9.0.0 **Rearrange**, through the shared `SomcpCatalog::movePosition()`, so the other carriers shift as there; the core finds the carrier from its own id, so no request value is handed over. A move is not a change of the carrier: alone, it never makes a new version, even when an order uses the carrier, as neither version of the core makes one; with fields that do, the carrier now in use is moved after them. `changes` records `position` with its old and new place. The logo counts as a change of the carrier: a logo given always does (an address cannot be compared with a stored file), a removal only when there is one; it is written on the carrier now in use, after the new version is made (whose `copyCarrierData()` has already copied the old logo), so the old version keeps its own for past orders. The file comes through `SomcpUpload::fetchImage()` into a temporary file before anything is written, where the brand and supplier tools fetch it after, since a carrier an order uses gets a new identifier at the first write and a logo that is never stored must not cause one; then, once the carrier is saved, `ImageManager::resize()` to `img/s/<id_carrier>.jpg` (`_PS_SHIP_IMG_DIR_`), forced to JPEG, as the 8.0.1 wizard writes it, on both versions; `remove_logo` deletes that file, as `CarrierLogoFileUploader::deleteOldFile()` does on 9.0.0; both then delete the copies the back office list keeps in the image cache (`carrier_mini_<id>.jpg`, `carrier_mini_<id>_<id_shop>.jpg`). `changes` records `logo` with `old` the `download_ref` read before and `new` the `url` or `upload_ref` given (`SomcpUpload::origin()`), or `null` for a removal. `ranges` is sorted by `from`, then compared with the ranges of the billing the carrier has after the call (`SomcpToolGetCarrier::ranges()`, over the zones after the call), bounds and prices as numbers, so `50` and `50.000000` are equal and the read given back is no change. Decision (the whole list): `ranges` replaces every range of the billing in one call, as both back offices save the whole table, rather than separate operations per range: a range has no identifier the merchant sees, and the bounds of the next range move with it. Decision (the `delivery` rows): written global (`id_shop` and `id_shop_group` NULL) on both versions, as 9.0.0 does in `CarrierRangeRepository::set()`, and every row of the carrier for that range table deleted first whatever its shop, rather than the rows of the shop context as 8.0.1 does, since `SomcpToolGetCarrier::ranges()` picks a shop row before the global one and a row left behind would hide what was just written. The write, on the carrier now in use (after `copyCarrierData()` for a new version, so the copied ranges are replaced, never added), in one transaction: the `delivery` rows of the carrier for `range_price` or `range_weight`, then its rows of that table, deleted; each range inserted (`delimiter1`, `delimiter2`), then one `delivery` row per zone with a price, with `Db::insert()`, since `RangePrice::add()` and `RangeWeight::add()` would add a price of 0 in every zone; the ranges of the other billing are kept, so a carrier switched back finds them again, as on both back offices. A failed range write names what was saved, through the same failure answer. `changes` records `ranges` with `old` and `new` the two lists in the shape of `somcp_get_carrier`.

**Returns** - `changed: true`, `id_carrier` (the carrier now in use), `changes` (one entry per field changed: `field`, `old`, `new`, `delay (fr)` for a language, `billing` and `out_of_range` in their words), with a new version `replaced_id_carrier` and a `warning` (`An order uses this carrier, so the shop keeps it for that order and gives the carrier a new identifier, <new>. Use <new> from now on.`), with more than one shop `also_in_other_shops` (the other shops of the carrier) and a `warning`, with an added zone that has no price yet in `delivery` for the billing of the carrier a `warning` naming it (`The zone(s) <ids> have no price yet: the shop does not offer this carrier there until its ranges get one.`), none for a free carrier, which the shop offers in every zone it serves without a price, with ranges written and more than one shop in the installation a `warning` that their prices now apply in every shop, with a logo given or removed `logo` (the `url` or `upload_ref` with `stored: true`, `removed: true`, or the `refused` reason), then `carrier`, the object of `somcp_get_carrier` read back through `SomcpToolGetCarrier::carrier()`.

**Notes** - replayable: the same call twice changes nothing the second time. The carrier of the shop of the token only; its name, **Speed grade**, **Logo**, **Tracking URL**, status, groups, zones (`carrier_zone` has no shop column), place in the list, **Free shipping**, handling costs, billing, **Out-of-range behavior** and the size and weight limits are shared by every shop (a move says so in the `warning` with more than one shop), the **Transit time** and the **Tax** are per shop. A carrier made by a module is changed the same way, as the back office does. `update()` writes back the `shipping_method` the constructor resolves from `PS_SHIPPING_METHOD` when `0` is stored, as the form does: the billing does not change.

**Differences between 8.0.1 and 9.0.0** - the page (the wizard, with **Enabled** in **Summary** and **Group access** in step 3, against the **General settings** tab of a Symfony form) and when a new version is made: at every save on 8.0.1, only once an order uses the carrier on 9.0.0; the place is dragged in the 8.0.1 list and set in the **Rearrange** mode of 9.0.0, both through `Carrier::updatePosition()`; 8.0.1 deletes the prices of a free carrier and 9.0.0 does not (the tool keeps them on both); 9.0.0 switches free shipping and handling costs against each other, 8.0.1 saves what is posted (the tool follows 9.0.0); a blank limit is 0 on 8.0.1 and refused on 9.0.0 (the tool takes 0 for no limit, never a blank); 8.0.1 adds prices of 0 for an added zone and deletes the prices of a removed one, 9.0.0 touches only `carrier_zone` (the tool follows 9.0.0 on both); the ranges are a table edited in place with **Add new range** and **Delete** on 8.0.1 and the **Ranges** window with **Add range** on 9.0.0, their prices written for the shop context on 8.0.1 and global on 9.0.0, the bounds unchecked by the 8.0.1 server and checked by `CarrierRangeZone::assertRanges()` on 9.0.0 (the tool writes global rows and checks the bounds on both); the logo is uploaded by AJAX into the image cache and copied at save on 8.0.1, any image resized to JPEG, and checked at save on 9.0.0, JPEG only, 8 MB at most (the tool takes every image the module takes, 10 MiB at most, as a JPEG, on both: the stored file is the same). The tool follows 9.0.0 on both, so a carrier no order uses keeps the identifier the assistant has just read. The columns and `Carrier::copyCarrierData()` are the same.

#### `somcp_delete_carrier`
**State**: written · [issue #321](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/321)
**Nature**: `acts(DELETE, 'AdminCarriers')` · **Scope**: `mcp.orders.write`

**Does** - deletes one carrier of **Shipping > Carriers**, as the **Delete** of its row (and **Delete selected** in **Bulk actions**, one carrier per call). Irreversible: the description asks the assistant to confirm with the merchant by name first, and to turn the carrier off with `somcp_update_carrier` when it may come back.

**Do not use for** - turning a carrier off (→ `somcp_update_carrier`, `active`); the carrier of one order (→ `somcp_set_order_carrier`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_carrier` | the row deleted | integer | yes | from `somcp_list_carriers`; missing or not a whole number: `SomcpTool::notAnIdentifier()`; unknown or already `deleted`: the line of `SomcpToolGetCarrier::notFound()` |

Unknown arguments through `SomcpTool::unknownFields()`, with the fields of `somcp_list_carriers` and `somcp_get_carrier` named as worked out by the shop, so a carrier given whole is understood.

**Errors** - in the envelope `Nothing was deleted. Correct these and call again:` (`SomcpTool::refused('deleted', $problems)`), every problem at once, nothing written:
- the default carrier (`PS_CARRIER_DEFAULT` of the shop of the token): `id_carrier: <id> is the default carrier of the shop - choose another default carrier in Shipping > Preferences first`; deleted, the checkout would go on pointing to a carrier no list shows;
- a carrier of a module (`is_module`): `id_carrier: <id> belongs to the module <external_module_name>, which manages it - disable it with somcp_update_carrier, or manage it from that module`; the module keeps its reference and would recreate it or stop offering delivery.

A delete that fails is checked on the table and answered `The carrier could not be deleted.`, with the hint to read `somcp_list_carriers`.

**Then** - one behaviour on both versions, the one of 9.0.0: used by an order (`Carrier::isUsed()`), it is flagged `deleted = 1` and saved, never `Carrier::delete()`, which on 8.0.1 would remove the row the orders point to; used by none, `Carrier::delete()` removes it with its links (`cart_rule_carrier`, `module_carrier`, its tax rules group rows). Then `Warehouse::removeCarrier()` by its `id_reference` and `Carrier::cleanPositions()`, as the 8.0.1 list does. Checked on the table rather than trusted from the core: gone, or `deleted = 1` when kept for orders. Logged as `carrier_deleted`.

**Returns** - `deleted: true`, `id_carrier`, `carrier` (the object of `somcp_get_carrier` as it was before the deletion), `kept_for_orders` (`true` with the `warning` `Orders use this carrier: PrestaShop keeps it hidden for them, and it is gone from the list.` when it was only flagged); with more than one shop, `also_in_other_shops` and a `warning`, since a carrier is not tied to one shop.

**Notes** - not replayable: a second call finds no carrier. The demo carriers: 1 is the default and is refused, 2 is used by orders and is kept hidden, 3 and 4 are removed.

**Differences between 8.0.1 and 9.0.0** - the core: the 8.0.1 list only flags every carrier `deleted`, used or not, and removes its logo; 9.0.0 removes a carrier no order uses and flags the others. The tool behaves the same on both, as 9.0.0 does, so `Carrier::delete()` is only called on a carrier no order uses, where it gives the same result on both.

#### `somcp_get_shipping_preferences`
**State**: written · [issue #391](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/391)
**Nature**: `acts(VIEW, 'AdminShipping')` · **Scope**: `mcp.orders.read`, as `somcp_get_carrier`

**Does** - reads **Shipping > Preferences**: the card **Handling**
(**Handling charges**, **Free shipping starts at** a price and a weight) and
the card **Carrier options** (**Default carrier**, **Sort by**, **Order
by**). These settings decide what every order pays for shipping on top of the
carriers' ranges, and which carrier a customer finds selected. Read only,
replayable.

**Do not use for** - the price ranges and costs of one carrier (→
`somcp_get_carrier`); the free shipping of a cart rule (→
`somcp_get_cart_rule`); changing them (→ `somcp_update_shipping_preferences`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code for the transit time of the default carrier, through `SomcpTool::readLanguage()` (#149); a code the shop does not have is refused with `SomcpTool::languageProblem()`. The shop default when left out |

Unknown arguments through `SomcpTool::unknownFields()`, in the envelope
`Nothing was read. Correct these and call again:`.

**Returns** - one object, built in the public
`SomcpToolGetShippingPreferences::preferences($idShop, $idLang)`, for the
update to answer with, each key read with `Configuration::get(<key>, null,
$idShopGroup, $idShop)` for the shop of the token and its group:

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `handling_cost_tax_excl` | **Handling charges** | `PS_SHIPPING_HANDLING` | float, tax excluded, the name `somcp_get_carrier` gives it |
| `free_shipping_from_price` | **Free shipping starts at** (price) | `PS_SHIPPING_FREE_PRICE` | float, `0` when off |
| `free_shipping_from_weight` | **Free shipping starts at** (weight) | `PS_SHIPPING_FREE_WEIGHT` | float, `0` when off |
| `currency` | - | `PS_CURRENCY_DEFAULT` | ISO code of the default currency the two amounts are in |
| `weight_unit` | - | `PS_WEIGHT_UNIT` | the unit of the weight, as `somcp_get_localization_settings` names it |
| `default_carrier` | **Default carrier** | `PS_CARRIER_DEFAULT` | `best_price` (-1), `best_grade` (-2) or `carrier` |
| `default_id_carrier`, `default_carrier_name`, `default_carrier_delay` | - | - | with `carrier`, the current version of the carrier of the stored **reference** (the value of the list is an `id_reference`), found through `SomcpToolListCarriers::query()` for the shop of the token: its `id_carrier` (the identifier `somcp_list_carriers` gives), name and transit time in `language`; `null` otherwise |
| `carrier_sort` | **Sort by** | `PS_CARRIER_DEFAULT_SORT` | `price` (0) or `position` (1) |
| `carrier_order` | **Order by** | `PS_CARRIER_DEFAULT_ORDER` | `ascending` (0) or `descending` (1) |
| `language` | - | - | the ISO code of the transit time |

A reference whose carrier is deleted or not in the shop: `default_id_carrier`
`null` and `warning` `The default carrier no longer exists: customers get the
first carrier available; choose another in Shipping > Preferences.`

**Notes**
- The core compares **Free shipping starts at** (price) with the products and discounts of the cart, tax included, and the weight with `Cart::getTotalWeight()`; each applies only above 0. Coupons are not taken into account.
- The demo shops hold the same values: handling 2, both thresholds 0, default carrier reference 1, sorted by price, ascending.

**Differences between 8.0.1 and 9.0.0** - none: the same forms, keys and
adapters (`HandlingConfiguration`, `CarrierOptionsConfiguration`).

#### `somcp_update_shipping_preferences`
**State**: written · [issue #392](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/392)
**Nature**: `acts(EDIT, 'AdminShipping')` · **Scope**: `mcp.orders.write`, as `somcp_update_carrier`

**Does** - changes **Shipping > Preferences**, as the **Save** buttons of its
cards **Handling** and **Carrier options** do: **Handling charges**, the two
**Free shipping starts at**, **Default carrier**, **Sort by**, **Order by**.
Replayable: the same call twice changes nothing the second time.

**Do not use for** - the ranges and costs of one carrier, or whether it adds
handling costs (→ `somcp_update_carrier`); a free shipping voucher (→
`somcp_create_cart_rule`).

**Parameters** - all optional; a key left out keeps its value. The keys are
those of `somcp_get_shipping_preferences`, whose answer can be given back
whole: `currency`, `weight_unit`, `default_carrier_name`,
`default_carrier_delay`, `language` and `warning` are worked out by the shop,
ignored when given back as read (`language` when it is an active language of
the shop, the transit time then compared in it) and refused otherwise
(`- <key>: worked out by the shop, so it is not written here`).

| Name | On screen | Type | Rule |
|---|---|---|---|
| `handling_cost_tax_excl` | **Handling charges** | number | 0 or more, `Validate::isPrice()`: `handling_cost_tax_excl: an amount of 0 or more, tax excluded, in <currency>` |
| `free_shipping_from_price` | **Free shipping starts at** (price) | number | the same rule and line; 0 turns it off |
| `free_shipping_from_weight` | **Free shipping starts at** (weight) | number | 0 or more, `Validate::isUnsignedFloat()`: `free_shipping_from_weight: a weight of 0 or more, in <weight unit>`; 0 turns it off |
| `default_carrier` | **Default carrier** | string | `best_price`, `best_grade` or `carrier`, otherwise `default_carrier: best_price, best_grade or carrier`; `carrier` without `default_id_carrier`: `default_id_carrier: required with default_carrier carrier`, except when a carrier is already the default (the read of a default carrier deleted since, given back) |
| `default_id_carrier` | **Default carrier** | integer | from `somcp_list_carriers`, through `SomcpTool::id()`; implies `default_carrier` `carrier` when given alone; with `best_price` or `best_grade`: `default_id_carrier: only with default_carrier carrier`. The list of the screen offers every carrier of the shop not deleted, enabled or not (`CarrierByReferenceChoiceProvider` asks `Carrier::getCarriers()` for all of them), read through `SomcpToolListCarriers::query()` since the provider lives in the back office's container; unknown: `SomcpTool::notFound('default_id_carrier', 'carrier', <id>, 'somcp_list_carriers')`; a deleted carrier or an older version: `default_id_carrier: the carrier <id> is not offered by the Default carrier list, which leaves out deleted carriers and older versions - find the current one with somcp_list_carriers` |
| `carrier_sort` | **Sort by** | string | `price` or `position`, otherwise `carrier_sort: price or position` |
| `carrier_order` | **Order by** | string | `ascending` or `descending`, otherwise `carrier_order: ascending or descending` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:` (`SomcpTool::refused('changed', $problems)`), every problem at once,
before anything is written. A failed save stops the call and names what was
saved before it, with the hint to read `somcp_get_shipping_preferences`
before calling again, logged as `shipping_preferences_update_failed`.

**Then** - `SomcpToolGetShippingPreferences::preferences($idShop, $idLang)` is
read first; the keys whose value differs (amounts compared as numbers, so `2`
and `2.0` are equal) are saved in the order of the two cards with
`Configuration::updateValue(<key>, <value>, false, $idShopGroup, $idShop)` for
the shop of the token, as `HandlingConfiguration` and
`CarrierOptionsConfiguration` save them: the amounts as floats,
`PS_CARRIER_DEFAULT` as `-1`, `-2` or the carrier's **`id_reference`** (never
its `id_carrier`: the default follows a new version of the carrier),
`PS_CARRIER_DEFAULT_SORT` and `PS_CARRIER_DEFAULT_ORDER` as `0` or `1`.
Nothing differs: `changed: false`, `reason` `The shipping preferences already
hold every value given. Nothing was written.`, `preferences` as read. Logged
as `shipping_preferences_updated` with the fields saved.

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per field its `field`, `old`, `new`, under the names and codes of
the parameters; a new default carrier gives `default_carrier` and
`default_id_carrier` as each changes), and `preferences` read back through
`preferences()`. Warnings, beside the answer:
- `handling_cost_tax_excl` changed: `Handling charges are added only by the carriers whose Handling costs is on (handling_costs of somcp_get_carrier).`;
- `free_shipping_from_price` or `free_shipping_from_weight` set above 0: `Every carrier is now free from <amount> <currency or weight unit>, whatever its own ranges.`;
- more than one shop: `The settings changed for this shop only, as the back office saves them in a shop context.`, the sentence of `somcp_update_tax_options`.

**Notes** - the demo shops hold handling 2, both thresholds 0, default
carrier reference 1 (**Click and collect**), sorted by price, ascending.

**Differences between 8.0.1 and 9.0.0** - none: the same forms, keys and
adapters.

#### `somcp_get_payment_preferences`
**State**: written · [issue #395](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/395)
**Nature**: `acts(VIEW, 'AdminPaymentPreferences')` · **Scope**: `mcp.orders.read`, as `somcp_get_shipping_preferences`

**Does** - reads **Payment > Preferences**: for each payment module, the four
cards **Currency restrictions**, **Group restrictions**, **Country
restrictions** and **Carrier restrictions**. A shop sells only where a
payment module is offered. Read only, replayable. The payment modules
themselves (installation, accounts, keys) are out of scope.

**Do not use for** - changing the currencies, groups, countries and carriers
a module is offered to (→ `somcp_update_payment_restrictions`); installing, enabling or
configuring a payment module (→ `somcp_get_module`,
`somcp_set_module_active`); the payment methods of an order (→
`somcp_get_order`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | no | technical name of one payment module, as `somcp_list_modules` gives it, compared without case; every payment module when left out. Not a payment module: `SomcpTool::notFound('name', 'payment module', <name>, 'somcp_list_modules', 'technical name')` |
| `language` | string | no | ISO code for the names of the groups and carriers, through `SomcpTool::readLanguage()` (#149); a code the shop does not have is refused with `SomcpTool::languageProblem()`. The shop default when left out |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was read. Correct these and call again:`.

**Returns** - `payment_modules`, built in the public
`SomcpToolGetPaymentPreferences::preferences($idShop, $idLang, $name)` for the
update to answer with, one object per module in the order of the page
(display name). The modules are the installed modules whose instance is a
`PaymentModule`, enabled or not, as `PaymentModuleListProvider` lists the
columns, in the public `SomcpToolGetPaymentPreferences::modules($idShop)`.
Each table is read on `id_module` and the `id_shop` of the token:

| Key | On screen | Value |
|---|---|---|
| `name`, `display_name` | the column | technical and display name |
| `active` | - | the module enabled in this shop (a row of `module_shop`) |
| `currencies_mode` | - | `checkbox` or `radio` (one choice, and the rows **Customer currency** and **Shop default currency**) |
| `currencies` | **Currency restrictions** | `module_currency`: `{id_currency, iso_code}`, the identifiers of `somcp_list_currencies`, a deleted currency left out; `-1` as `{"id_currency": -1, "iso_code": null, "name": "Customer currency"}`, `-2` as `"Shop default currency"` |
| `groups` | **Group restrictions** | `module_group`: `{id_group, name}`, the identifiers of `somcp_list_customer_groups`, the name in `language` |
| `countries` | **Country restrictions** | `module_country`: `{id_country, iso_code}`, the identifiers of `somcp_list_countries` |
| `carriers` | **Carrier restrictions** | `module_carrier` stores the **reference**: `{id_carrier, name}`, the current version of each reference through `SomcpToolGetShippingPreferences::carrierOfReference()`, the identifier `somcp_list_carriers` gives; a reference whose carrier is deleted is left out |
| `limited_countries` | - | the ISO codes the module restricts itself to (`Module::$limited_countries`), lowercase, or `null` |

and `language`. No payment module: `payment_modules: []` and `warning` `No
payment module installed`, the sentence of the page. A module with an empty
list: `warning` `<display name> is offered to no <lists>: customers cannot pay
with it.`, given as soon as one of the four lists is empty (the checkout
offers a module only to a cart that matches a row of each), naming the empty
lists joined with `, ` and ` or ` before the last one: `no carrier`, `no
currency or group`, `no currency, group, country or carrier`.

**Notes**
- The demo shops: four payment modules (`ps_cashondelivery`, `ps_checkpayment`, `ps_checkout`, `ps_wirepayment`), each offered to the currencies of the shop, the groups 1 to 3 and the carrier references 1 to 4; `ps_checkout` to 200 countries, the three others to US only; every module in `checkbox` mode.

**Differences between 8.0.1 and 9.0.0** - none in the restrictions; the
display names differ in the demo data (**Wire payment** on 8.0.1, **Bank
transfer** on 9.0.0).

#### `somcp_update_payment_restrictions`
**State**: written · [issue #396](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/396)
**Nature**: `acts(EDIT, 'AdminPaymentPreferences')` · **Scope**: `mcp.orders.write`, as `somcp_update_shipping_preferences`

**Does** - changes **Payment > Preferences** for one payment module, as the
**Save** button of its cards **Currency restrictions**, **Group
restrictions**, **Country restrictions** and **Carrier restrictions** does
(#396, #397): each list given replaces the stored one, a list left out is
kept. One module of the answer of `somcp_get_payment_preferences` can be
given back as it is.

**Do not use for** - installing, enabling or configuring a payment module (→
`somcp_install_module`, `somcp_set_module_active`); the currencies and groups
themselves (→ `somcp_update_currency`, `somcp_list_customer_groups`).

**Parameters**

`display_name`, `active`, `currencies_mode`, `limited_countries`, `language`
and `warning` are worked out by the shop, ignored when given back as read
(`language` when it is an active language of the shop, the names of the
answer then read in it) and refused otherwise (`- <key>: worked out by the
shop, so it is not written here`), as `SomcpToolUpdateShippingPreferences`
does.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `name` | the module's column | string | required (`name: required`), the technical name of a payment module of `SomcpToolGetPaymentPreferences::modules()`, compared without case; otherwise `SomcpTool::notFound('name', 'payment module', <name>, 'somcp_get_payment_preferences', 'technical name')` |
| `currencies` | **Currency restrictions** | array | the full list after the call. Each item an `id_currency` or the object the read gives (`{id_currency, ...}`, the other keys ignored); each a currency of `SomcpToolListCurrencies::currency()`, otherwise `SomcpTool::notFound('currencies', 'currency', <id>, 'somcp_list_currencies')`. A module in `checkbox` mode: any number, `[]` allowed; `-1` or `-2`: `currencies: -1 and -2 only for a module that takes one currency`. A module in `radio` mode: exactly one item, an `id_currency`, `-1` (**Customer currency**) or `-2` (**Shop default currency**), otherwise `currencies: <display name> takes one currency, or -1 (Customer currency) or -2 (Shop default currency)` |
| `groups` | **Group restrictions** | array | the full list after the call, the same way: `id_group` or `{id_group, ...}`; each a group of `Group::getGroups($idLang, $idShop)`, otherwise `SomcpTool::notFound('groups', 'customer group', <id>, 'somcp_list_customer_groups')`; `[]` allowed |
| `countries` | **Country restrictions** | array | the full list after the call, the same way: `id_country` or `{id_country, ...}`; each a country of `SomcpToolListCountries::country()`, otherwise `SomcpTool::notFound('countries', 'country', <id>, 'somcp_list_countries')`; a country outside the module's `limited_countries`: `countries: <display name> works only in <ISO codes>`; `[]` allowed |
| `carriers` | **Carrier restrictions** | array | the full list after the call: `id_carrier` or `{id_carrier, ...}`, turned into its `id_reference` through `SomcpToolListCarriers::query()`, as the **Default carrier** of `somcp_update_shipping_preferences`; unknown: `SomcpTool::notFound('carriers', 'carrier', <id>, 'somcp_list_carriers')`; a deleted carrier or an older version: `carriers: the carrier <id> is not offered by the Carrier restrictions list, which leaves out deleted carriers and older versions - find the current one with somcp_list_carriers`; two versions of one carrier keep one row; `[]` allowed |
| `language` | - | string | ISO code for the names of the answer, through `SomcpTool::readLanguage()`; the shop default when left out |

A list that is not a list: `<list>: a list of <key>, [] for none`; an item
that is not a whole number: `SomcpTool::notAnIdentifier(<list>)`. A list given
twice the same identifier keeps it once. Unknown arguments through
`SomcpTool::unknownFields()`.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:` (`SomcpTool::refused('changed', $problems)`), every problem at once,
before anything is written. A failed save stops the call and names the lists
saved before it, with the hint to read `somcp_get_payment_preferences` before
calling again, logged as `payment_restrictions_update_failed`.

**Then** - `SomcpToolGetPaymentPreferences::preferences($idShop, $idLang,
$name)` is read first; each list whose set of identifiers differs is written
by the public `SomcpToolUpdatePaymentRestrictions::replace($type, $idModule,
$idShop, $ids)`, shared with part 2, as the core's
`PaymentRestrictionsConfigurator::configureRestrictions()` writes it, since
the configurator lives in the back office's container: `Db::delete()` of the
rows of `module_<type>` for the module and the shop of the token, then
`Db::insert()` of one row per identifier, every value cast to `int`. `$type`
is `currency`, `group`, `country` or `carrier`, a fixed map to the table and
its column (`id_reference` for `carrier`), never built from the request.
Nothing differs: `changed: false`, `reason` `The payment module already has
every list given. Nothing was written.`, `preferences` as read. Logged as
`payment_restrictions_updated` with the module and the lists saved (names and
counts only).

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per list its `field`, `added` and `removed` identifiers, carriers
by `id_carrier`), and `preferences`, the module read back through
`preferences()`. Warnings, beside the answer:
- a list left empty: `<display name> is now offered to no <lists>: customers cannot pay with it.`, the sentence of `somcp_get_payment_preferences` (`SomcpToolGetPaymentPreferences::offeredToNone()`), the empty lists joined with `, ` and ` or ` before the last one (`no customer group or carrier`);
- the module disabled: `<display name> is disabled: these restrictions apply once it is enabled with somcp_set_module_active.`.

**Notes** - the demo shops: every module in `checkbox` mode; `module_group`
1, 2, 3 for each module on both; `module_currency` 1 and 2 on 8.0.1, 1 on
9.0.0; `module_country` US only for `ps_cashondelivery`, `ps_checkpayment`
and `ps_wirepayment`, 200 countries for `ps_checkout`; `module_carrier`
references 1 to 4 for every module; no module limits its countries but
`ps_checkout`.

**Differences between 8.0.1 and 9.0.0** - none: the same configurator and
tables.

#### `somcp_get_email_settings`
**State**: written · [issue #398](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/398)
**Nature**: `acts(VIEW, 'AdminEmails')` · **Scope**: `mcp.system.read`, as `somcp_get_cache_status`

**Does** - reads the card **Email** of **Advanced Parameters > E-mail**: where
customer messages go, how the shop sends its e-mails, the SMTP parameters,
the subject prefix, the format, the e-mail log and the DKIM signing. Read
only, replayable. The **SMTP password** and the **DKIM private key** are never
read: the answer says only whether each one is set. The list of sent e-mails
(→ `somcp_list_email_logs`) and **Send a test email** are not part of it.

**Do not use for** - changing these settings (→
`somcp_update_email_settings`); the e-mail of an order status (→
`somcp_resend_order_status_email`); the layout of the e-mails (**Design >
Email Theme**, not covered yet).

**Parameters** - none. Unknown arguments through
`SomcpTool::unknownFields()`, in the envelope `Nothing was read. Correct these
and call again:`.

**Returns** - one object, built in the public
`SomcpToolGetEmailSettings::settings($idShop)` for the update to answer with,
each key read with `Configuration::get(<key>, null, $idShopGroup, $idShop)`
for the shop of the token and its group, as `EmailDataConfigurator` reads
them:

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `send_emails_to` | **Send emails to** | `PS_MAIL_EMAIL_MESSAGE` | the `id_contact` stored, `null` when no row exists (the list then shows its first contact) |
| `send_emails_to_choices` | - | - | the contacts the list offers, `{id_contact, name}`, `Contact::getContacts()` in the shop's default language; each in full with `somcp_list_contacts` |
| `mail_method` | the three choices under **Send emails to** | `PS_MAIL_METHOD` | `sendmail` (1), `smtp` (2) or `never` (3) |
| `smtp_domain` | **Email domain name** | `PS_MAIL_DOMAIN` | text |
| `smtp_server` | **SMTP server** | `PS_MAIL_SERVER` | text |
| `smtp_username` | **SMTP username** | `PS_MAIL_USER` | text |
| `smtp_password_set` | **SMTP password** | `PS_MAIL_PASSWD` | `true` when not empty; the value is never in the answer |
| `smtp_encryption` | **Encryption** | `PS_MAIL_SMTP_ENCRYPTION` | `off`, `tls` or `ssl` (8.0.1 only) |
| `smtp_port` | **Port** | `PS_MAIL_SMTP_PORT` | integer, `null` when empty |
| `subject_prefix` | **Enable the store name as a prefix in the email's subject** | `PS_MAIL_SUBJECT_PREFIX` | boolean on 9.0.0, `null` on 8.0.1 where the setting does not exist |
| `mail_type` | the three choices of the format | `PS_MAIL_TYPE` | `html` (1), `text` (2) or `both` (3) |
| `log_emails` | **Log Emails** | `PS_LOG_EMAILS` | boolean; the e-mails kept are listed by `somcp_list_email_logs` |
| `dkim_enabled` | **DKIM signing** | `PS_MAIL_DKIM_ENABLE` | boolean |
| `dkim_domain`, `dkim_selector` | **DKIM domain**, **DKIM selector** | `PS_MAIL_DKIM_DOMAIN`, `PS_MAIL_DKIM_SELECTOR` | text |
| `dkim_key_set` | **DKIM private key** | `PS_MAIL_DKIM_KEY` | `true` when not empty; the key is never in the answer |

A `warning` when `mail_method` is `never`: `The shop sends no e-mail at all:
customers get no order confirmation.`; when it is `smtp` with `smtp_server`
empty: `SMTP is chosen but no SMTP server is set: e-mails cannot leave the
shop.` Both sentences are built in the public
`SomcpToolGetEmailSettings::warnings()`, which the update answers with too.

**Notes**
- The SMTP fields are shown by the page's script only with **Set my own SMTP parameters**; they are saved whatever the method.
- The demo shops: sendmail, both formats, server `smtp.`, port 25, no encryption, the e-mail log on, DKIM off, no password nor key; no row for `PS_MAIL_EMAIL_MESSAGE` nor `PS_MAIL_DOMAIN`.

**Differences between 8.0.1 and 9.0.0** - **Enable the store name as a
prefix in the email's subject** on 9.0.0 only; **SSL** among the encryptions
on 8.0.1 only.

#### `somcp_update_email_settings`
**State**: written · [issue #399](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/399)
**Nature**: `acts(EDIT, 'AdminEmails')` · **Scope**: `mcp.system.write`, as `somcp_clear_cache`

**Does** - changes the card **Email** of **Advanced Parameters > E-mail**, as
its **Save** button does, except the two secrets: the **SMTP password** and the
**DKIM private key** are typed by the merchant on the page and never go
through a tool, since what an assistant is given stays in its conversation.
Replayable: the same call twice changes nothing the second time.

**Do not use for** - sending a test e-mail (**Send a test email**, not
covered); the e-mail of an order status (→ `somcp_resend_order_status_email`).

**Parameters** - all optional; a key left out keeps its value. The keys are
those of `somcp_get_email_settings`, whose answer can be given back whole:
`send_emails_to_choices`, `smtp_password_set`, `dkim_key_set` and `warning`
are worked out by the shop, ignored when given back as read and refused
otherwise (`- <key>: worked out by the shop, so it is not written here`). A
value equal to the one read is always taken, so a setting stored before the
screen offered it does not refuse the read given back.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `send_emails_to` | **Send emails to** | integer | an `id_contact` of `send_emails_to_choices` or of `somcp_list_contacts`; otherwise `SomcpTool::notFound('send_emails_to', 'contact', <id>, 'somcp_get_email_settings')`, or `send_emails_to: a whole number above 0` |
| `mail_method` | the three choices | string | `SomcpTool::oneOf()`: `mail_method: one of sendmail, smtp, never` |
| `smtp_domain` | **Email domain name** | string | `Validate::isCleanHtml()`, otherwise `<field>: holds a script or an event attribute, which PrestaShop refuses`; empty allowed |
| `smtp_server` | **SMTP server** | string | the same rule |
| `smtp_username` | **SMTP username** | string | the same rule |
| `smtp_encryption` | **Encryption** | string | `off` or `tls`, and `ssl` on 8.0.1: `smtp_encryption: one of off, tls` on 9.0.0 |
| `smtp_port` | **Port** | integer | 1 to 65535, or `null` to empty it: `smtp_port: a port number from 1 to 65535` |
| `subject_prefix` | **Enable the store name as a prefix in the email's subject** | boolean | 9.0.0 only; on 8.0.1 `true` or `false` answers `SomcpTool::notOnThisVersion()`, `null` (as read) is ignored |
| `mail_type` | the format | string | `mail_type: one of html, text, both` |
| `log_emails` | **Log Emails** | boolean | `log_emails: true or false` |
| `dkim_enabled` | **DKIM signing** | boolean | the same rule |
| `dkim_domain` | **DKIM domain** | string | `Validate::isCleanHtml()`; empty allowed |
| `dkim_selector` | **DKIM selector** | string | the same rule |

`smtp_password`, `dkim_key`, or any name holding `passw`, `dkim_key` or
`private_key`: `- <key>: type it on the E-mail page of the back office; it
never goes through a tool`, before anything is written. Booleans are booleans
only (`"false"` is refused). Unknown arguments through
`SomcpTool::unknownFields()`.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:` (`SomcpTool::refused('changed', $problems)`), every problem at once,
before anything is written. A failed save stops the call and names what was
saved before it, with the hint to read `somcp_get_email_settings` before
calling again, logged as `email_settings_update_failed`.

**Then** - `SomcpToolGetEmailSettings::settings($idShop)` is read first; the
keys whose value differs are saved in the order of the card with
`Configuration::updateValue(<key>, <value>, false, $idShopGroup, $idShop)` for
the shop of the token, as `EmailDataConfigurator::updateConfiguration()` saves
them: `PS_MAIL_METHOD` and `PS_MAIL_TYPE` as their codes 1, 2, 3, booleans as
`0` or `1`, an emptied port as an empty string. `PS_MAIL_PASSWD` and
`PS_MAIL_DKIM_KEY` are never written. Nothing differs: `changed: false`,
`reason` `The e-mail settings already hold every value given. Nothing was
written.`, `settings` as read. Logged as `email_settings_updated` with the
names of the fields saved, never their values.

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per field its `field`, `old`, `new`, under the names and words of
the parameters), and `settings` read back through `settings()`. Warnings,
beside the answer:
- `mail_method` set to `never`: `The shop now sends no e-mail at all: customers get no order confirmation.`;
- `mail_method` set to `smtp` with no password set and `smtp_username` not empty: `The SMTP password is not set: type it on the E-mail page of the back office.`;
- `dkim_enabled` set to `true` with no key set: `DKIM is on but no private key is set: type it on the E-mail page of the back office.`;
- the warnings of `SomcpToolGetEmailSettings::warnings()` on what was read back (its sentence for `never` replaced by the one above);
- more than one shop: `The settings changed for this shop only, as the back office saves them in a shop context.`, the sentence of `somcp_update_tax_options`.

**Notes** - the tests never change `mail_method`: the CI shops would stop
sending the e-mails other tests read. Next step: `somcp_send_test_email` proves the shop
can send with what was saved.

**Differences between 8.0.1 and 9.0.0** - `subject_prefix` on 9.0.0 only;
`ssl` on 8.0.1 only.

#### `somcp_list_email_logs`
**State**: written · [issue #423](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/423)
**Nature**: `acts(VIEW, 'AdminEmails')` · **Scope**: `mcp.customers.read`, as `somcp_search_customers`

**Does** - lists the e-mails the shop sent, as the list **Email (<n>)** of
**Advanced Parameters > E-mail** shows them: to whom, which template, in which
language, with which subject, when. Read only, replayable. The scope is the
customers' one because each line holds the address of a customer.

**Do not use for** - the e-mail settings (→ `somcp_get_email_settings`); the
messages customers wrote (Customer Service, not covered); sending a test (→
`somcp_send_test_email`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `recipient` | string | **Recipient** contains this text, as the filter of the list |
| `template` | string | **Template** contains this text, for example `order_conf` |
| `subject` | string | **Subject** contains this text |
| `language` | string | the ISO code of a language of the shop (**Language**); every language when left out; a code the shop does not have is refused with `SomcpTool::languageProblem()` |
| `date_from` / `date_to` | string | **Sent**, `YYYY-MM-DD`, both days included (`date_to` up to 23:59:59), as `somcp_list_stock_movements` takes them; `date_to` before `date_from` refused (`date_to: a date not before date_from`) |
| `limit` | integer | 20 by default, 100 at most (`limit: a whole number from 1 to 100`) |
| `offset` | integer | 0 by default (`offset: a whole number, 0 at least`) |

The three texts are trimmed and passed through `pSQL()` inside `LIKE
'%<text>%'`, as the list does. Unknown arguments through
`SomcpTool::unknownFields()`.

**Returns**
- `emails`: one object per line, newest first (`id_mail` descending, as the
  list opens): `id_mail` (**ID**), `recipient` (**Recipient**), `template`
  (**Template**), `language` (the ISO code of **Language**, `null` for a
  language deleted since), `subject` (**Subject**), `sent` (**Sent**,
  `SomcpTool::isoDate()`);
- `returned`, `total_count` (every line that matches the filters), `offset`,
  as `somcp_list_stock_movements`;
- `log_emails`: **Log Emails**, read through `SomcpToolGetEmailSettings::settings()`.

`warning`, joined by a space when several apply:
- **Log Emails** off: `Log Emails is off: e-mails sent from now on are not added to this list. Turn it on with somcp_update_email_settings (log_emails).` (the lines kept from before are still listed, unlike the page, which hides the list);
- multistore on with more than one shop: `This list holds the e-mails of every shop: PrestaShop keeps one log for all of them.`

**Errors** - in the envelope `Nothing was read. Correct these and call again:`
(`SomcpTool::refused('read', $problems)`), every problem in one answer: an
unknown argument, a language the shop does not have, a date that is not
`YYYY-MM-DD`, `date_to` before `date_from`, `limit` or `offset` out of range,
a text filter that is not a string (`<field>: a text`).

**Notes**
- The table `mail` has no shop column: the log is one for every shop of the installation. A line is written by `Mail::send()` after each sent e-mail when **Log Emails** is on, one per recipient; the test e-mail of **Send a test email** is not logged.
- The `id_mail` is the one `somcp_delete_email_log` takes.
- The recipients of the test shops are real addresses: the scenario never names one.

**Differences between 8.0.1 and 9.0.0** - only the column sizes of
`recipient` and `subject`; the list, its filters and its query are the same.

#### `somcp_delete_email_log`
**State**: written · [issue #424](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/424)
**Nature**: `acts(DELETE, 'AdminEmails')` · **Scope**: `mcp.system.write`, as `somcp_clear_cache`

**Does** - deletes one line of the list of sent e-mails of **Advanced
Parameters > E-mail**, as its **delete** button does: for example the line of
a customer who asked to be forgotten. One line per call: **Delete selected**
is the same call repeated; **Erase all** is `somcp_erase_email_logs`.

**Do not use for** - every line at once (→ `somcp_erase_email_logs`);
stopping the log (→ `somcp_update_email_settings`, `log_emails`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `id_mail` | integer | required; the **ID** of the list, from `somcp_list_email_logs`; not a whole number above 0: `SomcpTool::notAnIdentifier('id_mail')`; unknown: `SomcpTool::notFound('id_mail', 'logged e-mail', <id>, 'somcp_list_email_logs')` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - the line is read first through
`SomcpToolListEmailLogs::email()`, then `(new Mail($idMail))->delete()`, as
`EmailLogEraser::erase()` does: no SQL of its own. A `false` or an exception:
a tool error `The logged e-mail could not be deleted: <reason>. Read it with
somcp_list_email_logs before calling again.`, logged as
`email_log_delete_failed`. Logged as `email_log_deleted` with the `id_mail`
and the template only, never the recipient or the subject.

**Returns** - `deleted: true`, `id_mail`, and `email`: the line as it was, the
same object as one item of `emails` of `somcp_list_email_logs`.

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:` (`SomcpTool::refused('deleted', $problems)`): an unknown argument,
`id_mail` missing or not an identifier, an unknown `id_mail`.

**Notes**
- Changes the shop: the assistant asks first. Not replayable: a second call answers the unknown `id_mail`. Nothing in the shop depends on a line of the log.
- The shop test writes a line of its own through the `Mail` object, deletes it, then deletes it again to see the refusal: the recipients of the demo lines are real addresses.

**Differences between 8.0.1 and 9.0.0** - none: `Mail::delete()` (the
`ObjectModel` one) on both.

#### `somcp_erase_email_logs`
**State**: written · [issue #425](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/425)
**Nature**: `acts(DELETE, 'AdminEmails')` · **Scope**: `mcp.system.write`, as `somcp_clear_cache`

**Does** - empties the list of sent e-mails of **Advanced Parameters >
E-mail**, as its **Erase all** button does. The log is one table for the whole
installation: on a multistore installation, this removes the lines of every
shop, as the button does.

**Do not use for** - one line (→ `somcp_delete_email_log`); stopping the log
(→ `somcp_update_email_settings`, `log_emails`).

**Parameters** - none, as `somcp_clear_cache`: the tool is marked
`destructiveHint`, so the client asks the merchant before the call. Unknown
arguments through `SomcpTool::unknownFields()`.

**Then** - the lines are counted (`SELECT COUNT(*) FROM mail`), then
`Mail::eraseAllLogs()`, the call of `EmailLogEraser::eraseAll()`: no SQL write
of its own. A list already empty is not erased again. `false` or an exception:
a tool error `The list of sent e-mails could not be erased.`, logged as
`email_logs_erase_failed`. Logged as `email_logs_erased` with the count only.

**Returns** - `erased: true`, `deleted_count`: the number of lines there were
(`0` for a list already empty). `warning`, joined by a space when several
apply: multistore on with more than one shop (counted as
`somcp_list_email_logs` counts them) `The lines of every shop were erased:
PrestaShop keeps one log for all of them.`; **Log Emails** off `Log Emails is
off: the list stays empty until it is turned on with
somcp_update_email_settings (log_emails).`

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:` (`SomcpTool::refused('deleted', $problems)`): an unknown argument.

**Notes**
- Changes the shop and cannot be undone: the assistant asks first. Replayable: a second call answers `deleted_count: 0`.
- The JSON scenario only checks the refusal of an unknown argument: the other scenarios read the list. The shop test erases it after every scenario, erases it again to see `deleted_count: 0`, then reads `somcp_list_email_logs` to see `total_count: 0`.

**Differences between 8.0.1 and 9.0.0** - none: `Mail::eraseAllLogs()` is the
same `TRUNCATE` on both.

#### `somcp_send_test_email`
**State**: written · [issue #426](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/426)
**Nature**: `acts(EDIT, 'AdminEmails')` · **Scope**: `mcp.system.write`, as `somcp_update_email_settings`

**Does** - sends the test e-mail of **Advanced Parameters > E-mail**, card
**Test your email configuration**, with the settings the shop has saved: after
`somcp_update_email_settings`, it proves the shop can send before the first
order e-mail is due, and gives the server's own message when it cannot. The
page lets any right on it send the test; the tool asks **edit**, as
`somcp_update_email_settings`, since it sends an e-mail in the shop's name.

**Do not use for** - changing the settings (→ `somcp_update_email_settings`);
the list of what was sent (→ `somcp_list_email_logs`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `send_email_to` | string | **Send a test email to**; the shop's address (`PS_SHOP_EMAIL`) when left out, as the page fills it; checked with `Validate::isEmail()`: `send_email_to: an e-mail address` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - the settings are read through `SomcpToolGetEmailSettings::settings()`,
plus the two secrets it never answers (`PS_MAIL_PASSWD`, `PS_MAIL_DKIM_KEY`),
read with `Configuration::get()` for the shop of the token and passed to the
core only. **Never send emails** chosen: refused, nothing sent. Otherwise the
core's `EmailConfigurationTester::testConfiguration()`, built without the
Symfony container (`PrestaShop\PrestaShop\Adapter\Configuration` and the
context's translator), calls `Mail::sendMailTest()` with the text, subject,
escaping and failure sentence of the running version. The page sends the
values of its form, saved or not; this tool sends the saved ones, which are
what every e-mail of the shop uses. The test is never written to the list of
sent e-mails. Logged as `email_test_sent` or `email_test_failed` with the
sending method only, never the address or the transport's message.

**Returns** - sent: `sent: true`, `send_email_to`, `mail_method` (as the read
names it), and the `warning` `Check the inbox of <send_email_to>, spam folder
included: the shop handed the e-mail over, which does not prove it arrived.`
Not sent: a tool error with the core's sentence of the running version -
**Error: Please check your configuration** on 8.0, **An error has occurred.
Please check your configuration** from 8.1.0 on - then the transport's message when
there is one, cut to 500 characters, then `Read the settings with
somcp_get_email_settings.`

**Errors** - in the envelope `Nothing was sent. Correct these and call again:`
(`SomcpTool::refused('sent', $problems)`): an unknown argument, an address
that is not one, **Never send emails** chosen (`mail_method: Never send emails
is chosen, so the shop sends no e-mail - change it with
somcp_update_email_settings first`).

**Notes**
- Sends an e-mail: the assistant asks first. Replayable: each call sends one more e-mail.
- Neither secret ever appears in the answer, the log or an error: a transport message that quotes one is answered with `<hidden>` in its place.
- The JSON scenario checks the refusals only; the shop test sends one test to the shop's own address and accepts either answer, `"sent": true` or the failure sentence of the version, with neither secret in the text.

**Differences between 8.0.1 and 9.0.0** - the failure sentence and the
escaping of the text (above), both taken from the core of the running
version; the mail library underneath (SwiftMailer on 8.0.1, Symfony Mailer on
9.0.0) is the core's business.

#### `somcp_get_order_settings`
**State**: written · [issue #400](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/400)
**Nature**: `acts(VIEW, 'AdminOrderPreferences')` · **Scope**: `mcp.orders.read`, as `somcp_get_shipping_preferences`

**Does** - reads **Shop Parameters > Order Settings**, tab **Order
Settings**: the cards **General** and **Gift options**. Read only,
replayable. The tab **Statuses** has its own tools.

**Do not use for** - the order statuses (→ `somcp_list_order_statuses`);
shipping costs and free shipping (→ `somcp_get_shipping_preferences`);
changing these settings (→ `somcp_update_order_settings`).

**Parameters** - none. Unknown arguments through
`SomcpTool::unknownFields()`, in the envelope `Nothing was read. Correct these
and call again:`.

**Returns** - one object, built in the public
`SomcpToolGetOrderSettings::settings($idShop)` for the update to answer with,
each key read with `Configuration::get(<key>, null, $idShopGroup, $idShop)`
for the shop of the token and its group, as `GeneralConfiguration` and
`GiftOptionsConfiguration` read them; a key with no row reads as the core's
adapters default it (`false`, `0`):

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `final_summary` | **Enable final summary** | `PS_FINAL_SUMMARY_ENABLED` | boolean |
| `guest_checkout` | **Enable guest checkout** | `PS_GUEST_CHECKOUT_ENABLED` | boolean |
| `disable_reordering` | **Disable reordering option** | `PS_DISALLOW_HISTORY_REORDERING` | boolean |
| `minimum_purchase_tax_excl` | **Minimum purchase total required in order to validate the order** | `PS_PURCHASE_MINIMUM` | float, `0` when off; `currency` the ISO code of the default currency it is in |
| `recalculate_shipping` | **Recalculate shipping costs after editing the order** | `PS_ORDER_RECALCULATE_SHIPPING` | boolean |
| `delayed_shipping` | **Delayed shipping** | `PS_SHIP_WHEN_AVAILABLE` | boolean |
| `terms_of_service` | **Terms of service** | `PS_CONDITIONS` | boolean |
| `terms_id_cms` | **Page for terms and conditions** | `PS_CONDITIONS_CMS_ID` | the `id_cms` of `somcp_list_cms_pages`, `0` for **None**; `terms_page_title` its title in the shop's default language, found through `SomcpToolUpdateCmsSeo::page()`, `null` for **None** or a page that no longer exists in the shop |
| `backorder_status` | **Set backorder status** | `PS_ENABLE_BACKORDER_STATUS` | boolean on 9.0.0, `null` on 8.0.1 where the setting does not exist |
| `gift_wrapping` | **Offer gift wrapping** | `PS_GIFT_WRAPPING` | boolean |
| `gift_wrapping_price_tax_excl` | **Gift-wrapping price** | `PS_GIFT_WRAPPING_PRICE` | float |
| `gift_wrapping_id_tax_rules_group` | **Gift-wrapping tax** | `PS_GIFT_WRAPPING_TAX_RULES_GROUP` | the `id_tax_rules_group` of `somcp_list_tax_rules`, `0` for **None**; `gift_wrapping_tax_name` its name through `SomcpToolGetTaxRule::existingRule()`, or `null` |
| `recycled_packaging` | **Offer recycled packaging** | `PS_RECYCLABLE_PACK` | boolean |

`PS_ALLOW_MULTISHIPPING`, which `GeneralConfiguration` of 8.0.1 also reads,
is left out, as its form shows no such field.

Warnings: a page chosen that no longer exists, `The page for terms and
conditions no longer exists: choose another with
somcp_update_order_settings.`;
`terms_of_service` on with no page, `Terms of service are required but no page
is chosen: customers are asked to accept a page that does not exist.`

**Notes** - the demo shops, every row global: terms of service on with the
page 3, guest checkout on, shipping recalculated, no minimum, no gift
wrapping nor recycled packaging; no row for `PS_FINAL_SUMMARY_ENABLED`,
`PS_SHIP_WHEN_AVAILABLE` and `PS_GIFT_WRAPPING_TAX_RULES_GROUP`.

**Differences between 8.0.1 and 9.0.0** - **Set backorder status** on 9.0.0
only; the help of **Offer gift wrapping** differs.

#### `somcp_update_order_settings`
**State**: written · [issue #401](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/401)
**Nature**: `acts(EDIT, 'AdminOrderPreferences')` · **Scope**: `mcp.orders.write`, as `somcp_update_shipping_preferences`

**Does** - changes **Shop Parameters > Order Settings**, tab **Order
Settings**, as the **Save** buttons of its cards **General** and **Gift
options** do: `GeneralConfiguration::updateConfiguration()` and
`GiftOptionsConfiguration::updateConfiguration()` write each value for the
shop of the context, with no other step.

**Do not use for** - the order statuses (→ `somcp_update_order_status`);
shipping costs and free shipping (→ `somcp_update_shipping_preferences`).

**Parameters** - all optional; a key left out keeps its value. The read of
`somcp_get_order_settings` given back whole writes nothing: `currency`,
`terms_page_title`, `gift_wrapping_tax_name` and `warning` are computed keys,
ignored when given back as read and refused otherwise (`- <key>: worked out
by the shop, so it is not written here`).

| Name | On screen | Type | Rule |
|---|---|---|---|
| `final_summary` | **Enable final summary** | boolean | |
| `guest_checkout` | **Enable guest checkout** | boolean | |
| `disable_reordering` | **Disable reordering option** | boolean | |
| `minimum_purchase_tax_excl` | **Minimum purchase total required in order to validate the order** | number | 0 or more, `Validate::isPrice()`: `minimum_purchase_tax_excl: an amount of 0 or more, tax excluded, in <currency>`; 0 turns it off |
| `recalculate_shipping` | **Recalculate shipping costs after editing the order** | boolean | |
| `delayed_shipping` | **Delayed shipping** | boolean | |
| `terms_of_service` | **Terms of service** | boolean | |
| `terms_id_cms` | **Page for terms and conditions** | integer | `0` (**None**) or the `id_cms` of a page of the shop, through `SomcpTool::id()` and `SomcpToolUpdateCmsSeo::page()`; otherwise `notFound('terms_id_cms', 'CMS page', <id>, 'somcp_list_cms_pages')` |
| `backorder_status` | **Set backorder status** | boolean | 9.0.0 only; on 8.0.1, `true` or `false` answers `backorder_status: not on PrestaShop <version>, which has no Set backorder status; leave the field out` (`null`, as read, is ignored) |
| `gift_wrapping` | **Offer gift wrapping** | boolean | |
| `gift_wrapping_price_tax_excl` | **Gift-wrapping price** | number | 0 or more, `Validate::isPrice()` |
| `gift_wrapping_id_tax_rules_group` | **Gift-wrapping tax** | integer | `0` (**None**) or an `id_tax_rules_group` of the shop, through `SomcpToolGetTaxRule::existingRule()`; otherwise `notFound('gift_wrapping_id_tax_rules_group', 'tax rule', <id>, 'somcp_list_tax_rules')` |
| `recycled_packaging` | **Offer recycled packaging** | boolean | |

Booleans are booleans only (`"false"` is refused: `<field>: true or false`).
Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope of #169 under `Nothing was changed.`, before anything
is written.

**Then** - `SomcpToolGetOrderSettings::settings($idShop)` is read first; the
keys whose value differs are saved in the order of the two cards with
`Configuration::updateValue(<key>, <value>, false, $idShopGroup, $idShop)` for
the shop of the token: booleans as `0` or `1`, amounts as floats, the page and
the tax as integers. A failed save stops the call and names what was saved
before it, as `somcp_update_tax_options` does. Nothing differs:
`changed: false`, `reason` `The order settings already hold every value
given. Nothing was written.`, `settings` as read. Logged as
`order_settings_updated` with the names of the fields saved.

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per field its `field`, `old`, `new`, under the names of the
parameters), and `settings` read back through `settings()`. Warnings, beside
the answer:
- `gift_wrapping` changed on 9.0.0: the help of the screen, `Remember to regenerate email templates in Design > Email theme after enabling or disabling this feature.`;
- `terms_of_service` on with `terms_id_cms` 0 after the call: the warning of `somcp_get_order_settings`;
- `guest_checkout` set to `false`: `Visitors must now create an account before they order.`;
- more than one shop: `The settings changed for this shop only, as the back office saves them in a shop context.`, the sentence of `somcp_update_tax_options`.

**Differences between 8.0.1 and 9.0.0** - `backorder_status` on 9.0.0 only.

#### `somcp_get_product_settings`
**State**: written · [issue #408](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/408)
**Nature**: `acts(VIEW, 'AdminPPreferences')` · **Scope**: `mcp.catalog.read`, as `somcp_get_product`

**Does** - reads **Shop Parameters > Product Settings**: the cards **General
product settings**, **Product page**, **Products stock** and **Pagination**.
Read only, replayable.

**Do not use for** - the stock of one product (→ `somcp_get_stock`); the
out-of-stock behavior or labels of one product (→ `somcp_get_product`); the
customer groups that hide prices (Customer Settings > Groups, not covered);
changing these settings (→ `somcp_update_product_settings`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `languages` | array | the ISO codes of the five texts, the rule of #149 through `SomcpToolCreateProduct::languages()`, as `somcp_get_maintenance_settings` takes it; left out, every active language |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - one object, built in the public
`SomcpToolGetProductSettings::settings($idShop, $idLangs)` for the update to
answer with, each key read with `Configuration::get(<key>, $idLang,
$idShopGroup, $idShop)` for the shop of the token and its group, as
`GeneralConfiguration`, `PageConfiguration`, `StockConfiguration` and
`PaginationConfiguration` read them; a key with no row reads as the core's
adapters read it (`false`, `0`, empty):

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `catalog_mode` | **Catalog mode** | `PS_CATALOG_MODE` | boolean |
| `catalog_mode_show_prices` | **Show prices** | `PS_CATALOG_MODE_WITH_PRICES` | boolean; saved as false by the core whenever catalog mode is off |
| `new_product_days` | **Number of days for which the product is considered 'new'** | `PS_NB_DAYS_NEW_PRODUCT` | integer |
| `short_description_limit` | **Max size of short description** | `PS_PRODUCT_SHORT_DESC_LIMIT` | integer, in characters |
| `quantity_discounts_based_on` | **Quantity discounts based on** | `PS_QTY_DISCOUNT_ON_COMBINATION` | `products` (0) or `combinations` (1) |
| `force_friendly_url` | **Force update of friendly URL** | `PS_FORCE_FRIENDLY_PRODUCT` | boolean |
| `breadcrumb_category` | **Category used in breadcrumbs** | `PS_PRODUCT_BREADCRUMB_CATEGORY` | `default` or `current`; `null` before 9.0.0 |
| `activate_new_products` | **Activate new products by default** | `PS_PRODUCT_ACTIVATION_DEFAULT` | boolean |
| `specific_price_priorities` | **Default order of priority for specific prices** | `PS_SPECIFIC_PRICE_PRIORITIES` | a list of four among `store`, `currency`, `country`, `group`, in the stored order; nothing stored: the core's default, `group`, `currency`, `country`, `store` |
| `disabled_product_behavior` | **Default behavior for disabled products** | `PS_PRODUCT_REDIRECTION_DEFAULT` | the stored code, `404`, `410`, `200-displayed`, `404-displayed` or `410-displayed`; `null` before 8.1.0 |
| `display_available_quantities` | **Display available quantities on the product page** | `PS_DISPLAY_QTIES` | boolean |
| `add_to_cart_with_combinations` | **Display the "Add to cart" button when a product has combinations** | `PS_ATTRIBUTE_CATEGORY_DISPLAY` | boolean |
| `combination_image_in_listings` | **Use combination image in listings** | `PS_USE_COMBINATION_IMAGE_IN_LISTING` | boolean; `null` before 9.0.0 |
| `attribute_anchor_separator` | **Separator of attribute anchor on the product links** | `PS_ATTRIBUTE_ANCHOR_SEPARATOR` | `-` or `,` |
| `display_discounted_unit_price` | **Display the discounted unit price** | `PS_DISPLAY_DISCOUNT_PRICE` | boolean |
| `notify_product_in_cart` | **Display notifications if the product is already in the cart** | `PS_DISPLAY_AMOUNT_IN_CART` | boolean; `null` before 9.0.0 |
| `feature_values_order` | **Order of feature values** | `PS_FEATURE_VALUES_ORDER` | `alphabetical` (`name`) or `position`; `null` before 9.0.0 |
| `stock_management` | **Enable stock management** | `PS_STOCK_MANAGEMENT` | boolean |
| `pack_stock_type` | **Default pack stock behavior** | `PS_PACK_STOCK_TYPE` | `pack`, `products` or `both`, the words of `somcp_get_product` (`SomcpToolGetProduct::PACK_STOCK_TYPES`) |
| `display_unavailable_attributes` | **Display unavailable attributes on the product page** | `PS_DISP_UNAVAILABLE_ATTR` | boolean |
| `remaining_quantities_below` | **Display remaining quantities when the quantity is lower than** | `PS_LAST_QTIES` | integer, `0` when off |
| `allow_out_of_stock_ordering` | **Allow ordering of out-of-stock products** | `PS_ORDER_OUT_OF_STOCK` | boolean |
| `label_in_stock` | **Label of in-stock products** | `PS_LABEL_IN_STOCK_PRODUCTS` | text per ISO code |
| `label_backorders_allowed` | **Label of out-of-stock products with allowed backorders** | `PS_LABEL_OOS_PRODUCTS_BOA` | text per ISO code |
| `label_backorders_denied` | **Label of out-of-stock products with denied backorders** | `PS_LABEL_OOS_PRODUCTS_BOD` | text per ISO code |
| `delivery_time_in_stock` | **Delivery time of in-stock products** | `PS_LABEL_DELIVERY_TIME_AVAILABLE` | text per ISO code, empty when off |
| `delivery_time_backorders` | **Delivery time of out-of-stock products with allowed backorders** | `PS_LABEL_DELIVERY_TIME_OOSBOA` | text per ISO code, empty when off |
| `out_of_stock_label_in_listings` | **Display out-of-stock label on product listing pages** | `PS_SHOW_LABEL_OOS_LISTING_PAGES` | boolean |
| `products_per_page` | **Products per page** | `PS_PRODUCTS_PER_PAGE` | integer |
| `default_order_by` | **Default order by** | `PS_PRODUCTS_ORDER_BY` | `name`, `price`, `date_add`, `date_upd`, `position`, `brand`, `quantity`, `reference`, or `sales` on 9.0.0 |
| `default_order_way` | **Default order method** | `PS_PRODUCTS_ORDER_WAY` | `asc` or `desc` |

The five texts are objects keyed by ISO code, each language read from
`configuration_lang` as the page shows it, the shape of `maintenance_text`;
`languages`: the ISO codes read.

Warnings: `catalog_mode` on, `Catalog mode is on: visitors can browse the
products but cannot add them to the cart.`; `stock_management` off, `Stock
management is off: quantities are not counted and every product can be
ordered.`

**Errors** - in the envelope `Nothing was read. Correct these and call
again:` (`SomcpTool::refused('read', $problems)`): an unknown argument, a
language of `languages` the shop does not have (the rule of #149).

**Notes** - the demo shops, every row global: catalog mode off, 20 days new,
5000 characters, priorities `group`, `currency`, `country`, `store`, stock
management on, ordering out of stock denied, 3 remaining quantities, 12
products per page by position ascending; no row for
`PS_QTY_DISCOUNT_ON_COMBINATION`, `PS_FORCE_FRIENDLY_PRODUCT`,
`PS_PRODUCT_ACTIVATION_DEFAULT`, `PS_DISPLAY_DISCOUNT_PRICE` and the two
delivery times. **Default behavior for disabled products** came with 8.1.0,
read in its `GeneralConfiguration`: `null` on 8.0, a code from 8.1.

**Differences between 8.0.1 and 9.0.0** - **Category used in breadcrumbs**,
**Default behavior for disabled products** (from 8.1.0), **Use combination
image in listings**, **Display notifications if the product is already in the
cart**, **Order of feature values** and the **Product sales** order on 9.0.0
only; the wording of **Display the "Add to cart" button...** (8.0.1 **... has
attributes**), of the pack stock field (8.0.1 **Default pack stock
management**) and of the help of **Force update of friendly URL** differs.

#### `somcp_update_product_settings`
**State**: written · [issue #409](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/409), [#410](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/410)
**Nature**: `acts(EDIT, 'AdminPPreferences')` · **Scope**: `mcp.catalog.write`, as `somcp_update_product`

**Does** - changes **Shop Parameters > Product Settings**, as the **Save**
buttons of its cards **General product settings**, **Product page**,
**Products stock** and **Pagination** do. Only the fields given change.

**Do not use for** - the stock of a product (→ `somcp_set_stock_quantity`);
the priorities, status, stock labels or out-of-stock behavior of one product
(→ `somcp_update_product`).

**Parameters** - all optional, under the names and words of
`somcp_get_product_settings`, whose read given back whole writes nothing. The
fields, their keys and kinds are one constant map,
`SomcpToolUpdateProductSettings::FIELDS`; the five texts are
`SomcpToolGetProductSettings::LABELS`.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `catalog_mode` | **Catalog mode** | boolean | |
| `catalog_mode_show_prices` | **Show prices** | boolean | `true` with `catalog_mode` off after the call: `catalog_mode_show_prices: only with catalog_mode on - the shop saves it off otherwise`; `catalog_mode` turned off saves it `false` too, as the core does, both lines in `changes` |
| `new_product_days` | **Number of days for which the product is considered 'new'** | integer | 0 or more: `new_product_days: a number of days, 0 or more` |
| `short_description_limit` | **Max size of short description** | integer | 1 or more: `short_description_limit: a number of characters, 1 or more` |
| `quantity_discounts_based_on` | **Quantity discounts based on** | string | `products` or `combinations` |
| `force_friendly_url` | **Force update of friendly URL** | boolean | |
| `breadcrumb_category` | **Category used in breadcrumbs** | string | `default` or `current`; from 9.0.0 |
| `activate_new_products` | **Activate new products by default** | boolean | |
| `specific_price_priorities` | **Default order of priority for specific prices** | array | `store`, `currency`, `country`, `group`, each once, in the order wanted: a word twice, `specific_price_priorities: The selected condition must be different in each field to set an order of priority.` (the core's sentence); otherwise `specific_price_priorities: four of store, currency, country, group`; saved as `id_shop`, `id_currency`, `id_country`, `id_group` joined by `;` |
| `disabled_product_behavior` | **Default behavior for disabled products** | string | `404`, `410`, `200-displayed`, `404-displayed`, `410-displayed`; from 8.1.0 |
| `display_available_quantities` | **Display available quantities on the product page** | boolean | |
| `add_to_cart_with_combinations` | **Display the "Add to cart" button when a product has combinations** | boolean | |
| `combination_image_in_listings` | **Use combination image in listings** | boolean | from 9.0.0 |
| `attribute_anchor_separator` | **Separator of attribute anchor on the product links** | string | `-` or `,` |
| `display_discounted_unit_price` | **Display the discounted unit price** | boolean | |
| `notify_product_in_cart` | **Display notifications if the product is already in the cart** | boolean | from 9.0.0 |
| `feature_values_order` | **Order of feature values** | string | `alphabetical` or `position` (stored `name`, `position`); from 9.0.0 |
| `stock_management` | **Enable stock management** | boolean | |
| `pack_stock_type` | **Default pack stock behavior** (9.0.0), **Default pack stock management** (8.0.1) | string | `pack`, `products` or `both`, stored 0, 1, 2; `default` (a product's own word) is refused by `SomcpTool::oneOf()` |
| `display_unavailable_attributes` | **Display unavailable attributes on the product page** | boolean | |
| `remaining_quantities_below` | **Display remaining quantities when the quantity is lower than** | integer | 0 or more, 0 turns it off: `remaining_quantities_below: a quantity, 0 or more` |
| `allow_out_of_stock_ordering` | **Allow ordering of out-of-stock products** | boolean | |
| `label_in_stock` | **Label of in-stock products** | object keyed by ISO code | the rule of the product field `available_now` |
| `label_backorders_allowed` | **Label of out-of-stock products with allowed backorders** | object keyed by ISO code | the rule of `available_later` |
| `label_backorders_denied` | **Label of out-of-stock products with denied backorders** | object keyed by ISO code | the rule of `available_later` |
| `delivery_time_in_stock` | **Delivery time of in-stock products** | object keyed by ISO code | the rule of `delivery_in_stock`; empty turns it off |
| `delivery_time_backorders` | **Delivery time of out-of-stock products with allowed backorders** | object keyed by ISO code | the rule of `delivery_out_stock`; empty turns it off |
| `out_of_stock_label_in_listings` | **Display out-of-stock label on product listing pages** | boolean | |
| `languages` | - | array | the languages of the texts given, the rule of #149 (`SomcpToolCreateProduct::languages()`); every active language when left out; a language left out keeps its texts |
| `products_per_page` | **Products per page** | integer | 1 or more: `products_per_page: a number of products, 1 or more` |
| `default_order_by` | **Default order by** | string | `name`, `price`, `date_add`, `date_upd`, `position`, `brand`, `quantity`, `reference`, and `sales` from 9.0.0 (before it, the list without `sales`) |
| `default_order_way` | **Default order method** | string | `asc` or `desc` |

A field of a later version given a value: `SomcpTool::notOnThisVersion(<key>,
<the words of the screen>)`; `null`, as read, is ignored. A choice outside its
list: `SomcpTool::oneOf()`. Booleans are booleans only (`"false"` is refused).
The five texts are read with `SomcpToolCreateProduct::texts()`, each language
`trim()`med and checked with `(new Product())->validateField()` against the
product field it stands in for, so the size and rule come from
`Product::$definition`; a refusal is under `<key> (<iso>)`. An empty text is
allowed (nothing is displayed). A text given back as read is no change, and
so is any other field given exactly as the read has it, skipped before its
check: a stored value the rules refuse (a key with no row reads as `""`) never
blocks the read given back whole. `warning` is ignored. Unknown arguments through `SomcpTool::unknownFields()`; every problem
in one answer, in the envelope of #169 under `Nothing was changed.`, before
anything is written.

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per field its `field`, `old`, `new`, under the names and words of
the parameters; a text one line per language, `{"field": "label_in_stock
(fr)", "old": "", "new": "En stock"}`), and `settings` read back through
`SomcpToolGetProductSettings::settings()`. Nothing differs: `changed: false`,
`reason` `The product settings already hold every value given. Nothing was
written.`, `settings` as read.

Warnings: `catalog_mode` just turned on, `The shop is now in catalog mode:
visitors can browse the products but cannot add them to the cart.`;
`stock_management` just turned off, `Stock management is now off: quantities
are no longer counted and every product can be ordered.`;
`allow_out_of_stock_ordering` just turned on with stock management on,
`Products out of stock can now be ordered, unless a product says otherwise.`;
then the warnings of `somcp_get_product_settings` read back (its catalog mode
and stock management sentences, each only when this call did not just turn it
so); on an installation of several shops, `The settings changed for this shop
only, as the back office saves them in a shop context.`

**Errors** - those above; a save that fails stops the call and names what was
saved before it (`The product settings could not be changed: <field> could not
be saved, after <fields> had been saved. Read them with
somcp_get_product_settings before calling again.`).

**Notes**
- Each key that differs saved with `Configuration::updateValue(<key>, <value>,
  false, $idShopGroup, $idShop)` for the shop of the token, as
  `GeneralConfiguration`, `PageConfiguration`, `StockConfiguration` and
  `PaginationConfiguration` save them in a shop context: booleans as `0` or
  `1`, numbers as integers, choices as their stored codes. The texts are saved
  as `StockConfiguration` saves them, `Configuration::updateValue(<key>,
  <texts by id_lang>, false, ...)`, with only the languages given, so the
  others, inactive ones included, keep theirs. The priorities are written as
  `SpecificPricePriorityUpdater::updateDefaultPriorities()` writes them.
- Log line `product_settings_updated` with the names of the fields saved,
  never the texts.
- Show the merchant the change before the call; catalog mode stops every order.

**Differences between 8.0.1 and 9.0.0** - `breadcrumb_category`,
`combination_image_in_listings`, `notify_product_in_cart`,
`feature_values_order` and the `sales` order on 9.0.0 only;
`disabled_product_behavior` from 8.1.0.

#### `somcp_get_customer_settings`
**State**: written · [issue #411](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/411)
**Nature**: `acts(VIEW, 'AdminCustomerPreferences')` · **Scope**: `mcp.customers.read`, as `somcp_search_customers`

**Does** - reads **Shop Parameters > Customer Settings**, tab **Customer
Settings**, card **General**. Read only, replayable. The tabs **Groups** and
**Titles** are other pages (`AdminGroups`, `AdminGenders`).
`somcp_create_customer` and `somcp_search_customers` read B2B mode for their
own fields; this tool is where the merchant's choice is read.

**Do not use for** - the customer groups (→ `somcp_list_customer_groups`);
one customer (→ `somcp_get_customer`); changing these settings (→
`somcp_update_customer_settings`).

**Parameters** - none. Unknown arguments through
`SomcpTool::unknownFields()`, in the envelope `Nothing was read. Correct these
and call again:`.

**Returns** - one object, built in the public
`SomcpToolGetCustomerSettings::settings($idShop)` for the update to answer
with, each key read with `Configuration::get(<key>, null, $idShopGroup,
$idShop)` for the shop of the token and its group, as `CustomerConfiguration`
reads them; a key with no row reads as the core's adapter reads it (`false`,
`0`):

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `redisplay_cart_at_login` | **Re-display cart at login** | `PS_CART_FOLLOWING` | boolean |
| `send_email_after_registration` | **Send an email after registration** | `PS_CUSTOMER_CREATION_EMAIL` | boolean |
| `password_reset_delay_minutes` | **Password reset delay** (**minutes**) | `PS_PASSWD_TIME_FRONT` | integer, 0 or more |
| `b2b_mode` | **Enable B2B mode** | `PS_B2B_ENABLE` | boolean |
| `ask_for_birth_date` | **Ask for birth date** | `PS_CUSTOMER_BIRTHDATE` | boolean |
| `partner_offers` | **Enable partner offers** | `PS_CUSTOMER_OPTIN` | boolean |

No warning.

**Errors** - an unknown argument, above.

**Differences between 8.0.1 and 9.0.0** - 9.0.0 shows above the card **Make
sure partner offers are not set as required in the Customers section of the
back office before disabling them. Otherwise, new customers won't be able to
create an account and proceed to checkout.**, 8.0.1 does not; 9.0.0 saves
through the multistore form (a checkbox per field in a shop context), 8.0.1
for the context.

#### `somcp_update_customer_settings`
**State**: written · [issue #412](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/412)
**Nature**: `acts(EDIT, 'AdminCustomerPreferences')` · **Scope**: `mcp.customers.write`, as `somcp_update_customer`

**Does** - changes **Shop Parameters > Customer Settings**, card **General**,
as its **Save** button does. Only the fields given change.

**Do not use for** - the customer groups and their prices (Customer Settings >
Groups, not covered); one customer (→ `somcp_update_customer`).

**Parameters** - all optional, under the names of
`somcp_get_customer_settings`, whose read given back whole writes nothing.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `redisplay_cart_at_login` | **Re-display cart at login** | boolean | |
| `send_email_after_registration` | **Send an email after registration** | boolean | |
| `password_reset_delay_minutes` | **Password reset delay** | integer | 0 or more: `password_reset_delay_minutes: a number of minutes, 0 or more` |
| `b2b_mode` | **Enable B2B mode** | boolean | |
| `ask_for_birth_date` | **Ask for birth date** | boolean | |
| `partner_offers` | **Enable partner offers** | boolean | |

Booleans are booleans only (`"false"` is refused: `<key>: true or false`).
Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope of #169 under `Nothing was changed.`, before anything
is written.

**Returns** - the shape of every update tool (#356): `changed: true`,
`changes` (per field its `field`, `old`, `new`, under the names of the
parameters), and `settings` read back through
`SomcpToolGetCustomerSettings::settings()`. Nothing differs: `changed: false`,
`reason` `The customer settings already hold every value given. Nothing was
written.`, `settings` as read.

Warnings: `partner_offers` turned off on 9.0.0, the note of the screen, `Make
sure partner offers are not set as required in the Customers section of the
back office before disabling them. Otherwise, new customers won't be able to
create an account and proceed to checkout.`; `send_email_after_registration`
turned off, `New customers no longer get an e-mail with their account
details.`; on an installation of several shops, `The settings changed for
this shop only, as the back office saves them in a shop context.`

**Errors** - those above; a save that fails stops the call and names what was
saved before it (`The customer settings could not be changed: <field> could
not be saved, after <fields> had been saved. Read them with
somcp_get_customer_settings before calling again.`).

**Notes**
- Each key that differs saved with `Configuration::updateValue(<key>, <0 or 1,
  or the integer>, false, $idShopGroup, $idShop)` for the shop of the token, as
  `CustomerConfiguration::updateConfiguration()` saves them.
- `b2b_mode` changed: the tab `AdminOutstanding` (the page of B2B outstanding
  amounts) is set to the same state, as
  `CustomerPreferencesFormHandler::handleB2bUpdate()` does, through the legacy
  `Tab` object so it behaves the same on 8.0.1 and 9.0.0; a missing tab is
  skipped, a failed update is named like a failed save.
- Log line `customer_settings_updated` with the names of the fields saved.

**Differences between 8.0.1 and 9.0.0** - the partner offers note on 9.0.0
only; the rest the same.

#### `somcp_list_titles`
**State**: written · [issue #430](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/430)
**Nature**: `acts(VIEW, 'AdminGenders')` · **Scope**: `mcp.customers.read`, as `somcp_search_customers`

**Does** - lists the social titles of **Shop Parameters > Customer Settings**,
tab **Titles**: their name in each language, the gender they stand for and
their image. Read only, replayable. The `id_gender` is the one
`somcp_create_customer` and `somcp_update_customer` take.

**Do not use for** - a customer's own title (→ `somcp_get_customer`); the
customer settings (→ `somcp_get_customer_settings`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `language` | string | the ISO code of an active language for `name`, as `somcp_list_contacts` takes it (`SomcpTool::readLanguage()`); the shop default when left out; a code the shop does not have is refused with `SomcpTool::languageProblem()` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns**
- `titles`: one object per title, by `id_gender`: `id_gender` (**ID**),
  `name` (**Social title**, in `language`), `names` (every active language,
  keyed by ISO code), `gender` (**Gender**: `male`, `female` or `other`, from
  `type` 0, 1, 2; the screen calls the third **Neutral** on 8.0.1 and
  **Other** on 9.0.0, the tool says `other` on both), `image` (**Image**:
  `SomcpDownload::offer('gender_image', <id_gender>, $idShop)`, `null`
  without a file);
- `count`; `language`.

The names are read through `SomcpToolCreateCustomer::titles()`, the one query
behind `SomcpToolCreateCustomer::genders()`, so the customer tools and the
list never name a title differently. Each item is built by
`SomcpToolListTitles::item()`, which `somcp_create_title` answers with too.

**The image** - the kind `gender_image` of `SomcpDownload::KINDS`, reader
`somcp_list_titles`, kept at `_PS_GENDERS_DIR_ . <id_gender> . '.jpg'` with no
shop table, as `order_state_icon`: titles are shared by every shop.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`
(`SomcpTool::refused('read', $problems)`): an unknown argument, a language the
shop does not have.

**Notes**
- The object `Gender`: `type` (0 male, 1 female, 2 the third choice), per language `name` (20 characters at most); no shop table.
- The demo shops: 1 **Mr.**, type 0; 2 **Mrs.**, type 1; no image file.

**Differences between 8.0.1 and 9.0.0** - the page (legacy on 8.0.1, Symfony
on 9.0.0) and the word of the third gender (**Neutral** / **Other**); the
table and the object are the same.

#### `somcp_create_title`
**State**: written · [issue #439](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/439)
**Nature**: `acts(ADD, 'AdminGenders')` · **Scope**: `mcp.customers.write`, as `somcp_create_customer`

**Does** - creates a social title of **Shop Parameters > Customer Settings**,
tab **Titles**, as **Add new title** then **Save** do: its name in each
language, the gender it stands for and its image. One title per call. Read
`somcp_list_titles` first: two titles may share a name.

**Do not use for** - changing a title (→ `somcp_update_title`); the title of a
customer (→ `somcp_update_customer`).

**Parameters**

| Name | Screen | Type | Rule |
|---|---|---|---|
| `name` | **Social title** (8.0.1) / **Title** (9.0.0) | object | required, one text per language keyed by ISO code, through `SomcpToolCreateProduct::languages()` and `texts()` as `somcp_create_contact` takes its `name` (#149); trimmed; never empty; 20 characters at most (`name (<iso>): 20 characters at most`); the `isCatalogName` rule of 9.0.0 on both versions (`name (<iso>): no <, >, ;, =, #, { or }`) |
| `languages` | | array | as `somcp_create_contact` |
| `gender` | **Gender** | string | required: `male`, `female` or `other`, the words `somcp_list_titles` answers (`SomcpToolListTitles::GENDERS`); anything else through `SomcpTool::oneOf('gender', ...)` |
| `image` | **Image** | object | optional: `SomcpUpload::schema()`, read with `SomcpUpload::argument()` and fetched by `SomcpToolUpdateLanguage::fetch()` (`SomcpUpload::fetchImage()`), as the `flag` of `somcp_create_language`; `files: ['image' => SomcpUpload::images()]` |
| `image_width` | **Image width** | integer | optional, 0 at least, 16 when left out as the form shows; only with `image` (`image_width: only with image`) |
| `image_height` | **Image height** | integer | optional, 0 at least, 16 when left out; only with `image` |

The picture is resized only when width and height are both above 0, otherwise
kept at its own size, as 8.0.1 decides and as the help of both screens says.
Unknown arguments through `SomcpTool::unknownFields()`; `id_gender` is refused
as unknown: an item of `somcp_list_titles` given back is not a creation. The
checks live in `SomcpToolCreateTitle::planTitle($arguments, $idShop, $errors,
$required)`, which `somcp_update_title` calls with `$required = false`.

**Then** - the image is fetched before anything is written; then `new
Gender()`, its names (a language not filled takes the default one's, as the
form fills it) and `type` 0, 1 or 2, `add()`. Then the image, through
`SomcpToolCreateTitle::storeImage($idGender, $file, $width, $height)`:
`Gender::deleteImage()`, `ImageManager::resize()` to
`_PS_GENDERS_DIR_ . <id_gender> . '.jpg'`, forced to JPEG (the storefront and
`SomcpDownload::stored()` ask for `.jpg`), then the cached thumbnails of both
versions removed (`img/tmp/gender_mini_<id>_<id_shop>.jpg` for every shop,
`img/tmp/genders_mini_<id>.jpg`). A refused image deletes the title just
created, so a failed call leaves nothing: `The social title could not be
created: the image <reason>.` Log line `title_created` with the `id_gender`
only.

**Returns** - `created: true` and the title as `somcp_list_titles` answers one
item, built by the same `SomcpToolListTitles::item()`: `id_gender`, `name`
(default language), `names` (every active language), `gender`, `image`
(`SomcpDownload::offer('gender_image', ...)`, `null` without a file).
`warning` when the shop runs several shops: `A social title is shared by every
shop: it is offered on all of them.`

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`: an unknown argument, `name` missing, empty, too long or with a refused
character, `gender` missing or unknown, an `image` that is not one picture,
`image_width` or `image_height` not a whole number of 0 at least or given
without `image`, an unknown language (#149).

**Notes** - changes the shop: the assistant asks first. Not replayable: a
second call creates a second title. A title has no shop table: it is shared by
every shop.

**Differences between 8.0.1 and 9.0.0** - the label of the name (**Social
title** / **Title**); the validation of the name (`isString` on 8.0.1,
`isCatalogName` on 9.0.0: the tool applies the stricter one on both, so a
title created on 8.0.1 stays valid after an upgrade); the name of the cached
thumbnail (both removed). The rest is the same.

#### `somcp_update_title`
**State**: written · [issue #440](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/440)
**Nature**: `acts(EDIT, 'AdminGenders')` · **Scope**: `mcp.customers.write`, as `somcp_update_customer`

**Does** - changes a social title of **Shop Parameters > Customer Settings**,
tab **Titles**, as its **Edit** action then **Save** do: its name in each
language, its gender, its image. Only the fields given change. One title per
call.

**Do not use for** - creating a title (→ `somcp_create_title`); the title of
one customer (→ `somcp_update_customer`).

**Parameters**

| Name | Screen | Type | Rule |
|---|---|---|---|
| `id_gender` | | integer | required, from `somcp_list_titles`; `SomcpTool::notAnIdentifier('id_gender')`; unknown: `SomcpTool::notFound('id_gender', 'social title', <id>, 'somcp_list_titles')` |
| `name`, `languages`, `gender`, `image`, `image_width`, `image_height` | | | optional: the rules of `somcp_create_title`, through `SomcpToolCreateTitle::planTitle($arguments, $idShop, $errors, false)`; `name` per language keyed by ISO code |
| `names` | | object | read by `somcp_list_titles`: taken as `name` |

- An item of `somcp_list_titles` given back whole changes nothing: a plain
  `name` equal to the stored text of the default language, or of any language
  (`SomcpToolCreateProduct::givenBackAsRead()`), is no change; `names` is
  taken as `name`, for the languages being filled, only when one differs;
  `image` given back as the `download_ref` the list answered is "unchanged"
  through `SomcpUpload::same($given, 'gender_image', (string) $idGender)`.
- `image: null`: refused, `image: a social title's image cannot be removed,
  only replaced`, as neither screen removes one.

Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - every check first, every mistake in one answer. Nothing to change:
no write. Otherwise the new image is fetched before anything is written, then
`Gender` loaded, the names and gender given set, `update()`; then
`SomcpToolCreateTitle::storeImage()` when a new picture is given. A failure of
either: `The social title could not be changed: <reason>. Read it with
somcp_list_titles before calling again.`, with `the name and gender were
saved, the image was not: <reason>` when the image fails after a saved name.
Log line `title_updated` with the `id_gender` and the names of the fields
changed.

**Returns** - `changed` (`true` or `false`), `changes` (each field written,
with its `old` and `new` value, as every update tool answers), `reason` when
nothing changed, and `title`: the item of `SomcpToolListTitles::item()`, as
the list and the creation answer it. `warning` when the shop runs several
shops: `A social title is shared by every shop: it is offered on all of
them.`, and when the gender changes on a title customers hold: `<n> customers
of this shop have this title.`

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`: the errors of `somcp_create_title` (except `name` and `gender`
required), plus `id_gender` missing, not an identifier or unknown, and
`image: null`.

**Notes** - changes the shop: the assistant asks first. Replayable: a second
identical call changes nothing.

**Differences between 8.0.1 and 9.0.0** - those of `somcp_create_title`; none
of its own.

#### `somcp_delete_title`
**State**: written · [issue #441](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/441)
**Nature**: `acts(DELETE, 'AdminGenders')` · **Scope**: `mcp.customers.write`, as `somcp_delete_contact`

**Does** - deletes a social title of **Shop Parameters > Customer Settings**,
tab **Titles**, as its **Delete** action does. One title per call: **Delete
selected** is the same call repeated.

**Do not use for** - changing a title (→ `somcp_update_title`); removing the
title of one customer (→ `somcp_update_customer` with `id_gender: 0`).

**Parameters**

| Name | Type | Required | Rule |
|---|---|---|---|
| `id_gender` | integer | yes | from `somcp_list_titles`; `SomcpTool::notAnIdentifier('id_gender')`; unknown: `SomcpTool::notFound('id_gender', 'social title', <id>, 'somcp_list_titles')` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - the title is read with `SomcpToolListTitles::item()` (through
`SomcpToolCreateTitle::answer()`), the customers of the shop of the token who
hold it are counted, then `Gender::delete()`, as both screens do
(`AdminController::processDelete()` on 8.0.1, `DeleteTitleHandler` on 9.0.0):
neither checks anything, so a title held by customers is deleted. A `false` or
an exception: a tool error `The social title could not be deleted: <reason>.
Read it with somcp_list_titles before calling again.`, logged as
`title_delete_failed`. Then `deleteImage()` and the cached copies
(`SomcpToolCreateTitle::dropCachedImages()`, the ones `storeImage()` removes):
stricter than both screens on purpose, which leave `img/genders/<id>.jpg` on
disk where nothing would ever remove it. Logged as `title_deleted` with the
`id_gender` only.

**Returns** - `deleted: true`, `id_gender`, and `title`: the title as it was
(`id_gender`, `name`, `names` in every language, `gender`), the item of
`somcp_list_titles` without its `image`, so the assistant can create it again
with `somcp_create_title`. `warning` when customers of this shop held it:
`<n> customers of this shop had this title: their accounts now show none.`; in
multistore, also `A social title is shared by every shop: it is gone from all
of them.`

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`: an unknown argument, `id_gender` missing, not an identifier or
unknown, given in one answer.

**Notes** - changes the shop and cannot be undone: the assistant asks first,
and says how many customers hold the title. Not replayable: a second call
answers the unknown `id_gender`. The JSON scenario checks the refusals only, so
the demo titles 1 and 2 are never deleted; the shop test deletes the title it
created with `somcp_create_title`, then checks it is gone from the list and its
file from `img/genders/`.

**Differences between 8.0.1 and 9.0.0** - none: both call
`ObjectModel::delete()`; only the name of the cached thumbnail differs (both
removed).

#### `somcp_list_contacts`
**State**: written · [issue #413](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/413)
**Nature**: `acts(VIEW, 'AdminContacts')` · **Scope**: `mcp.customers.read`, as `somcp_search_customers`

**Does** - lists the contacts of **Shop Parameters > Contact**, tab
**Contacts**: the addresses the contact form of the storefront offers, and
those **Send emails to** of `somcp_get_email_settings` chooses from
(`send_emails_to_choices`). Read only, replayable. The tab **Stores** is
another page (`AdminStores`).

**Do not use for** - the messages customers sent (Customer Service, not
covered); the e-mail settings (→ `somcp_get_email_settings`).

**Parameters**

| Name | Type | Rule |
|---|---|---|
| `language` | string | the ISO code of an active language for the translated texts, as `somcp_list_cms_pages` takes it (`SomcpTool::readLanguage()`); the shop default language when left out; a code the shop does not have is refused |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns**
- `contacts`: one object per contact of the shop of the token, in the order of
  `Contact::getContacts()` (by name): `id_contact`, `name` (**Title**),
  `email` (**Email address**), `description` (**Description**),
  `customer_service` (boolean, whether the messages sent to it are kept in
  **Customer Service**), `used_for_customer_messages` (`true` for the contact
  of **Send emails to**, `PS_MAIL_EMAIL_MESSAGE` read through
  `SomcpToolGetEmailSettings::settings()`; `false` for all when no row is
  stored);
- `count`; `language`: the ISO code of the texts.

`id_contact` and `name` are those of `send_emails_to_choices`: a contact read
here is given to `somcp_update_email_settings` (`send_emails_to`) as it is.

No contact: `contacts: []`, `count: 0`, and a `warning`: `The shop has no
contact: the contact form of the storefront cannot send a message.`

**Errors** - in the envelope `Nothing was read. Correct these and call again:`
(`SomcpTool::refused('read', $problems)`): an unknown argument, a language the
shop does not have (`SomcpTool::languageProblem()`).

**Notes** - the addresses are the shop's own, shown on the page; nothing else
about the contacts is hidden.

**Differences between 8.0.1 and 9.0.0** - none on the list.

#### `somcp_create_contact`
**State**: written · [issue #427](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/427)
**Nature**: `acts(ADD, 'AdminContacts')` · **Scope**: `mcp.customers.write`, as `somcp_create_customer`

**Does** - creates a contact of **Shop Parameters > Contact**, as **Add new
contact** then **Save** do: a title per language, the address that receives
the messages, whether they are kept in **Customer Service**, a description.
The contact form of the storefront offers it, and **Send emails to** of
`somcp_update_email_settings` chooses among the contacts.

**Do not use for** - changing a contact (→ `somcp_update_contact`); the
stores of the **Stores** tab (not covered).

**Parameters**

| Name | Type | Required | Rule |
|---|---|---|---|
| `languages` | array | no | the languages being filled, #149 (`SomcpToolCreateProduct::languages()`) |
| `name` | object | yes | **Title**, one text per language keyed by ISO code (`texts(..., true)`); each checked as `ContactType` does: `<`, `>`, `=`, `{`, `}` refused (`name (<iso>): no <, >, =, { or }`), 255 characters at most (`name (<iso>): 255 characters at most`) |
| `email` | string | no | **Email address**, `Validate::isEmail()`, 255 at most (`email: an e-mail address`); empty when left out, as the form |
| `customer_service` | boolean | no | **Save messages?**, `false` when left out, as the switch opens (`customer_service: true or false`) |
| `description` | object | no | **Description**, one text per language keyed by ISO code; a text holding an HTML tag refused (`description (<iso>): no HTML tag`, the `NoTags` check `strip_tags($text) !== $text`) |
| `id_contact` | integer | no | comes with an item of `somcp_list_contacts`: ignored by a creation, which gets an identifier of its own |
| `used_for_customer_messages` | boolean | no | comes with an item of `somcp_list_contacts`: ignored by a creation; the receiver of the messages is chosen with `somcp_update_email_settings` (`send_emails_to`) |

Both are declared in the schema of `define()`, not in `contactSchema()`, so a
client that checks arguments against the schema accepts an item of the list
given back whole. Unknown arguments through `SomcpTool::unknownFields()`; `id_contact` and
`used_for_customer_messages`, which the list answers, are named as worked out
by the shop. The form fills an empty language with the default language's
text; the tool keeps the rule of #149 instead (every language being filled
needs its text, `languages` choosing which), and the languages not being
filled take the default one's text. The checks are
`SomcpToolCreateContact::planContact()`, public so `somcp_update_contact`
applies the same.

**Then** - as `AddContactHandler::handle()` does, on the `Contact` object (the
MCP endpoint has no command bus): `name`, `description`, `email`,
`customer_service`, the shop of the token only (`id_shop_list = [$idShop]`),
then `add()`. A `false` or an exception: a tool error `The contact could not
be created: <reason>.`, logged as `contact_create_failed`. Logged as
`contact_created` with the `id_contact` only.

**Returns** - `created: true`, `id_contact`, `contact`: the contact as
`somcp_list_contacts` gives one item (`id_contact`, `name`, `email`,
`description`, `customer_service`, `used_for_customer_messages`, through
`SomcpToolListContacts::item()`) in the shop's default language, plus `names`
and `descriptions`: every language, keyed by ISO code. `warning` when
multistore is on with more than one shop: `The contact was created for this
shop only; the back office can share it with others (Shop association).`

**Errors** - in the envelope `Nothing was created. Correct these and call
again:` (`SomcpTool::refused('created', $problems)`), every problem in one
answer: unknown arguments, `name` missing, the rules above, a language of
#149.

**Notes**
- Changes the shop: the assistant asks first. Not replayable: each call creates one more contact (two contacts may share a title, as on the page). To make it the receiver of the messages: `somcp_update_email_settings` with `send_emails_to` = the `id_contact`.
- The JSON scenario checks the refusals only; the shop test creates a contact with every field, reads it with `somcp_list_contacts`, then deletes it whatever happens.

**Differences between 8.0.1 and 9.0.0** - the label of the shop field only
(**Shop association** / **Store association**), not taken by the tool.

#### `somcp_update_contact`
**State**: written · [issue #428](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/428)
**Nature**: `acts(EDIT, 'AdminContacts')` · **Scope**: `mcp.customers.write`, as `somcp_update_customer`

**Does** - changes a contact of **Shop Parameters > Contact**, as **Edit**
then **Save** do. Only the fields given change.

**Do not use for** - choosing which contact receives the messages (→
`somcp_update_email_settings`, `send_emails_to`); creating one (→
`somcp_create_contact`).

**Parameters**

| Name | Type | Required | Rule |
|---|---|---|---|
| `id_contact` | integer | yes | from `somcp_list_contacts`; `SomcpTool::notAnIdentifier('id_contact')`; unknown or not of this shop: `SomcpTool::notFound('id_contact', 'contact', <id>, 'somcp_list_contacts')` |
| `languages`, `name`, `email`, `customer_service`, `description` | | no | the rules of `somcp_create_contact`, through the same function (`SomcpToolCreateContact::planContact()`); `name` and `description` per language keyed by ISO code, a language left out keeping its text; `email` given `""` empties the address, as the form allows |
| `used_for_customer_messages` | boolean | no | read by `somcp_list_contacts`: taken only as the list gives it, so an item can be given back whole; the receiver of the messages is chosen with `somcp_update_email_settings` (`send_emails_to`) |

A plain text of `name` or `description` equal to a stored text (one item of
`somcp_list_contacts` given back, which answers them in one language) is no
change, through `SomcpToolCreateProduct::givenBackAsRead()`, as
`somcp_update_country` takes its name. `used_for_customer_messages` of the
list is accepted when it equals the stored state and refused otherwise
(`used_for_customer_messages: not set by this tool - change send_emails_to
with somcp_update_email_settings`). Unknown arguments through
`SomcpTool::unknownFields()`.

**Then** - as `EditContactHandler` does, on the `Contact` object: each field
is compared with the stored contact; nothing differs: `changed: false`,
nothing saved. Otherwise the fields that differ are set, the shop of the token
kept as the shop list (its other shops untouched), then `update()`. A `false`
or an exception: a tool error `The contact could not be changed: <reason>.`,
logged as `contact_update_failed`. Logged as `contact_updated` with the
`id_contact` and the names of the changed fields, never their values.

**Returns** - the shape of every update tool (#356): `changed` (boolean),
`changes` (per field its `field`, `old`, `new`; a text per language as `name
(<iso>)`), `reason` when nothing changed, and `contact`: the contact as
`somcp_list_contacts` gives one item (through `SomcpToolListContacts::item()`)
in the shop's default language, plus `names` and `descriptions`: every
language, keyed by ISO code, as `somcp_create_contact` answers it.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`: unknown arguments, `id_contact` missing, not an identifier or
unknown, the rules of `somcp_create_contact`, given in one answer.

**Notes**
- Changes the shop: the assistant asks first. Replayable: a second call answers `changed: false`.
- The JSON scenario checks the refusals only, so the demo contacts 1 and 2 are never changed; the shop test changes the address and **Save messages?** of the contact it created, then gives its list item back whole (`changed: false`), before deleting it.

**Differences between 8.0.1 and 9.0.0** - none for the fields the tool takes.

#### `somcp_delete_contact`
**State**: written · [issue #429](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/429)
**Nature**: `acts(DELETE, 'AdminContacts')` · **Scope**: `mcp.customers.write`, as `somcp_create_customer`

**Does** - deletes a contact of **Shop Parameters > Contact**, as its
**Delete** action does. One contact per call: **Delete selected** is the same
call repeated.

**Do not use for** - changing a contact (→ `somcp_update_contact`).

**Parameters**

| Name | Type | Required | Rule |
|---|---|---|---|
| `id_contact` | integer | yes | from `somcp_list_contacts`; `SomcpTool::notAnIdentifier('id_contact')`; unknown or not of this shop: `SomcpTool::notFound('id_contact', 'contact', <id>, 'somcp_list_contacts')` |

Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - the contact of **Send emails to** (`send_emails_to` of
`SomcpToolGetEmailSettings::settings()`, when not `null`) is refused, nothing
deleted: `id_contact: this contact receives the customers' messages (Send
emails to) - choose another with somcp_update_email_settings first`. Stricter
than the page on purpose: `ContactDeleter` checks nothing, and the order page
would be left without a receiver. Otherwise the contact is read as
`somcp_create_contact` answers it, then `Contact::delete()` on the shop of the
token, as `ContactDeleter` does. A `false` or an exception: a tool error `The
contact could not be deleted: <reason>. Read it with somcp_list_contacts
before calling again.`, logged as `contact_delete_failed`. Logged as
`contact_deleted` with the `id_contact` only.

**Returns** - `deleted: true`, `id_contact`, and `contact`: the contact as it
was, the answer of `somcp_create_contact` (`names` and `descriptions` in every
language), so the assistant can create it again. `warning` when the shop has no
contact left, the sentence of `somcp_list_contacts`
(`SomcpToolListContacts::NO_CONTACT`): `The shop has no contact: the contact
form of the storefront cannot send a message.`

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`: an unknown argument, `id_contact` missing, not an identifier, unknown,
or the contact of **Send emails to**, given in one answer.

**Notes**
- Changes the shop and cannot be undone: the assistant asks first. Not replayable: a second call answers the unknown `id_contact`.
- The messages already received keep their `id_contact` in **Customer Service**.
- The JSON scenario checks the refusals only, so the demo contacts 1 and 2 are never deleted; the shop test deletes the contact it created with this tool, then reads the list to see it gone. The refusal for the contact of **Send emails to** is not run: the test shops have no stored row for it.

**Differences between 8.0.1 and 9.0.0** - none: `ContactDeleter` and
`Contact::delete()` are the same on both.

#### `somcp_list_stores`
**State**: written · [issue #455](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/455)
**Nature**: `acts(VIEW, 'AdminStores')` · **Scope**: `mcp.customers.read`, as `somcp_list_contacts`

**Does** - lists the stores of **Shop Parameters > Contact**, tab **Stores**
(`AdminStores`): the stores the storefront page of the stores shows, each with
every field of its **Edit** form (address, coordinates, hours, picture), so
the item can be given back whole to the update that follows. Read only,
replayable.

**Do not use for** - the contact addresses of the contact form (→
`somcp_list_contacts`).

**Parameters**

| Name | Screen | Type | Rule |
|---|---|---|---|
| `language` | | string | ISO code of an active language for the texts and the country and state names, by `SomcpTool::readLanguage()`; the shop default when left out; a code the shop does not have is refused |
| `name` | filter **Name** | string | contains, without case |
| `city`, `postcode` | filters **City**, **Zip/Postal code** | string | contains, without case |
| `id_country` | filter **Country** | integer | from `somcp_list_countries`; `SomcpTool::notAnIdentifier('id_country')`; unknown or not of this shop: `SomcpTool::notFound('id_country', 'country', <id>, 'somcp_list_countries')` |
| `active` | filter **Enabled** | boolean | `active: true or false` otherwise |
| `sort` | the sortable columns | string | `id_asc` (default, as the screen), `id_desc`, `name_asc`, `name_desc`, `city_asc`, `city_desc`, through `SomcpTool::oneOf()` |
| `limit`, `offset` | | integers | as `somcp_list_cart_rules`: 20 by default, 100 at most; `offset` 0 at least |

Unknown arguments through `SomcpTool::unknownFields()`. Every mistake in one
refusal, in the envelope `Nothing was read. Correct these and call again:`.

**Returns**
- `stores`: one object per store of the shop of the token (`store_shop`),
  built by `SomcpToolListStores::item()`, public for the tools that write a
  store: `id_store`, `name` (**Name**), `address1` (**Address**), `address2`
  (**Address (2)**), `postcode` (**Zip/Postal code**), `city`, `id_country`
  and `country` (its name in the language read), `id_state` and `state`
  (`null` without a state), `latitude`, `longitude` (numbers), `phone`,
  `fax`, `email` (**Email address**), `note`, `active` (**Active**: shown on
  the storefront), `hours`, `image`, `date_add`, `date_upd`
  (`SomcpTool::isoDate()`);
- `hours`: the seven days by their English names (`monday` to `sunday`), in
  the order of the form, each the list `store_lang.hours` stores for that day
  in the language read (`[]` for an empty day); `null` when the row holds no
  valid JSON list of seven lists of strings. Never the joined text of the form
  (` | ` between two openings);
- `image`: the **Picture**, `SomcpDownload::offer('store_image', <id_store>,
  <id_shop>)`, `null` without a picture. The kind `store_image` of
  `SomcpDownload::KINDS`, reader `somcp_list_stores`, is `img/st/<id>.jpg`
  with `store_shop` as its shop table; the thumbnails
  (`<id>-stores_default.jpg`) are never offered;
- `returned`, `total_count`, `offset`, `language`.

**Notes**
- The texts are those of `language`; the update will take them per language, as every write does.
- The JSON scenario reads the 5 demo stores, filters on a name, and checks the refusals; the shop test reads store 1 (**Dade County**) and its Monday hours, and downloads its picture when the shop has one.

**Differences between 8.0.1 and 9.0.0** - none in the list or the form. Only
the thumbnails written for a new picture differ (8.0.1 `-<type>.jpg` and `2x`
with `PS_HIGHT_DPI`, 9.0.0 every format of `ImageFormatConfiguration`), which
the update will follow.

#### `somcp_get_contact_details`
**State**: written · [issue #456](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/456)
**Nature**: `acts(VIEW, 'AdminStores')` · **Scope**: `mcp.customers.read`, as `somcp_list_contacts`

**Does** - reads the card **Contact details** of **Shop Parameters >
Contact**, tab **Stores**: the shop's own name, e-mail, registration number,
address and phone, which the e-mails, the invoices and the page titles print.
Read only, replayable; one object per shop.

**Do not use for** - the stores shown on the storefront (→
`somcp_list_stores`); the addresses of the contact form (→
`somcp_list_contacts`).

**Parameters** - none (`additionalProperties: false`), as
`somcp_get_general_settings`; an unknown argument through
`SomcpTool::unknownFields()`.

**Returns**
- `contact_details`, built by `SomcpToolGetContactDetails::details()`, public
  for the change that follows, each key read for the shop of the token and
  its group through `SomcpToolGetGeneralSettings::stored()`:

| Field | Screen | Setting |
|---|---|---|
| `shop_name` | **Store name** | `PS_SHOP_NAME` |
| `shop_email` | **Shop email** | `PS_SHOP_EMAIL` |
| `registration_number` | **Registration number** | `PS_SHOP_DETAILS` |
| `address1` | **Shop address line 1** | `PS_SHOP_ADDR1` |
| `address2` | **Shop address line 2** | `PS_SHOP_ADDR2` |
| `postcode` | **Zip/Postal code** | `PS_SHOP_CODE` |
| `city` | **City** | `PS_SHOP_CITY` |
| `id_country`, `country` | **Country** | `PS_SHOP_COUNTRY_ID` |
| `id_state`, `state` | **State** | `PS_SHOP_STATE_ID` |
| `phone` | **Phone** | `PS_SHOP_PHONE` |
| `fax` | **Fax** | `PS_SHOP_FAX` |

- `id_country` and `id_state` are `null` for 0 or no row, with `country` and
  `state` `null`; otherwise the names in the shop's default language, from
  the identifiers (never `PS_SHOP_COUNTRY` and `PS_SHOP_STATE`, which the
  screen writes beside them);
- `language`: the ISO code of the names;
- `warning` when no country is saved: `No country is saved: the screen
  preselects <name of the context country>, which the shop does not use until
  it is saved.` The answer never gives that preselected country as the shop's.

**Notes** - the e-mail is the shop's, not a person's: it is answered as the
screen shows it. The field names are those of the change, which takes the same
object back.

**Differences between 8.0.1 and 9.0.0** - none in the card
(`_getDefaultFieldsContent()` is the same on both).

#### `somcp_list_tax_rules`
**State**: written
**Nature**: `acts(VIEW, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.read`

**Does** - lists the tax rules a product can take, with the rate each one applies in the shop's default country.

**Do not use for** - the tax of one product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | the **Enabled** filter: true by default, enabled rules only; false disabled rules only; anything else refused (`active: true or false`) |
| `name` | string | no | part of the rule name (the **Name** filter of the list), case-insensitive; left out, every rule |

**Returns** - per rule: `id_tax_rules_group`, `name`, `active`, `rate` in the default country, `country` (ISO code of that country). Plus `count`, and `taxes_enabled`: false when the shop has taxes switched off, so that no rule applies.

**Notes**
- Scope `mcp.catalog.read`, not a configuration one: it is read to price a product. The rate depends on the country; the one returned is the one the back office product page shows.
- The rate comes from `TaxRulesGroup::getAssociatedTaxRatesByIdCountry()` for `PS_COUNTRY_DEFAULT` of the shop of the token, the same call `somcp_get_product` and `somcp_search_products` make, so the three tools agree. A rule with nothing for that country gives 0.
- `taxes_enabled` added to the first draft of this entry: with `PS_TAX` off, `somcp_get_product` gives a rate of 0 whatever the rule, and an assistant reading 20 here would otherwise price tax included wrongly.
- Read through a query on `tax_rules_group` and `tax_rules_group_shop` in the shop of the token, rather than `TaxRulesGroup::getTaxRulesGroups()`, which takes the shop from the global context. Rules deleted in the back office (`deleted = 1`, kept for the orders that used them) are left out. Sorted by identifier, the order of **International > Taxes > Tax Rules** (the product page drop-down sorts by name, the list the merchant manages them in does not).
- `name` filters as the **Name** column of that list does, the same parameter as `somcp_list_manufacturers`, `somcp_list_suppliers` and `somcp_list_attachments`.

#### `somcp_get_tax_rule`
**State**: written · [issue #236](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/236)
**Nature**: `acts(VIEW, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.read`

**Does** - reads one tax rule of **International > Taxes > Tax Rules** as its
edit page shows it: its **Name**, **Enable**, and every row of its list, the
tax it applies per **Country**, **State** and **Zip/Postal code**, with its
**Behavior**. `somcp_list_tax_rules` gives only the rate in the shop's default
country; this is what tells what a customer delivered abroad pays. Read only.

**Do not use for** - the rate of one product (→ `somcp_get_product`); listing
the rules (→ `somcp_list_tax_rules`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rules_group` | the rule opened from the list | integer | yes | from `somcp_list_tax_rules` (`active: false` lists the disabled ones) |
| `country` | **Country** | string | no | ISO code of a country (`DE`): only the rows of that country; left out, every row |
| `language` | | string | no | ISO code for the country, state and tax names; the shop default when left out |

`country` takes an ISO code rather than an `id_country`: no somcp tool lists
countries, and the ISO code is what `somcp_list_tax_rules` gives.

**Returns**
- `id_tax_rules_group`, `name` (**Name**), `active` (**Enable**), `date_add`, `date_upd` (ISO 8601, `SomcpTool::isoDate()`);
- `country_rules`: one entry per row of the list, in its order (`id_tax_rule` ascending): `id_tax_rule`, `country` (ISO code) and `country_name`, `state` (ISO code, `null` for every state) and `state_name`, `zipcode_from` / `zipcode_to` (`null` when the row has no range, the `--` of the list), `behavior` (`this_tax_only`, `combine`, `one_after_another`, the three choices of **Behavior**), `id_tax`, `tax_name` and `rate` (`id_tax` 0, `tax_name` `null` and `rate` 0 for **No Tax**), `description`;
- `rate_in_default_country` and `default_country`: the rate `somcp_list_tax_rules` gives for this rule and the country it is for, through the same `SomcpToolListTaxRules::defaultCountryRates()`, so the two tools agree;
- `taxes_enabled`, as `somcp_list_tax_rules`, and `language`.

**Errors** - in the shared envelope, every problem at once: a language the
shop does not have, named by `SomcpTool::languageProblem()` with the ones it
has; an unknown argument, an identifier that is not a whole number above 0, an
unknown, deleted or other-shop rule (`id_tax_rules_group: no tax rule of this
shop has the identifier <n> - find it with somcp_list_tax_rules`), an unknown
`country` (refused, never read as every row).

**Notes**
- Read through queries on `tax_rules_group` joined to `tax_rules_group_shop` for the token's shop, as `somcp_list_tax_rules` does, never `new TaxRulesGroup()` or `TaxRulesGroup::getTaxRulesGroups()`, which load a rule of any shop or read the shop from the global context. The rows from `tax_rule`, joined to `country_lang`, `state` and `tax_lang` in the language read; a row whose tax was deleted since is still listed, as the edit page lists it.
- A rule made with **Country** All has one row per country: the answer can hold some 240 rows. No paging: the list of the edit page shows them all on one page too; `country` narrows it.
- On 8.0.1 the panel is **Tax Rules**, on 9.0.0 **Tax rules**; the fields and tables are the same on both.

#### `somcp_create_tax_rule`
**State**: written · [issue #238](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/238)
**Nature**: `acts(ADD, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - creates one tax rule of **International > Taxes > Tax Rules** with
its **Name** and **Enable**, as **Add new tax rule** then **Save and stay** do.
It is the first step of setting up the taxes of a new shop: the rule gets its
rows per country afterwards, and products take its `id_tax_rules_group`.

**Do not use for** - adding a country, a state or a zip range to a rule
(→ `somcp_add_country_rule`); changing a rule (→ `somcp_update_tax_rule`); deleting a rule
(→ `somcp_delete_tax_rule`); giving a rule to a product
(→ `somcp_update_product`, `id_tax_rules_group`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `name` | **Name** | string | yes | at most the size of the **Name** column, read from the database: 64 characters on PrestaShop 9, 50 on some 8.0 shops; `Validate::isGenericName()` (no `<`, `>`, `=`, `{`, `}`) |
| `active` | **Enable** | boolean | no | left out, `false`, the default of the form; the answer says so. A disabled rule is not offered on the product page |

**Then** - `new TaxRulesGroup()`, `name`, `active`, `add()` with
`id_shop_list` set to the token's shop, as the form saves it in that shop's
context; then read back through `SomcpToolGetTaxRule::rule()`, the function of
`somcp_get_tax_rule`.

**Returns** - `created: true`, then `tax_rule`, the rule as
`somcp_get_tax_rule` returns it in the shop default language
(`id_tax_rules_group`, `name`, `active`, `date_add`, `date_upd`,
`country_rules` empty, `rate_in_default_country` 0, `default_country`,
`taxes_enabled`, `language`). When `active` was left out, a `note` says the
rule was created disabled, as the form does.

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`, every problem at once, each named with its field: `name` missing
(`name: required`), not a text, empty, too long (`name: <size of the column> characters at most,
<n> given`) or with a refused character; `active` not a boolean (`active: true
or false`, as `somcp_list_tax_rules`); unknown arguments through
`SomcpTool::unknownFields()`, the fields `somcp_get_tax_rule` returns and the
shop works out named as such. A failed `add()` is reported as such, the rule
removed again if it went in: nothing half written.

**Notes**
- Not replayable: each call creates one more rule, even with the same name. On a lost answer, read `somcp_list_tax_rules` with `name`, with `active: true` and with `active: false`, before calling again.
- Scope `mcp.catalog.write`, as `somcp_list_tax_rules` reads with `mcp.catalog.read`: a tax rule is created to price products.
- Multistore: the rule is created for the token's shop only, as the form in one shop's context.
- The same form, fields and class definition on 8.0.1 and 9.0.0; the panel title differs (**Tax Rules** / **Tax rules**), and so can the size of the `tax_rules_group.name` column: `varchar(64)` on 9.0.0, `varchar(50)` on some 8.0 shops although the class says 64. The limit is read from the column (`SHOW COLUMNS`), 64 when it cannot be read, so a name the database would cut is refused instead. Names are not unique: the back office saves two rules of the same name.

#### `somcp_update_tax_rule`
**State**: written · [issue #294](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/294)
**Nature**: `acts(EDIT, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - changes the **Name** and **Enable** of one tax rule of
**International > Taxes > Tax Rules**, as its edit page then **Save and stay**
do, and so also switches a rule on or off as the **Enabled** column of the
list does (the same `active` field). Only what is given changes.

**Do not use for** - adding a country row to the rule
(→ `somcp_add_country_rule`); deleting one (→ `somcp_delete_country_rule`);
changing one (→ `somcp_update_country_rule`); deleting a rule (→ `somcp_delete_tax_rule`); giving a
rule to a product (→ `somcp_update_product`, `id_tax_rules_group`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rules_group` | the rule edited | integer | yes | from `somcp_list_tax_rules` or the answer of `somcp_create_tax_rule`; a rule that does not exist, is deleted or is not in the token's shop is not found, through `SomcpToolGetTaxRule::existingRule()`, as `somcp_get_tax_rule` answers it |
| `name` | **Name** | string | no | the checks of the create, through `SomcpToolCreateTaxRule::name()`: not empty, at most the size of the **Name** column (64 characters on PrestaShop 9, 50 on some 8.0 shops), `Validate::isGenericName()`. Left out: unchanged |
| `active` | **Enable** | boolean | no | `true` or `false`, refused otherwise with `active: true or false`. Left out: unchanged |

The fields `somcp_get_tax_rule` returns and the shop works out
(`SomcpToolCreateTaxRule::COMPUTED`) are refused as worked out by the shop,
so a rule read with `somcp_get_tax_rule` can be given back after its
`id_tax_rules_group`, `name` and `active` are picked out.

**Then** - `name` and `active` are compared with the stored values first.
Nothing differs: `changed: false`, `id_tax_rules_group` and `reason` (`The
tax rule already holds every value given. Nothing was written.`), and
`update()` is never called. Something differs and no order line carries the
rule (`TaxRulesGroup::isUsed()`): `name`, `active`, `update()` with
`id_shop_list` set to the token's shop. Carried by an order line: the rule
stays unchanged for the orders already placed and the change goes on a copy,
through `SomcpToolUpdateTaxRule::replaceUsed()`, never `update()` on the used
rule: (1) `duplicateObject()`, (2) a `tax_rules_group_shop` row for the copy
per shop of the old rule, (3) the `tax_rule` rows copied as `historize()` does,
(4) `product`, `product_shop` and `carrier_tax_rules_group_shop` moved to the
copy (never `carrier`), (5) the old rule `softDelete()`d, so the lists no
longer show it, then (6) `name` and `active` written on the copy with
`update()`. The copy is complete before anything leaves the old rule: a failure
in 1 to 3 removes it and nothing moved; a failure in 4 to 6 names the step and
both identifiers, logged, so the merchant can look in the back office. The
tools that change the country rows of a rule call the same function (#303,
#304).

**Returns** - `changed: true`, `id_tax_rules_group`, `changes` (one entry per
field changed: `field`, `old`, `new`), then `tax_rule` as `somcp_get_tax_rule`
returns it, read back through `SomcpToolGetTaxRule::rule()`. When the rule is
also in other shops, `also_in_other_shops` (their number) and a `warning`.
A rule carried by an order line: `id_tax_rules_group` is the copy's,
`replaced_id_tax_rules_group` the old one, and the `warning` starts with `This
rule is used by orders already placed: PrestaShop keeps it unchanged for them
and gives the rule a new identifier, <new>. Products and carriers now use the
new one.`

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once, each named with its field: identifier not
given or not found (pointing to `somcp_list_tax_rules`), `name` as the create
refuses it, `active` not a boolean, unknown arguments through
`SomcpTool::unknownFields()`. A failed `update()` is reported as such.

**Notes**
- Replayable: the same call twice changes nothing the second time. For a rule carried by an order line, the old identifier is not found after the first call: on a lost answer, find the copy with `somcp_list_tax_rules` and `name`.
- A disabled rule is no longer offered on the product page; products that already have it keep it.
- Multistore: a tax rule has no per-shop name or status (`tax_rules_group` only), so the change shows in every shop the rule is in, as the back office does it from one shop's context.
- The same form, fields and class definition on 8.0.1 and 9.0.0; the panel title differs (**Tax Rules** / **Tax rules**), and so can the size of the `tax_rules_group.name` column (see `somcp_create_tax_rule`).
- Differences between 8.0.1 and 9.0.0: for a rule an order line carries, `TaxRulesGroup::update()` copies it through `historize()`. On 9.0.0 that works; on 8.0.1 its last but one statement updates a `carrier.id_tax_rules_group` column that does not exist, so `historize()` fails after the old rule is deleted and its copy made, the carriers stay on the deleted rule and the change is never written. The tool runs the 9.0.0 sequence itself, so it behaves the same on both.

#### `somcp_delete_tax_rule`
**State**: written · [issue #296](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/296)
**Nature**: `acts(DELETE, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one tax rule of **International > Taxes > Tax Rules** with
its rows per country, as the row action **Delete** of the list does
(**Delete selected** does the same for several, one per call here).

**Do not use for** - switching a rule off (→ `somcp_update_tax_rule`,
`active: false`), which keeps it for later; deleting one country row of a
rule (→ `somcp_delete_country_rule`); adding a country row
(→ `somcp_add_country_rule`); changing one (→ `somcp_update_country_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rules_group` | the row deleted | integer | yes | from `somcp_list_tax_rules`; unknown, deleted or not in the token's shop: not found, through `SomcpToolGetTaxRule::existingRule()`, as `somcp_get_tax_rule` answers it |

Unknown arguments through `SomcpTool::unknownFields()`.

**Refused, nothing deleted** - every reason at once, unlike the back office,
which deletes the rule and leaves products and carriers pointing at a rule
that no longer exists, then charged no tax without a word:
- products of the token's shop on it (`product_shop`): `id_tax_rules_group: used by N products - give them another tax rule with somcp_update_product first, or they would be sold without tax`;
- carriers of the token's shop on it (`carrier_tax_rules_group_shop`, deleted carriers left out): `id_tax_rules_group: used by N carriers - give them another tax rule in Shipping > Carriers first`;
- a rule `tax_rules_group_shop` also ties to another shop: `id_tax_rules_group: also used by N other shops of this installation - delete it from the back office in the context of every shop`, since `delete()` removes it from every shop.

One of a kind is said in the singular (`used by 1 carrier`).

**Then** - `TaxRulesGroup::delete()`, as `AdminController::processDelete()`
calls it for this controller; then `tax_rules_group` is checked to no longer
hold the row.

**Returns** - `deleted: true`, `id_tax_rules_group`, `name` (read before),
`country_rules_deleted` (the number of its `tax_rule` rows, read before).

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`, each problem named with its field. A failed `delete()` is reported
as such, with a hint to read the rule with `somcp_get_tax_rule`.

**Notes**
- Not replayable: a second call finds nothing and says so.
- A real deletion, not the `deleted = 1` a change of a used rule leaves: past orders keep their amounts (`order_detail_tax`).
- No difference between 8.0.1 and 9.0.0: the same controller path, the same `delete()`, the same tables.

#### `somcp_add_country_rule`
**State**: written · [issue #303](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/303)
**Nature**: `acts(ADD, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - adds a row to one tax rule of **International > Taxes > Tax
Rules**, as the **New tax rule** panel of its edit page then **Save and
stay** do: a country, its states, a zip/postal code range, a behavior, a tax
and a description. A rule without a row charges nothing. **All** in
**Country** adds one row for each country of the shop in one call.

**Do not use for** - creating a
rule (→ `somcp_create_tax_rule`); renaming or switching a rule off
(→ `somcp_update_tax_rule`); deleting a row (→ `somcp_delete_country_rule`);
changing a row (→ `somcp_update_country_rule`).

**Parameters**

The names are those `somcp_get_tax_rule` gives in each `country_rules`
entry, so a row read there can be given here.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rules_group` | the rule edited | integer | yes | from `somcp_list_tax_rules`; unknown, deleted or not in the token's shop: not found, through `SomcpToolGetTaxRule::existingRule()` |
| `country` | **Country** | string or `null` | yes | ISO code, read through `SomcpToolCreateSupplier::supplierCountry()`, as the supplier and manufacturer address tools (`country: no country of this shop has the ISO code "ZZ"`); `null` is **All**: one row, `id_state` 0, for each country of the shop (`country_shop`), active or not, as `processCreateRule()` loops over `Country::getCountries()`, read through `SomcpToolCreateSupplier::shopCountries()`, the join `supplierCountry()` uses. Left out stays `country: required, the ISO code of the country`: **All** is asked for, never the result of a field left out |
| `state` | **State** | string or `null` | no | one state, as `somcp_get_tax_rule` gives it: one ISO code of the country's states through `SomcpToolCreateSupplier::stateOf()` (`state: US has no state with the ISO code "ZZ"`), one row; `null` is **All**; a code for a country without states: `state: FR has no states, leave the field out` (`null` is accepted there). With `states` in the same call: `state: state or states, not both`, `state: null` counting as given |
| `states` | **State** | array of strings | no | the list for several: ISO codes of the country's states, one row each, each through `SomcpToolCreateSupplier::stateOf()` (`states: US has no state with the ISO code "ZZ"`); left out or empty: **All** (one row, `id_state` 0); given for a country without states: `states: FR has no states, leave the field out`; a code twice counts once |
| `zipcode_from` | **Zip/Postal code range**, first bound | string | no | `Validate::isPostCode()`, no hyphen, the size of the column at most (12), and `Country::checkZipCode()` when the country has `need_zip_code` and a `zip_code_format` (`zipcode_from: not in the format of US (00000)`) |
| `zipcode_to` | **Zip/Postal code range**, second bound | string | no | the same checks; only with `zipcode_from` (`zipcode_to: only with zipcode_from`); the two ordered and folded by `TaxRule::breakDownZipCode()` |
| `behavior` | **Behavior** | string | no | `this_tax_only` (the default, first option of the form), `combine` or `one_after_another`, the values of `SomcpToolGetTaxRule::BEHAVIORS` |
| `id_tax` | **Tax** | integer | yes | from `somcp_list_taxes`; `0` is **No Tax**; otherwise a tax that exists and is not deleted. Required here, unlike the form, so a row is never added without a tax by omission |
| `description` | **Description** | string | no | `Validate::isString()`, the size of the column at most (100) |

Unknown arguments through `SomcpTool::unknownFields()`; `id_tax_rule`,
`country_name`, `state_name`, `tax_name` and `rate` are named as worked out
by the shop. A `country_rules` entry of `somcp_get_tax_rule`, or the
`country_rule` of `somcp_delete_country_rule`, is given back with
`id_tax_rules_group` once those five are picked out.

**Refused, nothing written** - every problem at once:
- a country and state that already have a **This tax only** row, through `TaxRulesGroup::hasUniqueTaxRuleForCountry()` as the form checks it, named with the field the call used (`state` or `states`): `states: US AL already has a This tax only row in this rule - delete it with somcp_delete_country_rule first` (`country: US ...` without a state);
- a **This tax only** row asked where the country and state already have any row, which the form lets through although the other rows would never apply: `states: US AL already has a row in this rule, and a This tax only row must be alone - choose combine or one_after_another, or delete that row with somcp_delete_country_rule first`;
- with **All**, the same two tests for each country, gathered into one line rather than one per country: `country: 3 countries already have a row in this rule that All would contradict: FR, US, DE - delete them with somcp_delete_country_rule first, or choose combine or one_after_another`. The core stops at the first such country and keeps the rows written before it; the tool writes none;
- with **All**, a state or a range, which belong to one country: `state: only with one country, not with All` (or `states:`), `zipcode_from: only with one country, not with All` (or `zipcode_to:`). A range is refused rather than checked against the format of every country, which the core refuses for most of them anyway.

**Then** - one `TaxRule` per state (or one with `id_state` 0), for each
country with **All**, then `add()`;
a failure after the first row deletes the rows the call added, so a call adds
all its rows or none. Then `update()` on the rule, for the token's shop only,
as `updateTaxRulesGroup()` does, so its `date_upd` says when it last changed.
A rule an order line carries (`TaxRulesGroup::isUsed()`) is first replaced by
a copy under a new identifier, through `SomcpToolUpdateTaxRule::replaceUsed()`,
the copy `somcp_update_tax_rule` makes, and the rows go on the copy.

**Returns** - `created: true`, `id_tax_rules_group` (the copy's for a used
rule, with `replaced_id_tax_rules_group`), `id_tax_rule` (the rows added, one
per state, or per country with **All**), `country_count` (the number of
countries written, with **All** only), `also_in_other_shops` and `warning` when `tax_rules_group_shop`
ties the rule to other shops, then `tax_rule` as `somcp_get_tax_rule` returns
it.

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`, each problem named with its field. A failed `add()` is reported as
such, with a hint to read the rule with `somcp_get_tax_rule`.

**Notes**
- Not replayable: the same call twice adds the rows twice (unless the first is **This tax only**, then refused). Read the rule first, and again if an answer is lost.
- A row with no state applies to the whole country; a **Combine** or **One after another** row adds to the other rows that match an address.
- No difference between 8.0.1 and 9.0.0: the same panel and table; `TaxRule` gives no size on 8.0.1, hence the limits read from the columns.

#### `somcp_update_country_rule`
**State**: written · [issue #306](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/306)
**Nature**: `acts(EDIT, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - changes one row of a tax rule of **International > Taxes > Tax
Rules** in place, as the row **Edit** of the list on its edit page fills the
**New tax rule** panel with the row and **Save and stay** saves it: its
country, state, zip/postal code range, behavior, tax and description. One row
per call; only what is given changes.

**Do not use for** - adding a state or a country to a rule (→
`somcp_add_country_rule`: a second state is a second row); deleting a row
(→ `somcp_delete_country_rule`); the **Name** and **Enable** of the rule
(→ `somcp_update_tax_rule`).

**Parameters**

The names are those of a `country_rules` entry of `somcp_get_tax_rule`, so a
row read there can be given back after its `id_tax_rule`, with only what
changes.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rule` | the row edited | integer | yes | from `somcp_get_tax_rule`; found as `somcp_delete_country_rule` finds it, through `SomcpToolDeleteCountryRule::ruleOf()`: `id_tax_rule: no country row of a tax rule of this shop has the identifier <n> - find it with somcp_get_tax_rule` |
| `country` | **Country** | string | no | ISO code, through `SomcpToolCreateSupplier::supplierCountry()`, as `somcp_add_country_rule`. Changed alone, the stored state must belong to the new country, otherwise `state: AL is not a state of FR - give state too, or null for All` |
| `state` | **State** | string or `null` | no | one ISO code of the country's states, through `SomcpToolCreateSupplier::stateOf()` (`state: US has no state with the ISO code "ZZ"`); `null` is **All** (`id_state` 0); given for a country without states: `state: FR has no states, leave the field out`. Singular, as `somcp_get_tax_rule` gives it |
| `zipcode_from` | **Zip/Postal code range**, first bound | string or `null` | no | the checks of `SomcpToolAddCountryRule::zipcodes()`, against the country the row will have (run again when the country changes); `null` removes the range, both bounds |
| `zipcode_to` | **Zip/Postal code range**, second bound | string or `null` | no | the same checks; ordered and folded by `TaxRule::breakDownZipCode()` with the resulting `zipcode_from`; given without a range left: `zipcode_to: only with zipcode_from` |
| `behavior` | **Behavior** | string | no | the values of `SomcpToolGetTaxRule::BEHAVIORS`, through `SomcpToolAddCountryRule::behavior()` |
| `id_tax` | **Tax** | integer | no | from `somcp_list_taxes`, `0` **No Tax**, through `SomcpToolAddCountryRule::tax()` |
| `description` | **Description** | string | no | through `SomcpToolAddCountryRule::description()` |

Left out (or `null` for `behavior` and `id_tax`): unchanged. Unknown
arguments through `SomcpTool::unknownFields()`; `country_name`, `state_name`,
`tax_name` and `rate` are named as worked out by the shop. No
`id_tax_rules_group`: the row names its rule.

**Refused, nothing written** - every problem at once, and when the country,
the state or the behavior changes, the two checks of
`SomcpToolAddCountryRule::unique()` with the edited row left out of both, as
`hasUniqueTaxRuleForCountry()` leaves it out on the edit page:
`state: US AL already has a This tax only row in this rule - delete it with somcp_delete_country_rule first`
(`country: US ...` without a state), and the **This tax only** row that would
not be alone for its country and state.

**Then** - every field given compared with the stored row first (zip codes
after `breakDownZipCode()`). Nothing differs: `changed: false`, `id_tax_rule`
and `reason` `The country row already holds every value given. Nothing was
written.`, nothing saved and no copy made. Something differs: a rule an order
line carries (`TaxRulesGroup::isUsed()`) is first replaced by a copy through
`SomcpToolUpdateTaxRule::replaceUsed()`, and the same row is found again on
the copy with `SomcpToolDeleteCountryRule::sameRow()`; then `TaxRule::update()`
on the row, then `update()` on the rule for the token's shop only, so its
`date_upd` says when it last changed.

**Returns** - `changed: true`, `id_tax_rule` (the row on the copy, for a used
rule), `id_tax_rules_group`, for a used rule `replaced_id_tax_rules_group`
and the `warning` of `somcp_add_country_rule`, `changes` (one entry per field
changed: `field`, `old`, `new`, in the names and values of a `country_rules`
entry), `also_in_other_shops` and its `warning` when `tax_rules_group_shop`
ties the rule to other shops, then `tax_rule` as `somcp_get_tax_rule` returns
it.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, each problem named with its field. A failed `update()` is reported
as such, with a hint to read the rule with `somcp_get_tax_rule`.

**Notes**
- Replayable: the same call twice changes nothing the second time.
- No difference between 8.0.1 and 9.0.0 for an unused rule: the same `processCreateRule()` and table; for a used rule the copy is made the same way on both.

#### `somcp_delete_country_rule`
**State**: written · [issue #304](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/304)
**Nature**: `acts(DELETE, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one row of a tax rule of **International > Taxes > Tax
Rules**, as the row **Delete** of the list on its edit page does (**Delete
selected** does the same for several, one per call here).

**Do not use for** - deleting the whole rule (→ `somcp_delete_tax_rule`);
switching a rule off (→ `somcp_update_tax_rule`, `active: false`); adding a
row (→ `somcp_add_country_rule`); changing a row in place
(→ `somcp_update_country_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax_rule` | the row deleted | integer | yes | from `somcp_get_tax_rule`, `country_rules[].id_tax_rule`; read with `SomcpTool::id()`; its rule must pass `SomcpToolGetTaxRule::existingRule()` for the token's shop, otherwise `id_tax_rule: no country row of a tax rule of this shop has the identifier <n> - find it with somcp_get_tax_rule`, so a row of a deleted rule or of another shop's rule is not found |

Unknown arguments through `SomcpTool::unknownFields()`. No
`id_tax_rules_group`: the row names its rule, and a second identifier could
only contradict it.

**Then** - the row read before, through `SomcpToolGetTaxRule::countryRule()`;
`TaxRule::delete()`, then `tax_rule` is checked to no longer hold the row;
then `update()` on the rule, for the token's shop only, as
`updateTaxRulesGroup()` does, so its `date_upd` says when it last changed. A
rule an order line carries (`TaxRulesGroup::isUsed()`) is first replaced by a
copy under a new identifier, through `SomcpToolUpdateTaxRule::replaceUsed()`,
and the same row is deleted from the copy, as PrestaShop 9 does it. The last
row may go: the rule stays, charging nothing.

**Returns** - `deleted: true`, `id_tax_rule`, `id_tax_rules_group` (the
copy's for a used rule, with `replaced_id_tax_rules_group`), `country_rule`
(the row as it was, a `country_rules` entry of `somcp_get_tax_rule`), then
`tax_rule` as `somcp_get_tax_rule` returns it after the deletion. A `warning`
when the last row went while products or carriers of the token's shop take
the rule (the counts of `SomcpToolDeleteTaxRule::users()`), and
`also_in_other_shops` when `tax_rules_group_shop` ties the rule to other
shops.

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`, each problem named with its field. A failed `delete()` is reported
as such, with a hint to read the rule with `somcp_get_tax_rule`.

**Notes**
- Not replayable: a second call finds nothing and says so.
- No difference between 8.0.1 and 9.0.0 for an unused rule: the same controller path, the same `TaxRule::delete()`, the same table; for a used rule the copy is made the same way on both.

#### `somcp_list_taxes`
**State**: written · [issue #237](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/237)
**Nature**: `acts(VIEW, 'AdminTaxes')` · **Scope**: `mcp.catalog.read`

**Does** - lists the taxes of **International > Taxes > Taxes** with the
columns of that list: **ID**, **Name**, **Rate**, **Enabled**. It is the source
of the `id_tax` a tax rule applies (`somcp_get_tax_rule` gives it per country):
no other tool gives one. Read only.

**Do not use for** - the tax rules a product takes (→ `somcp_list_tax_rules`:
a product takes a tax rule, never a tax); what a rule applies per country
(→ `somcp_get_tax_rule`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax` | **ID** filter | integer | no | the tax with this identifier; one no tax has gives an empty list, as the **ID** filter of `somcp_list_customer_groups` |
| `name` | **Name** filter | string | no | part of the name, case-insensitive, in the language of the answer; left out, every tax |
| `rate` | **Rate** filter | number | no | the taxes whose rate is exactly this number, compared at the three decimals of the column (`5.5` finds `5.500`) |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both (**All**, the default of the list) |
| `language` | | string | no | ISO code for the names, through `SomcpTool::readLanguage()`; the shop default when left out |

No paging: a shop holds tens of taxes, not thousands, and one answer lets the
assistant pick the right one by name and rate.

**Returns** - `taxes`, sorted by `id_tax` as the list: `id_tax`, `name`, `rate`
(a number, `4` for `4.000`), `active`. Plus `count`, `taxes_enabled` (**Enable
tax** of the **Tax options**, as `somcp_list_tax_rules` gives it: when false no
rate is charged at all) and `language`.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_tax` that is not an identifier (`SomcpTool::notAnIdentifier()`), a `rate`
that is not a number (`rate: a number, for example 20 or 5.5`), an
`active` that is not a boolean (`active: true or false`, the line of
`somcp_list_tax_rules`), a language the shop does not have, named by
`SomcpTool::languageProblem()` with the ones it has. An empty list is
`taxes: []`, `count: 0`.

**Notes**
- Read through a query on `tax` and `tax_lang` (left join, so a tax with no name in the language read is still listed), `deleted = 0`, rather than `Tax::getTaxes()`, which takes the language and nothing else and has no name filter. A tax deleted from the list stays in the table with `deleted = 1` when an order used it; the list leaves it out, and so does the tool.
- Taxes are not tied to a shop (no `tax_shop` table on either version): the same list for every shop.
- `name` filters as the **Name** filter of `somcp_list_tax_rules`, `somcp_list_manufacturers` and `somcp_list_attachments` do.
- `rate` is an equality, where the list matches the rate as text: there `6` also finds 6.250, 6.600 and 6.850, while the rate an assistant is given is always the one it means exactly.
- The same list, columns, filters and tables on 8.0.1 and 9.0.0; 9.0.0 only offers two more **Items per page** choices. The **Tax options** form below the list is read by `somcp_get_tax_options`.
- The taxes are created, changed and deleted with `somcp_create_tax`.

#### `somcp_list_languages`
**State**: written · [issue #339](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/339)
**Nature**: `acts(VIEW, 'AdminLanguages')` · **Scope**: `mcp.catalog.read`

**Does** - lists the languages of **International > Localization >
Languages** with the columns of that list: **ID**, **Name**, **ISO code**,
**Language code**, **Locale** (shown on 9.0.0), **Date format**, **Date format
(full)**, **Enabled**, and which one is the shop default. The `iso_code` is
the key every translated field of the other tools takes. Read only.

**Do not use for** - the translations of the shop (**International >
Translations**); changing a language (→ `somcp_update_language`); deleting
one (→ `somcp_delete_language`); adding one (→ `somcp_create_language`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_lang` | **ID** filter | integer | no | one language, exact, read with `SomcpTool::id()`; an identifier the shop does not have gives an empty list, as the screen and `somcp_list_cms_pages` do |
| `name` | **Name** filter | string | no | part of the name, case-insensitive |
| `iso_code` | **ISO code** filter | string | no | exact, case-insensitive (`fr`) |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both (**All**) |

No filter on the codes and date formats, no paging and no `language`: a shop
holds a handful of languages, and their names are those of the `lang` table.

**Returns** - `languages`, sorted by `id_lang` as the list: `id_lang` (**ID**),
`name` (**Name**), `iso_code` (**ISO code**), `language_code` (**Language
code**), `locale` (**Locale**, read on both versions), `date_format` (**Date
format**), `date_format_full` (**Date format (full)**), `active`
(**Enabled**), `is_rtl`, `default` (`true` for the `PS_LANG_DEFAULT` of the
shop of the token, the language a tool reads in when `language` is left out),
`flag` (**Flag**: a `download_ref` through `SomcpDownload::offer('language_flag',
...)` on `img/l/<id_lang>.jpg`, `null` without one, which `somcp_update_language`
takes back as no change). Plus `count`. Only an enabled language is accepted by the `language` and
`languages` of the other tools; a disabled one is listed so the assistant can
tell the merchant why.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_lang` that is not an identifier (`SomcpTool::notAnIdentifier()`), an
`active` that is not a boolean (`active: true or false`, the line of
`somcp_list_taxes`). An empty list is `languages: []`, `count: 0`.

**Notes**
- Read through a query on `lang` joined to `lang_shop` on the shop of the token, rather than `Language::getLanguages()`, so every filter stays one `WHERE`; the default read as `somcp_ping` reads it.
- The flag is given as every file of the module is, a `download_ref` for `somcp_download_file`, never an address.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - the **Locale** column and its filter on 9.0.0 only (the answer gives `locale` on both, from the same column); otherwise the same list, filters, labels and tables (`lang`, `lang_shop`).

#### `somcp_create_language`
**State**: written · [issue #372](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/372)
**Nature**: `acts(ADD, 'AdminLanguages')` · **Scope**: `mcp.catalog.write`

**Does** - adds one language to **International > Localization > Languages**,
as **Add new language** does. A shop that opens to a new market needs the
language before any product, page or e-mail can be written in it.

**Do not use for** - changing a language (→ `somcp_update_language`);
installing a language with its translations (→ `somcp_install_language_pack`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `name` | **Name** | string | yes | the checks of `somcp_update_language` (`SomcpToolUpdateLanguage::text()`) |
| `iso_code` | **ISO code** | string | yes | the same checks; a code already used (`Language::getIdByIso()`): `iso_code: <code> is already the code of <name> (<id_lang>)` |
| `language_code` | **Language code** | string | yes | the same checks |
| `date_format` | **Date format** | string | no | the same checks; left out, `Y-m-d` |
| `date_format_full` | **Date format (full)** | string | no | the same checks; left out, `Y-m-d H:i:s` |
| `flag` | **Flag** | object | yes | `{url}` or `{upload_ref}` through `SomcpUpload::schema()` and `argument()`, declared with `files: ['flag' => SomcpUpload::images()]`; left out, the line of an empty one |
| `no_picture` | **"No-picture" image** | object | yes | the same |
| `is_rtl` | **RTL language** | boolean | no | left out, `false` |
| `active` | **Status** | boolean | no | left out, `true`, as the switch of the form |

**Locale** (9.0.0 only on screen) is never asked: it is taken from
`Language::getLangDetails(<iso>)` when the shop knows the code, as the core
handler does. Both images are required, as the form requires them: without the
"No-picture" image, a product without a photo would show a broken picture in
that language. Unknown arguments through `SomcpTool::unknownFields()`.

**Then** - both images fetched (`SomcpToolUpdateLanguage::fetch()`, through
`SomcpUpload::fetchImage()`) before anything is written; `new Language()`, the
fields, `locale`, `validateFields(false)`, `add()` with `id_shop_list` the shop
of the token (not `$only_add`, as `AddLanguageHandler` calls it: every `_lang`
table gets a copy of the default language's rows); the flag written by
`SomcpToolUpdateLanguage::storeFlag()` with the new `id_lang`, the "No-picture"
image by `storeNoPicture()` with the ISO code; the temporary files removed in
`finally`; `Tools::generateRobotsFile()`. An image the shop cannot store after
`add()` deletes the language again, on every shop, so a half-made language
never stays. Log lines `language_created` and `language_create_failed`.

**Returns** - `created: true`, `id_lang`, `flag` and `no_picture` (`{url}` or
`{upload_ref}` with `stored: true`), `language`, the row read back through
`SomcpToolListLanguages::row()`, and a `warning`:
- always: `Every text of the shop was copied from <default name> into <name>: translate them, or install the translation pack of <iso> in International > Translations.`;
- on an installation of several shops: `<name> was added to this shop only; the others do not list it.`

**Errors** - in the envelope of #169, every problem at once, under `Nothing was
created.`; an image refused when fetched: `Nothing was created. Correct these
and call again:` with `flag: <reason>`; the shop refusing: `Nothing was created.
The shop refused language <iso>: <reason> - read somcp_list_languages before
calling again`.

**Notes**
- An address that is not https is refused when the image is fetched, after the arguments are checked, as in `somcp_update_language`.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - 9.0.0 shows **Locale**; the handler and the tables are the same.

#### `somcp_update_language`
**State**: written · [issue #368](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/368)
**Nature**: `acts(EDIT, 'AdminLanguages')` · **Scope**: `mcp.catalog.write`

**Does** - changes one language of **International > Localization >
Languages**, as its **Edit** page and the **Enabled** switch of the list do:
**Name**, **ISO code**, **Language code**, **Date format**, **Date format
(full)**, **RTL language**, **Status**, **Flag** and **"No-picture" image**.
Only the fields that differ are written; a row of `somcp_list_languages` given
back whole writes nothing.

**Do not use for** - deleting a language (→
`somcp_delete_language`); translating a text (→ the update tool of that
object).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_lang` | **ID** | integer | yes | from `somcp_list_languages`; otherwise `SomcpTool::notFound()` with that tool |
| `name` | **Name** | string | no | `Validate::isGenericName()`, at most 32 characters (`SomcpTool::columnSize()`) |
| `iso_code` | **ISO code** | string | no | two letters, `Validate::isLanguageIsoCode()` and the size of `Language::$definition`, stored in lower case; another language with that code: `iso_code: <code> is already the code of <name> (<id_lang>)` (`Language::getIdByIso()`) |
| `language_code` | **Language code** | string | no | `Validate::isLanguageCode()`, at most 5 characters, stored in lower case as the help line asks |
| `date_format` | **Date format** | string | no | `Validate::isPhpDateFormat()`, at most 32 characters; the key of `somcp_list_languages` (`date_format_lite` in the table) |
| `date_format_full` | **Date format (full)** | string | no | the same checks |
| `is_rtl` | **RTL language** | boolean | no | |
| `active` | **Status** | boolean | no | `false` for the default language refused: `active: <name> is the default language of the shop - it cannot be disabled` |
| `flag` | **Flag** | object | no | a new picture, `{url}` or `{upload_ref}` through `SomcpUpload::schema()` and `argument()`, declared with `files: ['flag' => SomcpUpload::images()]`, the rules of the logo of `somcp_update_carrier`; the `download_ref` `somcp_list_languages` answers for this language (or `null` when it has none) is no change, recognised through `SomcpUpload::same($flag, 'language_flag', $id)` |
| `no_picture` | **"No-picture" image** | object | no | the picture the storefront shows, in this language, for a product, a category or a brand without one; `{url}` or `{upload_ref}`, the same rules as `flag`; the page shows no preview of it and nothing reads it back, so no `SomcpDownload` kind: given, it is always written |

`locale` and `default` are taken only when they equal the stored value,
otherwise `<field>: worked out by the shop`, the rule of
`somcp_update_currency`. Unknown arguments through
`SomcpTool::unknownFields()`. A text given empty is refused: every one of them
is required by `Language::$definition` or the form.

**Then** - the row is found through `SomcpToolListLanguages::language()`, the
query of the list (`lang` joined to `lang_shop` on the shop of the token);
when `iso_code` changes, `Language::moveToIso(<new code>)` moves the
translation, e-mail and module folders of the old code first (the core handler
passes it the language identifier instead, which leaves them under a folder
named after a number), and `locale` follows from `Language::getLangDetails()`
when the shop knows the code; a write that then fails moves the folders back;
only the fields that differ are set on `new Language($id)`; when `active` goes
to `false`, the employees whose `id_lang` it is move to the default language
first, the query of `EditLanguageHandler::updateEmployeeLanguage()`; then
`validateFields(false)` and `update()`; nothing written when nothing differs.
A flag is fetched with `SomcpUpload::fetchImage()` before anything is written,
and stored after the row as `uploadFlagImageIfChanged()` does:
`Language::deleteImage()`, then `ImageManager::resize()` into
`img/l/<id_lang>.jpg`, forced to JPEG, and the cached
`img/tmp/lang_mini_<id_lang>_<id_shop>.jpg` of every shop removed. A
"No-picture" image is fetched the same way and written after the row, with the
ISO code the language holds then (it may change in the same call), as
`AbstractLanguageHandler::copyNoPictureImage()` does: `<iso>.jpg` at its own
size into `img/p/`, `img/c/` and `img/m/`, then `<iso>-default-<type>.jpg` in
the same three folders at the size of every row of
`ImageType::getImagesTypes('products')`, forced to JPEG; the first resize that
fails stops the copy (`refused: the shop could not resize the image`).
Log lines `language_updated` and `language_update_failed`.

**Returns** - the shape of every update tool (#356): `changed` (boolean),
`id_lang`, `changes` (`field`, `old`, `new`; for `flag`, `new` is
`SomcpUpload::origin()`), `reason` when nothing differs, `flag` when one was
given and `no_picture` likewise (`SomcpUpload::origin()` with `stored: true`
or `refused: <reason>`; in `changes`, its `old` is `null`, since it cannot be
read),
and `language`, the row read back as `somcp_list_languages` gives it.

Warnings, beside the answer:
- a flag or a "No-picture" image the shop could not take while other fields were saved: `The other changes were saved, but the flag was not: <reason>.` (or `the "No-picture" image`); a picture refused with nothing else to change is an error;
- `iso_code` changed: `The translation files and e-mail templates of <old> now sit under <new>; a module or theme that ships files for <old> only will not find them.`;
- `active` set to `false` with employees moved: `<n> employees used <name> in the back office; they now use <default name>.`;
- any field changed on an installation of several shops (`SomcpToolCreateTax::otherShops()`): `also_in_other_shops: <n>` and `A language is shared by every shop: this change reaches every shop of this installation (<n + 1>).`

**Errors** - in the envelope of #169, every problem at once, under `Nothing was
changed.`

**Notes**
- The texts of the shop in that language (products, categories, pages) are not touched: this is the language itself, not a translation.
- **Locale** is not taken: PrestaShop derives it from the ISO code and 9.0.0 shows it read-only.
- The change of the ISO code itself is not run on the CI shop: it moves real folders; the refusal of a code another language holds is. Nor is the "No-picture" image written there: it replaces the real pictures of the storefront.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - 9.0.0 shows **Locale** read-only and its list has a **Locale** column; the handler, the checks and the table are the same.

#### `somcp_delete_language`
**State**: written · [issue #371](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/371)
**Nature**: `acts(DELETE, 'AdminLanguages')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one language of **International > Localization >
Languages**, as the **Delete** of its row does (and **Delete selected** for
one language). The page warns: "When you delete a language, all related
translations in the database will be deleted."

**Do not use for** - switching a language off, which keeps its texts (→
`somcp_update_language` with `active: false`); deleting a translation of one
object (→ the update tool of that object).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_lang` | **ID** | integer | yes | from `somcp_list_languages`, found through `SomcpToolListLanguages::language()`; otherwise `SomcpTool::notFound()` with that tool |

Unknown arguments through `SomcpTool::unknownFields()`.

**Refused**, every problem at once, before anything is deleted:
- the default language of any shop (`PS_LANG_DEFAULT` read per shop): `id_lang: <name> is the default language of this shop - PrestaShop never deletes it`, or `... of shop <shop name>`;
- the language of the employee of the token, the "Used language" check of the back office: `id_lang: <name> is the language you use in the back office - change it in your profile first`;
- an installation of several shops (`SomcpToolCreateTax::otherShops()`): `id_lang: a language is shared by every shop - deleting it here would remove it from the <n> other shops of this installation too`.

**Then** - `Shop::setContext(Shop::CONTEXT_ALL)`, as `DeleteLanguageHandler`
does; every employee whose `id_lang` it is moved to the default language, the
query of `EditLanguageHandler::updateEmployeeLanguage()` (the back office
leaves them on a language that no longer exists); `Language::delete()`, which
removes the rows of every `_lang` table, the translation and e-mail files and
the language images; `Tools::generateRobotsFile()`, as the handler writes
robots.txt again; the context put back in `finally`. Customers keep their
`id_lang`: the storefront falls back on the default language for them. Log
lines `language_deleted` and `language_delete_failed`.

**Returns** - `deleted: true`, `id_lang`, `name`, `iso_code`, and a `warning`:
- always: `Every text of the shop in <name> was deleted with it (products, categories, pages, e-mails); adding the language again starts from the texts of the default language.`;
- employees moved: `<n> employees used <name> in the back office; they now use <default name>.`;
- customers: `<n> customers had chosen <name>; they now see the shop in <default name>.`

**Errors** - in the envelope of #169, under `Nothing was deleted.`; the shop
refusing: `Nothing was deleted. The shop refused to delete language <id>:
<reason> - read it again with somcp_list_languages`.

**Notes**
- A deletion cannot be undone: the description offers `somcp_update_language` with `active: false` instead.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none in the handler, `Language::delete()` or the tables.

#### `somcp_install_language_pack`
**State**: written · [issue #380](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/380)
**Nature**: `acts(ADD, 'AdminTranslations')` · **Scope**: `mcp.catalog.write`

**Does** - adds a language with its translations, or updates the translations
of an installed one, from PrestaShop's translation server, as the card **Add /
Update a language** of **International > Translations** does (button **Add or
update a language**). The call can take up to two minutes.

**Do not use for** - creating a language by hand, without translations (→
`somcp_create_language`); changing its name, status, formats or flag (→
`somcp_update_language`); deleting it (→ `somcp_delete_language`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `iso_code` | **Please select the language you want to add or update** | string | yes | two letters, `Validate::isLanguageIsoCode()`, offered by `Language::getLangDetails()`; otherwise `iso_code: a language code PrestaShop offers, for example fr or de - the list of Add / Update a language`; missing: `iso_code: required` |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was changed. Correct these and call again:`,
before any download.

**Then**
- `@set_time_limit(120)` when `max_execution_time` is above 0 and below 120: the extraction, the translation of every `*_lang` table and the e-mails come after the download;
- `$before = (int) Language::getIdByIso($iso, true)`, then `Language::downloadAndInstallLanguagePack($iso, _PS_VERSION_, null, $before === 0)`, as `LanguagePackInstaller` calls it: a fresh install for a new language, an update of the translations otherwise;
- on success, the caches cleared through `SomcpToolClearCache::clear()`, as `LanguagePackImporter` clears them;
- the language read back through `SomcpToolListLanguages::language()` and `row()`.

**Returns** - `changed: true`, `added` (`true` when the language did not
exist, `false` for an update), `id_lang`, `language` (the row of
`somcp_list_languages`). Warnings, beside the answer:
- on an update: `The translations of <name> were replaced by PrestaShop's, and the texts PrestaShop ships in the database (order statuses, page titles, contacts...) were translated again in this language, as the back office does.`;
- on an installation of several shops (`SomcpToolCreateTax::otherShops()` above 0): `Languages are shared by every shop: this reaches every shop of this installation (<n + 1>).`;
- on an add, when `language.active` is true: `The language is installed: check its Status with somcp_update_language before customers see it.`

**Errors** - `SomcpTool::text(..., true)`:
- the download failed (no language created, an error starting with the core's `Language pack unavailable.`): `PrestaShop's translation server did not send the <iso_code> pack within 20 seconds. Nothing was changed. Try again later.`; the address of the pack is not repeated;
- an error after the language was created: `The language <iso_code> was created (id_lang <id>), but its translations could not all be installed: <messages>. Call again to finish, or delete it with somcp_delete_language.`;
- any other error: `The language pack could not be installed: <the core's messages, joined>. Nothing was changed.`

The folder of the shop is taken out of the core's messages.

**Notes**
- The only outgoing request is the one PrestaShop's own card sends, to its own translation server, with the core's wait (`Language::PACK_DOWNLOAD_TIMEOUT`, 20 seconds).
- Logged as `language_pack_installed` with the ISO code and `added` or `updated`, `language_pack_install_failed` with the ISO code.
- A real install reaches PrestaShop's server and rewrites translation files: the scenario only checks the refusals, made before any download.

**Differences between 8.0.1 and 9.0.0** - none in the card, the controller, `LanguagePackImporter` or `LanguagePackInstaller`; 9.0.0 tries the download three times before giving up, 8.0.1 once, each attempt waiting 20 seconds.

#### `somcp_export_translations`
**State**: written · [issue #452](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/452)
**Nature**: `acts(ADD, 'AdminTranslations')` · **Scope**: `mcp.catalog.write`

**Does** - builds the translation pack of one language as a zip of XLF files,
as the card **Export a language** of **International > Translations** does
(button **Export**): the core parts, a theme and a module, any of them
together, with the shop's own changes of the wordings over the files. The zip
is handed over as a `download_ref`, kept 15 minutes.

**Do not use for** - installing a language with PrestaShop's translations (→
`somcp_install_language_pack`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `language` | **Language** | string | yes | the ISO code of an installed language of the shop of the token, as `somcp_list_languages` answers `iso_code`; another: `SomcpTool::notFound('language', 'language', <code>, 'somcp_list_languages', 'iso_code')`; missing: `language: required` |
| `core` | **PrestaShop translations** (boxes **Back office**, **Front office**, **Email**, **Other**) | array of strings | no | any of `back_office`, `front_office`, `email`, `other`, sent to the core as `back`, `front`, `mails`, `others`; `email` adds `mails_body`, as the screen does; another value: `core[<i>]: one of back_office, front_office, email, other` |
| `theme` | **Theme translations** | string | no | the name of an installed theme other than `classic` (`somcp_get_theme`, `name`); `classic`: `theme: the wordings of classic are the core's Front office - give front_office in core`, as `ExportCataloguesType::excludeDefaultThemeFromChoices()` leaves it out; another: `SomcpTool::notFound('theme', 'theme', <name>, 'somcp_get_theme', 'name')` |
| `module` | **Installed module translations** | string | no | the technical name of an installed module, compared without case (`somcp_list_modules`, `name`); another: `SomcpTool::notFound('module', 'installed module', <name>, 'somcp_list_modules', 'name')` |

None of `core`, `theme`, `module` given, or `core: []` alone: `core, theme or
module: at least one - You must select at least one translation type to export
translations.`. Unknown arguments through `SomcpTool::unknownFields()`; every
problem in one answer, in the envelope `Nothing was exported. Correct these and
call again:`.

**Then**
- `@set_time_limit(120)` when `max_execution_time` is above 0 and below 120, as `somcp_install_language_pack` does: the back office catalogue of a language is over 10 000 wordings;
- inside `SomcpBackOffice::asBackOffice()` (the back office's kernel, shared with the Module Manager tools), `prestashop.translation.export.translation_catalogue` (`TranslationCatalogueExporter::export($selections, $locale)`), the selections built as `TranslationsController::exportCataloguesAction()` builds them, the locale read with `Language::getLocaleByIso()`;
- the zip the core answers moved at once (`SomcpDownload::keepExport()`: `rename()`, or `copy()` then `unlink()` across devices) to `var/somcp/exports/`, closed as the deposits are (`SomcpUpload::closedFolder()`), under `<YmdHis>-<16 hex>.zip`: PrestaShop 8 empties its own export folder at the next export. The files there older than 15 minutes are removed first, and the folder on uninstall (`SomcpDownload::removeExports()`).

**Returns** - `exported: true`, `language`, `locale`, `parts` (`back_office`,
`front_office`, `email`, `other`, `theme <name>`, `module <name>`, in the order
given), `file` (`{"download_ref": "translation_export.<id>.<expiry>.<signature>"}`,
kind `translation_export` of `SomcpDownload::KINDS`, whose reader is this
tool), `bytes`, `expires_at`, `then`.

**Errors** - a failure of the core (an exception of `export()`, the kernel not
booting): `The translations could not be exported: <message, 500 characters at
most>.`, nothing kept; the folder of the shop is taken out of the message.

**Notes**
- Changes nothing of the shop: it writes one file of its own, kept 15 minutes. The pack is what **Add / Update a language** of another shop imports; this tool does not import one.
- Logged as `translations_exported` or `translations_export_failed` with the language and the parts, never the file name.

**Differences between 8.0.1 and 9.0.0** - 8.0.1 empties the whole export folder before each export, 9.0.0 works in `export/<uniqid()>/` and keeps the others (left to the core, as the screen leaves it); the themes offered (none but `classic` on 8.0.1, `hummingbird` on 9.0.0).

#### `somcp_list_translations`
**State**: written · [issue #453](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/453)
**Nature**: `acts(VIEW, 'AdminTranslations')` · **Scope**: `mcp.catalog.read`

**Does** - reads the translations of a language as the card **Modify
translations** of **International > Translations** shows them (button
**Modify**): without `domain`, the domains of one type of wordings with their
counts, the tree on the left of the editor; with `domain`, its wordings, each
with the source, the translation of the files and the shop's own.

**Do not use for** - the pack of a language as a file (→
`somcp_export_translations`); installing PrestaShop's translations (→
`somcp_install_language_pack`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `language` | **Select your language** | string | yes | ISO code of an installed language, as `somcp_list_languages` answers `iso_code`, checked by `SomcpToolExportTranslations::locale()`; another: `SomcpTool::notFound('language', 'language', <code>, 'somcp_list_languages', 'iso_code')` |
| `type` | **Type of translation**, **Select the type of email content** | string | yes | `back_office`, `front_office`, `module`, `email_subject`, `email_body`, `other`, sent as `back`, `themes`, `modules`, `mails`, `mails_body`, `others`; missing: `type: required, one of ...`; another: `type: one of ...` |
| `theme` | **Select your theme** | string | no | with `front_office` only: an installed theme (`somcp_get_theme`, `name`); left out or `classic`: **Core (no theme selected)**, the core's front office; another: `SomcpTool::notFound('theme', 'theme', <name>, 'somcp_get_theme', 'name')`; with another type: `theme: only with type front_office` |
| `module` | **Select your module** | string | with `module` | an installed module compared without case (`somcp_list_modules`, `name`), through `SomcpToolExportTranslations::module()`; missing: `module: required with type module - find it with somcp_list_modules`; with another type: `module: only with type module` |
| `domain` | the tree | string | no | a `domain` this tool answers without it (`AdminCatalogFeature`, `ShopThemeCheckout`...); not in the tree: `SomcpTool::notFound('domain', 'domain', <name>, 'somcp_list_translations', 'domain')` |
| `search` | **Search translations** | string | no | words, all found without case in the source, the file or the shop's translation (`Message::contains()`); with or without `domain` |
| `missing_only` | the red count of a node | boolean | no | the domains with a wording missing, or the wordings with neither file nor shop translation; default false |
| `limit`, `offset` | | integers | no | with `domain`: `limit` 20 by default and 100 at most (`limit: a whole number from 1 to 100`), `offset` 0 at least (`offset: a whole number, 0 at least`) |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was read. Correct these and call again:`.

**Then** - inside `SomcpBackOffice::asBackOffice()`:
- the tree: `prestashop.translation.builder.translation_catalogue` (`getRawCatalogue()->buildTree()`, shaped as the editor's `TranslationsTreeBuilder` shapes it, without its API links, whose router needs a session on PrestaShop 8) on the definition of `prestashop.translation.factory.provider_definition` (`build($type, $selected)`), `themes` with no theme or `classic` read as `front`, as `listTreeAction()` does; the leaves flattened in the tree's order;
- a domain: `prestashop.translation.builder.translation_catalogue` (`getDomainCatalogue()`) with `ModuleProviderDefinition(<module>)`, else `ThemeProviderDefinition(<theme>)`, else `CoreDomainProviderDefinition(<domain>)`, as `listDomainTranslationAction()` does; `Messages` is the catalogue `messages` of the other wordings.

**Returns**
- without `domain`: `language`, `locale`, `type`, `theme`, `module`, `total_translations`, `total_missing_translations`, `domains` (each `domain`, `name`, `path`, `total_translations`, `total_missing_translations`);
- with `domain`: the same header, `domain`, `total_translations`, `total_missing_translations`, `returned`, `total_count`, `offset`, `translations` (each `source`, `file_translation`, `shop_translation`, `shown` = `shop_translation ?? file_translation ?? source`).

**Errors** - a failure of the core (`TranslationFilesNotFoundException`, the
kernel not booting): `The translations could not be read: <message, 500
characters at most>.`; the folder of the shop is taken out of the message.

**Notes** - read only. A wording keeps its placeholders (`%s`, `%1$s`,
`%name%`) as the source writes them.

**Differences between 8.0.1 and 9.0.0** - the themes offered (`classic` on
8.0.1; `classic` and `hummingbird` on 9.0.0); the code read is the same.

#### `somcp_update_translation`
**State**: written · [issue #454](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/454)
**Nature**: `acts(EDIT, 'AdminTranslations')` · **Scope**: `mcp.catalog.write`

**Does** - the changing half of the card **Modify translations** of
**International > Translations**: saves the shop's own translation of one
wording (**Save**), or removes it (**Reset**) so the translation of the files
shows again.

**Do not use for** - reading the wordings (→ `somcp_list_translations`);
installing PrestaShop's translations (→ `somcp_install_language_pack`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `language`, `type`, `theme`, `module` | as `somcp_list_translations` | | `language`, `type` | checked by `SomcpToolListTranslations::selection()`, the same rules and messages |
| `domain` | the tree | string | yes | a `domain` `somcp_list_translations` answers; missing: `domain: required, ...`; not in the tree: `SomcpTool::notFound('domain', 'domain', <name>, 'somcp_list_translations', 'domain')` |
| `source` | the source above the box | string | yes | the `source` of a wording of that domain; missing: `source: required, ...`; not in the domain: `SomcpTool::notFound('source', 'wording', <text, 100 characters>, 'somcp_list_translations', 'source')` |
| `translation` | the box | string | without `reset` | the shop's translation, 4000 characters at most; empty after `trim()`: `translation: a text - use reset to show the translation of the files again`; not the same number of placeholders as the source (`PassVsprintf`, `Translator::$regexSprintfParams`): `translation: keep the placeholders of the source (<the placeholders>)` |
| `reset` | **Reset** | boolean | no | true: the shop's translation removed; with `translation`: `reset: without translation` |
| `file_translation`, `shop_translation`, `shown` | | | no | read only: accepted when equal to the read; another value: `<field>: read only - change translation` |

Unknown arguments through `SomcpTool::unknownFields()`; the problems in the
envelope `Nothing was changed. Correct these and call again:`.

**Then** - inside `SomcpBackOffice::asBackOffice()`, the wording read as
`somcp_list_translations` reads it; `translation` equal to its
`shop_translation`, or `reset` when it has none: `changed: false`, nothing
written. Otherwise `prestashop.service.translation`: `findLanguageByLocale()`,
then `saveTranslationMessage($lang, <domain>, <source>, <translation>, <theme>)`
or `resetTranslationMessage(<id_lang>, <domain>, <source>, <theme>)`, the theme
`null` for the core's wordings and `classic`, as the editor sends them, and
`Messages` saved in the catalogue `messages`; then `Tools::clearSf2Cache()`
(removed once the answer is sent, as PrestaShop 9's clearer does) and, for
`AdminNavigationMenu`, the translator cleared for the language and the `tab`
names translated again for the shop of the token, as
`translateMultilingualContent()` does. Logger line `translation_updated`: the
language, the domain, `saved` or `reset`, never the wording.

**Returns** - `changed`, `language`, `type`, `domain`, `changes` (`field`
`translation`, `old`, `new`) when changed, and `translation`: the wording read
again as `somcp_list_translations` answers it; `warning` for
`AdminNavigationMenu` (`The back office menu is translated again for this
language.`) and in multistore (`The translations are shared by every shop.`).

**Errors** - `saveTranslationMessage()` refusing: `The translation could not
be saved. Read the wording with somcp_list_translations before calling
again.`; a failure of the core: `The translation could not be saved: <message,
500 characters at most>.`, the folder of the shop taken out.

**Notes** - one wording per call. The table `translation` has no shop: the
change reaches every shop.

The JSON scenario covers the refusals, the save, the same save again and the
two resets; the shop test reads the saved wording with
`somcp_list_translations`, gives its row back whole (`changed: false`, no
`changes`), then resets it whatever failed.

**Differences between 8.0.1 and 9.0.0** - the right the screen asks (`create`
or `update`); the cache clearer of the API (an in-process `cache:clear` on 8,
a clearing at the end of the request on 9), both replaced by
`Tools::clearSf2Cache()`.

#### `somcp_get_localization_settings`
**State**: written · [issue #382](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/382)
**Nature**: `acts(VIEW, 'AdminLocalization')` · **Scope**: `mcp.catalog.read`

**Does** - reads the settings of **International > Localization >
Localization**: the **Configuration** card (default language, country and
currency, browser detection, time zone), **Local units** and **Advanced**.
The language, country and currency a shop starts from, and the units every
product weight and carrier range is read in. Read only.

**Do not use for** - the list of languages, countries or currencies (→
`somcp_list_languages`, `somcp_list_countries`, `somcp_list_currencies`); the
units of one carrier's ranges (→ `somcp_get_carrier`, which already gives
`weight_unit` and `dimension_unit`); changing the settings (→
`somcp_update_localization_settings`). The **Import a localization pack** card
holds an action, not a setting: `somcp_import_localization_pack`.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `language` | - | string | no | ISO code of one of the shop's active languages, for the names of the default country and currency, through `SomcpTool::readLanguage()`; a code the shop does not have is refused with `SomcpTool::languageProblem()`. The shop default language when left out |

Unknown arguments through `SomcpTool::unknownFields()`, in the envelope
`Nothing was read. Correct these and call again:`.

**Returns** - `language` (the ISO code the names are in) and `localization`,
built in the public `SomcpToolGetLocalizationSettings::settings()`, which the
update reads too. Each key read with `Configuration::get(<key>, null,
$idShopGroup, $idShop)` for the shop of the token and its group:

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `default_id_lang` | **Default language** | `PS_LANG_DEFAULT` | integer, the `id_lang` of `somcp_list_languages` |
| `default_language` | - | - | `{iso_code, name}` of that language, from `Language` (computed); `null` when the setting names no language |
| `detect_language_from_browser` | **Set language from browser** | `PS_DETECT_LANG` | boolean |
| `default_id_country` | **Default country** | `PS_COUNTRY_DEFAULT` | integer, the `id_country` of `somcp_list_countries` |
| `default_country` | - | - | `{iso_code, name}`, the name in `language` (`Country::getNameById()`) (computed) |
| `detect_country_from_browser` | **Set default country from browser language** | `PS_DETECT_COUNTRY` | boolean |
| `default_id_currency` | **Default currency** | `PS_CURRENCY_DEFAULT` | integer, the `id_currency` of `somcp_list_currencies` |
| `default_currency` | - | - | `{iso_code, name}`, the name in `language` (computed) |
| `timezone` | **Time zone** | `PS_TIMEZONE` | string, for example `Europe/Paris` |
| `weight_unit`, `distance_unit`, `volume_unit`, `dimension_unit` | **Weight unit**, **Distance unit**, **Volume unit**, **Dimension unit** | `PS_WEIGHT_UNIT`, `PS_DISTANCE_UNIT`, `PS_VOLUME_UNIT`, `PS_DIMENSION_UNIT` | string, as stored |
| `language_identifier` | **Language identifier** | `PS_LOCALE_LANGUAGE` | string |
| `country_identifier` | **Country identifier** | `PS_LOCALE_COUNTRY` | string |

**Notes**
- The keys are those `LocalizationConfiguration`, `LocalUnitsConfiguration` and `AdvancedConfiguration` of `src/Adapter/Localization/` read and write, the same on both versions.
- The demo shops differ (default country, volume unit): the scenario only expects the keys.

**Differences between 8.0.1 and 9.0.0** - none in the keys or the adapters.
On screen: **Default country** has an empty `--` choice first on 9.0.0 only;
**Default currency** carries no required mark on 9.0.0; the **Time zone** list
comes from the `timezone` table, 560 rows on 8.0.1 and 559 on 9.0.0.

#### `somcp_update_localization_settings`
**State**: written · [issue #383](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/383)
**Nature**: `acts(EDIT, 'AdminLocalization')` · **Scope**: `mcp.catalog.write`

**Does** - changes the settings of **International > Localization >
Localization**, as the **Save** buttons of its **Configuration**, **Local
units** and **Advanced** cards do: the default language and country, browser
detection, the default currency, the time zone, the four units and the two
server identifiers. A new default currency is set as the core sets it
(#384): every exchange rate reset and read again from PrestaShop's feed,
fetched before anything is written.

**Do not use for** - enabling a language or a country (→
`somcp_update_language`, `somcp_update_country`); the units of one carrier
(they are the shop's); importing a localization pack (→
`somcp_import_localization_pack`).

**Parameters** - all optional; a key left out keeps its value. The read of
`somcp_get_localization_settings` given back whole writes nothing:
`default_language`, `default_country`, `default_currency` and `language` are
computed keys, ignored when given back as read and refused otherwise, as
`somcp_update_tax_options` does.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `default_id_lang` | **Default language** | integer | `SomcpTool::id()`; a language of the shop (`Language::getLanguages(false, $idShop)`), otherwise `SomcpTool::notFound('default_id_lang', 'language', <id>, 'somcp_list_languages')`; a disabled one: `default_id_lang: the language <id> is disabled - the form offers enabled languages only; enable it with somcp_update_language first` |
| `detect_language_from_browser` | **Set language from browser** | boolean | `true or false` |
| `default_id_country` | **Default country** | integer | `SomcpTool::id()`; an existing country, otherwise `notFound(..., 'country', ..., 'somcp_list_countries')`; a disabled country is accepted, as the form offers it, with the warning below |
| `detect_country_from_browser` | **Set default country from browser language** | boolean | `true or false` |
| `default_id_currency` | **Default currency** | integer | `SomcpTool::id()`; the value read is no change; otherwise a currency of the shop (`SomcpToolListCurrencies::query()`), otherwise `SomcpTool::notFound('default_id_currency', 'currency', <id>, 'somcp_list_currencies')`; a disabled one: `default_id_currency: the currency <id> is disabled - the form offers enabled currencies only; enable it with somcp_update_currency first` (the form offers the enabled currencies, `CurrencyByIdChoiceProvider`) |
| `timezone` | **Time zone** | string | a `name` of the `timezone` table, compared exactly (`Europe/Paris`); otherwise `timezone: a time zone name of the list, for example Europe/Paris` |
| `weight_unit`, `distance_unit`, `volume_unit`, `dimension_unit` | **Weight unit**, **Distance unit**, **Volume unit**, **Dimension unit** | string | trimmed, not empty (`<name>: required, never empty`), `Validate::isGenericName()` (`<name>: no <>={} in a unit`), 10 characters at most (`<name>: 10 characters at most, a unit such as kg or cm`) |
| `language_identifier` | **Language identifier** | string | two letters, stored lowercase: otherwise `language_identifier: two letters, ISO 639-1, for example en` |
| `country_identifier` | **Country identifier** | string | two letters, stored lowercase: otherwise `country_identifier: two letters, ISO 3166-1 alpha-2 in lowercase, for example us` |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was changed. Correct these and call again:`,
before anything is written.

**Then**
- `$before = SomcpToolGetLocalizationSettings::settings($idShop, $idLang)`;
- a new `default_id_currency`, before anything is written: the currencies read through `SomcpToolUpdateExchangeRates::currencies()`, then the feed fetched by `SomcpToolUpdateExchangeRates::feed()`, shared with that tool (20 seconds). `null`: `PrestaShop's exchange rate server did not answer within 20 seconds, or sent something unreadable. Nothing was changed: the default currency stays <ISO>, since changing it needs new exchange rates. Try again later.`; the new currency absent from the feed (`SomcpToolUpdateExchangeRates::listed()`, its source included): `PrestaShop's exchange rates do not include <ISO>: the rates of the other currencies cannot be worked out from it. Nothing was changed.`;
- the keys whose value differs, each saved with `Configuration::updateValue(<key>, <value>, false, $idShopGroup, $idShop)` for the shop of the token, in the order of the cards; a failed save stops the call and names what was saved before it;
- right after `PS_CURRENCY_DEFAULT`, in the shop of the token (`Somcp::actAsShop()`): the feed stored in the cache `Tools::simplexml_load_file()` reads, then the core's own `CurrencyManager::updateDefaultCurrency()` (service `prestashop.adapter.manager.currency`, or `new CurrencyManager()` where no container runs), which sets every rate to 1 and calls `Currency::refreshCurrencies()`; it finds the feed in the cache and sends no second request;
- nothing differs: `changed: false`, `reason` `The localization settings already hold every value given. Nothing was written.`, `localization` as read;
- logged as `localization_settings_updated` with the fields saved, `localization_settings_update_failed` with the failure.

**Returns** - `changed: true`, `changes` (per field its `field`, `old`,
`new`, under the names of the parameters), and `localization` read back
through `settings()`. When the default currency changed, also `rates` (one
item per currency whose rate moved, the shape of `changes` of
`somcp_update_exchange_rates`, `SomcpToolUpdateExchangeRates::rateChanges()`)
and, when not empty, `not_in_feed` (the ISO codes the feed leaves out, now at
rate 1). Warnings, in `warning`:
- the default currency changed: `The default currency is now <ISO>. Product prices were not converted: each keeps its figure, now read in <ISO>, as the back office warns. Check them with somcp_get_product.`;
- `not_in_feed` not empty: `PrestaShop's exchange rates do not include <ISO codes>: their rate is now 1; set it with somcp_update_currency.`;
- `default_id_country` set to a disabled country: `<name> is the default country but is not enabled: customers cannot pick it in an address; enable it with somcp_update_country.`;
- a unit changed: `The <field> changed from <old> to <new>: weights, sizes and carrier ranges already typed are not converted, only their label changes.`;
- on an installation of several shops: `The settings changed for this shop only, as the back office saves them in a shop context.`

**Notes**
- The core's Save of the Configuration card also calls `LanguageActivator::enable()` on the language chosen; only enabled languages are accepted, so it has nothing to do.
- The scenario checks the refusals; `dev/shop-tests/run.php` gives the read back whole (no change), sets the time zone and puts it back. The CI never reaches PrestaShop's server: no real change of the default currency is tested.
- Product prices are never converted by the core: they keep their figures, read in the new currency, which is what the warning of the field on screen says.

**Differences between 8.0.1 and 9.0.0** - none in the adapters or the
controller. The **Time zone** list has 560 names on 8.0.1, 559 on 9.0.0
(`US/Pacific-New` on 8.0.1 only): read from the table, never from a list in
the code.

#### `somcp_import_localization_pack`
**State**: written · [issue #389](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/389)
**Nature**: `acts(EDIT, 'AdminLocalization')` · **Scope**: `mcp.catalog.write` · currencies and languages: [issue #390](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/390)

**Does** - imports a country's localization pack, as the **Import** button of
the **Import a localization pack** card of **International > Localization >
Localization**: the **States**, the **Taxes** and tax rules, the
**Currencies**, the **Languages**, the **Units**, and **Change the behavior of
the price display for groups**, in one call. The pack is always the shop's own
file (`localization/<iso>.xml`): **Download pack data** is always **No**. For
currencies and languages the core makes its own two downloads: the exchange
rates of the new currencies, and the pack of each new language.

**Do not use for** - one state, tax or tax rule (→ `somcp_create_state`,
`somcp_create_tax`, `somcp_create_tax_rule`); the default language, country
and currency (→ `somcp_update_localization_settings`); one currency or one
language (→ `somcp_create_currency`, `somcp_install_language_pack`).

**Parameters**

| Name | On screen | Type | Required | Rule |
|---|---|---|---|---|
| `country` | **Localization pack you want to import** | string | yes | two letters, lowercase or uppercase, stored lowercase, checked (`/^[a-z]{2}$/` and `Validate::isLanguageIsoCode()`) before any path is built; a file `_PS_ROOT_DIR_ . '/localization/<iso>.xml'` of the shop, so `default` is refused; otherwise `country: no localization pack for <value> in this shop - the packs are named by the ISO code of their country, for example fr`. The country must exist (`Country::getByIso()`), otherwise `SomcpTool::notFound('country', 'country', <iso>, 'somcp_list_countries', 'ISO code')` |
| `content` | **Content to import** | array | yes | one or more of `states`, `taxes`, `currencies`, `languages`, `units`, `groups`, taken once each in the order of the card; empty: `content: at least one of states, taxes, currencies, languages, units, groups`; anything else: `content: <value> is not one of states, taxes, currencies, languages, units, groups`. `currencies` is imported on every connection. Where no kernel container runs (the MCP endpoint; the built-in chat has one), the core's command bus (`prestashop.core.command_bus`) that creates a currency is missing, so the tool creates them itself (see **Then**); there, when the official currencies cannot be read (`SomcpBackOffice::cldrLocaleRepository()` null): `content: the currencies PrestaShop knows cannot be read here - add them on the Add new currency page`, the sentence of `somcp_create_currency` |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was changed. Correct these and call again:`,
before anything is imported. An empty selection never reaches the core, which
would import everything and change the default language.

**Then**
- the file loaded; one that does not load: `Cannot load the localization pack of <iso>. Nothing was changed.`;
- with `currencies` or `languages`: `@set_time_limit(120)` when `max_execution_time` is above 0 and below 120, as `somcp_install_language_pack` does;
- read before, in the shop of the token (`Somcp::actAsShop()`): the identifiers of `state`, `zone`, `tax` and `tax_rules_group`, the five unit keys, `PRICE_DISPLAY_METHOD`, the **Price display method** of the groups of `PS_CUSTOMER_GROUP`, `PS_GUEST_GROUP` and `PS_UNIDENTIFIED_GROUP`, the `active` and `contains_states` of the country, the identifiers of `lang`, and the currencies through `SomcpToolUpdateExchangeRates::currencies()` (the rows of `somcp_list_currencies`);
- one content at a time, in the order of the card, since the core skips every content after one that reports an error; a hard error stops the call. The core's importer, `SomcpBackOffice::coreService('prestashop.core.localization.pack.import.importer')`, with `new LocalizationPackImportConfig($iso, $content, false)`; where no kernel container runs (the MCP endpoint is a front controller), the same steps that importer takes with **Download pack data** off: a new `LocalizationPack` and `loadLocalisationPack($xml, $content, false, $iso)` on the local file. The core's code, never a copy of it;
- with `currencies` where no command bus runs, before the core's import of the currencies: each `<currency>` of the pack whose `iso_code` `Currency::exists()` does not find is created by `SomcpToolCreateCurrency::create(<iso>, 1, true, <shop>, <repository>)`, the code of `somcp_create_currency` (the deleted row of the same code reused, the CLDR numeric code, decimals, names and symbols), then `Cache::clear()` and `PaymentModule::addCurrencyPermissions()`, what `_installCurrencies()` does in that order; the core's import then finds them all and only refreshes the rates. A currency the shop refuses is a hard error, `a currency of the pack could not be created: <iso> - <reason>`, answered with what had been created before it, the currencies by their ISO codes (`Before that, 2 currencies (EUR, GBP) had been created.`); the currencies created count in `created.currencies`;
- read after, the same, and the difference is the answer;
- `_installCurrencies()` creates each missing currency at rate 1, enabled, allowed for every payment module, then `Currency::refreshCurrencies()` asks for every rate (5 seconds); `_installLanguages()` downloads and installs each missing language with `Language::downloadAndInstallLanguagePack()`, the code of `somcp_install_language_pack`, then refreshes the names and symbols of every currency. The feed not answering (`Cannot parse feed.`) and a language pack not sent (`Language pack unavailable.`) are warnings: the rest stays imported; a currency the core's command refuses leaves a `null` in its errors, answered as `a currency of the pack could not be created`;
- other errors returned by the core: the call answers them as a failure, with what had been created or changed before them, and asks to read the states and taxes before calling again;
- logged as `localization_pack_imported` with the ISO code, the contents and the counts, `localization_pack_import_failed` otherwise.

The import is not undone by a second call, which creates nothing new and
answers `changed: false`, `reason` `The shop already holds everything this
pack brings for <contents>. Nothing was created.`

**Returns**
- `changed`;
- `country`: `id_country`, `iso_code`, `name`, and `active` / `contains_states` (`true`) when the import turned them on;
- `created`: `states` (the shape of `SomcpToolListStates::state()`), `zones` (`id_zone`, `name`), `taxes` (`id_tax`, `name`, `rate`), `tax_rules` (`id_tax_rules_group`, `name`), `currencies` (`id_currency`, `iso_code`, `name`, `conversion_rate`), `languages` (`id_lang`, `iso_code`, `name`), each only when not empty;
- `changes`: per field its `field`, `old`, `new`: `weight_unit`, `volume_unit`, `dimension_unit`, `distance_unit` (the names of `somcp_get_localization_settings`), `base_distance_unit` (`PS_BASE_DISTANCE_UNIT`), and `price_display_method` (`tax_included` / `tax_excluded`), for the setting and, with `id_group`, for each of the three groups;
- `rates`, with `currencies`: the currencies that existed and whose rate moved, the shape of `changes` of `somcp_update_exchange_rates` (`SomcpToolUpdateExchangeRates::rateChanges()`);
- `kept`: how many states, taxes and tax rules of the pack already existed and were not touched.

Warnings, in `warning`:
- the feed did not answer: `PrestaShop's exchange rate server did not answer: the new currencies are at rate 1; refresh them with somcp_update_exchange_rates.`;
- a language pack not sent: `PrestaShop's translation server did not send the <ISO codes> pack within <seconds> seconds: <ISO codes> was not added. Try again later with somcp_install_language_pack.`, the language left out of `created.languages`;
- languages or currencies created: `<names> are now offered on the storefront; disable them with somcp_update_language or somcp_update_currency if the shop does not sell in them yet.`;
- taxes or tax rules kept: `Taxes and tax rules that already had their name were kept as they are, even when the pack has another rate: check them with somcp_list_taxes.`;
- the country enabled by the import: `<name> was not enabled: the import enabled it, as the back office does.`;
- a unit changed: `The <field> changed from <old> to <new>: weights, sizes and carrier ranges already typed are not converted, only their label changes.`;
- on an installation of several shops: `The pack was imported for the whole installation: states, taxes and units are shared by every shop.`

**Notes**
- `_installStates()` creates each missing state, with a zone created when none has its name, and turns **Contains states** on; `_installTaxes()` creates each tax and tax rule whose name is missing and never changes one that has it; `_installUnits()` overwrites the units; `_installGroups()` sets the price display. The country of the pack is enabled when it is not.
- The scenario only tests refusals: an import changes the shop for good. `dev/shop-tests/run.php` imports the `units` of the pack of the default country and puts the units read before back in `finally`. The CI never reaches PrestaShop's servers: no real import of currencies or languages is tested.

**Differences between 8.0.1 and 9.0.0** - none in `LocalizationPackImporter`,
`LocalizationPack` or the 96 local files. The service is a class on 8.0.1 and
an alias on 9.0.0: the same id on both. The language download differs as
`somcp_install_language_pack` says (20 seconds on 8.0.1, up to three tries on
9.0.0).

#### `somcp_get_general_settings`
**State**: written · [issue #385](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/385)
**Nature**: `acts(VIEW, 'AdminPreferences')` · **Scope**: `mcp.catalog.read`

**Does** - reads the price rounding and the three storefront pages of
**Shop Parameters > General**, tab **General**: **Round mode**, **Round
type**, **Display suppliers**, **Display brands**, **Display best sellers**.
The rounding decides every price the shop shows and every total it invoices.
Read only.

**Do not use for** - changing them (→ `somcp_update_general_settings`); the
price of one product (→ `somcp_get_product`); the tax options (→
`somcp_get_tax_options`); the language, country, currency and units (→
`somcp_get_localization_settings`). The other fields of the tab (**Enable
SSL**, **Increase front office security**, **Allow iframes on HTML fields**,
**Use HTMLPurifier Library**, **Enable Multistore**, and **Main Shop
Activity** on 8.0.1) are neither read nor written.

**Parameters** - none. Unknown arguments through `SomcpTool::unknownFields()`,
in the envelope `Nothing was read. Correct these and call again:`.

**Returns** - one object, built in the public
`SomcpToolGetGeneralSettings::settings()`, which the update reads too, under
the names the update takes back. Each key read with `Configuration::get(<key>,
null, $idShopGroup, $idShop)` for the shop of the token and its group:

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `round_mode` | **Round mode** | `PS_PRICE_ROUND_MODE` | `half_up` (2), `half_down` (3), `half_even` (4), `half_odd` (5), `up` (0), `down` (1): `ROUND_MODES` |
| `round_mode_label` | - | - | the words of the screen for that choice, for example **Round up away from zero, when it is half way there (recommended)** (computed) |
| `round_type` | **Round type** | `PS_ROUND_TYPE` | `item` (1), `line` (2), `total` (3): `ROUND_TYPES` |
| `round_type_label` | - | - | **Round on each item**, **Round on each line** or **Round on the total** (computed) |
| `display_suppliers` | **Display suppliers** | `PS_DISPLAY_SUPPLIERS` | boolean |
| `display_brands` | **Display brands** | `PS_DISPLAY_MANUFACTURERS` | boolean |
| `display_best_sellers` | **Display best sellers** | `PS_DISPLAY_BEST_SELLERS` | boolean |

A stored number outside the lists (an old shop): the code and the label are
`null`, and a `warning` names the stored value and says to choose one with
`somcp_update_general_settings`.

**Notes**
- The keys are those `src/Adapter/Preferences/PreferencesConfiguration.php` reads and writes, the same on both versions.
- The demo shops differ on **Round type** (`item` on 8.0.1, `line` on 9.0.0): the scenario only expects the keys.

**Differences between 8.0.1 and 9.0.0** - none for these five fields: the
same keys, the same choices, the same help. The tab differs elsewhere (8.0.1
has **Enable SSL on all pages** and **Main Shop Activity**), and those fields
are not read.

#### `somcp_update_general_settings`
**State**: written · [issue #386](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/386)
**Nature**: `acts(EDIT, 'AdminPreferences')` · **Scope**: `mcp.catalog.write`

**Does** - changes the price rounding and the three storefront pages of
**Shop Parameters > General**, tab **General**, as its **Save** button does
for those fields: **Round mode**, **Round type**, **Display suppliers**,
**Display brands**, **Display best sellers**.

**Do not use for** - the tax options (→ `somcp_update_tax_options`); the
language, country, currency and units (→
`somcp_update_localization_settings`); enabling the suppliers or brands
themselves (→ `somcp_set_supplier_active`, `somcp_set_manufacturer_active`).
The other fields of the tab are never written, and are refused as unknown
fields.

**Parameters** - all optional; a key left out, or `null`, keeps its value.
The read of `somcp_get_general_settings` given back whole writes nothing:
`round_mode_label` and `round_type_label` are computed keys, ignored when
given back as read and refused otherwise (`- round_mode_label: worked out by
the shop, so it is not written here`), as `somcp_update_localization_settings`
does; the read's `warning` is ignored.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `round_mode` | **Round mode** | string | a code of `SomcpToolGetGeneralSettings::ROUND_MODES`, otherwise `round_mode: one of half_up, half_down, half_even, half_odd, up, down` |
| `round_type` | **Round type** | string | a code of `SomcpToolGetGeneralSettings::ROUND_TYPES`, otherwise `round_type: one of item, line, total` |
| `display_suppliers` | **Display suppliers** | boolean | `true or false` |
| `display_brands` | **Display brands** | boolean | `true or false` |
| `display_best_sellers` | **Display best sellers** | boolean | `true or false` |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was changed. Correct these and call again:`,
before anything is written.

**Then**
- `$before = SomcpToolGetGeneralSettings::settings($idShop)`;
- the keys whose value differs, in the order of the screen, each saved with `Configuration::updateValue(<key>, <value>, false, $idShopGroup, $idShop)` for the shop of the token: the stored number for a code, `1` or `0` for a switch, as `PreferencesConfiguration::updateConfiguration()` saves them; a failed save stops the call and names what was saved before it;
- nothing differs: `changed: false`, `reason` `The general settings already hold every value given. Nothing was written.`, `settings` as read;
- logged as `general_settings_updated` with the fields saved, `general_settings_update_failed` with the failure.

**Returns** - `changed: true`, `changes` (per field its `field`, `old`,
`new`, under the names and codes of the parameters), and `settings` read
back through `settings()`. Warnings, in `warning`:
- `round_mode` or `round_type` changed: `Prices are now rounded with <new label>: orders and invoices already made keep their totals, new carts and orders use the new rounding.` (the core reads the keys at each computation, `Tools::ps_round()` and `Cart::getOrderTotal()`);
- a display switch turned on: `The <suppliers, brands or best sellers> page is now shown on the storefront, even when its module is disabled.`;
- on an installation of several shops: `The settings changed for this shop only, as the back office saves them in a shop context.`

**Notes**
- The back office saves the whole tab at once; this tool saves only the keys whose value differs, so the fields it leaves out are never touched.
- The scenario checks the refusals; `dev/shop-tests/run.php` gives the read back whole (no change), turns **Display suppliers** over and puts it back.

**Differences between 8.0.1 and 9.0.0** - none for these five fields.

#### `somcp_get_maintenance_settings`
**State**: written · [issue #387](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/387)
**Nature**: `acts(VIEW, 'AdminMaintenance')` · **Scope**: `mcp.catalog.read`

**Does** - reads the tab **Maintenance** of **Shop Parameters > General**:
**Enable store**, **Enable store for logged-in employees** (9.0.0 only) and
**Custom maintenance text**. Whether the storefront is open to visitors.
Read only.

**Do not use for** - whether the shop answers at all (→ `somcp_ping`); the
rounding and storefront pages (→ `somcp_get_general_settings`); opening or
closing the storefront, or changing its text (→
`somcp_update_maintenance_settings`). **Maintenance IP** is neither read nor
written.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `languages` | - | array | no | the languages of **Custom maintenance text** to give, ISO codes among the shop's active languages, every one by default: the rule of #149 (`SomcpToolCreateProduct::languages()`), an unknown one refused |

Unknown arguments through `SomcpTool::unknownFields()`, in the envelope
`Nothing was read. Correct these and call again:`, with an unknown language
in the same answer.

**Returns** - one object, built in the public
`SomcpToolGetMaintenanceSettings::settings($idShop, $idLangs)`, which the
update reads too, under the names the update takes back. Each key read with
`Configuration::get(<key>, ..., $idShopGroup, $idShop)` for the shop of the
token and its group:

| Key | On screen | Configuration key | Value |
|---|---|---|---|
| `store_enabled` | **Enable store** | `PS_SHOP_ENABLE` | boolean |
| `open_for_logged_in_employees` | **Enable store for logged-in employees** | `PS_MAINTENANCE_ALLOW_ADMINS` | boolean on 9.0.0; `null` on 8.0.1, where the field does not exist (`hasEmployeeSwitch()`) |
| `maintenance_text` | **Custom maintenance text** | `PS_MAINTENANCE_TEXT` | object keyed by ISO code, each language read with its `id_lang`, the HTML as stored |
| `languages` | - | - | the ISO codes given in `maintenance_text` |

When `store_enabled` is false, a `warning`: `The storefront is closed:
visitors see the maintenance text, and only the addresses of Maintenance IP
in the back office<, and the employees logged in to the back office> see the
shop.` (the part in angle brackets on 9.0.0 when
`open_for_logged_in_employees` is true).

**Notes**
- The keys are those `src/Adapter/Shop/MaintenanceConfiguration.php` reads and writes (`CONFIGURATION_FIELDS`), each a multistore field.
- The scenario checks the shape, never a text.

**Differences between 8.0.1 and 9.0.0** - **Enable store for logged-in
employees** exists on 9.0.0 only (`PS_MAINTENANCE_ALLOW_ADMINS`, no row on
8.0.1); the two other fields are the same.

#### `somcp_update_maintenance_settings`
**State**: written · [issue #388](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/388)
**Nature**: `acts(EDIT, 'AdminMaintenance')` · **Scope**: `mcp.catalog.write`

**Does** - opens or closes the storefront and changes its maintenance
message, as the **Save** button of **Shop Parameters > General**, tab
**Maintenance**, does for **Enable store**, **Enable store for logged-in
employees** (9.0.0 only) and **Custom maintenance text**. The description
says to close or open the storefront only when the merchant asks.

**Do not use for** - the rounding and storefront pages (→
`somcp_update_general_settings`); disabling one product, category or page
(→ `somcp_set_product_active`, `somcp_set_category_active`). **Maintenance
IP** is never written, and refused as an unknown field.

**Parameters** - all optional; a key left out, or `null`, keeps its value.
The read of `somcp_get_maintenance_settings` given back whole writes
nothing; its `warning` is ignored.

| Name | On screen | Type | Rule |
|---|---|---|---|
| `store_enabled` | **Enable store** | boolean | `true or false` |
| `open_for_logged_in_employees` | **Enable store for logged-in employees** | boolean | `true or false`; on 8.0.1 any value other than `null` is refused: `open_for_logged_in_employees: not on PrestaShop <version>, which has no Enable store for logged-in employees; leave the field out` |
| `languages` | - | array | the languages of `maintenance_text` given, the rule of #149 (`SomcpToolCreateProduct::languages()`); a language left out keeps its text |
| `maintenance_text` | **Custom maintenance text** | string or object | an object keyed by ISO code, or one text when one language is filled, through `SomcpToolCreateProduct::texts()`; a text given back as stored is no change; otherwise cleaned and checked by `SomcpToolCreateProduct::html()` (`Tools::purifyHTML()` then `Validate::isCleanHtml()` with the shop's iframe setting), at most 21844 characters (`maintenance_text (<iso>): 21844 characters at most, <n> given`, the limit `FormattedTextareaType::LIMIT_TEXT_UTF8` of the screen); an empty text refused (`maintenance_text (<iso>): required, never empty`) |

Unknown arguments through `SomcpTool::unknownFields()`; every problem in one
answer, in the envelope `Nothing was changed. Correct these and call again:`,
before anything is written.

**Then**
- `$before = SomcpToolGetMaintenanceSettings::settings($idShop, $idLangs)`;
- the keys whose value differs, each saved with `Configuration::updateValue(<key>, <value>, <html>, $idShopGroup, $idShop)` for the shop of the token: `PS_SHOP_ENABLE` and `PS_MAINTENANCE_ALLOW_ADMINS` as `1` or `0`, `PS_MAINTENANCE_TEXT` as an array `[id_lang => text]` of the languages that differ with `$html = true`, as `MaintenanceConfiguration::updateConfiguration()` saves them; a failed save stops the call and names what was saved before it;
- nothing differs: `changed: false`, `reason` `The maintenance settings already hold every value given. Nothing was written.`, `settings` as read;
- logged as `maintenance_settings_updated` with the fields saved (never the text), `maintenance_settings_update_failed` with the failure.

**Returns** - `changed: true`, `changes` (per field its `field`, `old`,
`new`; for the text one item per language changed, `field`
`maintenance_text (<iso>)`), and `settings` read back through `settings()`.
Warnings, in `warning`:
- `store_enabled` turned off: `The storefront is now closed: visitors see the maintenance text. Only the addresses of Maintenance IP in the back office<, and the employees logged in to the back office,> see the shop.` (the part in angle brackets on 9.0.0 when `open_for_logged_in_employees` is true);
- `store_enabled` turned on: `The storefront is now open to every visitor.`;
- on an installation of several shops: `The settings changed for this shop only, as the back office saves them in a shop context.`

**Notes**
- The scenario checks the refusals; `dev/shop-tests/run.php` gives the read back whole (no change), changes the text of the default language and puts it back. `store_enabled` is never turned off in the CI: the shop tests read the storefront.

**Differences between 8.0.1 and 9.0.0** - **Enable store for logged-in
employees** on 9.0.0 only, see above; the rest the same.

#### `somcp_list_currencies`
**State**: written · [issue #340](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/340)
**Nature**: `acts(VIEW, 'AdminCurrencies')` · **Scope**: `mcp.catalog.read`

**Does** - lists the currencies of **International > Localization >
Currencies** with the columns of that list: **ID**, **Currency**, **Symbol**,
**ISO code**, **Exchange rate**, **Enabled**, and which one is the shop
default. The `iso_code` is what the `currency` of the other tools takes
(`somcp_create_order`, `somcp_set_order_currency`,
`somcp_create_catalog_price_rule`). Read only.

**Do not use for** - every field of one currency (→ `somcp_get_currency`);
changing the currency of an order (→ `somcp_set_order_currency`); adding,
changing, enabling or deleting a currency, or updating the exchange rates
(later tools).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_currency` | **ID** filter | integer | no | one currency, exact, read with `SomcpTool::id()`; an identifier the shop does not have gives an empty list, as the screen and `somcp_list_languages` do |
| `name` | **Currency** filter | string | no | part of the name in `language`, case-insensitive |
| `iso_code` | **ISO code** filter | string | no | exact, case-insensitive (`eur`) |
| `active` | **Enabled** filter | boolean | no | `true` enabled only, `false` disabled only; left out, both (**All**) |
| `language` | - | string | no | ISO code of one of the shop's active languages, for the names and symbols; the shop default language when left out, as `somcp_list_taxes` |

No **Symbol** filter and no paging: a shop holds a handful of currencies, and
the answer gives every symbol.

**Returns** - `currencies`, sorted by `id_currency` as the list: `id_currency`
(**ID**), `name` (**Currency**, in `language`), `symbol` (**Symbol**, in
`language`), `iso_code` (**ISO code**), `conversion_rate` (**Exchange rate**, a
number, the rate of the shop of the token from `currency_shop`, against the
default currency), `active` (**Enabled**), `default` (`true` for the
`PS_CURRENCY_DEFAULT` of the shop, whose rate is 1). Plus `count` and
`language`. Only an enabled currency is accepted by the `currency` of the
other tools; a disabled one is listed so the assistant can tell the merchant
why.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once: an unknown argument (`SomcpTool::unknownFields()`), an
`id_currency` that is not an identifier (`SomcpTool::notAnIdentifier()`), an
`active` that is not a boolean (`active: true or false`, the line of
`somcp_list_taxes`), a language the shop does not have
(`SomcpTool::languageProblem()`, as `somcp_list_taxes`). An empty list is
`currencies: []`, `count: 0`.

**Notes**
- Read through a query on `currency` (`deleted = 0`) joined to `currency_shop` on the shop of the token and to `currency_lang` in the language asked, rather than `Currency::getCurrencies()`, which has no name or ISO code filter; the default read as `Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)`. The query and the building of a row are shared with `somcp_get_currency` (`SomcpToolListCurrencies::currency()` and `row()`).
- The **Exchange rate** block below the list (**Live exchange rates**, **Update exchange rates**) holds actions, not part of this list: **Update exchange rates** is `somcp_update_exchange_rates`.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - none in the list, its columns, its filters, its bulk actions, its labels or the tables (`currency`, `currency_lang`, `currency_shop`); the demo data differs (US Dollar and Euro on 8.0.1, US Dollar alone on 9.0.0).

#### `somcp_get_currency`
**State**: written · [issue #363](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/363)
**Nature**: `acts(VIEW, 'AdminCurrencies')` · **Scope**: `mcp.catalog.read`

**Does** - reads one currency of **International > Localization >
Currencies** as its **Edit** page shows it: the fields of
`somcp_list_currencies`, plus **Decimals**, the numeric ISO code, whether it
is an alternative currency, the format of the language (**Customize symbol
and format**) and whether its names or symbols differ from the CLDR ones. It
is the read before the write: `somcp_update_currency` takes back what this
one gives. Read only.

**Do not use for** - many currencies (→ `somcp_list_currencies`); the
currency of a country (→ `somcp_get_country`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_currency` | - | integer | yes | the currency, from `somcp_list_currencies`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_currency')`; not a currency of the shop (`currency_shop`, `deleted = 0`, as `somcp_list_currencies` filters it): `SomcpTool::notFound('id_currency', 'currency', $id, 'somcp_list_currencies')` |
| `language` | - | string | no | ISO code of one of the shop's active languages, for **Currency name**, **Symbol** and the format (`SomcpTool::readLanguage()`, refused with `SomcpTool::languageProblem()`); the shop default language when left out |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - `language`, then the keys of the row of `somcp_list_currencies`
(`id_currency`, `name`, `symbol`, `iso_code`, `conversion_rate`, `active`,
`default`), built by `SomcpToolListCurrencies::row()` and read through
`SomcpToolListCurrencies::currency()`, the query of the list; then:
- `precision` (**Decimals**): an integer;
- `numeric_iso_code`: a string, or `null` when empty;
- `alternative`: boolean, `currency.unofficial` (**Create an alternative currency**, the words of the add page);
- `pattern`: the stored `currency_lang.pattern` of that language, or `null` when empty (PrestaShop then formats with the CLDR pattern of the language);
- `modified`: boolean, `currency.modified` (the names or symbols differ from the CLDR ones, which **Restore default settings** brings back).

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once.

**Notes**
- The back-office address of the page is never part of the answer.
- A read: no log line, nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - none in the page, the fields or the tables (page title **Currencies** on 8.0.1, **Editing currency <name>** on 9.0.0); the demo data differs (US Dollar and Euro on 8.0.1, US Dollar alone on 9.0.0).

#### `somcp_create_currency`
**State**: written · [issue #367](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/367)
**Nature**: `acts(ADD, 'AdminCurrencies')` · **Scope**: `mcp.catalog.write`

**Does** - adds one currency to **International > Localization >
Currencies**, as **Add new currency** does: with **Select a currency**, one of
the official currencies PrestaShop knows (CLDR), its names and symbols in
every language filled by the shop when not given; with **Create an alternative
currency** (`alternative`, #373), a currency PrestaShop does not know (a local
or loyalty currency), with the ISO code, names and symbols the merchant gives.

**Do not use for** - a currency the shop already has (→
`somcp_update_currency`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `alternative` | **Create an alternative currency** | boolean | no | `true`: the ISO code must be three letters CLDR does not know, otherwise `iso_code: <code> is an official currency - leave alternative out to add it`; `false` or left out: an official currency; not a boolean: `alternative: true or false` |
| `iso_code` | **Select a currency** | string | yes | trimmed, upper-cased, three letters; a currency of CLDR (`getCurrency()` of the CLDR locale of the shop default language not null); otherwise `iso_code: <code> is not an official currency PrestaShop knows (ISO 4217, for example USD or EUR)`; already a currency not deleted: `iso_code: <code> is already currency <id> of this shop - change it with somcp_update_currency` |
| `conversion_rate` | **Exchange rate** | number | yes | above 0, 6 decimals kept; otherwise `conversion_rate: a number above 0, the value of 1 <default currency> in this currency` |
| `precision` | **Decimals** | integer | no | 0 or more, otherwise `precision: a whole number of 0 or more`; the CLDR decimals of the currency when left out, `2` (the value of the form) for an alternative one |
| `active` | **Status** | boolean | no | `true` when left out, as the page; not a boolean: `active: true or false` |
| `name`, `symbol` | **Currency name**, **Symbol** | string or object | no | keyed by ISO code with `languages`, by `SomcpToolCreateProduct::languages()` and `texts()` (#149); at most 255 characters, `Validate::isGenericName()`; empty or left out, the CLDR text |
| `languages` | - | array | no | the languages `name` and `symbol` fill, every active language when left out |

Unknown arguments through `SomcpTool::unknownFields()`.

With `alternative: true`, as `AddUnofficialCurrencyHandler::handle()`: the
ISO code checked against CLDR (`assertCurrencyIsNotInReference()`) and against
the currencies not deleted, with the lines above; `unofficial = 1`,
`numeric_iso_code` `null`; an empty name or symbol takes the ISO code, as
`refreshLocalizedCurrencyData()` does, with a warning `<field> left empty in
<languages>: the ISO code <code> is shown instead.`. When CLDR cannot be read:
`iso_code: the currencies PrestaShop knows cannot be read here, so <code>
cannot be checked - add it on the Add new currency page`.

**Then** - the CLDR repository through `SomcpBackOffice::cldrLocaleRepository()`:
the kernel container (the 9.0.0 identifier, then the 8.0.1 one), or the light
container every front page builds (`ContainerBuilder::getContainer('front')`,
as `FrontController::buildContainer()`); when none answers: `Nothing was
created. The currencies PrestaShop knows cannot be read here - add it on the
Add new currency page`. The deleted `Currency` of the same ISO code, or a new
one, set as `AbstractCurrencyHandler::addEntity()` does: `iso_code`, `active`,
`deleted = false`, `unofficial` (`alternative`), `conversion_rate`,
`numeric_iso_code` and `precision` from CLDR (none and `2` for an alternative
currency), `id_shop_list` the shop of the token so
`currency_shop` gets its row and its rate; `refreshLocalizedCurrencyData()`
with every language, `validateFields()` and `validateFieldsLang()`, then
`save(true, true)`.

**Returns** - `created: true`, `id_currency`, `restored: true` when the
deleted row of the same ISO code was reused (its orders stay tied to it), and
`currency`, the answer of `somcp_get_currency` read back (`alternative: true`
for an alternative currency). A `warning` when:
- a deleted row was reused: `<ISO code> was deleted before: the same currency is back, without the payment modules it had in Payment > Preferences (somcp_update_payment_restrictions).`;
- no payment module accepts it (`module_currency`): `Payment > Preferences (somcp_update_payment_restrictions) decides which payment modules accept <ISO code>; no module accepts it yet.`

**Errors** - in the envelope `Nothing was created. Correct these and call
again:`, every problem at once; the shop refusing the save: `Nothing was
created. The shop refused currency <ISO code>: <reason> - read
somcp_list_currencies before calling again`.

**Notes**
- Logged as `currency_created`, `currency_create_failed` when the shop refuses.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - the identifier of the CLDR service; the handler and the tables are the same.

#### `somcp_update_currency`
**State**: written · [issue #364](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/364)
**Nature**: `acts(EDIT, 'AdminCurrencies')` · **Scope**: `mcp.catalog.write`

**Does** - changes one currency of **International > Localization >
Currencies**, as its **Edit** page does for **Exchange rate**, **Decimals**,
**Status**, **Currency name**, **Symbol**, **Customize symbol and format** and
**Restore default settings** (#365), or as the **Enabled** switch of the list
does. Only the fields given and different from the stored currency are
written.

**Do not use for** - deleting a currency (→ `somcp_delete_currency`); the currency of a country
(→ `somcp_update_country`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_currency` | - | integer | yes | the currency, from `somcp_list_currencies`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_currency')`; not a currency of the shop: `SomcpTool::notFound('id_currency', 'currency', $id, 'somcp_list_currencies')`, found through `SomcpToolGetCurrency::read()` and `SomcpToolListCurrencies::currency()` |
| `conversion_rate` | **Exchange rate** | number | no | above 0, 6 decimals kept (`currency_shop.conversion_rate`, decimal(13,6)); otherwise `conversion_rate: a number above 0, the value of 1 <default currency> in this currency`; a different rate for the default currency of the shop: `conversion_rate: <ISO code> is the default currency of this shop - every rate is relative to it, its rate stays 1` |
| `precision` | **Decimals** | integer | no | 0 or more; otherwise `precision: a whole number of 0 or more` |
| `active` | **Status** | boolean | no | not a boolean: `active: true or false`; `false` for the default currency of any shop (`PS_CURRENCY_DEFAULT` read per shop): `active: <name> is the default currency for shop <shop name>, and therefore cannot be disabled`, one line per shop |
| `name` | **Currency name** | string or object | no | an object keyed by ISO code of language, or one text when one language is filled (`SomcpToolCreateProduct::languages()` and `texts()`, as `somcp_update_country` takes its name); a language left out keeps its name; `""` takes the CLDR name back; checked by `SomcpToolCreateCurrency::checkedText()`, as the add: `name (<iso>): <n> characters at most, <m> given`, `name (<iso>): a character PrestaShop refuses in a currency name (< > = { })` |
| `symbol` | **Symbol** | string or object | no | the same shape and checks; `""` takes the CLDR symbol back |
| `format` | **Customize symbol and format** | string or object | no | the position of the symbol, one of the four transformations of the core's `PatternTransformer` (`leftWithSpace`, `leftWithoutSpace`, `rightWithSpace`, `rightWithoutSpace`), or `default` for the CLDR format (an empty `pattern`); one word for every language filled, or an object keyed by ISO code; otherwise `format: leftWithSpace, leftWithoutSpace, rightWithSpace, rightWithoutSpace or default`. A raw pattern is never taken: the storefront prints it in every price |
| `restore_defaults` | **Restore default settings** | boolean | no | `true`: every name, symbol and pattern emptied, then filled again from CLDR; beside `name`, `symbol` or `format`: `restore_defaults: alone, or the texts given beside it would be lost` |
| `languages` | - | array of strings | no | the ISO codes of the languages `name`, `symbol` and `format` change; every active language when left out |

The texts are set on the `Currency`, each pattern built as the core's
`AbstractCurrencyHandler::applyPatternTransformations()` builds it (the
currency pattern of the language's CLDR locale, `SomcpToolCreateCurrency::cldrLocale()`,
moved by `PatternTransformer::transform()`), then
`refreshLocalizedCurrencyData()` runs with every language, as the **Edit**
page saves: an empty name or symbol takes the CLDR one again and `modified`
is worked out. `validateFieldsLang()` is the last check. The CLDR repository
comes from `SomcpBackOffice::cldrLocaleRepository()`; when it is null and a
name, symbol, format or `restore_defaults` is given: `<field>: the formats of
the shop cannot be read here - change it on the Edit page of the currency`.

A row of `somcp_get_currency` given back whole writes nothing: its `name` and
`symbol`, one text, are no change when they are the stored text of a language
(`SomcpToolCreateProduct::givenBackAsRead()`); `language`, `iso_code`,
`default`, `numeric_iso_code`, `alternative`, `pattern` and `modified` are
taken only when they equal the stored value, read in the `language` given;
otherwise `<field>: worked out by the shop`, or for `pattern` `pattern: worked
out by the shop - change it with format`. At least one of `conversion_rate`,
`precision`, `active`, `name`, `symbol`, `format` and `restore_defaults`,
otherwise `conversion_rate, precision, active, name, symbol, format or
restore_defaults: give at least one field to change`. Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - the shape of every update tool (#356): `changed: true`,
`id_currency` and `changes` (`field`, `old`, `new`; a name, symbol or pattern
with its language, `name (fr)`, compared after the CLDR texts are filled
again); nothing differed,
`changed: false`, `id_currency` and `reason` (`The currency already holds
every value given. Nothing was written.`). In both cases `currency`, the
answer of `somcp_get_currency` read back. When something was written, a
`warning` when:
- `conversion_rate` changed: `Prices in <ISO code> are now converted at <rate>; orders already placed keep the rate they were placed at.`;
- `active` set to `false` while payment modules accept the currency in the shop (`module_currency`): `<n> payment modules accept <ISO code> in Payment > Preferences (somcp_update_payment_restrictions); they keep that setting but the currency is no longer offered.`;
- `precision` or `active` changed on an installation of several shops (`SomcpToolCreateTax::otherShops()`): `also_in_other_shops: <n>` and `Decimals and Status are shared by every shop: this change reaches every shop of this installation (<n + 1>).`; the exchange rate stays the one of this shop;
- a name or symbol given empty for an alternative currency, which CLDR does not know: `<ISO code> is an alternative currency: an empty <field> takes its ISO code.`

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once.

**Notes**
- Written through `new Currency($id, null, $idShop)` with `id_shop_list` the shop of the token, so the rate goes to its `currency_shop` row only; the names, symbols and formats are put back in every language before `update()`, which the constructor had turned into the string of one language.
- Replayable: the same call twice writes once, the second answers `changed: false`.
- `iso_code` is read-only on the **Edit** page of both versions: never written here.
- Logged as `currency_updated` with the fields changed, `currency_update_failed` when the shop refuses.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none in the handlers, the checks or the tables.

#### `somcp_delete_currency`
**State**: written · [issue #366](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/366)
**Nature**: `acts(DELETE, 'AdminCurrencies')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one currency of **International > Localization >
Currencies**, as the **Delete** of its row does (and **Delete selected** for
one currency). PrestaShop keeps the row, flagged deleted, for the orders
placed in it.

**Do not use for** - switching a currency off (→ `somcp_update_currency`,
`active`); many currencies at once (one call per currency).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_currency` | - | integer | yes | the currency to delete, from `somcp_list_currencies`; read with `SomcpTool::id()`; not one: `SomcpTool::notAnIdentifier('id_currency')`; unknown: `SomcpTool::notFound('id_currency', 'currency', $id, 'somcp_list_currencies')`, through `SomcpToolGetCurrency::read()` |

Unknown arguments through `SomcpTool::unknownFields()`. Refusals, all at once:
- the default currency of the shop of the token: `id_currency: <ISO code> is the default currency of this shop - PrestaShop never deletes it`;
- the default currency of another shop (`PS_CURRENCY_DEFAULT` read for each shop): `id_currency: <name> is the default currency for shop <shop name>, and therefore cannot be removed`;
- a country uses it as its **Default currency** (`country.id_currency`), which the back office does not check: `id_currency: <ISO code> is the default currency of <n> countries (<iso>, ...) - set them to the default store currency first with somcp_update_country (id_currency 0)`, the first ten named;
- the installation has more than one shop (`SomcpToolCreateTax::otherShops()`): `id_currency: a currency is shared by every shop - deleting it here would remove it from the <n> other shops of this installation too`, the rule of `somcp_delete_zone` and `somcp_delete_state`.

**Returns** - `deleted: true`, `id_currency`, `warning` and `currency`, the
answer of `somcp_get_currency` read before the deletion. The warning:
- payment restrictions removed (`module_currency`, counted before): `<n> payment modules no longer accept <ISO code> in Payment > Preferences (somcp_update_payment_restrictions); adding the currency again does not bring that setting back.`;
- always: `Orders placed in <ISO code> keep it; PrestaShop keeps the currency hidden for them, and adding <ISO code> again brings the same currency back.`

**Errors** - in the envelope `Nothing was deleted. Correct these and call
again:`, every problem at once; the shop refusing `Currency::delete()`:
`Nothing was deleted. The shop refused to delete currency <id> - read it again
with somcp_list_currencies`.

**Notes**
- Deleted through `Currency::delete()`: the `module_currency` rows of the currency go, then `softDelete()` flags the row `deleted = 1`; it leaves every list.
- Logged as `currency_deleted`, `currency_delete_failed` when the shop refuses.
- The back-office address of the page is never part of the answer.

**Differences between 8.0.1 and 9.0.0** - none in the handler, `Currency::delete()` or the tables.

#### `somcp_update_exchange_rates`
**State**: written · [issue #378](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/378)
**Nature**: `acts(EDIT, 'AdminCurrencies')` · **Scope**: `mcp.catalog.write`

**Does** - updates the exchange rates of every currency of the shop from
PrestaShop's exchange rate server, as the **Update** button of **Update
exchange rates**, in the **Exchange rate** block of **International >
Localization > Currencies**. It waits up to 20 seconds for that server.

**Do not use for** - setting one rate by hand (→ `somcp_update_currency`);
reading the rates (→ `somcp_list_currencies`).

**Parameters** - none. `{}`; any argument refused through
`SomcpTool::unknownFields()`, in the envelope `Nothing was changed. Correct
these and call again:`.

**Then**
- the currencies of the shop read before, through `SomcpToolListCurrencies::query()`, `row()` and `defaultCurrency()`;
- no currency other than the default one: `changed: false`, `reason` `<ISO code> is the only currency of this shop: its rate is always 1. Nothing was written.`, without calling the server;
- otherwise the feed fetched by the public `feed()`, shared with `somcp_update_localization_settings`: `@set_time_limit(40)` when `max_execution_time` is above 0 and below 40, `_PS_CURRENCY_FEED_URL_` fetched with `Tools::file_get_contents(..., 20)` (constant `TIMEOUT`), parsed (`null` when empty or not XML); then stored in the cache `Tools::simplexml_load_file()` reads, then `Currency::refreshCurrencies()` in the shop of the token (`Somcp::actAsShop()`): it finds the feed in the cache and sends no second request;
- the currencies read again and compared row by row (`currencies()` and `rateChanges()`, public, with `listed()`, the ISO codes of the feed, for `somcp_update_localization_settings`).

**Returns** - the shape of every update tool:
- `changed` (boolean);
- `changes`, when a rate moved: one item per currency, `id_currency`, `iso_code`, `field` `conversion_rate`, `old`, `new`;
- `reason` when nothing moved: `The exchange rates of PrestaShop give the rates the shop already holds. Nothing was written.`;
- `not_in_feed`: the ISO codes of the non-default currencies the feed does not list, with the warning `PrestaShop's exchange rates do not include <ISO codes>: their rate was left unchanged; set it with somcp_update_currency.`;
- `currencies`: the rows of `somcp_list_currencies` read back, the default one included.

Warnings, when a rate moved: `Prices shown in these currencies change now;
orders already placed keep their rate.`, and on an installation of several
shops (`SomcpToolCreateTax::otherShops()` above 0) `The exchange rates changed
for this shop only; the other shops keep theirs.`

**Errors** - `SomcpTool::text(..., true)`:
- feed empty or not XML: `PrestaShop's exchange rate server did not answer within 20 seconds, or sent something unreadable. Nothing was changed. Try again later, or set a rate with somcp_update_currency.`;
- `Currency::refreshCurrencies()` returns an error: `The exchange rates could not be updated: <its message>. Nothing was changed.`

**Notes**
- The only outgoing request is the one PrestaShop's own button sends, to its own server; the core's 5-second wait is replaced by 20 seconds.
- A currency absent from the feed keeps its rate: the core skips it without a word, so the tool names it.
- Logged as `exchange_rates_updated` with the count of rates changed, `exchange_rates_update_failed` with the reason.
- The rates of the feed change every day: the scenario only checks the refusal of an argument, and the CI never reaches PrestaShop's server.

**Differences between 8.0.1 and 9.0.0** - none in the page, `Currency::refreshCurrencies()` or `Tools::file_get_contents()`; the demo data differs (US Dollar and Euro on 8.0.1, US Dollar alone on 9.0.0, where the tool answers `changed: false` without calling the server).

#### `somcp_create_tax`
**State**: written · [issue #308](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/308)
**Nature**: `acts(ADD, 'AdminTaxes')` · **Scope**: `mcp.catalog.write`

**Does** - creates one tax of **International > Taxes > Taxes**, as **Add new
tax** then **Save**: a **Name** per language, a **Rate** and **Enable**. A tax
rule charges a tax per country (`somcp_add_country_rule`, `id_tax`): this is
how a rate the install did not provide is made.

**Do not use for** - the rate a country charges in a rule (→ a tax rule row,
`somcp_add_country_rule`, which takes this `id_tax`); a product's tax
(→ `somcp_update_product`, which takes a tax rule, never a tax).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `languages` | | array of strings | no | ISO codes of the languages the `name` is given in, through `SomcpToolCreateProduct::languages()`; every active language by default, a language left out taking the default one's text (`completeTexts()`) |
| `name` | **Name** | object by ISO code | yes | one text per language being filled (a plain string when one is), each trimmed, at most the size of `tax_lang.name` (`SomcpTool::columnSize()`, 32), checked with `validateField('name', ...)` on a `Tax`: `name (fr): 32 characters at most, 40 given`, `name (fr): a character PrestaShop refuses in a tax name (< > = { }) - ...` |
| `rate` | **Rate** | number | yes | a percentage from 0 to 100 with at most three decimals, `rate: a number from 0 to 100, at most three decimals, for example 20 or 5.5`; stored as given (`5.5` is `5.500`) |
| `active` | **Enable** | boolean | no | left out, `false`, the form's default; not a boolean: `active: true or false` |

Decision (**Rate**): 0 to 100 rather than the form's numeric-in-6-characters, which takes `-5` and `999999`; a rate out of range would charge every order of the countries the tax is given to. The three decimals are the column's: a fourth is refused rather than stored rounded.

Unknown arguments through `SomcpTool::unknownFields()`, with `id_tax` named as worked out by the shop, so a row of `somcp_list_taxes` can be given back.

**Returns** - `created: true`, `id_tax`, and `tax`, the row of `somcp_list_taxes` for that tax in the shop default language (`id_tax`, `name`, `rate`, `active`), read back through the shared `SomcpToolListTaxes::tax()`. With `active: false` a `warning` tells the merchant to enable the tax before giving it to a rule; on a multistore the answer gives `also_in_other_shops`, the number of other shops the tax now shows in, with a `warning`, as the tax rule tools do.

**Errors** - in the envelope of #169, under `Nothing was created.`, every problem at once.

**Notes**
- Not replayable: the same call twice creates two taxes, as the form does; the description says to read `somcp_list_taxes` first.
- A tax is tied to no shop (no `tax_shop` table): the same tax for every shop of a multistore.
- The same fields, checks and tables on 8.0.1 and 9.0.0; `Validate::isGenericName()` refuses `< > = { }` on both.
- Changing a tax is `somcp_update_tax`, deleting it `somcp_delete_tax`.

#### `somcp_update_tax`
**State**: written · [issue #309](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/309)
**Nature**: `acts(EDIT, 'AdminTaxes')` · **Scope**: `mcp.catalog.write`

**Does** - changes one tax of **International > Taxes > Taxes**, as its **Edit**
then **Save** or the **Enabled** switch of its row: **Name**, **Rate**,
**Enable**. Only the fields given change. Every field is checked by the
functions of `somcp_create_tax`, and the answer is the same shared row.

**Do not use for** - the tax one country charges in a rule
(→ `somcp_update_country_rule`, `id_tax`); deleting a tax
(→ `somcp_delete_tax`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax` | the tax edited | integer | yes | from `somcp_list_taxes`; `SomcpTool::notAnIdentifier('id_tax')`, and `SomcpTool::notFound()` for a tax missing or flagged deleted |
| `languages` | | array of strings | no | as `somcp_create_tax`: the languages the `name` given covers |
| `name` | **Name** | object by ISO code | no | the check of `somcp_create_tax`, for the languages given only; a language left out keeps its name; given, never empty. The `name` of a row of `somcp_list_taxes`, one text, is taken back as it is: when it is the stored name of one of the languages, it is no change, even on a shop with several languages |
| `rate` | **Rate** | number | no | the check of `somcp_create_tax` (0 to 100, three decimals) |
| `active` | **Enable** | boolean | no | `true` or `false` |

Left out: unchanged. Unknown arguments through `SomcpTool::unknownFields()`; a row of `somcp_list_taxes` can be given back, nothing in it being worked out but `id_tax`, which this tool takes.

**Refused, nothing written** - on PrestaShop 8 only, `active: false` for an enabled tax that tax rule rows charge, since the core would delete those rows: `active: on PrestaShop 8, disabling this tax deletes its 2 row(s) in the tax rules 1, 9 - change their tax with somcp_update_country_rule first, or keep the tax enabled`, counted through `SomcpToolUpdateTax::charging()`. On 9.0.0 the rows stay and the change goes through.

Decision (PrestaShop 8, disabling): refuse rather than follow the back office, which removes the rows from every rule without a word and leaves those countries untaxed with nothing to undo it. Detected with `version_compare(_PS_VERSION_, '9.0.0', '<')`.

**Returns** - `changed: true`, `id_tax` (the new tax for a used one); for a used tax `replaced_id_tax` and a `warning`; `changes` (one entry per field changed: `field`, `old`, `new`); then `tax`, the shared row. Nothing differs: `changed: false`, `id_tax` and `The tax already holds every value given. Nothing was written.`. On a multistore, `also_in_other_shops` gives the number of other shops the change shows in, with a `warning`, as the tax rule tools do.

**Errors** - in the envelope of #169, under `Nothing was changed.`, every problem at once. On a used tax, the rows still on the old tax are counted after the save, whatever the core answered (`SomcpToolUpdateTax::charging()`): a row left there is an error that says what the shop now holds, `The tax was copied to a new identifier, <new>, but <n> row(s) of the tax rules <ids> still charge the old one, <old>, which is now hidden - give them the new tax with somcp_update_country_rule.`, logged as `tax_update_failed` with both identifiers.

**Notes**
- Replayable: the same call twice changes nothing the second time.
- A tax is tied to no shop: the change shows in every shop of a multistore (`also_in_other_shops`, counted by `SomcpToolCreateTax::otherShops()`).
- On 8.0.1 a used tax turned off goes through the core's copy path, which does not delete rows; the refusal above applies only to a tax no order carries.

#### `somcp_delete_tax`
**State**: written · [issue #310](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/310)
**Nature**: `acts(DELETE, 'AdminTaxes')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one tax of **International > Taxes > Taxes**, as the
**Delete** of its row. Irreversible.

**Do not use for** - a tax that should stop being charged in one country
(→ `somcp_delete_country_rule`); turning a tax off (→ `somcp_update_tax`,
`active`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_tax` | the row deleted | integer | yes | from `somcp_list_taxes`, the check of `somcp_update_tax` (`notAnIdentifier()`, `notFound()` for a tax missing or flagged deleted) |

Unknown arguments through `SomcpTool::unknownFields()`, with `name`, `rate` and `active` named as worked out by the shop, so a row of `somcp_list_taxes` given whole is understood.

**Refused, nothing written** - a tax that tax rule rows charge: `id_tax: charged by 2 row(s) of the tax rules 1, 9 - give them another tax with somcp_update_country_rule, or delete them with somcp_delete_country_rule first, or those countries would be charged nothing`, counted through `SomcpToolUpdateTax::charging()`.

Decision (rows): refuse rather than delete them with the tax as the back office does, which removes the rows from every rule at once and leaves those countries untaxed with nothing to read back. Refused, the assistant moves or deletes the rows one by one with the country row tools, each of which says what it changes.

**Returns** - `deleted: true`, `id_tax`, `tax` (the row as it was: `name`, `rate`, `active`), and `kept_for_orders`: `true` with a `warning` when the tax was only flagged (an order carries it), `false` when it was really removed. On a multistore, `also_in_other_shops` gives the number of other shops the tax is gone from, with a `warning`.

**Errors** - in the envelope of #169, under `Nothing was deleted.`, every problem at once.

**Notes**
- Not replayable: the second call finds no tax (`notFound()`).
- A tax is tied to no shop: gone for every shop of a multistore.
- The same `Tax::delete()`, handlers and tables on 8.0.1 and 9.0.0.

#### `somcp_get_tax_options`
**State**: written · [issue #311](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/311)
**Nature**: `acts(VIEW, 'AdminTaxes')` · **Scope**: `mcp.catalog.read`

**Does** - reads the **Tax options** of **International > Taxes > Taxes**, the
form below the list of taxes: **Enable tax**, **Display tax in the shopping
cart**, **Based on**, **Use ecotax** and **Ecotax**. These settings decide
whether the rates of the tax rules are charged at all, on which address and
with which ecotax. Read only.

**Do not use for** - the taxes and their rates (→ `somcp_list_taxes`); the
rate a country charges (→ `somcp_get_tax_rule`); the ecotax of one product
(→ `somcp_get_product`, `ecotax_tax_excl`).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `language` | | string | no | ISO code, through `SomcpTool::readLanguage()` and listed with `SomcpTool::languageProblem()`; the shop default when left out. A tax rule name is not translated, but the answer gives `language` as the other tax tools do |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - one object, under the names an update takes back (#307), read
by the shared `SomcpToolGetTaxOptions::options()`:
- `taxes_enabled`: **Enable tax** (`PS_TAX`), the name `somcp_list_taxes`, `somcp_list_tax_rules` and `somcp_get_tax_rule` already give it;
- `display_tax_in_cart`: **Display tax in the shopping cart** (`PS_TAX_DISPLAY`);
- `based_on`: **Based on** (`PS_TAX_ADDRESS_TYPE`), `"invoice_address"` or `"delivery_address"`; a value that is neither (an old shop) is given as stored, with a `warning`;
- `use_ecotax`: **Use ecotax** (`PS_USE_ECOTAX`);
- `ecotax_id_tax_rules_group`: **Ecotax** (`PS_ECOTAX_TAX_RULES_GROUP_ID`), `0` for **No tax**; the `id_tax_rules_group` of `somcp_list_tax_rules`;
- `ecotax_tax_rule`: the name of that rule, read through `SomcpToolGetTaxRule::existingRule()`, `null` for 0 or for a rule deleted since (then a `warning`: choose another with `somcp_update_tax_options`);
- `ecotax_rate`: the rate the shop charges on an ecotax, `SomcpCatalog::ecotaxRate()`, the number the product tools use for `ecotax_tax_incl`;
- `language`.

Each key is read with `Configuration::get($key, null, $idShopGroup, $idShop)`,
the shop and group of the token, as `somcp_get_seo_settings` reads.

**Errors** - in the envelope `Nothing was read. Correct these and call again:`,
every problem at once (`SomcpTool::refused('read', $problems)`): an unknown
argument, a language the shop does not have.

**Notes**
- When **Enable tax** is off, a `warning` says so: no rate of any tax rule is charged, and the cart shows no tax line whatever **Display tax in the shopping cart** holds (the core turns it off with **Enable tax**).
- Read only, replayable.
- The same form, the same five keys and the same `TaxOptionsConfiguration` on 8.0.1 and 9.0.0.
- To change them: `somcp_update_tax_options`.

#### `somcp_update_tax_options`
**State**: written · [issue #312](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/312)
**Nature**: `acts(EDIT, 'AdminTaxes')` · **Scope**: `mcp.catalog.write`

**Does** - changes the **Tax options** of **International > Taxes > Taxes**, as
the **Save** of that card does: **Enable tax**, **Display tax in the shopping
cart**, **Based on**, **Use ecotax**, **Ecotax**. Only the fields given change;
the answer of `somcp_get_tax_options` can be given back as it is.

**Do not use for** - the rates (→ `somcp_update_tax`, `somcp_update_country_rule`); the ecotax of one product (→ `somcp_update_product`).

**Parameters**

Every field takes what `somcp_get_tax_options` answers.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `taxes_enabled` | **Enable tax** | boolean | no | `PS_TAX` |
| `display_tax_in_cart` | **Display tax in the shopping cart** | boolean | no | `PS_TAX_DISPLAY`; `true` with **Enable tax** off (given or stored) refused |
| `based_on` | **Based on** | string | no | `invoice_address` (**Invoice address**) or `delivery_address` (**Delivery address**), stored as `id_address_invoice` / `id_address_delivery` in `PS_TAX_ADDRESS_TYPE` |
| `use_ecotax` | **Use ecotax** | boolean | no | `PS_USE_ECOTAX`; see the refusal below |
| `ecotax_id_tax_rules_group` | **Ecotax** | integer | no | `PS_ECOTAX_TAX_RULES_GROUP_ID`: `0` (**No tax**) or an enabled tax rule of the shop from `somcp_list_tax_rules`, checked with `SomcpToolGetTaxRule::existingRule()`; refused while **Use ecotax** stays off, as the form hides the field and never saves it |
| `reset_product_ecotax` | | boolean | no | confirms `use_ecotax: false` when products carry an ecotax; refused with anything but `use_ecotax: false` |

A value given equal to the stored one is never refused, whatever the other
fields hold: the conditions on `display_tax_in_cart` and
`ecotax_id_tax_rules_group` apply only to a value that changes. Unknown
arguments through `SomcpTool::unknownFields()`: `ecotax_tax_rule`,
`ecotax_rate` and `language` given back as `somcp_get_tax_options` reads them
are no change, given otherwise they are named as worked out by the shop, as
`warning` always is.

**Refused, nothing written** - `use_ecotax: false` while it is on and at least
one product of the shop carries an ecotax (`product_shop.ecotax > 0`), unless
`reset_product_ecotax: true` is given. The core then sets the ecotax of every
product to 0 (`Product::resetEcoTax()`), which no screen can give back.

**Then** - nothing differs: `changed: false` and `The tax options already hold
every value given. Nothing was written.` Something differs: each key saved
with `Configuration::updateValue($key, $value, false, $idShopGroup, $idShop)`,
the shop and group of the token, in the order of the core's Save; turning the
ecotax off with the flag runs `Product::resetEcoTax()` first, as the core does;
a save that leaves **Enable tax** off also stores `PS_TAX_DISPLAY` 0, as the
core does, and lists it in `changes`. Logged as `tax_options_updated`.

**Returns** - `changed: true`, `changes` (one entry per key changed: `field`,
`old`, `new`, under the names of the parameters, `based_on` in its screen
words), `products_reset` (the count, only when the ecotax was turned off with
the flag), `warning` when products were reset (in every shop) or the
installation has more than one shop (this shop only), then `tax_options`, the
object of `somcp_get_tax_options` read back through
`SomcpToolGetTaxOptions::options()`.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`, every problem at once: a switch that is not `true` or `false`, a
`based_on` that is neither address, an ecotax rule unknown, disabled or given
with the ecotax off, `reset_product_ecotax` without `use_ecotax: false`, the
ecotax turned off without the flag. A save that fails half way names the
fields already saved and asks to read them with `somcp_get_tax_options`.

**Notes**
- Replayable: the same call twice changes nothing the second time.
- Changing **Based on** or **Enable tax** changes the prices of carts not yet ordered; orders already placed keep their amounts.
- The same form, keys and `TaxOptionsConfiguration.php` on 8.0.1 and 9.0.0.

#### `somcp_list_customer_groups`
**State**: written · [issue #51](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/51)
**Nature**: `acts(VIEW, 'AdminGroups')` · **Scope**: `mcp.customers.read`

**Does** - lists the customer groups of the token's shop, as **Shop
Parameters > Customer Settings > Groups** shows them: every column of the
list, and the groups the shop uses for visitors, guests and registered
customers (**Default groups options**). It is the source of the `id_group`
that `somcp_create_customer`, `somcp_update_customer`, the category tools and
the specific prices of the product tools take. Read only.

**Do not use for** - the members of a group (→ `somcp_search_customers` with
`id_group`); one customer's groups (→ `somcp_get_customer`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_group` | integer | no | **ID**: keeps the group with this identifier; a group this shop does not have gives an empty list, as the filter on screen |
| `name` | string | no | part of **Group name**, case-insensitive; the same parameter as `somcp_list_suppliers`, `somcp_list_manufacturers` and `somcp_list_attachments` |
| `show_prices` | boolean | no | **Show prices** (**Yes** / **No**): `true` for the groups that show prices, `false` for those that hide them; every group when left out |
| `reduction_min` / `reduction_max` | number | no | **Discount (%)**, the `reduction` answered, 0 to 100, both included: `reduction_min: 0.01` lists the groups that give a discount |
| `members_min` / `members_max` | integer | no | **Members**, the `members_count` answered, 0 at least, both included, applied after the count so the filter and the answer never disagree: `members_max: 0` lists the empty groups |
| `date_from` / `date_to` | string | no | **Creation date** (`date_add`), YYYY-MM-DD, both days included, read with `SomcpTool::day()` |
| `sort` | string | no | `id_asc` (default), `id_desc`, `name_asc`, `name_desc`, `reduction_asc`, `reduction_desc`, `members_asc`, `members_desc`, `date_add_asc`, `date_add_desc` (`SomcpToolListCustomerGroups::SORTS`, the `<column>_<way>` of `somcp_list_cart_rules`); no **Show prices** sort, as on screen; ties by `id_group` ascending |
| `language` | string | no | ISO code of one of the shop's active languages; a code the shop does not have is refused. The shop default language when left out. |

All filters combine with AND.

**Returns** - `groups`, per group:

| Field | On screen | Notes |
|---|---|---|
| `id_group` | **ID** | the name `group_ids`, `id_default_group` and `somcp_get_customer` already use |
| `name` | **Group name** | in `language` |
| `reduction` | **Discount (%)** | number, percent as shown (`10` for 10 %) |
| `price_display_method` | **Price display method** | `tax_excluded` or `tax_included` |
| `show_prices` | **Show prices** | bool |
| `members_count` | **Members** | customers not deleted, among those the token's shop shares |
| `date_add` | **Creation date** | ISO 8601, as `somcp_get_customer` gives its dates |
| `default_for` | **Default groups options** | list of `visitors`, `guests`, `customers` (one group can hold several); empty when none |

Plus `count`, `customer_groups_enabled` (**Customer groups** of
**Performance**: when false the shop ignores groups for prices) and
`language`. Groups of the token's shop only (`group_shop`), sorted by
**ID** as the list unless `sort` says otherwise.

**Errors** - every problem at once, nothing read, each under "Nothing was read.
Correct these and call again:": a field the tool does not take; `id_group: a
whole number above 0`; `name: a text`; `show_prices: true or false`;
`reduction_min: a number from 0 to 100` (the same for `reduction_max`),
`reduction_max: a number not below reduction_min`; `members_min: a whole
number, 0 at least`, `members_max: a whole number not below members_min`
(`SomcpToolListCartRules::checkWholeRange()`); `date_from: a date,
YYYY-MM-DD`, `date_to: a date not before date_from`
(`SomcpToolListCartRules::checkPeriod()`); `sort: one of ...`
(`SomcpTool::oneOf()`); a language the shop does not have, with the ones it
has.

**Notes**
- Read with one query on `group`, `group_lang` and `group_shop` for the
  token's shop, and the member count as `AdminGroupsController` writes it,
  with the customer sharing of the token's shop (`Shop::getSharedShops()`)
  rather than the global context. `Group::getGroups()` takes the shop from the
  context.
- **Category discount** (`group_reduction`) and **Authorized modules** are
  settings of one group, shown only on its edit form: they belong to
  `somcp_get_customer_group`, not to the list.
- Same legacy page, same columns, same options and same tables on PrestaShop
  8.0.1 and 9.0.0. The page is a tab of **Customer Settings**, not in the side
  menu, so the right asked is `view` on `AdminGroups` itself.

#### `somcp_get_customer_group`
**State**: written · [issue #442](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/442)
**Nature**: `acts(VIEW, 'AdminGroups')` · **Scope**: `mcp.customers.read`, as `somcp_list_customer_groups`

**Does** - reads one customer group of **Shop Parameters > Customer Settings >
Groups** in full, as its **Edit** form shows it: what
`somcp_list_customer_groups` answers, plus the name in every language, the
**Category discount** rows and the **Authorized modules**. Read only,
replayable.

**Do not use for** - the list of groups (→ `somcp_list_customer_groups`); the
members of a group (→ `somcp_search_customers` with `id_group`); changing a
group (→
`somcp_update_customer_group`).

**Parameters**

| Name | Type | Required | Rule |
|---|---|---|---|
| `id_group` | integer | yes | from `somcp_list_customer_groups`; `SomcpTool::notAnIdentifier('id_group')`; a group this shop does not have (`group_shop`): `SomcpTool::notFound('id_group', 'customer group', <id>, 'somcp_list_customer_groups')` |
| `language` | string | no | ISO code of an active language for `name` and the category names, through `SomcpTool::readLanguage()` and `languageProblem()`, as `somcp_list_customer_groups`; the shop default when left out |

Unknown arguments through `SomcpTool::unknownFields()`.

**Returns** - `group`, one object:
- every field of one item of `somcp_list_customer_groups`, under the same
  names and in the same formats (`id_group`, `name`, `reduction`,
  `price_display_method`, `show_prices`, `members_count`, `date_add`,
  `default_for`), read by `SomcpToolListCustomerGroups::groups()`, the query
  the list itself calls;
- `names`: every active language, keyed by ISO code, as `somcp_list_titles`
  answers `names`;
- `category_discounts`: one object per row of **Category discount**
  (`GroupReduction::getGroupReductions()`, as the form reads it), sorted by
  `id_category`: `id_category` (of `somcp_list_categories`), `category` (its
  name in `language`, `null` when the category no longer exists, as the form
  shows an empty path), `reduction` (**Discount (%)**, percent as shown:
  `group_reduction.reduction * 100`, rounded to 2 decimals); `[]` when none;
- `authorized_modules`: the technical `name` of every installed module
  authorized for the group on the shop of the token
  (`Module::getAuthorizedModules()`), sorted, the `name` of
  `somcp_list_modules`; `unauthorized_modules`: the installed ones that are
  not, so the two lists are the **Yes** / **No** of the screen.

Plus `language`, and `customer_groups_enabled` as `somcp_list_customer_groups`
answers it; when `false`, a `warning`: `Customer groups are disabled in
Advanced Parameters > Performance: the discounts of this group do not apply.`
(`SomcpToolGetCustomerGroup::GROUPS_DISABLED`, for the update tool too).

**Errors** - in the envelope `Nothing was read. Correct these and call
again:`: an unknown argument, `id_group` missing, not an identifier or not of
this shop, a language the shop does not have, given in one answer.

**Notes** - read only, replayable. The JSON scenario reads group 3
(**Customer**) and checks it has no category discount and authorizes `somcp`.

**Differences between 8.0.1 and 9.0.0** - none in the form (only the label
**Shop association** / **Store association**, multistore only); the installed
modules differ between the shops.

#### `somcp_update_customer_group`
**State**: written · [issue #443](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/443)
**Nature**: `acts(EDIT, 'AdminGroups')` · **Scope**: `mcp.customers.write`, as `somcp_update_customer`

**Does** - changes a customer group of **Shop Parameters > Customer Settings >
Groups**, as its **Edit** form then **Save** do: its name in each language,
its **Discount**, its **Price display method**, **Show prices** and its
**Category discount** list (#444) and its **Authorized modules** (#445). Only
the fields given change. One group per call.

**Do not use for** - the groups of one customer (→ `somcp_update_customer`).

**Parameters**

| Name | Screen | Type | Rule |
|---|---|---|---|
| `id_group` | | integer | required, from `somcp_list_customer_groups`; `SomcpTool::notAnIdentifier('id_group')`; not of this shop: `SomcpTool::notFound('id_group', 'customer group', <id>, 'somcp_list_customer_groups')` |
| `name` | **Name** | object | per language keyed by ISO code, through `SomcpToolCreateProduct::languages()` and `texts()` as `somcp_update_contact` takes its `name`, a language left out keeping its text; trimmed, never empty; `name (<iso>): 32 characters at most`; `Validate::isGenericName()`: `name (<iso>): no <, >, =, { or }` |
| `languages` | | array | as `somcp_update_contact` |
| `reduction` | **Discount** | number | percent as shown, 0 to 100, at most 2 decimals, the rule of `validateDiscount()`: `reduction: a percentage from 0 to 100` |
| `price_display_method` | **Price display method** | string | `tax_excluded` or `tax_included`, the words of `somcp_list_customer_groups`; anything else: `SomcpTool::oneOf()` |
| `show_prices` | **Show prices** | boolean | `show_prices: true or false` |
| `category_discounts` | **Category discount** | array | the whole list, as the form saves it: each item `{"id_category": <int>, "reduction": <number>}`, the shape `somcp_get_customer_group` answers (its `category` name is accepted and ignored); `[]` removes every row. Not a list of objects: `category_discounts: a list of {id_category, reduction}`; `id_category` not an identifier (`SomcpTool::notAnIdentifier()`) or not of this shop (`SomcpToolUpdateCategory::existingCategory()`): `SomcpTool::notFound('category_discounts[<i>].id_category', 'category', <id>, 'somcp_list_categories')`; the Root category (`PS_ROOT_CATEGORY`), not in the tree of the form: `category_discounts[<i>].id_category: the Root category is not in the tree of the form, choose Home or below`; `reduction` by the rule of the Discount: `category_discounts[<i>].reduction: a percentage from 0 to 100`; the same category twice: `category_discounts[<i>].id_category: given twice`; another key of an item: `category_discounts[<i>].<key>: not a field of a category discount` |
| `authorized_modules` | **Authorized modules:** | array of strings | the whole list of the modules set to **Yes**, by technical name, the shape `somcp_get_customer_group` answers; every installed module left out is set to **No**. Not a list of strings: `authorized_modules: a list of module names from somcp_list_modules`; a name that is not an installed module (`Module::getModulesInstalled()`, compared without case, as `somcp_get_module` does): `SomcpTool::notFound('authorized_modules[<i>]', 'installed module', <name>, 'somcp_list_modules', 'name')`; `[]`: `authorized_modules: at least one module - the back office cannot leave a group with none`, as the screen |
| `unauthorized_modules` | | array of strings | the other half of the read: accepted only when it is exactly the installed modules not in `authorized_modules` (or not in the stored list when `authorized_modules` is left out), compared without case and in any order; otherwise `unauthorized_modules: the installed modules left out of authorized_modules` |

The read given back whole changes nothing: the other fields of the `group` of
`somcp_get_customer_group` (`names`, `members_count`, `date_add`,
`default_for`) are declared in the schema and accepted unchanged; given with
another value they are refused (`members_count: read only`, `date_add: read
only`, `default_for: chosen in Customer Settings > Groups, Default groups
options`). The `category_discounts` and the `authorized_modules` given back as
read (same rows, any order) are no change. `name` and `names` given back are read by
`SomcpToolCreateProduct::nameGivenBack()`, as `somcp_update_title` reads them:
a plain `name` equal to a stored text is no change. Unknown arguments through
`SomcpTool::unknownFields()`.

**Then** - every check first, through
`SomcpToolUpdateCustomerGroup::planGroup()` (public, for a creation tool), every
mistake in one answer. Nothing to change: no write, `changed: false`. Otherwise
the `Group` object with the fields given, `update()` on the shop of the token
(`id_shop_list`). A `false` or an exception: a tool error `The customer group
could not be changed: <reason>. Read it with somcp_get_customer_group before
calling again.`, logged as `customer_group_update_failed`.

A `category_discounts` that differs is written as `updateCategoryReduction()`
of `AdminGroupsController` writes it, in one database transaction with the
`Group` fields: `DELETE` of the rows of the group in `group_reduction` and
`product_group_reduction_cache` (a direct write, as the controller does:
`GroupReduction::delete()` would empty the price cache of every group for the
products of the category); when the list is not empty,
`Configuration::updateGlobalValue('PS_GROUP_FEATURE_ACTIVE', 1)` if it was
off; then per row `Category::addGroupsIfNoExist()` (the group is given access
to the category) and a `GroupReduction` (`reduction` = percent / 100)
`add()`, which fills `product_group_reduction_cache`. A failure rolls the
transaction back and answers `The category discounts could not be saved:
<reason>. Read the group with somcp_get_customer_group before calling again.`

An `authorized_modules` that differs is written as `updateRestrictions()` of
`AdminGroupsController` writes it, in the same transaction:
`Group::addModulesRestrictions(<id_group>, <id_module of each name>,
[<shop of the token>])`, never the shops of the context (a token acts for one
shop, and `module_group` of another shop is never touched), then
`Cache::clean(Hook::MODULE_LIST_BY_HOOK_KEY . '*')`. The tool writes no SQL of
its own for it. A `false` rolls back and answers `The authorized modules could
not be saved. Read the group with somcp_get_customer_group before calling
again.`; the log line names `authorized_modules` among the fields changed,
never the modules.

`Tools::clearSmartyCache()` when `reduction`, `price_display_method`,
`show_prices` or `category_discounts` changed, as the **Show prices** switch of the list does. Logged as
`customer_group_updated` with the `id_group` and the names of the fields
changed.

**Returns** - `changed`, `changes` (`field`, `old`, `new`, as the other update
tools), and `group`: `SomcpToolGetCustomerGroup::group()`, the answer of
`somcp_get_customer_group`. `warning`, each when it applies: `show_prices` set
to `false` while **Catalog mode** is off: `Customers of this group no longer
see prices; to hide them for every group, turn on catalog mode in Product
Settings instead.`; `reduction` above 0 while customer groups are still
disabled after the save (`Group::update()` turns the global value on by
itself): `SomcpToolGetCustomerGroup::GROUPS_DISABLED`; a default group
(`default_for` not empty): `This group is used for <visitors, guests,
customers>: the change reaches every one of them.`; customer groups turned on
by a category discount: `Customer groups were turned on in Advanced
Parameters > Performance, as the back office does when a category discount is
saved.`; a category discount list not empty while the group **Discount** is
above 0, the two warnings of the screen in one sentence: `A category discount
replaces the group discount for the products of that category, it does not add
to it; only products whose default category it is are concerned.`; a category
the group could not see before (`category_group`, read before the write): `The
group can now see the category <name>.`; a module newly set to **No** that is
hooked to `paymentOptions` for the shop of the token (`hook_module`, read
before the write; `Hook::getHookModuleExecList()` filters by the groups of the
visitor of the context and would miss one): `The customers of this group no
longer see these payment modules: <display names>.`, since a group left
without a payment module cannot order.

**Errors** - in the envelope `Nothing was changed. Correct these and call
again:`: an unknown argument; `id_group` missing, not an identifier or not of
this shop; the rules above; a read only field given with another value.

**Notes** - changes the shop: the assistant asks first. Replayable: a second
identical call changes nothing. The shop test gives back the read of group 3,
sets its Discount to 5 and back to 0, Show prices off and back on, and its
category discounts to 10 % on Clothes, given back unchanged, then `[]`, gives
its `authorized_modules` back unchanged, then without `ps_checkpayment` (the
payment warning) and the full list again, and restores the group whatever
happens.

**Differences between 8.0.1 and 9.0.0** - none: the same legacy controller,
the same `Group` definition.

### Diagnostics

Tools that describe the connection itself, and the two that carry files in and out of the shop for the other tools.

#### `somcp_ping`
**State**: tested
**Nature**: `actsOnNothing()` - no permission required · **Scope**: `mcp.access`

**Does** - checks that the connection to the shop works and says what it
reached: which shop answered, the PrestaShop version, the languages, currency
and time zone its values are expressed in, and the employee account the
connection acts under. On a multistore installation it also names the shops this
connection cannot reach.

**Do not use for** - reading shop data: it reads none.

**Parameters** - none.

**Returns** - seven lines of text, eight on a multistore shop.

**Notes** - outside the permission system by design: it is how an assistant with
no rights anywhere can still say which account it is connected as.

#### `somcp_upload_file`
**State**: written
**Nature**: no permission of its own; the tool named must be available to the connection · **Scope**: `mcp.access`

**Does** - puts a file on the shop for another tool and answers with the
`upload_ref` that tool takes. The assistant names the tool (`for`) and its file
field; the shop opens a deposit with that field's own formats and size, as the
tool declares them (`files:` of its `SomcpTool`). The file comes in the same
call by one of the entries of `SomcpUpload::ENTRIES`: `file`, a file the person
attached in the assistant's conversation as its client passes it (ChatGPT's
file parameters, declared in the tool's `_meta` as `openai/fileParams`);
`data`, the bytes as a data URI (`x-mcp-file`, the form the protocol's file
input proposals take); `url`, a public address the shop downloads. Without any,
the answer says how the file gets in, each step only if the one before failed:
the assistant posts it itself to the deposit's direct address (`send_to`) when
it can make an HTTP request, telling the person once which domain to allow in
their assistant's network settings if its own environment blocks the request;
then it brings it here with `upload_ref`; then, with `upload_ref` alone, it is
given the merchant's deposit page. The page comes only after such a failure: a
refused file, `upload_ref` with no file, or a tool called back with a deposit
still empty. In compact mode the tool stays in
the list, since a client reads what it can pass a file to from the tools it
lists.

**Do not use for** - a file the merchant attached in this module's back office
chat, which already has its `upload_ref`; a tool that takes a public address,
when one is at hand (give it as `url` to that tool directly); a module tool
asking for files itself (it hands out its own deposit).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `for` | string | yes, unless `upload_ref` | the tool the file is for, e.g. `somcp_create_product` |
| `field` | string | no | its argument that takes the file, e.g. `images`, `virtual_file.file`, `logo`; may be left out when the tool has only one |
| `upload_ref` | string | no | instead of `for` and `field`: a deposit a tool already opened and asked the assistant to fill - a tool of this module, or of another through the contract (`upload`) - filled with `file`, `data` or `url` under that deposit's own rules; alone, it answers with the merchant's deposit page |
| `file` | object | no | a file of the assistant's conversation: `download_url` and `file_id` (required), `mime_type`, `file_name`; the shop downloads `download_url` with the guards of `url`; refused for `somcp_upload_module` |
| `data` | string | no | the file as `data:<media type>;base64,<content>`; the media type decides the format checked; the exact bytes of a file the assistant holds, never one it writes itself |
| `name` | string | no | with `data` only: the file's name, else the `name=` parameter of the data URI, else `file`; its extension is always the one the media type gives; refused with `file` or `url`, which bring their own |
| `url` | string | no | a public `https` address (no redirect) the shop downloads now; refused for `somcp_upload_module` |

At most one of `file`, `data` and `url`. The directions any deposit hands out
name this way right after posting the file, and before asking the merchant, so
a module asking for files through the contract gets it without a change.

**Returns** - with an entry: `deposited` true, `upload_ref`, `for`, `field`,
then `files` (`name` and `bytes` of each file the deposit holds, as kept),
`files_held`, `files_allowed` and `then`; with `upload_ref`: the same without
`for` and `field`, `then` saying to call the tool that opened the deposit
again. Each call adds a file and never replaces one, and `then` says so until
the deposit is full: an assistant that read `deposited` as "replaced" sent the
same file again. The sizes let it see a file it rebuilt instead of copying. Without: `deposited` false, `upload_ref`, `for`, `field`, `accepted`
(extensions), `max_bytes`, `expires_at`, `send_to` (`url`, `method` POST,
`encoding` multipart/form-data, `field` `file`) and `how`, the directions in
order. With `upload_ref` and no file: the directions again, the merchant's
deposit page included, as an answer rather than an error. A refused file is
answered with the same directions after the reason. The
direct address answers `{"received": true, "file": ...}`, or `received` false
with the reason it refused the file.

**Errors** - under "Nothing was deposited.", every problem at once: a field the
tool does not take; `for` missing, naming a tool that does not exist, is
switched off or is out of this connection's reach (one message for all three),
or a tool that takes no file; `field` not one of the tool's file fields (the
list is given); more than one entry; an entry empty; `url` or `file` for a
field, or a deposit, that is never fetched from an address (an archive); an
`upload_ref` that names no open deposit of this account (typed wrong, expired,
already full, or a file of the built-in assistant - one message for all). A file the shop cannot use (not
public `https`, a redirect, too large, the wrong format or content, a data URI
that is not base64) is refused with its reason and the other ways, the deposit
staying open.

**Notes** - one file per deposit, used once, tied to the token's employee and
shop, open 15 minutes. Every way in ends in the same check
(`SomcpUpload::admit()`): count, size, the extension against the field's list,
the bytes against the extension. The size of a data URI is measured before it
is decoded. The direct address is a front address signed for this deposit
only; anything wrong there gets an empty 404. The tool asks for no back office
right of its own: the deposit is of use only to the tool named, whose scopes
and rights are checked before anything is opened, and again when it is called.

#### `somcp_download_file`
**State**: written
**Nature**: no permission of its own; the right of the file asked for is checked · **Scope**: `mcp.access`

**Does** - gives the file behind a `download_ref` - every file a tool of the
shop finds: a document of an order (`somcp_get_order_document`), a product
photo or the file of a virtual product (`somcp_get_product`,
`somcp_search_products`), a category image (`somcp_get_category`), a brand or
supplier logo, an attribute texture, an order status icon, a carrier logo, a
logo of the shop (`somcp_get_theme`), an attachment (`somcp_list_attachments`)
- attached to the answer when it is 4 MiB or less, an image as an `image`
block the client shows and the model sees, any other file as an embedded
`resource`, and nothing else: no address is given beside it, so the assistant
reads or shows the file rather than handing the merchant a link. Only a file
too large to attach comes as a signed address the assistant fetches itself
(`url`, a GET, 15 minutes), the door the chat's files use, and the page that
serves it to the merchant signed in to the back office. The built-in chat,
which passes its model the text of an answer only, gets the page. In compact
mode the tool stays in the list.

**Do not use for** - finding a file (→ the tool that reads it).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `download_ref` | string | yes | the reference exactly as the other tool gave it |

**Returns** - a text block with `file` (its name), `mime_type`, `bytes`,
`inside` and `then`. With `inside` true, a second block: `image` (`data`,
base64, `mimeType`) for an image, `resource` (`blob`, base64) for any other
file. With `inside` false, also `url` and `expires_at` (null when the shop
could not keep the file, and in the built-in chat) and `page` (null when the
back office address is not known yet): the assistant fetches `url` itself, or
gives the merchant the page.

**Errors** - under "Nothing was read.": a field the tool does not take;
`download_ref` missing; expired (ask the tool that gave it again); not a
reference made for this account on this shop (typed wrong, another account,
another shop, an unknown kind - one message for all); the account or the
connection without what the tool reading that kind of file needs (its scopes
and back office rights, `SomcpDownload::mayHave()`); the file deleted since.

**Notes** - the reference is checked by `SomcpDownload::check()`, the same
check as the download page's, then the right, and only then is the file looked
up, so whether it exists is told to no one who may not have it. A document is
built on demand from the shop's templates with the core `PDF` class; any other
file is read where the shop keeps it (`SomcpDownload::stored()`), and must
belong to the shop of the token (its `_shop` table) where the object has one.
Its `mime_type` is read from its bytes (`SomcpUpload::identify()`, the check
of every file coming in), never taken from what was stored with it: an
attachment whose content is not what its name says goes out as
`application/octet-stream`, and `then` tells the assistant not to read it as
that type. The one exception is an image named as another image format the
shop takes (JPEG, PNG, GIF, WebP): it goes out under the format of its bytes,
its extension changed to match, since PrestaShop itself writes PNG into files
it names `.jpg` when the image quality setting asks for PNG. Its name keeps plain letters, digits, `.` and `_` only, everything
else becoming `-` (`SomcpDownload::cleanName()`): an invoice named
`#FA000104/2026.pdf` goes out as `FA000104-2026.pdf`, which a client can show.
A file too large to attach is then kept as a deposit by `SomcpUpload::share()`
for 15 minutes - the same store, the same check of the bytes and the same
signed link (`controllers/front/file.php`) as any other file - and purged with
the deposits. The link carries the hash of the deposit's reference and a
signature, never the reference; anything wrong gets an empty 404. The back
office page shows a PDF or a JPEG, PNG, GIF or WebP image, and has the browser
save anything else, so an attachment is never run inside the back office.

**Differences between 8.0.1 and 9.0.0** - none: the document objects, the
`PDF` class, the folders of the files and the download page are the same.

### Catalogue

The products and the categories they are sorted into: texts, prices, combinations, images, the category tree.

**Not here**: stock quantities (→ Stock), what sold (→ Statistics).

#### `somcp_search_products`
**State**: written
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.catalog.read`

**Does** - finds products with the filters of the back office product list (ID, name, reference, category, price without and with tax, quantity, status, EAN13, MPN, UPC, ISBN), and returns one short line each.

**Do not use for** - reading a product already identified (→ `somcp_get_product`), or stock levels across the catalogue (→ `somcp_list_low_stock`).

**Parameters** - every filter is optional and they combine (AND). Text filters match a part of the value, case-insensitive.

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | no | one product by its ID |
| `name` | string | no | part of the name, in `language` |
| `reference` | string | no | part of the reference of the product **or of one of its combinations** |
| `id_category` | integer | no | products associated with this category, from `somcp_list_categories` |
| `price_tax_excl_min` / `price_tax_excl_max` | number | no | price without tax, bounds included |
| `price_tax_incl_min` / `price_tax_incl_max` | number | no | price with tax, bounds included |
| `quantity_min` / `quantity_max` | integer | no | total quantity, combinations included, bounds included |
| `active` | boolean | no | active or inactive products only |
| `ean13` | string | no | part of the EAN13 of the product **or of one of its combinations** |
| `mpn` | string | no | part of the product's MPN |
| `upc` | string | no | part of the product's UPC barcode |
| `isbn` | string | no | part of the product's ISBN |
| `sort` | enum | no | `date_add_desc` (default), then the columns of the back office product list, each way: `id_asc`, `id_desc`, `name_asc`, `name_desc`, `reference_asc`, `reference_desc`, `category_asc`, `category_desc` (the name of the default category in the language of the call, as the **Category** column shows it), `price_asc`, `price_desc`, `quantity_asc`, `quantity_desc`, `active_asc`, `active_desc` (**Status**); ties by `id_product`, in the same direction |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise; the shop default when left out; given back as `language` in the answer |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `reference`, `name`, `price_tax_excl`, `price_tax_incl`, `currency`, `quantity`, `active`, `id_category_default`, `category_default` (the name of the default category in `language`, the Category column), `cover_image` (the cover of the Image column, as a `download_ref`; `null` when the product has none), `ean13`. When the product was found through a combination's reference or EAN13: `matched_combinations`, a list of `id_product_attribute`, `reference`, `ean13` for the combinations that matched. Plus `returned`, `total_count`, `offset` and `language` (the ISO code the names are read in).

**Notes**
- Read through a query (`DbQuery`), not through Product objects. Same columns as the back office product list: `product_shop` for price and status, `product_lang` for the name, `category_product` for the category, `stock_available` with `id_product_attribute = 0` for the quantity.
- Reference and EAN13 also look in `product_attribute`; MPN, UPC and ISBN only on the product. A product found through several combinations comes back once.
- Prices are the product's base price, before specific prices and combination impacts. The price with tax uses the product's tax rule in the shop's default country, as the back office list does.
- At least one filter is not required: without any, the tool lists the catalogue page by page.
- Like the back office list, the prices add the ecotax when the shop uses it, and a product still being created (`state` other than saved) is left out. A tax rule with no rate for the default country counts as no tax.
- Prices are rounded to the precision of the shop default currency, which is the currency they are stored in. With both `reference` and `ean13` given, a combination is listed in `matched_combinations` when it matches either one.
- `category_default` and `cover_image` are read for the page only, one query each (`category_lang` and `image_shop` of the shop of the token), never one per product. The cover is handed out by `SomcpDownload::offer()`, as the images of `somcp_get_product`.
- A `language` the shop does not have is refused, like the other read tools, rather than read in the default language.
- A field the tool does not take, a `sort` outside the list, an `active` that is not true or false, an `id_product` or `id_category` that is not a whole number above 0, a price bound that is not a number or a quantity bound that is not a whole number is refused in one answer, `Nothing was read`, before anything is read: never ignored, never replaced by the default order.
- An `id_category` the shop does not have is refused in the same answer, `id_category: no category of this shop has the identifier <n> - find it with somcp_list_categories`, instead of answering an empty list.

#### `somcp_get_product`
**State**: written
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office product page shows for one product, tab by tab, combinations included.

**Do not use for** - looking for products (→ `somcp_search_products`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes | from `somcp_search_products` |
| `id_product_attribute` | integer | no | one combination of that product: `combinations` then holds that one only, and `specific_prices` and `suppliers` keep what applies to it |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - one object, grouped like the tabs of the product page, with `language` (the ISO code the texts are read in) at the top. Texts are in `language`; every amount comes with `currency`. Values are the ones PrestaShop stores, so the write tools can take them back as they are.

| Group | Fields | Where it is read |
|---|---|---|
| `basics` | `id_product`, `product_type` (standard, combinations, pack, virtual), `name`, `description_short` (summary), `description` | `Product`, `Product::getProductType()` |
| `categories` | `id_category_default`, `categories` (`id_category` and `name` of each associated category) | `Product::getProductCategoriesFull()` |
| `brand` | `id_manufacturer`, `manufacturer_name` | `Manufacturer::getNameById()` |
| `related_products` | `id_product`, `reference` and `name` of each related product, active or not | `Product::getAccessoriesLight()` |
| `references` | `reference`, `mpn`, `upc`, `ean13` (EAN-13 or JAN), `isbn` | `Product` |
| `features` | per feature: `id_feature`, `id_feature_value`, `name`, `value`, `custom` | `feature_product`, `feature_lang`, `feature_value`, `feature_value_lang` |
| `attachments` | per document: `id_attachment`, `name`, `description`, `file_name`, `mime` | `Product::getAttachmentsStatic()` |
| `condition` | `condition` (new, used, refurbished; PrestaShop 9 adds open_box, damaged, new_with_defects), `show_condition` | `Product` |
| `customization` | `customizable` (0 no, 1 optional fields, 2 a required field), `uploadable_files`, `text_fields`, and `fields`, each: `id_customization_field`, `type` (file or text), `name`, `required` | `Product::getCustomizationFields()` |
| `stock` | `quantity` (total, combinations included), `location` (stock location), `out_of_stock` (deny, allow, default), `available_now` (label when in stock), `available_later` (label when out of stock and backorders allowed), `available_date`, `minimal_quantity` (minimum quantity for sale), `low_stock_threshold` (low stock level, `null` when none), `low_stock_alert` (email alert at that level), `pack_stock_type` (pack, products, both, default; `null` when the product is not a pack) | `stock_available`, `Product` (`product_shop`) |
| `pack` | `items`, each: `id_product`, `id_product_attribute` (0 for none), `name`, `reference`, `quantity`; empty unless `product_type` is pack | `pack`, `product`, `product_lang` |
| `virtual_file` | `file` (a `download_ref` for `somcp_download_file`), `display_filename`, `nb_downloadable` (0 for unlimited), `date_expiration` (ISO 8601 or `null`), `nb_days_accessible` (0 for unlimited), `active`; `null` when the product has no file. Never the name on disk | `product_download` |
| `shipping` | `width`, `height`, `depth`, `weight`, `additional_delivery_times` (none, default, product), `delivery_in_stock`, `delivery_out_stock`, `additional_shipping_cost`, `currency`, `carriers` (every carrier of the shop with `id_carrier`, `name` and `available` true or false for this product) | `Product`, `Product::getCarriers()`, `Carrier::getCarriers()` |
| `pricing` | `price_tax_excl`, `id_tax_rules_group`, `tax_rules_group_name`, `tax_rate`, `price_tax_incl`, `wholesale_price` (purchase price, tax excluded), `unit_price_tax_excl`, `unit_price_tax_incl`, `unity` (the unit), `on_sale`, `ecotax_tax_excl`, `ecotax_tax_incl` (with the rate of the ecotax tax rule `PS_ECOTAX_TAX_RULES_GROUP_ID` in the default country), `currency` | `Product`, `TaxRulesGroup`, `TaxRulesGroup::getAssociatedTaxRatesByIdCountry()` |
| `specific_prices` | `rules`, each one: `id_specific_price`, `id_product_attribute`, `id_shop`, `currency` and `country` (ISO code, `null` for all), `id_group`, `id_customer` (0 for all), `from_quantity`, `price` (fixed or -1), `reduction`, `reduction_type` (amount or percentage), `reduction_tax`, `amounts_currency`, `from`, `to` (ISO 8601 with the shop's offset, `null` for no date); plus `priorities`, the order used to pick between them, and `use_default_priorities`: `true` when the product follows the shop's default order (**Use default order** on 9.0.0, no row in `specific_price_priority`), `false` when it has its own (**Set a specific order for this product**); `priorities` is the order that applies either way | `SpecificPrice::getByProductId()`, `SpecificPrice::getPriority()`, `specific_price_priority` |
| `seo` | `meta_title`, `meta_description`, `link_rewrite` (friendly URL), `url` (full product URL), `redirect_type`, `id_type_redirected` (redirection page), `meta_keywords`, `tags` | `Product`, `product_lang`, `Link::getProductLink()`, `Product::getTags()` |
| `options` | `visibility` (both, catalog, search, none), `available_for_order`, `show_price`, `online_only` (web exclusive, not sold in store), `active` | `Product` |
| `suppliers` | `id_supplier_default`, and `suppliers`, per supplier and combination: `id_supplier`, `supplier_name`, `id_product_attribute`, `product_supplier_reference` (supplier SKU), `product_supplier_price_te` (supplier purchase price), `currency` (supplier currency) | `product_supplier`, `supplier`, `currency` |
| `images` | per image: `id_image`, `position`, `cover` (the default image), `legend`, `download_ref` (the original file) | `Image::getImages()`, `SomcpDownload::offer()` |
| `combinations` | per combination: `id_product_attribute`, `attributes` (each: `id_attribute_group`, `group`, `id_attribute`, `value`, for example Size: M), `default_on`, `reference`, `mpn`, `upc`, `ean13`, `isbn`, `quantity`, `location`, `available_date`, `available_now`, `available_later`, `price_impact_tax_excl`, `price_impact_tax_incl`, `unit_price_impact_tax_excl`, `unit_price_impact_tax_incl`, `weight_impact`, `wholesale_price`, `ecotax_tax_excl` and `ecotax_tax_incl` (the combination's own ecotax, stored without tax in `product_attribute_shop.ecotax`, 0 when it takes the product's; tax included as `pricing.ecotax_tax_incl`), `minimal_quantity`, `low_stock_threshold` (`null` when none), `low_stock_alert`, `currency`, `images` (ids) | `product_attribute`, `product_attribute_shop`, `product_attribute_lang`, `product_attribute_combination`, `stock_available`, `product_attribute_image` |

**Notes**
- Called by identifiers only, never by a reference, an EAN13 or a name: finding the product is the job of `somcp_search_products`, which also returns the `id_product_attribute` of a matching combination.
- An `id_product_attribute` that does not belong to `id_product` in this shop is refused, never silently ignored. A product not associated with the shop, or still being created, is answered like one that does not exist.
- The values are the product's own, before specific prices and cart rules; `specific_prices` lists the rules apart, so the assistant does not mistake a promotion for the price. Only the rules of this shop or of all shops are listed.
- PrestaShop 8.0 has no `product_attribute_lang`: the `available_now` and `available_later` of a combination exist from 8.1 and are `null` before. PrestaShop 9 dropped `meta_keywords`: the field is `null` there. Checked with the table and the column, not the version number.
- `tags` is the product's tags (the Étiquettes field) in `language`, a comma separated list, empty when it has none. It is a different field from `meta_keywords`, is kept on every PrestaShop version, and is the shape `somcp_create_product` and `somcp_update_product` take back.
- `tax_rate` is the rate of the tax rule in the shop's default country, the one the back office shows, and the one `somcp_search_products` uses: 0 when the shop has taxes switched off or the rule has nothing for that country. Amounts with tax are rounded to the precision of the shop default currency; the ecotax is not added to them.
- No carrier ticked on the product means every carrier may deliver it: each one then says `available: true`.
- `low_stock_threshold` is read by `SomcpCatalog::threshold()`, the code `somcp_get_stock` uses, and the ecotax rate by `SomcpCatalog::ecotaxRate()`, the code `somcp_search_products` uses, so the three tools agree. The pack items are read from the `pack` table rather than `Pack::getItems()`, which loads a full product per item.
- A `language` the shop does not have is refused, like the other read tools, rather than read in the default language - one line among the other problems of the call (`SomcpTool::languageProblem()`).
- Read the product in the shop of the token (`product_shop`), never the default shop. Stock follows the shop's stock sharing, as the core decides it.
- Features are read from the tables rather than `Product::getFrontFeatures()`, which returns nothing when features are switched off for the storefront and does not say whether a value is custom. Combinations are read the same way rather than with `Product::getAttributeCombinations()`, whose stock join ignores the shop.

#### `somcp_create_product`
**State**: written
**Nature**: `acts(ADD, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - creates one complete product: everything `somcp_get_product` reads can be given at creation, combinations and images included, in every language of the shop or the ones the merchant picks. Inactive by default. Each field says whether the merchant gives it or the assistant writes it; SEO is written by the assistant in every language, checked and warned about.

**Do not use for** - changing an existing product (→ `somcp_update_product`), or creating several at once, which it refuses by design.

**Parameters** - the groups and field names of `somcp_get_product`, so what one tool reads the other writes. Only `basics.name` and `pricing.price_tax_excl` are required. Fields that are computed or read elsewhere are not accepted: `id_product`, `url`, `manufacturer_name`, `supplier_name`, `tax_rules_group_name`, `tax_rate`, `price_tax_incl`, `unit_price_tax_incl`, `quantity` totals.

**Who fills a field.** The tool cannot see who typed a value, so this is a rule for the assistant, written in the tool description and in the prompt `somcp-catalogue-manage`:
- **merchant**: the assistant asks the merchant and never invents the value. It may suggest one, the merchant confirms.
- **merchant, from the shop**: an object that already exists in the shop (category, brand, carrier, tax rule, supplier, attribute, feature, document, customer group). The assistant shows the shop's list, read with the matching `somcp_list_*` tool, and the merchant picks; the tool refuses an id that does not exist and never creates the object.
- **assistant**: the assistant writes it, in every language being filled, and shows it to the merchant before the call.
- **merchant and assistant**: the merchant gives the material (an image URL, the sizes and colours), the assistant prepares the rest (legends, combination codes) and shows it before the call.

**Languages.** `languages`, a list of ISO codes among the shop's active languages, says which languages are filled; all of them by default. Every translatable text is an object keyed by ISO code, `{"fr": "...", "en": "..."}`, and must be given for each language in `languages`. A language of the shop left out of `languages` takes the default language's text, as the back office does. Lengths are counted in characters, spaces included, on the visible text (HTML tags not counted).

| Group | Field | Who fills it | Rule |
|---|---|---|---|
| `basics` | `product_type` | merchant | a value of PrestaShop's `ProductType`: `standard`, `combinations`, `pack`, `virtual` |
| | `name` | merchant | required; 128 characters at most per language (`ProductSettings::MAX_NAME_LENGTH`) |
| | `description_short` (summary) | assistant | at most the shop's **Max size of short description** (Shop Parameters > Product Settings, `PS_PRODUCT_SHORT_DESC_LIMIT`, 800 when empty), counted on the visible text and refused past it; 100 to 200 characters reads best, outside that `seo_warnings` says so without blocking; HTML cleaned, 65 535 bytes at most on PrestaShop 8 |
| | `description` | assistant | HTML cleaned; 65 535 bytes at most on PrestaShop 8 |
| `categories` | `categories` | merchant, from the shop | ids from `somcp_list_categories` |
| | `id_category_default` | merchant, from the shop | one of `categories`, added to them if missing; Home when none |
| `brand` | `id_manufacturer` | merchant, from the shop | id from `somcp_list_manufacturers` |
| `related_products` | ids | merchant | the assistant finds candidates with `somcp_search_products`, the merchant picks |
| `references` | `reference` | merchant | 50 characters at most (PrestaShop allows 64); refused when another product or combination already uses it |
| | `mpn`, `upc`, `ean13`, `isbn` | merchant | each checked with its `Validate` rule |
| `features` | `id_feature` with `id_feature_value` or a custom value | merchant, from the shop | ids from `somcp_list_features` |
| `attachments` | `id_attachment` | merchant, from the shop | ids from `somcp_list_attachments`; files do not travel through the call |
| `condition` | `condition`, `show_condition` | merchant | values of the running PrestaShop version |
| `customization` | fields: `type` (file or text), `name`, `required` | merchant | `customizable`, `uploadable_files`, `text_fields` follow from them |
| `stock` | `quantity`, `location` | merchant | written through `StockAvailable`; with combinations, `quantity` is refused: each combination has its own and the product's is their total |
| | `out_of_stock` | merchant | deny, allow, or shop default |
| | `available_now`, `available_later` | merchant | the merchant's text, or left out: the storefront then shows the shop's default label |
| | `available_date` | merchant | a date, or left out: none |
| | `minimal_quantity` (Minimum quantity for sale) | merchant | a whole number from 1, 1 by default, checked with `Product::$definition` |
| | `low_stock_threshold` (Low stock level), `low_stock_alert` (email alert at that level) | merchant | a whole number, or `null` for none, stored the way the running version stores no level (`NULL` on 8, `0` on 9) |
| | `pack_stock_type` (Pack quantities / Pack stock behavior) | merchant | `pack`, `products`, `both`, `default` (the default); refused unless `product_type` is `pack` |
| `pack` | `items`, each: `id_product`, `id_product_attribute` (0 or left out for none), `quantity` (from 1) | merchant, from the shop | refused unless `product_type` is `pack`; the assistant finds the products with `somcp_search_products` and the combinations with `somcp_get_product`, the merchant picks; a product that is itself a pack, a combination of another product or the same item twice is refused; written with `Pack::addItem()` after the product |
| `virtual_file` | `file` (`url` or `upload_ref`), `display_filename` (the Filename field) | merchant | both required; refused unless `product_type` is `virtual`; see **Images** for the two doors a file comes in through, without the image check and within the shop's maximum attachment size (`PS_ATTACHMENT_MAXIMUM_SIZE`), the limit of the back office; stored under `download/` with a name from `ProductDownload::getNewFilename()`, then a `ProductDownload` row, and `PS_VIRTUAL_PROD_FEATURE_ACTIVE` turned on: without it the shop looks no file up, and the core turns it on only for a virtual product saved active |
| | `nb_downloadable`, `nb_days_accessible` | merchant | whole numbers, 0 for unlimited (the default) |
| | `date_expiration` | merchant | a date, YYYY-MM-DD, or `null`: none |
| `shipping` | `width`, `height`, `depth`, `weight` | merchant | |
| | `additional_delivery_times` | merchant | none, shop default, or this product's |
| | `delivery_in_stock`, `delivery_out_stock` | merchant | used when `additional_delivery_times` is this product's, which they select when it is left out; refused next to another value |
| | `additional_shipping_cost` | merchant | |
| | `carriers` | merchant, from the shop | ids from `somcp_list_carriers`; none given means every carrier, as in the back office |
| `pricing` | `price_tax_excl` | merchant | required |
| | `id_tax_rules_group` | merchant, from the shop | id from `somcp_list_tax_rules`, never guessed; 0 for no tax; left out, the rule the back office preselects for a new product (the most used one), so a product is never sold without VAT by omission |
| | `wholesale_price`, `unit_price_tax_excl`, `unity`, `on_sale` | merchant | |
| | `ecotax_tax_excl` | merchant | a positive number; refused on PrestaShop 9 while the ecotax is switched off (`PS_USE_ECOTAX`), since the field is then not on the page, unless it is the value already stored, given back unchanged (0 for a new product); always taken on PrestaShop 8 |
| `specific_prices` | `rules` as read by `somcp_get_product`, plus `priorities` | merchant | currency and country by ISO code, group from `somcp_list_customer_groups`; each written through `SpecificPrice::add()` for the shop of the token; a combination is named by its position in `combinations` (`combination`, from 0); a percentage `reduction` is the fraction PrestaShop stores, 0.2 for 20 %; a rule with neither a price nor a reduction is refused; `priorities` lists `id_shop`, `id_currency`, `id_country`, `id_group` once each (`id_customer`, always first, is left out); `use_default_priorities` (boolean, the radio **Use default order** / **Set a specific order for this product** of 9.0.0; 8.0.1 has no radio, the tool works the same on both, the table being the same): `true`, the default, writes no row and takes `priorities` only when it is the shop's default order, `false` needs `priorities`; **Apply to all products** (8.0.1), a shop setting, is not written; `from` and `to` in ISO 8601, converted to the shop's time zone (the shop's time without an offset), so a bound given back as read changes nothing |
| `seo` | `meta_title`, `meta_description`, `link_rewrite`, `redirect_type`, `id_type_redirected`, `meta_keywords`, `tags` | assistant | every language being filled; see **SEO** below |
| `options` | `visibility`, `available_for_order`, `show_price`, `online_only`, `active` | merchant | `active` false by default |
| `suppliers` | `id_supplier_default` | merchant, from the shop | id from `somcp_list_suppliers` |
| | per supplier and combination: `id_supplier`, `combination` (position in `combinations`, left out for the product), `product_supplier_reference`, `product_supplier_price_te`, `currency` | merchant | currency by ISO code, among the shop's currencies, the shop default otherwise; each supplier is also associated with the product itself; `id_supplier_default` left out is the first supplier given |
| `images` | `url` or `upload_ref` | merchant | see **Images** below |
| | `legend`, `cover`, `position`, `combinations` (positions in `combinations`) | merchant and assistant | legend in every language being filled; one cover at most, the first image that arrived otherwise |
| `combinations` | `attributes` | merchant, from the shop | `id_attribute` values from `somcp_list_attributes`; two combinations with the same attributes are refused |
| | `default_on`, `reference`, `mpn`, `upc`, `ean13`, `isbn`, `quantity`, `location`, `available_date`, `available_now`, `available_later`, `price_impact_tax_excl`, `unit_price_impact_tax_excl`, `weight_impact`, `wholesale_price`, `ecotax_tax_excl`, `minimal_quantity`, `low_stock_threshold`, `low_stock_alert` | merchant and assistant | same rules as the product's own fields; one combination is the default; `ecotax_tax_excl` is the combination's ecotax tax excluded, 0 to use the product's, refused on PrestaShop 9 while **Use ecotax** is off, unless it is the value already stored, given back unchanged (0 for a new combination), as `pricing.ecotax_tax_excl` (the 8.0.1 form shows it tax included as **Ecotax (tax incl.)**, 9.0.0 both; the stored, tax excluded value is the input); `ecotax_tax_incl`, worked out by the shop, is not written |

**SEO**
- Fields: `meta_title`, `meta_description`, `link_rewrite`, `redirect_type`, `id_type_redirected`, `meta_keywords` where the column exists (PrestaShop 8; on 9 the field is refused with a clear message, not dropped silently), and `tags`.
- `tags` is the product's tags (the Étiquettes field), a comma separated list per language, written with `Tag::addTags()` to the shop's own tag field - a different field from `meta_keywords`, and kept on every PrestaShop version. Each tag is 32 characters at most and may not hold `< > = # { }`; a longer or refused one fails the call rather than being cut. The shop creates the tags it does not have yet and links them.
- `link_rewrite` left out: generated from the name in each language with `Tools::str2url()`. Given: passed through `Tools::str2url()`, then refused if `Validate::isLinkRewrite()` fails. Never empty in any language.
- `meta_title` 255 characters at most and `meta_description` 512 at most, the sizes of `Product::$definition` that the back office refuses past: longer is refused, never cut. Past 70 characters for the title and 160 for the description, a search result cuts it: `seo_warnings` says so, without refusing (`SomcpTool::seoTextLength()`, the rule every SEO write shares).
- `redirect_type` is what an inactive product does: the values of `RedirectType` in the running version (PrestaShop 9 adds `default`, `200-displayed`, `404-displayed`, `410-displayed`). A `301-product` or `302-product` needs an existing `id_type_redirected` product, a category redirect an existing category. Left out: the shop default.
- The answer carries `seo_warnings`, per language: no `meta_title` or no `meta_description` (the storefront then falls back on the name), a `meta_title` past 70 characters or a `meta_description` past 160, with its length (`meta_title is 85 characters: past 70, a search result cuts it`, the sentence of `SomcpTool::metaLengthAdvice()` every SEO write gives), a `link_rewrite` or a `meta_title` already used by another product in that language. Warnings never block the creation.

**Images**
- Files do not travel through the conversation: an image is given by a public `https` URL the shop downloads, or by the `upload_ref` of a file already on the shop (attached in the built-in assistant, or from `somcp_upload_file` with `for` set to this tool and `field` to `images`), redeemed for the employee the call runs as. One of the two per image, never both, never file content.
- That download is a new network request from the shop, so it is guarded: `https` on the standard port only, no credentials in the address, the host must resolve to a public address (no localhost, no private, link-local, shared, documentation or multicast range, IPv4 hidden in IPv6 included, checked after resolution and then pinned for the connection), no proxy, redirects not followed, 10 MB at most counted while reading, and the content must be a JPEG, PNG, GIF or WebP that `ImageManager` accepts. A refused image is reported in the answer and does not stop the creation.
- Thumbnails generated through `ImageManager` for every image type, as the back office does, in JPEG and in each extra format switched on in the settings (PrestaShop 8.1 and later), with the fitment of each type on PrestaShop 9.

**Returns** - `created`, `id_product`, `seo_warnings`, `images` (per image, its `url` or `upload_ref`: `id_image` or the reason it was refused), `pack_items` (per item, as given: `added` or the reason it was refused), `pack_quantity` (for a pack counted from its products, the quantity its stock line now holds, worked out from them as `Pack::getQuantity()` does; `null` otherwise), `virtual_file` (its `url` or `upload_ref`: `added` or the reason it was refused; `null` when none was given), and the product as `somcp_get_product` returns it.

**Notes**
- Written through the core classes (`Product::add()`, `Combination`, `StockAvailable`, `SpecificPrice`, `Image`) so hooks fire and other modules see a normal product creation.
- Everything or nothing for the product itself: if the product or a combination cannot be written, what was created is deleted and the call fails. Only images, pack items, the downloadable file and SEO warnings are reported without failing: they are written after the product, every check done before the first write.
- The name on disk of a downloadable file is never returned: it is what lets a customer download it.
- Not replayable: the read tool to check is `somcp_search_products` with the reference.
- Every problem is reported at once, before anything is written: unknown or computed fields, identifiers that do not exist in the shop, texts missing in a language, references already used, two combinations with the same attributes or two values of one attribute group. The rules of each field (`validate`, `size`, `values`) are read from the class definition of the running version through `ObjectModel::validateField()`, so an EAN13 is checked as PrestaShop 8 or 9 checks it.
- Lists of identifiers (`categories`, `related_products`, `attachments`, `carriers`, combination `attributes`) take integers; the objects `somcp_get_product` returns are accepted too.
- The labels of a combination (`available_now`, `available_later`) exist from PrestaShop 8.1; on 8.0 they are refused with a clear message rather than dropped.
- The stock movement of an initial quantity carries the employee of the token, set on the context for the length of the write since the MCP endpoint has none. Quantities are written last, so a rollback leaves no movement behind.

#### `somcp_update_product`
**State**: written
**Nature**: `acts(EDIT, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - changes one existing product with everything `somcp_create_product` can write: the same groups, the same fields, the same rules, combinations and images included. Only what is given changes.

**Do not use for** - creating a product (→ `somcp_create_product`), switching one on or off alone (→ `somcp_set_product_active`), or deleting one (→ `somcp_delete_product`).

**Parameters** - `id_product` (required), `languages`, then the groups and fields of `somcp_create_product`, all optional. Every rule of that entry applies unchanged: who fills each field (merchant, merchant from the shop, assistant, both), the lengths (name 128, summary at most the shop's Max size of short description with 100 to 200 as advice, reference 50, SEO title 55, meta description 512, counted on the visible text with spaces), the identifiers taken from the `somcp_list_*` tools, the SEO section and the image download guards. The tool extends `SomcpToolCreateProduct` and calls its checks, so the two cannot drift apart.

**What "only what is given" means**

| Kind of field | Given | Left out |
|---|---|---|
| a single value (`price_tax_excl`, `weight`, `active`...) | replaced | kept |
| a translatable text | replaced in the languages filled, the others kept | kept in every language |
| a set chosen as a whole in the back office: `categories`, `related_products`, `features`, `attachments`, `carriers`, `customization` fields | the set given replaces the old one entirely | kept |
| `combinations` | an item with `id_product_attribute` changes that combination, field by field; an item without one creates a combination | kept |
| `images` | an item with `id_image` changes its legend, cover, position or combinations, and may carry the `download_ref` `somcp_get_product` returns for that image, which is ignored (a `url`, an `upload_ref` or the `download_ref` of another image beside an `id_image` is refused); an item with `url` or `upload_ref` adds an image | kept |
| `specific_prices` | an item with `id_specific_price` changes it, field by field; an item without one adds it; `use_default_priorities` `true` removes the product's own order of priorities (the one statement `RemoveSpecificPricePriorityForProductCommand` runs, `DELETE` on `specific_price_priority` for the product), a change only when it had one, and takes `priorities` only when it is the shop's default order, as the creation does, so a product that follows the default read back changes nothing; `false` with `priorities` sets an order of its own, `false` alone on a product following the default writes that default as its own | kept |
| `suppliers` | per supplier and combination, the values given replace the old ones | kept |
| `pack.items` | the list given replaces the pack's contents entirely, compared with the `pack` table first so the same list writes nothing; refused on a product that is not a pack | kept |
| `virtual_file` | a `file` given replaces the file on disk (the old one deleted once the new one is written, as the back office does) and always counts as a change; the other fields change alone; `file` and `display_filename` are required when the product has no file yet; refused on a product that is not virtual | kept |

Removing is always explicit, never inferred from a missing item: `remove_combinations`, `remove_images`, `remove_specific_prices`, `remove_suppliers` (lists of identifiers; a supplier is removed from the product and from all its combinations), `remove_pack_items` (a list of `{id_product, id_product_attribute}` as `pack.items` reads them, refused next to `pack`) and `remove_virtual_file` (`true` deletes the file and its `product_download` row with `ProductDownload::delete()`, refused next to `virtual_file`). A removed combination takes its stock with it and a removed file can no longer be downloaded by those who bought it, so the assistant names each one to the merchant before the call.

`languages` left out means every active language, as at creation, so a text must then be given in each one; to change one language, `languages` names it alone.

The `combination` of a specific price or a supplier, and the `combinations` of an image, are positions in the `combinations` of the call, as at creation. An item holding only an `id_product_attribute` points at an existing combination without changing it. A specific price or a supplier row can instead name an existing combination by the `id_product_attribute` `somcp_get_product` gives it, so a rule or a row comes back as it was read; one of the two per item, never both (`<path>: combination or id_product_attribute, not both`), and a combination of another product, or one in `remove_combinations`, is refused with the path of the field. An image names the combinations it illustrates by `combinations` (positions) or, instead, by `id_product_attributes` (existing combinations of this product, from `somcp_get_product`), one of the two per image, never both (`images[<i>]: combinations or id_product_attributes, not both`), so an image given back the combinations it already shows is no change; an empty list means it illustrates none, and a combination that is not of this product is refused (`images[<i>].id_product_attributes: no combination of this product has the identifier <m> - find it with somcp_get_product`), and so is one in `remove_combinations`, which the call deletes before it links the images (`images[<i>].id_product_attributes: combination <m> is also in remove_combinations`). `somcp_create_product` keeps positions only.

**Returns** - `changed: true`, `id_product`, `changes` (each field written: `field`, `old`, `new`; an item added or removed says so), `seo_warnings` for the languages whose texts changed, `images` (per item: `removed`, `changed`, the `id_image` of an image added, or the reason it was refused), `pack_items` (the pack as written, per item `added` or the reason it was refused; `null` when unchanged), `pack_quantity` (for a pack counted from its products, its quantity recounted from them; `null` otherwise), `packs` (the packs counted from their products that hold this product, each `id_product` and its recounted `quantity`), `virtual_file` (`added`, `changed`, `removed` or the reason it was refused; `null` when unchanged), and the product as `somcp_get_product` returns it. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- `id_product` that does not exist in this shop, or an `id_product_attribute`, `id_image`, `id_specific_price` that does not belong to it: refused, nothing written.
- A `reference` changed to one another product or combination uses: refused, as at creation. The product's own references do not count: a combination often has the reference of its product, the demo data included.
- `product_type` can change as in the back office, except away from `combinations` while the product still has some: they would be lost silently. Remove them first, explicitly. Away from `pack` while it has contents, or from `virtual` while it has a file, is refused too: those are removed first, with `remove_pack_items` or `remove_virtual_file` in a call of their own. Adding combinations to a standard product makes it a combinations product, as at creation.
- The attributes of an existing combination do not change: the back office does not change them either. Remove the combination and add another.
- The default combination moves with `default_on: true` on another one; `default_on: false` on the current default alone is refused. When the default one is removed, the first combination left becomes the default.
- With combinations, `stock.quantity` and `stock.location` are refused: each combination has its own, and the core writes a product location on every combination row.
- The stock fields of the creation (`minimal_quantity`, `low_stock_threshold`, `low_stock_alert`, `pack_stock_type`, the last on a pack only) and `pricing.ecotax_tax_excl` are taken with its rules; the low stock level and alert of one combination change through its item in `combinations`, like `minimal_quantity`.
- `link_rewrite` is only written in the languages given, never made again from a new name. Changed on an active product, the old URL stops working: the answer says so in `seo_warnings`, and the assistant tells the merchant before the call.
- `tags` replace the product's tags in the languages given: the language's old tags go and the given ones take their place. A language left out keeps its tags, and a language whose tags are already exactly those given is not rewritten (it does not count as a change). Format and limits are those of `somcp_create_product`.
- Two specific prices that would apply to the same customers, combination, quantity and dates are refused before anything is written: the database accepts only one.
- Every check runs before the first write, so a call refused for a reason the shop can foresee changes nothing. Everything or nothing cannot hold past that point: undoing the creation means deleting the product, which an update cannot do, and a database transaction does not hold either, because hooks commit it - `ps_facetedsearch`, shipped with every shop, truncates its cache table on each product save. If a write still fails, the answer names the steps already written, and the assistant reads the product before anything else. Images come last and are reported without failing.
- After every call that writes, the product's search index is rebuilt as the back office does on each save, whatever changed: the index also holds the tags, categories, features, brand, references and combinations. A product the shop search can find (active, visible everywhere or in search) is indexed when Search > Indexation is on; one it can no longer find leaves the index.
- Written through the core classes (`Product::update()`, `Combination`, `StockAvailable`, `SpecificPrice`, `ProductSupplier`, `Image`) so hooks fire, in the shop of the token only: on a multistore installation the other shops keep their prices, texts and options.
- Each value is compared with what the shop holds before writing: calling it again with the same values changes nothing and says `changed: false`, except for an image added by `url`, which would be added twice. The read tool to check first is `somcp_get_product`.

#### `somcp_set_product_active`
**State**: written
**Nature**: `acts(EDIT, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - switches one product on or off on the storefront: the **Status** toggle of the back office product list (Catalogue > Products).

**Do not use for** - deleting a product (→ `somcp_delete_product`), or any other change (→ `somcp_update_product`, which can also set `active` along with other fields).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `id_product`, `active`. When the product is already in that state: `changed: false` with a `reason`, and nothing is written.

**Notes**
- Kept apart from `somcp_update_product` on purpose: "switch this product off" must touch the status and nothing else, the way the toggle does.
- The core's own sequence (`ProductStatusUpdater` on 8, `BulkUpdateProductStatusHandler` on 9): the `active` field alone, written through `Product::update()` so hooks fire, then the search index updated when the shop indexes products.
- Written in the shop of the token (`product_shop`), so on a multistore installation the product stays as it was in the other shops.
- `id_product` that does not exist in this shop: refused, nothing written. `active` must be a boolean: a string such as `"false"` is refused rather than read as true.
- Switching on a product with no name in the shop default language is refused, as PrestaShop 9 refuses it, on 8 too.
- Not replayable: the read tool to check is `somcp_get_product`.

#### `somcp_delete_product`
**State**: written
**Nature**: `acts(DELETE, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one product from the shop of the token, with its combinations, images and stock there, as the back office does.

**Do not use for** - hiding a product from the storefront, which is reversible (→ `somcp_set_product_active`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |

**Returns** - `deleted`, `id_product`, `name` (in the default language of the token's shop, given as `language`), `reference`, and `still_in_shops`: how many other shops still carry the product (0 when it is gone for good).

**Notes**
- Irreversible. The description tells the assistant to ask the merchant first, naming the product by its **name and reference** (read with `somcp_get_product`), and to offer switching it off instead.
- A product that has been ordered is deleted like any other: PrestaShop handles it, and orders keep their lines with the product's name, reference and price as sold.
- Multistore: the shop comes from the token, never from an argument, so the tool deletes in that shop only (`id_shop_list` set to it before `Product::delete()`). PrestaShop removes the product completely only when no shop carries it any more. When `still_in_shops` is above 0, the assistant tells the merchant, who removes it elsewhere from a connection to each of those shops.
- Through `Product::delete()`, so hooks fire and other modules clean up after it.
- `id_product` that does not exist in this shop, or a product already deleted: refused, nothing deleted. A second call never reads as a success.
- `Product::delete()` also answers false when a clean-up step after the removal fails. Whether the product is still in the shop is read back from `product_shop` rather than inferred, and the answer says which of the two happened.
- Not replayable: the read tool to check is `somcp_search_products`, since `somcp_get_product` has nothing to read once the product is gone.

#### `somcp_list_categories`
**State**: written
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.catalog.read`

**Does** - lists the categories of this shop: identifier and name, nothing else.

**Do not use for** - everything about one category (→ `somcp_get_category`), or the products of a category (→ `somcp_search_products` with `id_category`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - per category: `id_category`, `name`. Plus `count`.

**Notes**
- Kept that short on purpose, as the maintainer decided: it is the list an assistant reads before any write that takes a category, which must never guess an identifier.
- Two categories can share a name in different branches (Men > T-shirts, Women > T-shirts): the assistant reads `somcp_get_category` to tell them apart before writing.
- The root category is left out: nothing can be placed in it. Home is listed.
- Read through a query on `category`, `category_shop` and `category_lang` in the shop of the token, rather than `Category::getSimpleCategories()`, which takes the shop from the global context. Inactive categories are listed too, in tree order (`nleft`), so each one follows its parent.

#### `somcp_get_category`
**State**: written
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office category form shows for one category.

**Do not use for** - the list of categories (→ `somcp_list_categories`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_category` | integer | yes | from `somcp_list_categories` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_category`, `name`, `active` (Displayed), `id_parent` and `parent_name`, `level`, `position`, `description`, `additional_description`, `groups` (id and name of each customer group with access), `link_rewrite`, `url`, `meta_title`, `meta_description`, `meta_keywords`, `cover_image`, `thumbnail_image` (the **Category thumbnail**) and `menu_thumbnails` (a `download_ref` each, `null` when there is none), `products_count`, `children_count`, and on PrestaShop 9 `redirect_type` with `id_type_redirected`.

**Notes**
- Columns of `category`, `category_lang`, `category_group`, checked against PrestaShop 8.0.3 and 9.2. `meta_keywords` exists on PrestaShop 8 only and is `null` on 9; `redirect_type` and `id_type_redirected` exist on 9 only and are `null` on 8; `menu_thumbnails` exist on 8 only and are `null` on 9.
- Read in the shop of the token: a category of another shop, or an identifier that does not exist, is answered like one that does not exist. `products_count` and `children_count` count the products and subcategories of this shop only.
- Read by the same method `somcp_create_category` and `somcp_update_category` answer with, and checked by the same test of the shop as `somcp_update_category`, so the three tools name every field alike and agree on what exists.

#### `somcp_get_category_kpis`
**State**: written
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.catalog.read`

**Does** - returns the four figures shown above the category list in Catalog > Categories: Disabled Categories, Empty Categories, Top Category (last month) and Average number of products per category, computed as the back office computes them, for the shop of the token.

**Do not use for** - the list of categories (→ `somcp_list_categories`), or one category and its product count (→ `somcp_get_category`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code the top category name is read in, refused when the shop does not have it; the shop default when left out; given back as `language` |

**Returns** - `disabled_categories` (integer), `empty_categories` (integer, Home counted when it holds no product directly), `top_category` (`id_category`, `name`, `sales_tax_excl`, `currency` the ISO code of the shop default currency, `date_from`, `date_to` as `YYYY-MM-DD`; `null` when nothing sold in the period), `products_per_category` (integer, rounded) and `language` (the ISO code the name was read in).

**Notes**
- The four queries of `controllers/admin/AdminStatsController.php` (`getDisabledCategories` line 163, `getEmptyCategories` line 315, `getBestCategory` line 357, `getTotalProducts` / `getTotalCategories` lines 195 and 174), at the same lines on 8.0.1 and 9.0.0, run at each call; the back office KPI cache (`ConfigurationKPI`) is never read or written.
- Two differences from the screen, so a multistore shop stays correct: the top category is restricted to `orders.id_shop` of the token (the back office query is not), and each sold line is divided by its own order's `conversion_rate` before summing (the back office divides a product's whole sum by one order's rate). On a single-currency shop the figures match the screen.
- The top category counts every invoiced order line of the period whatever the order's current status, as the back office counts it. The back office query names a category even when nothing sold; this answers `top_category: null` when the best sum is null or zero.
- An unknown argument or a `language` the shop does not have is refused in the envelope of #169 with `Nothing was read.`; nothing is written, nothing to retry.

**Differences between 8.0.1 and 9.0.0** - none.

#### `somcp_create_category`
**State**: written
**Nature**: `acts(ADD, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - creates one category with every field of the back office category form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing a category (→ `somcp_update_category`), or switching it on or off (→ `somcp_set_category_active`).

**Parameters** - the fields of `somcp_get_category`, except those computed by PrestaShop (`id_category`, `parent_name`, `level`, `position`, `url`, counts). The rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; forbidden characters `<>{}`, and `;=#` before PrestaShop 9.1 (`Validate::isCatalogName` of the running version) |
| `id_parent` | merchant, from the shop | from `somcp_list_categories`; Home (the top category of the shop of the token) when left out; never the root |
| `active` (Displayed) | merchant | true by default |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office; 65 535 bytes at most on PrestaShop 8 (21 844 characters in the back office form), refused past that rather than stored cut |
| `additional_description` (additional information) | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office; 65 535 bytes at most on PrestaShop 8 (21 844 characters in the back office form), refused past that rather than stored cut |
| `groups` (group access) | merchant, from the shop | ids from `somcp_list_customer_groups`, or the `{"id_group", "name"}` objects `somcp_get_category` returns (the schema takes both, the name is ignored); every group when left out, as in the back office; an empty list is refused rather than read as every group |
| `link_rewrite` | assistant | generated from the name when left out; `Tools::str2url()` then `Validate::isLinkRewrite()`; 128 characters at most |
| `meta_title` | assistant | 255 characters at most; past 70, a search result cuts it |
| `meta_description` | assistant | 512 characters at most; past 160, a search result cuts it |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}`; 255 characters at most |
| `redirect_type` | assistant | PrestaShop 9 only, refused on 8 with a clear message: what the page does while not displayed, a value of the core's `RedirectType` (`301`, `302`, `404`, `410`); the shop default when left out |
| `id_type_redirected` | merchant, from the shop | PrestaShop 9 only: the category a `301` or `302` goes to, from `somcp_list_categories`; 0 or left out for the closest parent, as the storefront does; refused next to a `404` or `410` |
| `cover_image` | merchant | an object with `url` or `upload_ref` (one of the two, never both), downloaded or redeemed with the guards of `somcp_create_product`'s images |
| `thumbnail_image` | merchant | **Category thumbnail**: the small image shown where the parent category lists its subcategories, and in menus if the theme uses it; an object with `url` or `upload_ref`, same guards as the cover |
| `menu_thumbnails` | merchant | PrestaShop 8 only, refused on 9, which removed them from the form: up to 3 images, each an object with `url` or `upload_ref`, same guards |

**Returns** - `created`, `id_category`, `seo_warnings` (no meta title or description in a language, a `link_rewrite` already used by another category), `images` (per image, named by `url` or `upload_ref`: `for` - `cover_image`, `thumbnail_image` or `menu_thumbnails` - and `stored: true` or the reason it was `refused`), and `category`, with the fields `somcp_get_category` returns (`menu_thumbnails` is `null` on PrestaShop 9, like `meta_keywords`).

**Notes**
- Written through `Category::add()` in the shop of the token (`id_shop_list`), so `actionCategoryAdd` fires, the tree is rebuilt and the position and group access are set as in the back office. If the add fails after the row went in, the category is deleted again.
- The core's category image uploaders (`src/Adapter/Image/Uploader/Category*`) cannot be called: they take an uploaded file and move it with `move_uploaded_file()`, which refuses a file that did not arrive in a POST. The downloaded image is written where they write it instead, under the same names: the cover as `img/c/<id>.jpg` with one file per category image type (and each extra format switched on, from PrestaShop 8.1, with the fitment of each type on 9), a menu thumbnail as `img/c/<id>-<slot>_thumb.jpg`, slots 0 to 2, after which the Smarty cache is cleared as the core does. The **Category thumbnail** is written as each version's `CategoryThumbnailImageUploader` writes it: on PrestaShop 8, `img/c/<id>-<small type>.jpg` at the size of the category image type named `small` (the same file the cover writes for that type, so the last one written wins, as in the back office), then `img/c/<id>_thumb.jpg` at the size of the image; on PrestaShop 9, the old `img/c/<id>_thumb.jpg` is deleted, the image written under that name, then `img/c/<id>_thumb-<type>.<format>` for every category image type and generation format.
- Every problem is reported at once, before anything is written; images are fetched once the category exists, and one refused does not undo it.
- Not replayable: the read tool to check is `somcp_list_categories`.
- The forbidden characters are PrestaShop's own rules, read from the running version: `isCatalogName` for the name (`<>;=#{}` up to 9.0, `<>{}` from 9.1), `isGenericName` for the meta fields (`<>={}` up to 9.0, `<>{}` from 9.1); the descriptions are rich text and follow `isCleanHtml`, as the back office form does.

#### `somcp_update_category`
**State**: written
**Nature**: `acts(EDIT, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - changes one category with everything `somcp_create_category` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_category`), or only switching it on or off (→ `somcp_set_category_active`).

**Parameters** - `id_category` (required), `languages`, then the fields of `somcp_create_category`, all optional, and `position` (integer: the place among the categories of the same parent, from 0 as `somcp_get_category` returns it; the back office list shows it plus 1). A text given replaces the languages given and keeps the others; `groups` given replaces the whole set; `cover_image` given replaces the cover and `thumbnail_image` the **Category thumbnail**; `menu_thumbnails` given replaces the existing ones, and `remove_cover_image` / `remove_thumbnail_image` / `remove_menu_thumbnails` (booleans, `remove_thumbnail_image` on PrestaShop 9 only, which has a Delete button for it, `remove_menu_thumbnails` on PrestaShop 8 only) remove explicitly, never next to the field that already replaces them. The cover, thumbnail and menu thumbnails `somcp_get_category` returns, given back unchanged (their `download_ref`, bare or `{"download_ref"}`, `null` for none), are no change: nothing is downloaded, written or listed in `changes` (see **Files given back**).

**Returns** - `changed`, `id_category`, `changes` (per field its `field`, `old` and `new` value; a text names its language; `position` gives the old and new place), `seo_warnings` for the languages whose texts changed, `images` (per image: `for`, and `stored`, `removed` or the reason it was `refused`), and `category`, as `somcp_get_category` returns it. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- Home, the top category of the token's shop (`$shop->id_category`), is changed with the fields of its own form in the back office (**Edit** above the list): every field but `id_parent` and `position`, refused on it since a moved top would leave the tree unreachable; on PrestaShop 9 it only takes `redirect_type` `404` or `410`, and `id_type_redirected` is refused, as its form offers no redirection. The root category (`PS_ROOT_CATEGORY`, `id_parent` 0) and the Home of another shop are refused, as the back office refuses them. A category cannot be moved under itself or one of its children, checked by walking up `id_parent` rather than trusting `nleft` / `nright`.
- Written through `Category::update()` in the shop of the token (`id_shop_list`), so `actionCategoryUpdate` fires, and a new parent has the level, positions and tree worked out by the core. Group access is only rewritten when `groups` is given.
- `position` is written after, with `Category::updatePosition()`, what the drag and drop of the list ends in: the other categories of the parent shift and `actionCategoryUpdate` fires. It is refused outside 0 to the number of other categories under the parent; given with `id_parent`, it is the place under the new parent.
- `link_rewrite` is only written in the languages given, never made again from a new name. Changed on a displayed category, the old URL stops working: said in `seo_warnings`.
- On PrestaShop 9, a target left from a `301` or `302` is cleared when `redirect_type` becomes `404` or `410`, since the core accepts a target only next to a redirection; `id_type_redirected` alone keeps the current `redirect_type`.
- Every problem is reported at once, before anything is written. Images come after the write, as at creation: a new cover is written over the current files, so a refused one leaves the old cover in place; menu thumbnails given replace the current ones, which are deleted first. A removed cover goes through `ObjectModel::deleteImage()`, as in the back office; a removed thumbnail deletes the files `DeleteCategoryThumbnailImageHandler` deletes: `img/c/<id>_thumb.jpg`, its copy in `img/tmp/`, and `img/c/<id>_thumb-<type>.<format>` for every category image type and format.
- Not replayable: the read tool to check is `somcp_get_category`.

#### `somcp_set_category_active`
**State**: written
**Nature**: `acts(EDIT, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - shows or hides one category on the storefront: the **Displayed** toggle of the back office category list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_category`), or deleting the category (→ `somcp_delete_category`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_category` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `id_category`, `active`. When the category is already in that state: `changed: false` with a `reason`, and nothing is written.

**Notes**
- Hiding a category does not switch its products off: they stay reachable through search and their other categories. The description says so, so the merchant is not surprised.
- Root and Home are refused (a hidden Home leaves the storefront tree unreachable), by the check `somcp_update_category` uses for the root and the Home of another shop; a category of another shop, or an identifier that does not exist, is refused like one that does not exist, by the same test of the shop.
- The core's own sequence (`SetCategoryIsEnabledHandler`, through `ObjectModel::toggleStatus()`, on 8 and 9): the `active` field alone, written through `Category::update()` so `actionCategoryUpdate` fires. The value asked for is written rather than flipped.
- Multistore: PrestaShop keeps Displayed in `category`, not per shop, so the change shows in every shop sharing the category, as the back office toggle does. The description says so. Positions are only touched in the shop of the token (`id_shop_list`).
- `active` must be a boolean: a string such as `"false"` is refused rather than read as true. Only a whole number is an identifier.
- Not replayable: the read tool to check is `somcp_get_category`.


#### `somcp_delete_category`
**State**: written
**Nature**: `acts(DELETE, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one category with all its subcategories, as the **Delete** action of **Catalog > Categories** does, and settles the products left without a category the way the merchant chose in the dialog "What do you want to do with the products associated with this category?".

**Do not use for** - hiding a category (→ `somcp_set_category_active`), moving it (→ `somcp_update_category` with `id_parent`), deleting one product (→ `somcp_delete_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_category` | integer | yes | from `somcp_list_categories`, never guessed |
| `products` | string | yes | `associate_and_disable`, `associate_only` or `remove_associated` |

| On screen (8.0.1 and 9.0.0) | `products` |
|---|---|
| **If they have no other category, I want to associate them with the parent category and turn them offline. (Recommended)** | `associate_and_disable` |
| **If they have no other category, I want to associate them with the parent category.** | `associate_only` |
| **If they have no other category, I want to delete them as well.** | `remove_associated` |

`products` is required although the screen checks the first choice: the deletion cannot be undone, so the merchant's choice is given, never assumed.

**Returns** - `deleted: true`, `id_category`, `name`, `id_parent`, `parent_name` (read before the deletion, in the default language of the token's shop), `deleted_subcategories` (`id_category` and `name` of each), the products the call touched, each as `id_product` and `name`: `products_moved` (to the parent), `products_disabled`, `products_deleted`, `products_default_category_changed`, and `still_in_shops`, how many other shops still have the category. `products_not_settled` lists the products that could not be moved, switched off or deleted, when there are any. `subcategories_not_deleted` (`id_category` and `name`) lists the subcategories still in the shop after the call, which `deleted_subcategories` then leaves out, and `products_cleanup_incomplete` the products removed from the shop whose clean-up failed (also in `products_deleted`), each only when there are any and each with a sentence in `warnings`.

**Notes**
- Irreversible. The description makes the assistant read the category with `somcp_get_category` (`children_count`, `products_count`), tell the merchant how many subcategories go with it and what the chosen mode does to products, then confirm by name.
- Through `Category::delete()`, so every subcategory goes with it, with its images, group access, product links, cart rule restrictions and group reductions, and `actionCategoryDelete` fires.
- Only the products of the deleted categories are touched. The core handlers act on every product of the shop that has no category after the deletion, including ones that had none before; the tool reads the products linked to the category and its subcategories (`category_product`, and those whose default category is one of them) before the deletion, and acts on those only.
- A product left in no category is moved to the parent of the deleted category, which becomes its default (Home when the parent is not in this shop), and switched off with `associate_and_disable`; with `remove_associated` it is deleted the way `somcp_delete_product` deletes one (shop of the token, gone checked after). When `Product::delete()` fails after the product left the shop, it is reported with the sentence `somcp_delete_product` gives (`SomcpToolDeleteProduct::CLEANUP_INCOMPLETE`).
- `Category::delete()` answers true even when a subcategory stayed, so the category and each of its subcategories are asked of the shop after the call; one still there is never announced as deleted.
- The three modes are checked in `dev/shop-tests/run.php` on a category, a subcategory and a product built there, since their identifiers differ from one version to the other.
- A product that keeps other categories but whose default category was deleted gets the parent as its new default, and is added to it, as 9.0.0 does; 8.0.1 leaves it pointing at a category that no longer exists, the tool does the same as 9.0.0 on both.
- Root and Home are refused, by the check `somcp_update_category` uses; a category of another shop, or an identifier that does not exist, is refused like one that does not exist, so a second call never reads as a success.
- Multistore: `id_shop_list` set to the shop of the token. `Category::delete()` only removes the shared rows when no other shop still has the category; `still_in_shops` says when other shops still have it.
- Unknown arguments are refused, in the envelope `Nothing was deleted. Correct these and call again:`.
- Not replayable: the read tool to check is `somcp_list_categories`.

#### `somcp_list_manufacturers`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the brands of this shop as **Catalog > Brands & Suppliers > Brands** shows them: identifier, name, **Enabled**, **Addresses** and **Products**.

**Do not use for** - everything about one brand, its logo included (→ `somcp_get_manufacturer`), or the products of a brand (`somcp_search_products` has no brand filter; `somcp_get_product` names the brand of one product).

**Parameters** - all optional, the filters of the list:
- `active` (boolean) - `true` for enabled brands only, `false` for disabled ones only; left out, all of them (**All**).
- `name` (string) - part of the brand name, case-insensitive (the **Name** filter).

**Returns** - per brand: `id_manufacturer`, `name`, `active` (**Enabled**), `addresses_count` (**Addresses**), `products_count` (**Products**). Plus `count`. Every brand matching the filters is returned, with no paging: the list stays short.

**Notes**
- The list an assistant reads before a write that takes a brand, which must never guess an identifier. Disabled ones are listed too unless `active` is given, since a product can still point to them.
- `addresses_count` leaves out deleted addresses (`address.deleted = 1`), kept by PrestaShop for the orders that used them and hidden from the list. `products_count` counts the products of the shop of the token whose brand this is, the same count as `somcp_get_manufacturer`.
- Same columns, labels and filters on PrestaShop 8.0.1 and 9.0.0. Not here: **Delete** and **Delete selected** (no tool deletes a brand yet), and the **Logo** (read with `somcp_get_manufacturer`).
- No `language` parameter, unlike the first draft of this entry: a brand name is not translated in PrestaShop (`Manufacturer::$name` has no `lang`, checked on 8.0.0 and 9.0.0), so it would change nothing.
- Read through a query on `manufacturer` and `manufacturer_shop` in the shop of the token, rather than `Manufacturer::getManufacturers()`, which takes the shop from the global context and leaves out a brand with no row in the language asked for. Sorted by name, as the core does.

#### `somcp_get_manufacturer`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office brand form shows for one brand.

**Do not use for** - the list (→ `somcp_list_manufacturers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | yes | from `somcp_list_manufacturers` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_manufacturer`, `name`, `short_description`, `description`, `meta_title`, `meta_description`, `meta_keywords`, `logo` (a `download_ref`, `null` without one), `active`, `products_count`.

**Notes**
- Columns of `manufacturer` and `manufacturer_lang`, checked against PrestaShop 8.0.0 and 9.0.0; `meta_keywords` exists on PrestaShop 8 only and is `null` on 9. A brand's postal addresses are a separate back office list and are not part of the brand form.
- Read in the shop of the token: a brand of another shop, or an identifier that does not exist, is answered like one that does not exist (`manufacturer_shop`), since the `Manufacturer` constructor takes no shop. `products_count` counts the products of this shop only.
- `logo` is `null` when the brand has none, rather than the "no picture" image `Link::getManufacturerImageLink()` falls back on.
- The reader and the test of the shop are public, for the brand write tools to answer with the brand as this tool returns it.

#### `somcp_create_manufacturer`
**State**: written
**Nature**: `acts(ADD, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - creates one brand with every field of the back office brand form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing one (→ `somcp_update_manufacturer`), or switching it on or off (→ `somcp_set_manufacturer_active`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; one string, the same in every language (`Manufacturer::$name` has no `lang`); 64 characters at most; forbidden characters `<>{}`, and `;=#` before PrestaShop 9.1 (`Validate::isCatalogName` of the running version) |
| `short_description` | assistant | rich text, cleaned by `Validate::isCleanHtml()`; 65 535 bytes at most on PrestaShop 8 (21 844 characters in the back office form), refused past that rather than stored cut |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()`; 65 535 bytes at most on PrestaShop 8 (21 844 characters in the back office form), refused past that rather than stored cut |
| `meta_title` | assistant | forbidden characters `<>{}`, and `=` before PrestaShop 9.1 (`Validate::isGenericName`); 255 characters at most; past 70, a search result cuts it |
| `meta_description` | assistant | same characters; 512 characters at most; past 160, a search result cuts it |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}`; 255 characters at most |
| `logo` | merchant | an object with `url` (a public `https` address) or `upload_ref` (a file already on the shop), one of the two, as the `images` of `somcp_create_product`, fetched and checked by the same shared function |
| `active` | merchant | true by default; a boolean, a string such as `"false"` is refused |

**Returns** - `created`, `id_manufacturer`, `seo_warnings` (no meta title or description in a language), `logo` (`null` when none was given, otherwise its `url` or `upload_ref` with `stored: true` or the reason it was `refused`), and `manufacturer`, the brand as `somcp_get_manufacturer` returns it.

**Notes**
- Written through `Manufacturer::add()` in the shop of the token (`id_shop_list`), as the core's `AddManufacturerHandler` does, so the object hooks fire. If the add fails after the row went in, the brand is deleted again.
- The core's `ManufacturerImageUploader` cannot be called: it moves the file with `move_uploaded_file()`, which refuses a file that did not arrive in a POST. The logo, downloaded or redeemed, is written where it writes it instead, as `img/m/<id>.jpg` with one file per brand image type (and each extra format switched on, from PrestaShop 8.1, with the fitment of each type on 9), and the cached logo of the back office list is dropped as the uploader does. A logo refused is reported and does not undo the brand.
- PrestaShop accepts two brands of the same name: the description tells the assistant to read `somcp_list_manufacturers` first.
- Every problem is reported at once, before anything is written. The rich texts, meta texts and meta keywords are checked by the same methods as `somcp_create_category`, with the limits of `Manufacturer::$definition`.
- Not replayable: the read tool to check is `somcp_list_manufacturers`.

#### `somcp_update_manufacturer`
**State**: written
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one brand with everything `somcp_create_manufacturer` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_manufacturer`), or only switching it on or off (→ `somcp_set_manufacturer_active`).

**Parameters** - `id_manufacturer` (required), `languages`, then the fields of `somcp_create_manufacturer`, all optional. A text given replaces the languages given and keeps the others. `remove_logo` removes the logo explicitly.

**Returns** - `changed`, `changes` (per field its old and new value, a text per language), `seo_warnings` (for the languages whose texts changed), `logo` (`null` when untouched, otherwise its `url` or `upload_ref` with `stored: true`, `removed: true` or the reason it was `refused`), and `manufacturer`, the brand as `somcp_get_manufacturer` returns it.

**Notes**
- Extends `somcp_create_manufacturer`: every field is checked by the same code, and the logo fetched with the same guards and written where the creation writes it, over the current files, so a logo that cannot be had leaves the brand with the one it had.
- An `id_manufacturer` that does not exist, or a brand of another shop, is refused by the same test of the shop as `somcp_get_manufacturer`.
- Written through one `Manufacturer::update()` in the shop of the token (`id_shop_list`), as the core's `EditManufacturerHandler` does, so the object hooks fire; left to the context, the update could attach the brand to a shop it was never in.
- `remove_logo` deletes what the core's `DeleteManufacturerLogoImageHandler` deletes: the original, every brand image type in every format, and the two cached copies of the back office. It is refused next to `logo`, and counts as a change only when the brand has a logo.
- Replayable: the same values again change nothing, fire no hook and say `changed: false`. A logo given always counts as a change, since an address or an `upload_ref` cannot be compared with a stored file; `changes` names it by its `url` or `upload_ref`.

#### `somcp_set_manufacturer_active`
**State**: written
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - switches one brand on or off: the **Enabled** toggle of the back office brand list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_manufacturer`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | yes | from `somcp_list_manufacturers` |
| `active` | boolean | yes |  |

**Returns** - `changed`, `id_manufacturer`, `active`. When it is already in that state: `changed: false` with a `reason`, and nothing is written.

**Notes**
- Switching a brand off hides its page on the storefront; its products stay on sale and keep their brand. The description says so, so the merchant is not surprised.
- A brand of another shop, or an identifier that does not exist, is refused like one that does not exist, by the same test of the shop as `somcp_get_manufacturer`.
- The core's own sequence (`ToggleManufacturerStatusHandler`, on 8 and 9): the `active` field set, then the brand saved, so the object hooks fire. Here the save is one `Manufacturer::update()` narrowed to `active`, so the texts of the brand as loaded are not written back. The value asked for is written rather than flipped.
- Multistore: PrestaShop keeps Enabled in `manufacturer`, not per shop, so the change shows in every shop sharing the brand, as the back office toggle does. The description says so. The update runs in the shop of the token (`id_shop_list`), so it never attaches the brand to another shop.
- `active` must be a boolean: a string such as `"false"` is refused rather than read as true. Only a whole number is an identifier.
- Not replayable: the read tool to check is `somcp_get_manufacturer`.

#### `somcp_list_manufacturer_addresses`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the postal addresses of the brands: the **Brand addresses** list of the back office.

**Do not use for** - the brands themselves (→ `somcp_list_manufacturers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | no | the addresses of one brand only |
| `lastname` | string | no | part of the **Last name**, case-insensitive |
| `firstname` | string | no | part of the **First name**, case-insensitive |
| `postcode` | string | no | part of the **Zip/Postal code** |
| `city` | string | no | part of the **City**, case-insensitive |
| `country` | string | no | ISO code of the **Country**, for example `FR` |
| `language` | string | no | ISO code of one of the shop's active languages; a code the shop does not have is refused, among the other problems of the call with `SomcpTool::languageProblem()`. The shop default language when left out. |

**Returns** - `addresses`, per address: `id_address`, `id_manufacturer`, `manufacturer_name`, `lastname`, `firstname`, `postcode`, `city`, `country` (ISO code, `null` when the country no longer exists), `country_name` (the name the **Country** column shows, in `language`, `null` when the country no longer exists). Plus `count` and `language`. An `id_manufacturer` that is not a brand of this shop, a `country` code no country has and a `language` the shop does not have are errors, not an empty list.

**Notes**
- Rows of `address` with `id_manufacturer` set and not deleted. Brand addresses are managed under the brands page, so the permission is the one on `AdminManufacturers`, as in the back office.
- `address` has no shop column: an address belongs to the shop of its brand, so only the addresses of brands in `manufacturer_shop` for the shop of the token are listed, and `id_manufacturer` is checked by the same test of the shop as `somcp_get_manufacturer`.
- The filters are those of the **Addresses** list, the same on PrestaShop 8.0 and 9.0: the text columns match a part of the value, **Country** one country. They combine with each other and with `id_manufacturer`.
- Read through a query on `address`, `manufacturer`, `manufacturer_shop`, `country` and `country_lang`, not through `Address` objects. Sorted by brand name, then by identifier.

#### `somcp_get_manufacturer_address`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of one brand address.

**Do not use for** - the list (→ `somcp_list_manufacturer_addresses`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_address` | integer | yes | from `somcp_list_manufacturer_addresses` |

**Returns** - `id_address`, `id_manufacturer`, `manufacturer_name`, `lastname`, `firstname`, `address1`, `address2`, `postcode`, `city`, `country` (ISO code, `null` when the country no longer exists), `state` (ISO code, `null` when the address has none), `dni`, `phone`, `phone_mobile`, `other`.

**Notes**
- An `id_address` that belongs to a customer, a supplier or nobody is refused, like one that does not exist: this tool reads brand addresses only. A deleted address, kept by PrestaShop for past orders and hidden from the back office list, is refused too.
- `address` has no shop column: the address must belong to a brand of the shop of the token, checked by the same test of the shop as `somcp_get_manufacturer`.
- Read through an `Address` object. The reader and the test are public, so the brand address write tools answer with the address as this tool returns it.

#### `somcp_create_manufacturer_address`
**State**: written
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - adds one postal address to a brand, with every field of the back office brand address form.

**Do not use for** - changing one (→ `somcp_update_manufacturer_address`).

**Parameters**

| Field | Who fills it | Rule |
|---|---|---|
| `id_manufacturer` (Brand) | merchant, from the shop | required on creation, from `somcp_list_manufacturers` |
| `lastname`, `firstname` | merchant | required; `Validate::isName()`; 255 characters at most |
| `address1` | merchant | required; `Validate::isAddress()`; 128 characters at most |
| `address2` | merchant | `Validate::isAddress()`; 128 characters at most |
| `postcode` | merchant | checked against the country's format (`Country::checkZipCode()`) |
| `city` | merchant | required; 64 characters at most |
| `country` | merchant | ISO code, required, among the shop's countries |
| `state` | merchant | ISO code, required when the country uses states |
| `dni` | merchant | national ID number, required when the country asks for one (`Country::isNeedDni()`) |
| `phone`, `phone_mobile` | merchant | `Validate::isPhoneNumber()`; 32 characters at most |
| `other` | merchant | forbidden characters `<>{}` (`Validate::isMessage()`); 300 characters at most |

**Returns** - `created`, `id_address`, and under `address` the address as `somcp_get_manufacturer_address` returns it.

**Notes**
- Permission `edit` on `AdminManufacturers`: adding an address edits the brand, which is the right the back office checks.
- The core's own sequence (`AddManufacturerAddressHandler`, the same on 8 and 9): the fields of the form, the brand, and the alias `manufacturer`, which the form does not ask for; then `validateFields()` and `validateFieldsLang()` on the whole object, then `Address::add()`. Here likewise, so the hooks fire.
- The street lines, postal code, city, phones, DNI, country and state are checked by the same code as the contact details of `somcp_create_supplier`, since both are an `address` row: the rules of the running version's `Address`, the country among the shop's countries (`country_shop`), the state among the country's, the postal code against the country's format. Fields the merchant made required for addresses (Customers > Addresses, Set required fields) are required here too, as `validateFields()` reads them.
- `other`: PrestaShop 9 stores a longer text than its form accepts; the form's 300 characters is the limit held on both versions.
- An `id_manufacturer` that is not a brand of the shop of the token is refused, by the same test of the shop as `somcp_get_manufacturer`. Only a whole number is an identifier.
- Every problem is reported at once, and nothing is written until the whole call is valid.
- Not replayable: the read tool to check is `somcp_list_manufacturer_addresses`.

#### `somcp_update_manufacturer_address`
**State**: written
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one brand address with everything `somcp_create_manufacturer_address` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_manufacturer_address`).

**Parameters** - `id_address` (required), then the fields of `somcp_create_manufacturer_address`, all optional; `id_manufacturer` moves the address to another brand.

**Returns** - `changed`, `id_address`, `changes` (per field its old and new value, `country` and `state` as ISO codes), and under `address` the address as `somcp_get_manufacturer_address` returns it. When nothing would change: `changed: false` with a `reason`, and nothing is written.

**Notes**
- Only brand addresses are accepted, by the same test as `somcp_get_manufacturer_address`: a customer, supplier or deleted address, or the address of a brand of another shop, is refused like one that does not exist. Only a whole number is an identifier.
- Extends `somcp_create_manufacturer_address`: the values given are laid over the stored ones and the whole goes through the creation's checks, since the country decides whether a state or a DNI is required and which postal code format applies. A stored state is not carried over to a new country; give the state again when the new country has states. A new `id_manufacturer` must be a brand of the shop of the token.
- Only the fields given are compared and written, so a stored value the merchant did not name is never rewritten.
- The core's own sequence (`EditManufacturerAddressHandler`, the same on 8 and 9): the fields given set on the stored address, then `validateFields()` and `validateFieldsLang()`, then `Address::update()`, so the hooks fire. The alias is left as it is.
- Every problem is reported at once, and nothing is written until the whole call is valid.
- Replayable: the same values again change nothing, fire no hook and say `changed: false`.

#### `somcp_delete_manufacturer_address`
**State**: written
**Nature**: `acts(DELETE, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one brand address, as the delete action of the back office **Brand addresses** list does.

**Do not use for** - anything else than a brand address: customer and supplier addresses are refused. Changing one (→ `somcp_update_manufacturer_address`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_address` | integer | yes | from `somcp_list_manufacturer_addresses` |

**Returns** - `deleted`, `id_address`, `manufacturer_name`, `city`.

**Notes**
- Irreversible: the description tells the assistant to read the address with `somcp_get_manufacturer_address` and name the brand and the city to the merchant before the call. The brand itself is kept.
- Permission `delete` on `AdminManufacturers`: the right the back office checks on its delete action for a brand address.
- Only brand addresses are accepted, by the same test as `somcp_get_manufacturer_address`: a customer, supplier or deleted address, or the address of a brand of another shop, is refused like one that does not exist. Only a whole number is an identifier.
- The core's own sequence (`DeleteAddressHandler`, the same on 8 and 9): `Address::delete()`, so hooks fire. An address an order still points to is flagged deleted rather than removed, as in the back office; either way it is gone from the list and from the brand tools.
- Whether the address is gone is read back with the test of `somcp_get_manufacturer_address` rather than inferred from `delete()`. A second call is refused, never read as a success.
- Not replayable: the read tool to check is `somcp_list_manufacturer_addresses`.

#### `somcp_list_suppliers`
**State**: written
**Nature**: `acts(VIEW, 'AdminSuppliers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the suppliers of this shop as **Catalog > Brands & Suppliers > Suppliers** shows them: identifier, name, **Number of products** and **Enabled**.

**Do not use for** - everything about one supplier, its logo included (→ `somcp_get_supplier`), or the products of a supplier (`somcp_search_products` has no supplier filter; `somcp_get_product` names the suppliers of one product).

**Parameters** - all optional, the filters of the list:
- `active` (boolean) - `true` for enabled suppliers only, `false` for disabled ones only; left out, all of them (**All**).
- `name` (string) - part of the supplier name, case-insensitive (the **Name** filter).

**Returns** - per supplier: `id_supplier`, `name`, `active` (**Enabled**), `products_count` (**Number of products**). Plus `count`. Every supplier matching the filters is returned, with no paging: the list stays short.

**Notes**
- The list an assistant reads before a write that takes a supplier, which must never guess an identifier. Disabled ones are listed too unless `active` is given, since a product can still point to them.
- `products_count` counts the products of the shop of the token in `product_supplier`, one per product, the same count as `somcp_get_supplier`: a product can have several suppliers.
- Same columns, labels and filters on PrestaShop 8.0.1 and 9.0.0. Not here: **Delete** and **Delete selected** (no tool deletes a supplier yet), and the **Logo** (read with `somcp_get_supplier`).
- No `language` parameter, unlike the first draft of this entry: a supplier name is not translated in PrestaShop (`Supplier::$name` has no `lang`, checked on 8.0.0 and 9.0.0), so it would change nothing.
- Read through a query on `supplier` and `supplier_shop` in the shop of the token, rather than `Supplier::getSuppliers()`, which takes the shop from the global context and leaves inactive suppliers out by default. Sorted by name, as the core does.

#### `somcp_get_supplier`
**State**: written
**Nature**: `acts(VIEW, 'AdminSuppliers')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office supplier form shows for one supplier.

**Do not use for** - the list (→ `somcp_list_suppliers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_supplier` | integer | yes | from `somcp_list_suppliers` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_supplier`, `name`, `description`, `phone`, `phone_mobile`, `address1`, `address2`, `postcode`, `city`, `country` (ISO code), `state` (ISO code, when the country has states), `dni` (national ID number), `meta_title`, `meta_description`, `meta_keywords`, `logo` (a `download_ref`, `null` without one), `active`, `products_count`.

**Notes**
- Columns of `supplier` and `supplier_lang`, and of the supplier's row in `address` for the contact fields, checked against PrestaShop 8.0.0 and 9.0.0; `meta_keywords` exists on PrestaShop 8 only and is `null` on 9.
- The address row is found with `Address::getAddressIdBySupplierId()`, the lookup the supplier form uses. A supplier with no such row - one created without contact details - answers with those fields empty, and `country` and `state` `null`.
- Read in the shop of the token: a supplier of another shop, or an identifier that does not exist, is answered like one that does not exist (`supplier_shop`), since the `Supplier` constructor takes no shop. `products_count` counts the products of this shop in `product_supplier`, as the back office supplier list does: a product can have several suppliers.
- `logo` is `null` when the supplier has none, rather than the "no picture" image `Link::getSupplierImageLink()` falls back on.
- The reader and the test of the shop are public, for the supplier write tools to answer with the supplier as this tool returns it.

#### `somcp_create_supplier`
**State**: written
**Nature**: `acts(ADD, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - creates one supplier with every field of the back office supplier form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing one (→ `somcp_update_supplier`), or switching it on or off (→ `somcp_set_supplier_active`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; one string, the same in every language (`Supplier::$name` has no `lang`); 64 characters at most; forbidden characters `<>{}`, and `;=#` before PrestaShop 9.1 (`Validate::isCatalogName` of the running version) |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()`; 65 535 bytes at most on PrestaShop 8 (21 844 characters in the back office form), refused past that rather than stored cut |
| `phone`, `phone_mobile` | merchant | `Validate::isPhoneNumber()`: digits, spaces and `+-.()/`; 32 characters at most |
| `address1` | merchant | required by PrestaShop for a supplier; `Validate::isAddress()`; 128 characters at most |
| `address2` | merchant | same rules, not required |
| `postcode` | merchant | `Validate::isPostCode()`, 12 characters at most, and checked against the country's format (`Country::checkZipCode()`) when it has one |
| `city` | merchant | required; `Validate::isCityName()`; 64 characters at most |
| `country` | merchant | ISO code, required, among the countries of the shop of the token (`country_shop`) |
| `state` | merchant | ISO code of a state of that country, required when the country uses states, refused when it has none |
| `dni` | merchant | national ID number, required when the country asks for one (`need_identification_number`, checked by `Address::validateField()`); `Validate::isDniLite()`, 16 characters at most |
| `meta_title` | assistant | forbidden characters `<>{}`, and `=` before PrestaShop 9.1 (`Validate::isGenericName`); 255 characters at most; past 70, a search result cuts it |
| `meta_description` | assistant | same characters; 512 characters at most; past 160, a search result cuts it |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}`; 255 characters at most |
| `logo` | merchant | an object with `url` (a public `https` address) or `upload_ref` (a file already on the shop), one of the two, as the `images` of `somcp_create_product`, fetched and checked by the same shared function |
| `active` | merchant | true by default; a boolean, a string such as `"false"` is refused |

The name and every contact field are one string each, the same in every language.

**Returns** - `created`, `id_supplier`, `seo_warnings` (no meta title or description in a language), `logo` (`null` when none was given, otherwise its `url` or `upload_ref` with `stored: true` or the reason it was `refused`), and `supplier`, the supplier as `somcp_get_supplier` returns it.

**Notes**
- Written through `Supplier::add()` in the shop of the token (`id_shop_list`), then its `Address` through `Address::add()` with `id_supplier` set and the placeholder alias, first name and last name (`supplier`) the core's `AddSupplierHandler` writes. The handler adds the address first and links it after; here it is linked from the start, so a failure in between cannot leave an address that belongs to nobody. If either add fails after a row went in, what was written is deleted again.
- Every problem is reported at once, before anything is written. The name, rich text, meta texts, meta keywords and logo are checked by the same methods as `somcp_create_manufacturer`; the contact fields by the definition of `Address` of the running version, then both whole objects by `validateFields()`, as the handler does.
- The postal code format is the one the checkout asks of a customer of that country; the back office supplier form does not check it, the specification asks for it.
- The core's `SupplierImageUploader` cannot be called: it moves the file with `move_uploaded_file()`, which refuses a file that did not arrive in a POST. The logo, downloaded or redeemed, is written where it writes it, as `img/su/<id>.jpg` with one file per supplier image type, by the code `somcp_create_manufacturer` writes a brand logo with, and the cached logo of the back office list is dropped as the uploader does. A logo refused is reported and does not undo the supplier.
- PrestaShop accepts two suppliers of the same name: the description tells the assistant to read `somcp_list_suppliers` first.
- Not replayable: the read tool to check is `somcp_list_suppliers`.

#### `somcp_update_supplier`
**State**: written
**Nature**: `acts(EDIT, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one supplier with everything `somcp_create_supplier` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_supplier`), or only switching it on or off (→ `somcp_set_supplier_active`).

**Parameters** - `id_supplier` (required), `languages`, then the fields of `somcp_create_supplier`, all optional. A text given replaces the languages given and keeps the others. `remove_logo` removes the logo explicitly.

**Returns** - `changed`, `changes` (per field its old and new value, a text per language, `country` and `state` as ISO codes), `seo_warnings` (for the languages whose texts changed), `logo` (`null` when untouched, otherwise its `url` or `upload_ref` with `stored: true`, `removed: true` or the reason it was `refused`), and `supplier`, the supplier as `somcp_get_supplier` returns it.

**Notes**
- Extends `somcp_create_supplier`: every field is checked by the same code, the contact block included, and the logo fetched with the same guards and written where the creation writes it, over the current files, so a logo that cannot be had leaves the supplier with the one it had.
- The contact details are checked as a whole: the values given are laid over the stored ones before the creation's checks run, since the country decides whether a state or a DNI is required and which postal code format applies. A stored state is not carried over to a new country; give the state again when the new country has states.
- An `id_supplier` that does not exist, or a supplier of another shop, is refused by the same test of the shop as `somcp_get_supplier`.
- Written as the core's `EditSupplierHandler` does: one `Supplier::update()` in the shop of the token (`id_shop_list`), then `Address::update()` on the supplier's address row, so the object hooks fire. A supplier with no address row gets one, added with the creation's placeholders, when contact details are given.
- `remove_logo` deletes what the core's `DeleteSupplierLogoImageHandler` deletes: the original, every supplier image type in every format, and the two cached copies of the back office. It is refused next to `logo`, and counts as a change only when the supplier has a logo.
- Replayable: the same values again change nothing, fire no hook and say `changed: false`. A logo given always counts as a change, since an address or an `upload_ref` cannot be compared with a stored file; `changes` names it by its `url` or `upload_ref`.

#### `somcp_set_supplier_active`
**State**: written
**Nature**: `acts(EDIT, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - switches one supplier on or off: the **Enabled** toggle of the back office supplier list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_supplier`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_supplier` | integer | yes | from `somcp_list_suppliers` |
| `active` | boolean | yes |  |

**Returns** - `changed`, `id_supplier`, `active`. When it is already in that state: `changed: false` with a `reason`, and nothing is written.

**Notes**
- A disabled supplier stays attached to its products, with their supplier references and purchase prices; only its page on the storefront goes. The description says so, so the merchant is not surprised.
- A supplier of another shop, or an identifier that does not exist, is refused like one that does not exist, by the same test of the shop as `somcp_get_supplier`.
- The core's own sequence (`ToggleSupplierStatusHandler`, on 8 and 9): `Supplier::toggleStatus()`, which flips `active` and saves the supplier narrowed to that field, so the object hooks fire. Here the save is the same `Supplier::update()` narrowed to `active`, but the value asked for is written rather than flipped.
- Multistore: PrestaShop keeps Enabled in `supplier`, not per shop, so the change shows in every shop sharing the supplier, as the back office toggle does. The description says so. The update runs in the shop of the token (`id_shop_list`), so it never attaches the supplier to another shop.
- `active` must be a boolean: a string such as `"false"` is refused rather than read as true. Only a whole number is an identifier.
- Not replayable: the read tool to check is `somcp_get_supplier`.

#### `somcp_list_attributes`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - lists the attribute groups (Size, Colour...) and, under each, its values: identifier, name and position, plus the type and the number of values of each group and the colour of each value of a colour group. A cascade, like the categories.

**Do not use for** - everything about one group or one value (→ `somcp_get_attribute_group`, `somcp_get_attribute_value`), or the combinations of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | no | one group only |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `attribute_groups`, per group: `id_attribute_group`, `name`, `group_type` (`select`, `radio` or `color`), `position` (0 for the first, the **Position** column), `values_count` (the **Values** column), and its `values`: `id_attribute`, `name`, `position`, `color` (`#RRGGBB`, `null` outside a group of type `color`). The fields keep the names of `somcp_get_attribute_group` and `somcp_get_attribute_value`. Plus `count`, the number of groups. An `id_attribute_group` that is not a group of this shop is an error, not an empty list.

**Notes**
- A combination is a set of `id_attribute`, never of names: names are translated and two groups can hold the same value name.
- `name` is the name the back office lists, not the public name shown on the storefront.
- Read through queries on `attribute_group`, `attribute_group_shop`, `attribute` and `attribute_shop` in the shop of the token, rather than `AttributeGroup::getAttributesGroups()` and `AttributeGroup::getAttributes()`, which take the shop from the global context. Sorted by position, as the back office does.

#### `somcp_get_attribute_group`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office attribute form for one group.

**Do not use for** - the list (→ `somcp_list_attributes`), or one value (→ `somcp_get_attribute_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | yes | from `somcp_list_attributes` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_attribute_group`, `name`, `public_name`, `group_type` (select, radio, color), `position`, `values_count`, and when `ps_facetedsearch` is installed and enabled: `url_name`, `meta_title`, `indexable`. Then what a delete of the group would take, in the shop of the token: `combinations_count` (the combinations that hold one of its values), `combinations_only_on_it` (those made only of its values, which `somcp_delete_attribute_group` deletes with their stock) and `products` (the distinct products of those combinations, each as `id_product` and `name`, in `language`), counted by `SomcpToolGetAttributeGroup::whatGoes()`, the function the delete counts with. A group that is not one of this shop is an error.

**Notes**
- Columns of `attribute_group` and `attribute_group_lang`, read through `AttributeGroup`; the group must be in `attribute_group_shop` for the shop of the token, since the constructor would load it whatever its shop. `position` is the raw column, 0 for the first.
- `values_count` counts the values of the group in `attribute_shop` for the shop of the token, the ones `somcp_list_attributes` lists.
- The faceted search fields are `null` without the module. With it, they are read from `layered_indexable_attribute_group` and `layered_indexable_attribute_group_lang_value`, the tables its form reads; a group the form was never saved for has no row there, and gives `''` and `false`, as the form shows it.

#### `somcp_create_attribute_group`
**State**: written
**Nature**: `acts(ADD, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - creates one attribute group with every field of the back office attribute form, in every language of the shop or the ones the merchant picks.

**Do not use for** - adding a value to a group (→ `somcp_create_attribute_value`), or changing one (→ `somcp_update_attribute_group`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; `Validate::isGenericName()` (no `<>={}`) |
| `public_name` | merchant | required; the name shown to customers; 64 characters at most; `Validate::isGenericName()` |
| `group_type` | merchant | required; a type PrestaShop offers: `select`, `radio`, `color`; any other text is refused, since the product page only draws these three |
| `url_name` (URL) | assistant | faceted search; the word used in filtered URLs, the name otherwise; refused unless it is already what `Tools::str2url()` makes of it, as the module's form does; 128 characters at most |
| `meta_title` (title tag) | assistant | faceted search; 128 characters at most; `Validate::isGenericName()`, since the module strips tags from it |
| `indexable` | merchant | faceted search; false when left out, as in the form; a boolean, a string such as `"false"` is refused |

**Faceted search fields.** `url_name`, `meta_title` and `indexable` are not PrestaShop's: they belong to the module `ps_facetedsearch`, which adds them to the back office form and stores them in its own tables. The module saves them from the form's POST data in its `actionAttributeGroupSave` / `actionAttributeSave` hooks, so a call that goes through the core class alone would silently drop them. The tool writes the same rows the module writes, in `layered_indexable_attribute_group`, `layered_indexable_attribute_group_lang_value` and `layered_indexable_attribute_lang_value`. When the module is not installed and enabled, these fields are refused with a clear message and the rest is written; the `null` the get tools return for them then is skipped, so their answer can be given back.

**Returns** - `created`, `id_attribute_group`, and `attribute_group`, the group as `somcp_get_attribute_group` returns it. `faceted_search` appears only when the faceted search rows could not be written; the group is kept then.

**Notes**
- Written through `AttributeGroup::add()` in the shop of the token (`id_shop_list`), as the core's `AddAttributeGroupHandler` does, so the hooks fire; `add()` places the group last and sets the colour flag from `group_type`. If the add fails after the row went in, the group is deleted again.
- `ps_facetedsearch` reads its fields from the request in its `actionAttributeGroupSave` hook, so they never reach it from `AttributeGroup::add()`. With the module installed and enabled, the tool writes after the add what its form writes on every save: one row of `layered_indexable_attribute_group`, and one row of `layered_indexable_attribute_group_lang_value` per language whose URL word or title tag is filled (a language left out taking the default one's text) - the module writes none for a language where both are empty - then drops the module's filter block cache. A new group has no value yet, so `layered_indexable_attribute_lang_value` is written by `somcp_create_attribute_value`.
- The group is created without values.
- PrestaShop accepts two groups of the same name: the description tells the assistant to read `somcp_list_attributes` first.
- Every problem is reported at once, before anything is written.
- Not replayable: the read tool to check is `somcp_list_attributes`.

#### `somcp_update_attribute_group`
**State**: written
**Nature**: `acts(EDIT, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - changes one attribute group with everything `somcp_create_attribute_group` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_attribute_group`), or changing one of its values (→ `somcp_update_attribute_value`).

**Parameters** - `id_attribute_group` (required, from `somcp_list_attributes`), `languages`, then the fields of `somcp_create_attribute_group`, all optional, checked by the same code. A text given replaces the languages given and keeps the others; given, `name` and `public_name` are never empty. `indexable` left out keeps what the group has. `position` (merchant) is the place among the attribute groups, from 0 as `somcp_get_attribute_group` gives it (the back office list shows it plus 1), refused outside 0 to the number of groups minus 1; left out, the group keeps its place.

**Returns** - `changed`; when something changed, `changes` (per field and language, its `old` and `new` value) and `attribute_group`, the group as `somcp_get_attribute_group` returns it. `note` appears when `group_type` leaves `color`, `faceted_search` only when the faceted search rows could not be written while the rest was.

**Notes**
- A group that is not in `attribute_group_shop` for the shop of the token is refused, the same test as `somcp_get_attribute_group`.
- Written through `AttributeGroup::update()` in the shop of the token (`id_shop_list`), as the core's `EditAttributeGroupHandler` does, so the hooks fire and `update()` sets the colour flag from `group_type`.
- `position` is written last with `AttributeGroup::updatePosition()`, where the drag and drop of the back office list ends, so the other groups shift as there; `changes` records the old and new place. The core finds the group to move from the list's request (`id_attribute_group`), which an MCP call does not carry, so the shared `SomcpCatalog::movePosition()` hands it over for the move only - the one function meant for every tool that reorders a catalogue list, attribute values and features included.
- Changing `group_type` away from `color` leaves the values' colours and textures in place but unused, as in the back office; the answer says so in `note`.
- The faceted search fields are compared with the module's rows and, when one changes, the group's rows are written again as `somcp_create_attribute_group` writes them, the other languages keeping their text; the module's `actionAttributeGroupSave` hook leaves them alone when the request carries no form. A rename alone still drops the module's filter block cache, as its form does on every save.
- Every problem is reported at once, before anything is written. Replayable: the same values again write nothing and say `changed: false`.

#### `somcp_delete_attribute_group`
**State**: written
**Nature**: `acts(DELETE, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one attribute group (Size, Color...) with all its values, as the **Delete** action of **Catalog > Attributes & Features > Attributes** does.

**Do not use for** - changing a group (→ `somcp_update_attribute_group`), deleting one product (→ `somcp_delete_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | yes | from `somcp_list_attributes` or `somcp_get_attribute_group`; a string of digits accepted, as the other attribute group tools |
| `language` | string | no | ISO code for `name` in the answer; read with `SomcpTool::readLanguage()`, refused among the other problems of the call with `SomcpTool::languageProblem()` |

**Returns** - `deleted: true`, `id_attribute_group`, `name` (read before the delete with `SomcpToolGetAttributeGroup::group()`, in `language`), `language`, `values_deleted` (the `values_count` it had), `combinations_deleted` (combinations whose every value was in this group), `combinations_changed` (combinations that only lost one value), `products` (the distinct products of those combinations, each as `id_product` and `name`), `catalog_price_rules` (the `id_specific_price_rule` of this shop with a condition on one of its values, left in place by the core), `still_in_shops` (how many other shops still hold the group). All counted before the delete, in the shop of the token.

**Errors** - in the envelope `Nothing was deleted. Correct these and call again:`: an unknown argument (`<name>: not a field of this tool`); `id_attribute_group` missing or not a whole number above 0; a group not in this shop (`id_attribute_group: no attribute group of this shop has the identifier <n> - find it with somcp_list_attributes`, found with `SomcpToolGetAttributeGroup::existingGroup()`). An unknown language is one more line of the same envelope (`SomcpTool::languageProblem()`). A second call for the same group is refused the same way, never answered as a success.

**Notes**
- Irreversible. Through `AttributeGroup::delete()`, never with SQL of its own: the values are removed from every combination, the combinations left without any value are deleted through `Combination::delete()` with their stock and images, the values are deleted, the other groups are reordered and `actionAttributeGroupDelete` fires. The employee of the token is put on the context for the call, as `somcp_delete_product` does.
- Before the call, the assistant reads `somcp_get_attribute_group` and shows the merchant `values_count`, `products`, `combinations_count` and `combinations_only_on_it` (the combinations that go with their stock), then confirms by name. Both tools count through `SomcpToolGetAttributeGroup::whatGoes()`, so what the merchant is shown before is what the delete answers after.
- The core does not touch the catalogue price rules: a condition `{"type": "attribute", "id_attribute": N}` on one of its values stays and then matches nothing. `catalog_price_rules` names them, and the assistant tells the merchant to change or remove those rules.
- `cleanDeadCombinations()` scans every combination of the shop: a combination already without any value before the call goes with it. `combinations_deleted` is counted from the group's own values before the call, so the answer only names what this delete caused.
- Multistore: `id_shop_list` set to the shop of the token, never the all-shops context. The core deletes the values only when the group has no shop rows to keep (`hasMultishopEntries()`, true for any row once multistore is on); otherwise `ObjectModel::delete()` only takes the group out of this shop, `values_deleted`, `combinations_deleted` and `combinations_changed` are 0, `products` and `catalog_price_rules` are empty and `still_in_shops` says where it remains.
- One group per call (**Delete selected** is several calls). Not replayable: a lost answer is checked with `somcp_get_attribute_group`, which then answers "not found".

**Differences between 8.0.1 and 9.0.0** - none in the core method or the list actions.

#### `somcp_get_attribute_value`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office value form for one attribute value.

**Do not use for** - the list (→ `somcp_list_attributes`), or the group itself (→ `somcp_get_attribute_group`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute` | integer | yes | from `somcp_list_attributes` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_attribute`, `id_attribute_group` and `group_name`, `name`, `position`, `color` (hex code) and `texture` (the current texture as a `download_ref`, `null` when there is none), when `ps_facetedsearch` is installed and enabled `url_name` and `meta_title`, then `combinations_count` (the combinations of this shop that hold the value, deleted with it by `somcp_delete_attribute_value`) and `products` (the distinct products of those combinations, each as `id_product` and `name`, in `language`). A value that is not one of this shop is an error.

**Notes**
- Columns of `attribute` and `attribute_lang`, read through `ProductAttribute`; the value must be in `attribute_shop` for the shop of the token, the set `somcp_list_attributes` lists, since the constructor would load it whatever its shop. `position` is the raw column, 0 for the first of its group. `group_name` is the name the back office lists, not the public one.
- `color` and `texture` are `null` unless the group is of type `color`: only that type draws them on the product page, and a value whose group changed type keeps them stored but unused. The texture is the file PrestaShop keeps in `img/co/` (`_PS_COL_IMG_DIR_`) under the value's identifier, handed out as a `download_ref`.
- The faceted search fields are `null` without the module. With it, they are read from `layered_indexable_attribute_lang_value`, the table its form reads; a value the form was never saved for has no row there, and gives `''`, as the form shows it.
- `combinations_count` and `products` are counted in the shop of the token by `SomcpToolGetAttributeValue::whatGoes()`, the one function `somcp_delete_attribute_value` counts with, so the merchant sees before confirming exactly what the delete reports. A value in no combination gives `0` and `[]`.

#### `somcp_create_attribute_value`
**State**: written
**Nature**: `acts(ADD, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - adds one value to an attribute group, with every field of the back office value form.

**Do not use for** - creating a group (→ `somcp_create_attribute_group`).

**Parameters** - same rules as `somcp_create_attribute_group`.

| Field | Who fills it | Rule |
|---|---|---|
| `id_attribute_group` | merchant, from the shop | required; from `somcp_list_attributes` |
| `name` (value) | merchant | required; 128 characters at most; `Validate::isGenericName()` (no `< > = { }`); on PrestaShop 9 also no `;` or `#`, which its Symfony value form refuses as a catalogue name (`TypedRegex::TYPE_CATALOG_NAME`) - a value saved with them could not be saved again from that form |
| `url_name` (URL) | assistant | faceted search, as for the group |
| `meta_title` (title tag) | assistant | faceted search, as for the group |
| `color` | merchant | groups of type `color` only, refused otherwise (`null`, as `somcp_get_attribute_value` returns it there, is skipped); a hex code `#RRGGBB`, `Validate::isColor()` (which alone would also let a bare word through) |
| `texture` | merchant | groups of type `color` only, refused otherwise (`null`, as `somcp_get_attribute_value` returns it there, is skipped); an object, `url` (a public https address the shop downloads) or `upload_ref` (a file already on the shop, such as a photo attached in the chat), one of the two, fetched with the guards of `somcp_create_product` once the value exists; a bare address is refused |

The faceted search fields follow the rule given under `somcp_create_attribute_group`. A value has no `indexable` of its own: that switch belongs to its group, and is refused here.

**Returns** - `created`, `id_attribute`, `texture` (`null` when none was given, otherwise its `url` or `upload_ref` with `stored: true` or the reason it was `refused`), and `attribute_value`, the value as `somcp_get_attribute_value` returns it. `faceted_search` appears only when the faceted search rows could not be written; the value is kept then.

**Notes**
- The group must be in `attribute_group_shop` for the shop of the token, the same test as `somcp_get_attribute_group`.
- Written through `ProductAttribute::add()` in the shop of the token (`id_shop_list`), as the core's `AddAttributeHandler` does, so `actionAttributeSave` fires; `add()` places the value last in its group. If the add fails after the row went in, the value is deleted again.
- A name the group already holds in a language is refused, compared without case among the values of the group in `attribute_shop` for the shop of the token, as the back office refuses it.
- The texture is written where the back office writes it, `img/co/<id_attribute>.jpg` (`_PS_COL_IMG_DIR_`), re-encoded as JPEG, without thumbnails. Only its shape is checked before the write; a texture that cannot be fetched or is not an image leaves the value created and says why in `texture`.
- `ps_facetedsearch` reads its fields from the request in its `actionAttributeSave` hook, so on `add()` it finds none and writes no row. With the module installed and enabled, the tool then writes one row of `layered_indexable_attribute_lang_value` per language whose URL word or title tag is filled (a language left out taking the default one's text) - the module writes none for a language where both are empty - and drops the module's filter block cache.
- Every problem is reported at once, before anything is written.
- Not replayable: the read tool to check is `somcp_list_attributes`.

#### `somcp_update_attribute_value`
**State**: written
**Nature**: `acts(EDIT, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - changes one attribute value with everything `somcp_create_attribute_value` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_attribute_value`), or changing its group (→ `somcp_update_attribute_group`).

**Parameters** - `id_attribute` (required, from `somcp_list_attributes`), `languages`, then the fields of `somcp_create_attribute_value`, all optional, checked by the same code; `id_attribute_group` moves the value to another group; `remove_texture` (a boolean, groups of type `color` only, not with `texture`) removes the texture; `position` (merchant) is the place among the values of the group, from 0 as `somcp_get_attribute_value` gives it (the back office list shows it plus 1), refused outside 0 to the number of values minus 1 - with `id_attribute_group`, the place in the group it moves to, where it counts as one more. A text given replaces the languages given and keeps the others; given, `name` is never empty. `color`, `texture` and `remove_texture` follow the type of the group the value ends up in; left out, `position` keeps the value's place.

**Returns** - `changed`; when something changed, `changes` (per field and language, its `old` and `new` value) and `attribute_value`, the value as `somcp_get_attribute_value` returns it. `texture` appears when one was given (its `url` or `upload_ref` with `stored: true` or the reason it was `refused`), `note` when the value leaves a group of type `color` with a colour or texture, `faceted_search` only when the faceted search rows could not be written while the rest was.

**Notes**
- A value that is not in `attribute_shop` for the shop of the token is refused, the same test as `somcp_get_attribute_value`; a group to move to must be in `attribute_group_shop`, the test of `somcp_get_attribute_group`.
- Written through `ProductAttribute::update()` in the shop of the token (`id_shop_list`), as the core's `EditAttributeHandler` does, so `actionAttributeSave` fires.
- The value stays on the combinations that use it: a new name or colour shows on every one of them, which the assistant tells the merchant before the call.
- A move is refused while a combination uses the value: a combination holds one value per group, and the core would leave it with a value of a group its product was not built on. A moved value is placed last in its new group, and the positions of the group it leaves are closed up. Its colour and texture stay stored; a group not of type `color` does not show them, and the answer says so in `note`.
- A name the group already holds in a language is refused, as at creation; on a move, the names kept from the value are compared with the new group too.
- `position` is written last with `ProductAttribute::updatePosition()`, where the drag and drop of the values list ends, so the other values of the group shift as there; `changes` records the old and new place. With `id_attribute_group`, the value is moved first, placed last in its new group, then put at `position` there. The core finds the group to reorder from the list's request (`id_attribute_group`), so the move goes through the shared `SomcpCatalog::movePosition()`, as `somcp_update_attribute_group` does.
- A texture given is always fetched and written over the current one, as at creation, so giving it again is a change - except the one `somcp_get_attribute_value` returned, its `download_ref` or `null` when there is none, which changes nothing; one that cannot be used leaves the current texture in place and says why in `texture`, and is an error when it was the only change. `remove_texture` deletes `img/co/<id_attribute>.jpg`; on a value without a texture it changes nothing.
- `ps_facetedsearch` deletes the value's rows of `layered_indexable_attribute_lang_value` in its `actionAttributeSave` hook and writes back only what its form's request carries, so after `update()` the tool writes the rows again: the ones given merged with the ones kept, as `somcp_create_attribute_value` writes them. A call that changes only these fields does not go through `update()`. The module's filter block cache is dropped, as its form does on every save.
- Every problem is reported at once, before anything is written. Replayable, except for a texture: the same values again write nothing and say `changed: false`.

#### `somcp_delete_attribute_value`
**State**: written
**Nature**: `acts(DELETE, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one value of an attribute (M under Size, Red under Color...), as the **Delete** action of one value on **Catalog > Attributes & Features > Attributes** does.

**Do not use for** - deleting a whole group (→ `somcp_delete_attribute_group`), changing a value (→ `somcp_update_attribute_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute` | integer | yes | from `somcp_list_attributes` or `somcp_get_attribute_value`; a string of digits accepted, as the other attribute value tools |
| `language` | string | no | ISO code for `name` in the answer; read with `SomcpTool::readLanguage()`, refused among the other problems of the call with `SomcpTool::languageProblem()` |

**Returns** - `deleted: true`, `id_attribute`, `id_attribute_group`, `name` (read before the delete, in `language`), `language`, `combinations_deleted` (the combinations that held the value, each as `id_product` and `id_product_attribute`, as they were before the call), `default_changed` (the `id_product` of the products that got a new default combination), `cart_rules` (the `id_cart_rule` whose product restrictions held this value), `catalog_price_rules` (the `id_specific_price_rule` of this shop with a condition on it, left in place by the core), `still_in_shops` (how many other shops still hold the value). All counted before the delete, in the shop of the token, through the function shared with `somcp_get_attribute_value`.

**Errors** - in the envelope `Nothing was deleted. Correct these and call again:`: an unknown argument (`<name>: not a field of this tool`); `id_attribute` missing or not a whole number above 0; a value not in this shop (`id_attribute: no attribute value of this shop has the identifier <n> - find it with somcp_list_attributes`, found with `SomcpToolGetAttributeValue::existingValue()`). An unknown language is one more line of the same envelope (`SomcpTool::languageProblem()`). A second call for the same value is refused the same way, never answered as a success.

**Notes**
- Irreversible. Through `ProductAttribute::delete()`, never with SQL of its own: every combination that uses the value is deleted through `Combination::delete()` with its stock and images, a product that loses its default combination gets the first one left, the cart rule restrictions on the value are cleaned, the other values of the group are reordered and `actionAttributeDelete` fires. The employee of the token is put on the context for the call, as `somcp_delete_product` does.
- Before the call, the assistant reads `somcp_get_attribute_value` (`combinations_count` and `products`) and tells the merchant what goes and which products are concerned, then confirms by name.
- The core does not touch the catalogue price rules: a condition `{"type": "attribute", "id_attribute": N}` on the value stays and then matches nothing. `catalog_price_rules` names them, and the assistant tells the merchant to change or remove those rules.
- Multistore: `id_shop_list` set to the shop of the token, never the all-shops context. The core removes the value only when it has no shop rows to keep (`hasMultishopEntries()`, true for any row once multistore is on); otherwise `ObjectModel::delete()` only takes the value out of this shop, `combinations_deleted`, `default_changed` and `cart_rules` are empty, `catalog_price_rules` is empty and `still_in_shops` says where it remains.
- One value per call (**Delete selected** is several calls). Not replayable: a lost answer is checked with `somcp_get_attribute_value`, which then answers "not found".

**Differences between 8.0.1 and 9.0.0** - none in the core method or the list actions.

#### `somcp_list_features`
**State**: written
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - lists the features (Material, Composition...) and, under each, its predefined values: identifier, name and the position and count the back office lists show. A cascade, like the attributes.

**Do not use for** - everything about one feature or one value (→ `somcp_get_feature`, `somcp_get_feature_value`), or the features of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | no | one feature only |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `features`, per feature: `id_feature`, `name`, `position` (0 for the first, as `somcp_get_feature` returns it), `values_count` (the **Values** column: its predefined values), and its `values`: `id_feature_value`, `value`, and on PrestaShop 9 `position`. Plus `count`, the number of features. An `id_feature` that is not a feature of this shop is an error, not an empty list.

**Notes**
- Predefined values only (`custom` = 0, or NULL, which the core reads the same way). A custom value typed on one product belongs to that product and is read with `somcp_get_product`.
- Read through queries on `feature`, `feature_shop`, `feature_value` and their language tables in the shop of the token, rather than `Feature::getFeatures()` and `FeatureValue::getFeatureValuesWithLang()`, which take the shop from the global context. Features are sorted by position, as the back office does.
- Values differ between versions: `feature_value.position` only exists from PrestaShop 9 on, where the value list shows **Position** and **Rearrange**. There, values are sorted by position then identifier, as the back office does, and carry `position`; on PrestaShop 8 they are sorted by identifier and the key is left out, rather than a 0 that would read as "first". The version test is `SomcpCatalog::featureValuesHavePosition()`, shared with `somcp_get_feature_value` and `somcp_update_feature_value`.

#### `somcp_get_feature`
**State**: written
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office feature form for one feature.

**Do not use for** - the list (→ `somcp_list_features`), or one value (→ `somcp_get_feature_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | yes | from `somcp_list_features` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_feature`, `name`, `position`, `values_count`, `products_count`, and when `ps_facetedsearch` is installed and enabled: `url_name`, `meta_title`, `indexable`. A feature that is not one of this shop is an error.

**Notes**
- Columns of `feature` and `feature_lang`, read through `Feature`; the feature must be in `feature_shop` for the shop of the token, since the constructor takes no shop. `position` is the raw column, 0 for the first.
- `values_count` counts the predefined values only (`custom` = 0 or NULL), the ones `somcp_list_features` lists: a custom value belongs to one product.
- `products_count` counts the distinct products of this shop that show the feature, with a predefined or a custom value (`feature_product` joined to `product_shop`), through `SomcpToolGetFeature::productsCount()`, the function `somcp_delete_feature` counts its `products_count` with: the merchant hears it before confirming a delete.
- The faceted search fields are `null` without the module. With it, they are read from `layered_indexable_feature` and `layered_indexable_feature_lang_value`, the tables its form reads; a feature the form was never saved for has no row there, and gives `''` and `false`, as the form shows it.

#### `somcp_create_feature`
**State**: written
**Nature**: `acts(ADD, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - creates one feature with every field of the back office feature form, in every language of the shop or the ones the merchant picks.

**Do not use for** - adding a value to a feature (→ `somcp_create_feature_value`), or changing one (→ `somcp_update_feature`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; `Validate::isGenericName()` (no `<>={}`) |
| `url_name` (URL) | assistant | faceted search; the word used in filtered URLs, the name otherwise; refused unless it is already what `Tools::str2url()` makes of it, as the module's form does; 128 characters at most |
| `meta_title` (title tag) | assistant | faceted search; 128 characters at most; `Validate::isGenericName()`, since the module strips tags from it |
| `indexable` | merchant | faceted search; false when left out, as in the form; a boolean, a string such as `"false"` is refused |

**Faceted search fields.** `url_name`, `meta_title` and `indexable` belong to `ps_facetedsearch`, exactly as for attributes: the module saves them from the back office form only, so the tool writes its rows itself, in `layered_indexable_feature`, `layered_indexable_feature_lang_value` and `layered_indexable_feature_value_lang_value`, and refuses these fields with a clear message when the module is not installed and enabled - except the `null` the get tools return for them then, which is skipped.

**Returns** - `created`, `id_feature`, and `feature`, the feature as `somcp_get_feature` returns it. `faceted_search` appears only when the faceted search rows could not be written; the feature is kept then.

**Notes**
- Written through `Feature::add()` in the shop of the token (`id_shop_list`), as the core's `AddFeatureHandler` does, so the object hooks fire and the feature takes the last position. If the add fails after the row went in, the feature is deleted again.
- `ps_facetedsearch` reads its fields from the request in its `actionFeatureSave` hook, so they never reach it from `Feature::add()`. With the module installed and enabled, the tool writes after the add what its form writes on every save: one row of `layered_indexable_feature`, and one row of `layered_indexable_feature_lang_value` per language of the shop (empty texts included, a language left out taking the default one's text), then drops the module's filter block cache. A new feature has no value yet, so `layered_indexable_feature_value_lang_value` is written by `somcp_create_feature_value`.
- The feature is created without values.
- PrestaShop accepts two features of the same name: the description tells the assistant to read `somcp_list_features` first.
- Every problem is reported at once, before anything is written.
- Not replayable: the read tool to check is `somcp_list_features`.

#### `somcp_update_feature`
**State**: written
**Nature**: `acts(EDIT, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - changes one feature with everything `somcp_create_feature` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_feature`).

**Parameters** - `id_feature` (required), `languages`, then the fields of `somcp_create_feature`, all optional. A text given replaces the languages given and keeps the others. `position` (merchant) is the place among the features, from 0 as `somcp_get_feature` gives it (the back office list shows it plus 1), refused outside 0 to the number of features minus 1; left out, the feature keeps its place.

**Returns** - `changed`, `id_feature`, `changes` (per field its old and new value, a text per language: `name (en)`), and `feature`, the feature as `somcp_get_feature` returns it. `faceted_search` appears only when the faceted search rows could not be written while the name did change. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- Renaming a feature renames it on every product that carries it, which the assistant tells the merchant before the call.
- The name is written through `Feature::update()` in the shop of the token (`id_shop_list`), as the core's `EditFeatureHandler` does, so the object hooks fire. A language not filled keeps its name.
- `position` is written last with `Feature::updatePosition()`, where the drag and drop of the back office list ends, so the other features shift as there; `changes` records the old and new place. It goes through the shared `SomcpCatalog::movePosition()`, as for attribute groups and values; the core moves the feature from its own identifier, so no value of the list's request is handed over.
- Faceted search fields left out keep what the feature holds, `indexable` included: unlike the creation, it is not turned off when missing. When one of them changes, the rows are written again as the module's form writes them, every language of the shop with its stored text unless a new one was given. A feature whose form was never saved with the module reads as empty texts and not indexable, as `somcp_get_feature` shows it. The module's filter block cache is dropped after any change, a rename included, as its form does.
- Every problem is reported at once, before anything is written.
- Replayable: the same values again say `changed: false`. On a lost answer, read `somcp_get_feature` before calling again.

#### `somcp_delete_feature`
**State**: written
**Nature**: `acts(DELETE, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one feature (Composition, Material...) with all its values, as the **Delete** action of **Catalog > Attributes & Features > Features** does.

**Do not use for** - changing a feature (→ `somcp_update_feature`), deleting one product (→ `somcp_delete_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | yes | from `somcp_list_features` or `somcp_get_feature`; a string of digits accepted, as the other feature tools |
| `language` | string | no | ISO code for `name` in the answer; read with `SomcpTool::readLanguage()`, refused among the other problems of the call with `SomcpTool::languageProblem()` |

**Returns** - `deleted: true`, `id_feature`, `name` (read before the delete with `SomcpToolGetFeature::feature()`, in `language`), `language`, `values_deleted` (all its values, custom ones included), `products_count` (how many distinct products of this shop showed it, counted with `SomcpToolGetFeature::productsCount()` and named as in `somcp_get_feature`), `catalog_price_rules` (the `id_specific_price_rule` of this shop with a condition on one of its values, left in place by the core, read with `SomcpToolCreateCatalogPriceRule::rulesOn()`). All counted before the delete.

**Errors** - in the envelope `Nothing was deleted. Correct these and call again:`: an unknown argument (`<name>: not a field of this tool`); `id_feature` missing or not a whole number above 0; a feature not in this shop (`id_feature: no feature of this shop has the identifier <n> - find it with somcp_list_features`, found with `SomcpToolGetFeature::existingFeature()`); a feature shared with other shops (see Notes). An unknown language is one more line of the same envelope (`SomcpTool::languageProblem()`). A second call for the same feature is refused the same way, never answered as a success.

**Notes**
- Irreversible. Through `Feature::delete()`, never with SQL of its own: its values and their texts are deleted, it is removed from every product, `actionFeatureDelete` fires and the positions are reordered. The employee of the token is put on the context for the call, as `somcp_delete_product` does.
- Before the call, the assistant reads `somcp_get_feature` (`products_count`) and tells the merchant how many products lose the feature, then confirms by name.
- The core does not touch the catalogue price rules: a condition `{"type": "feature", "id_feature_value": N}` on one of its values stays and then matches nothing. `catalog_price_rules` names them, and the assistant tells the merchant to change or remove those rules.
- Multistore: unlike `AttributeGroup::delete()`, `Feature::delete()` has no multistore guard and empties the feature in every shop. The tool therefore refuses a feature that `feature_shop` holds for another shop than the token's (`id_feature: shared with <n> other shop(s); deleting it here would remove its values from all of them - delete it from the back office in the all-shops context`), and never switches to the all-shops context itself. A feature of this shop only is deleted with `id_shop_list` set to it. This is the one difference from the back office.
- One feature per call (**Delete selected** is several calls). Not replayable: a lost answer is checked with `somcp_get_feature`, which then answers "not found".

**Differences between 8.0.1 and 9.0.0** - none in the core method or the list actions.

#### `somcp_get_feature_value`
**State**: written
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office value form for one predefined feature value.

**Do not use for** - the list (→ `somcp_list_features`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature_value` | integer | yes | from `somcp_list_features` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_feature_value`, `id_feature` and `feature_name`, `value`, and when `ps_facetedsearch` is installed and enabled: `url_name`, `meta_title`. On PrestaShop 9, `position` too (0 for the first). `products_count`: the distinct products of this shop that have the value (`feature_product` joined to `product_shop`), counted with `SomcpToolGetFeatureValue::productsCount()`, the function `somcp_delete_feature_value` reports its `products_count` with: the merchant hears it before confirming a delete. A value that is not one of this shop is an error.

**Notes**
- Read through `FeatureValue` and `Feature`. `feature_value` has no shop table: a value belongs to the shops of its feature, so its feature must be in `feature_shop` for the shop of the token, the test `somcp_get_feature` makes.
- A custom value (`custom` = 1) is refused with its own message: it belongs to one product, and is read with `somcp_get_product`. `custom` NULL is predefined, as the core reads it.
- `position` is read from `feature_value` on PrestaShop 9 only, the version test `somcp_list_features` uses (`SomcpCatalog::featureValuesHavePosition()`); on PrestaShop 8 the column does not exist and the key is left out.
- The faceted search fields are `null` without the module. With it, they are read from `layered_indexable_feature_value_lang_value`, the table its form reads; a value the form was never saved for has no row there, and gives `''`, as the form shows it.

#### `somcp_create_feature_value`
**State**: written
**Nature**: `acts(ADD, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - adds one predefined value to a feature, with every field of the back office value form.

**Do not use for** - creating a feature (→ `somcp_create_feature`), changing a value (→ `somcp_update_feature_value`), or giving one product its own value (→ `somcp_update_product`, `features` with a custom value).

**Parameters** - same rules as `somcp_create_feature`: `languages`, translatable texts as an object keyed by ISO code (a plain string when one language is filled), who fills each field.

| Field | Who fills it | Rule |
|---|---|---|
| `id_feature` (Feature) | merchant, from the shop | required; from `somcp_list_features`; a feature of another shop is refused like one that does not exist |
| `value` | merchant | required; 255 characters at most; `Validate::isGenericName()` (no `<>={}`) |
| `url_name` (URL) | assistant | faceted search, as for the feature |
| `meta_title` (title tag) | assistant | faceted search, as for the feature |

The faceted search fields follow the rule given under `somcp_create_feature`. A value has no `indexable` of its own: that switch is the feature's, and is refused here as a field the tool does not take.

**Returns** - `created`, `id_feature_value`, and `feature_value`, the value as `somcp_get_feature_value` returns it. `faceted_search` appears only when the faceted search rows could not be written; the value is kept then.

**Notes**
- Written through `FeatureValue::add()` with `custom` = 0, so `actionFeatureValueSave` fires; from PrestaShop 9 on, `add()` also gives it the last position in its feature. If the add fails after the row went in, the value is deleted again.
- `ps_facetedsearch` reads its fields from the request in its `actionFeatureValueSave` hook, so they never reach it from `FeatureValue::add()`. With the module installed and enabled, the tool writes after the add one row of `layered_indexable_feature_value_lang_value` per language of the shop (empty texts included, a language left out taking the default one's text), then drops the module's filter block cache.
- Two predefined values with the same text in one feature are refused, compared per language being filled and without case, and the error gives the `id_feature_value` already there. Custom values are not compared: each belongs to one product.
- `feature_value` has no shop table: the value belongs to the shops of its feature.
- Every problem is reported at once, before anything is written.
- Not replayable: the read tool to check is `somcp_list_features`.

#### `somcp_update_feature_value`
**State**: written
**Nature**: `acts(EDIT, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - changes one predefined feature value with everything `somcp_create_feature_value` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_feature_value`), changing the feature itself (→ `somcp_update_feature`), or a custom value typed on one product (→ `somcp_update_product`).

**Parameters** - `id_feature_value` (required), `languages`, then the fields of `somcp_create_feature_value`, all optional; `id_feature` moves the value to another feature. A text given replaces the languages given and keeps the others.

**Returns** - `changed`, `id_feature_value`, `changes` (per field its old and new value, a text per language: `value (en)`), and `feature_value`, the value as `somcp_get_feature_value` returns it. `faceted_search` appears only when the faceted search rows could not be written while the text or the feature did change. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- The value stays on the products that use it: changing its text changes it there too, which the assistant tells the merchant before the call.
- Written through `FeatureValue::update()`, as the core's `EditFeatureValueHandler` does, so `actionFeatureValueSave` fires. A language not filled keeps its text.
- A value whose text another predefined value of its feature already holds is refused, compared per language and without case, as at creation; the value never clashes with itself, so its case can be changed.
- `id_feature` moves the value, as the Feature field of the back office value form does. The products carrying a value name its feature in their own `feature_product` row, which `FeatureValue::update()` leaves alone: they would go on showing the value under the feature it left. The move is therefore refused while a product carries the value. The texts it keeps are compared with the values of the feature it moves to, in every language. From PrestaShop 9 on, where values have a position, it takes the last one in its new feature and the old one closes the gap.
- Faceted search fields left out keep what the value holds. When one of them changes, the rows are written again as the module's form writes them, every language of the shop with its stored text unless a new one was given. A value whose form was never saved with the module reads as empty texts, as `somcp_get_feature_value` shows it. The module's filter block cache is dropped after any change.
- Custom values (`custom` = 1) are refused with their own message, and a value of another shop's feature like one that does not exist, the tests `somcp_get_feature_value` makes.
- Every problem is reported at once, before anything is written.
- Replayable: the same values again say `changed: false`. On a lost answer, read `somcp_get_feature_value` before calling again.

#### `somcp_delete_feature_value`
**State**: written
**Nature**: `acts(DELETE, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one predefined value of a feature (Composition: Cotton), as the **Delete** action on the values of a feature in **Catalog > Attributes & Features > Features** does.

**Do not use for** - a custom value typed on one product (→ `somcp_update_product`), the whole feature (→ `somcp_delete_feature`), changing a value (→ `somcp_update_feature_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature_value` | integer | yes | from `values[]` of `somcp_list_features`; a string of digits accepted, as the other feature tools |
| `language` | string | no | ISO code for `feature_name` and `value` in the answer; read with `SomcpTool::readLanguage()`, refused among the other problems of the call with `SomcpTool::languageProblem()` |

**Returns** - `deleted: true`, `id_feature_value`, `id_feature`, `feature_name` and `value` (read before the delete with `SomcpToolGetFeatureValue::featureValue()`, the names `somcp_get_feature_value` answers with), `language`, `products_count` (how many distinct products of this shop had the value, counted with `SomcpToolGetFeatureValue::productsCount()` as `products_count` of `somcp_get_feature_value`), `catalog_price_rules` (the `id_specific_price_rule` of this shop with a **Feature** condition on the value, left in place by the core, read with `SomcpToolCreateCatalogPriceRule::rulesOn()`). All counted before the delete.

**Errors** - in the envelope `Nothing was deleted. Correct these and call again:`: an unknown argument (`<name>: not a field of this tool`); `id_feature_value` missing or not a whole number above 0; a value not in this shop (`id_feature_value: no value of a feature of this shop has the identifier <n> - find it with somcp_list_features`, found with `SomcpToolGetFeatureValue::loadedValue()`); a custom value (`custom` = 1, sent to `somcp_get_product` and `somcp_update_product`); a value of a feature shared with other shops (see Notes). An unknown language is one more line of the same envelope (`SomcpTool::languageProblem()`). A second call for the same value is refused the same way, never answered as a success.

**Notes**
- Irreversible. Through `FeatureValue::delete()`, never with SQL of its own: the value is removed from every product (`feature_product`), its row and texts are deleted and `actionFeatureValueDelete` fires, which `ps_facetedsearch` listens to for its own row of the value. The employee of the token is put on the context for the call, as `somcp_delete_product` does.
- Before the call, the assistant reads `somcp_get_feature_value` (`products_count`) and tells the merchant how many products lose the value, then confirms by name.
- The core does not touch the catalogue price rules: a condition `{"type": "feature", "id_feature_value": N}` on the value stays and then matches nothing. `catalog_price_rules` names them, and the assistant tells the merchant to change or remove those rules.
- Multistore: `feature_value` has no shop table, so a value belongs to every shop of its feature and `FeatureValue::delete()` removes it and its product links in all of them. A value whose feature `feature_shop` holds for another shop than the token's is refused (`id_feature_value: the feature of this value is shared with other shops - deleting it here would remove it from their products too`), on the test of `somcp_delete_feature` (`SomcpToolDeleteFeature::otherShops()`). This is the one difference from the back office.
- One value per call (**Delete selected** is several calls). Not replayable: a lost answer is checked with `somcp_get_feature_value`, which then answers "not found".

**Differences between 8.0.1 and 9.0.0** - on 9.0.0 only, the core renumbers the positions of the other values of the feature (`cleanPositions()`); 8.0.1 has no position for values. The rest of `FeatureValue::delete()` and the list actions are the same.

#### `somcp_list_attachments`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttachments')` · **Scope**: `mcp.catalog.read`

**Does** - lists the files of the shop (Catalogue > Files: manuals, data sheets) that a product can carry, with the columns of the back office list and a download link.

**Do not use for** - the files of one product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | no | part of the name (Name), case-insensitive, in the language read |
| `file_name` | string | no | part of the name the file was uploaded under (File), case-insensitive |
| `language` | string | no | ISO code of one of the shop's active languages; the shop default when left out; any other code is refused, among the other problems of the call with `SomcpTool::languageProblem()` |

**Returns** - `attachments`, per file: `id_attachment` (ID), `name` (Name), `description` (Description of the form, in the same language), `file_name` (File), `file_size` (Size, in bytes), `products_count` (Products), `mime`, `file` (a `download_ref` for `somcp_download_file`). Plus `count`, the number of files, and `language`, the ISO code the names and descriptions were read in.

**Notes**
- The file goes through `somcp_download_file`, with the rights of this tool, as every file of the shop: no storefront link is handed out, which would open for anyone who has it.
- That link works without being signed in, as on the storefront: anyone holding it can download the file. The description says so, so the assistant does not paste it somewhere public by mistake.
- Columns of `attachment` and `attachment_lang`; `products_count` from `product_attachment`. `file_name` is the name the file was uploaded under; the back office File column shows the name it is stored under on disk, which means nothing to a merchant.
- Files have no shop of their own in PrestaShop: every shop sees the same list, as in the back office. `products_count` only counts products of the shop of the token (`product_shop`), so a count never reaches into another shop.
- A file with no name in the language read is still listed, with an empty `name`, rather than missing from the only list of identifiers.
- Filters as the back office list does: `name` on the translated name, `file_name` on the uploaded name. Left out, every file is listed.
- The language follows the shared rule: the shop default when left out (never the language of the client's browser), refused with the list of the shop's languages when unknown, inactive or not a string, as one line among the other problems of the call (`SomcpTool::languageProblem()`), and named in the answer so a text can be given back keyed by its ISO code.
- Lists only, never uploads: files do not travel through the conversation.

### Stock

The quantities the storefront sells against, per product and combination.

**Not here**: prices and texts (→ Catalogue).

#### `somcp_get_stock`
**State**: written
**Nature**: `acts(VIEW, 'AdminStockManagement')` · **Scope**: `mcp.stock.read`

**Does** - returns the quantities of one product, per combination.

**Do not use for** - finding what runs low across the catalogue (→ `somcp_list_low_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out; given back as `language` in the answer |

**Returns** - `id_product`, `name`, `reference`, `id_supplier`, `supplier`, `active`, the product line - `quantity` (total), `physical_quantity`, `reserved_quantity`, `low_stock_threshold`, `low_stock_alert`, `reserved_by` - and per combination: `id_product_attribute`, `attributes`, `reference`, `quantity`, `physical_quantity`, `reserved_quantity`, `low_stock_threshold`, `low_stock_alert`, `reserved_by`. Each `reserved_by` entry is `id_order`, `reference` and `quantity`.

**Notes**
- Read from `stock_available`, the quantities the storefront sells against, through the same reader as `somcp_get_product` (restricted to the shop or its group by `StockAvailable::addSqlShopRestriction()`, as stock sharing decides), so the two tools always agree.
- The product line also carries `physical_quantity`, `reserved_quantity` and `low_stock_threshold`: without them, a product without combinations would have none of the three.
- `reserved_by` names the orders behind the reserved stock. `reserved_quantity` is a stored count with no link to its orders, so the list is rebuilt from the order lines: an order counts once it is valid (`order_state.logable`) and until it ships (`order_state.shipped`), with the quantity the line still holds after any refund or return. The product line sums an order's combinations into one entry; each combination lists its own. Restricted to this shop's orders, so the total can differ from `reserved_quantity` when stock is shared with the group or a line was partly refunded.
- Combinations are listed from `product_attribute_shop`, so one without a stock row yet comes back at zero rather than missing. `low_stock_threshold` is the shop's own (`product_shop`, `product_attribute_shop`), `null` when no alert is set.
- The columns of **Catalog > Stock**: **Reference** is the product's, and each combination's own; **Supplier** is the default supplier (`product.id_supplier`), `supplier` its name, `null` when none; **Status** is `active` for this shop (`product_shop`); **Low stock alert:** is `low_stock_alert`, the email alert at the low stock level, read by `SomcpCatalog::lowStockAlert()` like `somcp_get_product`. All come from the queries the tool already runs, none per combination.
- A product still being created, or not in this shop, is not found, as in `somcp_get_product`.

#### `somcp_list_low_stock`
**State**: written
**Nature**: `acts(VIEW, 'AdminStockManagement')` · **Scope**: `mcp.stock.read`

**Does** - lists the products and combinations at or under a quantity.

**Do not use for** - the stock of one known product (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `threshold` | integer | no | each product's own low stock level when absent, 0 when it has none |
| `active` | boolean | no | enabled products only, true by default; false disabled products only (**Filter by status**); anything else refused (`active: true or false`) |
| `id_category` | integer | no | products associated with this category, its default one or not, from `somcp_list_categories` (**Filter by categories**) |
| `id_supplier` | integer | no | products whose default supplier this is (`product.id_supplier`), from `somcp_list_suppliers` (**Filter by supplier**) |
| `search` | string | no | part of the product name, of the product or combination reference, of the attributes of the combination (Black, Size - M), or of the supplier name, as the search box of the tab (**Search products**); from PrestaShop 9 on, also of an EAN-13, ISBN, UPC or MPN code |
| `language` | string | no | ISO code of one of the shop's active languages, for the product names and the search, refused otherwise; the shop default when left out; given back as `language` in the answer |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per line: `id_product`, `id_product_attribute`, `reference`, `name`, `quantity`, `threshold`, `supplier` (the name of the default supplier, the **Supplier** column, `null` when none). Plus `returned`, `total_count`, `offset` and `language`.

**Notes**
- One line per thing that can be sold: a product without combinations (`id_product_attribute` 0), or each combination of a product that has some, listed from `product_attribute_shop`. A product with combinations has no line of its own: its quantity is only their total, which can look fine while one size is gone.
- `quantity` is read from `stock_available`, restricted to the shop or its group by `StockAvailable::addSqlShopRestriction()`, as in `somcp_get_stock`. A combination without a stock row counts as zero.
- Without `threshold`, a line is compared with its own low stock level (`low_stock_threshold`) - the combination's, or the product's for a product without combinations - and with 0 when none is set. `threshold` on each line is the level used.
- `reference` is the combination's own on a combination line; `name` is the product's, in the language read (the shop default when none is given). Attributes and the physical and reserved quantities are read with `somcp_get_stock`.
- Lowest quantity first. A product still being created, or not in this shop, is left out.
- The filters are those of the **Stock** tab of **Catalog > Stock**, the same on PrestaShop 8 and 9. `search` matches each line on its own: a combination is found by its own reference, without its other combinations. `total_count` counts with every filter applied.
- A field the tool does not take, a `threshold` that is not a whole number, an `active` that is not true or false, or an `id_category` or `id_supplier` that is not a whole number above 0 is refused in one answer, `Nothing was read`, before anything is read, rather than dropped while the whole stock is listed.
- An `id_category` or `id_supplier` the shop does not have is refused in the same answer, named with the tool that lists them (`id_category: no category of this shop has the identifier <n> - find it with somcp_list_categories`, `id_supplier: no supplier of this shop has the identifier <n> - find it with somcp_list_suppliers`), instead of answering an empty list.

#### `somcp_set_stock_quantity`
**State**: written
**Nature**: `acts(EDIT, 'AdminStockManagement')` · **Scope**: `mcp.stock.write`

**Does** - sets the available quantity of one product or one combination.

**Do not use for** - reading stock (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |
| `id_product_attribute` | integer | no | a combination |
| `quantity` | integer | one of the two | the new quantity |
| `delta` | integer | one of the two | a movement, positive or negative |

**Returns** - `changed`, `id_product`, `id_product_attribute`, `previous_quantity`, `quantity`, and `packs` when the product is held in packs counted from their products: each `id_product` and the `quantity` recounted after the change.

**Notes**
- The Stock page's own sequence (`StockRepository::updateStock()`): the change goes through `StockAvailable::updateQuantity()` as a movement with the employee edition reason, then the physical and reserved quantities of the product are brought in line. A `quantity` becomes the movement that reaches it. `setQuantity()` is not used: it skips packs, the low stock alert and the movement reason. The employee of the token is put on the context for the write, so the movement carries their name.
- A product with combinations is refused without `id_product_attribute`: its line is the total of its combinations, which the core rewrites. A combination must belong to the product in this shop. A pack whose stock is worked out from its products is refused; one that counts both moves its products too, as in the back office.
- The quantity already in place, or a `delta` of 0, writes nothing and answers `changed: false`. `quantity` and `delta` are whole numbers under a billion either way. `quantity` in the answer is read back after the write.
- Read in the shop of the token, restricted as stock sharing decides, as in `somcp_get_stock`. A product still being created, or not in this shop, is not found.
- A delta is not replayable: a lost answer retried would move the stock twice, which the description says, naming `somcp_get_stock` to check.

#### `somcp_list_stock_movements`
**State**: written
**Nature**: `acts(VIEW, 'AdminStockManagement')` · **Scope**: `mcp.stock.read`

**Does** - lists the stock movements of this shop, newest first, as the **Movements** tab of **Catalog > Stock**, with the filters of that tab: who changed a quantity, when, by how much and why.

**Do not use for** - the current quantities (→ `somcp_get_stock`), what runs low (→ `somcp_list_low_stock`), or changing a quantity (→ `somcp_set_stock_quantity`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | no | movements of one product (from `somcp_search_products`) |
| `id_product_attribute` | integer | no | of one combination of `id_product` (from `somcp_get_stock`); refused without `id_product` |
| `search` | string | no | part of the product name, of the product or combination reference, of the attributes of the combination (Black, Size - M), or of the supplier name, as the search box of the tab; from PrestaShop 9 on, also of an EAN-13, ISBN, UPC or MPN code |
| `id_stock_mvt_reason` | integer | no | one movement type, as **Filter by movement type**, from `types` of a previous answer |
| `id_employee` | integer | no | movements made by one employee, as **Filter by employee**, from `employees` of a previous answer |
| `date_from` / `date_to` | string | no | days `YYYY-MM-DD`, as **From** / **To** of **Filter by period**, both included (`date_to` up to 23:59:59); a value that is not a day is refused |
| `id_category` | integer | no | products filed under one category (from `somcp_list_categories`) |
| `id_supplier` | integer | no | products of one default supplier (from `somcp_list_suppliers`) |
| `active` | boolean | no | `true` enabled products only, `false` disabled only; all when left out, as **Filter by status** "All" |
| `language` | string | no | ISO code of one of the shop's active languages, for the product, attribute and type names; refused otherwise; the shop default language when left out |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - `movements`, newest first, each with `id_stock_mvt`, `id_product`, `id_product_attribute`, `name`, `attributes` (as `somcp_get_stock` gives them), `reference` (the combination's own when it has one, the product's otherwise), `id_stock_mvt_reason`, `type`, `quantity` (signed: `-2` for two sold), `date` (ISO 8601), `id_employee`, `employee` ("First Last", empty for a movement made by an order) and `id_order` (`null` when none). Plus `returned`, `total_count`, `offset`, `types` (every movement type of the shop: `id_stock_mvt_reason`, `type`, `sign`), `employees` (every employee who made a movement of this shop: `id_employee`, `employee`) and `language`.

**Notes**
- Read like the core's `StockMovementRepository`: `stock_mvt` joined to `stock_available` on `id_stock`, restricted to the shop through `StockAvailable::addSqlShopRestriction()` as `somcp_get_stock` does, so shared stock shows the movements of the group, as the tab does. A movement of a product deleted since is left out (inner join on `product_shop`).
- `stock_mvt_reason` differs between installations (12 rows on 8.0.1, 5 on 9.0.0), so an identifier means nothing across shops: `types` is always read from the shop, with its `sign`. It lists every type not deleted, not only the ones used, so a filter can be given before a first movement; `employees` follows the tab and lists only the employees found in the movements.
- The employee name is the one saved on the movement, as the tab shows it.
- Errors in the shape of #169, all reported in one answer: unknown arguments; an unknown `id_product`, `id_category` or `id_supplier`, naming the tool to find it with; a combination that is not one of `id_product`; an `id_stock_mvt_reason` not among `types`; a non-boolean `active`; a day that is not one.
- A read: nothing written, nothing to retry.

### Customers

Customer accounts and what belongs to them: groups, addresses, a summary of their orders.

**Not here**: the orders themselves (→ Orders).

#### `somcp_search_customers`
**State**: written
**Nature**: `acts(VIEW, 'AdminCustomers')` · **Scope**: `mcp.customers.read`

**Does** - finds customers of this shop, as the list of **Customers > Customers**, with the filters of that list, and returns one short line each.

**Do not use for** - reading one customer already identified (→ `somcp_get_customer`), or their orders (→ `somcp_search_orders` with `customer_email`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `query` | string | no | words matched against first name, last name, email, and company when B2B mode is on, each word in at least one of them, as the header search **Customers by name**: "doe pub@" finds John DOE |
| `email` | string | no | exact email address |
| `id_default_group` | integer | no | default group (**Group** filter) |
| `id_group` | integer | no | the **members** of a group (`customer_group`), whatever their default group, as the **View** page of **Shop Parameters > Customer Settings > Groups** lists them (`INNER JOIN customer_group`); with `id_default_group`, both apply. In a multistore that shares customers, `members_count` of `somcp_list_customer_groups` also counts the customers of the shops that share them, as the **Members** column does |
| `id_gender` | integer | no | social title (**Social title** filter) |
| `active` / `newsletter` / `partner_offers` | boolean | no | each left out = All, as the list |
| `is_guest` | boolean | no | `false` registered accounts only, `true` guests only; both when left out |
| `date_from` / `date_to` | string | no | **Registration**, YYYY-MM-DD, both included (`00:00:00` and `23:59:59`, as the grid) |
| `sort` | string | no | `date_add` (default), `last_visit`, `sales`, `lastname`, `id_customer` |
| `order` | string | no | `desc` (default) or `asc` |
| `language` | string | no | ISO code of one of the shop's active languages, for the social title and group names; refused otherwise; the shop default language when left out |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per customer: `id_customer`, `social_title` (empty when none), `firstname`, `lastname`, `email`, `company` (empty when none), `id_default_group`, `default_group` (name), `sales_tax_incl`, `sales_currency` (ISO code of the default currency), `active`, `newsletter`, `partner_offers`, `date_add` (ISO 8601), `last_visit` (ISO 8601, `null` when never), `is_guest`. Plus `returned`, `total_count`, `offset` and `language` (the ISO code the names were read in).

**Notes**
- The list stays plain: no address, no phone, no birthday, no note. Those come with one named customer (`somcp_get_customer`).
- Built with `DbQuery` on the tables and conditions of `CustomerQueryBuilder`: a customer deleted with their data kept (`deleted = 1`) is left out, as the list leaves them out. `Customer::searchByName()` is not called: it does not filter `deleted`, and takes a third argument on PrestaShop 9 only; its word-by-word condition is reproduced.
- Shop: the customers of the token's shop (`c.id_shop`), as the grid, even in a multistore with **Share customers** on.
- `sales_tax_incl` sums `total_paid_tax_incl / conversion_rate` of the customer's valid orders in this shop, `0` when none (the grid shows "--"). PrestaShop 8.0 sums `total_paid_real` on its **Sales** column instead, so the figure can differ from the 8.0 screen for an order not fully paid; 9.0 sums the same total as the tool.
- `last_visit` is the latest `connections.date_add` of a visitor tied to the customer, as the **Last visit** column.
- A field the tool does not take, a `sort` or `order` outside its list, a yes/no filter that is not true or false, a date that is not YYYY-MM-DD, or an `id_default_group`, `id_group` or `id_gender` that is not a whole number above 0 is refused in one answer, `Nothing was read`, before anything is read: never ignored, never replaced by the default order.
- An `id_default_group` or an `id_group` the shop does not have is refused in the same answer (`SomcpToolListCatalogPriceRules::groupOfShop()`), `id_default_group: no customer group of this shop has the identifier <n> - find it with somcp_list_customer_groups`; an `id_gender` it does not have with the list of its social titles, worded as `somcp_create_customer` words it (`id_gender: not a social title of this shop, which has 1 Mr., 2 Mrs.`), named in the language asked. Neither is answered with an empty list.
- A read: nothing written, nothing to retry.

#### `somcp_get_customer`
**State**: written
**Nature**: `acts(VIEW, 'AdminCustomers')` · **Scope**: `mcp.customers.read`

**Does** - returns everything the page of one customer shows, **Customers > Customers > View**: account, groups, addresses, orders, carts, purchased and viewed products, vouchers, messages, last emails and last connections.

**Do not use for** - looking for customers (→ `somcp_search_customers`), or reading one order of theirs (→ `somcp_get_order`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_customer` | integer | one of the two | from `somcp_search_customers` or `somcp_get_order`; used first when both are given |
| `email` | string | one of the two | exact; an email shared by several accounts of the shop (a guest and a registered account) is refused with the `id_customer` of each |
| `language` | string | no | ISO code of one of the shop's active languages, for the social title, group, country, order status and product names; refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default language when left out |

A misspelt field is named and nothing is read. A customer of another shop, or deleted with their data kept (`deleted = 1`), answers as unknown in the shared envelope: `id_customer: no customer of this shop has the identifier <n> - find it with somcp_search_customers`; an email nobody has, `email: no customer of this shop has this email - find one with somcp_search_customers`.

**Returns**
- `customer`: `id_customer`, `firstname`, `lastname`, `email`, `id_gender`, `social_title`, `birthday` (`YYYY-MM-DD`, `null` when none), `age` (`null` when no birthday), `date_add`, `last_visit` (`null` when never), `customer_language` (ISO code of the language the customer uses), `newsletter`, `newsletter_date_add`, `group_ids` (every group's `id_group`, in `groups` order) and `id_default_group`, the names `somcp_update_customer` and `somcp_create_customer` take, `partner_offers`, `date_upd`, `active`, `is_guest`, `private_note` (as `somcp_get_order` names it), and the B2B fields, always given and empty when unset: `company`, `siret`, `ape`, `website`, `allowed_outstanding_amount` with `currency` (the default currency), `max_payment_days`, `id_risk`, `risk`.
- `groups`: each `id_group`, `name`, `default` (the names for the merchant; the ids to give back are `group_ids` and `id_default_group` in `customer`).
- `addresses`: each `id_address`, `alias`, `company`, `firstname`, `lastname`, `address1`, `address2`, `postcode`, `city`, `state`, `country`, `country_iso`, `phone`, `phone_mobile`, `vat_number`, `dni`, `other` (the names of the addresses of `somcp_get_order`).
- `orders`: `count`, `valid_count`, `valid_total_tax_incl` with `currency` (**for a total amount of**, `SUM(total_paid_tax_incl / conversion_rate)` of the valid orders, in the default currency), `invalid_count`, `sales_rank` (`null` with no valid order), and `latest`, the 10 newest: `id_order`, `reference`, `date_add`, `payment`, `status` (`status_id`, `name`), `products_count` (units ordered), `total_paid_tax_incl`, `currency` (the order's). More with `somcp_search_orders` (`customer_email`).
- `carts`: `count` and `latest`, the 10 newest: `id_cart`, `date_add`, `carrier`, `total_tax_incl` (`null` when the cart cannot be priced any more), `currency`, `id_order` (`null` when not ordered).
- `purchased_products`: the 10 newest order lines, every order valid or not: `date_add`, `id_order`, `valid`, `id_product`, `id_product_attribute`, `name` (with its combination), `quantity`.
- `viewed_products`: the 10 product pages most recently visited: `id_product`, `name`.
- `vouchers`: the cart rules reserved to this customer, each `id_cart_rule`, `name`, `code`, `quantity`, `date_to`, `active`.
- `messages_count`: the customer's messages (`CustomerThread::getCustomerMessages()`).
- `last_emails`: the 10 newest, each `date_add`, `template`, `subject`, `language` (ISO code of the language the email was sent in).
- `last_connections`: the 10 newest, each `date_add`, `pages_viewed`, `total_time`, `origin` (the referrer), `ip_address`.

Plus `language` (the ISO code the names were read in). Dates in ISO 8601.

**Notes**
- A detail gives everything, because one named customer was asked for. Never the password hash, `secure_key`, `reset_password_token` or `reset_password_validity`, which the page does not show either.
- The **Wishlist** block comes from the `blockwishlist` module, not the core: left out.
- Read only: the private note, the groups and the account are written by `somcp_update_customer`.
- `sales_rank` counts, as the page's handler, the customers whose valid orders sum a higher `total_paid_real`, within this shop.
- A read: nothing written, nothing to retry.

**Differences between 8.0.1 and 9.0.0**
- **Valid orders: for a total amount of**: 8.0.1 sums `total_paid_real`, 9.0.0 `total_paid_tax_incl`; the tool returns the tax included total on both, as `somcp_search_customers` does for **Sales**.
- **Purchased products**: 8.0.1 lists the valid orders only, 9.0.0 every order; the tool lists every order's lines on both, with `valid`. **Viewed products** is shown on 8.0.1 only; returned on both.
- 9.0.0 shows **Customer type** (`is_guest`, returned on both) and marks the default group.

#### `somcp_create_customer`
**State**: written
**Nature**: `acts(ADD, 'AdminCustomers')` · **Scope**: `mcp.customers.write`

**Does** - creates one customer account, as the form **Customers > Customers > Add new customer** does.

**Do not use for** - changing an account (→ `somcp_update_customer`), or adding an address (not covered yet).

**Parameters** - the names `somcp_get_customer` returns, so an account read there can be given back. Every field is the merchant's.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `firstname` | **First name** | string | yes | letters, the dot and spaces only (`Validate::isCustomerName`) |
| `lastname` | **Last name** | string | yes | same rule |
| `email` | **Email** | string | yes | refused when a registered account of this shop already uses it, with that account's `id_customer` |
| `is_guest` | **Guest account** (9.0.0 only on screen) | boolean | no | `false` by default; a guest has no password and may share its email with other guests |
| `id_gender` | **Social title** | integer | no | `id_gender` of the shop, from `somcp_list_titles`, a whole number or a string of digits as `somcp_search_customers` takes it; an unknown one is refused with the list of the shop's social titles and their identifiers |
| `birthday` | **Birthday** | string | no | `YYYY-MM-DD`, in the past |
| `active` | **Enabled** | boolean | no | `true` by default |
| `partner_offers` | **Partner offers** | boolean | no | `false` by default (`optin`) |
| `newsletter` | **Newsletter** column of the list | boolean | no | `false` by default; when `true`, writes `newsletter_date_add` and the registration IP as the list's switch does |
| `group_ids` | **Group access** | array of integers | no | every group of the shop by default, as the form preselects them |
| `id_default_group` | **Default customer group** | integer | no | `PS_CUSTOMER_GROUP` (Customer) by default; must be in `group_ids` |
| `company` | **Company** (B2B) | string | no | B2B fields: refused when B2B mode is off |
| `siret` | **SIRET** (B2B) | string | no | `Validate::isSiret` |
| `ape` | **APE** (B2B) | string | no | `Validate::isApe` |
| `website` | **Website** (B2B) | string | no | `Validate::isUrl` |
| `allowed_outstanding_amount` | **Allowed outstanding amount** (B2B) | number | no | in the shop's default currency, 0 or more |
| `max_payment_days` | **Maximum number of payment days** (B2B) | integer | no | 0 or more |
| `id_risk` | **Risk rating** (B2B) | integer | no | `1` None, `2` Low, `3` Medium, `4` High on a standard shop (`risk`) |

**Returns** - `created: true`, `id_customer`, `password` (what the customer does to get one), and `customer`: the account read back with the header block of `somcp_get_customer` (`SomcpToolGetCustomer::account()`, shared, not a copy), so the answer shows what the shop stored.

**Errors** - every refused field listed in one answer, nothing written: `Nothing was created. Correct these and call again:` then one line per field, in the wordings of the shared conventions (**Errors**). A group in `group_ids` or `id_default_group` is a whole number or a string of digits, as `somcp_search_customers` takes it, else `<field>: a whole number above 0`; one the shop does not have: `no customer group of this shop has the identifier <id> - find it with somcp_list_customer_groups`.

**Notes**
- **Password**: no password goes through the assistant. The tool hashes a random one, as `AddCustomerHandler` hashes the typed one, and the customer sets theirs with **Forgot your password?** on the shop.
- **No email is sent**: the back office form sends none on either version.
- **B2B fields**: shown by the form only when **Enable B2B mode** is on (**Shop Parameters > Customer Settings**); refused when it is off, with that path in the error.
- **Required fields**: the fields made mandatory with **Set required fields for this section** (`required_field`, **Partner offers** among them) are enforced, read on the account itself rather than on the posted form as `validateFieldsRequiredDatabase()` does.
- **Language**: the account takes the shop's default language, as the form, which has no language field.
- The customer is created in the shop of the token (`id_shop`, `id_shop_group`), and the groups are written explicitly after `add()`.
- Not replayable: on a lost answer, read `somcp_search_customers` with the email before calling again.

**Differences between 8.0.1 and 9.0.0**
- **Guest account** switch on 9.0.0 only; the tool takes `is_guest` on both.
- Email check: 9.0.0 checks the email only for a registered account, 8.0.1 always; both use `Customer::getByEmail()`, which ignores guests, so the result is the same: a registered email is unique within the shop, a guest email is not.
- 9.0.0 calls `updateGroup()` after `add()`; 8.0.1 relies on `groupBox`. The tool writes the groups explicitly on both.

#### `somcp_update_customer`
**State**: written
**Nature**: `acts(EDIT, 'AdminCustomers')` · **Scope**: `mcp.customers.write`

**Does** - changes one customer account, as the form **Customers > Customers > Edit** does, plus the private note of the customer page and the **Newsletter** / **Partner offers** switches of the list.

**Do not use for** - creating an account (→ `somcp_create_customer`), or changing an address (not covered yet).

**Parameters** - only `id_customer` is required; every other field is left as it is when absent. The names are those `somcp_get_customer` returns, so a value read there can be given back. Every value is the merchant's.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_customer` | - | integer | yes | from `somcp_search_customers` |
| `firstname` | **First name** | string | no | letters, the dot and spaces only (`Validate::isCustomerName`) |
| `lastname` | **Last name** | string | no | same rule |
| `email` | **Email** | string | no | refused when another registered account of this shop uses it, with that account's `id_customer` |
| `id_gender` | **Social title** | integer | no | from `somcp_list_titles`; `0` clears it; an unknown one is refused with the list of the shop's social titles and their identifiers |
| `birthday` | **Birthday** | string or null | no | `YYYY-MM-DD`, in the past; `null` clears it |
| `active` | **Enabled** | boolean | no | |
| `partner_offers` | **Partner offers** | boolean | no | `optin` |
| `newsletter` | **Newsletter** column of the list | boolean | no | as the list's switch: `newsletter_date_add` and the registration IP set on subscription, cleared on unsubscription |
| `group_ids` | **Group access** | array of integers | no | the full list after the change, not a difference |
| `id_default_group` | **Default customer group** | integer | no | must be in the groups after the change |
| `private_note` | **Add a private note** (customer page) | string | no | the note `somcp_get_customer` and `somcp_get_order` return; empty string clears it |
| `company` | **Company** (B2B) | string | no | B2B fields: refused when B2B mode is off |
| `siret` | **SIRET** (B2B) | string | no | `Validate::isSiret` |
| `ape` | **APE** (B2B) | string | no | `Validate::isApe` |
| `website` | **Website** (B2B) | string | no | `Validate::isUrl` |
| `allowed_outstanding_amount` | **Allowed outstanding amount** (B2B) | number | no | in the shop's default currency, 0 or more |
| `max_payment_days` | **Maximum number of payment days** (B2B) | integer | no | 0 or more |
| `id_risk` | **Risk rating** (B2B) | integer | no | `1` None, `2` Low, `3` Medium, `4` High on a standard shop (`risk`) |

**Returns** - `changed: true`, `id_customer`, `changes` (each field written with `old` and `new`), and `customer`: the account read back with the header block of `somcp_get_customer` (`SomcpToolGetCustomer::account()`, shared, not a copy). When every value given is already stored: `changed: false` and `reason`, nothing written.

**Errors** - every refused field listed in one answer, nothing written: `Nothing was changed. Correct these and call again:` then one line per field, in the wordings of the shared conventions (**Errors**). Unknown customer, of another shop, or deleted with "keep the data": `id_customer: no customer of this shop has the identifier <id> - find it with somcp_search_customers`, with the misspelt fields of the same call. A group in `group_ids` or `id_default_group` is a whole number or a string of digits, as `somcp_search_customers` takes it, else `<field>: a whole number above 0`; one the shop does not have: `no customer group of this shop has the identifier <id> - find it with somcp_list_customer_groups`.

**Notes**
- **Same rules as the creation**: names, email, social title, birthday, B2B fields and required fields are checked by the functions of `somcp_create_customer`, called rather than copied.
- **Password**: the form has one; the tool never writes it. The customer sets theirs with **Forgot your password?** on the shop.
- **B2B fields**: shown only when **Enable B2B mode** is on (**Shop Parameters > Customer Settings**). Refused when it is off, with that path in the error.
- **Guest account**: the edit form has no **Guest account** switch on either version; turning a guest into a registered account is the customer page's **Transform to a customer account** button, outside this tool (`is_guest` is refused).
- **Required fields**: those set with **Set required fields for this section** (`required_field`) are enforced on the account after the change, as `assertRequiredFieldsAreNotMissing()` does.
- One customer per call. Not replayable: on a lost answer, read `somcp_get_customer` before calling again; the description says so.

**Differences between 8.0.1 and 9.0.0**
- The edit form has the same fields on both versions (with and without B2B mode); 9.0.0 adds the hint "Password should be at least 5 characters long." and the count in **Select all (3)**.
- **Customer type** (guest or registered) is shown on the customer page of 9.0.0 only; the tool behaves the same on both.

### Statistics

Figures over a period, computed from valid orders.

**Not here**: the orders behind them (→ Orders).

#### `somcp_get_sales_summary`
**State**: written · [issue #21](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/21)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.stats.read`

**Does** - returns the sales of this shop over a period, as **Stats > Stats Dashboard** and **Stats > Sales and orders** count them: revenue, orders, products bought, average basket, new customers.

**Do not use for** - the orders behind the figures (→ `somcp_search_orders`), the products that sold most (→ `somcp_list_best_sellers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` | string | yes | **From:**, `YYYY-MM-DD`, that day included, in the shop's time zone |
| `date_to` | string | yes | **To:**, `YYYY-MM-DD`, that day included; not before `date_from` |
| `compare_previous` | boolean | no | adds `previous`: the same figures for the period of the same length that ends the day before `date_from` |
| `delivery_country` | string | no | **All countries**, the filter of **Sales and orders**: the ISO code of a country, only the orders whose delivery address is in it, read as `somcp_search_orders` reads it; every country when left out |
| `language` | string | no | ISO code of one of the shop's active languages, for the status names of `orders_by_status`; the shop default when left out, a code the shop does not have refused |

**Returns** - `date_from` / `date_to` (as received), `delivery_country` (the ISO code, upper case, when it was given), `currency` (ISO code of the default currency, the one every amount is in), `language` (the ISO code the status names are in), `revenue_tax_excl` (**Revenue** on Stats Dashboard), `sales_tax_excl` (**Sales** on Sales and orders: revenue of the orders in a validated status minus their credit slips), `revenue_tax_incl`, `orders_count` (**Placed orders** / **Orders placed**), `products_bought` (**Bought items** / **Products bought**, sum of `order_detail.product_quantity`), `average_basket_tax_excl` and `average_basket_tax_incl` (`0` with no order), `new_customers` (**Registrations**: accounts created in the period), `orders_by_status` (the pie **Percentage of orders per status.** of **Sales and orders**: one entry per status that has at least one order, the most orders first, then by `id_order_state`, each with `id_order_state` as `somcp_list_order_statuses` names it, `name` in `language`, `orders`, the orders whose latest status it is, and `percent`, their share of the block rounded to 2 decimals; every order **placed** in the period counts, valid or not, dated by its **order date** (`date_add`) as the screen does, so this block counts the orders `somcp_search_orders` finds on the same dates; an empty list when no order was placed, the screen's **No orders for this period.**), and `previous` with `compare_previous` (the same object with its own dates, without `previous`). Amounts are rounded to 2 decimals.

**Errors** - every mistake of the call at once, in the envelope of the shared conventions (`Nothing was read. Correct these and call again:`): a field the tool does not take, a missing or malformed date, `date_to` before `date_from`, a `compare_previous` that is not `true` or `false`, a `delivery_country` no country has, a `language` the shop does not have. An empty period is not an error: every figure is `0`.

**Notes** - with `delivery_country`, every figure but `new_customers` keeps only the orders delivered in that country, credit slips included, and `previous` uses the same filter; `new_customers` counts accounts, which have no delivery country, so it is unchanged. Counts orders with `valid = 1`, dated by **`invoice_date`** (not `date_add`), amounts divided by `conversion_rate`, as `statsforecast` and `statssales` do; **Sales** also keeps only statuses counted as validated (`logable`) and takes off the credit slips with the formula of `statssales`. The tables are read directly, so the figures still come when the stats modules are disabled or uninstalled. Only the token's shop. `somcp_search_orders` filters on `date_add`: on the same dates a few orders can differ at the edges of the period. **Visits**, conversion and the distribution blocks of **Stats Dashboard** (payment, category, language, zone, currency, attribute) are not returned. `orders_by_status` keeps the token's shop, where the core reads every shop for this block alone.

Same labels, module versions (`statsforecast` 2.0.4, `statssales` 2.1.0) and queries on 8.0.1 and 9.0.0.

#### `somcp_list_best_sellers`
**State**: written · [issue #22](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/22)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.stats.read`

**Does** - lists the products that sold most over a period, as **Stats > Best-selling products** shows them: quantity sold, price sold, sales, quantity sold in a day, page views, available quantity, status.

**Do not use for** - sales totals (→ `somcp_get_sales_summary`), the orders behind a product (→ `somcp_search_orders`), the stock of every combination (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` | string | yes | **From:**, `YYYY-MM-DD`, that day included, in the shop's time zone |
| `date_to` | string | yes | **To:**, `YYYY-MM-DD`, that day included; not before `date_from` |
| `sort` | enum | no | `sales` (default, as the screen), `quantity_sold`, `quantity_sold_per_day`, `average_price_tax_excl`, `page_views`; always descending, ties by `id_product` ascending |
| `language` | string | no | language of `name`: ISO code of one of the shop's active languages, the shop default when left out, any other value refused |
| `limit` | integer | no | 20 by default, 100 at most |
| `offset` | integer | no | 0 by default |

**Returns** - `date_from` / `date_to` (as received), `currency` (ISO code of the default currency, the one every amount is in), `language` (ISO code the names are in), `products[]` with `id_product`, `reference` (**Reference**), `name` (**Name**), `quantity_sold` (**Quantity sold**), `average_price_tax_excl` (**Price sold**), `revenue_tax_excl` (**Sales**), `quantity_sold_per_day` (**Quantity sold in a day**, 2 decimals), `page_views` (**Page views**), `quantity` (**Available quantity for sale**), `active` (**Active**), then `total_count` and `offset`. Amounts are rounded to 2 decimals.

**Errors** - every problem at once, nothing read, each under "Nothing was read. Correct these and call again:": a field the tool does not take (`<field>: not a field of this tool`), a missing or malformed date (`date_from: a date, YYYY-MM-DD`), `date_to` before `date_from` (`date_to: a date not before date_from`), an unknown `sort` (`sort: one of sales, ...`), an out-of-range `limit` (`limit: a whole number from 1 to 100`) or `offset` (`offset: a whole number, 0 at least`), an unknown `language` (`language: not one of the shop's languages, which are en, fr - leave language out for the shop default`). An empty period is not an error: `products` is empty, `total_count` is `0`.

**Notes** - counts orders with `valid = 1`, dated by **`invoice_date`** (not `date_add`), as `somcp_get_sales_summary`; one row per product, **combinations merged** (`GROUP BY od.product_id`); a product deleted since is not listed. **Sales** is `SUM(unit_price_tax_excl * product_quantity / conversion_rate)`, credit slips not taken off; **Price sold** is the average of the order lines' unit prices, not weighted by quantity; **Quantity sold in a day** divides by the days from the later of `date_from` and the product's date added to the shop, to the earlier of `date_to` and today, both included (`0` when there is no such day). **Page views** sums `page_viewed` over the day ranges inside the period, `0` when page views are not recorded (`statsdata`, **Save page views**). **Available quantity for sale** is the `stock_available` row without combination. Only the token's shop. The tables are read directly, so the list still comes when `statsbestproducts` is disabled or uninstalled.

Same labels, module version (`statsbestproducts` 2.0.1) and query on 8.0.1 and 9.0.0.

### Content

The CMS pages of the storefront.

**Not here**: product texts (→ Catalogue).

#### `somcp_list_cms_pages`
**State**: written · [issue #23](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/23)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.content.read`

**Does** - lists the CMS pages and page categories of the token's shop, as **Design > Pages** shows them: identifier, URL, title, meta title, position, displayed or not, and the category of each page.

**Do not use for** - reading one page with its content (→ `somcp_get_cms_page`), the SEO checks of the pages (→ `somcp_audit_cms_seo`), changing a page's SEO texts (→ `somcp_update_cms_seo`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms_category` | integer | no | only the pages of that category (not of its sub-categories), as the screen; every category when left out. A category the shop does not have is refused |
| `id_cms` | integer | no | **ID** filter: exactly this page, as `id_product` of `somcp_search_products`; a page the shop does not have gives an empty list |
| `link_rewrite` | string | no | **URL** filter: part of the page's URL alias, the name `pages[].link_rewrite` has in the answer and in `somcp_update_cms_seo`, compared without case |
| `active` | boolean | no | **Displayed** filter: `true` displayed pages only, `false` hidden only; both when left out |
| `title` | string | no | part of the **Title**, compared without case |
| `meta_title` | string | no | **Meta title** filter: part of the page's Meta title (column `head_seo_title`), the name `pages[].meta_title` has in the answer and in `somcp_update_cms_seo`, compared without case |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

No paging: a shop has a few dozen pages at most, and every matching page is returned.

**Returns** - `pages[]` with `id_cms` (**ID**, the identifier `somcp_update_cms_seo` and `somcp_audit_cms_seo` take), `link_rewrite` (**URL**), `title` (**Title**, column `meta_title`), `meta_title` (**Meta title**, column `head_seo_title`, empty string when not set), `position` (**Position**, from 0 as in the other tools; the screen shows it from 1), `active` (**Displayed**), `id_cms_category`, `url` (the page's address on the storefront); `categories[]`, every category of the shop, root included, with `id_cms_category`, `name`, `description`, `id_parent` (`0` for the root), `position` (from 0), `active`, `pages_count` (pages directly in it); then `total_count` (pages returned) and `language` (ISO code the texts are in). Pages sorted by category then position, categories by `id_parent` then position.

**Errors** - every problem at once, nothing read, each under "Nothing was read. Correct these and call again:": a field the tool does not take (`<field>: not a field of this tool`); an `id_cms_category` that is not a whole number above 0: `id_cms_category: a whole number above 0` (`"3"` is read as 3); an unknown one: `id_cms_category: no page category of this shop has the identifier <id> - find it with somcp_list_cms_pages`, whose `categories[]`, called without the filter, list them; a non-boolean `active`: `active: true or false`; a non-string `title`: `title: a string, part of the page Title`; an `id_cms` that is not a whole number above 0: `id_cms: a whole number above 0`; a non-string `link_rewrite`: `link_rewrite: a string, part of the page URL`; a non-string `meta_title`: `meta_title: a string, part of the page Meta title`; an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages. No page matching is not an error: `pages` is empty.

**Notes** - the screen's **Title** is the column `meta_title` and its **Meta title** is `head_seo_title`: the tool names them `title` and `meta_title`, as `somcp_update_cms_seo`, never by the column name. Categories are returned because no other tool gives an `id_cms_category`. Only the token's shop (`cms_shop`, `cms_category_shop`, `id_shop` of the `_lang` tables). The content of a page is not returned.

Same columns and grid queries on 8.0.1 and 9.0.0. 8.0.1 also has a **Position** filter, which 9.0.0 replaces with a **Rearrange** button for drag and drop: the tool takes no position filter on either, since the answer gives the position of every page, from 0, and a filter from 1 beside it would invite an off-by-one.

#### `somcp_get_cms_page`
**State**: written · [issue #24](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/24)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.content.read`

**Does** - returns one CMS page of the token's shop with everything its edit form under **Design > Pages** shows: category, title, meta title, meta description, meta keywords (PrestaShop 8 only), friendly URL, page content, indexation and display.

**Do not use for** - listing pages or finding a page by its title (→ `somcp_list_cms_pages`), the SEO checks of the pages (→ `somcp_audit_cms_seo`), changing a page's SEO texts (→ `somcp_update_cms_seo`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms` | integer | yes | **ID** of the list, from `somcp_list_cms_pages`. A page the token's shop does not have (absent from `cms_shop`) is refused as unknown, never read from another shop, by the same check as `somcp_update_cms_seo` |
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

**Returns** - `id_cms`; `language` (ISO code the texts are in); `id_cms_category` (**Page category**, named as in `somcp_list_cms_pages`, whose `categories[]` give its name); `title` (**Title**, column `meta_title`); `meta_title` (**Meta title**, column `head_seo_title`, empty string when not set); `seo_title` (the title search engines read: `meta_title` when filled, `title` otherwise, built by the same function as `somcp_update_cms_seo`); `meta_description` (**Meta description**); `meta_keywords` (**Meta keywords**, PrestaShop 8 only, `null` on 9, read from `CMS::$definition`, never the version number); `link_rewrite` (**Friendly URL**); `content` (**Page content**, the stored HTML as is, never cut, empty string when not set); `indexation` (**Indexation by search engines**, boolean); `active` (**Displayed**, boolean); `position` (**Position**, from 0 as in `somcp_list_cms_pages` and the other tools; the screen shows it from 1); `url` (the page's address on the storefront).

Every field `somcp_update_cms_seo` takes (`meta_title`, `meta_description`, `link_rewrite`, `indexation`) has the same name here, so a value read can be given back; the texts here are plain strings in the language asked, where `somcp_update_cms_seo` takes them as an object indexed by ISO code (`{"en": "..."}`).

**Errors** - every problem at once, nothing read, each under "Nothing was read. Correct these and call again:": a field the tool does not take (`<field>: not a field of this tool`); a missing `id_cms`, or one that is not a whole number above 0: `id_cms: a whole number above 0` (`"3"` is read as 3); an unknown `id_cms`: `id_cms: no CMS page of this shop has the identifier <id> - find it with somcp_list_cms_pages`, the line `somcp_update_cms_seo` gives too; an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages.

**Notes** - the screen's **Title** is the column `meta_title` and its **Meta title** is `head_seo_title`: the tool names them `title` and `meta_title`, as `somcp_list_cms_pages` and `somcp_update_cms_seo`, never by the column name. Read through the core object `CMS`, not raw SQL. The texts are per language and per shop (`cms_lang.id_shop`). The form's **SEO preview** is built from the fields above and stores nothing.

**Differences between 8.0.1 and 9.0.0** - **Meta keywords** exists on 8.0.1 only (field and column `cms_lang.meta_keywords`): `meta_keywords` is `null` on 9.0.0. The content is a `longtext` column on both, returned whole.

### Modules

The modules present on the shop, their state and version.

**Not here**: tools published by other modules, which they declare themselves (see EXTENDING.md).

#### `somcp_list_modules`
**State**: written · [issue #25](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/25)
**Nature**: `acts(VIEW, 'AdminModulesSf')` · **Scope**: `mcp.modules.read`

**Does** - lists the modules of the token's shop as **Modules > Module Manager** shows them, installed or only on disk: name, version on disk and installed, author, description, installed or not, enabled or not, enabled on mobile or not, and whether the files on disk are newer than the installed version.

**Do not use for** - one module's details and hooks (→ `somcp_get_module`), installing (→ `somcp_install_module`), enabling or disabling (→ `somcp_set_module_active`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `status` | string | no | the **Status** filter: `enabled`, `disabled` (installed but disabled), `installed`, `uninstalled` (on disk only); every module when left out (**Show all modules**). Any other value refused, naming the accepted ones |
| `query` | string | no | part of the technical name or of the display name (the search field), compared without case |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per module, sorted by `display_name`: `name` (technical name, the module's folder, the identifier `somcp_get_module`, `somcp_install_module` and `somcp_set_module_active` take); `display_name` (card title, in the language of the request); `version` (version of the files on disk); `version_installed` (`module.version`, `null` when not installed); `needs_upgrade` (`true` when the files on disk are newer than `version_installed`, the core's rule of `Module::needUpgrade()`); `author` ("by ..."); `description` (card text); `installed` (boolean); `active` (**Enabled** / **Disabled** on the token's shop, from `module_shop`, `false` when not installed); `active_on_mobile` (**Enable Mobile** / **Disable Mobile**, from bit 4 of `module_shop.enable_device`, `null` when not installed). Then `total_count` (modules matching, before `limit`) and `offset`.

**Errors** - in the shared envelope, every problem at once, nothing read: a field the tool does not take (`<field>: not a field of this tool`), an unknown `status`, a non-string `query`, an out-of-range `limit` or `offset`, each naming the field. No module matching: not an error, `modules` is empty.

**Notes** - read from the shop only: what is on disk through `Module::getModulesOnDisk(true)` (each module's `config.xml` or class), the state from `module` and `module_shop` for the token's shop, never the global context's. The marketplace's **Upgrade available**, its categories (**Category** filter) and **Recently used** need a network call or are not stored by the shop: not returned. Stays on the legacy `Module` class, present on both versions. `somcp` lists itself like any other module. `id_module` is never returned: the name is the identifier everywhere.

**Differences between 8.0.1 and 9.0.0** - the bulk actions differ (**Enable Mobile** / **Disable Mobile** on 8.0.1, **Delete** on 9.0.0) and the per-module action is **Upgrade** on 8.0.1, **Update** on 9.0.0; nothing the tool reads changes, `enable_device` is in `module_shop` on both.

#### `somcp_get_module`
**State**: written · [issue #26](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/26)
**Nature**: `acts(VIEW, 'AdminModulesSf')` · **Scope**: `mcp.modules.read`

**Does** - returns one module of the token's shop as its card under **Modules > Module Manager** shows it, installed or only on disk, plus what the card does not show: the hooks it is attached to on the token's shop (**Design > Positions**).

**Do not use for** - listing or searching modules (→ `somcp_list_modules`), installing (→ `somcp_install_module`), enabling or disabling (→ `somcp_set_module_active`), the module's own settings page behind **Configure**.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes | technical name, the module's folder, exactly as `modules[].name` of `somcp_list_modules`; compared without case |

**Returns** - the same fields, same names and same rules as one entry of `modules[]` in `somcp_list_modules`: `name`, `display_name` (card title), `version` (files on disk), `version_installed` (`null` when not installed), `needs_upgrade`, `author`, `description`, `installed`, `active` (on the token's shop, `false` when not installed), `active_on_mobile` (`null` when not installed). Plus `hooks[]`: the hooks the module is attached to on the token's shop, each with `name` (`hook.name`) and `position` (`hook_module.position`, as stored), sorted by hook name; empty when the module is not installed or not enabled on this shop.

**Errors** - in the shared envelope, every problem at once, nothing read: a field the tool does not take (`<field>: not a field of this tool`); a missing, empty or non-string `name`, naming the field. A `name` neither on disk nor in `module`: `name: no module of this shop has the technical name <name> - find it with somcp_list_modules` (`SomcpTool::notFound()`).

**Notes** - read from the shop only, as `somcp_list_modules`, through the same row builder (`SomcpModules`): the files through `Module::getModulesOnDisk(true)`, the state from `module`, `module_shop` and `hook_module` for the token's shop, never the global context's. A module installed whose folder is gone is returned from the database alone, with an empty `version`. `id_module` is never returned: the name is the identifier everywhere. **Upgrade available**, **Category** and **Recently used** are not returned, for the reasons given in `somcp_list_modules`.

**Differences between 8.0.1 and 9.0.0** - the upgrade action reads **Upgrade** on 8.0.1, **Update** on 9.0.0, and the mobile actions are bulk actions on 8.0.1 only; nothing the tool reads changes, `module`, `module_shop` and `hook_module` have the same columns.

#### `somcp_install_module`
**State**: written · [issue #27](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/27)
**Nature**: `acts(ADD, 'AdminModulesSf')` · **Scope**: `mcp.modules.write`

**Does** - installs one module whose folder is already in the shop's `modules` folder and that is not installed yet - the **Install** button of its card under **Modules > Module Manager** - and answers with the module as `somcp_get_module` reads it afterwards.

**Do not use for** - upgrading, uninstalling, resetting or deleting a module; enabling or disabling one (→ `somcp_set_module_active`); uploading a module archive (→ `somcp_upload_module`); downloading a module from a link or the marketplace.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes | technical name, the module's folder, exactly as `modules[].name` of `somcp_list_modules` (`status: "uninstalled"` lists the candidates); compared without case |

**Returns** - the fields of `somcp_get_module` read back after the install, through the same function (`SomcpModules::read()`): `name`, `display_name`, `version`, `version_installed`, `needs_upgrade`, `author`, `description`, `installed`, `active`, `active_on_mobile`, `hooks[]`.

**Errors** - in the shared envelope, every problem at once, under "Nothing was installed. Correct these and call again:":
- a field the tool does not take (`<field>: not a field of this tool`); a missing, empty or non-string `name`, naming the field;
- a `name` neither on disk nor installed, or installed with its folder gone: `name: no module of this shop has the technical name <name> - find it with somcp_list_modules` (`SomcpTool::notFound()`);
- a module already installed: refused and nothing changed (`name: already installed, version <v> - upgrade it with somcp_upgrade_module and a newer archive`), since `ModuleManager::install()` would upgrade it;
- the core refuses (no **add** right on the Module Manager), or the module's `install()` returns `false`: "The module was not installed", with the module's own messages (`getErrors()`) and the state read back, so nothing is claimed as installed.

**Notes** - disk only: no archive, no `url`, no `upload_ref`; `install()` gets no source. Through the core's `ModuleManager` of the back office's container (`SomcpModules::throughCore()`): the MCP endpoint is no back office request, so the back office's kernel is booted for the call and its container made the one every module reaches, then the previous one put back. Before that, `ps_distributionapiclient`, shipped with PrestaShop, threw on `actionBeforeInstallModule` for want of a back office service, and nothing could be installed from an assistant. With the token's employee on the context for the length of the call, the module's install checks (name, `ps_versions_compliancy`, `dependencies`, override conflicts, in `Module::install()`), hooks and tabs are set up as from the back office. Every shop is in maintenance during the install and put back as it was once the module is installed; a failed install leaves it in maintenance and says so. What the module prints while installing is dropped. On a multistore installation the core installs for the shops of the current context. Not replayable: on a lost answer, read `somcp_get_module` instead of retrying.

**Differences between 8.0.1 and 9.0.0** - the install event is dispatched even after a failed install on 8.0.1, only after a success on 9.0.0: the state is read back in both cases rather than assumed. 9.0.0 also refreshes the translation catalogues before installing and can install from a remote zip; neither changes the tool.

#### `somcp_upload_module`
**State**: written · [issue #171](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/171)
**Nature**: `acts(ADD, 'AdminModulesSf')` · **Scope**: `mcp.modules.upload`

**Does** - puts a module archive (`.zip`) the employee deposited on the shop into the `modules` folder and installs it right after - the **Upload a module** button of **Modules > Module Manager** - and answers as `somcp_install_module` does.

**Do not use for** - a module already on disk (→ `somcp_install_module`); upgrading a module from a newer archive (→ `somcp_upgrade_module`); a module from a link or the marketplace.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `upload_ref` | **Upload a module** | string | yes | a `.zip` already on the shop: attached to the chat, or from `somcp_upload_file` with `for` set to `somcp_upload_module` (kind `archive`, never by `url`), the same `upload_ref` as the product images |

No `url`: a link read anywhere would become code running on the shop. No `name`: it comes from the archive.

**Returns** - the answer of `somcp_install_module` (the fields of `somcp_get_module` read back after the install), plus `uploaded: true` and `file_name`, the name the archive was deposited under.

**Errors** - in the shared envelope, `SomcpTool::refused('installed', ...)`: every problem at once under "Nothing was installed.", each named with its field, nothing extracted:
- an unknown argument, through `SomcpTool::unknownFields()`; `url` is refused with the reason: an archive comes by `upload_ref` only;
- `upload_ref` missing, empty or not a string;
- a deposit unknown, expired or already used, or larger than 64 MB: the wording of the product tools;
- not a `.zip` (a tarball included): "only a .zip archive is accepted";
- not one top folder `<name>/` holding `<name>/<name>.php`, `<name>` other than letters, digits, `-` and `_`, an entry with an absolute path, `..` or a backslash, a symbolic link entry;
- unpacked size above 10 times the archive or 256 MB;
- a folder `modules/<name>` already there, in any case: "a module named <name> is already on the shop; nothing was replaced";
- the install itself failing: the answer of `somcp_install_module` ("The module was not installed", the module's own messages and the state read back), after the folder was removed again.

**Notes**
- The archive is not handed to the core's `ZipSourceHandler`, which extracts every entry over any folder already there and turns the install of a module already installed into an upgrade: it is checked, extracted into a folder of its own under `var/somcp/`, moved to `modules/<name>` in one rename, then installed through the function of `somcp_install_module`, with no source. The work folder is removed whatever happens.
- Its own scope, `mcp.modules.upload`, apart from `mcp.modules.write`: the merchant consents to code from outside the shop running with the shop's rights.
- Not replayable: the deposit is used up by the call; on a lost answer, read `somcp_get_module` instead of retrying.
- Multistore: the folder is shared by every shop, the install is for the shops of the token's context, as `somcp_install_module`, whose maintenance it shares: every shop is closed during the install.

**Differences between 8.0.1 and 9.0.0** - the core's archive handling is identical on both, and 9.0.0's `RemoteZipSourceHandler` is not used; the install differs as described in `somcp_install_module`.

#### `somcp_upgrade_module`
**State**: written · [issue #255](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/255)
**Nature**: `acts(ADD, 'AdminModulesSf')` · **Scope**: `mcp.modules.upload`

**Does** - upgrades one installed module, as **Upgrade** in **Modules > Module Manager** does: from a newer archive (`.zip`) the employee deposited on the shop, its files replaced first, or from newer files already in its folder (put there by FTP or a deployment); then its `upgrade/` scripts run. Answers with the module as `somcp_get_module` reads it afterwards.

**Do not use for** - a new module (→ `somcp_upload_module`); a module on disk but not installed (→ `somcp_install_module`); downgrading; enabling or disabling (→ `somcp_set_module_active`); a module from a link or the marketplace.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `upload_ref` | **Upload a module** | string | one of the two | a `.zip` already on the shop: attached to the chat, or from `somcp_upload_file` with `for` set to `somcp_upgrade_module` (kind `archive`, never by `url`) |
| `name` | **Upgrade** on the module's card | string | one of the two | the technical name of a module whose files on disk are newer than its installed version (`needs_upgrade` in `somcp_get_module`) |

**Then** - with `upload_ref`: the archive passes the checks of `somcp_upload_module` (`SomcpToolUploadModule::check()`, shared), except that `modules/<name>` must exist under that exact name; the module must be installed on the token's shop, and the version the archive declares, read without running it (`<version>` of `config.xml`, else `$this->version` of `<name>.php`), must be higher than the installed one. The current folder is moved aside into the work folder and the new one moved in, each in one rename. With `name`: the module must be installed and its files newer than the installed version.

Then the new files are checked as the back office checks a module before installing it, since the core's `ModuleManager::upgrade()` checks none of it: the module loads and answers with the expected version, `Module::checkCompliancy()` (its `ps_versions_compliancy`), and each of its `dependencies` installed. With an archive, any of these failing puts the previous folder back. Then `ModuleManager::upgrade($name, $source)`, through `SomcpModules::throughCore()` in the back office's container, which runs `Module::initUpgradeModule()`, the module's `upgrade/upgrade-X.Y.Z.php` scripts and `upgradeModuleVersion()`. `$source` is the archive when there is one: without it, `ps_distributionapiclient` downloads the module from PrestaShop's servers on `actionBeforeUpgradeModule`, over the files the merchant sent. With `name` there is no source, as with the back office's **Upgrade** button: a module PrestaShop distributes itself may then be downloaded in its latest version, as there.

**Maintenance** - every shop goes in maintenance (`PS_SHOP_ENABLE` 0) from just before the files change until the upgrade went through, then each shop is put back as it was (one already in maintenance stays so). A refusal that changed nothing puts it back too. A failed upgrade leaves the shop in maintenance and says so: the merchant reopens it in **Shop Parameters > General > Maintenance** once they have checked it. The module's endpoints still answer during maintenance.

**Returns** - `upgraded: true`, `version_before`, `version_after`, then the fields of `somcp_get_module` read back.

**Errors** - in the shared envelope, `SomcpTool::refused('upgraded', ...)`: every problem at once under "Nothing was upgraded.":
- both `upload_ref` and `name`, or neither; the refusals of `somcp_upload_module` for the archive;
- no folder `modules/<name>` (→ `somcp_upload_module`); on disk but not installed (→ `somcp_install_module`); `name` of no module (`name: no module of this shop has the technical name <name> - find it with somcp_list_modules`); files on disk at the installed version ("nothing to upgrade");
- no version in the archive; a version not higher than the installed one;
- the new files not loading as a module, answering with another version (the module already loaded in this request), made for other PrestaShop versions, or needing a module that is not installed;
- the upgrade itself failing: "The module was not upgraded", the core's message and the state read back. The new files stay, and what the scripts already changed in the database stays changed, as in the back office; the shop stays in maintenance.

**Notes**
- Loading the new files runs the module's main file, as the Module Manager does for every module it lists.
- Same scope as `somcp_upload_module`, `mcp.modules.upload`: code from outside the shop runs with the shop's rights.
- Not replayable: the deposit is used up; on a lost answer, read `somcp_get_module` instead of retrying. The assistant asks the merchant first.
- Multistore: the files are shared by every shop; the upgrade runs once, and every shop is in maintenance meanwhile.
- The core's access check asks the same right: `AdminModuleDataProvider::isAllowedAccess('upgrade')` wants `add` on `AdminModulesSf`.

**Differences between 8.0.1 and 9.0.0** - `ModuleManager::upgrade()` is the same on both (8.0.1 line 164, 9.0.0 line 195); 9.0.0 also disables the module's hooks while it runs.

#### `somcp_set_module_active`
**State**: written · [issue #28](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/28)
**Nature**: `acts(EDIT, 'AdminModulesSf')` · **Scope**: `mcp.modules.write`

**Does** - enables or disables one installed module on the token's shop - the **Enable** / **Disable** actions of its card under **Modules > Module Manager** - and, on PrestaShop 8 only, **Enable Mobile** / **Disable Mobile**; answers with the module as `somcp_get_module` reads it afterwards.

**Do not use for** - installing (→ `somcp_install_module`), uploading (→ `somcp_upload_module`), upgrading (→ `somcp_upgrade_module`), resetting, uninstalling or deleting a module; the module's own settings behind **Configure**.

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `name` | - (`data-tech-name` of the card) | string | yes | technical name, the folder, as `modules[].name` of `somcp_list_modules`; compared without case |
| `active` | **Enable** / **Disable** | boolean | no | the state wanted on the token's shop |
| `active_on_mobile` | **Enable Mobile** / **Disable Mobile** | boolean | no | PrestaShop 8 only; refused on 9 |

At least one of `active` and `active_on_mobile` is given; both may be given in one call (`active: true, active_on_mobile: false` brings back a module that was off on mobile).

**Returns** - the fields of `somcp_get_module` read back after the change, through the same function (`SomcpModules::read()`): `name`, `display_name`, `version`, `version_installed`, `needs_upgrade`, `author`, `description`, `installed`, `active`, `active_on_mobile`, `hooks[]`; plus `changed`: each field written with `from` and `to` (`{"active": {"from": true, "to": false}}`).

**Errors** - in the shared envelope, under "Nothing was changed. Correct these and call again:", the mistakes of the call all at once, then the state of the module once they are corrected:
- a field the tool does not take (`<field>: not a field of this tool`), a missing, empty or non-string `name`, a non-boolean `active` / `active_on_mobile`, neither given, or `active_on_mobile` on PrestaShop 9 (`active_on_mobile: not on PrestaShop <version>, which has no mobile switch for modules; leave the field out`): each naming the field;
- a `name` that is not a module of the shop: `name: no module of this shop has the technical name <name> - find it with somcp_list_modules` (`SomcpTool::notFound()`);
- a module not installed: refused, naming `somcp_install_module`;
- a call that would change nothing (the state read is already the one asked): refused and nothing called, since the core answers success and fires the hooks anyway;
- `active_on_mobile: true` on a module disabled and not enabled in the same call: refused;
- `name` is `somcp` with `active: false`: refused - the answer would never reach the assistant and every connected assistant would lose its access;
- on PrestaShop 8, disabling a module also enabled on another shop than the token's: refused, since the core would disable it on every shop;
- the core refuses (no **edit** right on the Module Manager) or the module's `enable()` / `disable()` returns `false`: "The module was not changed as asked", with the module's own messages (`getErrors()`) and the state read back.

**Notes**
- The state is read first with `SomcpModules::read()`, then only the actions that change something are called, in this order: `enable()`, then the mobile action; `disable()` alone when disabling. Through the core's `ModuleManager` (`SomcpModules::throughCore()`, shared with `somcp_install_module`), with the token's employee on the context; what the module prints is dropped.
- The core never checks the current state: it answers success to a disable of a disabled module, and a second **Enable Mobile** corrupts `module_shop.enable_device` (7 becomes 11). Hence the refusal of a call that would change nothing.
- **Disable** deletes the `module_shop` row (and `module.active` becomes 0 once no shop row is left); **Enable** inserts one with `enable_device` 7, so enabling again a module disabled earlier brings mobile back on: pass `active_on_mobile: false` with `active: true` to keep it off there.
- Disabling a module also disables its back-office tabs; its hooks stay attached, but `hooks[]` reads them for an enabled module only.
- Not replayable: on a lost answer, read `somcp_get_module` instead of retrying.

**Differences between 8.0.1 and 9.0.0**
- Mobile actions exist on 8.0.1 only; on 9.0.0 `Module::enableDevice()` / `disableDevice()` are deprecated and do nothing.
- `Module::disable()` deletes the rows of every shop on 8.0.1, of the context shops on 9.0.0.

### Appearance

What the storefront looks like: the main menu of `ps_mainmenu`, the active theme and its page layouts.

**Not here**: content of the pages (→ Content).

#### `somcp_list_menu_links`
**State**: written · [issue #29](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/29)
**Nature**: `acts(VIEW, 'AdminModulesSf')` · **Scope**: `mcp.appearance.read`

**Does** - lists the entries of the storefront's main menu, the **Selected items** of the **Menu Top Link** panel on the configuration page of the **Main menu** module (`ps_mainmenu`), in menu order, and its custom links (**Link list**).

**Do not use for** - the shop's categories (→ `somcp_list_categories`), its CMS pages (→ `somcp_list_cms_pages`); adding an entry (→ `somcp_add_menu_link`) or changing a custom link (→ `somcp_update_menu_link`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, for the labels; refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

**Returns** - `module_active`; `entries[]` (**Selected items**, in menu order) with `position` (**Change position**, 1 for the first), `item` (the stored code, `CAT3`, `ALLMAN0`, ...), `type` (the group of **Available items**: `category`, `product`, `cms_page`, `cms_category`, `all_brands`, `brand`, `all_suppliers`, `supplier`, `link`, `shop`), the identifier that applies under the name its list tool gives (`id_category`, `id_product`, `id_cms`, `id_cms_category`, `id_manufacturer`, `id_supplier`, `id_link`, `id_shop`; none for **All brands** / **All suppliers**), `label` (category, product, brand, supplier or shop name, CMS page title, CMS category name, "All brands" / "All suppliers", custom link label, in the requested language), `url` and `new_window` (**Link** and **New window**, custom links only, `null` otherwise); `skipped[]`, the stored codes whose object no longer exists, which the screen hides; `custom_links[]` (**Link list**), every custom link of the token's shop, in the menu or not, with `id_link`, `label`, `url`, `new_window`, `in_menu`, `translated` (`false` when the link has no label in the language read, so `label` and `url` are the default language's, as the storefront shows them); `language` (ISO code the labels are in).

**Errors** - every problem at once, nothing read, under "Nothing was read. Correct these and call again:": an unknown argument: `<name>: not a field of this tool`; an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages, among the other problems. `ps_mainmenu` not installed or not enabled on the token's shop is not an error: `module_active: false` and empty lists.

**Notes** - reads `MOD_BLOCKTOPMENU_ITEMS` (comma-separated `PREFIX<id>`, falling back from the shop to its group and the global value, as the module) and the tables `linksmenutop` and `linksmenutop_lang` (`id_shop` 0 or the token's shop), never the module's widget, which builds the storefront tree for a customer group and caches it. Labels through the core classes, as the module's `makeMenuOption()`; a custom link without a label in the requested language takes the default language's, as the module does. On the storefront a category entry shows the category and its whole active subtree, a CMS category its sub-categories and pages, **All brands** / **All suppliers** every brand / supplier. Read only.

Same labels, configuration key, prefixes, tables and columns on 8.0.1 (`ps_mainmenu` 2.3.1) and 9.0.0 (2.3.4); 9.0.0 always applies the shop filter to CMS categories and pages, with the same result.

#### `somcp_add_menu_link`
**State**: written · [issue #30](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/30)
**Nature**: `acts(EDIT, 'AdminModulesSf')` · **Scope**: `mcp.appearance.write`

**Does** - adds one entry to the storefront's main menu, one line of **Selected items** in the **Menu Top Link** panel of the **Main menu** module (`ps_mainmenu`), what **Add** then **Save** do, at the end or at a given position. For a custom link, it can create the link (**Add a new link** panel) in the same call, since a custom link outside the menu shows nowhere on the storefront.

**Do not use for** - changing a custom link already created (→ `somcp_update_menu_link`); reading the menu (→ `somcp_list_menu_links`); removing or moving an entry (not offered yet).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `type` | enum | yes | the group of **Available items**: `category`, `product`, `cms_page`, `cms_category`, `all_brands`, `brand`, `all_suppliers`, `supplier`, `link`, `shop` - the values of `entries[].type` of `somcp_list_menu_links` |
| `id_category`, `id_product`, `id_cms`, `id_cms_category`, `id_manufacturer`, `id_supplier`, `id_link`, `id_shop` | integer | the one of `type` | the object, under the name its list tool gives it; none for `all_brands` / `all_suppliers`; an identifier that does not go with `type` is refused; `id_link` left out with `link` creates a new custom link |
| `languages` | array of ISO codes | no | new custom link only: the languages filled, among the shop's active languages, all of them by default |
| `label` | object keyed by ISO code | new custom link | **Label**, the text of the entry; not empty in every language filled; a language left out gets the default language's text; 128 characters at most |
| `url` | object keyed by ISO code | new custom link | **Link**, same rule as `label`; an absolute `http` / `https` address or a path starting with `/`, checked with `Validate::isUrl()`; 128 characters at most |
| `new_window` | boolean | no | **New window**, new custom link only; `false` by default, as the form |
| `position` | integer | no | **Change position**: 1 for the first entry; at the end when left out; above the number of entries plus one is refused |
| `language` | string | no | ISO code of one of the shop's active languages for the labels of the answer, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

**Returns** - `added`: the new entry in the shape of `entries[]` of `somcp_list_menu_links` (`position`, `item`, `type`, the identifier, `label`, `url`, `new_window`); `created_link`: `true` when a custom link was created, its `id_link` in `added`; `entries`: the whole menu read back after the save; `language`.

**Errors** - one message per refused field, nothing written: `ps_mainmenu` not installed or not enabled on the token's shop; the identifier of `type` left out (`<field>: required with type <type>`) or not a whole number above 0 (`<field>: a whole number above 0`, a string of digits taken as the number); an object that does not exist, a custom link of another shop included (`<field>: no <thing> of this shop has the identifier <id> - find it with <tool>`, with the list tool its row names; for a shop, `id_shop: no shop of this installation has the identifier <id> - find it with somcp_ping`); an entry already in the menu, which the screen no longer offers in **Available items**; `id_link` given with `label`, `url`, `new_window` or `languages`; an empty `label` or `url`, an invalid `url`, a text over 128 characters, `position` out of range, a `languages` code the shop does not have; an unknown argument; an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages, among the other problems, the entry being looked up in the shop default meanwhile.

**Notes** - writes the list the way **Save** does: `MOD_BLOCKTOPMENU_ITEMS` read as `somcp_list_menu_links` reads it, the code inserted at its place, the codes reported in `skipped` kept where they are, then `Configuration::updateValue(..., false, $idShopGroup, $idShop)` for the token's shop only, never the global row. A new custom link goes through the module's `Ps_MenuTopLinks::add()` with the token's `id_shop`, one row per language, before the list is written; the module itself accepts an empty label and link, so the tool checks them. The module's cache is then emptied through its public `hookActionCategoryUpdate()`, so the storefront shows the change at once. Not replayable: on a lost answer, read `somcp_list_menu_links`.

Same panels, labels, configuration key, tables and submit code on 8.0.1 (`ps_mainmenu` 2.3.1) and 9.0.0 (2.3.4).

#### `somcp_update_menu_link`
**State**: written · [issue #31](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/31)
**Nature**: `acts(EDIT, 'AdminModulesSf')` · **Scope**: `mcp.appearance.write`

**Does** - changes one custom link of the storefront's main menu, the **Update link** panel of the **Main menu** module (`ps_mainmenu`), opened by **Edit** in **Link list**: its **Label**, **Link** and **New window**. Only what is given changes, where the form writes every field and empties those left blank.

**Do not use for** - adding an entry to the menu, or creating a custom link (→ `somcp_add_menu_link`); reading the menu (→ `somcp_list_menu_links`); moving or removing an entry, deleting a custom link (not offered yet).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_link` | integer | yes | **Link ID**, the custom link, from `custom_links[].id_link` of `somcp_list_menu_links`; a link of another shop is refused as unknown |
| `languages` | array of ISO codes | no | the languages being filled, among the shop's active languages, all of them by default; the other languages keep their texts |
| `label` | object keyed by ISO code | no | **Label**; a plain string accepted when one language is filled; given, required and not empty in every language filled; 128 characters at most |
| `url` | object keyed by ISO code | no | **Link**, same rule as `label`; an absolute `http` / `https` address or a path starting with `/`, checked with `Validate::isUrl()`; 128 characters at most |
| `new_window` | boolean | no | **New window** |
| `language` | string | no | ISO code of one of the shop's active languages for the labels of the answer, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |
| `in_menu` | boolean | no | as `custom_links[]` of `somcp_list_menu_links` returns it, accepted unchanged so a row read there can be given back whole; a different value is refused: `true` for a link out of the menu as `in_menu: not changed by this tool - put the link in the menu with somcp_add_menu_link`, `false` for a link in the menu as `in_menu: not changed by this tool - taking a link out of the menu is done in Modules > Module Manager > Main menu > Configure` |
| `translated` | boolean | no | as `custom_links[]` returns it for the language read (the one filled when a single one is, otherwise `language`), accepted unchanged, a different value refused as worked out by the shop; `false` with the default-language `label` / `url` the list showed leaves that language as it is: no change |

At least one of `label`, `url`, `new_window` is required.

**Returns** - `changed`; `id_link`; `changes`, each field written with `field` (`label (<iso>)`, `url (<iso>)`, `new_window`), `old` and `new`; `link`, the link after the save in the shape of `custom_links[]` of `somcp_list_menu_links` (`id_link`, `label`, `url`, `new_window`, `in_menu`, `translated`); `language`. A call that would change nothing writes nothing and answers `changed: false`, with empty `changes` and a `reason`.

**Errors** - one message per refused field, nothing written: `ps_mainmenu` not installed or not enabled on the token's shop; `id_link` left out (`id_link: required, from custom_links of somcp_list_menu_links`) or not a whole number above 0 (`id_link: a whole number above 0`, a string of digits taken as the number); an unknown `id_link` for the token's shop (`id_link: no custom link of this shop has the identifier <id> - find it with somcp_list_menu_links`); none of `label`, `url`, `new_window` given; an empty `label` or `url`, an invalid `url`, a text over 128 characters, a `languages` code the shop does not have (the texts of `somcp_add_menu_link`); an unknown argument; an `in_menu` or `translated` that differs from what the list reads; an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages, among the other problems, the link being looked up in the shop default meanwhile.

**Notes** - reads the link raw from `linksmenutop` and `linksmenutop_lang` (`id_shop` 0 or the token's shop; `Ps_MenuTopLinks::getLinkLang()` escapes the texts for display), merges what is given, then calls the module's `Ps_MenuTopLinks::update()` with the full texts of every language that has a row and the link's own `id_shop`, so a link shared by every shop stays shared. `update()` only runs `UPDATE`: a language filled that has no `linksmenutop_lang` row (installed after the link was created) gets one first, with the columns of `Ps_MenuTopLinks::add()`. The link is read back to confirm the write, then the module's cache is emptied through its public `hookActionCategoryUpdate()`, as `somcp_add_menu_link`. The link's place in the menu never changes. On a lost answer, read `somcp_list_menu_links` and compare before calling again.

Same panel, labels, message, tables and code on 8.0.1 (`ps_mainmenu` 2.3.1) and 9.0.0 (2.3.4).

#### `somcp_get_theme`
**State**: written · [issue #32](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/32)
**Nature**: `acts(VIEW, 'AdminThemes')` · **Scope**: `mcp.appearance.read`

**Does** - returns the theme of the token's shop as **Design > Theme & Logo** shows it: the theme in use and the other installed themes, the four logos of the **Logo** panel, and the layout of every page of **Choose layouts**.

**Do not use for** - the meta titles and URLs of the pages (→ `somcp_list_meta_pages` / `somcp_update_meta_page`); changing a page layout (→ `somcp_update_theme_setting`); the main menu (→ `somcp_list_menu_links`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, for the page titles and descriptions; refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |

**Returns** - `theme` (**My current theme**) with `name` (technical name, `classic`), `display_name` (`Classic`), `version` (**Version**), `author` (**Designed by**), `parent` (the parent theme of a child theme, `null` otherwise); `other_themes[]`, the other cards of **My theme for ... shop**, with `name`, `display_name`, `version`, `author`, for information only; `logos` with `header` (**Header logo**), `mail` (**Mail logo**), `invoice` (**Invoice logo**), `favicon` (**Favicon**), a `download_ref` each, `null` when unset or when the file is missing, and `mail_uses_header` / `invoice_uses_header`, `true` when that logo is `null` and the shop prints the header logo instead; `default_layout`, the layout key of a page without its own setting; `available_layouts[]` (the options of **Layout**) with `layout` (key, `layout-full-width`, ...), `name` (**Full Width**), `description`; `pages[]` (rows of **Choose layouts**, in the screen's order) with `page` (the meta page name, the same value as `somcp_list_meta_pages`), `title` (**Page**, the page name when the title is empty, as the screen), `description` (**Description**), `layout` (**Layout**, the key), `uses_default` (`true` when the page has no setting of its own); `language` (ISO code the texts are in).

**Errors** - an unknown argument: `<name>: not a field of this tool` and an unknown `language`: `SomcpTool::languageProblem()`, with the shop's languages, among the other problems, together under "Nothing was read. Correct these and call again:"; the theme's folder or configuration missing from the server.

**Notes** - the token's shop only: its `shop.theme_name` and its own `config/themes/<theme>/shop<id>.json`, read through the core `ThemeManagerBuilder::buildRepository()` and `ThemeRepository::getInstanceByName()` as the back office, never by parsing the file; on a multistore installation two shops can use different themes. Layouts from `theme_settings.layouts` and `theme_settings.default_layout`, a page absent from the first using the second, as `PageLayoutCustomizationFormFactory`; available layouts from `meta.available_layouts`. Pages from `Meta::getAllMeta()`, as `GetPagesForLayoutCustomizationHandler`. Logos from `PS_LOGO`, `PS_LOGO_MAIL`, `PS_LOGO_INVOICE`, `PS_FAVICON` for the token's shop, files in `img/`. Read only, apart from the configuration cache the core writes on a first read, as the back office does. Logo upload, **Use this theme**, theme deletion, **Add new theme**, **Export current theme** and the two `ps_themecusto` tabs are not part of this tool.

Classic 2.0.6 on 8.0.1, 3.0.2 on 9.0.0; 9.0.0 also ships **Hummingbird** (installed, not used on the test shop). **Best sales** is titled **Best sellers** on 9.0.0 and `index` has no description there: the tool returns what the shop stores, never a fixed list.

#### `somcp_update_theme_setting`
**State**: written · [issue #33](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/33)
**Nature**: `acts(EDIT, 'AdminThemes')` · **Scope**: `mcp.appearance.write`

**Does** - sets the layout of one page of the theme of the token's shop, as one row of **Design > Theme & Logo > Choose layouts** followed by **Save** does. Every other page keeps its setting.

**Do not use for** - installing or switching themes; uploading a logo; the meta title or URL of a page (→ `somcp_update_meta_page`); reading the layouts (→ `somcp_get_theme`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `page` | string | yes | **Page**, the row: the meta page name, as `somcp_get_theme` returns it in `pages[].page` and `somcp_list_meta_pages` in `page`; refused when it is not one of the rows of **Choose layouts** (`Meta::getAllMeta()`) |
| `layout` | string | yes | **Layout**: a key of `available_layouts[].layout` of `somcp_get_theme`, for example `layout-left-column`; refused when the theme of the shop does not offer it, the error lists the keys it does offer |

**Returns** - `changed`; `page`; `previous_layout`, the layout the page had, the default layout when it had none of its own; `layout`, the layout now set; `theme`, technical name of the theme changed (`classic`). A call that would change nothing writes nothing and answers `changed: false` with `page`, `layout`, `theme` and a `reason`.

**Errors** - one message per refused field, nothing written: `page` or `layout` missing or not a string, an unknown `page`, a `layout` the theme does not offer (with the keys offered), an unknown argument (`<name>: not a field of this tool`); demo mode; the theme's folder or configuration missing from the server, as `somcp_get_theme`.

**Notes** - the core setter `Theme::setPageLayouts()` replaces the whole of `theme_settings.layouts`, and the screen always sends every page: the tool reads `getPageLayouts()`, sets the one page and hands the whole array back, so no other page loses its setting, and a page with no stored value gets one only when it is the page asked. The theme is loaded as `somcp_get_theme` loads it (`ThemeManagerBuilder::buildRepository()` for the token's shop), saved by `ThemeManager::saveTheme()` from `ThemeManagerBuilder::build()`, which writes the token's shop's own `config/themes/<theme>/shop<id>.json` and no other shop's, then the Smarty cache is cleared, as `ThemePageLayoutsCustomizer::customize()` does. Refused in demo mode (`_PS_MODE_DEMO_`), as the back office. One page per call; not replayable: on a lost answer, read `somcp_get_theme`. Other theme settings (logos, switching or deleting themes, the `ps_themecusto` tabs) are out of scope on purpose.

Same form, field names and four layouts on Classic on 8.0.1 (2.0.6) and 9.0.0 (3.0.2). The page titles differ in places (**Best sales** on 8.0.1, **Best sellers** on 9.0.0): `page` is the stable name, never the title.

### System

How the shop runs: caching.

**Not here**: the connection itself (→ Diagnostics).

#### `somcp_get_cache_status`
**State**: written
**Nature**: `acts(VIEW, 'AdminPerformance')` · **Scope**: `mcp.system.read`

**Does** - returns every card of **Advanced Parameters > Performance** for the token's shop: Smarty, Debug mode, Optional features, CCC (Combine, Compress and Cache), Media servers and Caching, with warnings on the settings that slow the storefront or should not stay on in production. Read only.

**Do not use for** - clearing the cache (→ `somcp_clear_cache`); the server, PHP and database versions (→ `somcp_ping`); changing any of these settings, which stays in the back office.

**Parameters** - none.

**Returns**

| Field | On screen | Notes |
|---|---|---|
| `smarty.template_compilation` | **Template compilation** | `never`, `if_updated` or `force` (stored 0, 1, 2) |
| `smarty.cache` | **Cache** | bool |
| `smarty.multi_front_optimization` | **Multi-front optimizations** | bool |
| `smarty.clear_cache` | **Clear cache** | `never` or `everytime`, as stored |
| `debug.disable_overrides` | **Disable all overrides** | bool |
| `debug.debug_mode` | **Debug mode** | bool: the setting written in the defines file, as `DebugMode::isDebugModeEnabled()` reads it, never the `_PS_MODE_DEV_` constant of the running request |
| `debug.debug_cookie` | **Debug cookie name** | 9.0.0: `true` when debug mode is limited to a cookie, `false` otherwise; `null` on 8.0.1. The cookie name and value are never returned |
| `debug.debug_profiling` | **Debug profiler** | bool on 9.0.0, `null` on 8.0.1 |
| `optional_features.combinations` | **Combinations** | bool |
| `optional_features.features` | **Features** | bool |
| `optional_features.customer_groups` | **Customer groups** | bool |
| `ccc.smart_cache_css` | **Smart cache for CSS** | bool |
| `ccc.smart_cache_js` | **Smart cache for JavaScript** | bool |
| `ccc.apache_optimization` | **Apache optimization** | bool |
| `media_servers` | **Media server #1** to **#3** | the domains filled in, in order; empty list when none |
| `caching.use_cache` | **Use cache** | bool |
| `caching.caching_system` | **Caching system** | `CacheMemcache`, `CacheMemcached`, `CacheApc` or `CacheXcache`, as stored, even when **Use cache** is off |
| `caching.available_systems` | the options not greyed out | the systems whose PHP extension is loaded: `memcache`, `memcached`, `apc` or `apcu`, `xcache` |
| `caching.servers` | Memcache servers table | `id_memcached_server`, `ip`, `port`, `weight`; empty list when none |
| `warnings` | - | `code` and `why`, as `somcp_get_seo_settings`: `debug_mode_on`, `debug_profiling_on`, `force_compilation`, `smarty_cache_off`, `overrides_disabled`, `ccc_css_off`, `ccc_js_off`, `cache_system_unavailable` (**Use cache** is on and the stored system's extension is not loaded) |

**Errors** - an unknown argument (`<name>: not a field of this tool`). A setting the running version does not have is `null`, never an error: `debug.debug_cookie` and `debug.debug_profiling` on PrestaShop 8.0. A switch never saved reads `false`, as the back office shows it.

**Notes**
- Each `PS_` key is read for the token's shop with the helper of `somcp_get_seo_settings`, not through the back office form handlers, which take the shop from the global context. The optional features are the keys `Combination`, `Feature` and `Group::isFeatureActive()` read.
- Debug mode and the profiler are read through the core's `DebugMode` and `DebugProfiling` classes; on 8.0.1 the profiler is not on the screen, so it is `null` there.
- **Use cache** and **Caching system** live in `app/config/parameters.php` and apply to the whole installation, as debug mode does.
- The labels of **Caching system** change with the server; the keys are returned, never the labels.

9.0.0 adds **Debug cookie name**, **Debug cookie value** and **Debug profiler** to the Debug mode card; every other field, value and key is the same on 8.0.1 and 9.0.0.

#### `somcp_clear_cache`
**State**: written
**Nature**: `acts(DELETE, 'AdminPerformance')` · **Scope**: `mcp.system.write`

**Does** - clears the shop's caches, as the **Clear cache** button at the top of **Advanced Parameters > Performance** does: Symfony, Smarty (cache and compiled templates), XML, the CCC files of the theme (CSS and JavaScript) and the class index. Nothing else changes.

**Do not use for** - reading how caching is configured (→ `somcp_get_cache_status`); changing the Performance settings, which stays in the back office; clearing the Memcache or APC cache, which the button does not do either.

**Parameters** - none.

**Returns**

| Field | On screen | Notes |
|---|---|---|
| `cleared` | - | the caches cleared, in the order of the core's chain: `symfony`, `smarty`, `xml`, `media`, `class_index` |
| `symfony_cleared_at` | - | `end_of_request`: the Symfony folder goes after the answer is sent, as in the core |
| `message` | **All caches cleared successfully** | the sentence of the screen |

**Errors** - an unknown argument (`<name>: not a field of this tool`); an account without `delete` on `AdminPerformance` (the sentence of every `acts()` refusal).

**Notes**
- The route of the button is guarded by `is_granted('delete', 'AdminPerformance')` on both versions, not `edit`: the tool asks the same right.
- The button runs `prestashop.core.cache.clearer.cache_clearer_chain`. The MCP endpoint is a front controller where no Symfony kernel is booted, so `SymfonyContainer::getInstance()` is `null` there: when a container offers the chain, its `clear()` is called; otherwise its steps are called in the same order through their legacy calls, `Tools::clearSf2Cache()`, `Tools::clearSmartyCache()`, `Tools::clearXMLCache()`, `Media::clearCache()`, `PrestashopAutoload::getInstance()->generateIndex()`. The container lookup is the one shared in `SomcpBackOffice::coreService()`.
- Every call clears and clearing twice is harmless: there is no "nothing to change", and a lost answer can be retried.
- The storefront and the back office are slower until the caches rebuild (on the test shops, 2.7 seconds for the next back-office page on 8.0.1, more than 12 seconds on 9.0.0): the description says not to clear a busy shop without the merchant asking.
- Multistore: the caches are shared by every shop of the installation, and the call clears them for all of them, not only the token's.

9.0.0 adds the Doctrine cache to the chain and clears the Symfony cache of three apps behind a lock; through the legacy path both versions remove `var/cache/<env>/`, Doctrine included. Same button, route, right and message on 8.0.1 and 9.0.0.

---

### SEO

What search engines read of the shop: titles, descriptions, URLs and their settings, robots.txt, the sitemap, and the pages as they are actually served.

**Not here**: the product, category and brand fields themselves, which their own update tools write (→ Catalogue); what answer engines and AI assistants need (→ AEO).

#### `somcp_audit_product_seo`
**State**: written
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - lists the products whose search engine fields have a problem, with the problem named, per language.

**Do not use for** - reading or changing one product (→ `somcp_get_product`, `somcp_update_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language of the shop when left out |
| `active` | boolean | no | `true` (default) active products only - hidden products rank for nothing - `false` disabled products only, the Status filter of `somcp_search_products` |
| `checks` | array of enum | no | a subset of the checks below; all by default |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - `products`, one entry per product and language: `id_product`, `name`, `language`, `issues` (each a `check` from the list below and the `value` at fault). Plus `returned`, `total_count` (entries with at least one issue), `offset`, `counts` per check over the whole catalogue, `languages` audited and `active`, the Status filter applied.

**Notes**
- Checks: `meta_title_missing`, `meta_title_too_long` (over 70 characters), `meta_title_duplicate` (another product has it in that language), `meta_description_missing`, `meta_description_short` (under 70), `meta_description_long` (over 160), `link_rewrite_duplicate`, `description_short` (the description holds under 150 words, tags removed), `image_legend_missing` (an image without its alternative text), `no_image`, `no_brand`, `no_gtin` (no ean13, upc nor isbn), `inactive_without_redirect` only when `active` is false.
- The `value` is the title, description or friendly URL at fault, the number of words for `description_short`, the ids of the images without legend for `image_legend_missing`, the `redirect_type` for `inactive_without_redirect` (on PrestaShop 9, `{"redirect_type": "default", "applies": "404"}` for a product left on the shop default), and an empty string for the checks that report an absence.
- The product-wide checks (`no_image`, `no_brand`, `no_gtin`, `inactive_without_redirect`) repeat on each language entry, and `counts` counts entries, not products.
- Duplicates are found by the database, grouping the values of the products audited (same shop, same `active` filter) in one language, so its collation decides what is the same title or URL, as it does for the shop's own URLs. Spaces around a value are ignored.
- `inactive_without_redirect`: a 301 or 302 sends to another page and `200-displayed` (PrestaShop 9) keeps the page shown; anything else - empty, 404, 410, `404-displayed`, `410-displayed` - is reported. On PrestaShop 9 a product on `default` is judged by **Shop Parameters > Product Settings > Default behavior for disabled products** (`PS_PRODUCT_REDIRECTION_DEFAULT`, read for the token's shop): not reported when that default is **Display product as discontinued with 200 response**, reported with both values otherwise. PrestaShop 8 has neither `default` nor that setting.
- An image counts in the shop when `image_shop` has it; a language without an `image_lang` row is a missing legend. Errors, every one in the same answer under "Nothing was read. Correct these and call again:": a field the tool does not take, a `checks` that is not a list of the names above (`checks: one of ...`), an `active` that is not true or false, a `limit` that is not a whole number from 1 to 100, an `offset` below 0 - never replaced by a default, which applies only to an argument left out. These arguments are read by `SomcpToolAuditProductSeo::auditArguments()`, shared with `somcp_audit_category_seo`. An unknown `language` is one more line of the same answer: `language: not one of the shop's languages, which are <codes> - leave language out for all of them`.
- Read in SQL from `product`, `product_shop`, `product_lang` and `image_lang` of this shop, in batches of products, so a catalogue of 50,000 products stays fast; only the page asked for is kept, while `counts` and `total_count` cover the whole catalogue. A product still being created (`state` 0) is left out. No write, ever: corrections go through `somcp_update_product`.

#### `somcp_audit_category_seo`
**State**: written
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.seo.read`

**Does** - lists the categories whose search engine fields have a problem, per language.

**Do not use for** - changing a category (→ `somcp_update_category`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |
| `active` | boolean | no | `true` (default) displayed categories only, `false` hidden ones only |
| `checks` | array | no | only these checks; all of them when left out |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - `categories`, one entry per category and language: `id_category`, `name`, `language`, `active`, `issues` (each a `check` from the list below and the `value` at fault). Plus `returned`, `total_count` (entries with at least one issue), `offset`, `counts` per check over every category audited, `languages` audited, and `active`, the Status filter applied.

**Notes**
- Checks: `meta_title_missing`, `meta_title_too_long` (over 70 characters), `meta_title_duplicate` (another category has it in that language), `meta_description_missing`, `meta_description_short` (under 70 characters), `meta_description_long` (over 160), `description_missing` (a category page with no text to rank on: no word once tags are removed), `link_rewrite_duplicate`, `empty_category` (displayed, no active product), `no_cover_image`.
- The root and Home categories are left out: they have no page of their own. The root is the category without a parent, a Home category the one flagged `is_root_category`.
- The `value` is the title, description or friendly URL at fault, and an empty string for the checks that report an absence. `empty_category` and `no_cover_image` concern the whole category and repeat on each language entry; `counts` counts entries, not categories.
- The lengths and the word count are the ones of `somcp_audit_product_seo`, read from the same code: a meta title over 70 characters, a meta description under 70 or over 160, the lengths every write tool advises past (`SomcpTool::META_TITLE_RECOMMENDED`, `SomcpTool::metaDescriptionRecommended()`). Duplicates are found by the database among the categories audited in one language, as for products. Displayed categories only by default, like the product audit's active products; `active: false` audits the hidden ones only, which keep their fields, but only a displayed one can be `empty_category`. `checks` narrows the audit and the `counts` to the names given, the same list and refusal as the product audit.
- `empty_category`: no product of the category, directly associated with it, is active in this shop - what the category page lists. A product still being created (`state` 0) does not count.
- `no_cover_image`: the shop has no `img/c/<id_category>.jpg`, the file the back office form shows as the cover, on PrestaShop 8 and 9.
- Errors: the ones of `somcp_audit_product_seo`, from the same `SomcpToolAuditProductSeo::auditArguments()`: a field the tool does not take, a bad `checks`, `active`, `limit` or `offset`, every one in the same answer under "Nothing was read. Correct these and call again:", never replaced by a default; an unknown `language` is one more line of the same answer, ending `leave language out for all of them`.
- Read in SQL from `category`, `category_shop`, `category_lang` and `category_product` of this shop, in batches; only the page asked for is kept, while `counts` and `total_count` cover every category. No write, ever.

#### `somcp_audit_cms_seo`
**State**: written
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.seo.read`

**Does** - lists the CMS pages whose search engine fields have a problem, per language.

**Do not use for** - changing a page's SEO fields (→ `somcp_update_cms_seo`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |

**Returns** - `pages`, one entry per page and language: `id_cms`, `title` (the page title, column `meta_title`), `meta_title` (the Meta title, column `head_seo_title`), `seo_title` (the one search engines read), `language`, `active`, `issues` (each a `check` from the list below and the `value` at fault). Plus `total_count`, `counts` per check and `languages` audited.

**Notes**
- Checks: `meta_title_too_long`, `meta_title_duplicate`, `meta_description_missing`, `meta_description_short` (under 70 characters), `meta_description_long` (over 160), `content_short` (under 150 words), `link_rewrite_duplicate`, `not_indexable` (the page's `indexation` flag is off).
- `title`, `meta_title` and `seo_title` carry the same names and meaning as in `somcp_get_cms_page` and `somcp_update_cms_seo`: the form's **Title** is the column `meta_title` and its **Meta title** the column `head_seo_title`, so an assistant that reads a field here writes it back under the right name. `seo_title` is the one search engines read, as `Meta::getCmsMetas()` builds it on PrestaShop 8 and 9: the Meta title when filled, the Title otherwise - and the title the checks look at. The Title is required by the form, so there is no `meta_title_missing`.
- The lengths and the word count are the ones of `somcp_audit_product_seo`, read from the same code: over 70 characters for the title, under 70 or over 160 for the description, under 150 words of content once tags are removed. The `value` is the title, description or friendly URL at fault, the number of words for `content_short`, and an empty string for the checks that report an absence.
- Duplicates are found by the database among the pages of this shop in one language, so its collation decides what is the same title or URL.
- Every page associated with the shop is audited, shown or not, with `active` saying which. A shop has a handful of pages, so they are returned whole, without paging, read in SQL from `cms`, `cms_shop` and `cms_lang`. No write, ever.

#### `somcp_audit_brand_seo`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.seo.read`

**Does** - lists the brands whose search engine fields or descriptions have a problem, per language.

**Do not use for** - changing a brand (→ `somcp_update_manufacturer`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |

**Returns** - `brands`, one entry per brand and language: `id_manufacturer`, `name`, `language`, `active`, `products_count`, `issues` (each a `check` from the list below and the `value` at fault). Plus `total_count`, `counts` per check and `languages` audited.

**Notes**
- Checks: `meta_title_missing`, `meta_title_too_long` (over 70 characters), `meta_description_missing`, `meta_description_short` (under 70 characters), `meta_description_long` (over 160), `description_missing`, `no_logo`, `inactive_with_products`. The limits are those of `somcp_audit_product_seo`, `somcp_audit_category_seo` and `somcp_audit_cms_seo` (`SomcpTool::META_TITLE_RECOMMENDED`, `SomcpToolAuditProductSeo::DESCRIPTION_MIN`, `SomcpTool::metaDescriptionRecommended()`); the `value` of the three length checks is the text at fault.
- `description_missing`: the brand's description, the text of its page, holds no word once tags are removed. The short description is not checked.
- `no_logo`: the shop has no `img/m/<id_manufacturer>.jpg`, the file `somcp_get_manufacturer` reads as the logo.
- `inactive_with_products`: a disabled brand has no page, while products still carry it. Its `value` is `products_count`.
- `products_count` is the number of products of this shop attached to the brand, counted as `somcp_get_manufacturer` counts it. The `value` of the missing checks and `no_logo` is an empty string: they report an absence. `no_logo` and `inactive_with_products` concern the whole brand and repeat on each language entry.
- A brand's texts are the same in every shop (`manufacturer_lang` has no `id_shop`); a language the brand has no row in counts as empty. Every brand associated with the shop is audited, enabled or not, and returned whole, without paging, read in SQL from `manufacturer`, `manufacturer_shop` and `manufacturer_lang`. No write, ever.

#### `somcp_update_cms_seo`
**State**: written
**Nature**: `acts(EDIT, 'AdminCmsContent')` · **Scope**: `mcp.seo.write`

**Does** - changes the meta title, meta description, friendly URL and indexing flag of one CMS page.

**Do not use for** - the page's content (a Content tool), or a product, category or brand (→ their update tools).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms` | integer | yes | from `somcp_list_cms_pages`, any page of the shop; `somcp_audit_cms_seo` finds the pages to fix |
| `languages` | array | no | ISO codes being filled, among the shop's active languages, all of them by default; each text below is then given in each of them |
| `meta_title` | object | no | per ISO code; the form's **Meta title** (`head_seo_title`); 255 characters at most (the column); past 70, a search result cuts it, said in `seo_warnings`; an empty text removes it |
| `meta_description` | object | no | per ISO code; 512 characters at most, a warning over 160 |
| `link_rewrite` | object | no | per ISO code; `Tools::str2url()` then `Validate::isLinkRewrite()`, 128 characters at most, never empty |
| `meta_keywords` | object | no | per ISO code, comma separated; the form's **Meta keywords**, PrestaShop 8 only: a value is refused on 9, which has none, and `null` is skipped |
| `indexation` | boolean | no | whether search engines may index the page (**Indexation by search engines**) |
| `language` | string | no | as `somcp_get_cms_page` returns it: the language its plain texts are in, read as `languages: ["<iso>"]` when `languages` is left out; next to a `languages` that does not hold it, refused as `language: not one of languages`; one the shop does not have is refused once, with the shop's languages, as every tool words it (`language: not one of the shop's languages, which are en, fr - leave language out for the shop default`) |
| `title`, `content`, `id_cms_category`, `active`, `position`, `seo_title`, `url` | as read | no | what `somcp_get_cms_page` returns and this tool does not write, accepted unchanged so a page read there can be given back whole; a different value is refused with where it is changed (`not changed by this tool - the merchant changes it in Design > Pages > Edit`, or `worked out by the shop from the Meta title and the Title` / `from the Friendly URL`) |

**Returns** - `changed`, `id_cms`; when something changed, `changes` (each a `field`, named `<field> (<iso>)` for a text, its `old` and `new` value, as the other update tools give them), `seo_warnings` (per ISO code) and `page` (`id_cms`, `active`, `indexation`, and per language changed its `title`, `meta_title`, `seo_title` - the title search engines read -, `meta_description`, `meta_keywords` (`null` on PrestaShop 9) and `link_rewrite`, read back from the shop). A call that changes nothing answers `changed: false` with a `reason`.

**Notes**
- The names follow the CMS page form of Design > Pages, not the columns: the form's **Title** is the column `meta_title`, the page's h1 and its default title tag, and its **Meta title** is `head_seo_title`, which overrides the title tag when filled (`EditCmsPageHandler` on PrestaShop 8 and 9). This tool writes the second under the name `meta_title`; the page title stays with the content. `somcp_get_cms_page` and `somcp_audit_cms_seo` return `title`, `meta_title` and `seo_title` with the same meaning as this tool.
- Written through the `CMS` object, after `validateFields()` and `validateFieldsLang()` as the back office handler does, so hooks fire, with `id_shop_list` set to the shop of the token: `cms_lang` of the other shops keeps its texts. `indexation` lives in `cms`, so it is the same in every shop. A page not associated with the shop (`cms_shop`) is answered like one that does not exist. The texts are taken as the catalogue write tools take them (`languages`, then each text keyed by ISO code, with the same errors); a text equal to what the page holds is left out. What `somcp_get_cms_page` returns can be given back: the fields this tool does not write are compared with that very reading of the page, in the `language` it was read in, and any field neither tool has is refused.
- The page to change is read before with `somcp_get_cms_page`, and an answer lost after the call is checked there too: the audit only returns the pages that fail one of its checks.
- Every value is checked and compared before anything is written, and every problem reported at once. A call that changes nothing writes nothing and answers `changed: false`.
- Errors, every one in the same answer under "Nothing was changed. Correct these and call again:": an `id_cms` that is not a whole number above 0, an unknown page (`id_cms: no CMS page of this shop has the identifier <n> - find it with somcp_list_cms_pages`), reported with any field the tool does not take; then the problems of each value.
- `seo_warnings`, for each language changed: a title search engines read over 70 characters (under `meta_title`), a `meta_description` over 160 (the lengths every SEO tool advises past and the audits flag), each with its length in the sentence of `SomcpTool::metaLengthAdvice()` every SEO write gives (`meta_title is 85 characters: past 70, a search result cuts it`), and a new `link_rewrite` on a displayed page, whose old URL breaks; the assistant tells the merchant before the call.

#### `somcp_list_meta_pages`
**State**: written
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - lists the shop's own pages (home, contact, new products, best sales, search...) with their Page title, Meta description and Rewritten URL, under the names every SEO tool gives them.

**Do not use for** - products, categories, brands or CMS pages, which have their own fields.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, refused otherwise; the shop default when left out; given back as `language` in the answer |

**Returns** - `pages`, per page: `id_meta`, `page` (the controller name), `meta_title` (the **Page title**), `meta_description` (the **Meta description**), `meta_keywords` (the **Meta keywords**, PrestaShop 8 only, `null` on 9), `link_rewrite` (the **Rewritten URL**), `url`, `configurable`. Plus `count` and the `language` read.

**Notes**
- Read from `meta` and `meta_lang` of the token's shop, as the Traffic & SEO page lists them, in one query, without paging: a shop has a few dozen pages.
- The back office lists only the pages whose `configurable` is true. The others - the product, category and CMS templates and some payment module pages - are returned too, with `configurable: false`, empty texts and `url: null`: their texts come from each product, category or page.
- `url` is the public address the storefront builds: `Link::getModuleLink()` for a module page (`module-<module>-<controller>`), `Link::getPageLink()` otherwise. The home page has no `link_rewrite`.
- `meta_lang` holds `keywords` on PrestaShop 8 only, a field PrestaShop 9 no longer has: `meta_keywords` is `null` there, as `somcp_get_manufacturer` returns it.

#### `somcp_update_meta_page`
**State**: written · [issue #88](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/88)
**Nature**: `acts(EDIT, 'AdminMeta')` · **Scope**: `mcp.seo.write`

**Does** - changes the Page title, Meta description or Rewritten URL of one of the shop's own pages.

**Do not use for** - products, categories, brands or CMS pages (→ their update tools, `somcp_update_cms_seo` for a CMS page).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_meta` | integer | yes | from `somcp_list_meta_pages` |
| `languages` | array | no | ISO codes being filled, among the shop's active languages, all of them by default; each text below is then given in each of them |
| `meta_title` | object | no | per ISO code; the form's **Page title**; 128 characters at most (the column); past 70, a search result cuts it, said in `seo_warnings`; an empty text removes it |
| `meta_description` | object | no | per ISO code; the form's **Meta description**; 255 characters at most (the column), a warning over 160 |
| `meta_keywords` | object | no | per ISO code; the form's **Meta keywords**, PrestaShop 8 only (on 9 a text is refused, `meta_keywords: not on PrestaShop <version>, which has no Meta keywords field; leave the field out`, and the `null` of the read is ignored); 255 characters at most, an empty text removes them |
| `link_rewrite` | object | no | per ISO code; the form's **Rewritten URL**; checked as typed with `Validate::isLinkRewrite()` (which follows `PS_ALLOW_ACCENTED_CHARS_URL`), never rewritten; 254 characters at most (`varchar(254)` on PrestaShop 8); required in the shop default language, may be empty in the others; refused if another page of the shop already uses it in that language |

**Returns** - `changed`, `id_meta`; when something changed, `changes` (each a `field` named with its language, `meta_title (fr)`, then the `old` and `new` value, as every update tool records them), `seo_warnings` (per ISO code) and `page` (`id_meta`, `page`, and per language changed its `meta_title`, `meta_description`, `meta_keywords` (`null` on PrestaShop 9) and `link_rewrite`, read back from the shop). A call that changes nothing answers `changed: false` with a `reason`.

**Notes**
- The text fields have the names every SEO tool uses and `somcp_list_meta_pages` returns, but the list gives them flat in one language, with `page`, `url` and `configurable`, which this tool does not take: to write, give each field as one object keyed by ISO code.
- Written through the `Meta` object, after `validateFields()` and `validateFieldsLang()`, with `id_shop_list` set to the shop of the token: `meta_lang` is per shop, and the other shops keep their texts. The texts are taken as the catalogue write tools take them (`languages`, then each text keyed by ISO code, with the same errors); a text equal to what the page holds is left out, and any other field is refused.
- The pages whose `configurable` is false (the product, category and CMS templates, some module pages) are refused: their texts come from each product, category or page, and the back office does not list them.
- Errors, every one in the same answer under "Nothing was changed. Correct these and call again:": an `id_meta` that is not a whole number above 0, an unknown page (`id_meta: no page of this shop has the identifier <n> - find it with somcp_list_meta_pages`), a page that is not `configurable` (`id_meta: the page <page> takes its texts from each product, category or CMS page ...`), each reported with any field the tool does not take; a former name is refused with the one to send (`title: renamed meta_title`, `description: renamed meta_description`, `keywords: renamed meta_keywords`, `url_rewrite: renamed link_rewrite`); then the problems of each value.
- The home page (`index`) has no `link_rewrite`: a new one is refused, the value it holds given back unchanged is not. `EditMetaHandler` only checks the shape of a friendly URL; the tool also refuses one already used by another page of the shop in the same language, since both addresses would lead to one page.
- Every value is checked and compared before anything is written, and every problem reported at once. A call that changes nothing writes nothing and answers `changed: false`.
- `seo_warnings`, for each language changed: a `meta_title` over 70 characters, a `meta_description` over 160 (the lengths every SEO tool advises past and the audits flag), each with its length in the sentence of `SomcpTool::metaLengthAdvice()` every SEO write gives (`meta_title is 85 characters: past 70, a search result cuts it`), and a new `link_rewrite`, whose old address breaks; the assistant tells the merchant before the call.

#### `somcp_get_seo_settings`
**State**: written
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's SEO and URL settings, and what each one means for search engines.

**Do not use for** - changing them, which stays in the back office: these settings break every URL of the shop when wrong.

**Parameters**

None.

**Returns** - `friendly_urls`, `accented_urls`, `default_language_url_prefix` (**Use prefix for default language**, `null` on PrestaShop 8, which has no such option), `canonical_redirect` (none, 302 or 301), `disable_apache_multiviews` and `disable_apache_mod_security` (the two Apache options of the Set up URLs card), `https` (whether the shop answers in HTTPS) and `https_everywhere` (the PrestaShop 8 setting serving every page in HTTPS, `null` on PrestaShop 9, which dropped it), `domains` (main first, each with `domain`, `domain_ssl`, `uri`, `main`, `active`), `routes` (`product`, `category`, `brand`, `supplier`, `cms`, `cms_category`, `module`, each with its `pattern` and `customized`), `product_attributes_in_title` (**Display attributes in the product meta title**), `robots_txt_present`, `sitemap_module` (`installed`, `active`), `languages` (`iso_code`, `name`, `active`, `default`, `url_prefix` - whether this language's addresses carry its ISO code), `warnings` (each a `code` and `why`).

**Notes**
- Settings read by their configuration keys in each version, for the shop of the token. A switch never saved reads `false`, as the back office shows it; only a setting the version does not have is `null` (`default_language_url_prefix` on PrestaShop 8, `https_everywhere` on PrestaShop 9), decided by the version, never guessed from a missing row.
- A route's default pattern is read from `Dispatcher::$default_routes` of the running version (PrestaShop 8 puts the category and the EAN-13 in the product address), and `PS_ROUTE_<route>` replaces it when not empty, as `Dispatcher::loadRoutes()` does.
- `sitemap_module.active` is read from `module_shop` for the shop of the token, since `Module::isEnabled()` answers for the shop of the global context.
- A language's `url_prefix` follows what the storefront does: the ISO code is in the addresses only when the shop serves more than one active language, and then every language carries it, except the default language on PrestaShop 9 when **Use prefix for default language** is off.
- `warnings` names what costs rankings: friendly URLs off (`friendly_urls_off`), no canonical redirection (`no_canonical_redirect`), a shop not served in HTTPS (`not_https`, or `https_not_everywhere` on PrestaShop 8), no robots.txt (`no_robots_txt`), no sitemap module (`no_sitemap_module`).

#### `somcp_get_robots_txt`
**State**: written
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's robots.txt and what it allows and blocks, rule by rule.

**Do not use for** - AI crawlers specifically (→ `somcp_check_ai_crawler_access`), or regenerating the file (→ `somcp_generate_robots_txt`).

**Parameters**

None.

**Returns** - `present`, `content` (the file as it is), `groups` (one per group of the file, in its order: `user_agents`, `allow`, `disallow`, each rule as written), `sitemaps` (declared sitemap URLs), `size` (bytes), `last_modified`, `warnings` (each a `code` and `why`, with `user_agents`, `rule`, `url` and `language` when they apply). Without a file, `present` is false, `content`, `size` and `last_modified` are `null` and `groups` and `sitemaps` are empty.

**Notes**
- Read from `robots.txt` at the root of the PrestaShop installation, the file `Tools::generateRobotsFile()` writes. A multistore installation has one for every shop, holding a block of rules per shop folder. At most the first 500 KiB are read, what Google reads.
- Groups follow RFC 9309: user-agent lines in a row share one group, a user-agent line after a rule starts a new one, `Sitemap` lines belong to the file, a rule before any user agent belongs to no group and is left out. Comments and other fields (`Crawl-delay`, `Host`) are not returned in `groups`; they stay in `content`.
- `warnings`: `disallow_all` (a group whose rules block `/`: `Disallow: /`, `/*` or `*`), `blocks_products` and `blocks_categories` (a rule of a group blocking the address of a real page), `no_sitemap`, `not_at_domain_root` (the shop's `physical_uri` is not `/`: the file is served in that folder, where search engines never look), `too_large` (over 500 KiB), `no_robots_txt`.
- The pages checked are the first active, visible product of the shop and its first active category below Home (the categories `somcp_audit_category_seo` audits), their address built with `Link` in each active language of the shop. Each group decides as search engines do: the longest matching pattern wins, `Allow` wins a tie, `*` matches anything and a final `$` ends the address; an empty `Disallow` blocks nothing. A group of `disallow_all` is not checked page by page.

#### `somcp_generate_robots_txt`
**State**: written
**Nature**: `acts(EDIT, 'AdminMeta')`, plus `add` and `delete` on `AdminMeta` · **Scope**: `mcp.seo.write`

**Does** - writes the shop's robots.txt again, as the Generate button of the Traffic & SEO page does.

**Do not use for** - reading it (→ `somcp_get_robots_txt`).

**Parameters**

None.

**Returns** - `changed` (false when the new file is byte for byte the old one), `previous_content` (`null` when there was no file), `content`.

**Notes**
- Through the core's own `Tools::generateRobotsFile(true)`, the call the back office button makes through `RobotsTextFileGenerator` on PrestaShop 8 and 9, so modules that add their lines through `actionAdminMetaBeforeWriteRobotsFile` and `actionAdminMetaAfterWriteRobotsFile` still do.
- The button asks for the `add`, `edit` and `delete` rights on `AdminMeta` together (`MetaController::generateRobotsFileAction`), since the file is replaced whole: the tool asks for the same three.
- Any line added by hand to the file is lost: the previous content is returned and the assistant tells the merchant before the call.
- One file for the whole installation, at the root of PrestaShop. On a multistore installation it holds the rules of every shop's folder, but its `Sitemap` line is the one of the shop the call is made for, as when the button is pressed from that shop.
- The new content can only be known by writing it, so a call that changes nothing still rewrites the same bytes, and answers `changed: false`. A file the web server cannot write is refused with nothing changed.
- Refused in demo mode (`_PS_MODE_DEMO_`), with nothing read or written, as the button is (`DemoRestricted` on `generateRobotsFileAction`).

#### `somcp_audit_page`
**State**: written
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - fetches one public page of the shop and returns what a search engine reads on it.

**Do not use for** - many pages at once (→ `somcp_check_links`), or the fields stored in the database (→ the audit tools of this section).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `url` | string | yes | an address of this shop, or a path such as `/en/3-clothes` |

**Returns** - `status`, `final_url`, `redirects` (each hop with its `url`, `status` and `location`), `time_ms`, `title`, `meta_description`, `meta_robots`, `x_robots_tag` (the header, `null` when not sent), `canonical`, `hreflang` (`language` and `url` of each alternate), `headings` (h1 to h3 in order, each a `level` and `text`, 100 at most), `images` (`count`, `without_alt`), `links` (`internal` and `external` counts), `json_ld` (each object's `@type` and its plain fields, nested objects one level down, lists by their `count`; a block that is not valid JSON gives `error: invalid_json`), `open_graph` (each `og:` property and its content), `word_count`, `warnings` (each a `code` and `why`).

**Notes**
- Only addresses of this shop are fetched: the host must be one of the shop's own active domains (`ShopUrl` of this shop, `domain` or `domain_ssl`, with its port), anything else is refused before any request, redirects included, so the tool can never be used to reach another site or the internal network. GET only, a 10 second timeout for the whole fetch, 2 MB at most per page, a user agent naming the module. The page is fetched as a visitor sees it, without the employee's session: no cookie, no credential. The resolved address is not checked against private ranges, since a shop's own name may resolve to one behind a load balancer: the list of domains is the boundary.
- A path is taken from the root of the shop's main domain, in https when the shop has SSL enabled. Accents and spaces are percent-encoded as a browser does; the fragment is dropped. An address with credentials, a backslash or a scheme other than http and https is refused.
- Redirects are followed one hop at a time, 5 at most, each `Location` checked against the domains before it is fetched. A redirect leaving the shop stops there, with `redirect_outside_shop` and the `location` that was not fetched.
- `warnings`: `no_h1`, `several_h1`, `canonical_elsewhere` (with the `canonical`), `noindex` (from the robots meta tag or the `X-Robots-Tag` header, `noindex` or `none` as a whole directive), `missing_hreflang` (with the `language`, only when the shop has several active languages), `no_title`, `title_too_long` (over 70 characters), `no_meta_description`, `description_too_long` (over 160), `redirect_chain` (more than one redirect), `redirect_outside_shop`, `too_many_redirects`, `slow_page` (over 2 seconds), `not_ok` (a status other than 200), `not_html`, `truncated` (over 2 MB). A page answering other than 200 is read but not judged on its content.
- Errors, every one in the same answer under "Nothing was fetched. Correct these and call again:": a field the tool does not take, a `url` that is not a full http or https address or a path from the root, a `url` that is not an address of this shop (listing its domains) - the same lines as `somcp_check_links`, from `SomcpToolAuditPage::readUrl()`. A shop with no active domain is refused before anything, in its own sentence.

#### `somcp_check_links`
**State**: written · [issue #93](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/93)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - follows the internal links of the shop's public pages from a starting page, as a visitor without an account, and reports broken links, redirect chains and slow pages. The many-pages companion of `somcp_audit_page`.

**Do not use for** - one page in detail (→ `somcp_audit_page`); the sitemap (→ `somcp_audit_sitemap`); the SEO texts stored in the database (→ `somcp_audit_product_seo`, `somcp_audit_category_seo`, `somcp_audit_cms_seo`, `somcp_audit_brand_seo`, `somcp_list_meta_pages`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `url` | string | no | the starting page, in the form `somcp_audit_page` takes: an address of this shop, or a path such as `/en/3-clothes`; `/`, the home page, when left out |
| `max_pages` | integer | no | pages fetched at most, 50 by default, 1 to 200 |

**Returns** - `pages_checked` (internal pages fetched), `broken` (each `url`, `status` - 0 when nothing answered, with the `error` - and `linked_from`, up to 10 page addresses; every internal link whose final answer is not 2xx), `redirect_chains` (each `url`, `redirects` - every hop with its `url`, `status` and `location`, as `somcp_audit_page` - and `final_url`; every internal link answered by more than one redirect, or ending outside the shop's domains with `outside_shop: true`, not followed), `slow_pages` (each `url` and `time_ms`, over 2 seconds), `external_links` (count of distinct external addresses seen, never fetched), `stopped_early` (`null`, `max_pages` or `time_limit`). `broken` and `redirect_chains` keep their order of discovery.

**Notes**
- Fetches through the same code as `somcp_audit_page` (`SomcpToolAuditPage::follow()`, `hosts()`, `readUrl()`, `absolute()`, `resolve()`, `links()`): only addresses of this shop's active domains are requested, redirects included, each `Location` checked before it is fetched, 5 redirects at most, 2 MB at most per page. GET only, as a visitor without the employee's session, a user agent naming the module.
- Pages are visited breadth first from `url`, each address once (fragment removed, query kept); only `text/html` answers are read for further links. External links are counted, never requested.
- 60 seconds for the whole call: each request gets at most 10 seconds, cut to what is left.
- Errors, every one in the same answer: `url` that is not an address of this shop (listing its domains), `max_pages` out of range, a field the tool does not take; a shop with no active domain is refused before anything.
- Read only.

#### `somcp_audit_sitemap`
**State**: written · [issue #94](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/94)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - reads the XML sitemaps the **Google sitemap** module (`gsitemap`) generated for the shop, counts their addresses, and checks them against the active catalogue, robots.txt and a sample of listed addresses fetched as a visitor.

**Do not use for** - robots.txt itself (→ `somcp_get_robots_txt`); following the links of the pages (→ `somcp_check_links`); one page in detail (→ `somcp_audit_page`); generating the sitemap (not offered yet).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `sample` | integer | no | how many listed addresses to fetch and check, 20 by default, 0 to 100 |
| `language` | string | no | ISO code: only that language's sitemap files; every language when left out |

**Returns**

| Field | On screen | Notes |
|---|---|---|
| `module_active` | - | `gsitemap` installed and enabled on the token's shop |
| `sitemaps` | **Your sitemaps** | each `file`, `url` (on the shop's main domain), `language` (ISO code from the file name), `url_count` (`null` when the file is missing, over 50 MB or not valid XML), `last_modified` (file time), `exists` (`false` when the module lists a file missing on disk) |
| `index` | - | `file`, `url`, `exists` of `<id_shop>_index_sitemap.xml`, and `announced` (whether a `Sitemap:` line of robots.txt points to it) |
| `last_generated` | - | `GSITEMAP_LAST_EXPORT`, `null` before the first generation |
| `frequency` | **How often do you update your store?** | the value as on screen (`weekly`, ...) |
| `excluded_pages` | **Indicate the pages that you do not want to include in your sitemap files:** | the `page` names unticked |
| `check_image_file` | **Check this box if you wish to check the presence of the image files on the server** | `GSITEMAP_CHECK_IMAGE_FILE` of the token's shop while the installed `gsitemap` is below 4.3.1; `null` from 4.3.1 on, whose screen no longer has the box, and when the module is not installed |
| `robots_txt_sitemaps` | - | the `Sitemap:` lines of robots.txt, as `somcp_get_robots_txt` returns them |
| `missing_products` | - | active products of the token's shop visible in the catalogue and absent from every sitemap: `count`, and the first 20 as `products`, each `id_product` and `name` (default language) |
| `inactive_listed` | - | disabled products still listed, the first 20: `url`, `id_product` |
| `sample_errors` | - | sampled addresses answering other than 200: `url`, `status` (0 when nothing answered, or for an address outside the shop's domains, never requested), `final_url` |
| `warnings` | - | each a `code` and `why`: `no_sitemap`, `module_inactive`, `index_not_in_robots_txt`, `stale` (generated longer ago than the frequency), `file_missing` (with its `file`) |

**Notes**
- The files are read from disk at the root of the installation, where the module writes them, never over HTTP, with `libxml` network access off and the protocol's 50 MB cap per file. Only the names the module recorded for the token's shop in `gsitemap_sitemap` are read, and only plain file names.
- The robots.txt lines come from the same reading as `somcp_get_robots_txt` (`SomcpToolGetRobotsTxt::sitemaps()`).
- Product addresses are built with `Link::getProductLink()` in each language read and compared by path and query, so friendly URLs and their language prefix match as the module writes them. A product listed in any language counts as listed.
- Only the sample is fetched, spread over the whole list, through the code of `somcp_audit_page` (`SomcpToolAuditPage::follow()`, `hosts()`): only the shop's own domains are requested, redirects included, 10 seconds per address and 60 seconds for the sample.
- Read only. A shop without `gsitemap` answers with `module_active: false`, empty lists and `no_sitemap`, never a failure. The cron address and its token are never returned.
- Errors, every one in the same answer: `sample` out of range, a `language` the shop does not have, a field the tool does not take.

**Differences between 8.0.1 and 9.0.0**
- `gsitemap` 4.2.0 / 4.4.0: the image-file check exists on 8.0.1 only, answered as `check_image_file` before gsitemap 4.3.1 and `null` after (the module version decides, not PrestaShop's); the brand priority on 9.0.0 only; the page list of 9.0.0 holds public pages only, and **best-sales** is labelled **Best sellers**. Same table, same file names, same index location.

### AEO

What answer engines and AI assistants need to recommend a product: complete and consistent product data, structured data that matches the shop, open access for their crawlers, and the pages that build trust.

**Not here**: classic search engine fields and settings (→ SEO).

#### `somcp_audit_product_schema`
**State**: written · [issue #95](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/95)
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - fetches the public page of one product, as a visitor, and compares the structured data (JSON-LD `Product`) it prints with what the product page of the back office holds.

**Do not use for** - the database fields alone (→ `somcp_audit_product_entities`); the meta title and description (→ `somcp_audit_product_seo`); any other page (→ `somcp_audit_page`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes | from `somcp_search_products`, never guessed; an unknown product is refused with the error of `somcp_get_product` |
| `id_product_attribute` | integer | no | from `somcp_get_product` `combinations[].id_product_attribute`: the page of that combination; refused when it is not a combination of the product |
| `language` | string | no | ISO code of one of the shop's active languages, the page's language; refused when the shop does not have it, naming its languages; the shop default language when left out |

**Returns** - `url` (the page fetched, after its redirects), `language` (the ISO code used), `found` (the `@type`s of every JSON-LD object of the page, `invalid_json` for a block that does not parse, as `somcp_audit_page`), `product` (as printed: `name`, `brand`, `sku`, `gtin` - whichever of `gtin13`, `gtin12`, `isbn`, `gtin` is printed first -, `mpn`, `image`, `description_length`), `offer` (as printed: `price`, `currency`, `availability`, `url`), `rating` (`rating_value`, `rating_count`, `review_count` as printed, `null` when absent), `mismatches` (each `field` - the name of `somcp_get_product`: `name`, `manufacturer_name`, `reference`, `mpn`, `ean13`, `upc`, `isbn`, `condition`, `price`, `currency`, `quantity` -, `on_screen`, the back office label of the shop's version, `printed` and `in_shop`), `missing` (each `field`, `on_screen` and the schema.org `property` absent from the page, only when the shop has a value to print). When the page prints no `Product`, `product`, `offer` and `rating` are `null` and `note` says so.

**Notes**
- Fetches through the code of `somcp_audit_page` (`SomcpToolAuditPage::hosts()`, `follow()`, `load()`, `jsonLd()`): only the shop's own active domains are requested, redirects included, GET only, 10 seconds, 2 MB, a user agent naming the module, no employee session. The address is `Link::getProductLink()` for the token's shop, the language and the combination.
- The shop's values are those `somcp_get_product` returns for the same product, combination and language, never a second reading of the database. A combination's own codes are used when set, the product's otherwise, as the storefront does. The name only has to appear in the printed name, which a combination's page may complete with its attributes.
- `price` is compared with the price with tax the storefront computes for the shop's default country, currency and group, specific prices included (`Product::getPriceStatic()`), to the cent. `quantity` is compared with the availability the core's product presenter prints: `Discontinued` for an inactive product, `InStock` with stock or with stock management off, `BackOrder` (or `PreOrder`, which themes also print) when **When out of stock** allows orders, `OutOfStock` otherwise.
- Labels: **Reference**, **MPN**, **UPC barcode**, **ISBN**, **Brand**; the `ean13` field is **EAN-13 or JAN barcode** on PrestaShop 8 and **GTIN (EAN, JA, ITF or UCC code)** on PrestaShop 9; `condition` is **Condition** on PrestaShop 8 and **Display condition on product page** on PrestaShop 9, where the box has no label of its own. One table of labels for this tool and `somcp_audit_product_entities` (`SomcpToolAuditProductSchema::fieldLabel()`), so one box never gets two names.
- Said plainly when the theme prints no `Product` JSON-LD at all: a theme matter, not something a tool can write. Classic prints its own; the comparison reads what is printed, never assumes a theme.
- Errors: the mistakes of the call, every one in the same answer under "Nothing was fetched. Correct these and call again:" - a field the tool does not take, an `id_product` or `id_product_attribute` that is not a whole number above 0, an unknown product (`id_product: no product of this shop has the identifier <n> - find it with somcp_search_products`) or a combination of another product (`id_product_attribute: no combination of this product has the identifier <n> - find it with somcp_get_product`), as `somcp_get_product` words them; an unknown language (`language: not one of the shop's languages, which are <codes> - leave language out for the shop default`); then, in their own sentence, a page that does not answer or answers other than 200 (with the HTTP status), a redirect or an address outside the shop's domains, a shop with no active domain.
- Read only.

#### `somcp_audit_product_entities`
**State**: written · [issue #96](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/96)
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - scores how complete each product of this shop is for an assistant answering a buyer, the least complete first, and names for each product what is missing, under the labels of the product page.

**Do not use for** - the page as printed (→ `somcp_audit_product_schema`); the meta title and description in detail (→ `somcp_audit_product_seo`); to read or fill one product (→ `somcp_get_product`, `somcp_update_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | `true` by default for active products only, `false` for disabled products only; the Status filter of `somcp_search_products` |
| `max_score` | integer | no | only products scoring at or under this, 0 to 100; all of them when left out |
| `language` | string | no | ISO code of one of the shop's active languages; the shop default language when left out; same refusal as `somcp_audit_product_seo` |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - `products` worst first, each `id_product` (as `somcp_search_products`), `name`, `score` (0 to 100), `missing` (each `field`, the name of `somcp_get_product`, and `on_screen`, the back office label of the shop's version); plus `returned`, `total_count`, `offset` (the names of `somcp_search_products`), `average_score` (over the whole catalogue audited, not the page) and `language` (the ISO code used).

**Notes**
- Eleven criteria, each worth the same, the score `100 x met / 11` rounded: brand (`id_manufacturer`), a GTIN (one of `ean13`, `upc`, `isbn`), `mpn`, features (3 or more), description (150 words or more, tags stripped, counted as `somcp_audit_product_seo`), summary (`description_short`), images (3 or more, each with a caption in the language), weight (above 0), condition (set and **Display condition on product page** on), a default category deeper than Home, meta description.
- The labels match the product page of the shop's version: the barcode is **EAN-13 or JAN barcode** on PrestaShop 8 and **GTIN (EAN, JA, ITF or UCC code)** on PrestaShop 9; the default category is **Main category** on PrestaShop 8 and **Default category** on PrestaShop 9; `condition` is **Condition** on PrestaShop 8 and **Display condition on product page** on PrestaShop 9; **Weight** on both. Same columns in the database. The labels come from one table shared with `somcp_audit_product_schema` (`SomcpToolAuditProductSchema::fieldLabel()`).
- The whole catalogue is scored with set queries on `product`, `product_shop`, `product_lang`, `feature_product` and the image tables of the token's shop and language, never one `Product` object per product.
- Errors in the shape of #169: an unknown language, `max_score` outside 0 to 100, `limit` above 100, an `active` that is not true or false (`SomcpTool::statusFilter()`, shared with the SEO audits).
- Read only.

#### `somcp_check_ai_crawler_access`
**State**: written · [issue #97](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/97)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - says whether the main AI crawlers may read the shop: for each crawler, whether the shop's robots.txt lets it fetch the home page, a product page and a category page, with the rule deciding it, and whether those pages tell it not to index them (robots meta tag or `X-Robots-Tag` header).

**Do not use for** - the full robots.txt and all its rules (→ `somcp_get_robots_txt`); writing it again (→ `somcp_generate_robots_txt`); the SEO of one page (→ `somcp_audit_page`).

**Parameters**

None.

**Returns**
- `robots_txt_present` - as `present` of `somcp_get_robots_txt`.
- `pages` - the pages checked, each `type` (`home`, `product`, `category`), `url`, `language` (ISO code), `meta_robots`, `x_robots_tag` (as `somcp_audit_page` returns them, `null` when absent), `fetched` (with `error` when the page could not be read).
- `crawlers` - one per crawler, in order: `GPTBot`, `OAI-SearchBot`, `ChatGPT-User`, `ClaudeBot`, `Claude-SearchBot`, `PerplexityBot`, `Google-Extended`, `Applebot-Extended`, `CCBot`, `Bingbot`. Each: `name` (the user agent token), `purpose` (one line), `group` (the user agents of the robots.txt group that applies, its own group else `*`, `null` when none), and `pages[]` - per page of `pages`: `type`, `language`, `allowed`, `rule` (the deciding line as written in the file, `null` when none matches), `noindex` (true when the page's robots meta or `X-Robots-Tag` says `noindex` or `none` for every robot or for this one, as in `GPTBot: noindex`).
- `warnings` - `code` and `why`, the shapes of `somcp_get_robots_txt`: `blocked_everywhere` (a crawler refused on every page), `blocks_products`, `blocks_categories` (a crawler refused on that page type), `noindex_page` (a page that keeps every crawler out), `no_robots_txt` (no file, so everything is allowed), `not_at_domain_root`.

**Notes**
- The robots.txt is read and matched with `somcp_get_robots_txt` (`samples()`, `parse()`, `rule()`, `matches()`), never a second copy, so the two tools can never disagree on what a rule blocks. Which group applies follows RFC 9309: the group whose user agent matches the crawler's token (case-insensitive), else `*`; the longest matching pattern wins, `Allow` wins a tie.
- The pages are those of `somcp_get_robots_txt` (first displayed product, first displayed category below Home, in each active language), plus the home page of each language. Each is fetched once with the code of `somcp_audit_page` (`follow()`, the address guard, `load()`), and its robots meta read with its reader (`meta()`) and its `X-Robots-Tag` header, same size and time limits.
- A page that cannot be fetched is reported in `pages` with `fetched` false and an `error`, never an error for the whole call. Its `allowed` and `rule` still come from the robots.txt; its `noindex` is false, since the page was not read.
- Reports only; whether to open the shop to a crawler is the merchant's choice.
- Errors in the shape of #169: unknown arguments.
- Same **Robots file generation** card and the same robots logic in the core and the Classic theme on 8.0.1 and 9.0.0. Read only.

#### `somcp_audit_store_policies`
**State**: written · [issue #98](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/98)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.seo.read`

**Does** - checks that the pages a buyer, a search engine or an answer engine looks for before trusting a shop exist, are displayed, can be indexed, have real content and are linked from the home page: legal notice, terms and conditions, shipping, returns, privacy, about us, contact. Also reports the settings those pages depend on (terms to accept at checkout, returns, delivery times).

**Do not use for** - the SEO fields of these pages (→ `somcp_audit_cms_seo`, `somcp_update_cms_seo`); one page in full (→ `somcp_get_cms_page`); whether robots may crawl them (→ `somcp_check_ai_crawler_access`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages; the shop default when left out; a code the shop does not have is refused |

**Returns**
- `language` - the ISO code used.
- `pages` - one entry per expected page, in this order: `legal_notice`, `terms`, `shipping`, `returns`, `privacy`, `about`, `contact`. Each: `type`, `found`, `matched_by` (`setting` - **Page for terms and conditions**, `role` - `cms_role`, `url` - friendly URL, `title` - **Title**, `controller` - the contact page; `null` when not found), `id_cms` (as `somcp_list_cms_pages`), `title`, `active`, `indexation` (as `somcp_get_cms_page`: **Title**, **Displayed**, **Indexation by search engines**), `url` (storefront address in `language`), `word_count` (words of **Page content**, tags removed), `linked` (true when the home page links to `url`, `null` when the home page could not be read) and, when several pages could match, `candidates` (`id_cms` and `title` of each, for the merchant to choose). `id_cms`, `title`, `active`, `indexation` and `word_count` are `null` for the contact page.
- `checkout` - `terms_required` (**Terms of service**), `terms_id_cms` (**Page for terms and conditions**, `null` for None).
- `returns` - `enabled` (**Enable returns**), `days` (**Time limit of validity**).
- `delivery_times` - `in_stock`, `out_of_stock` (the two **Delivery time** texts of Product Settings in `language`, `null` when empty), `products_without` (active products of the shop set to no delivery time, or to the default one while the in-stock text is empty).
- `home` - `url`, `fetched`, `error` (when not read).
- `warnings` - `code` and `why`, plus `type` for a page: `page_missing`, `page_hidden`, `page_noindex`, `page_too_short` (under 100 words, the contact page excepted), `page_not_linked`, `terms_not_required`, `terms_page_mismatch` (**Page for terms and conditions** is hidden or is not the page of the `LEGAL_CONDITIONS` role), `returns_page_but_disabled`, `returns_enabled_no_page`, `no_delivery_time`.
- `note` - the reminder that the legal content of these pages is the merchant's responsibility.

**Notes**
- Matching order, the first that answers wins: the setting (`terms` only), the `cms_role` rows (`LEGAL_NOTICE`, `LEGAL_CONDITIONS`), then the friendly URL, then the **Title**, in every active language of the shop, from one keyword list (en, fr, es, de; English for the others). Only CMS pages associated with the token's shop; a page already matched to one type is not offered to the next.
- The home page is fetched once with the code of `somcp_audit_page` (`hosts()`, `follow()`, `load()`, `links()`), same size and time limits; a home page that cannot be fetched is reported in `home`, never an error for the whole call. The words are counted as every other audit (`SomcpToolAuditProductSeo::words()`).
- The contact page is the `contact` front controller, not a CMS page.
- No figure, text or advice about what a policy must say: the tool reports what exists.
- Errors in the shape of #169: unknown arguments, a `language` the shop does not have.
- Same pages, roles, settings and labels on 8.0.1 and 9.0.0. Read only.

#### `somcp_get_llms_txt`
**State**: written · [issue #99](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/99)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's llms.txt, the plain text summary some AI tools read at the root of a site, if it has one, and says whether somcp wrote it.

**Do not use for** - writing it (→ `somcp_generate_llms_txt`); robots.txt (→ `somcp_get_robots_txt`); whether AI crawlers may read the shop (→ `somcp_check_ai_crawler_access`).

**Parameters**

None.

**Returns**
- `present` - as `somcp_get_robots_txt`: false when there is no file or it cannot be read.
- `content` - the file as it is (first 500 KiB, byte order mark dropped), `null` when absent.
- `size` - bytes, `null` when absent.
- `last_modified` - ISO 8601, `null` when absent.
- `written_by_somcp` - true when the file holds the marker `somcp_generate_llms_txt` writes, `null` when absent.
- `url` - the address where AI tools ask for it: root of the token's shop domain + `/llms.txt`.
- `warnings` - `code` and `why`, the shape of `somcp_get_robots_txt`: `no_llms_txt`, `not_at_domain_root` (the shop is in a folder: the file at the root of the domain is the one read), `too_large`, `not_markdown` (no `# ` title line, which the proposed format starts with).
- `note` - that llms.txt is a proposed convention no crawler or assistant is bound to read, and on a multistore installation that the file is shared by every shop.

**Notes**
- The file is read with `somcp_get_robots_txt` (`read()`, `MAX_BYTES`), never a second copy: same root (`_PS_ROOT_DIR_`), same size cap, same byte order mark handling. The marker is one constant, `SomcpToolGetLlmsTxt::MARKER`, used by `somcp_generate_llms_txt` too.
- Nothing is written or fetched over the network: the file is read from disk. The `.htaccess` of 8.0.1 and 9.0.0 serves an existing file as is; on nginx it depends on the server rules, so the tool reports the file, not what the web server serves.
- No back office screen shows or edits an llms.txt on either version; the view right is the one of **Shop Parameters > Traffic & SEO**, where robots.txt is managed.
- Errors in the shape of #169: unknown arguments. An absent or unreadable file is an answer with `present: false`, never an error.
- Same root and same `.htaccess` rule on 8.0.1 and 9.0.0. Read only.

#### `somcp_generate_llms_txt`
**State**: written · [issue #100](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/100)
**Nature**: `acts(EDIT, 'AdminMeta')` with `add` and `delete` · **Scope**: `mcp.seo.write`

**Does** - writes an llms.txt at the root of the installation, the plain text summary some AI tools read at the root of a site, from the shop's own data: name, a summary agreed with the merchant, the main categories, the policy and information pages, the contact page.

**Do not use for** - reading the file (→ `somcp_get_llms_txt`); robots.txt (→ `somcp_generate_robots_txt`); whether AI crawlers may read the shop (→ `somcp_check_ai_crawler_access`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code of one of the shop's active languages, the language of the file and of its links; shop default when left out; an unknown code refused among the other problems of the call with `SomcpTool::languageProblem()` |
| `summary` | string | no | the paragraph describing the shop, written with the merchant; the home page meta description when left out; 1000 characters at most |
| `max_categories` | integer | no | 20 by default, 100 at most: the first categories under Home in their Position order |
| `overwrite` | boolean | no | required to replace a file somcp did not write (no marker) |

**Returns**
- `changed` - false when the content written is the one already on disk (the file is then not rewritten).
- `written` - whether the file was written this call.
- `previous_content` - the file before, `null` when there was none; the only copy left of a file written by hand.
- `content` - what was written.
- `url` - as `somcp_get_llms_txt`: root of the token's shop domain + `/llms.txt`.
- `language` - the ISO code used.
- `warnings` - `code` and `why`, the shape of `somcp_get_robots_txt`: `not_at_domain_root` (the shop is in a folder), `shared_by_shops` (one file for every shop of the installation), `pages_not_indexable` (a listed CMS page has Indexation by search engines set to No).
- `note` - a fixed reminder for the merchant: llms.txt is a proposed convention that no crawler or assistant is bound to read, and only what the shop holds was written.

**Notes**
- No back office screen writes an llms.txt on either version, so the tool assembles the file itself. It is written atomically next to robots.txt (a temporary file in the same folder, then renamed), so a reader never gets half a file.
- The file is read with `somcp_get_robots_txt` (`read()`, `MAX_BYTES`) and the marker is the one constant `SomcpToolGetLlmsTxt::MARKER`, shared with `somcp_get_llms_txt`. Which CMS page is a policy page is decided by `SomcpToolAuditStorePolicies::policyPages()`, called not copied, so the policy pages come first in the file.
- The rights are those of `somcp_generate_robots_txt`: `add`, `edit` and `delete` on `AdminMeta`, since it replaces a file at the root whole. A file somcp did not write is replaced only with `overwrite: true`; without it the call is refused as an error and the file's content comes back in the refusal. A shop whose root is not writable gets a clear refusal, nothing changed, as `somcp_generate_robots_txt`.
- Only what the shop holds is written: no claim, review, price or figure is invented. One file at the root means one language per file. Nothing is fetched over the network.
- Errors in the shape of #169: unknown arguments; unknown language; `summary` too long; `max_categories` out of range. An existing file without the marker and no `overwrite: true` is an error too, nothing written: the sentence asks to call again with `overwrite` set to true once the merchant agrees, followed by the file's current content.
- Same root, same `.htaccess` rule and same sources on 8.0.1 and 9.0.0.

### Sales

The levers that turn visits into orders: carts left behind, what customers search for, what they look at without buying, products that stopped selling, and the promotions that move them.

**Not here**: sales figures over a period (→ Statistics), the orders themselves (→ Orders), a product's own specific prices (→ `somcp_update_product`).

#### `somcp_list_abandoned_carts`
**State**: written · [issue #101](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/101)
**Nature**: `acts(VIEW, 'AdminCarts')` · **Scope**: `mcp.sales.read`

**Does** - lists the carts that hold products and never became an order, newest first, as the **Abandoned cart** carts of **Orders > Shopping Carts**. Read only.

**Do not use for** - placed orders (→ `somcp_search_orders`); one customer's history (→ `somcp_get_customer`); sales figures (→ `somcp_get_sales_summary`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `older_than_hours` | integer | no | 24 by default, the back office rule; 1 at least |
| `date_from` / `date_to` | string | no | `YYYY-MM-DD` on `cart.date_add`, the **Date** column; an invalid date is refused, not ignored (#169) |
| `with_customer` | boolean | no | true: only carts linked to a customer account (guest accounts included); false: only carts without one |
| `customer_name` | string | no | the **Customer** filter: first name, last name or both, whole or partial |
| `online` | boolean | no | the **Online** filter |
| `id_cart` | integer | no | the **ID** filter; a cart that is not abandoned (it has an order, is empty or too recent) gives an empty list, not an error |
| `id_carrier` | integer | no | the **Carrier** filter, from `somcp_list_carriers`, matched on the carrier's reference so carts saved before the carrier was edited are found |
| `language` | string | no | ISO code of one of the shop's active languages for product names (#149); shop default when left out; unknown code refused |
| `limit` / `offset` | integer | no | as `somcp_search_orders`: 20 by default, 100 at most |

**Returns**

| Field | Notes |
|---|---|
| `carts[].id_cart` | **ID** |
| `carts[].date_add` / `date_upd` | **Date** of the list is `date_add`; **Last updated** of the 9.0.0 page is `date_upd`; ISO 8601 with the shop's offset (`SomcpTool::isoDate()`), as the order and customer tools give their dates |
| `carts[].id_customer` | 0 when the cart has no customer, as `somcp_get_order` |
| `carts[].customer_name` | full name, empty when none; no email, phone or address (shared conventions); `somcp_get_customer` gives the rest under the right scope |
| `carts[].is_guest` | the customer is a guest account |
| `carts[].online` | **Online**: the cart's visitor had a connection in the last 30 minutes |
| `carts[].carrier` | **Carrier** name, empty when none |
| `carts[].products_count` | total quantity of products |
| `carts[].total_products_tax_excl` / `total_products_tax_incl` | **Total** of the list, products only; `null` when the cart can no longer be priced |
| `carts[].currency` | ISO code |
| `carts[].products` | the three most expensive lines: `id_product`, `id_product_attribute`, `name` (with the combination), `reference`, `quantity`, `unit_price_tax_incl` |
| `returned`, `total_count`, `offset` | as `somcp_search_orders` |
| `older_than_hours` | the age used |
| `total_value_tax_incl` | sum of `total_products_tax_incl` over every matching cart, not only this page, keyed by currency ISO code (no conversion) |
| `total_value_complete` | false when the sum leaves a matching cart out: more than 1000 carts match (it then covers the 1000 newest), or a cart could not be priced any more (a product removed since); each cart is priced by PrestaShop itself |
| `language` | ISO code used |

**Errors** - in the shape of #169, all in one answer: unknown arguments, `older_than_hours` below 1, invalid dates, `date_from` after `date_to`, `limit` outside 1 to 100, a negative `offset`, an unknown language, an `id_cart` or `id_carrier` that is not a whole number above 0, a carrier this shop does not have or a deleted one (`id_carrier: no carrier of this shop has the identifier N - find it with somcp_list_carriers`).

**Notes**
- The back office rule on both versions: a cart with no order, created more than 24 hours ago (`AdminCartsController` on 8.0.1, `CartStatus::ABANDONED_CART_EXPIRATION_TIME` in `CartQueryBuilder` on 9.0.0, both on `cart.date_add` against PHP's clock). A younger cart without an order is **Non ordered**.
- A cart with an order is never listed, nor a cart with no product line (the back office lists those; nothing to recover in them).
- The amounts are computed by the cart itself (`Cart::getOrderTotal()` with `Cart::ONLY_PRODUCTS`), so specific prices and taxes follow the shop's rules: **Total** of the list is the products only, without shipping.
- Read only: the tool sends nothing to anyone and never returns the **Share this cart** link of 9.0.0 or its token.
- Carts are per shop (`cart.id_shop`): the token's shop only. An `edit` right on `AdminCarts` is not needed: the list and the cart page are open with `view`.
- 8.0.1 has no **Status** column (the status is a text in **Order ID**) and shows **Online** only with guest checkout on; 9.0.0 has both. The tool returns the same fields on both.

#### `somcp_list_search_terms`
**State**: written · [issue #102](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/102)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists what visitors typed in the shop's search box over a period, with how many times and how many products each search found, as **Stats > Shop search**. Read only.

**Do not use for** - search engine queries from outside the shop, which need Search Console; the products that sold (→ `somcp_list_best_sellers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | yes | **From:** / **To:**, `YYYY-MM-DD`, both days included, as `somcp_list_best_sellers`; the employee's saved stats period is never read nor changed |
| `min_searches` | integer | no | 2 by default, as the screen ("searched for more than once"); 1 to include terms searched once |
| `no_results_only` | boolean | no | only the terms whose **Results** is 0: what visitors looked for and the catalogue does not have |
| `limit` / `offset` | integer | no | as `somcp_list_best_sellers`: 20 by default, 100 at most |

**Returns**

| Field | Notes |
|---|---|
| `terms[].keywords` | **Keywords**, as stored (the first spelling the grouping keeps) |
| `terms[].searches` | **Occurrences** |
| `terms[].results` | **Results**: the most products found by that term in the period (`MAX(results)`), not an average |
| `terms[].last_searched` | `MAX(date_add)`, ISO 8601 with the shop's offset (`SomcpTool::isoDate()`); not on the screen, so an old gap can be told from a new one |
| `date_from` / `date_to` | the period used |
| `returned`, `total_count`, `offset` | `total_count` counts the terms after the minimum length and `min_searches`, so it matches the rows of the screen |

Sorted by `searches`, highest first, then `keywords`.

**Errors** - in the shape of #169, all in one answer: unknown arguments; `date_from` / `date_to` missing or not a date, `date_to` before `date_from` (the texts of `somcp_list_best_sellers`); `min_searches` below 1; `limit` outside 1 to 100; a negative `offset`. When `statssearch` is not installed and enabled, the call is refused with a text that names **Shop search** (`statssearch`) and says nothing is recorded without it: an empty list would read as "nobody searched".

**Notes**
- Read from the `statssearch` table, one row per search of the shop (`hookActionSearch`). Rows are grouped by `keywords` in SQL, so the collation of the install merges spellings as the screen does: `Mug` and `mug` are one term.
- Terms shorter than the minimum word length of **Shop Parameters > Search** (`PS_SEARCH_MINWORDLEN`) are left out in SQL, not after paging, so a page is never short.
- The table holds no customer, no IP and no session: nothing personal is returned. A term is returned as text, never as a link.
- The token's shop only (`id_shop`), as the screen.
- No difference between 8.0.1 and 9.0.0 (`statssearch` 2.0.2 on both).

#### `somcp_list_viewed_not_sold`
**State**: written · [issue #103](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/103)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists the saved products of the shop with their page views and what they sold over a period, most viewed first, so the ones seen but not bought stand out - the figures **Stats > Product details** shows one product at a time. Read only.

**Do not use for** - best sellers (→ `somcp_list_best_sellers`); products that sold nothing whether viewed or not; stock (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | yes | **From:** / **To:**, `YYYY-MM-DD`, both days included, as `somcp_list_best_sellers`; the employee's saved stats period is never read nor changed |
| `id_category` | integer | no | **Choose a category**: only the products of that category, from `somcp_list_categories`; unknown in the shop is refused |
| `min_page_views` | integer | no | only the products viewed at least this many times; 1 by default, so a product nobody opened is left out; 0 lists every product |
| `language` | string | no | ISO code of one of the shop's active languages for `name`, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |
| `limit` / `offset` | integer | no | as `somcp_list_best_sellers`: 20 by default, 100 at most |

**Returns**

| Field | Notes |
|---|---|
| `page_views_recorded` | `true` when `statsdata` is installed and enabled and **Save global page views** is on; `false` adds a `note` saying page views are not being recorded now, so `page_views` counts only the time the setting was on in the period, the sales staying real |
| `products[].id_product`, `reference`, `name` | **Reference**, **Name**, under the names of `somcp_list_best_sellers` |
| `products[].page_views` | **Total Viewed** |
| `products[].quantity_sold` | **Total bought**, valid orders dated by their order date |
| `products[].revenue_tax_excl` | **Sales (tax excluded)**, divided by the order's conversion rate so it is in the default currency |
| `products[].conversion_rate` | **Conversion rate**, `quantity_sold / page_views`, two decimals, units bought per view; `null` when `page_views` is 0 |
| `products[].quantity` | **Available quantity for sale**, as `somcp_list_best_sellers` |
| `products[].active` | whether the product is on sale |
| `date_from` / `date_to`, `language` | what was used |
| `returned`, `total_count`, `offset` | `total_count` counts the products after `id_category` and `min_page_views` |

Sorted by `page_views`, highest first, then `conversion_rate`, lowest first, then `id_product`: the products most seen and least bought come first.

**Errors** - in the shape of #169, all in one answer: unknown arguments; `date_from` / `date_to` missing or not a date, `date_to` before `date_from` (the texts of `somcp_list_best_sellers`); `id_category` not a whole number above 0 (`"3"` is read as 3, as in every tool) or unknown in the shop, the identifier given named with `somcp_list_categories` to find it; `min_page_views` below 0; `limit` outside 1 to 100; a negative `offset`; an unknown `language`, named by `SomcpTool::languageProblem()` with the shop's languages.

**Notes**
- Saved products of the token's shop only (`product_shop`, `p.state = Product::STATE_SAVED`); orders of the token's shop only.
- Page views and the stock quantity are read through the shared `SomcpStats` subqueries, the same way `somcp_list_best_sellers` reads them.
- **Save global page views** (`PS_STATSDATA_PAGESVIEWS`) is off by default: while it is off no new views are saved to `page_viewed`, so `page_views` counts only the time it was on in the period, which on a fresh install is none.
- No customer is returned: the orders behind a product are `somcp_search_orders`.
- No difference between 8.0.1 and 9.0.0 (`statsproduct` 2.1.1 / 2.1.3, `statsdata` 2.1.0 / 2.1.2).

#### `somcp_list_unsold_products`
**State**: written · [issue #104](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/104)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists the active products of the shop that no valid order bought, over a period or ever, with their stock and what that stock is worth - the **Products never purchased** list of **Stats > Catalog statistics**, with the stock the screen does not show. Read only.

**Do not use for** - low stock (→ `somcp_list_low_stock`); products seen but not bought (→ `somcp_list_viewed_not_sold`); what sold most (→ `somcp_list_best_sellers`); the stock of one product (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | no | `YYYY-MM-DD`, both days included, as `somcp_list_best_sellers`; both or neither. Left out: no period, every valid order ever, as the screen. Given: products bought by no valid order dated (`o.date_add`) in the period, products added to the shop (`product_shop.date_add`) after `date_from` left out |
| `id_category` | integer | no | **Choose a category**: only the products of that category, from `somcp_list_categories`; unknown in the shop is refused |
| `min_quantity` | integer | no | only the products with at least this quantity for sale; 1 by default, so the stock that does not move; 0 lists every product, as the screen |
| `language` | string | no | ISO code of one of the shop's active languages for `name`, refused otherwise, among the other problems of the call with `SomcpTool::languageProblem()`; the shop default when left out |
| `limit` / `offset` | integer | no | as `somcp_list_best_sellers`: 20 by default, 100 at most |

**Returns**

| Field | Notes |
|---|---|
| `products[].id_product`, `reference`, `name` | **ID**, **Name**, under the names of `somcp_list_best_sellers` |
| `products[].quantity` | quantity for sale, as `somcp_list_best_sellers` |
| `products[].price_tax_excl` | the base price, tax excluded (`product_shop.price`), as `somcp_get_product` |
| `products[].wholesale_price` | **Cost price** of the product page, as `somcp_get_product` names it; `null` when 0, which means not set |
| `products[].stock_value_tax_excl` | `quantity` times `wholesale_price`, rounded to the default currency's precision; `null` when `wholesale_price` is `null` |
| `products[].date_add` | the date the product was added to the shop, ISO 8601 |
| `products[].last_sold` | the date of the last valid order of the shop holding the product, any date, ISO 8601; `null` when never bought |
| `total_count` | **Products never purchased**, with the filters, before paging |
| `products_available` | **Products available**: the active products `id_category` and `min_quantity` keep |
| `total_stock_value_tax_excl` | sum of `stock_value_tax_excl` over every product of `total_count`, not only the page |
| `date_from` / `date_to`, `language` | what was used; `null` dates when left out |
| `returned`, `offset` | as `somcp_list_best_sellers` |

Sorted by `stock_value_tax_excl`, highest first, `null` last, then `quantity`, highest first, then `id_product`: the stock that costs the most comes first.

**Errors** - in the shape of #169, all in one answer: unknown arguments; `date_from` / `date_to` not a date, `date_to` before `date_from` (the texts of `somcp_list_best_sellers`), one given without the other; `id_category` not a whole number above 0 (`"3"` is read as 3, as in every tool) or unknown in the shop, the identifier given named with `somcp_list_categories` to find it; `min_quantity` below 0; `limit` outside 1 to 100; a negative `offset`; an unknown `language`, named by `SomcpTool::languageProblem()` with the shop's languages. An empty list is `products: []`, `total_count: 0`.

**Notes**
- Active, saved products of the token's shop only (`product_shop.active = 1`, `p.state = Product::STATE_SAVED`); orders of the token's shop only (`o.id_shop`). The screen counts the orders of every shop: the figures differ only in a multistore shop.
- A product counts as bought when any of its combinations was (`order_detail.product_id`), as the screen.
- The stock quantity is read through the shared `SomcpStats` subquery, the same way `somcp_list_best_sellers` reads it; a product without a stock row has 0.
- Amounts are in the shop's default currency, read as stored, without the tax or specific prices of a customer.
- No difference between 8.0.1 and 9.0.0 (`statscatalog` 2.0.4 on both).

#### `somcp_list_cart_rules`
**State**: written · [issue #105](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/105)
**Nature**: `acts(VIEW, 'AdminCartRules')` · **Scope**: `mcp.sales.read`

**Does** - lists the cart rules of this shop - vouchers with a code and discounts applied on their own - newest first unless sorted otherwise, as **Catalog > Discounts > Cart Rules**, with what each one gives in one line. Read only.

**Do not use for** - one rule with its conditions (→ `somcp_get_cart_rule`); catalogue price rules (→ `somcp_list_catalog_price_rules`); the vouchers of one order (→ `somcp_get_order`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | the **Status** filter |
| `name` | string | no | part of the **Name**, without case, in `language` |
| `code` | string | no | part of the **Code**, without case; an empty code is refused (use `has_code`) |
| `has_code` | boolean | no | true: vouchers the customer types a code for; false: rules applied on their own, with no code |
| `current` | boolean | no | only the rules valid now: **Valid** **From**/**To** holds the shop's current time and **Quantity** is above 0 |
| `priority` | integer | no | the rules of exactly this **Priority**, 1 at least |
| `quantity_min` / `quantity_max` | integer | no | **Quantity**, the uses left, both included, 0 at least, as `somcp_search_products` names its quantity range; `quantity_max: 0` lists the rules with no use left |
| `expiration_date_from` / `expiration_date_to` | string | no | **Expiration date** (`date_to`), `YYYY-MM-DD` in the shop's time zone, both days included, as `somcp_search_orders` takes its period; named after the screen, since `date_to` is a field of each rule answered |
| `sort` | string | no | `id_desc` (default), `id_asc`, `name_asc`, `name_desc`, `priority_asc`, `priority_desc`, `code_asc`, `code_desc`, `quantity_asc`, `quantity_desc`, `expiration_date_asc`, `expiration_date_desc`: the sortable columns of the list (**Status** is not); ties newest first, so pages never overlap |
| `language` | string | no | ISO code of one of the shop's active languages for `name` (#149); shop default when left out; unknown code refused |
| `limit` / `offset` | integer | no | as `somcp_list_best_sellers`: 20 by default, 100 at most |

**Returns** - `cart_rules`, in the order of `sort` (by default the list's: `id_cart_rule` descending, newest first), each with:

| Field | Notes |
|---|---|
| `id_cart_rule` | **ID** |
| `name` | **Name**, in `language` |
| `priority` | **Priority** |
| `code` | **Code**, empty string when none |
| `quantity` | **Total available**: uses left, lowered at each order |
| `quantity_per_user` | **Total available for each user** |
| `date_from` / `date_to` | **Valid** **From**/**To** (**Expiration date** in the list), ISO 8601 with the shop's offset, `null` when not set |
| `active` | **Status** |
| `id_customer` | **Limit to a single customer**: the identifier `somcp_get_customer` takes, `null` when the rule is for everyone |
| `free_shipping` | **Free shipping** |
| `reduction` | **Apply a discount**: `{"type":"percent","value":10}`, or `{"type":"amount","value":5,"currency":"EUR","tax_included":true}`, plus `id_product` when it is a specific product; `null` for **None** |
| `reduction_applies_to` | **Apply a discount to**: `order`, `specific_product`, `cheapest_product` or `selected_products` (`reduction_product` 0, above 0, -1, -2); `null` when `reduction` is `null` |
| `gift` | **Send a free gift**: `{"id_product":...,"id_product_attribute":...}` or `null` |
| `summary` | one English line built from the three above, e.g. `10% off the order, free shipping` |
| `has_restrictions` | the restrictions that are on (`country`, `carrier`, `group`, `cart_rule`, `product`, `shop`), so the assistant knows when to read the rule with `somcp_get_cart_rule` |
| `times_used` | orders that used the rule (`order_cart_rule`, not deleted), the use the screen does not show |
| `returned`, `total_count`, `offset`, `language` | as `somcp_list_abandoned_carts` |

**Errors** - in the shape of #169, all in one answer: unknown arguments; an empty `code`; a `priority` that is not a whole number of 1 at least, a quantity that is not a whole number of 0 at least, a `quantity_max` below `quantity_min`; an expiration date that is not `YYYY-MM-DD`, an `expiration_date_to` before `expiration_date_from`; a `sort` outside the list (the values named); `limit` outside 1 to 100; a negative `offset`; an unknown language (#149). An empty list is `cart_rules: []`, `total_count: 0`.

**Notes**
- The filters combine with AND, `current` among them.
- The back office list shows every shop's rules; the token's shop keeps the rules it can use (`shop_restriction = 0`, or a `cart_rule_shop` row for it).
- `reduction`, `reduction_applies_to`, `gift` and `summary` are built by one shared method (`SomcpToolListCartRules::describe()`) that `somcp_get_cart_rule` (#106) calls, so a rule reads the same whichever tool reads it.
- No minimum amount, restriction list or product selection here: they belong to `somcp_get_cart_rule`. Read only.
- No difference between 8.0.1 and 9.0.0: the same legacy controller, list and `cart_rule` table on both.

#### `somcp_get_cart_rule`
**State**: written · [issue #106](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/106)
**Nature**: `acts(VIEW, 'AdminCartRules')` · **Scope**: `mcp.sales.read`

**Does** - returns every field of one cart rule, as the three tabs of **Catalog > Discounts > Cart Rules > Edit** show it (**Information**, **Conditions**, **Actions**), with its restrictions as lists of identifiers and names. Read only.

**Do not use for** - listing rules (→ `somcp_list_cart_rules`); catalogue price rules (→ `somcp_list_catalog_price_rules`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cart_rule` | integer | yes | the **ID** of the list, from `somcp_list_cart_rules` |
| `language` | string | no | ISO code of one of the shop's active languages (#149), for the names of the restrictions and of the gift; shop default when left out; unknown code refused |

**Returns** - `cart_rule`, with `language` at the top level:

| Field | Notes |
|---|---|
| `id_cart_rule` | |
| `name` | **Information** > **Name**, an object keyed by ISO code with every active language of the shop, so it can be given back to `somcp_update_cart_rule` as is |
| `description` | **Description**, plain text, empty string when none |
| `code` | **Code**, empty string when the rule applies on its own |
| `highlight` / `partial_use` | **Highlight** / **Partial use**, booleans |
| `priority` | **Priority** |
| `active` | **Status** |
| `id_customer` | **Conditions** > **Limit to a single customer**: `null` for everyone; with `customer` (`firstname`, `lastname`) beside it, the identifier `somcp_get_customer` takes |
| `date_from` / `date_to` | **Valid** **From** / **To**, ISO 8601 with the shop's offset, `null` when not set, as `somcp_list_cart_rules` |
| `minimum_amount` | **Minimum amount**: `null` when 0, else `{"value": 50, "currency": "EUR", "tax_included": false, "shipping_included": false}` |
| `quantity` / `quantity_per_user` | **Total available** / **Total available for each user** |
| `restrictions.country` | **Country selection**: `null` when off, else `[{"id_country", "name"}]` |
| `restrictions.carrier` | **Carrier selection**: `null` when off, else `[{"id_reference", "name"}]`, the `id_reference` of `somcp_list_carriers` (the core stores the reference, not `id_carrier`) |
| `restrictions.group` | **Customer group selection**: `null` when off, else `[{"id_group", "name"}]` |
| `restrictions.cart_rule` | **Compatibility with other cart rules**: `null` when off, else the rules it combines with, `[{"id_cart_rule", "name"}]` |
| `restrictions.product` | **Product selection**: `null` when off, else the groups `[{"quantity": 1, "rules": [{"type": "products", "values": [{"id_product", "name"}]}]}]`, `type` one of `products`, `categories`, `attributes` (named "Group - Value", as the form), `manufacturers`, `suppliers`; each value is keyed by the identifier of its type, as the list tool of that type returns it: `id_product` (`somcp_search_products`), `id_category` (`somcp_list_categories`), `id_attribute` (`somcp_list_attributes`), `id_manufacturer` (`somcp_list_manufacturers`), `id_supplier` (`somcp_list_suppliers`) |
| `restrictions.shop` | **Store selection** (multistore): `null` when off, else `[{"id_shop", "name"}]` |
| `free_shipping` | **Actions** > **Free shipping** |
| `reduction` / `reduction_applies_to` | **Apply a discount** / **Apply a discount to**, built by `SomcpToolListCartRules::describe()`, the shapes of `somcp_list_cart_rules` |
| `reduction_exclude_special` | **Exclude discounted products**, meaningful for a percent |
| `gift` | **Send a free gift**: `{"id_product", "id_product_attribute", "name"}` or `null` |
| `summary` | the one line of `somcp_list_cart_rules` |
| `times_used` | as `somcp_list_cart_rules` |
| `date_add` / `date_upd` | ISO 8601 with the shop's offset, `null` when not set |

**Errors** - in the shape of #169, all in one answer: unknown arguments; a missing or non-positive `id_cart_rule`; an unknown language (#149). An `id_cart_rule` no rule of this shop has: `id_cart_rule: no cart rule of this shop has the identifier <n> - find it with somcp_list_cart_rules`, the token's shop deciding as in `somcp_list_cart_rules`, in the same answer as the other problems of the call.

**Notes**
- The rules this shop can use and `times_used` are read by the same functions as `somcp_list_cart_rules` (`inShop()`, `timesUsed()`), so a rule the list hides is one this tool refuses.
- The restrictions are read by one public function, `SomcpToolGetCartRule::restrictions()`, that `somcp_update_cart_rule` calls to compare. Product selection is read with the core's `CartRule::getProductRuleGroups()`.
- A restriction pointing to something deleted since (a carrier, a product) is left out of its list, as the back office leaves it out of the form. When **Apply a discount to** is **Specific product**, the back office adds that product to **Product selection** on its own: it shows there too, as stored.
- No difference between 8.0.1 and 9.0.0 apart from the type of `description` (`text` / `mediumtext`).

#### `somcp_create_cart_rule`
**State**: written · [issue #107](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/107)
**Nature**: `acts(ADD, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - creates one cart rule - a voucher the customer types, or a discount applied on its own - with the fields of the **Information**, **Conditions** and **Actions** tabs of **Catalog > Discounts > Cart Rules > Add new**, and the **Restrictions** of the **Conditions** tab **Country selection**, **Carrier selection**, **Customer group selection** (#407), **Product selection** (#434) and **Compatibility with other cart rules** (#418).

**Do not use for** - changing a rule (→ `somcp_update_cart_rule`), switching it on (→ `somcp_set_cart_rule_active`), a discount on products of the catalogue (→ `somcp_create_catalog_price_rule`), a discount on one order already placed (→ `somcp_add_order_discount`), its shops (back office).

**Parameters** - the names and shapes `somcp_get_cart_rule` returns, so a rule read there can be given back.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `languages` | | array | no | ISO codes of the languages to fill (#149), all active ones by default; a language left out takes the default one's name |
| `name` | **Name** | object | yes | per ISO code, shown to the customer; 254 characters at most, `CartRule` validation |
| `description` | **Description** | string | no | for the merchant only, empty by default |
| `code` | **Code** | string | no | empty: the rule applies on its own to every cart that meets the conditions; refused if another rule of any shop has it; 254 characters at most |
| `highlight` | **Highlight** | boolean | no | false by default |
| `partial_use` | **Partial use** | boolean | no | true by default, as the form |
| `priority` | **Priority** | integer | no | 1 by default, 1 at least |
| `active` | **Status** | boolean | no | **false by default**, unlike the form: the merchant switches it on after reading it |
| `id_customer` | **Limit to a single customer** | integer or null | no | from `somcp_search_customers`; left out or `null`: everyone |
| `date_from` / `date_to` | **Valid** **From** / **To** | string | no | ISO 8601 as `somcp_get_cart_rule` returns it, converted to the shop's time zone (the shop's time without an offset); now and now plus one month by default, as the form |
| `minimum_amount` | **Minimum amount** | object or null | no | `{"value", "currency", "tax_included", "shipping_included"}`; `currency` an ISO code of an active currency of the shop, the default one when left out |
| `quantity` / `quantity_per_user` | **Total available** / **Total available for each user** | integer | no | 1 each by default, 0 at least |
| `free_shipping` | **Free shipping** | boolean | no | false by default |
| `reduction` | **Apply a discount** | object or null | no | `{"type": "percent", "value": 10}` or `{"type": "amount", "value": 5, "currency": "EUR", "tax_included": true}`, with `id_product` for a specific product, as `somcp_get_cart_rule`; `null`: **None** |
| `reduction_applies_to` | **Apply a discount to** | string or object | no | `order` (default), `specific_product`, `cheapest_product` or `selected_products`, the string `somcp_get_cart_rule` returns; `{"type": "specific_product", "id_product": 12}` also accepted; the last two refused with an `amount` |
| `reduction_exclude_special` | **Exclude discounted products** | boolean | no | false by default; only with a `percent` |
| `gift` | **Send a free gift** | object or null | no | `{"id_product", "id_product_attribute"}` (`name`, as read, is ignored); the combination required when the product has some, refused when it has none |
| `restrictions` | **Restrictions** | object | no | as `somcp_get_cart_rule` returns it: `country` **Country selection**, `carrier` **Carrier selection**, `group` **Customer group selection**, each a list of `{"id_country": 8}`, `{"id_reference": 2}` or `{"id_group": 3}` (a `name` given back is ignored) or `null` for none; `cart_rule` **Compatibility with other cart rules**, a list of `{"id_cart_rule": 2}` from `somcp_list_cart_rules` (the **Combinable cart rules**), `[]` to combine with none, `null` or left out to combine with every rule; `product` **Product selection**, a list of groups `{"quantity": 1, "rules": [{"type": "categories", "values": [{"id_category": 3}]}]}` as `somcp_update_cart_rule` takes it, `null` or left out for none, `[]` refused (a new rule has no read to give back); `shop` accepted `null` only |

`id_cart_rule`, `customer`, `summary`, `times_used`, `date_add` and `date_upd` are refused as worked out by the shop.

**Returns** - `created: true`, `id_cart_rule`, a `warning` when **Compatibility with other cart rules** checked made the back office stop other rules combining with the new one (`<n> other cart rule(s) can no longer be combined with this one, as in the back office: <id>, <id>.`), and `cart_rule` as `somcp_get_cart_rule` returns it, read back with its function (`SomcpToolGetCartRule::byId()`), names in the default language.

**Errors** - in the shape of #169 (`Nothing was created. Correct these and call again:` then one line per problem), every problem found before anything is written: unknown arguments; the checks of `AdminCartRulesController::postProcess()` with the core's wording, under their field: **The voucher cannot end before it begins.** (`date_to`), **The minimum amount cannot be lower than zero.** (`minimum_amount.value`), **Reduction percentage must be between 0% and 100%** and **Reduction amount cannot be lower than zero.** (`reduction.value`), **This cart rule code is already used** (`code`), **An action is required for this cart rule.** (`reduction`), **Product with required customization fields cannot be used as a gift.** (`gift.id_product`); a customer or product identifier that is not a whole number above 0 (`<path>: a whole number above 0`; 0 stays the default for `gift.id_product_attribute`); an unknown customer, product or combination (`<path>: no <thing> of this shop has the identifier <n> - find it with <tool>`); an unknown or inactive currency; a language of #149. For the restrictions, the six sentences of `somcp_update_cart_rule`: a value that is not a list (`restrictions.<key>: a list of {"<item key>": ...}, or null for no restriction`, the key of an item being `id_country`, `id_reference` for a carrier and `id_group`); an item that is not an identifier (`restrictions.<key>: a whole number above 0`, once for the list); an empty list (`restrictions.<key>: at least one, or null for no restriction`); a country or a group that does not exist (`restrictions.country: no country of this shop has the identifier <n> - find it with somcp_list_countries`, the same with `group` and `somcp_list_customer_groups`); a carrier reference with no carrier not deleted (`restrictions.carrier: no carrier of this shop has the reference <n> - find it with somcp_list_carriers`); for `cart_rule`, a value that is not a list (`restrictions.cart_rule: a list of {"id_cart_rule": ...}, or null for no restriction`), an item that is not an identifier and a rule this shop does not have (`restrictions.cart_rule: no cart rule of this shop has the identifier <n> - find it with somcp_list_cart_rules`); for `product`, the refusals of `somcp_update_cart_rule` with the same wording; and `shop` not `null` (`restrictions.<key>: not set by this tool - the merchant sets it on the Conditions tab of the rule in the back office`), another key (`restrictions.<key>: not a field of this tool`), `restrictions` that is not an object (`restrictions: not set by this tool - ...`).

**Notes**
- Written through `CartRule::add()`, so `actionObjectCartRuleAddAfter` fires, as in the back office. The checks live in one protected function, `SomcpToolCreateCartRule::planRule()`, that `somcp_update_cart_rule` extends.
- Then the rows `AdminCartRulesController::afterAdd()` writes and `CartRule::add()` does not, with `Db::insert` as the controller (these tables have no ObjectModel), only once `add()` succeeded: for **Specific product**, that product in **Product selection** (one group, quantity 1, type `products`) and `product_restriction = 1`, so the rule only applies to carts that hold it; with `restrictions.product` given, its groups through `SomcpToolCreateCartRule::writeProductSelection()`, the code of `somcp_update_cart_rule`, and the group of a **Specific product** added beside them only when no `products` rule holds that product, as `postProcess()` adds it, with a `warning` (`Product <id> was added to Product selection, as the back office adds it: the discount applies to that product.`); on a multistore, a `cart_rule_shop` row for the token's shop and `shop_restriction = 1`. If one of them fails, the rule is deleted again and nothing is kept.
- Last, **Compatibility with other cart rules**, by `writeCompatibility()`, the code of `somcp_update_cart_rule`, for every rule created: with the box unchecked, the new rule on the list of every rule with `cart_rule_restriction = 1`, as the form does on every save; with it checked (`cart_rule_restriction` set before `add()`), one pair of `cart_rule_combination` per rule listed, then every other rule whose box is unchecked gets it checked and keeps every other active rule, so it stops combining with the new one only. Those boxes are not undone if the creation fails afterwards, so nothing is written after them.
- The countries, carriers and groups given are checked and written by `planRestrictions()` and `writeRestrictions()`, the code of `somcp_update_cart_rule`: `country_restriction`, `carrier_restriction` and `group_restriction` set on the rule before `CartRule::add()`, then one row per item (`cart_rule_carrier.id_carrier` holding the `id_reference`) right after it, before the rows of `afterAdd()`. If they fail, the rule is deleted, and `CartRule::delete()` takes these rows with it. **Store selection** stays in the back office.
- Not to be retried blindly: if the answer is lost, look for the rule with `somcp_list_cart_rules` (`code`, or `name`) before calling again.

**Differences between 8.0.1 and 9.0.0**
- 9.0.0 refuses a gift product with required customization fields; 8.0.1 saves it. The tool refuses it on both versions, with the 9.0.0 wording.
- The same form fields and table on both, apart from the type of `description` (`text` / `mediumtext`).

#### `somcp_update_cart_rule`
**State**: written · [issue #108](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/108)
**Nature**: `acts(EDIT, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - changes one cart rule with everything `somcp_create_cart_rule` can write: the fields of the **Information**, **Conditions** and **Actions** tabs of **Catalog > Discounts > Cart Rules > Edit**, and of the **Restrictions** of the **Conditions** tab **Country selection**, **Carrier selection**, **Customer group selection** (#406), **Product selection** (#433) and **Compatibility with other cart rules** (#417). Only what is given changes.

**Do not use for** - switching a rule on or off (→ `somcp_set_cart_rule_active`), creating one (→ `somcp_create_cart_rule`), its shops (back office).

**Parameters** - the names and shapes of `somcp_create_cart_rule`, so a rule read with `somcp_get_cart_rule` can be given back as is.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_cart_rule` | **ID** of the list | integer | yes | from `somcp_list_cart_rules` |
| `languages` | | array | no | ISO codes of the languages `name` is given in (#149), all active ones by default |
| `name` | **Name** | object | no | per ISO code; changes in the languages given and keeps the others; never empty once given |
| every other field of `somcp_create_cart_rule` but `active` | as there | as there | no | same names, same shapes, same checks; left out: unchanged |
| `restrictions` | **Conditions** > **Restrictions** | object | no | the shape `somcp_get_cart_rule` answers; `country` (**Country selection**, a list of `{"id_country": 8}` from `somcp_list_countries`), `carrier` (**Carrier selection**, a list of `{"id_reference": 2}` from `somcp_list_carriers`) and `group` (**Customer group selection**, a list of `{"id_group": 3}` from `somcp_list_customer_groups`) are written, `null` for no restriction, a `name` given back ignored; `cart_rule` (**Compatibility with other cart rules**, the **Combinable cart rules**) is a list of `{"id_cart_rule": 2}` from `somcp_list_cart_rules`, `[]` for the box checked with no rule (combines with none), `null` for the box unchecked (combines with every rule); `product` (**Product selection**) is a list of groups `{"quantity": 2, "rules": [{"type": "categories", "values": [{"id_category": 3}]}]}`, `null` for the box unchecked: `quantity` **Number of products required in the cart to enjoy the discount** (a whole number, 1 at least, 1 when left out), `type` one of `products`, `attributes`, `categories`, `manufacturers`, `suppliers` (**Products**, **Attributes**, **Categories**, **Brands**, **Suppliers** of **Add a rule concerning**), `values` the items in the keys `somcp_get_cart_rule` gives (`id_product` from `somcp_search_products`, `id_attribute` from `somcp_list_attributes`, `id_category` from `somcp_list_categories`, `id_manufacturer` from `somcp_list_manufacturers`, `id_supplier` from `somcp_list_suppliers`), a bare identifier taken too, each item once in the order of the identifiers; the groups given replace the stored ones, and `[]` given back from a rule whose box is checked with no group changes nothing; a list equal to the read, in any order, changes nothing; a key left out keeps its value |

`active`, `customer`, `summary`, `times_used`, `date_add` and `date_upd` are accepted when given back unchanged from `somcp_get_cart_rule` (the names it adds in its reading language apart), and refused when they differ: `active` points to `somcp_set_cart_rule_active`, the others are worked out by the shop. In `restrictions`, `shop` is accepted the same way, and refused when they differ: `restrictions.<key>: not set by this tool - the merchant sets it on the Conditions tab of the rule in the back office`.

**Returns** - `changed: true`, `id_cart_rule`, `changes` (one entry per field changed: `field`, `old`, `new` as `somcp_get_cart_rule` reads them, `name (<iso>)` for a translated name, `restrictions.<key>` with the identifiers only, `null` for no restriction: `{"field": "restrictions.country", "old": [8], "new": [8, 21]}`; `restrictions.product` with the groups as read, the names left out), and `cart_rule` as `somcp_get_cart_rule` returns it, read back with `SomcpToolGetCartRule::byId()`. When a discount leaves the product it applied to and **Product selection** still holds it, `product_selection` says so: `... until it is removed from restrictions.product.` When the tool adds the group of a **Specific product**, as the back office does, `warning` says it: `Product <id> was added to Product selection, as the back office adds it: the discount applies to that product.` When **Compatibility with other cart rules** checked, the back office checks the box of every other rule that combined with all and stops it combining with this one: `warning` names them (`<n> other cart rule(s) can no longer be combined with this one, as in the back office: <id>, <id>.`). A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Errors** - in the shape of #169 (`Nothing was changed. Correct these and call again:` then one line per problem): those of `somcp_create_cart_rule`, checked by its `planRule()` on the rule as it will be once merged with what is kept (for example `date_from` given alone after the kept `date_to`: **The voucher cannot end before it begins.**; `reduction` set to `null` on a rule with no free shipping and no gift: **An action is required for this cart rule.**); `code` already used by another rule (its own code is accepted, as the form compares with `id_cart_rule`); an unknown `id_cart_rule` (`id_cart_rule: no cart rule of this shop has the identifier <n> - find it with somcp_list_cart_rules`), given with the fields of the call the tool does not take, in one answer. For the restrictions: a value that is not a list (`restrictions.<key>: a list of {"<item key>": ...}, or null for no restriction`, the key of an item being `id_country`, `id_reference` for a carrier and `id_group`); an item that is not an identifier (`restrictions.<key>: a whole number above 0`, once for the list); an empty list (`restrictions.<key>: at least one, or null for no restriction`, since a checked box with nothing selected matches no cart); a country or a group that does not exist (`restrictions.country: no country of this shop has the identifier <n> - find it with somcp_list_countries`, the same with `group` and `somcp_list_customer_groups`); a carrier reference with no carrier not deleted (`restrictions.carrier: no carrier of this shop has the reference <n> - find it with somcp_list_carriers`). For `cart_rule`: not a list and not `null` (`restrictions.cart_rule: a list of {"id_cart_rule": ...}, or null for no restriction`); an item that is not an identifier (`restrictions.cart_rule: a whole number above 0`); a rule this shop does not have (`restrictions.cart_rule: no cart rule of this shop has the identifier <n> - find it with somcp_list_cart_rules`); the rule itself (`restrictions.cart_rule: a cart rule cannot list itself`). For `product`, `<g>` and `<r>` the places in the lists starting at 0: not a list and not `null` (`restrictions.product: a list of {"quantity": ..., "rules": [...]}, or null for no restriction`); `[]` other than given back as read (`restrictions.product: at least one group, or null for no restriction`); a group that is not an object of `quantity` and `rules` (`restrictions.product[<g>]: an object of quantity and rules`); a `quantity` below 1 (`restrictions.product[<g>].quantity: a whole number, 1 at least`); no rule (`restrictions.product[<g>].rules: at least one rule`); another `type` (`restrictions.product[<g>].rules[<r>].type: one of products, attributes, categories, manufacturers, suppliers`); no value (`restrictions.product[<g>].rules[<r>].values: at least one, a list of {"<item key>": ...}`); an item that is not an identifier (`SomcpTool::notAnIdentifier()`, once for the list); an item the form would not offer (`SomcpTool::notFound()`: a product of the shop (`product_shop`), an attribute value, a category of the shop other than the root (`category_shop`), a brand, a supplier, each with its list tool).

**Notes**
- Written with `CartRule::update()` only. The back office's `afterUpdate()` deletes every restriction row of the rule (`cart_rule_country`, `_carrier`, `_group`, `_product_rule_group`, `_shop`, `cart_rule_combination`) and writes back what the form posted; going that way would wipe what the call does not give. The countries, carriers and groups given are written instead as `afterUpdate()` and `afterAdd()` write them, only for the keys that change: the rows of the rule deleted, then one row per item (`cart_rule_carrier.id_carrier` holding the `id_reference`), and `country_restriction`, `carrier_restriction`, `group_restriction` set on the rule, in one transaction with `CartRule::update()`. The checks and the writing are `SomcpToolCreateCartRule::planRestrictions()` and `writeRestrictions()`, for `somcp_create_cart_rule` to share; the table names are fixed in the code.
- **Product selection** is written as `afterUpdate()` clears it and `afterAdd()` writes it back, in the same transaction and only when it changes (`SomcpToolCreateCartRule::planProductSelection()` and `writeProductSelection()`): the groups of the rule deleted, then the rules and values left without them; then per group one row of `cart_rule_product_rule_group`, per rule one of `cart_rule_product_rule`, per item one of `cart_rule_product_rule_value`, with `Db::insert()` as the controller (these tables have no ObjectModel), and `product_restriction` set on the rule.
- A **Product selection** given back as read - same groups and rules in the same order, the items of a rule in any order - is compared with the stored one before any check (`SomcpToolCreateCartRule::productSelectionShape()`) and never checked again, so a group of quantity 0 or an item since removed from the shop, as the form can save them, does not stop the rule from being updated from its own read.
- **Compatibility with other cart rules** is written as the back office writes it, in the same transaction and only when it changes (`SomcpToolCreateCartRule::writeCompatibility()`, shared with `somcp_create_cart_rule`): every pair of `cart_rule_combination` naming the rule deleted; with the box checked, one pair per rule listed; with it unchecked, the rule added to the list of every rule whose own box is checked; with it checked, every other rule whose box is unchecked and that is not listed gets its box checked and keeps every other active rule, so it only stops combining with this one.
- Only the columns of the fields given are compared and written. The currency and tax switch of an amount that is zero, or of a percent, are not compared, so giving back what was read changes nothing.
- A `reduction` given without `id_product` on a **Specific product** discount keeps that product. Switching **Apply a discount to** to **Specific product** adds that product to **Product selection** (one group, quantity 1, type `products`) and sets `product_restriction`, with the rows `somcp_create_cart_rule` writes (`SomcpToolCreateCartRule::productSelection()`), unless a `products` rule already holds it, as `AdminCartRulesController::postProcess()` checks. With `restrictions.product` given, the same check runs on the groups given, also after `null`, while the rule applies to a **Specific product**: the group is added once, in the same write. Switching away keeps the product restriction, as the form keeps it.
- **Total available** (`quantity`) is what is left: every order using the rule has lowered it. A rule already used keeps its history: orders hold their own copy (`order_cart_rule`).
- Not to be retried blindly: if the answer is lost, read the rule with `somcp_get_cart_rule` and compare before calling again.

**Differences between 8.0.1 and 9.0.0**
- The 9.0.0 gift check of `somcp_create_cart_rule`, applied on both versions.

#### `somcp_set_cart_rule_active`
**State**: written · [issue #109](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/109)
**Nature**: `acts(EDIT, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - switches one cart rule on or off: the **Status** switch of the list **Catalog > Discounts > Cart Rules**, and of **Information** > **Status** in its form. Nothing else changes.

**Do not use for** - changing anything else of the rule (→ `somcp_update_cart_rule`), deleting it (back office).

**Parameters**

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `id_cart_rule` | **ID** | integer | yes | from `somcp_list_cart_rules` |
| `active` | **Status** | boolean | yes | `true` or `false`, nothing else (a string or a number is refused, never read as true) |

**Returns** - `changed: true`, `id_cart_rule`, `active`, and `warnings` when the rule is switched on but cannot apply yet or any more: the end of **Valid** passed, its start still to come, **Total available** at 0, or no **Code** (it then applies on its own to every cart that meets its conditions - the **Caution!** of the form). A rule already in that state answers `changed: false` with a `reason`, and nothing is written.

**Errors** - in the shape of #169 (`Nothing was changed. Correct these and call again:` then one line per problem): unknown arguments; `active: true or false`; an `id_cart_rule` that is not a whole number above 0; an unknown `id_cart_rule` (`id_cart_rule: no cart rule of this shop has the identifier <n> - find it with somcp_list_cart_rules`), given with the fields of the call the tool does not take, in one answer.

**Notes**
- The rules this shop can use are those of `somcp_list_cart_rules` (`shop_restriction = 0`, or a `cart_rule_shop` row for it), tested with its `SomcpToolListCartRules::inShop()`.
- Written as the list's switch writes it (`ObjectModel::toggleStatus()`): `setFieldsToUpdate(['active' => true])`, then `update()`, so `actionObjectCartRuleUpdateAfter` fires and the rule's other fields as loaded are never written back over a change made since. The form is not followed: it saves every field and rewrites the restrictions (`afterUpdate()`).
- Reversible, and the description says so: switching off is how a promotion is stopped early; orders that already used the rule keep their discount.
- A rule switched on with no code reaches every customer at once: the description tells the assistant to say so to the merchant before calling.
- Not to be retried blindly: if the answer is lost, read the rule with `somcp_get_cart_rule` before calling again.

**Differences between 8.0.1 and 9.0.0**
- None: the same list, the same switch and the same column.

#### `somcp_list_catalog_price_rules`
**State**: written · [issue #110](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/110)
**Nature**: `acts(VIEW, 'AdminSpecificPriceRule')` · **Scope**: `mcp.sales.read`

**Does** - lists the catalogue price rules of this shop - the discounts applied to products before the cart - as **Catalog > Discounts > Catalog Price Rules** shows them (`id_specific_price_rule` ascending), with the conditions that pick each rule's products. Read only.

**Do not use for** - cart rules and vouchers (→ `somcp_list_cart_rules`); one product's own specific prices (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | no | part of the **Name**, without case |
| `currency` | string | no | ISO code, the **Currency** filter; keeps that currency and **All currencies** |
| `country` | string | no | ISO code, the **Country** filter; keeps that country and **All countries** |
| `id_group` | integer | no | the **Group** filter, from `somcp_list_customer_groups`; keeps that group and **All groups** |
| `reduction_type` | string | no | `amount` or `percentage`, the **Reduction type** filter |
| `current` | boolean | no | only the rules whose **Beginning**/**End** period holds the shop's current time (an empty bound is open on that side) |
| `language` | string | no | ISO code of one of the shop's active languages for the names of currencies, countries, groups and conditions (#149); shop default when left out; unknown code refused |
| `limit` / `offset` | integer | no | as `somcp_list_best_sellers`: 20 by default, 100 at most |

**Returns** - `catalog_price_rules`, in the list's order (`id_specific_price_rule` ascending), each with:

| Field | Notes |
|---|---|
| `id_specific_price_rule` | **ID** |
| `name` | **Name** (not translated: the rule has no language table) |
| `id_shop` | **Shop** / **Store** on 9.0.0 |
| `currency` | **Currency**, ISO code, `null` for **All currencies** |
| `country` | **Country**, ISO code, `null` for **All countries** |
| `id_group` | **Group**, 0 for **All groups**; plus `group_name`, in `language` |
| `from_quantity` | **From quantity** |
| `price` | **Price (tax excl.)**, `-1` for **Leave initial price**, as `somcp_get_product` |
| `reduction_type` | **Reduction type**: `amount` or `percentage` |
| `reduction` | **Reduction**, in the shape of `somcp_get_product`: an amount, or for a percentage the fraction (`0.1` for 10 %) |
| `reduction_tax` | **Reduction with or without taxes**: `true` for **Tax included** |
| `amounts_currency` | the currency of `price` and of an amount: `currency`, or the shop default when `null`, as `somcp_get_product` |
| `from` / `to` | **Beginning**/**End**, ISO 8601 with the shop's offset as `somcp_get_product`, `null` for no date |
| `condition_groups` | a list of groups, each a list of conditions `{"type":..., "<id>":..., "name":...}`: `category`/`id_category`, `manufacturer`/`id_manufacturer`, `supplier`/`id_supplier`, `attribute`/`id_attribute`, `feature`/`id_feature_value`; `name` in `language`. `[]` when the rule applies to every product |
| `current` | the period holds the shop's current time |
| `returned`, `total_count`, `offset`, `language` | as `somcp_list_abandoned_carts` |

**Errors** - in the shape of #169, all in one answer: unknown arguments; an unknown `currency` or `country`; an `id_group` not a whole number above 0, or that `somcp_list_customer_groups` does not list for the shop, the identifier given named with that tool to find it; a `reduction_type` other than `amount`/`percentage`; `limit` outside 1 to 100; a negative `offset`; an unknown language (#149). An empty list is `catalog_price_rules: []`, `total_count: 0`.

**Notes**
- The back office lists every shop's rules (`CONTEXT_ALL`); the token's shop keeps `id_shop` 0 and its own.
- The conditions of one group must all hold (AND); a product is touched when one group holds (OR); no group means every product.
- Building one rule (with `condition_groups` and the percentage-to-fraction conversion) is one method (`SomcpToolListCatalogPriceRules::buildRule()`) that `somcp_create_catalog_price_rule` (#111) calls, so a rule reads the same whichever tool reads it.
- No difference between 8.0.1 and 9.0.0 in what the tool reads: the same legacy controller, list, class and tables; the multistore column is **Shop** on 8.0.1 and **Store** on 9.0.0.

#### `somcp_create_catalog_price_rule`
**State**: written · [issue #111](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/111)
**Nature**: `acts(ADD, 'AdminSpecificPriceRule')` · **Scope**: `mcp.sales.write`

**Does** - creates one catalogue price rule, as **Catalog > Discounts > Catalog Price Rules > Add new catalog price rule** does, with its conditions, and applies it at once to the products it picks.

**Do not use for** - a voucher or a cart discount (→ `somcp_create_cart_rule`); one product's own specific price (→ `somcp_update_product`).

**Parameters** - the names `somcp_list_catalog_price_rules` returns, so a rule read there can be given back.

| Name | On screen | Type | Required | Role |
|---|---|---|---|---|
| `name` | **Name** | string | yes | 255 characters at most, `isCleanHtml` |
| `currency` | **Currency** | string | no | ISO code of a currency of the shop; left out: **All currencies** |
| `country` | **Country** | string | no | ISO code; left out: **All countries** |
| `id_group` | **Group** | integer | no | from `somcp_list_customer_groups`, 0 (the default) for **All groups** |
| `from_quantity` | **From quantity** | integer | no | 1 by default, 1 or more |
| `price` | **Price (tax excl.)** / **Leave initial price** | number | no | a fixed price tax excluded, or -1 (the default, **Leave initial price**) |
| `reduction_type` | **Reduction type** | string | no | `amount` (the default) or `percentage` |
| `reduction` | **Reduction** | number | yes | an amount, or for a percentage the fraction as in `somcp_create_product` (`0.1` for 10 %), stored ×100 the way the form stores it |
| `reduction_tax` | **Reduction with or without taxes** | boolean | no | `true` (the default, **Tax included**) |
| `from` / `to` | **From** / **To** | string | no | ISO 8601 as `somcp_list_catalog_price_rules` gives it, converted to the shop's time zone (the shop's time without an offset); left out: no start, no end |
| `condition_groups` | **Conditions** | array of arrays | no | each group a list of `{"type": "category", "id_category": 4}`, `manufacturer`/`id_manufacturer`, `supplier`/`id_supplier`, `attribute`/`id_attribute`, `feature`/`id_feature_value`, the shape `somcp_list_catalog_price_rules` returns; `name` accepted and ignored. Left out or `[]`: every product |

`id_shop` is the token's shop; no **Shop** argument. The fields the list returns but the shop works out (`id_specific_price_rule`, `id_shop`, `group_name`, `amounts_currency`, `current`) are refused by name.

**Returns** - `created: true`, `catalog_price_rule` (one entry of `somcp_list_catalog_price_rules`, read back from the shop, names in the shop default language), `all_products` and `products_affected`: the number of distinct products `getAffectedProducts()` picked, or for a rule without condition (`all_products: true`, one specific price for product 0) the number of products of the shop.

**Errors** - in the shape of #169, all in one answer, nothing written: unknown arguments; `name` missing, empty, too long or refused by `isCleanHtml`; `reduction` missing; the checks of one rule of `specific_prices` in `somcp_create_product`, with the same texts (unknown currency, country or group, `from_quantity` below 1, a negative `price` other than -1, a negative `reduction`, above 1 for a percentage, neither a price nor a reduction, bad dates or `to` before `from`); a field the shop works out (`<field>: worked out by the shop, so it is not written here`); an unknown condition `type`, an empty group; a condition identifier that is not a whole number above 0 (`<path>: a whole number above 0`) or that the shop does not have, named with the word of the **Conditions** of the form (category, brand, supplier, attribute, feature value): `<path>: no <word> of this shop has the identifier <n> - find it with <tool>`.

**Notes**
- The checks of the price and the reduction are `SomcpToolCreateProduct::rule()`, called rather than copied: the rule writes specific prices.
- How conditions combine, said in the description: the conditions of one group must all hold (AND), one group is enough (OR); an attribute condition touches combinations, not the whole product.
- Writes: `SpecificPriceRule::add()`, then `addConditions()` once per group, then `apply()`, in the order of `processSave()`. A group that cannot be written deletes the rule again. The core reapplies every rule when a product is saved, so the tool has nothing else to do.
- No update or delete tool yet: a rule is changed or removed in the back office.
- Cart rules keep their own shape for a percentage (`{"type": "percent", "value": 10}`): this tool follows the product specific prices, the notion it writes.
- Not to be retried blindly: if the answer is lost, look for the rule with `somcp_list_catalog_price_rules`.

**Differences between 8.0.1 and 9.0.0**
- None in what the tool writes: the same class, tables and save. `name` is checked for 255 characters by the tool on both, since only 9.0.0 declares the `size`. The multistore field is **Shop** on 8.0.1 and **Store** on 9.0.0.

## Prompts

A prompt is the guide an assistant reads before using the tools of one part of the
shop. There is one per section, so a merchant starts from the menu of their
assistant with the right instructions loaded. How a prompt is built - `prompt.md`
plus `prompt.json`, filtering, arguments - is in the shared conventions above and in
[EXTENDING.md](EXTENDING.md).

Every tool the module ships belongs to at least one prompt, and CI checks it: a
pull request that adds a tool adds it to the prompt of its section, in both
`prompt.json` and the guide.

Every prompt names its **icon** in `prompt.json`, one of the names of
`PROMPT_ICONS` in `views/js/assistant.js`, and CI checks that too. The built-in
chat draws it beside the prompt in its suggestions and in its menu. A prompt that
needs a picture the set does not have adds it to `PROMPT_ICONS` in the same pull
request: one line, stroke paths on a 24 px grid.

| Prompt | Section | State |
|---|---|---|
| `somcp-connection-check` | Diagnostics | written |
| `somcp-order-lookup` | Orders | written |
| `somcp-order-status-change` | Orders | written |
| `somcp-catalogue-manage` | Catalogue | written |
| `somcp-stock-check` | Stock | written |
| `somcp-customer-lookup` | Customers | written |
| `somcp-sales-report` | Statistics | written |
| `somcp-content-pages` | Content | written |
| `somcp-modules-review` | Modules | written |
| `somcp-storefront-menu` | Appearance | written |
| `somcp-cache-maintenance` | System | written |
| `somcp-seo-audit` | SEO | written |
| `somcp-aeo-audit` | AEO | written · [issue #80](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/80) |
| `somcp-sales-boost` | Sales | written · [issue #81](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/81) |

#### `somcp-catalogue-manage`
**State**: written
**Section**: Catalogue · **Scope**: `mcp.catalog.read` · **Permission**: `view` on `AdminProducts` · **Icon**: `tag`

**Title** - Manage products

**Description** - Find, read and change products and categories in the PrestaShop shop, one at a time, without guessing an identifier.

**Arguments** - `product` (name, reference or ean13 of a product).

**Tools** - `somcp_ping`, `somcp_upload_file`, `somcp_download_file`, `somcp_search_products`, `somcp_get_product`, `somcp_list_categories`, `somcp_get_category`, `somcp_get_category_kpis`, `somcp_list_manufacturers`, `somcp_get_manufacturer`, `somcp_list_manufacturer_addresses`, `somcp_get_manufacturer_address`, `somcp_list_suppliers`, `somcp_get_supplier`, `somcp_list_attributes`, `somcp_get_attribute_group`, `somcp_get_attribute_value`, `somcp_list_features`, `somcp_get_feature`, `somcp_get_feature_value`, `somcp_list_attachments`, `somcp_list_tax_rules`, `somcp_get_tax_rule`, `somcp_list_taxes`, `somcp_create_tax_rule`, `somcp_update_tax_rule`, `somcp_delete_tax_rule`, `somcp_list_customer_groups`, `somcp_list_carriers`, `somcp_get_carrier`, `somcp_update_carrier`, `somcp_delete_carrier`, `somcp_create_product`, `somcp_update_product`, `somcp_set_product_active`, `somcp_delete_product`, `somcp_create_category`, `somcp_update_category`, `somcp_set_category_active`, `somcp_delete_category`, `somcp_create_manufacturer`, `somcp_update_manufacturer`, `somcp_set_manufacturer_active`, `somcp_create_manufacturer_address`, `somcp_update_manufacturer_address`, `somcp_delete_manufacturer_address`, `somcp_create_supplier`, `somcp_update_supplier`, `somcp_set_supplier_active`, `somcp_create_attribute_group`, `somcp_update_attribute_group`, `somcp_delete_attribute_group`, `somcp_create_attribute_value`, `somcp_update_attribute_value`, `somcp_delete_attribute_value`, `somcp_create_feature`, `somcp_update_feature`, `somcp_delete_feature`, `somcp_create_feature_value`, `somcp_update_feature_value`, `somcp_delete_feature_value`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - search before reading, and read before changing; take category identifiers from `somcp_list_categories`, never guess them; say whether a price is tax included or excluded; one product per write, never retried on a lost answer; switching a product off is reversible, deleting it is not, and the merchant is told which is happening.

#### `somcp-stock-check`
**State**: written
**Section**: Stock · **Scope**: `mcp.stock.read` · **Permission**: `view` on `AdminStockManagement` · **Icon**: `box`

**Title** - Check and adjust stock

**Description** - See what runs low in the PrestaShop shop and set quantities, one product or combination at a time.

**Arguments** - `threshold` (quantity at or under which a product counts as low).

**Tools** - `somcp_ping`, `somcp_list_low_stock`, `somcp_get_stock`, `somcp_list_stock_movements`, `somcp_search_products`, `somcp_set_stock_quantity`, `somcp_list_categories`, `somcp_list_suppliers`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start from `somcp_list_low_stock` or a named product; a quantity is per combination when the product has some; a `delta` moves stock and is never retried: on a lost answer, read `somcp_get_stock` first.

#### `somcp-customer-lookup`
**State**: written
**Section**: Customers · **Scope**: `mcp.customers.read`, `mcp.customers.write` · **Permission**: `view` on `AdminCustomers` · **Icon**: `person`

**Title** - Find a customer

**Description** - Find a customer of the PrestaShop shop, read their account and orders, and update it when asked.

**Arguments** - `customer` (name, email or company).

**Tools** - `somcp_ping`, `somcp_search_customers`, `somcp_get_customer`, `somcp_search_orders`, `somcp_create_customer`, `somcp_update_customer`, `somcp_list_customer_groups`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - search, then read the one customer the merchant means; the email is more reliable than the name; personal data stays in the answer it was asked for; no password ever goes through the conversation.

#### `somcp-sales-report`
**State**: written
**Section**: Statistics · **Scope**: `mcp.stats.read` · **Permission**: `view` on `AdminStats` · **Icon**: `chart`

**Title** - Sales report

**Description** - Report the PrestaShop shop's sales over a period: revenue, orders, average basket, best sellers.

**Arguments** - `period` (period to report on, for example last month).

**Tools** - `somcp_ping`, `somcp_get_sales_summary`, `somcp_list_best_sellers`, `somcp_search_orders`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - turn the period into two dates in the shop's time zone and say which; figures count valid orders dated by invoice date, tax excluded, in the default currency, as **Stats Dashboard** and **Sales and orders** do; `somcp_search_orders` filters on the date the order was placed, so a few orders can differ at the edges of a period; compare with the previous period when it helps; amounts always with their currency and tax said.

#### `somcp-content-pages`
**State**: written
**Section**: Content · **Scope**: `mcp.content.read` · **Permission**: `view` on `AdminCmsContent` · **Icon**: `page`

**Title** - Read content pages

**Description** - List and read the CMS pages of the PrestaShop shop.

**Arguments** - `page` (title of a page).

**Tools** - `somcp_ping`, `somcp_list_cms_pages`, `somcp_get_cms_page`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - list with `somcp_list_cms_pages` (filtered by `title` when the merchant names the page), then read the page the merchant means by its `id_cms`; quote the content rather than paraphrasing it when asked what a page says; **Title** is the page's title and **Meta title** the title of the browser tab and search results, named `title` and `meta_title` in every tool; a hidden page (`active: false`) is not on the storefront; changing a page's SEO texts is `somcp_update_cms_seo`, in the prompt `somcp-seo-audit`, not here.

#### `somcp-modules-review`
**State**: written
**Section**: Modules · **Scope**: `mcp.modules.read` · **Permission**: `view` on `AdminModulesSf` · **Icon**: `puzzle`

**Title** - Review modules

**Description** - See which modules the PrestaShop shop runs, their version and state, and install or switch one when asked.

**Arguments** - `module` (name of a module).

**Tools** - `somcp_ping`, `somcp_list_modules`, `somcp_get_module`, `somcp_install_module`, `somcp_upload_module`, `somcp_upgrade_module`, `somcp_upload_file`, `somcp_set_module_active`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read before acting; name the module and what it does before installing or switching it; one module per call; a payment or carrier module switched off stops orders or deliveries, say so before doing it.

#### `somcp-storefront-menu`
**State**: written
**Section**: Appearance · **Scope**: `mcp.appearance.read` · **Permission**: `view` on `AdminThemes` · **Icon**: `menu`

**Title** - Storefront menu and layout

**Description** - Read and change the main menu of the PrestaShop storefront and the page layouts of its theme.

**Arguments** - none.

**Tools** - `somcp_ping`, `somcp_list_menu_links`, `somcp_add_menu_link`, `somcp_update_menu_link`, `somcp_get_theme`, `somcp_update_theme_setting`, `somcp_list_categories`, `somcp_list_cms_pages`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read the menu or the theme first; take category and page identifiers from their list tools; one change per call.

#### `somcp-cache-maintenance`
**State**: written
**Section**: System · **Scope**: `mcp.system.read` · **Permission**: `view` on `AdminPerformance` · **Icon**: `refresh`

**Title** - Cache maintenance

**Description** - Check how the PrestaShop shop caches and clear the cache when changes do not show.

**Arguments** - none.

**Tools** - `somcp_ping`, `somcp_get_cache_status`, `somcp_clear_cache`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read the status first; clearing the cache slows the storefront while it rebuilds, so say so and clear it once, not as a reflex.

#### `somcp-seo-audit`
**State**: written
**Section**: SEO · **Scope**: `mcp.seo.read` · **Permission**: `view` on `AdminMeta` · **Icon**: `rank`

**Title** - Audit and improve SEO

**Description** - Find what holds the shop back in search results, fix it with the merchant's approval, and check the public pages afterwards.

**Arguments** - `focus` (optional: products, categories, pages or the whole shop).

**Tools** - `somcp_ping`, `somcp_audit_product_seo`, `somcp_audit_category_seo`, `somcp_audit_cms_seo`, `somcp_audit_brand_seo`, `somcp_update_cms_seo`, `somcp_list_meta_pages`, `somcp_update_meta_page`, `somcp_get_seo_settings`, `somcp_get_robots_txt`, `somcp_generate_robots_txt`, `somcp_audit_page`, `somcp_check_links`, `somcp_audit_sitemap`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start with `somcp_get_seo_settings` and the audits, then sort what was found by its effect on sales: products that sell or should sell first; propose every new title or description to the merchant before writing it, in each language of the shop; change a URL of a live page only after saying that the old one stops working; after a change, check the public page with `somcp_audit_page`, since a value in the database is not proof of what the theme prints.

#### `somcp-aeo-audit`
**State**: written · [issue #80](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/80)
**Section**: AEO · **Scope**: `mcp.seo.read` · **Permission**: `view` on `AdminProducts` · **Icon**: `bulb`

**Title** - Get recommended by AI assistants

**Description** - Check that answer engines and AI assistants can read the shop and trust its product data, and fix what they would miss.

**Arguments** - `product` (optional: name, reference or ean13 of a product to start from).

**Tools** - `somcp_ping`, `somcp_audit_product_schema`, `somcp_audit_product_entities`, `somcp_check_ai_crawler_access`, `somcp_audit_store_policies`, `somcp_get_llms_txt`, `somcp_generate_llms_txt`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - no tool can guarantee that an assistant cites the shop, and the guide never promises it; start with `somcp_check_ai_crawler_access` and `somcp_audit_store_policies`, then the products that sell; fix the data itself (brand, GTIN, features, description) with the Catalogue tools rather than adding text for robots; never invent a fact, a review or a figure about a product: ask the merchant; check the public page with `somcp_audit_product_schema` after a change.

#### `somcp-sales-boost`
**State**: written · [issue #81](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/81)
**Section**: Sales · **Scope**: `mcp.sales.read` · **Permission**: `view` on `AdminStats` · **Icon**: `cart`

**Title** - Boost sales

**Description** - Find the products, searches and carts where sales are lost, and set up promotions with the merchant's approval.

**Arguments** - `period` (optional: the number of days to look back, 30 by default).

**Tools** - `somcp_ping`, `somcp_list_abandoned_carts`, `somcp_list_search_terms`, `somcp_list_viewed_not_sold`, `somcp_list_unsold_products`, `somcp_list_cart_rules`, `somcp_get_cart_rule`, `somcp_create_cart_rule`, `somcp_update_cart_rule`, `somcp_set_cart_rule_active`, `somcp_list_catalog_price_rules`, `somcp_create_catalog_price_rule`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start from data, never from a guess: abandoned carts, searches with no result, products viewed but not sold, products that stopped selling; propose each promotion with its cost (the discount times the expected quantity) before creating it; a cart rule is created inactive unless the merchant says otherwise; never email or contact a customer: report the carts, the merchant decides.
