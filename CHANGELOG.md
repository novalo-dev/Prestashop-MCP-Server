# Changelog

All notable changes to the O'So MCP Server module are documented in this file.
The format is based on Keep a Changelog.

What is merged but not released yet waits under **Unreleased**, and becomes the
next version's section when it is published. Within a version, entries are
grouped by what they touch rather than by the day they were written. Sections
are:

- **Added** - new features or capabilities.
- **Changed** - changes to how an existing feature behaves.
- **Fixed** - bug fixes.
- **Security** - changes that affect the security of the shop or of the OAuth flow.
- **Removed** - features or options that were taken out.

## Unreleased

### Added

**Product tags.** Creating or updating a product can now fill its tags (the
Étiquettes field), given as a comma separated list per language. They are
written to the shop's own tag field, which is separate from the meta keywords
and exists on every PrestaShop version; the shop creates the tags it does not
have yet. On an update, the tags given replace the product's tags in those
languages, and a language left out keeps the ones it had. Reading a product
now returns its tags as well, so they can be read, changed and written back.

### Changed

**Stock of a product.** Reading a product's stock now also shows who is waiting
on it: alongside the reserved quantity, the orders holding that stock are
listed, each with its number and reference and how many units it holds, for the
product and for each of its combinations.

**Settings page.** The page was one long column of panels. It is now split
into seven tabs, drawn with PrestaShop's own tabs and panels: Configuration,
Tools and prompts, Access and security, Connected assistants, Built-in
assistant, Security log, Help and updates. They stay on one line; on a narrow
screen the bar scrolls sideways instead of wrapping.
The switch that turns the MCP server on and off leads the first tab and saves
as soon as it moves, without a trip to the Save button. The address to give an
assistant now sits in a regular back office field with its Copy button, on
the settings page and on the page shown to employees who may only connect an
assistant. The page remembers the tab you were on after saving. Every setting
keeps its meaning and is saved the same way.

**Logo.** The module has a new logo: a white pulse ending on an orange dot,
on the dark background of the other O'So modules.

### Added

**Product search.** The new tool `somcp_search_products` finds products with
the filters of the back office product list: identifier, name, reference,
category, price without or with tax, quantity, status, EAN13, MPN, UPC and
ISBN. A reference or an EAN13 also finds the product through its combinations,
and says which ones matched. It needs the new permission `mcp.catalog.read`,
which the consent screen describes as reading the catalogue, and the `view`
right on the back office products page.

**Reading a product.** The new tool `somcp_get_product` returns everything the
back office product page shows for one product, tab by tab: texts,
categories, brand, related products, references, features, attachments,
condition, customization, stock, shipping and carriers, prices and tax,
specific prices and their priorities, SEO, options, suppliers, images, and
every combination with its own codes, stock and price impacts. Prices are the
product's own; promotions are listed apart, so one is never taken for the
price. It needs the same permission as product search.

**Listing categories.** The new tool `somcp_list_categories` lists the
categories of the shop the assistant is connected to, with their identifier and
name and nothing else, so the assistant takes a category from the shop rather
than guessing it before searching, creating or changing a product. The root
category is left out; Home is listed. It needs the permission
`mcp.catalog.read` and the `view` right on the back office categories page.

**Reading a category.** The new tool `somcp_get_category` returns everything
the back office category form shows for one category: name, Displayed, parent,
place in the tree, descriptions, the customer groups that see it, friendly URL
and address, meta texts, meta keywords on PrestaShop 8, what the page does
while hidden on PrestaShop 9, cover image, menu thumbnails on PrestaShop 8, and
how many products and subcategories it holds. It tells apart two categories of
the same name by their parent, and gives the values under the names the
category creation and change take. It only reads the shop the assistant is
connected to. It needs the permission `mcp.catalog.read` and the `view` right
on the back office categories page.

**Listing brands.** The new tool `somcp_list_manufacturers` lists the brands
of the shop the assistant is connected to, with their identifier and name and
nothing else, so the assistant takes a brand from the shop rather than guessing
it before creating or changing a product. Brands that are switched off are
listed too, since a product can still be attached to one. It needs the
permission `mcp.catalog.read` and the `view` right on the back office brands
page.

