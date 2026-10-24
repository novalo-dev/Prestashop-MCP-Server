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

**Language.** Read in the shop's default language, unless a `language` parameter
gives an ISO code.

**Dates.** ISO 8601 in and out. Relative periods are worked out by the
assistant, which already knows the shop's time zone.

**Amounts.** Always with their currency code, and always with tax or without
said in the field name. Never a bare `total`.

**Paging.** `limit` defaults to 20 and caps at 100, `offset` for the rest. Every
list returns `total_count`, so an assistant knows there is more instead of
concluding from one page.

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
in the menu, the description, the arguments the merchant may fill in, the
scopes and back office permission it needs, and the tools it talks about.
Adding a prompt is adding a folder.

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
is handed the address and a reference as plain text; the merchant deposits,
says so, and the assistant calls the same tool again with `upload_ref`.

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

**Does** - finds orders by reference, customer, date, status, amount or payment
method, and returns one short line each.

**Do not use for** - reading an order already identified (→ `somcp_get_order`),
or carts that were never paid for (→ Carts).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | no | exact identifier |
| `reference` | string | no | whole or partial reference |
| `customer_name` | string | no | first name, last name or both, in any order |
| `customer_email` | string | no | exact match, more reliable than the name |
| `date_from` / `date_to` | string | no | `YYYY-MM-DD`, both days included |
| `status_id` | integer | no | shop-specific, never guessed |
| `total_min` / `total_max` | number | no | amount paid, tax included |
| `payment_method` | string | no | whole or partial |
| `only_valid` | boolean | no | paid orders only |
| `sort` | enum | no | `date_desc` (default), `date_asc`, `total_desc`, `total_asc` |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per order: `id_order`, `reference`, `date`, `customer_name`,
`phone`, `total_paid`, `currency`, `payment_method`, `status`, `status_id`. Plus
`returned`, `total_count` and `offset`.

**Notes** - read through a query, not through Order objects: a page would
otherwise load twenty orders with their customers and addresses. The phone is
the delivery address one, mobile first, because that is what carriers use. The
list stays plain on purpose; the address book of a shop does not belong in an
answer about what came in yesterday.

#### `somcp_get_order`
**State**: written
**Nature**: `acts(VIEW, 'AdminOrders')` · **Scope**: `mcp.orders.read`

**Does** - returns everything about one order.

