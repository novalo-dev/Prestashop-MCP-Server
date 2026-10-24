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
**State**: to write · [issue #4](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/4)
**Nature**: `acts(EDIT, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - changes one existing product with everything `somcp_create_product` can write: the same groups, the same fields, the same rules, combinations and images included. Only what is given changes.

**Do not use for** - creating a product (→ `somcp_create_product`), or deleting one (→ `somcp_delete_product`).

**Parameters** - `id_product` (required), `languages`, then the groups and fields of `somcp_create_product`, all optional. Every rule of that entry applies unchanged: who fills each field (merchant, merchant from the shop, assistant, both), the lengths (name 128, summary 100 to 200, reference 50, SEO title 55, meta description 512, counted on the visible text with spaces), the identifiers taken from the `somcp_list_*` tools, the SEO section and the image download guards.

**What "only what is given" means**

| Kind of field | Given | Left out |
|---|---|---|
| a single value (`price_tax_excl`, `weight`, `active`...) | replaced | kept |
| a translatable text | replaced in the languages given, the others kept | kept in every language |
| a set chosen as a whole in the back office: `categories`, `related_products`, `features`, `attachments`, `carriers`, `customization` fields | the set given replaces the old one entirely | kept |
| `combinations` | an item with `id_product_attribute` changes that combination, field by field; an item without one creates a combination | kept |
| `images` | an item with `id_image` changes its legend, cover, position or combinations; an item with `url` adds an image | kept |
| `specific_prices` | an item with `id_specific_price` changes it, an item without one adds it | kept |
| `suppliers` | per supplier and combination, the values given replace the old ones | kept |

Removing is always explicit, never inferred from a missing item: `remove_combinations`, `remove_images`, `remove_specific_prices`, `remove_suppliers` (lists of identifiers). A removed combination takes its stock with it, so the assistant names each one to the merchant before the call.

**Returns** - `changed`, then per field written its old and new value, `seo_warnings` for the languages touched, `images` (per URL: `id_image` or the reason it was refused), and the product as `somcp_get_product` returns it.

**Notes**
- `id_product` that does not exist, or an `id_product_attribute`, `id_image`, `id_specific_price` that does not belong to it: refused, nothing written.
- A `reference` changed to one another product or combination uses: refused, as at creation.
- `product_type` can change as in the back office, except away from `combinations` while the product still has some: they would be lost silently. Remove them first, explicitly.
- `link_rewrite` changed on an active product: the old URL stops working. The answer says so in `seo_warnings`; the assistant tells the merchant before the call.
- Everything or nothing, as at creation: if one part cannot be written, nothing is. Only images and SEO warnings are reported without failing.
- Written through the core classes (`Product::update()`, `Combination`, `StockAvailable`, `SpecificPrice`, `Image`) so hooks fire.
- Replayable: calling it again with the same values changes nothing and says `changed: false`, except for an image added by `url`, which would be added twice. The read tool to check first is `somcp_get_product`.

#### `somcp_set_product_active`
**State**: to write · [issue #6](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/6)
**Nature**: `acts(EDIT, 'AdminProducts')` · **Scope**: `mcp.catalog.write`

**Does** - switches one product on or off on the storefront: the **Status** toggle of the back office product list (Catalogue > Products).

**Do not use for** - deleting a product (→ `somcp_delete_product`), or any other change (→ `somcp_update_product`, which can also set `active` along with other fields).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |
| `active` | boolean | yes |  |

**Returns** - `changed`, `active`. When the product is already in that state: `changed: false` and nothing happens.

**Notes** - kept apart from `somcp_update_product` on purpose: "switch this product off" must touch the status and nothing else, the way the toggle does. Written in the shop of the token (`product_shop`), so on a multistore installation the product stays as it was in the other shops.

#### `somcp_delete_product`
**State**: to write · [issue #7](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/7)
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

#### `somcp_list_categories`
**State**: to write · [issue #8](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/8)
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

#### `somcp_get_category`
**State**: to write · [issue #52](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/52)
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
- Columns of `category`, `category_lang`, `category_group`, checked against PrestaShop 8.0.3 and 9.2. `meta_keywords` exists on PrestaShop 8 only and is `null` on 9; `redirect_type` exists on 9 only and is `null` on 8.
- Read in the shop of the token.

#### `somcp_create_category`
**State**: to write · [issue #9](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/9)
**Nature**: `acts(ADD, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - creates one category with every field of the back office category form, in every language of the shop or the ones the merchant picks.

**Do not use for** - changing a category (→ `somcp_update_category`), or switching it on or off (→ `somcp_set_category_active`).

**Parameters** - the fields of `somcp_get_category`, except those computed by PrestaShop (`id_category`, `parent_name`, `level`, `position`, `url`, counts). The rules of `somcp_create_product` apply: `languages`, translatable texts as an object keyed by ISO code, who fills each field, lengths counted in characters, spaces included, on the visible text.

| Field | Who fills it | Rule |
|---|---|---|
| `name` | merchant | required; 128 characters at most; forbidden characters `<>;=#{}` (`Validate::isCatalogName`) |
| `id_parent` | merchant, from the shop | from `somcp_list_categories`; Home when left out; never the root |
| `active` (Displayed) | merchant | true by default |
| `description` | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office |
| `additional_description` (additional information) | assistant | rich text, cleaned by `Validate::isCleanHtml()` as in the back office |
| `groups` (group access) | merchant, from the shop | ids from `somcp_list_customer_groups`; every group when left out, as in the back office |
| `link_rewrite` | assistant | generated from the name when left out; `Tools::str2url()` then `Validate::isLinkRewrite()`; 128 characters at most |
| `meta_title` | assistant | 70 characters at most |
| `meta_description` | assistant | 150 characters at most |
| `meta_keywords` | assistant | PrestaShop 8 only, refused on 9 with a clear message; 5 keywords at most, comma separated; forbidden characters `<>;=#{}` |
| `cover_image` | merchant | a public `https` URL, downloaded with the guards of `somcp_create_product` |
| `menu_thumbnails` | merchant | up to 3 public `https` URLs, same guards |

**Returns** - `created`, `id_category`, `seo_warnings` (no meta title or description in a language, a `link_rewrite` already used by another category), `images` (per URL: stored or the reason it was refused), and the category as `somcp_get_category` returns it.

**Notes**
- Written through `Category::add()` and the core's category image uploaders (`src/Adapter/Image/Uploader/Category*`, whose names differ between PrestaShop 8 and 9), so hooks fire and thumbnails are generated as in the back office.
- Not replayable: the read tool to check is `somcp_list_categories`.
- The forbidden characters `<>;=#{}` are PrestaShop's own rule for the name and the meta fields (`isCatalogName`, `isGenericName`); the descriptions are rich text and follow `isCleanHtml`, as the back office form does.

#### `somcp_update_category`
**State**: to write · [issue #10](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/10)
**Nature**: `acts(EDIT, 'AdminCategories')` · **Scope**: `mcp.catalog.write`

**Does** - changes one category with everything `somcp_create_category` can write, under the same rules. Only what is given changes.

**Do not use for** - creating one (→ `somcp_create_category`), or only switching it on or off (→ `somcp_set_category_active`).

**Parameters** - `id_category` (required), `languages`, then the fields of `somcp_create_category`, all optional. A text given replaces the languages given and keeps the others; `groups` given replaces the whole set; `menu_thumbnails` given replaces the existing ones, and `remove_cover_image` / `remove_menu_thumbnails` remove explicitly.

**Returns** - `changed`, per field its old and new value, `seo_warnings`, `images`, and the category as `somcp_get_category` returns it.

**Notes**
- The root and Home categories are refused, as in the back office. A category cannot be moved under itself or one of its children.
- `link_rewrite` changed on a displayed category: the old URL stops working, said in `seo_warnings`.

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
**State**: to write · [issue #45](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/45)
**Nature**: `acts(VIEW, 'AdminManufacturers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the brands of this shop: identifier and name, nothing else.

**Do not use for** - everything about one brand (→ `somcp_get_manufacturer`), or the products of a brand (→ `somcp_search_products`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per brand: `id_manufacturer`, `name`. Plus `count`.

**Notes** - same shape as `somcp_list_categories`: the list an assistant reads before a write that takes a brand, which must never guess an identifier. Inactive ones are listed too, since a product can still point to them.

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
**State**: to write · [issue #46](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/46)
**Nature**: `acts(VIEW, 'AdminSuppliers')` · **Scope**: `mcp.catalog.read`

**Does** - lists the suppliers of this shop: identifier and name, nothing else.

**Do not use for** - everything about one supplier (→ `somcp_get_supplier`), or the products of a supplier (→ `somcp_search_products`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per supplier: `id_supplier`, `name`. Plus `count`.

**Notes** - same shape as `somcp_list_categories`: the list an assistant reads before a write that takes a supplier, which must never guess an identifier. Inactive ones are listed too, since a product can still point to them.

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
**State**: to write · [issue #47](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/47)
**Nature**: `acts(VIEW, 'AdminAttributesGroups')` · **Scope**: `mcp.catalog.read`

**Does** - lists the attribute groups (Size, Colour...) and, under each, its values: identifier and name, nothing else. A cascade, like the categories.

**Do not use for** - everything about one group or one value (→ `somcp_get_attribute_group`, `somcp_get_attribute_value`), or the combinations of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_attribute_group` | integer | no | one group only |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per group: `id_attribute_group`, `name`, and its `values`: `id_attribute`, `name`. Plus `count`.

**Notes** - a combination is a set of `id_attribute`, never of names: names are translated and two groups can hold the same value name.

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
**State**: to write · [issue #48](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/48)
**Nature**: `acts(VIEW, 'AdminFeatures')` · **Scope**: `mcp.catalog.read`

**Does** - lists the features (Material, Composition...) and, under each, its predefined values: identifier and name, nothing else. A cascade, like the attributes.

**Do not use for** - everything about one feature or one value (→ `somcp_get_feature`, `somcp_get_feature_value`), or the features of a product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_feature` | integer | no | one feature only |
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per feature: `id_feature`, `name`, and its `values`: `id_feature_value`, `value`. Plus `count`.

**Notes** - predefined values only (`custom` = 0). A custom value typed on one product belongs to that product and is read with `somcp_get_product`.

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
**State**: to write · [issue #49](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/49)
**Nature**: `acts(VIEW, 'AdminAttachments')` · **Scope**: `mcp.catalog.read`

**Does** - lists the files of the shop (Catalogue > Files: manuals, data sheets) that a product can carry, with the columns of the back office list and a download link.

**Do not use for** - the files of one product (→ `somcp_get_product`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `language` | string | no | ISO code, shop default otherwise |

**Returns** - per file: `id_attachment` (ID), `name` (Name), `file_name` (File), `file_size` (Size, in bytes), `products_count` (Products), `mime`, `download_url`. Plus `count`.

**Notes**
- The file itself never travels through the conversation. `download_url` is the storefront link PrestaShop already gives customers on a product page, built with `Link::getPageLink('attachment', true, null, 'id_attachment=N')`; the `attachment` front controller sends the file. The merchant opens it in a browser to download or preview it.
- That link works without being signed in, as on the storefront: anyone holding it can download the file. The description says so, so the assistant does not paste it somewhere public by mistake.
- Columns of `attachment` and `attachment_lang`; `products_count` from `product_attachment`.
- Lists only, never uploads: files do not travel through the conversation.

### Stock

The quantities the storefront sells against, per product and combination.

**Not here**: prices and texts (→ Catalogue).

#### `somcp_get_stock`
**State**: to write · [issue #12](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/12)
**Nature**: `acts(VIEW, 'AdminStockManagement')` · **Scope**: `mcp.stock.read`

**Does** - returns the quantities of one product, per combination.

**Do not use for** - finding what runs low across the catalogue (→ `somcp_list_low_stock`).

**Parameters**

| Name | Type | Required | Role |
|---|---|---|---|
| `id_product` | integer | yes |  |

**Returns** - `quantity` (total), and per combination: `id_product_attribute`, `attributes`, `quantity`, `physical_quantity`, `reserved_quantity`, `low_stock_threshold`.

**Notes** - read from `StockAvailable`, the quantities the storefront sells against.

#### `somcp_list_low_stock`
**State**: to write · [issue #13](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/13)
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

#### `somcp_set_stock_quantity`
**State**: to write · [issue #14](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/14)
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

**Returns** - `changed`, `previous_quantity`, `quantity`.

**Notes** - through `StockAvailable::setQuantity()` / `updateQuantity()`, so stock movements and hooks follow. A delta is not replayable: a lost answer retried would move the stock twice, which the description says, naming `somcp_get_stock` to check.

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
| `somcp-stock-check` | Stock | draft · [issue #37](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/37) |
| `somcp-customer-lookup` | Customers | draft · [issue #38](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/38) |
| `somcp-sales-report` | Statistics | draft · [issue #39](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/39) |
| `somcp-content-pages` | Content | draft · [issue #40](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/40) |
| `somcp-modules-review` | Modules | draft · [issue #41](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/41) |
| `somcp-storefront-menu` | Appearance | draft · [issue #42](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/42) |
| `somcp-cache-maintenance` | System | draft · [issue #43](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/43) |

#### `somcp-catalogue-manage`
**State**: written
**Section**: Catalogue · **Scope**: `mcp.catalog.read` · **Permission**: `view` on `AdminProducts`

**Title** - Manage products

**Description** - Find, read and change products and categories in the PrestaShop shop, one at a time, without guessing an identifier.

**Arguments** - `product` (name, reference or ean13 of a product).

**Tools** - `somcp_ping`, `somcp_search_products`, `somcp_get_product`, `somcp_list_categories`, `somcp_create_product`, `somcp_update_product`, `somcp_set_product_active`, `somcp_delete_product`, `somcp_get_category`, `somcp_create_category`, `somcp_update_category`, `somcp_set_category_active`, `somcp_list_manufacturers`, `somcp_get_manufacturer`, `somcp_create_manufacturer`, `somcp_update_manufacturer`, `somcp_set_manufacturer_active`, `somcp_list_manufacturer_addresses`, `somcp_get_manufacturer_address`, `somcp_create_manufacturer_address`, `somcp_update_manufacturer_address`, `somcp_delete_manufacturer_address`, `somcp_list_suppliers`, `somcp_get_supplier`, `somcp_create_supplier`, `somcp_update_supplier`, `somcp_set_supplier_active`, `somcp_list_attributes`, `somcp_get_attribute_group`, `somcp_create_attribute_group`, `somcp_update_attribute_group`, `somcp_get_attribute_value`, `somcp_create_attribute_value`, `somcp_update_attribute_value`, `somcp_list_features`, `somcp_get_feature`, `somcp_create_feature`, `somcp_update_feature`, `somcp_get_feature_value`, `somcp_create_feature_value`, `somcp_update_feature_value`, `somcp_list_attachments`, `somcp_list_tax_rules`, `somcp_list_customer_groups`. A tool joins the prompt in the pull request that writes it; the prompt ships with the first tool of its section.

**The guide must say** - search before reading, and read before changing; take category identifiers from `somcp_list_categories`, never guess them; say whether a price is tax included or excluded; one product per write, never retried on a lost answer; switching a product off is reversible, deleting it is not, and the merchant is told which is happening.

#### `somcp-stock-check`
**State**: draft · [issue #37](https://github.com/novalo-dev/Prestashop-MCP-Server/issues/37)
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