**Reading a brand.** The new tool `somcp_get_manufacturer` returns everything
the back office brand form shows for one brand: name, Enabled, short
description, description, meta title and description, meta keywords on
PrestaShop 8, the logo address, and how many products of the shop belong to
it. A brand without a logo says so rather than showing a placeholder image. It
only reads the shop the assistant is connected to. It needs the permission
`mcp.catalog.read` and the `view` right on the back office brands page.

**Listing suppliers.** The new tool `somcp_list_suppliers` lists the suppliers
of the shop the assistant is connected to, with their identifier and name and
nothing else, so the assistant takes a supplier from the shop rather than
guessing it before creating or changing a product. Suppliers that are switched
off are listed too, since a product can still be attached to one. It needs the
permission `mcp.catalog.read` and the `view` right on the back office suppliers
page.

**Listing attributes.** The new tool `somcp_list_attributes` lists the
attribute groups of the shop the assistant is connected to (Size, Colour...)
and, under each one, its values, with their identifier and name and nothing
else, so the assistant takes the right values from the shop rather than
guessing them before creating or changing the combinations of a product. One
group can be asked for on its own. It needs the permission `mcp.catalog.read`
and the `view` right on the back office attributes page.

**Listing features.** The new tool `somcp_list_features` lists the features
of the shop the assistant is connected to (Composition, Property...) and, under
each one, its predefined values, with their identifier and name and nothing
else, so the assistant takes the right values from the shop rather than
guessing them before creating or changing a product. Values typed on one
product only are left out: they belong to that product. One feature can be
asked for on its own. It needs the permission `mcp.catalog.read` and the
`view` right on the back office features page.

**Reading a feature.** The new tool `somcp_get_feature` returns everything
the back office feature form shows for one feature: name, position, how many
predefined values it holds, and, when the faceted search module is installed,
the word used in filtered URLs, the title tag and whether filtered pages are
indexed. It only reads the shop the assistant is connected to. It needs the
permission `mcp.catalog.read` and the `view` right on the back office features
page.

**Creating a feature.** The new tool `somcp_create_feature` creates one
feature (Composition, Material...) with the fields of the back office feature
form: its name in each language and, when the faceted search module is
installed and enabled, the word used in filtered URLs, the title tag and
whether filtered pages are indexed, which are off unless you say otherwise.
Without that module, those three fields are refused rather than stored for
nothing. The feature is created without values. On a multistore installation
it is created in the shop the assistant is connected to. The consent screen
now says that the catalogue permission can create features. It needs the
permission `mcp.catalog.write` and the `add` right on the back office features
page.

**Listing files.** The new tool `somcp_list_attachments` lists the files of
the shop (Catalogue > Files: manuals, data sheets) with the columns of the back
office list - name, file, size, number of products - so the assistant takes
the right files from the shop rather than guessing them before creating or
changing a product. Each file comes with its storefront download link, the one
customers already see on a product page: the file itself never goes through
the conversation, and anyone holding the link can download it. It needs the
permission `mcp.catalog.read` and the `view` right on the back office files
page.

**Listing tax rules.** The new tool `somcp_list_tax_rules` lists the tax rules
of the shop (Localization > Taxes > Tax rules) with their identifier, name,
whether they are enabled, and the rate each one applies in the shop's default
country, the one the product page shows. The assistant then picks the right
rule from the shop when it creates or changes a product, rather than guessing
its identifier or leaving it to the back office default. It also says when
taxes are switched off in the shop. Disabled rules can be listed too. It needs
the permission `mcp.catalog.read` and the `view` right on the back office tax
rules page.