**Do not use for** - looking for orders (→ `somcp_search_orders`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two | identifier |
| `reference` | string | one of the two | reference |

**Returns** - `order`, `customer`, `addresses` (delivery and invoice),
`products`, `discounts`, `shipping`, `totals`, `payments`, `history`,
`documents` (invoices and credit slips), `messages`.

**Notes** - takes no search filter on purpose: given "Dupont's March orders" it
would have to pick one of twelve. Finding is one tool, reading is another. Read
through core objects, which one order can afford.

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
| `reference` | string | one of the two | reference |
| `status_id` | integer | yes | from `somcp_list_order_statuses`, never guessed |

**Returns** - `changed`, `order`, `previous_status`, `status`,
`status_sends_email`, `email_sent`, `warning`, `changed_by_employee_id`. When the
order already has that status: `changed: false` with a `reason`, and nothing
happens.

**Notes** - the sequence is the core's own, read from `UpdateOrderStatusHandler`
on 8.0.0 and 9.1.5 rather than reinvented: refuse a redundant status, set
`id_employee`, `changeIdOrderState()` with the payments carried over when there
is no invoice yet, then `addWithemail()`.

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
**State**: draft · [issue #19](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/19)
**Nature**: `acts(EDIT, 'AdminOrders')` · **Scope**: `mcp.orders.write`

**Does** - sets the tracking number of one order's shipment.

**Do not use for** - changing the order status to shipped (→ `somcp_set_order_status`), which is a separate decision.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_order` | integer | one of the two |  |
| `reference` | string | one of the two |  |
| `tracking_number` | string | yes | checked against the carrier's format when it has one |

**Returns** - `changed`, `previous_tracking_number`, `tracking_number`, `tracking_url`, `customer_notified`.

**Notes** - written where the back office writes it, on the order's `OrderCarrier`, with `actionAdminOrdersTrackingNumberUpdate` fired so carrier modules follow. Whether the customer is emailed follows the shop's own setting.

### Billing

The accounting records an order produces: invoices, credit slips, delivery
slips. They follow from an order without being the order.

**Not here**: the amounts of an order, which belong to the order itself
(→ Orders).

_No tool described yet._

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
each one plays in the shop, and what each one sets off: paid, shipped, delivery,
invoice, whether it emails the customer and what that email carries.

**Do not use for** - reading orders, or counting them by status (→
`somcp_search_orders`, which takes a `status_id` and returns `total_count`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per status: `id`, `name`, `roles`, `paid`, `shipped`, `delivery`,
`sends_email`, `invoice`, `counts_as_valid`, `hidden_from_customer`,
`attaches_invoice_pdf`, `attaches_delivery_slip_pdf`, `built_in`,
`email_template`, `payment_module`, `color`. Plus `count` and `language_id`.

**Notes** - in Configuration, not in Orders: an order uses a status without
containing one. Every field is a real column of `order_state`, checked against
the class after a first draft invented a `refunds` one.

`roles` is the point of the tool. PrestaShop records which status plays which
part in its own configuration - fourteen keys, the same on 8.0.0 and 9.1.5 - and
that is the only reliable way to find one: the name is translated and the
merchant can rename it. Without it, an assistant asked to mark an order shipped
guesses from a label. A list rather than one value, because
`PS_OS_OUTOFSTOCK` and `PS_OS_OUTOFSTOCK_UNPAID` land on the same status in a
default install. Empty for a status the merchant created.

No count of orders per status here, on purpose. It would hand order data to an
account holding only the right to see the statuses page, and the count already
exists in `somcp_search_orders`, where the permission is the right one.

`deleted` was returned and always false: `OrderState::getOrderStates()` filters
them out before we see them. Removed rather than left lying.

#### `somcp_list_carriers`
**State**: draft · [issue #20](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/20)
**Nature**: `acts(VIEW, 'AdminCarriers')` · **Scope**: `mcp.orders.read`

**Does** - lists the carriers of this shop with their identifiers, delays and tracking URL.

**Do not use for** - reading the carrier of one order (→ `somcp_get_order`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | true by default |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per carrier: `id_carrier`, `id_reference`, `name`, `delay`, `active`, `is_free`, `tracking_url`, `external_module`.

**Notes** - `id_carrier` changes each time a carrier is edited in PrestaShop; `id_reference` does not. Both are returned, and the description says which to keep.

#### `somcp_list_tax_rules`
**State**: draft · [issue #50](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/50)
**Nature**: `acts(VIEW, 'AdminTaxRulesGroup')` · **Scope**: `mcp.catalog.read`

**Does** - lists the tax rules a product can take, with the rate each one applies in the shop's default country.

**Do not use for** - the tax of one product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | true by default |

**Returns** - per rule: `id_tax_rules_group`, `name`, `active`, `rate` in the default country, `country` (ISO code of that country).

**Notes** - scope `mcp.catalog.read`, not a configuration one: it is read to price a product. The rate depends on the country; the one returned is the one the back office product page shows.

#### `somcp_list_customer_groups`
**State**: draft · [issue #51](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/51)
**Nature**: `acts(VIEW, 'AdminGroups')` · **Scope**: `mcp.customers.read`

**Does** - lists the customer groups (Visitor, Guest, Customer and the shop's own) with their identifiers.

**Do not use for** - the customers of a group (→ `somcp_search_customers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per group: `id_group`, `name`, `reduction` (percent), `price_display_method` (tax included or excluded), `show_prices`, `members_count`.

**Notes** - needed by a specific price aimed at one group.

### Diagnostics

Tools that describe the connection itself and touch no shop data.

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
| `sort` | enum | no | `date_add_desc` (default), `name_asc`, `price_asc`, `price_desc`, `quantity_asc`, `quantity_desc` |
| `language` | string | no | ISO code, shop default otherwise |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `reference`, `name`, `price_tax_excl`, `price_tax_incl`, `currency`, `quantity`, `active`, `id_category_default`, `ean13`. When the product was found through a combination's reference or EAN13: `matched_combinations`, a list of `id_product_attribute`, `reference`, `ean13` for the combinations that matched. Plus `returned`, `total_count` and `offset`.

**Notes**
- Read through a query (`DbQuery`), not through Product objects. Same columns as the back office product list: `product_shop` for price and status, `product_lang` for the name, `category_product` for the category, `stock_available` with `id_product_attribute = 0` for the quantity.
- Reference and EAN13 also look in `product_attribute`; MPN, UPC and ISBN only on the product. A product found through several combinations comes back once.
- Prices are the product's base price, before specific prices and combination impacts. The price with tax uses the product's tax rule in the shop's default country, as the back office list does.
- At least one filter is not required: without any, the tool lists the catalogue page by page.
- Like the back office list, the prices add the ecotax when the shop uses it, and a product still being created (`state` other than saved) is left out. A tax rule with no rate for the default country counts as no tax.
- Prices are rounded to the precision of the shop default currency, which is the currency they are stored in. With both `reference` and `ean13` given, a combination is listed in `matched_combinations` when it matches either one.

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
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - one object, grouped like the tabs of the product page. Texts are in `language`; every amount comes with `currency`. Values are the ones PrestaShop stores, so the write tools can take them back as they are.

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
| `stock` | `quantity` (total, combinations included), `location` (stock location), `out_of_stock` (deny, allow, default), `available_now` (label when in stock), `available_later` (label when out of stock and backorders allowed), `available_date` | `stock_available`, `Product` |
| `shipping` | `width`, `height`, `depth`, `weight`, `additional_delivery_times` (none, default, product), `delivery_in_stock`, `delivery_out_stock`, `additional_shipping_cost`, `currency`, `carriers` (every carrier of the shop with `id_carrier`, `name` and `available` true or false for this product) | `Product`, `Product::getCarriers()`, `Carrier::getCarriers()` |
| `pricing` | `price_tax_excl`, `id_tax_rules_group`, `tax_rules_group_name`, `tax_rate`, `price_tax_incl`, `wholesale_price` (purchase price, tax excluded), `unit_price_tax_excl`, `unit_price_tax_incl`, `unity` (the unit), `on_sale`, `currency` | `Product`, `TaxRulesGroup`, `TaxRulesGroup::getAssociatedTaxRatesByIdCountry()` |
| `specific_prices` | `rules`, each one: `id_specific_price`, `id_product_attribute`, `id_shop`, `currency` and `country` (ISO code, `null` for all), `id_group`, `id_customer` (0 for all), `from_quantity`, `price` (fixed or -1), `reduction`, `reduction_type` (amount or percentage), `reduction_tax`, `amounts_currency`, `from`, `to`; plus `priorities`, the order used to pick between them | `SpecificPrice::getByProductId()`, `SpecificPrice::getPriority()` |
| `seo` | `meta_title`, `meta_description`, `link_rewrite` (friendly URL), `url` (full product URL), `redirect_type`, `id_type_redirected` (redirection page), `meta_keywords` | `Product`, `product_lang`, `Link::getProductLink()` |
| `options` | `visibility` (both, catalog, search, none), `available_for_order`, `show_price`, `online_only` (web exclusive, not sold in store), `active` | `Product` |
| `suppliers` | `id_supplier_default`, and `suppliers`, per supplier and combination: `id_supplier`, `supplier_name`, `id_product_attribute`, `product_supplier_reference` (supplier SKU), `product_supplier_price_te` (supplier purchase price), `currency` (supplier currency) | `product_supplier`, `supplier`, `currency` |
| `images` | per image: `id_image`, `position`, `cover` (the default image), `legend`, `url` (large size) | `Image::getImages()`, `Link::getImageLink()` |
| `combinations` | per combination: `id_product_attribute`, `attributes` (each: `id_attribute_group`, `group`, `id_attribute`, `value`, for example Size: M), `default_on`, `reference`, `mpn`, `upc`, `ean13`, `isbn`, `quantity`, `location`, `available_date`, `available_now`, `available_later`, `price_impact_tax_excl`, `price_impact_tax_incl`, `unit_price_impact_tax_excl`, `unit_price_impact_tax_incl`, `weight_impact`, `wholesale_price`, `minimal_quantity`, `currency`, `images` (ids) | `product_attribute`, `product_attribute_shop`, `product_attribute_lang`, `product_attribute_combination`, `stock_available`, `product_attribute_image` |

**Notes**
- Called by identifiers only, never by a reference, an EAN13 or a name: finding the product is the job of `somcp_search_products`, which also returns the `id_product_attribute` of a matching combination.
- An `id_product_attribute` that does not belong to `id_product` in this shop is refused, never silently ignored. A product not associated with the shop, or still being created, is answered like one that does not exist.
- The values are the product's own, before specific prices and cart rules; `specific_prices` lists the rules apart, so the assistant does not mistake a promotion for the price. Only the rules of this shop or of all shops are listed.
- PrestaShop 8.0 has no `product_attribute_lang`: the `available_now` and `available_later` of a combination exist from 8.1 and are `null` before. PrestaShop 9 dropped `meta_keywords`: the field is `null` there. Checked with the table and the column, not the version number.
- `tax_rate` is the rate of the tax rule in the shop's default country, the one the back office shows, and the one `somcp_search_products` uses: 0 when the shop has taxes switched off or the rule has nothing for that country. Amounts with tax are rounded to the precision of the shop default currency; the ecotax is not added to them.
- No carrier ticked on the product means every carrier may deliver it: each one then says `available: true`.
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
| | `description_short` (summary) | assistant | 100 to 200 characters per language, refused outside |
| | `description` | assistant | free length; HTML cleaned |
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
| `shipping` | `width`, `height`, `depth`, `weight` | merchant | |
| | `additional_delivery_times` | merchant | none, shop default, or this product's |
| | `delivery_in_stock`, `delivery_out_stock` | merchant | used when `additional_delivery_times` is this product's, which they select when it is left out; refused next to another value |
| | `additional_shipping_cost` | merchant | |
| | `carriers` | merchant, from the shop | ids from `somcp_list_carriers`; none given means every carrier, as in the back office |
| `pricing` | `price_tax_excl` | merchant | required |
| | `id_tax_rules_group` | merchant, from the shop | id from `somcp_list_tax_rules`, never guessed; 0 for no tax; left out, the rule the back office preselects for a new product (the most used one), so a product is never sold without VAT by omission |
| | `wholesale_price`, `unit_price_tax_excl`, `unity`, `on_sale` | merchant | |
| `specific_prices` | `rules` as read by `somcp_get_product`, plus `priorities` | merchant | currency and country by ISO code, group from `somcp_list_customer_groups`; each written through `SpecificPrice::add()` for the shop of the token; a combination is named by its position in `combinations` (`combination`, from 0); a percentage `reduction` is the fraction PrestaShop stores, 0.2 for 20 %; a rule with neither a price nor a reduction is refused; `priorities` lists `id_shop`, `id_currency`, `id_country`, `id_group` once each (`id_customer`, always first, is left out) |
| `seo` | `meta_title`, `meta_description`, `link_rewrite`, `redirect_type`, `id_type_redirected`, `meta_keywords` | assistant | every language being filled; see **SEO** below |
| `options` | `visibility`, `available_for_order`, `show_price`, `online_only`, `active` | merchant | `active` false by default |
| `suppliers` | `id_supplier_default` | merchant, from the shop | id from `somcp_list_suppliers` |
| | per supplier and combination: `id_supplier`, `combination` (position in `combinations`, left out for the product), `product_supplier_reference`, `product_supplier_price_te`, `currency` | merchant | currency by ISO code, among the shop's currencies, the shop default otherwise; each supplier is also associated with the product itself; `id_supplier_default` left out is the first supplier given |
| `images` | `url` | merchant | see **Images** below |
| | `legend`, `cover`, `position`, `combinations` (positions in `combinations`) | merchant and assistant | legend in every language being filled; one cover at most, the first image that arrived otherwise |
| `combinations` | `attributes` | merchant, from the shop | `id_attribute` values from `somcp_list_attributes`; two combinations with the same attributes are refused |
| | `default_on`, `reference`, `mpn`, `upc`, `ean13`, `isbn`, `quantity`, `location`, `available_date`, `available_now`, `available_later`, `price_impact_tax_excl`, `unit_price_impact_tax_excl`, `weight_impact`, `wholesale_price`, `minimal_quantity` | merchant and assistant | same rules as the product's own fields; one combination is the default |

**SEO**
- Fields: `meta_title`, `meta_description`, `link_rewrite`, `redirect_type`, `id_type_redirected`, and `meta_keywords` where the column exists (PrestaShop 8; on 9 the field is refused with a clear message, not dropped silently).
- `link_rewrite` left out: generated from the name in each language with `Tools::str2url()`. Given: passed through `Tools::str2url()`, then refused if `Validate::isLinkRewrite()` fails. Never empty in any language.
- `meta_title` 55 characters at most (stricter than PrestaShop's 255, so search engines show it whole) and `meta_description` 512 at most, the limit of `Product::$definition`: longer is refused, never cut.
- `redirect_type` is what an inactive product does: the values of `RedirectType` in the running version (PrestaShop 9 adds `default`, `200-displayed`, `404-displayed`, `410-displayed`). A `301-product` or `302-product` needs an existing `id_type_redirected` product, a category redirect an existing category. Left out: the shop default.
- The answer carries `seo_warnings`, per language: no `meta_title` or no `meta_description` (the storefront then falls back on the name), `meta_description` over 160 characters (cut in search results), a `link_rewrite` or a `meta_title` already used by another product in that language. Warnings never block the creation.

**Images**
- Files do not travel through the conversation: an image is given by a public `https` URL and the shop downloads it.
- That download is a new network request from the shop, so it is guarded: `https` on the standard port only, no credentials in the address, the host must resolve to a public address (no localhost, no private, link-local, shared, documentation or multicast range, IPv4 hidden in IPv6 included, checked after resolution and then pinned for the connection), no proxy, redirects not followed, 10 MB at most counted while reading, and the content must be a JPEG, PNG, GIF or WebP that `ImageManager` accepts. A refused image is reported in the answer and does not stop the creation.
- Thumbnails generated through `ImageManager` for every image type, as the back office does, in JPEG and in each extra format switched on in the settings (PrestaShop 8.1 and later), with the fitment of each type on PrestaShop 9.

**Returns** - `created`, `id_product`, `seo_warnings`, `images` (per URL: `id_image` or the reason it was refused), and the product as `somcp_get_product` returns it.

**Notes**
- Written through the core classes (`Product::add()`, `Combination`, `StockAvailable`, `SpecificPrice`, `Image`) so hooks fire and other modules see a normal product creation.
- Everything or nothing for the product itself: if the product or a combination cannot be written, what was created is deleted and the call fails. Only images and SEO warnings are reported without failing.
- Pack contents and the file of a virtual product are not written here: a `pack` or `virtual` product is created empty and completed in the back office.
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

**Parameters** - `id_product` (required), `languages`, then the groups and fields of `somcp_create_product`, all optional. Every rule of that entry applies unchanged: who fills each field (merchant, merchant from the shop, assistant, both), the lengths (name 128, summary 100 to 200, reference 50, SEO title 55, meta description 512, counted on the visible text with spaces), the identifiers taken from the `somcp_list_*` tools, the SEO section and the image download guards. The tool extends `SomcpToolCreateProduct` and calls its checks, so the two cannot drift apart.

**What "only what is given" means**

| Kind of field | Given | Left out |
|---|---|---|
| a single value (`price_tax_excl`, `weight`, `active`...) | replaced | kept |
| a translatable text | replaced in the languages filled, the others kept | kept in every language |
| a set chosen as a whole in the back office: `categories`, `related_products`, `features`, `attachments`, `carriers`, `customization` fields | the set given replaces the old one entirely | kept |
| `combinations` | an item with `id_product_attribute` changes that combination, field by field; an item without one creates a combination | kept |
| `images` | an item with `id_image` changes its legend, cover, position or combinations; an item with `url` adds an image | kept |
| `specific_prices` | an item with `id_specific_price` changes it, field by field; an item without one adds it | kept |
| `suppliers` | per supplier and combination, the values given replace the old ones | kept |

Removing is always explicit, never inferred from a missing item: `remove_combinations`, `remove_images`, `remove_specific_prices`, `remove_suppliers` (lists of identifiers; a supplier is removed from the product and from all its combinations). A removed combination takes its stock with it, so the assistant names each one to the merchant before the call.

`languages` left out means every active language, as at creation, so a text must then be given in each one; to change one language, `languages` names it alone.

The `combination` of a specific price or a supplier, and the `combinations` of an image, are positions in the `combinations` of the call, as at creation. An item holding only an `id_product_attribute` points at an existing combination without changing it.

**Returns** - `changed: true`, `id_product`, `changes` (each field written: `field`, `old`, `new`; an item added or removed says so), `seo_warnings` for the languages whose texts changed, `images` (per item: `removed`, `changed`, the `id_image` of an image added, or the reason it was refused), and the product as `somcp_get_product` returns it. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- `id_product` that does not exist in this shop, or an `id_product_attribute`, `id_image`, `id_specific_price` that does not belong to it: refused, nothing written.
- A `reference` changed to one another product or combination uses: refused, as at creation. The product's own references do not count: a combination often has the reference of its product, the demo data included.
- `product_type` can change as in the back office, except away from `combinations` while the product still has some: they would be lost silently. Remove them first, explicitly. Away from `pack` while it has contents, or from `virtual` while it has a file, is refused too: those are removed in the back office first. Adding combinations to a standard product makes it a combinations product, as at creation.
- The attributes of an existing combination do not change: the back office does not change them either. Remove the combination and add another.
- The default combination moves with `default_on: true` on another one; `default_on: false` on the current default alone is refused. When the default one is removed, the first combination left becomes the default.
- With combinations, `stock.quantity` and `stock.location` are refused: each combination has its own, and the core writes a product location on every combination row.
- `link_rewrite` is only written in the languages given, never made again from a new name. Changed on an active product, the old URL stops working: the answer says so in `seo_warnings`, and the assistant tells the merchant before the call.
- Two specific prices that would apply to the same customers, combination, quantity and dates are refused before anything is written: the database accepts only one.
- Every check runs before the first write, so a call refused for a reason the shop can foresee changes nothing. Everything or nothing cannot hold past that point: undoing the creation means deleting the product, which an update cannot do, and a database transaction does not hold either, because hooks commit it - `ps_facetedsearch`, shipped with every shop, truncates its cache table on each product save. If a write still fails, the answer names the steps already written, and the assistant reads the product before anything else. Images come last and are reported without failing.
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

**Returns** - `deleted`, `id_product`, `name`, `reference`, and `still_in_shops`: how many other shops still carry the product (0 when it is gone for good).

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
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per category: `id_category`, `name`. Plus `count`.

**Notes**
- Kept that short on purpose, as the maintainer decided: it is the list an assistant reads before any write that takes a category, which must never guess an identifier.
- Two categories can share a name in different branches (Men > T-shirts, Women > T-shirts): the assistant reads `somcp_get_category` to tell them apart before writing.
- The root category is left out: nothing can be placed in it. Home is listed.
- Read through a query on `category`, `category_shop` and `category_lang` in the shop of the token, rather than `Category::getSimpleCategories()`, which takes the shop from the global context. Inactive categories are listed too, in tree order (`nleft`), so each one follows its parent. A language the shop does not have falls back on the shop default.

#### `somcp_get_category`
**State**: written
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office category form shows for one category.

**Do not use for** - the list of categories (→ `somcp_list_categories`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_category` | integer | yes | from `somcp_list_categories` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_category`, `name`, `active` (Displayed), `id_parent` and `parent_name`, `level`, `position`, `description`, `additional_description`, `groups` (id and name of each customer group with access), `link_rewrite`, `url`, `meta_title`, `meta_description`, `meta_keywords`, `cover_image` (URL), `menu_thumbnails` (URLs), `products_count`, `children_count`, and on PrestaShop 9 `redirect_type` with `id_type_redirected`.

**Notes**
- Columns of `category`, `category_lang`, `category_group`, checked against PrestaShop 8.0.3 and 9.2. `meta_keywords` exists on PrestaShop 8 only and is `null` on 9; `redirect_type` and `id_type_redirected` exist on 9 only and are `null` on 8; `menu_thumbnails` exist on 8 only and are `null` on 9.
- Read in the shop of the token: a category of another shop, or an identifier that does not exist, is answered like one that does not exist. `products_count` and `children_count` count the products and subcategories of this shop only.
- Read by the same method `somcp_create_category` and `somcp_update_category` answer with, and checked by the same test of the shop as `somcp_update_category`, so the three tools name every field alike and agree on what exists.
- A language the shop does not have falls back on the shop default.

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
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office |
| `additional_description` (additional information) | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office |
| `groups` (group access) | merchant, from the shop | ids from `somcp_list_customer_groups`; every group when left out, as in the back office; an empty list is refused rather than read as every group |
| `link_rewrite` | assistant | generated from the name when left out; `Tools::str2url()` then `Validate::isLinkRewrite()`; 128 characters at most |
| `meta_title` | assistant | 70 characters at most |
| `meta_description` | assistant | 150 characters at most |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}` |
| `redirect_type` | assistant | PrestaShop 9 only, refused on 8 with a clear message: what the page does while not displayed, a value of the core's `RedirectType` (`301`, `302`, `404`, `410`); the shop default when left out |
| `id_type_redirected` | merchant, from the shop | PrestaShop 9 only: the category a `301` or `302` goes to, from `somcp_list_categories`; 0 or left out for the closest parent, as the storefront does; refused next to a `404` or `410` |
| `cover_image` | merchant | a public `https` URL, downloaded with the guards of `somcp_create_product` |
| `menu_thumbnails` | merchant | PrestaShop 8 only, refused on 9, which removed them from the form: up to 3 public `https` URLs, same guards |

**Returns** - `created`, `id_category`, `seo_warnings` (no meta title or description in a language, a `link_rewrite` already used by another category), `images` (per URL: `for` - `cover_image` or `menu_thumbnails` - and `stored: true` or the reason it was `refused`), and `category`, with the fields `somcp_get_category` returns (`menu_thumbnails` is `null` on PrestaShop 9, like `meta_keywords`).

**Notes**
- Written through `Category::add()` in the shop of the token (`id_shop_list`), so `actionCategoryAdd` fires, the tree is rebuilt and the position and group access are set as in the back office. If the add fails after the row went in, the category is deleted again.
- The core's category image uploaders (`src/Adapter/Image/Uploader/Category*`) cannot be called: they take an uploaded file and move it with `move_uploaded_file()`, which refuses a file that did not arrive in a POST. The downloaded image is written where they write it instead, under the same names: the cover as `img/c/<id>.jpg` with one file per category image type (and each extra format switched on, from PrestaShop 8.1, with the fitment of each type on 9), a menu thumbnail as `img/c/<id>-<slot>_thumb.jpg`, slots 0 to 2, after which the Smarty cache is cleared as the core does.
- Every problem is reported at once, before anything is written; images are fetched once the category exists, and one refused does not undo it.
- Not replayable: the read tool to check is `somcp_list_categories`.
- The forbidden characters are PrestaShop's own rules, read from the running version: `isCatalogName` for the name (`<>;=#{}` up to 9.0, `<>{}` from 9.1), `isGenericName` for the meta fields (`<>={}` up to 9.0, `<>{}` from 9.1); the descriptions are rich text and follow `isCleanHtml`, as the back office form does.

#### `somcp_update_category`
**State**: written
**Nature**: `acts(EDIT, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - changes one category with everything `somcp_create_category` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_category`), or only switching it on or off (→ `somcp_set_category_active`).

**Parameters** - `id_category` (required), `languages`, then the fields of `somcp_create_category`, all optional. A text given replaces the languages given and keeps the others; `groups` given replaces the whole set; `cover_image` given replaces the cover; `menu_thumbnails` given replaces the existing ones, and `remove_cover_image` / `remove_menu_thumbnails` (booleans, `remove_menu_thumbnails` on PrestaShop 8 only) remove explicitly, never next to the field that already replaces them.

**Returns** - `changed`, `id_category`, `changes` (per field its `field`, `old` and `new` value; a text names its language), `seo_warnings` for the languages whose texts changed, `images` (per image: `for`, and `stored`, `removed` or the reason it was `refused`), and `category`, as `somcp_get_category` returns it. A call that would change nothing writes nothing and answers `changed: false` with a `reason`.

**Notes**
- The root category and Home - the top category of a shop, `is_root_category` - are refused: the back office changes Home through a form of its own, and refuses the root. A category cannot be moved under itself or one of its children, checked by walking up `id_parent` rather than trusting `nleft` / `nright`.
- Written through `Category::update()` in the shop of the token (`id_shop_list`), so `actionCategoryUpdate` fires, and a new parent has the level, positions and tree worked out by the core. Group access is only rewritten when `groups` is given.
- `link_rewrite` is only written in the languages given, never made again from a new name. Changed on a displayed category, the old URL stops working: said in `seo_warnings`.
- On PrestaShop 9, a target left from a `301` or `302` is cleared when `redirect_type` becomes `404` or `410`, since the core accepts a target only next to a redirection; `id_type_redirected` alone keeps the current `redirect_type`.
- Every problem is reported at once, before anything is written. Images come after the write, as at creation: a new cover is written over the current files, so a refused one leaves the old cover in place; menu thumbnails given replace the current ones, which are deleted first. A removed cover goes through `ObjectModel::deleteImage()`, as in the back office.
- Not replayable: the read tool to check is `somcp_get_category`.

#### `somcp_set_category_active`
**State**: to write · [issue #53](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/53)
**Nature**: `acts(EDIT, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - shows or hides one category on the storefront: the **Displayed** toggle of the back office category list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_category`). Deleting a category is not offered: it is done in the back office.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_category` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `active`. When the category is already in that state: `changed: false` and nothing happens.

**Notes** - hiding a category does not switch its products off: they stay reachable through search and their other categories. The description says so, so the merchant is not surprised. Root and Home are refused.

#### `somcp_list_manufacturers`
**State**: written
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the brands of this shop: identifier and name, nothing else.

**Do not use for** - everything about one brand (→ `somcp_get_manufacturer`), or the products of a brand (`somcp_search_products` has no brand filter; `somcp_get_product` names the brand of one product).

**Parameters** - none.

**Returns** - per brand: `id_manufacturer`, `name`. Plus `count`.

**Notes**
- Same shape as `somcp_list_categories`: the list an assistant reads before a write that takes a brand, which must never guess an identifier. Inactive ones are listed too, since a product can still point to them.
- No `language` parameter, unlike the first draft of this entry: a brand name is not translated in PrestaShop (`Manufacturer::$name` has no `lang`, checked on 8.0.0 and 9.0.0), so it would change nothing.
- Read through a query on `manufacturer` and `manufacturer_shop` in the shop of the token, rather than `Manufacturer::getManufacturers()`, which takes the shop from the global context and leaves out a brand with no row in the language asked for. Sorted by name, as the core does.

#### `somcp_get_manufacturer`
**State**: to write · [issue #54](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/54)
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office brand form shows for one brand.

**Do not use for** - the list (→ `somcp_list_manufacturers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | yes | from `somcp_list_manufacturers` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_manufacturer`, `name`, `short_description`, `description`, `meta_title`, `meta_description`, `meta_keywords`, `logo` (URL), `active`, `products_count`.

**Notes** - columns of `manufacturer` and `manufacturer_lang`, checked against PrestaShop 8.0.3 and 9.2; `meta_keywords` exists on PrestaShop 8 only and is `null` on 9. A brand's postal addresses are a separate back office list and are not part of the brand form.

#### `somcp_create_manufacturer`
**State**: to write · [issue #55](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/55)
**Nature**: `acts(ADD, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - creates one brand with every field of the back office brand form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing one (→ `somcp_update_manufacturer`), or switching it on or off (→ `somcp_set_manufacturer_active`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 64 characters at most; forbidden characters `<>;=#{}` (`Validate::isCatalogName`) |
| `short_description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` |
| `meta_title` | assistant | forbidden characters `<>={}` (`Validate::isGenericName`); 255 characters at most |
| `meta_description` | assistant | forbidden characters `<>={}`; 512 characters at most |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}` |
| `logo` | merchant | a public `https` URL, downloaded with the guards of `somcp_create_product` |
| `active` | merchant | true by default |

**Returns** - `created`, `id_manufacturer`, `seo_warnings` (no meta title or description in a language), `logo` (stored or the reason it was refused), and the brand as `somcp_get_manufacturer` returns it.

**Notes** - written through `Manufacturer::add()`; the logo through the core's image uploader for brands, so thumbnails are generated as in the back office. Not replayable: the read tool to check is `somcp_list_manufacturers`.

#### `somcp_update_manufacturer`
**State**: to write · [issue #56](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/56)
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one brand with everything `somcp_create_manufacturer` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_manufacturer`), or only switching it on or off (→ `somcp_set_manufacturer_active`).

**Parameters** - `id_manufacturer` (required), `languages`, then the fields of `somcp_create_manufacturer`, all optional. A text given replaces the languages given and keeps the others. `remove_logo` removes the logo explicitly.

**Returns** - `changed`, per field its old and new value, `seo_warnings`, `logo`, and the brand as `somcp_get_manufacturer` returns it.

**Notes** - an `id_manufacturer` that does not exist is refused. Replayable: the same values again change nothing and say `changed: false`.

#### `somcp_set_manufacturer_active`
**State**: to write · [issue #57](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/57)
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - switches one brand on or off: the **Enabled** toggle of the back office brand list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_manufacturer`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `active`. When it is already in that state: `changed: false` and nothing happens.

**Notes** - switching a brand off hides its page on the storefront; its products stay on sale.

#### `somcp_list_manufacturer_addresses`
**State**: to write · [issue #62](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/62)
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the postal addresses of the brands: the **Brand addresses** list of the back office.

**Do not use for** - the brands themselves (→ `somcp_list_manufacturers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_manufacturer` | integer | no | the addresses of one brand only |

**Returns** - per address: `id_address`, `id_manufacturer`, `manufacturer_name`, `lastname`, `firstname`, `postcode`, `city`, `country` (ISO code). Plus `count`.

**Notes** - rows of `address` with `id_manufacturer` set and not deleted. Brand addresses are managed under the brands page, so the permission is the one on `AdminManufacturers`, as in the back office.

#### `somcp_get_manufacturer_address`
**State**: to write · [issue #63](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/63)
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of one brand address.

**Do not use for** - the list (→ `somcp_list_manufacturer_addresses`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_address` | integer | yes | from `somcp_list_manufacturer_addresses` |

**Returns** - `id_address`, `id_manufacturer`, `manufacturer_name`, `lastname`, `firstname`, `address1`, `address2`, `postcode`, `city`, `country` (ISO code), `state` (ISO code), `dni`, `phone`, `phone_mobile`, `other`.

**Notes** - an `id_address` that belongs to a customer, a supplier or nobody is refused: this tool reads brand addresses only.

#### `somcp_create_manufacturer_address`
**State**: to write · [issue #64](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/64)
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
| `other` | merchant | forbidden characters `<>{}` (`Validate::isMessage()`) |

**Returns** - `created`, `id_address`, and the address as `somcp_get_manufacturer_address` returns it.

**Notes** - permission `edit` on `AdminManufacturers`: adding an address edits the brand, which is the right the back office checks. Written through `Address::add()` with `id_manufacturer` set. Not replayable: the read tool to check is `somcp_list_manufacturer_addresses`.

#### `somcp_update_manufacturer_address`
**State**: to write · [issue #65](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/65)
**Nature**: `acts(EDIT, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one brand address with everything `somcp_create_manufacturer_address` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_manufacturer_address`).

**Parameters** - `id_address` (required), then the fields of `somcp_create_manufacturer_address`, all optional; `id_manufacturer` moves the address to another brand.

**Returns** - `changed`, per field its old and new value, and the address as `somcp_get_manufacturer_address` returns it.

**Notes** - only brand addresses are accepted, as in `somcp_get_manufacturer_address`. Replayable: the same values again change nothing and say `changed: false`.

#### `somcp_delete_manufacturer_address`
**State**: to write · [issue #66](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/66)
**Nature**: `acts(DELETE, 'AdminManufacturers')` · **Scope**: `mcp.catalog.write`

**Does** - deletes one brand address.

**Do not use for** - anything else than a brand address: customer and supplier addresses are refused.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_address` | integer | yes | from `somcp_list_manufacturer_addresses` |

**Returns** - `deleted`, `id_address`, `manufacturer_name`, `city`.

**Notes** - irreversible: the assistant names the brand and the city to the merchant before the call. Through `Address::delete()`, so hooks fire.

#### `somcp_list_suppliers`
**State**: written
**Nature**: `acts(VIEW, 'AdminSuppliers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the suppliers of this shop: identifier and name, nothing else.

**Do not use for** - everything about one supplier (→ `somcp_get_supplier`), or the products of a supplier (`somcp_search_products` has no supplier filter; `somcp_get_product` names the suppliers of one product).

**Parameters** - none.

**Returns** - per supplier: `id_supplier`, `name`. Plus `count`.

**Notes**
- Same shape as `somcp_list_categories`: the list an assistant reads before a write that takes a supplier, which must never guess an identifier. Inactive ones are listed too, since a product can still point to them.
- No `language` parameter, unlike the first draft of this entry: a supplier name is not translated in PrestaShop (`Supplier::$name` has no `lang`, checked on 8.0.0 and 9.0.0), so it would change nothing.
- Read through a query on `supplier` and `supplier_shop` in the shop of the token, rather than `Supplier::getSuppliers()`, which takes the shop from the global context and leaves inactive suppliers out by default. Sorted by name, as the core does.

#### `somcp_get_supplier`
**State**: to write · [issue #58](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/58)
**Nature**: `acts(VIEW, 'AdminSuppliers')` · **Scope**: `mcp.catalog.read`

**Does** - returns everything the back office supplier form shows for one supplier.

**Do not use for** - the list (→ `somcp_list_suppliers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_supplier` | integer | yes | from `somcp_list_suppliers` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_supplier`, `name`, `description`, `phone`, `phone_mobile`, `address1`, `address2`, `postcode`, `city`, `country` (ISO code), `state` (ISO code, when the country has states), `dni` (national ID number), `meta_title`, `meta_description`, `meta_keywords`, `logo` (URL), `active`, `products_count`.

**Notes** - columns of `supplier` and `supplier_lang`, and of the supplier's row in `address` for the contact fields, checked against PrestaShop 8.0.3 and 9.2; `meta_keywords` exists on PrestaShop 8 only and is `null` on 9.

#### `somcp_create_supplier`
**State**: to write · [issue #59](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/59)
**Nature**: `acts(ADD, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - creates one supplier with every field of the back office supplier form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing one (→ `somcp_update_supplier`), or switching it on or off (→ `somcp_set_supplier_active`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 64 characters at most; forbidden characters `<>;=#{}` (`Validate::isCatalogName`) |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` |
| `phone`, `phone_mobile` | merchant | `Validate::isPhoneNumber()` |
| `address1` | merchant | required by PrestaShop for a supplier |
| `address2` | merchant | |
| `postcode` | merchant | checked against the country's format (`Country::checkZipCode()`) |
| `city` | merchant | required |
| `country` | merchant | ISO code, required, among the shop's countries |
| `state` | merchant | ISO code, required when the country uses states |
| `dni` | merchant | national ID number, required when the country asks for one (`Country::isNeedDni()`) |
| `meta_title` | assistant | forbidden characters `<>={}` (`Validate::isGenericName`); 255 characters at most |
| `meta_description` | assistant | forbidden characters `<>={}`; 512 characters at most |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>={}` |
| `logo` | merchant | a public `https` URL, downloaded with the guards of `somcp_create_product` |
| `active` | merchant | true by default |

**Returns** - `created`, `id_supplier`, `seo_warnings` (no meta title or description in a language), `logo` (stored or the reason it was refused), and the supplier as `somcp_get_supplier` returns it.

**Notes** - written through `Supplier::add()` and its `Address` (`id_supplier` set); the logo through the core's image uploader for suppliers, so thumbnails are generated as in the back office. Not replayable: the read tool to check is `somcp_list_suppliers`.

#### `somcp_update_supplier`
**State**: to write · [issue #60](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/60)
**Nature**: `acts(EDIT, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - changes one supplier with everything `somcp_create_supplier` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_supplier`), or only switching it on or off (→ `somcp_set_supplier_active`).

**Parameters** - `id_supplier` (required), `languages`, then the fields of `somcp_create_supplier`, all optional. A text given replaces the languages given and keeps the others. `remove_logo` removes the logo explicitly.

**Returns** - `changed`, per field its old and new value, `seo_warnings`, `logo`, and the supplier as `somcp_get_supplier` returns it.

**Notes** - an `id_supplier` that does not exist is refused. Replayable: the same values again change nothing and say `changed: false`.

#### `somcp_set_supplier_active`
**State**: to write · [issue #61](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/61)
**Nature**: `acts(EDIT, 'AdminSuppliers')` · **Scope**: `mcp.catalog.write`

**Does** - switches one supplier on or off: the **Enabled** toggle of the back office supplier list, and nothing else.

**Do not use for** - any other change (→ `somcp_update_supplier`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_supplier` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `active`. When it is already in that state: `changed: false` and nothing happens.

**Notes** - a disabled supplier stays attached to its products; only its page on the storefront goes.

#### `somcp_list_attributes`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - lists the attribute groups (Size, Colour...) and, under each, its values: identifier and name, nothing else. A cascade, like the categories.

**Do not use for** - everything about one group or one value (→ `somcp_get_attribute_group`, `somcp_get_attribute_value`), or the combinations of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | no | one group only |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `attribute_groups`, per group: `id_attribute_group`, `name`, and its `values`: `id_attribute`, `name`. Plus `count`, the number of groups. An `id_attribute_group` that is not a group of this shop is an error, not an empty list.

**Notes**
- A combination is a set of `id_attribute`, never of names: names are translated and two groups can hold the same value name.
- `name` is the name the back office lists, not the public name shown on the storefront.
- Read through queries on `attribute_group`, `attribute_group_shop`, `attribute` and `attribute_shop` in the shop of the token, rather than `AttributeGroup::getAttributesGroups()` and `AttributeGroup::getAttributes()`, which take the shop from the global context. Sorted by position, as the back office does.

#### `somcp_get_attribute_group`
**State**: to write · [issue #67](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/67)
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office attribute form for one group.

**Do not use for** - the list (→ `somcp_list_attributes`), or one value (→ `somcp_get_attribute_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | yes | from `somcp_list_attributes` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_attribute_group`, `name`, `public_name`, `group_type` (select, radio, color), `position`, `values_count`, and when `ps_facetedsearch` is installed: `url_name`, `meta_title`, `indexable`.

**Notes** - columns of `attribute_group` and `attribute_group_lang`; the faceted search fields are `null` without the module.

#### `somcp_create_attribute_group`
**State**: to write · [issue #68](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/68)
**Nature**: `acts(ADD, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - creates one attribute group with every field of the back office attribute form, in every language of the shop or the ones the merchant picks.

**Do not use for** - adding a value to a group (→ `somcp_create_attribute_value`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; `Validate::isGenericName()` |
| `public_name` | merchant | required; the name shown to customers; 64 characters at most |
| `group_type` | merchant | required; a type PrestaShop offers: `select`, `radio`, `color` |
| `url_name` (URL) | assistant | faceted search; the word used in filtered URLs, the name otherwise; `Tools::str2url()` |
| `meta_title` (title tag) | assistant | faceted search |
| `indexable` | merchant | faceted search; false when left out, as in the form |

**Faceted search fields.** `url_name`, `meta_title` and `indexable` are not PrestaShop's: they belong to the module `ps_facetedsearch`, which adds them to the back office form and stores them in its own tables. The module saves them from the form's POST data in its `actionAttributeGroupSave` / `actionAttributeSave` hooks, so a call that goes through the core class alone would silently drop them. The tool writes the same rows the module writes, in `layered_indexable_attribute_group`, `layered_indexable_attribute_group_lang_value` and `layered_indexable_attribute_lang_value`. When the module is not installed and enabled, these fields are refused with a clear message and the rest is written.

**Returns** - `created`, `id_attribute_group`, and the group as `somcp_get_attribute_group` returns it.

**Notes** - written through `AttributeGroup::add()`, so hooks fire. Not replayable: the read tool to check is `somcp_list_attributes`.

#### `somcp_update_attribute_group`
**State**: to write · [issue #69](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/69)
**Nature**: `acts(EDIT, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - changes one attribute group with everything `somcp_create_attribute_group` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_attribute_group`).

**Parameters** - `id_attribute_group` (required), `languages`, then the fields of `somcp_create_attribute_group`, all optional. A text given replaces the languages given and keeps the others.

**Returns** - `changed`, per field its old and new value, and the group as `somcp_get_attribute_group` returns it.

**Notes** - changing `group_type` away from `color` leaves the values' colours and textures in place but unused, as in the back office; the answer says so. Replayable: the same values again say `changed: false`.

#### `somcp_get_attribute_value`
**State**: to write · [issue #70](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/70)
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office value form for one attribute value.

**Do not use for** - the list (→ `somcp_list_attributes`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute` | integer | yes | from `somcp_list_attributes` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_attribute`, `id_attribute_group` and `group_name`, `name`, `position`, and for a group of type `color`: `color` (hex code) and `texture` (URL of the current texture, `null` when there is none); when `ps_facetedsearch` is installed: `url_name`, `meta_title`.

**Notes** - columns of `attribute` and `attribute_lang`; the texture is the file PrestaShop keeps in `img/co/` (`_PS_COL_IMG_DIR_`).

#### `somcp_create_attribute_value`
**State**: to write · [issue #71](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/71)
**Nature**: `acts(ADD, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - adds one value to an attribute group, with every field of the back office value form.

**Do not use for** - creating a group (→ `somcp_create_attribute_group`).

**Parameters** - same rules as `somcp_create_attribute_group`.

| Field | Who fills it | Rule |
|---|---|---|
| `id_attribute_group` | merchant, from the shop | required; from `somcp_list_attributes` |
| `name` (value) | merchant | required; 128 characters at most; `Validate::isGenericName()` |
| `url_name` (URL) | assistant | faceted search, as for the group |
| `meta_title` (title tag) | assistant | faceted search, as for the group |
| `color` | merchant | groups of type `color` only, refused otherwise; a hex code, `Validate::isColor()` |
| `texture` | merchant | groups of type `color` only; a public `https` URL, downloaded with the guards of `somcp_create_product` |

The faceted search fields follow the rule given under `somcp_create_attribute_group`.

**Returns** - `created`, `id_attribute`, `texture` (stored or the reason it was refused), and the value as `somcp_get_attribute_value` returns it.

**Notes** - written through `ProductAttribute::add()`, so hooks fire. Two values with the same name in one group are refused. Not replayable: the read tool to check is `somcp_list_attributes`.

#### `somcp_update_attribute_value`
**State**: to write · [issue #72](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/72)
**Nature**: `acts(EDIT, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.write`

**Does** - changes one attribute value with everything `somcp_create_attribute_value` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_attribute_value`).

**Parameters** - `id_attribute` (required), `languages`, then the fields of `somcp_create_attribute_value`, all optional; `id_attribute_group` moves the value to another group; `remove_texture` removes the texture explicitly.

**Returns** - `changed`, per field its old and new value, and the value as `somcp_get_attribute_value` returns it.

**Notes** - the value stays on the combinations that use it: renaming it renames it there too, which the assistant tells the merchant before the call. Replayable: the same values again say `changed: false`.

#### `somcp_list_features`
**State**: written
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - lists the features (Material, Composition...) and, under each, its predefined values: identifier and name, nothing else. A cascade, like the attributes.

**Do not use for** - everything about one feature or one value (→ `somcp_get_feature`, `somcp_get_feature_value`), or the features of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | no | one feature only |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `features`, per feature: `id_feature`, `name`, and its `values`: `id_feature_value`, `value`. Plus `count`, the number of features. An `id_feature` that is not a feature of this shop is an error, not an empty list.

**Notes**
- Predefined values only (`custom` = 0, or NULL, which the core reads the same way). A custom value typed on one product belongs to that product and is read with `somcp_get_product`.
- Read through queries on `feature`, `feature_shop`, `feature_value` and their language tables in the shop of the token, rather than `Feature::getFeatures()` and `FeatureValue::getFeatureValuesWithLang()`, which take the shop from the global context. Features are sorted by position, as the back office does; values by identifier, since `feature_value` only has a position from PrestaShop 9 on.

#### `somcp_get_feature`
**State**: to write · [issue #73](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/73)
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office feature form for one feature.

**Do not use for** - the list (→ `somcp_list_features`), or one value (→ `somcp_get_feature_value`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | yes | from `somcp_list_features` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_feature`, `name`, `position`, `values_count`, and when `ps_facetedsearch` is installed: `url_name`, `meta_title`, `indexable`.

**Notes** - columns of `feature` and `feature_lang`; the faceted search fields are `null` without the module.

#### `somcp_create_feature`
**State**: to write · [issue #74](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/74)
**Nature**: `acts(ADD, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - creates one feature with every field of the back office feature form, in every language of the shop or the ones the merchant picks.

**Do not use for** - adding a value to a feature (→ `somcp_create_feature_value`).

**Parameters** - the rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; `Validate::isGenericName()` |
| `url_name` (URL) | assistant | faceted search; the word used in filtered URLs, the name otherwise; `Tools::str2url()` |
| `meta_title` (title tag) | assistant | faceted search |
| `indexable` | merchant | faceted search; false when left out, as in the form |

**Faceted search fields.** `url_name`, `meta_title` and `indexable` belong to `ps_facetedsearch`, exactly as for attributes: the module saves them from the back office form only, so the tool writes its rows itself, in `layered_indexable_feature`, `layered_indexable_feature_lang_value` and `layered_indexable_feature_value_lang_value`, and refuses these fields with a clear message when the module is not installed and enabled.

**Returns** - `created`, `id_feature`, and the feature as `somcp_get_feature` returns it.

**Notes** - written through `Feature::add()`, so hooks fire. Not replayable: the read tool to check is `somcp_list_features`.

#### `somcp_update_feature`
**State**: to write · [issue #75](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/75)
**Nature**: `acts(EDIT, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - changes one feature with everything `somcp_create_feature` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_feature`).

**Parameters** - `id_feature` (required), `languages`, then the fields of `somcp_create_feature`, all optional. A text given replaces the languages given and keeps the others.

**Returns** - `changed`, per field its old and new value, and the feature as `somcp_get_feature` returns it.

**Notes** - renaming a feature renames it on every product that carries it, which the assistant tells the merchant before the call. Replayable: the same values again say `changed: false`.

#### `somcp_get_feature_value`
**State**: to write · [issue #76](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/76)
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - returns every field of the back office value form for one predefined feature value.

**Do not use for** - the list (→ `somcp_list_features`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature_value` | integer | yes | from `somcp_list_features` |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_feature_value`, `id_feature` and `feature_name`, `value`, and when `ps_facetedsearch` is installed: `url_name`, `meta_title`.

**Notes** - a custom value (`custom` = 1) is refused: it belongs to one product.

#### `somcp_create_feature_value`
**State**: to write · [issue #77](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/77)
**Nature**: `acts(ADD, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - adds one predefined value to a feature, with every field of the back office value form.

**Do not use for** - creating a feature (→ `somcp_create_feature`), or giving one product its own value (→ `somcp_update_product`, `features` with a custom value).

**Parameters** - same rules as `somcp_create_feature`.

| Field | Who fills it | Rule |
|---|---|---|
| `id_feature` (Feature) | merchant, from the shop | required; from `somcp_list_features` |
| `value` | merchant | required; 255 characters at most; `Validate::isGenericName()` |
| `url_name` (URL) | assistant | faceted search, as for the feature |
| `meta_title` (title tag) | assistant | faceted search, as for the feature |

The faceted search fields follow the rule given under `somcp_create_feature`.

**Returns** - `created`, `id_feature_value`, and the value as `somcp_get_feature_value` returns it.

**Notes** - written through `FeatureValue::add()` with `custom` = 0, so hooks fire. Two values with the same text in one feature are refused. Not replayable: the read tool to check is `somcp_list_features`.

#### `somcp_update_feature_value`
**State**: to write · [issue #78](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/78)
**Nature**: `acts(EDIT, 'AdminFeatures')` · **Scope**: `mcp.catalog.write`

**Does** - changes one predefined feature value with everything `somcp_create_feature_value` can write, under the same rules. Only what is given changes.

**Do not use for** - adding one (→ `somcp_create_feature_value`).

**Parameters** - `id_feature_value` (required), `languages`, then the fields of `somcp_create_feature_value`, all optional; `id_feature` moves the value to another feature.

**Returns** - `changed`, per field its old and new value, and the value as `somcp_get_feature_value` returns it.

**Notes** - the value stays on the products that use it: changing it changes it there too, which the assistant tells the merchant before the call. Custom values are refused. Replayable: the same values again say `changed: false`.

#### `somcp_list_attachments`
**State**: written
**Nature**: `acts(VIEW, 'AdminAttachments')` · **Scope**: `mcp.catalog.read`

**Does** - lists the files of the shop (Catalogue > Files: manuals, data sheets) that a product can carry, with the columns of the back office list and a download link.

**Do not use for** - the files of one product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `attachments`, per file: `id_attachment` (ID), `name` (Name), `file_name` (File), `file_size` (Size, in bytes), `products_count` (Products), `mime`, `download_url`. Plus `count`, the number of files.

**Notes**
- The file itself never travels through the conversation. `download_url` is the storefront link PrestaShop already gives customers on a product page, built with `Link::getPageLink('attachment', true, null, 'id_attachment=N')`; the `attachment` front controller sends the file. The merchant opens it in a browser to download or preview it.
- That link works without being signed in, as on the storefront: anyone holding it can download the file. The description says so, so the assistant does not paste it somewhere public by mistake.
- Columns of `attachment` and `attachment_lang`; `products_count` from `product_attachment`. `file_name` is the name the file was uploaded under; the back office File column shows the name it is stored under on disk, which means nothing to a merchant.
- Files have no shop of their own in PrestaShop: every shop sees the same list, as in the back office. `products_count` only counts products of the shop of the token (`product_shop`), so a count never reaches into another shop.
- A file with no name in the language read is still listed, with an empty `name`, rather than missing from the only list of identifiers.
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
| `language` | string | no | ISO code for the product and attribute names, shop default otherwise |

**Returns** - `id_product`, `name`, the product line - `quantity` (total), `physical_quantity`, `reserved_quantity`, `low_stock_threshold` - and per combination: `id_product_attribute`, `attributes`, `quantity`, `physical_quantity`, `reserved_quantity`, `low_stock_threshold`.

**Notes**
- Read from `stock_available`, the quantities the storefront sells against, through the same reader as `somcp_get_product` (restricted to the shop or its group by `StockAvailable::addSqlShopRestriction()`, as stock sharing decides), so the two tools always agree.
- The product line also carries `physical_quantity`, `reserved_quantity` and `low_stock_threshold`: without them, a product without combinations would have none of the three.
- Combinations are listed from `product_attribute_shop`, so one without a stock row yet comes back at zero rather than missing. `low_stock_threshold` is the shop's own (`product_shop`, `product_attribute_shop`), `null` when no alert is set.
- A product still being created, or not in this shop, is not found, as in `somcp_get_product`.

#### `somcp_list_low_stock`
**State**: written
**Nature**: `acts(VIEW, 'AdminStockManagement')` · **Scope**: `mcp.stock.read`

**Does** - lists the products and combinations at or under a quantity.

**Do not use for** - the stock of one known product (→ `somcp_get_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `threshold` | integer | no | each product's own low stock threshold when absent, 0 when it has none |
| `active` | boolean | no | active products only, true by default |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per line: `id_product`, `id_product_attribute`, `reference`, `name`, `quantity`, `threshold`. Plus `total_count`.

**Notes**
- One line per thing that can be sold: a product without combinations (`id_product_attribute` 0), or each combination of a product that has some, listed from `product_attribute_shop`. A product with combinations has no line of its own: its quantity is only their total, which can look fine while one size is gone.
- `quantity` is read from `stock_available`, restricted to the shop or its group by `StockAvailable::addSqlShopRestriction()`, as in `somcp_get_stock`. A combination without a stock row counts as zero.
- Without `threshold`, a line is compared with its own `low_stock_threshold` - the combination's, or the product's for a product without combinations - and with 0 when none is set. `threshold` on each line is the level used.
- `reference` is the combination's own on a combination line; `name` is the product's, in the shop default language. Attributes and the physical and reserved quantities are read with `somcp_get_stock`.
- Lowest quantity first. A product still being created, or not in this shop, is left out.

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

**Returns** - `changed`, `id_product`, `id_product_attribute`, `previous_quantity`, `quantity`.

**Notes**
- The Stock page's own sequence (`StockRepository::updateStock()`): the change goes through `StockAvailable::updateQuantity()` as a movement with the employee edition reason, then the physical and reserved quantities of the product are brought in line. A `quantity` becomes the movement that reaches it. `setQuantity()` is not used: it skips packs, the low stock alert and the movement reason. The employee of the token is put on the context for the write, so the movement carries their name.
- A product with combinations is refused without `id_product_attribute`: its line is the total of its combinations, which the core rewrites. A combination must belong to the product in this shop. A pack whose stock is worked out from its products is refused; one that counts both moves its products too, as in the back office.
- The quantity already in place, or a `delta` of 0, writes nothing and answers `changed: false`. `quantity` and `delta` are whole numbers under a billion either way. `quantity` in the answer is read back after the write.
- Read in the shop of the token, restricted as stock sharing decides, as in `somcp_get_stock`. A product still being created, or not in this shop, is not found.
- A delta is not replayable: a lost answer retried would move the stock twice, which the description says, naming `somcp_get_stock` to check.

### Customers

Customer accounts and what belongs to them: groups, addresses, a summary of their orders.

**Not here**: the orders themselves (→ Orders).

#### `somcp_search_customers`
**State**: draft · [issue #15](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/15)
**Nature**: `acts(VIEW, 'AdminCustomers')` · **Scope**: `mcp.customers.read`

**Does** - finds customers by name, email, company or registration date, and returns one short line each.

**Do not use for** - reading a customer already identified (→ `somcp_get_customer`), or their orders (→ `somcp_search_orders` with `customer_email`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `query` | string | no | name, email or company, whole or partial |
| `date_from` / `date_to` | string | no | registration date |
| `active` | boolean | no |  |
| `newsletter` | boolean | no |  |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per customer: `id_customer`, `name`, `email`, `company`, `date_add`, `active`, `orders_count`. Plus `total_count`.

**Notes** - the list stays plain: no address, no phone. Those come with one named customer.

#### `somcp_get_customer`
**State**: draft · [issue #16](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/16)
**Nature**: `acts(VIEW, 'AdminCustomers')` · **Scope**: `mcp.customers.read`

**Does** - returns everything about one customer: account, groups, addresses, and a summary of their orders.

**Do not use for** - looking for customers (→ `somcp_search_customers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_customer` | integer | one of the two |  |
| `email` | string | one of the two | exact |

**Returns** - `customer` (name, email, birthday, company, groups, newsletter, optin, active, dates), `addresses`, `orders` (count, total spent, last order), `messages_count`.

**Notes** - never the password hash, the secure key or the reset token.

#### `somcp_create_customer`
**State**: draft · [issue #17](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/17)
**Nature**: `acts(ADD, 'AdminCustomers')` · **Scope**: `mcp.customers.write`

**Does** - creates one customer account.

**Do not use for** - creating a guest checkout, or an address alone.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `firstname` | string | yes |  |
| `lastname` | string | yes |  |
| `email` | string | yes | refused if it already exists |
| `id_default_group` | integer | no | Customer by default |
| `newsletter` | boolean | no | false by default |
| `send_welcome_email` | boolean | no | false by default |

**Returns** - `created`, `id_customer`.

**Notes** - no password goes through the assistant: the account is created with a random one, and the customer sets theirs through the usual reset link. Not replayable: the read tool to check is `somcp_search_customers` with the email.

#### `somcp_update_customer`
**State**: draft · [issue #18](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/18)
**Nature**: `acts(EDIT, 'AdminCustomers')` · **Scope**: `mcp.customers.write`

**Does** - changes one customer's account: name, email, company, birthday, groups, newsletter, active.

**Do not use for** - addresses, which are not covered yet.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_customer` | integer | yes |  |
| `firstname` / `lastname` | string | no |  |
| `email` | string | no | refused if another account uses it |
| `company` | string | no |  |
| `birthday` | string | no | `YYYY-MM-DD` |
| `newsletter` | boolean | no |  |
| `active` | boolean | no |  |

**Returns** - `changed`, the fields written with their old and new values.

**Notes** - never the password.

### Statistics

Figures over a period, computed from valid orders.

**Not here**: the orders behind them (→ Orders).

#### `somcp_get_sales_summary`
**State**: draft · [issue #21](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/21)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.stats.read`

**Does** - returns sales over a period: revenue, number of orders, average basket, new customers.

**Do not use for** - the list of orders behind the figures (→ `somcp_search_orders`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | yes | `YYYY-MM-DD`, both days included |
| `compare_previous` | boolean | no | adds the same figures for the previous period of the same length |

**Returns** - `revenue_tax_excl`, `revenue_tax_incl`, `currency`, `orders_count`, `average_basket_tax_incl`, `new_customers`, and `previous` when asked.

**Notes** - counts valid orders only (`Order::valid`), converted to the shop's default currency, as the dashboard does.

#### `somcp_list_best_sellers`
**State**: draft · [issue #22](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/22)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.stats.read`

**Does** - lists the products that sold most over a period.

**Do not use for** - sales totals (→ `somcp_get_sales_summary`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | yes |  |
| `sort` | enum | no | `quantity` (default) or `revenue` |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `name`, `quantity_sold`, `revenue_tax_excl`, `currency`. Plus `total_count`.

**Notes** - valid orders only.

### Content

The CMS pages of the storefront.

**Not here**: product texts (→ Catalogue).

#### `somcp_list_cms_pages`
**State**: draft · [issue #23](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/23)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.content.read`

**Does** - lists the CMS pages with their category and status.

**Do not use for** - reading one page (→ `somcp_get_cms_page`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms_category` | integer | no |  |
| `active` | boolean | no |  |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per page: `id_cms`, `meta_title`, `id_cms_category`, `active`, `link`.

#### `somcp_get_cms_page`
**State**: draft · [issue #24](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/24)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.content.read`

**Does** - returns one CMS page with its content.

**Do not use for** - listing pages (→ `somcp_list_cms_pages`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms` | integer | yes |  |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `id_cms`, `meta_title`, `meta_description`, `content` (HTML), `link_rewrite`, `active`, `link`.

### Modules

The modules present on the shop, their state and version.

**Not here**: tools published by other modules, which they declare themselves (see EXTENDING.md).

#### `somcp_list_modules`
**State**: draft · [issue #25](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/25)
**Nature**: `acts(VIEW, 'AdminModules')` · **Scope**: `mcp.modules.read`

**Does** - lists the modules present on the shop with their version and state.

**Do not use for** - details of one module (→ `somcp_get_module`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `installed` | boolean | no |  |
| `active` | boolean | no |  |
| `query` | string | no | part of the name or display name |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per module: `name`, `display_name`, `version`, `installed`, `active`, `author`.

#### `somcp_get_module`
**State**: draft · [issue #26](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/26)
**Nature**: `acts(VIEW, 'AdminModules')` · **Scope**: `mcp.modules.read`

**Does** - returns one module's details: version on disk and in the database, state, author, hooks it is attached to.

**Do not use for** - listing modules (→ `somcp_list_modules`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes | technical name, the folder |

**Returns** - `name`, `display_name`, `description`, `version_on_disk`, `version_installed`, `installed`, `active`, `author`, `hooks`, `needs_upgrade`.

#### `somcp_install_module`
**State**: draft · [issue #27](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/27)
**Nature**: `acts(ADD, 'AdminModules')` · **Scope**: `mcp.modules.write`

**Does** - installs one module already present in the shop's `modules` folder.

**Do not use for** - uploading or downloading a module, which this tool does not do: the files must already be on the shop.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes | technical name, the folder |

**Returns** - `installed`, `name`, `version`, `errors`.

**Notes** - through the core's `ModuleManager`, exactly as the back office does it, so the module's own install checks run. Not replayable: the read tool to check is `somcp_get_module`.

#### `somcp_set_module_active`
**State**: draft · [issue #28](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/28)
**Nature**: `acts(EDIT, 'AdminModules')` · **Scope**: `mcp.modules.write`

**Does** - enables or disables one installed module.

**Do not use for** - installing or uninstalling it.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `name`, `active`. When the module is already in that state: `changed: false`.

**Notes** - somcp refuses to disable itself: the answer would never reach the assistant.

### Appearance

What the storefront looks like: the main menu of `ps_mainmenu`, the active theme and its page layouts.

**Not here**: content of the pages (→ Content).

#### `somcp_list_menu_links`
**State**: draft · [issue #29](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/29)
**Nature**: `acts(VIEW, 'AdminModules')` · **Scope**: `mcp.appearance.read`

**Does** - lists the entries of the storefront's main menu, from the `ps_mainmenu` module.

**Do not use for** - the shop's categories (→ `somcp_list_categories`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per entry, in menu order: `type` (category, CMS page, custom link, ...), `id`, `label`, `link`, `new_window`.

**Notes** - only listed when `ps_mainmenu` is installed and active; absent otherwise, never failing.

#### `somcp_add_menu_link`
**State**: draft · [issue #30](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/30)
**Nature**: `acts(ADD, 'AdminModules')` · **Scope**: `mcp.appearance.write`

**Does** - adds one custom link, a category or a CMS page to the main menu.

**Do not use for** - changing an existing custom link (→ `somcp_update_menu_link`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `type` | enum | yes | `category`, `cms`, `link` |
| `id` | integer | for category and cms |  |
| `label` | string | for link |  |
| `url` | string | for link |  |
| `new_window` | boolean | no |  |
| `position` | integer | no | at the end by default |

**Returns** - `added`, the menu as `somcp_list_menu_links` returns it.

**Notes** - written through `ps_mainmenu`'s own storage so its cache is cleared like the back office does. Not replayable.

#### `somcp_update_menu_link`
**State**: draft · [issue #31](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/31)
**Nature**: `acts(EDIT, 'AdminModules')` · **Scope**: `mcp.appearance.write`

**Does** - changes the label, URL or target of one custom link in the main menu.

**Do not use for** - adding an entry (→ `somcp_add_menu_link`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id` | integer | yes | custom link, from `somcp_list_menu_links` |
| `label` | string | no |  |
| `url` | string | no |  |
| `new_window` | boolean | no |  |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `changed`, the fields written with their old and new values.

#### `somcp_get_theme`
**State**: draft · [issue #32](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/32)
**Nature**: `acts(VIEW, 'AdminThemes')` · **Scope**: `mcp.appearance.read`

**Does** - returns the active theme: name, version, parent theme, logos and the layout used by each page.

**Parameters** - none.

**Returns** - `name`, `version`, `parent`, `logos`, `layouts` (page → layout), `available_layouts`.

#### `somcp_update_theme_setting`
**State**: draft · [issue #33](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/33)
**Nature**: `acts(EDIT, 'AdminThemes')` · **Scope**: `mcp.appearance.write`

**Does** - sets the layout of one page of the active theme, as Appearance > Theme & Logo > Choose layouts does.

**Do not use for** - installing or switching themes.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `page` | string | yes | page name, from `somcp_get_theme` |
| `layout` | string | yes | one of `available_layouts` |

**Returns** - `changed`, `page`, `previous_layout`, `layout`.

**Notes** - the scope is the page layouts on purpose: other theme settings depend on each theme. Wider settings can be proposed in the issue.

### System

How the shop runs: caching.

**Not here**: the connection itself (→ Diagnostics).

#### `somcp_get_cache_status`
**State**: draft · [issue #34](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/34)
**Nature**: `acts(VIEW, 'AdminPerformance')` · **Scope**: `mcp.system.read`

**Does** - returns how caching is configured: template compilation and cache, CCC for CSS and JavaScript, the caching system, debug mode.

**Parameters** - none.

**Returns** - `smarty_compile`, `smarty_cache`, `ccc_css`, `ccc_js`, `cache_enabled`, `cache_system`, `debug_mode`.

#### `somcp_clear_cache`
**State**: draft · [issue #35](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/35)
**Nature**: `acts(EDIT, 'AdminPerformance')` · **Scope**: `mcp.system.write`

**Does** - clears the shop's caches, as the Clear cache button in Advanced parameters > Performance does.

**Parameters** - none.

**Returns** - `cleared`.

**Notes** - the storefront is slower for a while as the cache rebuilds; the description says so.

---

### SEO

What search engines read of the shop: titles, descriptions, URLs and their settings, robots.txt, the sitemap, and the pages as they are actually served.

**Not here**: the product, category and brand fields themselves, which their own update tools write (→ Catalogue); what answer engines and AI assistants need (→ AEO).

#### `somcp_audit_product_seo`
**State**: to write · [issue #82](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/82)
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - lists the products whose search engine fields have a problem, with the problem named, per language.

**Do not use for** - reading or changing one product (→ `somcp_get_product`, `somcp_update_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language of the shop when left out |
| `active` | boolean | no | true by default: hidden products rank for nothing |
| `checks` | array of enum | no | a subset of the checks below; all by default |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `name`, `language`, `issues` (each a `check` from the list below and the value at fault). Plus `total_count` and `counts` per check over the whole catalogue.

**Notes**
- Checks: `meta_title_missing`, `meta_title_too_long` (over 60 characters), `meta_title_duplicate` (another product has it in that language), `meta_description_missing`, `meta_description_short` (under 70), `meta_description_long` (over 160), `link_rewrite_duplicate`, `description_short` (under 150 words, tags removed), `image_legend_missing` (an image without its alternative text), `no_image`, `no_brand`, `no_gtin` (no ean13, upc nor isbn), `inactive_without_redirect` only when `active` is false.
- Read in SQL from `product`, `product_shop`, `product_lang` and `image_lang` of this shop, in pages, so a catalogue of 50,000 products stays fast. No write, ever: corrections go through `somcp_update_product`.

#### `somcp_audit_category_seo`
**State**: to write · [issue #83](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/83)
**Nature**: `acts(VIEW, 'AdminCategories')` · **Scope**: `mcp.seo.read`

**Does** - lists the categories whose search engine fields have a problem, per language.

**Do not use for** - changing a category (→ `somcp_update_category`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per category: `id_category`, `name`, `language`, `issues`. Plus `total_count` and `counts` per check.

**Notes**
- Checks: `meta_title_missing`, `meta_title_too_long`, `meta_title_duplicate`, `meta_description_missing`, `meta_description_long`, `description_missing` (a category page with no text has nothing to rank on), `link_rewrite_duplicate`, `empty_category` (displayed, no active product), `no_cover_image`.
- The root and Home categories are left out: they have no page of their own.

#### `somcp_audit_cms_seo`
**State**: to write · [issue #84](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/84)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.seo.read`

**Does** - lists the CMS pages whose search engine fields have a problem, per language.

**Do not use for** - changing a page's SEO fields (→ `somcp_update_cms_seo`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |

**Returns** - per page: `id_cms`, `meta_title`, `language`, `active`, `issues`. Plus `counts` per check.

**Notes**
- Checks: `meta_title_too_long`, `meta_title_duplicate`, `meta_description_missing`, `meta_description_long`, `content_short` (under 150 words), `link_rewrite_duplicate`, `not_indexable` (the page's `indexation` flag is off).

#### `somcp_audit_brand_seo`
**State**: to write · [issue #85](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/85)
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.seo.read`

**Does** - lists the brands whose search engine fields or descriptions have a problem, per language.

**Do not use for** - changing a brand (→ `somcp_update_manufacturer`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code; every language when left out |

**Returns** - per brand: `id_manufacturer`, `name`, `language`, `products_count`, `issues`. Plus `counts` per check.

**Notes**
- Checks: `meta_title_missing`, `meta_description_missing`, `description_missing`, `no_logo`, `inactive_with_products`.

#### `somcp_update_cms_seo`
**State**: to write · [issue #86](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/86)
**Nature**: `acts(EDIT, 'AdminCmsContent')` · **Scope**: `mcp.seo.write`

**Does** - changes the meta title, meta description, friendly URL and indexing flag of one CMS page.

**Do not use for** - the page's content (a Content tool), or a product, category or brand (→ their update tools).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cms` | integer | yes |  |
| `meta_title` | object | no | per ISO code; 70 characters at most |
| `meta_description` | object | no | per ISO code; 512 characters at most, a warning over 160 |
| `link_rewrite` | object | no | per ISO code; `Tools::str2url()` then `Validate::isLinkRewrite()` |
| `indexation` | boolean | no | whether search engines may index the page |

**Returns** - `changed`, `id_cms`, and per field the `previous` and new values, plus `seo_warnings`.

**Notes**
- Written through the `CMS` object, so hooks fire. Only the languages given change.
- A new `link_rewrite` on an active page breaks the old URL: said in `seo_warnings`, and the assistant tells the merchant before the call.
- A call that changes nothing writes nothing and answers `changed: false`.

#### `somcp_list_meta_pages`
**State**: to write · [issue #87](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/87)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - lists the shop's own pages (home, contact, new products, best sales, search...) with their title, description and friendly URL.

**Do not use for** - products, categories, brands or CMS pages, which have their own fields.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per page: `id_meta`, `page` (the controller name), `title`, `description`, `url_rewrite`, `url`, `configurable`.

**Notes**
- Read through `Meta`, as the Traffic & SEO page lists them.

#### `somcp_update_meta_page`
**State**: to write · [issue #88](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/88)
**Nature**: `acts(EDIT, 'AdminMeta')` · **Scope**: `mcp.seo.write`

**Does** - changes the title, description or friendly URL of one of the shop's own pages.

**Do not use for** - products, categories, brands or CMS pages.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_meta` | integer | yes | from `somcp_list_meta_pages` |
| `title` | object | no | per ISO code; 70 characters at most |
| `description` | object | no | per ISO code; a warning over 160 |
| `url_rewrite` | object | no | per ISO code; refused if another page already uses it |

**Returns** - `changed`, `id_meta`, per field `previous` and new value, `seo_warnings`.

**Notes**
- Written through `Meta`. A new `url_rewrite` breaks the old address: said before and in the answer. The home page has no `url_rewrite` and refuses one.

#### `somcp_get_seo_settings`
**State**: to write · [issue #89](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/89)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's SEO and URL settings, and what each one means for search engines.

**Do not use for** - changing them, which stays in the back office: these settings break every URL of the shop when wrong.

**Parameters**

None.

**Returns** - `friendly_urls`, `accented_urls`, `canonical_redirect` (none, 302 or 301), `https` (whether the shop answers in HTTPS), `domains` (main and others), `routes` (product, category, brand, supplier, CMS and their patterns), `robots_txt_present`, `sitemap_module` (gsitemap installed and active, or not), `languages`, `warnings`.

**Notes**
- Settings read by their configuration keys in each version; a key a version does not have is `null`, never guessed.
- `warnings` names what costs rankings: friendly URLs off, no canonical redirection, a shop not served in HTTPS, no robots.txt, no sitemap module.

#### `somcp_get_robots_txt`
**State**: to write · [issue #90](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/90)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's robots.txt and what it allows and blocks, rule by rule.

**Do not use for** - AI crawlers specifically (→ `somcp_check_ai_crawler_access`), or regenerating the file (→ `somcp_generate_robots_txt`).

**Parameters**

None.

**Returns** - `present`, `content`, `groups` (per user agent: `allow`, `disallow`), `sitemaps` (declared sitemap URLs), `size`, `last_modified`, `warnings`.

**Notes**
- Read from the file at the shop root, the one PrestaShop writes.
- `warnings` names a rule that blocks product or category pages, a whole-site `Disallow: /`, a missing sitemap line.

#### `somcp_generate_robots_txt`
**State**: to write · [issue #91](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/91)
**Nature**: `acts(EDIT, 'AdminMeta')` · **Scope**: `mcp.seo.write`

**Does** - writes the shop's robots.txt again, as the Generate button of the Traffic & SEO page does.

**Do not use for** - reading it (→ `somcp_get_robots_txt`).

**Parameters**

None.

**Returns** - `changed`, `previous_content`, `content`.

**Notes**
- Through the core's own `Tools::generateRobotsFile()`, so modules that add their lines through its hook still do.
- Any line added by hand to the file is lost: the previous content is returned and the assistant tells the merchant before the call.

#### `somcp_audit_page`
**State**: to write · [issue #92](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/92)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - fetches one public page of the shop and returns what a search engine reads on it.

**Do not use for** - many pages at once (→ `somcp_check_links`), or the fields stored in the database (→ the audit tools of this section).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `url` | string | yes | an address of this shop, or a path such as `/en/3-clothes` |

**Returns** - `status`, `final_url`, `redirects` (each hop with its code), `time_ms`, `title`, `meta_description`, `meta_robots`, `canonical`, `hreflang` (language and URL of each alternate), `headings` (h1 to h3 in order), `images` (count, without alt), `links` (internal and external counts), `json_ld` (each block's `@type` and its main fields), `open_graph`, `word_count`, `warnings`.

**Notes**
- Only addresses of this shop are fetched: the host must be one of the shop's own domains (`ShopUrl` of this shop), anything else is refused before any request, redirects included, so the tool can never be used to reach another site or the internal network. GET only, a 10 second timeout, 2 MB at most per page, a user agent naming the module. The page is fetched as a visitor sees it, without the employee's session.
- `warnings`: no or several h1, a canonical pointing elsewhere, a `noindex`, a missing hreflang for an active language, a title or description too long, a redirect chain, a page slower than 2 seconds.

#### `somcp_check_links`
**State**: to write · [issue #93](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/93)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - follows the links of the shop's public pages from a starting page and reports broken links and redirect chains.

**Do not use for** - one page in detail (→ `somcp_audit_page`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `start_url` | string | no | the home page by default |
| `max_pages` | integer | no | 50 by default, 200 at most |

**Returns** - `pages_checked`, `broken` (URL, status, the pages linking to it), `redirect_chains` (URL and its hops), `slow_pages` (over 2 seconds), `stopped_early` with the reason.

**Notes**
- Only addresses of this shop are fetched: the host must be one of the shop's own domains (`ShopUrl` of this shop), anything else is refused before any request, redirects included, so the tool can never be used to reach another site or the internal network. GET only, a 10 second timeout, 2 MB at most per page, a user agent naming the module. The page is fetched as a visitor sees it, without the employee's session.
- Internal links only are followed; external links are listed, not fetched. The crawl stops at `max_pages` or after 60 seconds, and says so.

#### `somcp_audit_sitemap`
**State**: to write · [issue #94](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/94)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - finds the shop's sitemaps, counts their URLs, and checks them against the active catalogue.

**Do not use for** - robots.txt (→ `somcp_get_robots_txt`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `sample` | integer | no | how many listed URLs to fetch and check; 20 by default, 100 at most |

**Returns** - `sitemaps` (URL, `url_count`, `last_modified`), `missing_products` (active products of this shop absent from every sitemap, counted, the first 20 listed), `inactive_listed` (URLs of disabled products still listed), `sample_errors` (listed URLs answering other than 200).

**Notes**
- Sitemaps are found from robots.txt, then from the gsitemap module's files at the shop root.
- Only addresses of this shop are fetched: the host must be one of the shop's own domains (`ShopUrl` of this shop), anything else is refused before any request, redirects included, so the tool can never be used to reach another site or the internal network. GET only, a 10 second timeout, 2 MB at most per page, a user agent naming the module. The page is fetched as a visitor sees it, without the employee's session.

### AEO

What answer engines and AI assistants need to recommend a product: complete and consistent product data, structured data that matches the shop, open access for their crawlers, and the pages that build trust.

**Not here**: classic search engine fields and settings (→ SEO).

#### `somcp_audit_product_schema`
**State**: to write · [issue #95](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/95)
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - fetches a product's public page and compares its structured data (JSON-LD) with what the shop holds.

**Do not use for** - the database fields alone (→ `somcp_audit_product_entities`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |
| `id_product_attribute` | integer | no | a combination's page |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - `url`, `found` (the `@type`s present), `product` (name, brand, sku, gtin, mpn, image, description length as printed), `offer` (price, currency, availability, url as printed), `rating` (as printed, when present), `mismatches` (field, printed value, shop value), `missing` (recommended fields absent).

**Notes**
- Only addresses of this shop are fetched: the host must be one of the shop's own domains (`ShopUrl` of this shop), anything else is refused before any request, redirects included, so the tool can never be used to reach another site or the internal network. GET only, a 10 second timeout, 2 MB at most per page, a user agent naming the module. The page is fetched as a visitor sees it, without the employee's session.
- Prices are compared with the tax-included price the page shows for this shop's default country and currency, availability with the stock and the out-of-stock behaviour.
- Said plainly when the theme prints no Product JSON-LD at all: that is a theme matter, not something a tool can write.

#### `somcp_audit_product_entities`
**State**: to write · [issue #96](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/96)
**Nature**: `acts(VIEW, 'AdminProducts')` · **Scope**: `mcp.seo.read`

**Does** - scores how complete each product's data is for an assistant answering a buyer, and lists what is missing.

**Do not use for** - the page as printed (→ `somcp_audit_product_schema`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no | true by default |
| `max_score` | integer | no | only products scoring at or under this, out of 100 |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product, worst first: `id_product`, `name`, `score`, `missing` (from the list below). Plus `total_count` and `average_score`.

**Notes**
- Scored on: brand, a GTIN (ean13, upc or isbn), an mpn, at least 3 features, a description of 150 words or more, a short description, at least 3 images with alternative text, weight, condition, a default category deeper than Home, a meta description. Each item is worth the same; the list says which is missing, the score only sorts.

#### `somcp_check_ai_crawler_access`
**State**: to write · [issue #97](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/97)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - says whether the main AI crawlers may read the shop, from its robots.txt and the robots meta of its pages.

**Do not use for** - the full robots.txt (→ `somcp_get_robots_txt`).

**Parameters**

None.

**Returns** - per crawler (`GPTBot`, `OAI-SearchBot`, `ChatGPT-User`, `ClaudeBot`, `Claude-SearchBot`, `PerplexityBot`, `Google-Extended`, `Applebot-Extended`, `CCBot`, `Bingbot`): `allowed` on the home page, a product page and a category page, and the rule deciding it. Plus `meta_robots` found on those pages.

**Notes**
- Applies the robots.txt matching rules (most specific group, longest rule) the way the crawlers document them.
- Reports only; whether to open the shop to a crawler is the merchant's choice, and the answer says so.

#### `somcp_audit_store_policies`
**State**: to write · [issue #98](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/98)
**Nature**: `acts(VIEW, 'AdminCmsContent')` · **Scope**: `mcp.seo.read`

**Does** - checks that the pages buyers and assistants look for before trusting a shop exist, are displayed and are linked.

**Do not use for** - the SEO fields of those pages (→ `somcp_audit_cms_seo`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per expected page (`legal_notice`, `terms`, `shipping`, `returns`, `privacy`, `contact`, `about`): `found`, `id_cms` or the shop page, `active`, `url`, `word_count`. Plus `delivery_times` (whether products carry delivery time texts) and `warnings`.

**Notes**
- Pages are matched by their friendly URL and title in the shop's languages, then confirmed with the merchant when unsure; the answer says how each was matched.

#### `somcp_get_llms_txt`
**State**: to write · [issue #99](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/99)
**Nature**: `acts(VIEW, 'AdminMeta')` · **Scope**: `mcp.seo.read`

**Does** - returns the shop's llms.txt, the summary some AI tools read at a site's root, if it has one.

**Do not use for** - writing it (→ `somcp_generate_llms_txt`).

**Parameters**

None.

**Returns** - `present`, `content`, `size`, `last_modified`, `written_by_somcp`.

**Notes**
- llms.txt is a proposed convention, not a standard: the answer says so, and that no assistant is bound to read it.

#### `somcp_generate_llms_txt`
**State**: to write · [issue #100](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/100)
**Nature**: `acts(EDIT, 'AdminMeta')` · **Scope**: `mcp.seo.write`

**Does** - writes an llms.txt at the shop root from the shop's own data: name, description, main categories and their URLs, policy pages.

**Do not use for** - robots.txt (→ `somcp_generate_robots_txt`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `summary` | object | no | per ISO code: the paragraph describing the shop, written with the merchant |
| `overwrite` | boolean | no | required to replace a file somcp did not write |

**Returns** - `changed`, `previous_content`, `content`, `url`.

**Notes**
- Only factual content from the shop: no claim, review or figure invented. Active categories and CMS pages only, in the default language first.
- A file written by someone else is never replaced without `overwrite`, and its content comes back in the answer. A marker comment records that somcp wrote it.
- The file is written atomically next to robots.txt; a shop whose root is not writable gets a clear refusal.

### Sales

The levers that turn visits into orders: carts left behind, what customers search for, what they look at without buying, products that stopped selling, and the promotions that move them.

**Not here**: sales figures over a period (→ Statistics), the orders themselves (→ Orders), a product's own specific prices (→ `somcp_update_product`).

#### `somcp_list_abandoned_carts`
**State**: to write · [issue #101](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/101)
**Nature**: `acts(VIEW, 'AdminCarts')` · **Scope**: `mcp.sales.read`

**Does** - lists the carts with products that never became an order, newest first.

**Do not use for** - placed orders (→ `somcp_search_orders`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `older_than_hours` | integer | no | 2 by default: a cart still being filled is not abandoned |
| `date_from` / `date_to` | string | no | `YYYY-MM-DD` |
| `with_customer` | boolean | no | only carts of a known customer |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per cart: `id_cart`, `date_upd`, `customer` (id and name, or `null` for a guest), `products_count`, `total_tax_incl`, `currency`, `top_products` (the three most expensive lines). Plus `total_count` and `total_value`.

**Notes**
- A cart with an order is never listed. Personal data stays plain in the list: no email or phone, as the shared conventions require.
- Read only: the tool sends nothing to anyone.

#### `somcp_list_search_terms`
**State**: to write · [issue #102](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/102)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists what customers typed in the shop's search, with how often and how many results they got.

**Do not use for** - search engine queries from outside the shop, which need Search Console.

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | no | `YYYY-MM-DD`, the last 30 days by default |
| `no_results_only` | boolean | no | the searches that found nothing |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per term: `keywords`, `searches`, `average_results`, `last_searched`. Plus `total_count`.

**Notes**
- Read from the table the statssearch module fills; when that module is not installed or active, the answer says so and returns nothing, rather than an empty list that would read as no searches.
- Terms are grouped case- and accent-insensitively.

#### `somcp_list_viewed_not_sold`
**State**: to write · [issue #103](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/103)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists the products customers looked at most over a period, with what they sold, so the ones that are seen but not bought stand out.

**Do not use for** - best sellers (→ `somcp_list_best_sellers`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `date_from` / `date_to` | string | no | the last 30 days by default |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `name`, `views`, `quantity_sold`, `conversion` (orders per hundred views), `price_tax_incl`, `quantity` in stock. Sorted by views with the lowest conversion first among equals.

**Notes**
- Views come from the tables the statsdata module fills, with page views recorded; when it does not record them, the answer says so.
- Sales count valid orders only (`Order::valid`).

#### `somcp_list_unsold_products`
**State**: to write · [issue #104](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/104)
**Nature**: `acts(VIEW, 'AdminStats')` · **Scope**: `mcp.sales.read`

**Does** - lists active products in stock that sold nothing over a period.

**Do not use for** - low stock (→ `somcp_list_low_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `days` | integer | no | 90 by default |
| `min_quantity` | integer | no | only products with at least this much in stock; 1 by default |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per product: `id_product`, `name`, `quantity`, `price_tax_incl`, `stock_value_tax_excl` (quantity times wholesale price, when set), `date_add`, `last_sold` (or `null`). Plus `total_count` and `total_stock_value`.

**Notes**
- Products created during the period are left out: they had no chance to sell yet.

#### `somcp_list_cart_rules`
**State**: to write · [issue #105](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/105)
**Nature**: `acts(VIEW, 'AdminCartRules')` · **Scope**: `mcp.sales.read`

**Does** - lists the cart rules (vouchers and automatic discounts) with their dates, code and use.

**Do not use for** - reading one rule (→ `somcp_get_cart_rule`), or catalogue price rules (→ `somcp_list_catalog_price_rules`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `active` | boolean | no |  |
| `current` | boolean | no | only rules valid today |
| `limit` / `offset` | integer | no | 20 by default, 100 at most |

**Returns** - per rule: `id_cart_rule`, `name`, `code`, `active`, `date_from`, `date_to`, `quantity` left, `reduction` (percent, amount with currency and tax, free shipping, gift product, summarised), `times_used`. Plus `total_count`.

**Notes**
- Only the rules this shop can use.

#### `somcp_get_cart_rule`
**State**: to write · [issue #106](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/106)
**Nature**: `acts(VIEW, 'AdminCartRules')` · **Scope**: `mcp.sales.read`

**Does** - returns every field of one cart rule, as its three back office tabs show it.

**Do not use for** - listing rules (→ `somcp_list_cart_rules`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cart_rule` | integer | yes |  |

**Returns** - Information (name per language, description, code, highlight, partial use, priority, active), Conditions (customer, dates, minimum amount with currency and tax, quantities, carrier, country, group and product restrictions as lists), Actions (free shipping, percent or amount with currency and tax, the product it applies to, gift product).

**Notes**
- Restrictions are returned as names and identifiers, read from the core's own tables.

#### `somcp_create_cart_rule`
**State**: to write · [issue #107](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/107)
**Nature**: `acts(ADD, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - creates one cart rule: a voucher code or an automatic discount.

**Do not use for** - changing a rule (→ `somcp_update_cart_rule`), or a discount on products in the catalogue (→ `somcp_create_catalog_price_rule`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | object | yes | per ISO code, shown to the customer |
| `code` | string | no | empty for an automatic rule; refused if another rule has it |
| `date_from` / `date_to` | string | yes | `YYYY-MM-DD HH:MM:SS` |
| `reduction_percent` / `reduction_amount` | number | no | one of the two, or `free_shipping` |
| `reduction_tax` | boolean | no | whether an amount includes tax |
| `reduction_currency` | string | no | ISO code of an amount |
| `free_shipping` | boolean | no |  |
| `minimum_amount` | number | no | with `minimum_amount_tax` and `minimum_amount_currency` |
| `quantity` / `quantity_per_user` | integer | no | 1 each by default |
| `reduction_product` | integer | no | apply to one product only |
| `id_customer` | integer | no | a rule for one customer |
| `active` | boolean | no | false by default: the merchant switches it on |

**Returns** - `created`, `id_cart_rule`, and the rule as `somcp_get_cart_rule` returns it.

**Notes**
- Written through `CartRule`, so hooks fire. Product, category, carrier and country restrictions are not set by this tool in its first version: the answer says so when asked, and the back office remains the place for them.
- Created inactive by default, so a rule never reaches customers before the merchant has seen it.

#### `somcp_update_cart_rule`
**State**: to write · [issue #108](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/108)
**Nature**: `acts(EDIT, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - changes the dates, quantities, amounts or texts of one cart rule.

**Do not use for** - switching a rule on or off (→ `somcp_set_cart_rule_active`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cart_rule` | integer | yes |  |
| ... |  | no | the fields of `somcp_create_cart_rule`, each optional |

**Returns** - `changed`, per field `previous` and new value, and the rule.

**Notes**
- Only what is given changes; a call that changes nothing writes nothing and answers `changed: false`.

#### `somcp_set_cart_rule_active`
**State**: to write · [issue #109](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/109)
**Nature**: `acts(EDIT, 'AdminCartRules')` · **Scope**: `mcp.sales.write`

**Does** - switches one cart rule on or off.

**Do not use for** - changing its content (→ `somcp_update_cart_rule`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_cart_rule` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `id_cart_rule`, `active`.

**Notes**
- Reversible, and said so: switching off is how a promotion is stopped early.

#### `somcp_list_catalog_price_rules`
**State**: to write · [issue #110](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/110)
**Nature**: `acts(VIEW, 'AdminSpecificPriceRule')` · **Scope**: `mcp.sales.read`

**Does** - lists the catalogue price rules, the discounts applied to products before the cart.

**Do not use for** - cart rules (→ `somcp_list_cart_rules`), or one product's specific prices (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `current` | boolean | no | only rules valid today |

**Returns** - per rule: `id_specific_price_rule`, `name`, `from`, `to`, `reduction` with `reduction_type` and `reduction_tax`, `from_quantity`, `currency`, `country`, `group`, `conditions` (summarised).

**Notes**
- Only this shop's rules.

#### `somcp_create_catalog_price_rule`
**State**: to write · [issue #111](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/111)
**Nature**: `acts(ADD, 'AdminSpecificPriceRule')` · **Scope**: `mcp.sales.write`

**Does** - creates one catalogue price rule, optionally limited to some categories, and applies it.

**Do not use for** - a voucher (→ `somcp_create_cart_rule`), or one product's own price (→ `somcp_update_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `name` | string | yes |  |
| `reduction` | number | yes |  |
| `reduction_type` | enum | yes | `percentage` or `amount` |
| `reduction_tax` | boolean | no | for an amount |
| `from` / `to` | string | yes | `YYYY-MM-DD HH:MM:SS` |
| `from_quantity` | integer | no | 1 by default |
| `currency` / `country` / `group` | string or integer | no | ISO codes, and a group from `somcp_list_customer_groups`; all by default |
| `categories` | array of integer | no | limit the rule to products of these categories |

**Returns** - `created`, `id_specific_price_rule`, the rule, and `products_affected` (count).

**Notes**
- Written through `SpecificPriceRule` and its condition groups, then `apply()`, as the back office does.
- The answer says how many products the rule reaches, so the merchant sees its size before it runs a day.

## Prompts

A prompt is the guide an assistant reads before using the tools of one part of the
shop. There is one per section, so a merchant starts from the menu of their
assistant with the right instructions loaded. How a prompt is built - `prompt.md`
plus `prompt.json`, filtering, arguments - is in the shared conventions above and in
[EXTENDING.md](EXTENDING.md).

Every tool the module ships belongs to at least one prompt, and CI checks it: a
pull request that adds a tool adds it to the prompt of its section, in both
`prompt.json` and the guide.

| Prompt | Section | State |
|---|---|---|
| `somcp-connection-check` | Diagnostics | written |
| `somcp-order-lookup` | Orders | written |
| `somcp-order-status-change` | Orders | written |
| `somcp-catalogue-manage` | Catalogue | written |
| `somcp-stock-check` | Stock | written |
| `somcp-customer-lookup` | Customers | draft · [issue #38](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/38) |
| `somcp-sales-report` | Statistics | draft · [issue #39](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/39) |
| `somcp-content-pages` | Content | draft · [issue #40](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/40) |
| `somcp-modules-review` | Modules | draft · [issue #41](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/41) |
| `somcp-storefront-menu` | Appearance | draft · [issue #42](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/42) |
| `somcp-cache-maintenance` | System | draft · [issue #43](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/43) |
| `somcp-seo-audit` | SEO | to write · [issue #79](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/79) |
| `somcp-aeo-audit` | AEO | to write · [issue #80](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/80) |
| `somcp-sales-boost` | Sales | to write · [issue #81](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/81) |

#### `somcp-catalogue-manage`
**State**: written
**Section**: Catalogue · **Scope**: `mcp.catalog.read` · **Permission**: `view` on `AdminProducts`

**Title** - Manage products

**Description** - Find, read and change products and categories in the PrestaShop shop, one at a time, without guessing an identifier.

**Arguments** - `product` (name, reference or ean13 of a product).

**Tools** - `somcp_ping`, `somcp_search_products`, `somcp_get_product`, `somcp_list_categories`, `somcp_create_product`, `somcp_update_product`, `somcp_set_product_active`, `somcp_delete_product`, `somcp_get_category`, `somcp_create_category`, `somcp_update_category`, `somcp_set_category_active`, `somcp_list_manufacturers`, `somcp_get_manufacturer`, `somcp_create_manufacturer`, `somcp_update_manufacturer`, `somcp_set_manufacturer_active`, `somcp_list_manufacturer_addresses`, `somcp_get_manufacturer_address`, `somcp_create_manufacturer_address`, `somcp_update_manufacturer_address`, `somcp_delete_manufacturer_address`, `somcp_list_suppliers`, `somcp_get_supplier`, `somcp_create_supplier`, `somcp_update_supplier`, `somcp_set_supplier_active`, `somcp_list_attributes`, `somcp_get_attribute_group`, `somcp_create_attribute_group`, `somcp_update_attribute_group`, `somcp_get_attribute_value`, `somcp_create_attribute_value`, `somcp_update_attribute_value`, `somcp_list_features`, `somcp_get_feature`, `somcp_create_feature`, `somcp_update_feature`, `somcp_get_feature_value`, `somcp_create_feature_value`, `somcp_update_feature_value`, `somcp_list_attachments`, `somcp_list_tax_rules`, `somcp_list_customer_groups`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - search before reading, and read before changing; take category identifiers from `somcp_list_categories`, never guess them; say whether a price is tax included or excluded; one product per write, never retried on a lost answer; switching a product off is reversible, deleting it is not, and the merchant is told which is happening.

#### `somcp-stock-check`
**State**: written
**Section**: Stock · **Scope**: `mcp.stock.read` · **Permission**: `view` on `AdminStockManagement`

**Title** - Check and adjust stock

**Description** - See what runs low in the PrestaShop shop and set quantities, one product or combination at a time.

**Arguments** - `threshold` (quantity at or under which a product counts as low).

**Tools** - `somcp_ping`, `somcp_list_low_stock`, `somcp_get_stock`, `somcp_search_products`, `somcp_set_stock_quantity`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start from `somcp_list_low_stock` or a named product; a quantity is per combination when the product has some; a `delta` moves stock and is never retried: on a lost answer, read `somcp_get_stock` first.

#### `somcp-customer-lookup`
**State**: draft · [issue #38](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/38)
**Section**: Customers · **Scope**: `mcp.customers.read` · **Permission**: `view` on `AdminCustomers`

**Title** - Find a customer

**Description** - Find a customer of the PrestaShop shop, read their account and orders, and update it when asked.

**Arguments** - `customer` (name, email or company).

**Tools** - `somcp_ping`, `somcp_search_customers`, `somcp_get_customer`, `somcp_search_orders`, `somcp_create_customer`, `somcp_update_customer`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - search, then read the one customer the merchant means; the email is more reliable than the name; personal data stays in the answer it was asked for; no password ever goes through the conversation.

#### `somcp-sales-report`
**State**: draft · [issue #39](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/39)
**Section**: Statistics · **Scope**: `mcp.stats.read` · **Permission**: `view` on `AdminStats`

**Title** - Sales report

**Description** - Report the PrestaShop shop's sales over a period: revenue, orders, average basket, best sellers.

**Arguments** - `period` (period to report on, for example last month).

**Tools** - `somcp_ping`, `somcp_get_sales_summary`, `somcp_list_best_sellers`, `somcp_search_orders`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - turn the period into two dates in the shop's time zone and say which; figures count valid orders only; compare with the previous period when it helps; amounts always with their currency and tax said.

#### `somcp-content-pages`
**State**: draft · [issue #40](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/40)
**Section**: Content · **Scope**: `mcp.content.read` · **Permission**: `view` on `AdminCmsContent`

**Title** - Read content pages

**Description** - List and read the CMS pages of the PrestaShop shop.

**Arguments** - `page` (title of a page).

**Tools** - `somcp_ping`, `somcp_list_cms_pages`, `somcp_get_cms_page`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - list, then read the page the merchant means; quote the content rather than paraphrasing it when asked what a page says.

#### `somcp-modules-review`
**State**: draft · [issue #41](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/41)
**Section**: Modules · **Scope**: `mcp.modules.read` · **Permission**: `view` on `AdminModules`

**Title** - Review modules

**Description** - See which modules the PrestaShop shop runs, their version and state, and install or switch one when asked.

**Arguments** - `module` (name of a module).

**Tools** - `somcp_ping`, `somcp_list_modules`, `somcp_get_module`, `somcp_install_module`, `somcp_set_module_active`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read before acting; name the module and what it does before installing or switching it; one module per call; a payment or carrier module switched off stops orders or deliveries, say so before doing it.

#### `somcp-storefront-menu`
**State**: draft · [issue #42](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/42)
**Section**: Appearance · **Scope**: `mcp.appearance.read` · **Permission**: `view` on `AdminThemes`

**Title** - Storefront menu and layout

**Description** - Read and change the main menu of the PrestaShop storefront and the page layouts of its theme.

**Arguments** - none.

**Tools** - `somcp_ping`, `somcp_list_menu_links`, `somcp_add_menu_link`, `somcp_update_menu_link`, `somcp_get_theme`, `somcp_update_theme_setting`, `somcp_list_categories`, `somcp_list_cms_pages`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read the menu or the theme first; take category and page identifiers from their list tools; one change per call.

#### `somcp-cache-maintenance`
**State**: draft · [issue #43](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/43)
**Section**: System · **Scope**: `mcp.system.read` · **Permission**: `view` on `AdminPerformance`

**Title** - Cache maintenance

**Description** - Check how the PrestaShop shop caches and clear the cache when changes do not show.

**Arguments** - none.

**Tools** - `somcp_ping`, `somcp_get_cache_status`, `somcp_clear_cache`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - read the status first; clearing the cache slows the storefront while it rebuilds, so say so and clear it once, not as a reflex.

#### `somcp-seo-audit`
**State**: to write · [issue #79](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/79)
**Section**: SEO · **Scope**: `mcp.seo.read` · **Permission**: `view` on `AdminMeta`

**Title** - Audit and improve SEO

**Description** - Find what holds the shop back in search results, fix it with the merchant's approval, and check the public pages afterwards.

**Arguments** - `focus` (optional: products, categories, pages or the whole shop).

**Tools** - `somcp_ping`, `somcp_audit_product_seo`, `somcp_audit_category_seo`, `somcp_audit_cms_seo`, `somcp_audit_brand_seo`, `somcp_update_cms_seo`, `somcp_list_meta_pages`, `somcp_update_meta_page`, `somcp_get_seo_settings`, `somcp_get_robots_txt`, `somcp_generate_robots_txt`, `somcp_audit_page`, `somcp_check_links`, `somcp_audit_sitemap`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start with `somcp_get_seo_settings` and the audits, then sort what was found by its effect on sales: products that sell or should sell first; propose every new title or description to the merchant before writing it, in each language of the shop; change a URL of a live page only after saying that the old one stops working; after a change, check the public page with `somcp_audit_page`, since a value in the database is not proof of what the theme prints.

#### `somcp-aeo-audit`
**State**: to write · [issue #80](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/80)
**Section**: AEO · **Scope**: `mcp.seo.read` · **Permission**: `view` on `AdminProducts`

**Title** - Get recommended by AI assistants

**Description** - Check that answer engines and AI assistants can read the shop and trust its product data, and fix what they would miss.

**Arguments** - `product` (optional: name, reference or ean13 of a product to start from).

**Tools** - `somcp_ping`, `somcp_audit_product_schema`, `somcp_audit_product_entities`, `somcp_check_ai_crawler_access`, `somcp_audit_store_policies`, `somcp_get_llms_txt`, `somcp_generate_llms_txt`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - no tool can guarantee that an assistant cites the shop, and the guide never promises it; start with `somcp_check_ai_crawler_access` and `somcp_audit_store_policies`, then the products that sell; fix the data itself (brand, GTIN, features, description) with the Catalogue tools rather than adding text for robots; never invent a fact, a review or a figure about a product: ask the merchant; check the public page with `somcp_audit_product_schema` after a change.

#### `somcp-sales-boost`
**State**: to write · [issue #81](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/81)
**Section**: Sales · **Scope**: `mcp.sales.read` · **Permission**: `view` on `AdminStats`

**Title** - Boost sales

**Description** - Find the products, searches and carts where sales are lost, and set up promotions with the merchant's approval.

**Arguments** - `period` (optional: the number of days to look back, 30 by default).

**Tools** - `somcp_ping`, `somcp_list_abandoned_carts`, `somcp_list_search_terms`, `somcp_list_viewed_not_sold`, `somcp_list_unsold_products`, `somcp_list_cart_rules`, `somcp_get_cart_rule`, `somcp_create_cart_rule`, `somcp_update_cart_rule`, `somcp_set_cart_rule_active`, `somcp_list_catalog_price_rules`, `somcp_create_catalog_price_rule`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - start from data, never from a guess: abandoned carts, searches with no result, products viewed but not sold, products that stopped selling; propose each promotion with its cost (the discount times the expected quantity) before creating it; a cart rule is created inactive unless the merchant says otherwise; never email or contact a customer: report the carts, the merchant decides.