**Creating a product.** The new tool `somcp_create_product` creates one
complete product, offline by default: everything the product page holds can be
given at once, combinations, specific prices, suppliers and images included,
in every language of the shop or the ones you choose. Every problem is listed
before anything is written, and if writing still fails half way, what was
written is removed: you never end up with a half-made product. Images are
fetched by the shop from public https addresses only; one that cannot be
fetched is reported without stopping the creation. It needs the new
permission `mcp.catalog.write`, which the consent screen describes as changing
the catalogue, and the `add` right on the back office products page.

**Changing a product.** The new tool `somcp_update_product` changes one
existing product with everything the creation can write, under the same names
and with the same rules: texts, categories, prices, stock, SEO, suppliers,
specific prices, images and combinations. Only what you give changes; a text
given in one language leaves the others as they are, and combinations, images,
specific prices and suppliers are only removed when you name them. The answer
lists every field changed with its old and new value, and a call that would
change nothing writes nothing. Every problem is listed before anything is
written; if writing still fails, the answer says which parts were already
saved. It needs the permission `mcp.catalog.write` and the `edit` right on the
back office products page.

**Switching a product on or off.** The new tool `somcp_set_product_active`
puts one product online or takes it offline, like the Status toggle of the back
office product list, and changes nothing else. It only acts on the shop the
assistant is connected to; on a multistore installation the other shops keep
their own status. Asking for the status the product already has writes nothing.
A product without a name in the shop's default language cannot be put online.
It needs the permission `mcp.catalog.write` and the `edit` right on the back
office products page.

**Deleting a product.** The new tool `somcp_delete_product` deletes one product
with its combinations, images and stock, as the back office does. Orders that
contain it keep their lines as sold. The assistant is told to ask you first,
naming the product by its name and reference, and to offer switching it off
instead. On a multistore installation it only leaves the shop the assistant is
connected to, and the answer says how many other shops still carry it. The
consent screen now says that the catalogue permission can delete products. It
needs the permission `mcp.catalog.write` and the `delete` right on the back
office products page.

**Creating a category.** The new tool `somcp_create_category` creates one
category with the fields of the back office category form: name, parent,
Displayed, descriptions, customer groups, friendly URL, meta title and
description, meta keywords on PrestaShop 8, what the page does while hidden on
PrestaShop 9, a cover image and, on PrestaShop 8, menu thumbnails. It goes under
Home and is displayed unless you say otherwise, and is visible to every customer
group when none is chosen. Images are given by a public https address and
downloaded by the shop with the same safeguards as product images; one that
cannot be fetched is reported and does not stop the creation. The answer warns
when a meta title or description is missing, or when another category already
uses the friendly URL. On a multistore installation the category is created in
the shop the assistant is connected to. The consent screen now says that the
catalogue permission can create categories. It needs the permission
`mcp.catalog.write` and the `add` right on the back office categories page.

**Changing a category.** The new tool `somcp_update_category` changes one
existing category with everything the creation can write: name, parent,
Displayed, descriptions, customer groups, friendly URL, meta texts, meta
keywords on PrestaShop 8, what the page does while hidden on PrestaShop 9, the
cover image and, on PrestaShop 8, the menu thumbnails. Only what you ask for
changes, and the answer lists each change with its old and new value; a request
that would change nothing changes nothing. The friendly URL is never rebuilt
from a new name, and the answer warns when a displayed category's address
changes. The root category and Home are left to the back office, and a category
cannot be moved under one of its own subcategories. On a multistore
installation only the shop the assistant is connected to is changed. The
consent screen now says that the catalogue permission can edit categories. It
needs the permission `mcp.catalog.write` and the `edit` right on the back office
categories page.

**Showing or hiding a category.** The new tool `somcp_set_category_active`
shows or hides one category on the storefront, like the Displayed toggle of the
back office category list, and changes nothing else. Its products stay online:
they can still be found through search and their other categories, and the
assistant is told to say so. Asking for the state the category already has
writes nothing. The root category and Home cannot be hidden. PrestaShop keeps
Displayed once for all shops, so on a multistore installation the change shows
in every shop sharing the category, as in the back office. It needs the
permission `mcp.catalog.write` and the `edit` right on the back office
categories page.

**Creating a brand.** The new tool `somcp_create_manufacturer` creates one
brand with the fields of the back office brand form: name, short description,
description, meta title and description, meta keywords on PrestaShop 8, logo
and Enabled. It is enabled unless you say otherwise. The logo is given by a
public https address and downloaded by the shop with the same safeguards as
product images; one that cannot be fetched is reported and does not stop the
creation. The answer warns when a meta title or description is missing. On a
multistore installation the brand is created in the shop the assistant is
connected to. The consent screen now says that the catalogue permission can
create brands. It needs the permission `mcp.catalog.write` and the `add` right
on the back office brands page.

**Changing a brand.** The new tool `somcp_update_manufacturer` changes one
existing brand with everything the creation can write: name, short
description, description, meta title and description, meta keywords on
PrestaShop 8, logo and Enabled. Only what you ask for changes, and the answer
lists each change with its old and new value; a request that would change
nothing changes nothing. A new logo replaces the current one, which is kept if
the new one cannot be fetched, and the logo can also be removed. On a
multistore installation only the shop the assistant is connected to is changed.
The consent screen now says that the catalogue permission can edit brands. It
needs the permission `mcp.catalog.write` and the `edit` right on the back
office brands page.

**Reading the stock of a product.** The new tool `somcp_get_stock` gives the
stock of one product as the storefront sells against it: the quantity that can
still be sold, the physical quantity and the quantity held by orders not shipped
yet, with the low stock alert level when one is set. A product with
combinations comes with each combination, named by its attributes (Size: M),
and its own quantities. It gives the same quantities as reading the product. It
needs the new permission `mcp.stock.read`, which the consent screen describes
as reading the stock, and the `view` right on the back office Stock page.

**Listing what runs low.** The new tool `somcp_list_low_stock` lists the
products and combinations whose quantity left to sell is at or under a level,
lowest first. Without a level given, each one is compared with the low stock
alert level the merchant set on it, and one without an alert level shows only
once it reaches zero. Each combination has its own line, so a size that is gone
shows even when the product as a whole still has stock. Only active products by
default. It needs the permission `mcp.stock.read` and the `view` right on the
back office Stock page.

**Setting the stock of a product.** The new tool `somcp_set_stock_quantity`
changes the quantity the storefront sells against, for one product without
combinations or for one combination, as the quantity field of the back office
Stock page does: either to a new level, or by a movement such as -3 or +10.
Each change is recorded as a stock movement in the name of the employee who
connected the assistant, and the physical quantity follows. A product with
combinations is changed one combination at a time, and a pack whose stock comes
from its products is left to them. Asking for the quantity a product already
has changes nothing. It needs the new permission `mcp.stock.write`, which the
consent screen describes as changing the stock, and the `edit` right on the
back office Stock page.

**Check and adjust stock prompt.** A guided task for stock, picked from the
assistant's menu: find the product before reading its stock, say which quantity
is meant, name a combination by its attributes, and prefer a new level to a
movement, which is never repeated on a lost answer.

**Manage products prompt.** A guided task for the catalogue, picked from the
assistant's menu: search before reading, never guess an identifier, always say
whether a price is with or without tax.

**Feedback and source code.** A card in the Help and updates tab leads to the
module's GitHub repository, to report a problem, suggest a tool or read the
code.

**Built-in assistant.** A chat button now sits at the top right of every back
office page. It opens a conversation with the AI you connect in the new Built-in
assistant tab, with your own API key: Claude, ChatGPT, Gemini, Mistral,
DeepSeek, Grok or Cohere. For each one you pick a
level - economy, balanced or powerful - and the module always uses the newest
model of that level, without an update. A Custom level is there too, to pick the
exact model yourself from the list your provider offers, chosen from a drop-down
rather than typed. The assistant uses the same tools as
assistants connected over MCP, with the rights of the employee signed in, and
every change waits for you to click Approve. With several assistants switched
on, give each a role: the default one hands them the work of their role and
uses their answer. A monthly spending cap, estimated in US dollars from the
providers' public prices, stops the chat when it is reached, and shop-wide
instructions are added to every conversation. Files from your computer -
images, PDF and text files such as CSV, up to 5 MB each - can be attached to a
message. They are kept on your shop for a week, checked like any deposited
file, and a tool that takes a file can use them directly. The assistant sees
an image or a PDF through a private link that expires, when your shop is
reachable from the internet and the provider reads links; otherwise the file
itself is sent. An image an assistant generates - Gemini or OpenAI with an
image model, often the one given the images role - is kept the same way: shown
in the chat, and usable as the image of a product. Gemini and ChatGPT also offer
an Images level, which picks their newest image model by itself; an assistant at
that level never leads the chat, the default one asks it for pictures. The chat
opens from an Assistant link beside View my shop, can be enlarged, has a gear
that opens the module settings in a new tab, and its message box holds the
field, the files and the buttons together, with a reminder that the answers
come from an AI. Keys are stored encrypted.
Conversations are kept per employee: the menu in the chat slides out the list,
each with a bin to delete it. The setting "Employees allowed to use the built-in
assistant" chooses who sees the chat, separately from who may connect an
outside assistant; administrators always may. The assistant knows which back
office page the chat is open on, your PrestaShop version and the module
version, with links to the matching source and documentation, and the role of
every assistant switched on. When a key is tested or saved and the provider
refuses it, the message is now shown in red, not green, and the assistant is
switched off so the chat never opens onto a key that does not work; it comes
back on once a working key is saved. On a multistore install everything about
the assistant belongs to the shop you are on - its assistants and keys, budget,
instructions, allowed profile, spending and conversations are kept apart from
the other shops'. A long conversation no longer resends everything on every
turn: past a point the oldest messages are condensed into a summary, so it keeps
its thread without the cost climbing. When the assistant hands a task to another
of your assistants, the step now names which one it asked. A long-term memory
can be switched on: the assistant then remembers past conversations and recalls
the relevant ones when you ask something related, instead of starting blank,
and each employee's conversations stay their own. It is off until you turn it on
and pick an embeddings provider on the settings page, since embeddings are
billed. A provider you already use for the chat (OpenAI, Gemini, Mistral or
Cohere) lends its key, so nothing is asked twice; Voyage, which does embeddings
only, takes a key of its own. Each uses a sensible model by itself, and the
conversations are embedded and kept on your shop's own disk. Runs on Neuron AI.

**Product images from a file on the shop.** Creating or changing a product now
accepts, for each image, either a public address to download or the reference
of a file already on your shop: one you attached in the built-in assistant or
deposited, or an image an assistant generated.

**New version alert.** The Help and updates tab shows the installed version
and the latest one published on GitHub. When a newer version exists, the tab
title shows its number and the tab lists the main changes, with a button to
download the zip and the steps to install it from the Module Manager, which
keeps your settings and connected assistants. The module asks GitHub at most
once a day, only while an administrator has the settings page open, and a
Check now button asks again on demand. It never downloads or installs anything
by itself. A switch in the same tab turns the check off.

## 1.0.0 - Initial version

### Added

**MCP endpoint.** Reachable at `/module/somcp/mcp`, answering JSON-RPC over HTTP
POST. It speaks the current stateless revision of the protocol, `2026-07-28`, and
still answers the older `initialize` handshake used by `2025-11-25`, `2025-06-18`
and `2025-03-26`, because most assistants shipping today have not moved yet.
`server/discover`, `tools/list`, `tools/call` and `ping` are implemented.

**Server identity.** The server tells assistants who it is: its name and title,
the installed version read from the module itself, what it does, the address of
its publisher, and an icon - a static file served by the shop, from that shop's
own domain, needing no session, token or consent to fetch. The publisher's
website is stated as the publisher's, never as the shop's.

**OAuth 2.1 authorization server.** Authorization code with PKCE (S256 only),
refresh tokens with rotation and reuse detection, token revocation. Clients
identify themselves by Client ID Metadata Document, which is what the Claude
assistants use, with Dynamic Client Registration kept as a fallback for the ones
that only know that. Both can be turned off.

**Discovery.** Protected resource metadata (RFC 9728) and authorization server
metadata (RFC 8414), the latter served from the shop root as the standard
requires.

**Consent screen in the back office.** The merchant signs in with their usual
PrestaShop account and sees which assistant is asking, for which permissions, on
which shop. A token never carries more rights than the employee who granted it.

**Tools.** One file per tool in `tools/`, found by reading the folder: adding a
tool is adding a file. A tool declares what it does once, with the same four
words PrestaShop uses for its own permissions - view, add, edit, delete - and the
module takes from it both the permission the employee must hold and what the
assistant is told about the call. Only reading is repeatable and passes without a
question; everything that writes asks the merchant first and is never retried on
its own.

**Reaching one client's own feature.** A tool can carry the metadata a
particular assistant looks for - `openai/fileParams`, for one, without which
ChatGPT never fills a file parameter. The protocol keeps `_meta` for exactly
this, and a client that does not know a key ignores it, so a key meant for one
assistant costs the others nothing. The module publishes nothing there itself:
it carries what a tool declares, which is how a module added later reaches a
client feature without this one having to learn about it first. A key whose
prefix is reserved for the protocol is refused and written to the log, because
a module publishing under it would be putting words in a client's mouth from
inside a tool description.

**Prompts.** A prompt is a guided task the merchant starts from the
assistant's menu - how to find an order before reading one, why a status is
picked by the part it plays and not by its name, why a status change is never
repeated on a lost answer. Three ship with the module: checking the connection,
finding and reading orders, and changing a status, the last two with optional
fields for the order and the status. They cost nothing until picked, which is
why they were preferred both to the Skills extension, which no assistant reads,
and to the server instructions, which every conversation would pay for.

A prompt is filtered like the tools it talks about - a guide to changing an
order status is worthless to an account that may not change one - and the
merchant can switch each one off. A prompt published by another module puts
that module's words into the merchant's assistant, and that decision belongs to
the merchant.

**Tools from other modules.** Another module of the shop publishes its own tools
through a hook, and the name it chose is prefixed with that module's folder. It
does not get a say in this: without it, two modules that both thought of
`get_tracking` would cancel each other out silently, and a third party could
publish something called `somcp_get_order` that an assistant and a merchant
would both read as ours. The hook is fired once per module rather than once for
all of them, so a module that fails takes only its own tools down.

**A permission of their own.** A module publishing tools declares the permission
that goes with them rather than borrowing one of ours, prefixed with its folder
like its tool names are, and with the label the merchant reads on the consent
screen. Someone who let an assistant read their orders has not let it read their
customers' complaints, and the screen has to be able to ask the two questions
apart. A tool asking for a permission nobody declared is now refused when it
registers, and the refusal written to the log - it used to be accepted and then
unreachable forever, never offered at consent, so never in a token, so never
listed.

**How long an assistant keeps the list of tools.** A setting, per shop, between
ten seconds and an hour, five minutes by default. The server is stateless: no
connection stays open to an assistant, so the shop cannot tell it that the list
changed and it re-reads only when the delay it was given has run out. A tool
switched on this morning appears then, not before - which is why the only way to
see a new tool straight away used to be disconnecting the assistant and
connecting it again. Lower while tools are being written, back up in production
where the list barely moves.

**Switching a tool off.** Every tool the shop could offer is listed in the module
settings with a switch, and the merchant decides which ones an assistant may
reach - per shop, inherited like every other setting. A merchant who installs a
module publishing eight tools and wants two of them had no way of saying so short
of uninstalling it. A tool switched off is offered to nobody whatever their
account holds, is refused if an assistant calls it from a cached listing, and
stops making its permission worth granting on the consent screen.

**Everything a merchant reads, in their own language.** A tool's title is
translated rather than left in English - in the settings, and in the assistant's
own interface, which is what that field is for. Its description stays in
English, because that one is read by the model and not by a person. The
permission a tool needs is shown with the name of the page as the merchant's own
menu names it, taken from PrestaShop's tab rather than from a list kept here, so
a renamed or translated menu entry follows by itself. The security log keeps the
controller name, which is what is wanted while diagnosing.

**Permission filtering.** An assistant is only offered the tools the employee
behind it may use, so a bookkeeper with no right to delete products is never told
that deleting products is on offer. The permission is checked again when the tool
is called, because an assistant keeps the list of tools for minutes.

**Orders, reading.** `somcp_search_orders` finds orders by reference, customer,
date, status, amount or payment method, and returns one plain line each -
reference, customer, phone, amount paid, payment method, status. `somcp_get_order`
returns everything about one order, identified by its identifier or its
reference: customer, both addresses, product lines, discounts, carrier and
tracking, payments, status and how it got there, invoices and credit slips,
messages and totals. Finding is one tool and reading is another, so an assistant
handed "Dupont's March orders" cannot be left to pick one of twelve.

**Order statuses.** `somcp_list_order_statuses` lists the statuses a shop
actually has, with their identifiers and what each one sets off - paid, shipped,
delivery, invoice, whether reaching it emails the customer and whether that mail
carries the invoice or the delivery slip. Identifiers are not the same from one
shop to the next, and an assistant left without this tool guesses: harmless while
everything is read-only, and the wrong mail to a customer the day a tool changes
a status.

Each status also says which part it plays in the shop - shipped, delivered,
refunded, payment accepted, and eleven others - read from the shop's own
configuration rather than from the status name. A merchant renames "Shipped" and
the name stops telling anyone anything; the part it plays does not move. That is
what lets an assistant asked to mark an order shipped find the right status
instead of matching a translated label, and it is the last place the write tool
could have been left to guess. A status the merchant created plays no part and
says so.

**Orders, writing.** `somcp_set_order_status` changes the status of an order,
with everything the shop attaches to that status - the customer email, the stock
movement, the invoice, the refund. It follows the back office's own sequence so
that every hook fires and the other modules of the shop follow, and the order
history carries the name of the account behind the connection rather than an
anonymous line.

One order per call, deliberately. The back office can move a selection at once,
but an assistant handed that would turn one confirmation into thirty emails, and
a batch that half fails cannot be reported honestly. An assistant with ten orders
to move calls ten times, and the merchant is asked ten times.

A status the order already has is refused, without sending anything. That is the
guard against the likeliest way this tool could do harm: a call whose answer was
lost, repeated, mailing the customer twice.

**`somcp_ping`.** It tests that the connection works and
says what it reached - the shop, the PrestaShop version, the languages, currency
and time zone, the account it acts under, and on a multistore installation the
shops it cannot reach. It exists so the whole chain can be proven before the
tools that touch products, orders and customers are written.

**Multistore.** The settings page follows the store selector: all stores, a group
or a single store decides which addresses, which connected assistants and which
log entries are shown. Each store shows its own MCP address, built from its own
domain. Every setting says where its value comes from - set here, inherited from
the group, inherited from all stores, or the module default - and can be given a
value of its own or returned to what it inherits.

**Diagnostic.** Calls one named store's own addresses from the outside and
reports what an assistant would find: friendly URLs, discovery documents, the
challenge sent on an unauthenticated call, whether the `Authorization` header
survives the hosting, whether the store is enabled and open. A store picker
appears when several are in scope.

**Security log.** The events that matter - client registration, consent, tokens
issued, refreshed, revoked or refused - kept for thirty days, filtered by the
store selector.

**`docs/TOOLS.md`.** Where a tool is decided before it is written: the
conventions that apply to every tool, and one entry per tool saying what it does,
when an assistant should reach for another one, what it requires and what it
returns.

### Security

- Access tokens, refresh tokens and authorization codes are stored hashed. The
  clear value exists only in the HTTP response that carries it, and never in the
  log.
- Redirect URIs are matched exactly. The single exception is the loopback address
  used by desktop and command-line assistants, where the port may vary but the
  scheme, the host and the path must still match.
- Client metadata documents are fetched over HTTPS only, without following
  redirects, and never towards a private or reserved address.
- Everything OAuth is bound to the store that issued it: an authorization code, a
  refresh token or a revocation presented to another store is refused, and a code
  whose audience is another store is refused even when the caller claims
  otherwise. Withdrawing a consent stops at the store it was given for - the same
  employee approving the same assistant on another store made a separate
  decision, and it stays.
- A prompt is fetched by its name, never by a path. The file behind it is fixed
  when it is registered, and one declared by another module must resolve inside
  that module's own folder - a declaration pointing at the shop's configuration
  is refused rather than served as a guide.
- The account behind a token is checked on every call, before the list of
  permissions a tool needs and whatever is in it. An empty list has nothing to
  refuse, so a tool asking for no permission stayed reachable by a token whose
  employee had since been deleted or disabled - until that token expired, weeks
  later. The account has to be usable first.
- Permissions are read from the shop's own table, never from a list kept in the
  module. An unknown page or action is refused rather than assumed open, so a
  tool naming a page that does not exist disappears instead of opening. An
  account that has been disabled or deleted holds nothing, whatever its profile
  still says.
- The module settings are reserved for an administrator of the shop, checked
  before the forms are read so that rebuilding one by hand changes nothing
  either. Connecting an assistant remains open to the profiles the administrator
  allowed.
- The consent screen offers what the employee could actually put to use, not
  only what the assistant asked for. A client that knows how to ask for a
  session and nothing else would otherwise come away with a token reaching no
  tool, and the merchant would have to disconnect and reconnect it every time
  the shop gains a permission. Nothing is offered that the employee does not
  hold themselves: the list comes from the tools their own back office rights
  let them use, so an account with no right to read orders is never offered the
  permission to read them.
- The answer a shop gives an assistant that arrives without a token names every
  permission the server understands, not only the one that opens a session. A
  client asks for what it is told to ask for, so announcing less left it holding
  a token that could reach no tool, and the merchant looking at a consent screen
  offering one permission where the shop had several.
- Changing orders is a permission of its own, granted apart from reading them.
  A merchant who lets an assistant read what came in yesterday has not let it
  email their customers, and the consent screen says which of the two is being
  asked for.
- A write is never reported as having partly happened. When a status changes but
  the customer email fails, the answer says exactly that and tells the assistant
  not to repeat the call - the change is already recorded, and a second call
  would mail the customer again.
- Tool annotations are hints for display and for retrying, as the specification
  calls them. What a tool may do still comes from the scopes the merchant granted
  and the permissions the employee holds, checked on every call.

### Notes on the environment

Found the hard way, worth keeping:

- PrestaShop 9 serves every legacy back office page through one route and demands
  a Symfony token on it, which a shop front controller cannot produce. The
  authorization flow therefore enters the back office through a route of the
  module's own, which PrestaShop authenticates the same way it authenticates
  everything else. PrestaShop 8 keeps the legacy address: its own listener lets
  those through, and its legacy pages never reach the Symfony router.
- A module's `config/services.yml` is compiled on every PrestaShop, whether its
  routes are used there or not. A service whose constructor asks for a class
  only PrestaShop 9 has stops PrestaShop 8 from building its container, and the
  whole back office answers 500. The entry controllers therefore take nothing
  and look the PrestaShop 9 token up when they run.
- The back office answers on every store domain, but a session belongs to one
  domain. The flow stays on the domain the browser is already on.
- Maintenance mode answers 503 before the module is reached, so the MCP addresses
  opt out of it: maintenance hides the storefront from visitors, and these
  addresses are the merchant's own.
- `Db::insert()` with the null-values flag turns every empty string into NULL,
  not only nulls.
- PrestaShop's own redirect helpers are replaced when profiling is enabled, by a
  version that hands the URL to the current controller and does not stop the
  script. The module sends its own redirects.
- A module's route file is `config/routes.yml`, and PrestaShop adds the
  `/modules` prefix itself.
