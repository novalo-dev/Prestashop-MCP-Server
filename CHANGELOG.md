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

**Compact mode.** More than a hundred tools weigh on every request a connected
assistant makes, and some cap the count - VS Code stops at 128. In compact
mode, inspired by the MCP servers of Cloudflare and Azure, connected
assistants receive four tools instead of the whole list: `somcp_ping`,
`somcp_search_tools` to find the tool they need and read its parameters, then
`somcp_run_read_tool` or `somcp_run_write_tool` to run it - the second one
flagged so the assistant asks you before a change. The same permissions, the
same switches and the same checks apply to every call, and a guide (prompt)
now starts by telling the assistant which runner each of its tools goes
through. Turn it on or off in **Tools and prompts › Settings › Compact list of
tools**: on for a new shop, off for a shop that updates. An assistant on the
latest version of the protocol follows the change once its copy of the list
expires; the others, ChatGPT among them today, once you refresh their tools
in their own settings. The built-in assistant is not concerned.

**Orders created from the assistant.** The new tool `somcp_create_order`
creates an order for an existing customer, as **Add new order** then **Create
order** do in the back office: the products and combinations, the delivery and
invoice addresses, the carrier, free shipping, recycled packaging and gift, the
currency and language, the order message, the payment and the order status.
Every mistake comes back in one answer before anything is written, and a call
refused once the cart is built leaves no cart behind. It sends the emails a
new order sends for the status chosen, so the assistant shows you the lines,
the carrier, the total and the status first. Vouchers and changing the price
of a line are not part of it yet. It needs the **add** right on **Orders**.

**Invoices, delivery slips and credit slips handed over.** The new tool
`somcp_get_order_document` gives you a link to the PDF of an invoice, a
delivery slip or a credit slip of an order, the same document **View
invoice**, **View delivery slip** and the **Documents** tab open. The file
never goes through the assistant: the link opens it in your own signed-in back
office, for you only, and expires after 15 minutes. It needs the **view** right
on **Orders**. `somcp_get_order` now also gives the number of each credit slip,
as the **Documents** tab shows it.

**Order statuses the assistant can delete.** The new tool
`somcp_delete_order_status` removes an order status you created, as the
**Delete** action of **Shop Parameters > Order Settings > Statuses** does. The
orders already on it keep it in their history; the status just leaves the list
and the choices when you change an order. The statuses of PrestaShop and of
your modules cannot be deleted, as in the back office. It needs the **delete**
right on **Statuses**.

### Fixed

**Products and shop pages read in the shop's default language.**
`somcp_get_product`, `somcp_search_products` and `somcp_list_meta_pages` now
read in the shop's default language when no language is given, rather than in
a language picked from the assistant's browser settings, and say in their
answer which language the texts are in. A language the shop does not have is
refused with the list of the ones it has, as the SEO audits now do too. A
product or a category created with texts in some languages only fills the
others from the default language of the shop being written to.

**Test the connection can no longer be switched off.** The Connection group of
**Tools and prompts** said **Always on**, yet `somcp_ping` under it kept two
switches that did switch it off, and a connected assistant could no longer
check which shop and account it had reached. Its row now says **Always on**
like its group, and the tool stays on even where it had been switched off.

**Order statuses created with an email the shop can send, read back as the
list shows them.** `somcp_create_order_status` only accepted the email
templates of the shop's `mails/` folder, and refused those of your theme. It
now accepts every email an order status can send - the theme's, its parent
theme's and the shop's own - and refuses the emails of modules that
PrestaShop 9 lists in the form, saying why: a status set to one would send
your customers nothing. Its boxes and its template now take the names
`somcp_list_order_statuses` shows (`counts_as_valid`, `hidden_from_customer`,
`sends_email`, `attaches_invoice_pdf`, `attaches_delivery_slip_pdf`,
`email_template`), so a status read in the list can be given back; the former
names still work. Its answer is now built exactly as the list builds a row,
`status_id` included.

**The help of How long an assistant keeps the list of tools tells the
truth.** It said every assistant reads the list again once this delay has run
out. Only the assistants on the latest version of the protocol do; the
others, ChatGPT among them today, keep their list until you refresh their
tools in their own settings, and the help now says so.

### Security

**The built-in assistant runs on Neuron AI 4.1.22.** When an AI service
answered with a redirect written in a shortened form (`http:/` followed by
another site), the engine of the built-in assistant took it for the same
service and followed it, with your API key. It now treats it as another site
and does not send the key there. Nothing changes in how you use the assistant.

## 1.4.0 - 2026-11-10

### Added

**Private documents handed over safely.** When the assistant gives you a
private document, it hands over a link that opens in your own back office,
signed in - never the file itself in the conversation. The link works only for
the employee it was asked for, and only for fifteen minutes, after which you ask
again. This adds a hidden back office page to the module, so a shop upgrading in
place gains it on upgrade.

**Order currency changed.** The new tool `somcp_set_order_currency` changes
the currency of an order that is not validated yet, as **Change currency** in
the **Payment** block of the order page does: every amount of the order, its
lines, its shipping and its invoices is converted at the shop's exchange rates
of today, and the answer gives the totals before and after. A validated order
is refused, as the back office refuses it, and asking for the currency the
order already has changes nothing.

**A note on an invoice.** The new tool `somcp_set_invoice_note` sets the note
printed on one invoice of an order, as **Add note** / **Edit note** on an
invoice line of the **Documents** tab of the order page does. It is the note
the customer reads on the invoice PDF, not the order's private note the shop
keeps to itself; an empty note clears it. `somcp_get_order` now shows the note
of each invoice, so the change can be read back.

**Order product line edited.** The new tool `somcp_update_order_product`
changes the unit price, the quantity or the invoice of one product line of an
order not delivered, as the **Edit** action of a line in the **Products** block
of the order page does, and recomputes the totals. Only the fields given are
changed; a quantity of 0 is refused and points to removing the line, and the
assistant is told to say the new total before the change. On PrestaShop 9.2 and
later, which edit the line together with its shipments, the tool refuses and
points to the Edit action of the order page.

**Order product line removed.** The new tool `somcp_delete_order_product`
takes one product line off an order not delivered, as the **Delete** action of
a line in the **Products** block of the order page does, and recomputes the
totals. Nothing is refunded and no credit slip is made: the assistant is told to
say what the customer owes afterwards and to wait for your go-ahead. The last
line of an order is refused, since an order left without a product cannot be
put right; cancel the order instead. On PrestaShop 9.2 and later the tool
refuses and points to the order page, like the other order line tools. The tools
that add, edit and cancel order lines now point to one another when the request
belongs to another of them.

**Order address changed.** The new tool `somcp_set_order_address` points the
delivery or the invoice address of an order to another address of the same
customer, as **Select another address** of the order page does. PrestaShop
recomputes the taxes and the totals - a new country can change the tax - and
the assistant is told to say the new total before the change. An address that
is not the customer's is refused with the list of the ones that are.

**Order carrier changed.** The new tool `somcp_set_order_carrier` changes the
carrier of a shipment of an order, as the **Carrier** field of the **Edit
shipping details** window of the order page does. PrestaShop recomputes the
shipping cost and the totals, and the assistant is told to say the new total
before the change. Only the carriers the order may use are taken: any other is
refused with the list of the ones that are. The tracking number is left as it
is and no email is sent. `somcp_get_order` now gives the `id_carrier` of each
shipment, so the assistant can tell whether a carrier is already in place.

**Order status email resent.** The new tool `somcp_resend_order_status_email`
sends again the email of one past status of an order, as **Resend email** on a
line of the **Status** tab of the order page does. The status line is taken from
the order history, and the status is read from it; a status the shop does not
email is refused rather than reported as sent. Every call emails the customer
and the order records nothing, so the assistant is told to send it once.

**Order discounts.** The new tool `somcp_add_order_discount` adds a discount to
an order, as the **Add a discount** button of the order page does: a
percentage, an amount including taxes, or free shipping, on the whole order or
on one of its invoices. The order totals follow, and the assistant is told to
say the new total before adding it.

**Order discounts removed.** The new tool `somcp_delete_order_discount` removes
a discount from an order, as the delete action of the discounts table of the
order page does. The order totals follow, a free gift product given by the
discount loses its unit, and the assistant is told to say the new total before
removing it. A discount already removed is reported as such, and nothing
changes.

**Invoices generated.** The new tool `somcp_generate_order_invoice` generates
the invoice of an order that has none yet, as the **Generate invoice** button of
the order page does: the invoice takes the shop's next number, and nothing is
emailed. An order that already has one gets it back instead of a second
invoice, and a shop with invoices turned off is told where to turn them on.

**Cancelled products.** The new tool `somcp_cancel_order_products` takes
products off an order that is not paid yet, as the **Cancel products** button
of the order page does: the chosen quantities are removed, the order total and
the stock follow, and when no product is left the order goes to Canceled and
the customer is emailed. A paid or delivered order, or a shop with merchandise
returns off, is refused with the tool to use instead.

**Products added to an order.** The new tool `somcp_add_order_product` adds a
product, or one of its combinations, to an order that is not shipped yet, as
**Add a product** in the Products block of the order page does: the order total
is recomputed, and on an invoiced order the line goes to the invoice you name
or to a new one, with free shipping when asked. Left out, the price is the
product's price for that customer. A shipped order, a product already in the
order or a quantity the stock cannot cover is refused with the reason. On
PrestaShop 9.2 and later, which add the product together with its shipments,
the tool refuses and points to the button of the order page.

**Returned products.** The new tool `somcp_return_order_products` records the
products a customer sent back on a delivered order and refunds them, as the
**Return products** button of the order page does: the chosen quantities are
counted as returned, go back in stock only when you say so, the shipping is
refunded when asked, with a credit slip, a voucher or both. When merchandise
returns are off or the order is not delivered yet, the refusal says which tool
to use instead.

**Standard refunds.** The new tool `somcp_issue_standard_refund` pays back
whole lines of a paid order that is not delivered yet, as the **Standard
refund** button of the order page does: the chosen quantities go back in
stock, the shipping is refunded when asked, with a credit slip, a voucher or
both. When merchandise returns are off or the order is already delivered, the
refusal says which tool to use instead.

**Partial refunds.** The new tool `somcp_issue_partial_refund` pays back part
of a paid order, as the **Partial refund** button of the order page does: some
units of a line, part of a line's price, part of the shipping, with a credit
slip, a voucher or both. Each amount is given in the tax mode the order page
shows, a line that is not one of the order's or an amount above what is left
is refused instead of being lowered, and the answer gives the credit slip and
the voucher created.

**Create a catalogue price rule.** The new tool
`somcp_create_catalog_price_rule` creates one rule of
**Catalog > Discounts > Catalog Price Rules**, with everything the form holds:
name, currency, country, customer group, from quantity, a fixed price or the
products' own, the reduction as an amount or a percentage, tax included or not,
the period, and the condition groups that pick its products. The rule is
applied at once, and the answer says how many products it reaches. A
percentage is given as the same fraction the product tools use, and the price
and reduction are checked exactly as for a product's own specific prices.

**List the catalogue price rules.** The new tool
`somcp_list_catalog_price_rules` reads the rules of
**Catalog > Discounts > Catalog Price Rules** - the discounts applied to
products before the cart - as the list shows them: name, currency, country,
customer group, from quantity, reduction and period, with the conditions that
pick each rule's products. It filters by currency, country, group, reduction
type, and by the rules in force right now, and reads a percentage as the same
fraction the product tools report. Read only.

**Change a voucher or a cart discount.** The new tool `somcp_update_cart_rule`
changes one rule of **Catalog > Discounts > Cart Rules** with everything
`somcp_create_cart_rule` can write: the name in the languages given, the code,
the dates, the quantities, the minimum amount, free shipping, the discount and
what it applies to, and the gift. Only what is given changes, and the answer
lists each change with its old and new value; a call that would change nothing
writes nothing. It refuses what the back office refuses, checked on the rule as
it will be, so a start date after the end date kept is refused. The rule's
countries, carriers, groups, compatible rules, product selection and shops are
left exactly as they were, and switching it on or off is left to the tool below.

**Switch a voucher or a cart discount on or off.** The new tool
`somcp_set_cart_rule_active` is the **Status** switch of the list
**Catalog > Discounts > Cart Rules**: it switches one rule on or off and
changes nothing else, so a promotion can be stopped early and started again;
orders that already used the rule keep their discount. A rule already in that
state is left untouched. When a rule is switched on but cannot apply as
expected - its dates are over or still to come, nothing is left of **Total
available**, or it has no code and so applies on its own to every cart - the
answer says so.

### Changed

**Categories, brands, suppliers and stock read in a language the shop has.**
`somcp_list_categories`, `somcp_get_category`, `somcp_get_manufacturer`,
`somcp_get_supplier` and `somcp_get_stock` now read in the shop's default
language when no language is given, rather than in a language picked from the
assistant's browser settings, and say in their answer which language the texts
are in. A language the shop does not have is refused with the list of the ones
it has, instead of quietly answering in another one.

**Attributes and features read in a language the shop has.** The tools that read
attribute groups, attribute values, features and feature values
(`somcp_list_attributes`, `somcp_get_attribute_group`,
`somcp_get_attribute_value`, `somcp_list_features`, `somcp_get_feature`,
`somcp_get_feature_value`) now read in the shop's default language when no
language is given, rather than in a language picked from the assistant's
browser settings, and say in their answer which language the texts are in. A
language the shop does not have is refused with the list of the ones it has,
instead of quietly answering in another language that the assistant could then
write back under the wrong one.

**One name for the order status in every order and customer tool.**
`somcp_get_order`, `somcp_set_order_status` and the latest orders of
`somcp_get_customer` now give the status as `status_id`, the name
`somcp_set_order_status` takes and `somcp_list_order_statuses` returns, so the
assistant hands it on without guessing. The answer key changes: it was `id`
(`id_order_state` in `somcp_get_customer`). An order, status or customer
identifier that is not a whole number - a decimal, a list, true - is now
refused instead of being read as another order, status or customer.

**An order reference shared by several orders is no longer guessed.** When a
cart is split across carriers, PrestaShop gives its orders one reference, and
each has its own page in Orders > Orders. Reading an order, changing its
status, adding a message or a payment, and setting its private note by that
reference used to act on the newest of them without a word. They now change
nothing, send nothing to the customer, and answer with the identifiers of the
orders that share the reference, so the assistant asks which one is meant.
Every order tool now answers the same way when the order is not found, and
points to the order search.

**The built-in assistant knows the tools of the page you are on.** Opened on
the order page, the chat could not answer "what is this order" before looking
up the tools it needed, one more step before every first answer. It now has
the reading tools of the page you opened it from at hand from the first
message - reading an order or searching orders on the order page, for example.
The tools that change something on that page still go through the search and
still ask for your approval, and an employee without the right on the page gets
none of them.

**Tool descriptions stay short.** Every tool the assistant can use is sent to
it on each step of a conversation, so a long description slows every answer
and costs more. The descriptions of the tools now stay within 600 characters
and those of their fields within 200, and a new tool can no longer be added
above that: the few longer texts left, mostly on the product page, are listed
until they are shortened.

**Shorter attribute fields.** The fields of the four tools that create and
change attribute groups and values - names, public name, type, URL word, title
tag, texture, position - now each fit within 200 characters: no text of the
attributes page is longer than that any more.

**Shorter feature fields.** The fields of the four tools that create and
change features and feature values - name, value, URL word, title tag - now
each fit within 200 characters: no text of the features page is longer than
that any more.

**Shorter cart rule language and payment method fields.** The language field
of `somcp_get_cart_rule` and the payment method field of
`somcp_add_order_payment` now each fit within 200 characters: the payment
method is still best given as the Payment block of the order page suggests it,
the name of one of the shop's payment modules.

**Shorter image fields.** The image fields of the tools that create and change
categories, brands and suppliers - cover image, menu thumbnails, logo - now
each fit within 200 characters: an image is still given by its address or by
a file already on the shop, in the same words in every tool.

**Shorter product fields.** The fields of the tools that create and change a
product - its type, features, pack contents, downloadable file, combinations,
images, friendly URL, redirection and tags - now each fit within 200
characters: no field of the products page is longer than that any more.

**A photo from the chat can be a category image.** The cover and the menu
thumbnails of `somcp_create_category` and `somcp_update_category` now take an
image the same way the product and brand tools do: either a public `https`
address the shop downloads, or a file already on the shop - one attached in the
assistant chat, deposited by the merchant, or generated in the chat. A photo
the merchant drops into the conversation can now be used straight away as a
category cover or menu thumbnail. The plain address form is replaced by this
object: give `{"url": "https://..."}` instead of the bare address.

**Clearer order status list.** The tool that lists the order statuses now
returns each status's icon, and renames three answer keys to match what the
status form shows: `status_id` (was `id`), `in_transit` (was `delivery`, the
box 9.0.0 labels "Set the order as in transit") and `language`, the ISO code
used (was `language_id`). It now reads the name in any active language of the
shop and refuses an unknown language code or an unknown argument instead of
ignoring it. An assistant reading the list should use the new key names.

**Shorter order status tools.** The two tools of Shop Parameters > Order
Settings > Statuses that list and create an order status now describe
themselves in a few sentences, lighter for the assistant on every step. What
matters is kept: that a status is taken from the list and never assumed, that
a status is matched on its role rather than on its name, that an order status
is not a return status, that a name another status already has is refused,
that each field says whether you or the assistant fill it, and which read
checks a creation whose answer was lost.

**Shorter category tools.** The six tools of Catalog > Categories that list,
read, audit, create, change and show or hide a category now describe
themselves in a few sentences, lighter for the assistant on every step. What
matters is kept: that a category is taken from the list and never assumed,
that two categories can share a name in different branches, that the root
category is never changed and Home never moved or hidden, that hiding a
category leaves its products online, that each field says whether you or the
assistant fill it, and which read checks a change whose answer was lost. The
rules on the length of texts and on languages left out now sit in the
catalogue prompt, where the assistant reads them when you pick it.

**Shorter product tools.** The tools of Catalog > Products that search, read,
audit the SEO of, switch on or off and delete a product now describe themselves
in a few sentences, lighter for the assistant on every step. What matters is
kept: that a product is taken from the search and never assumed, that a
promotion is never mistaken for the price, that switching off can be undone
and is offered before a deletion, which cannot, that a deleted product leaves
only this shop and orders keep their lines, and which read checks a change
whose answer was lost. The thresholds of the SEO audit now sit with its list of
checks.

**Shorter product create and change tools.** The two tools of Catalog >
Products that create and change a product now describe themselves in a few
sentences, lighter for the assistant on every step. What matters is kept: that
identifiers come from the list tools, that only what is given changes and a
removal is final, which read to run before a change, and that a creation is not
retried blindly. The rules on who fills each field, on the length of texts and
on languages left out now sit in the catalogue prompt, where the assistant
reads them when you pick it.

### Fixed

**A deleted order status can no longer be set.** Deleting a status on **Shop
Parameters > Order Settings > Statuses** keeps it for the orders already on it,
and the order page no longer offers it - but the assistant could still move an
order to it. It is now refused like a status the shop does not have, and
nothing changes. The order status guide also tells the assistant to look up an
order given by its reference first, since a cart split across carriers gives
several orders the same reference.

### Security

**Redirects send the same headers as the rest of the module.** The redirects
of the connection page no longer tell which PHP version the shop runs, and
are never kept by a cache.

## 1.3.1 - 2026-11-07

### Added

**Install with Composer on PrestaShop 9.** Agencies and automated deployments
can now install the module with `composer require novalo/prestashop-mcp-server`
from the shop's root folder: it lands in `modules/somcp`, exactly where the zip
puts it, so a shop can move from one way to the other. The zip from the back
office stays the usual way to install it, and the only one on PrestaShop 8,
whose own packages refuse the PHP version the module needs.

### Changed

**The Assistant button stands out in the header.** It used to be a grey link
like **View my shop** beside it. It is now a soft orange button in the module's
colours, which turns solid orange while the chat is open, so you see at a
glance whether it is. The round button shown on pages without a header follows
the same colours.

**More room in the enlarged chat.** Enlarged, the chat now shows the list of
conversations open in a column beside it, and the panel grows by that column
instead of the messages losing room; picking a conversation leaves the list
open. Messages and the field to type in keep a margin on both sides, so lines
stay easy to read. The tokens used are shown once, at the foot of that list,
and "Powered by Neuron AI" is centred. On a screen too narrow for both, the
list still slides over the chat as before.

### Fixed

**The PrestaShop Addons validator can check the module again.** Its
compatibility step stopped with an error on 1.3.0. The sign-in and consent
pages used a way of writing PHP that only PHP 8.1 understands, which the
validator's checks may not read; they are written the older way, and work
exactly as before.

**A lighter zip, without the libraries' own housekeeping.** The libraries the
module ships came with files their authors use for their own repository -
code style and editor settings, test and CI configuration - and the Composer
plugin used only by a Composer install. They are left out of the zip, which
holds no hidden file besides the module's own `.htaccess`.

## 1.3.0 - 2026-11-07

**Your assistants have to be connected again after this update.** Every
connection made so far was approved on a page that asked for a password alone,
and none of them is kept. To connect an assistant again: turn on **Consent
page** in the **Access and security** tab of the module, connect the assistant
from Claude, ChatGPT or wherever you use it, then turn the page off again. The
diagnostic shows when the page is off, with a link to the switch.

### Added

**A user guide in PDF.** `readme_en.pdf`, attached to each release:
requirements, installation, each settings tab, connecting an assistant step by
step and the built-in assistant, with the path of every button and switch.

**Create a voucher or a cart discount.** The new tool `somcp_create_cart_rule`
creates one rule of **Catalog > Discounts > Cart Rules**, with the fields of the
**Information**, **Conditions** and **Actions** tabs: the name in each language,
the code (or none, for a discount applied on its own), the validity dates, a
single customer, the minimum amount, the quantities, free shipping, a percent
or an amount off the order, a product, the cheapest product or the selected
products, and a free gift. It refuses what the back office refuses, a code
already used or a rule that gives nothing among them, and a gift with required
customization fields on PrestaShop 8 as on 9. As on screen, a discount on one
product limits the rule to carts that hold it, and the rules that restrict
their compatibility accept the new one. The rule is created switched off unless
asked otherwise, so the merchant reads it before customers can use it;
restrictions stay in the back office. It joins the **Boost sales** prompt,
needs the `add` right on **Cart Rules** and the new **Sales - changes** access.

**Read one cart rule in full.** The new tool `somcp_get_cart_rule` gives every
field of a rule as the three tabs **Information**, **Conditions** and
**Actions** of **Catalog > Discounts > Cart Rules > Edit** show it: the name in
each language, the code, the validity dates, the minimum amount with its
currency, tax and shipping, the quantities, each restriction (countries,
carriers, customer groups, other cart rules, product selection, stores) with
the names of what it is limited to, and what the rule gives, in the same
words as the list. Read only, the current shop's rules only. It joins the
**Boost sales** prompt and needs the `view` right on **Cart Rules**.

**List the shop's vouchers and cart discounts.** The new tool
`somcp_list_cart_rules` lists the cart rules of **Catalog > Discounts > Cart
Rules**, newest first, telling vouchers with a code from discounts applied on
their own, with what each one gives in one line: the discount, free shipping,
a free gift, how many uses are left, whether it has conditions to read, and
the number of orders that already used it. Read only, the current shop's rules
only. It joins the **Boost sales** prompt and needs the `view` right on **Cart
Rules**.

**Find the stock that never sells.** The new tool `somcp_list_unsold_products`
lists the active products of the shop that no valid order bought, as
**Products never purchased** on **Stats > Catalog statistics**, with what the
screen does not show: the quantity for sale, the cost price and what that
stock is worth, the costliest first, and the date each product last sold. With
no period it counts every order ever, as the screen; with one, the products
not bought in it. It reads the orders of the current shop only, joins the
**Boost sales** prompt and needs the `view` right on **Stats**.

**Spot the products that are looked at but not bought.** The new tool
`somcp_list_viewed_not_sold` lists the products of the shop with their page
views and what they sold over a period, most viewed first, as **Stats >
Product details** shows one product at a time: Total Viewed, Total bought and
the conversion rate side by side, so the products that draw interest and miss
the sale stand out. Page views are counted only when **Save global page views**
is on in the **Data mining for statistics** module; when it is off the answer
says so rather than reading as nobody looked. It joins the **Boost sales**
prompt and needs the `view` right on **Stats**.

**See what visitors search for on the shop.** The new tool
`somcp_list_search_terms` lists what visitors typed in the shop's search box
over a period, as **Stats > Shop search**: for each term, how many times it was
searched and the most products it found. As on that page, only the terms
searched more than once are listed unless asked otherwise, and it can keep only
the searches that found nothing - what visitors looked for and the catalogue
does not have. When the **Shop search** module is not active the answer says
so, instead of an empty list that would read as nobody searched. It joins the
**Boost sales** prompt and needs the `view` right on **Stats**.

**Write an llms.txt for the shop.** The tool `somcp_generate_llms_txt` writes
an llms.txt at the root of the shop, the plain text summary some AI tools read
at the root of a site, from the shop's own data: its name, a summary written
with the merchant, the main categories, the policy and information pages and
the contact page, in the language asked for. A file the merchant wrote by hand
is replaced only when asked, and a shop whose root is not writable is told so
without anything being changed.

**See the carts that never became an order.** The new tool
`somcp_list_abandoned_carts` lists the carts that hold products and were left
without an order, newest first, with the same rule as the **Abandoned cart**
rows of **Orders > Shopping Carts**: no order, created more than 24 hours ago.
For each cart it gives the customer's name, whether the visitor is still on the
shop, the carrier, the products total tax excluded and included, and the three
most expensive products left in it, plus the total value of every matching cart
per currency. It can be narrowed to a period, to the carts of customer accounts
or without one, to a customer's name or to the visitors online. Nothing is sent
to anyone, no email, phone or address is given, and the **Share this cart**
link never leaves the shop. It opens the **Boost sales** prompt and needs the
new permission `mcp.sales.read`, which the consent screen describes as reading
abandoned carts, searches, product views and promotions, and the `view` right
on **Orders > Shopping Carts**.

**The diagnostic checks the sign-in page.** A new line, **Sign-in page**,
opens the page where you approve an assistant, as your browser would, and turns
red when something else answers in its place - typically another module that
sends visitors without an account to a login, an age check or a country page.
Left unnoticed, the assistant would wait on a page you never see.

### Changed

**Shorter audit and sales tools.** Four tools that went a little over the
length agreed for a tool description - the CMS pages SEO audit, the product
completeness audit for answer engines, the llms.txt generator and the list of
products viewed but not sold - now describe themselves in a few sentences,
lighter for the assistant on every step. What matters is kept: the names of
every check and figure, that a hand-written llms.txt is replaced only once you
agree, that it is never retried blindly, and which tool to use instead.

**A recorded payment now names who took it.** The answer of
`somcp_add_order_payment`, and each payment of `somcp_get_order`, now report
the employee who recorded the payment on PrestaShop 9, as the **Employee**
column of the **Payment** block shows it (nothing on PrestaShop 8, which has no
such column). The payment method is best given as the name of the shop's
payment module, so the Payment table and the order read the same method.

**Shorter attribute tools.** The seven tools of Catalog > Attributes &
Features > Attributes - list, read, create and change the attribute groups and
their values - now describe themselves in a few sentences, lighter for the
assistant on every step. What matters is kept: that a group or a value is taken
from the list and never guessed, that each field says whether you or the
assistant fill it, that a new name, public name, type or colour shows on every
product page or combination using it and is confirmed with you first, that a
value used by a combination cannot move to another group, and which read
checks a change.

### Fixed

**robots.txt is no longer rewritten on a shop in demo mode.** The tool
`somcp_generate_robots_txt` now refuses, with nothing changed, on a shop that
runs in demo mode, as the **Generate robots.txt file** button of **Shop
Parameters > Traffic & SEO** does.

**The zip passes the PrestaShop Addons validator again.** A folder added in
this version had no `index.php`, and the anti-robot widget's script had no
licence header at its top. Both are in place.

### Security

**A page to approve assistants that only opens when you open it.** The page
where an employee signs in and approves a new assistant is now off unless you
turn on **Consent page** in **Access and security**, and while it is off every
link to it is the shop's ordinary "page not found" - including one somebody
sends you to have you approve their own assistant. Turn it on for the minutes
you connect one: the assistants already connected keep working with it off.

**A code by e-mail after the password.** Once the password is accepted, the
page asks for a 6-digit code e-mailed to that employee. A code works once and
for ten minutes, a new one can be asked for after 30 seconds and a third after
45 more, and there is no fourth: once the third is mistyped five times or
expires, the connection is closed and has to be started again from the
assistant. A stolen password alone no longer connects anything, and an
employee who receives a code they did not ask for knows their password is out.

**Only the assistants you allow can be connected.** The list of allowed return
addresses (**Access and security** tab of the module, **Only allow these return
addresses**) is now filled in on a new shop with the assistants the module works
with - Claude, ChatGPT, Le Chat, Make, n8n Cloud, VS Code and Cursor - and an
empty list now refuses them all, where it used to accept any address. The list
is also checked each time someone signs in, not only when an application
registers, so taking a host off the list cuts it off at once. A connection link
that points anywhere else opens the shop's ordinary "page not found". Desktop
assistants and coding agents, which answer on your own computer, are still
always allowed. On update, an empty list is filled with the same assistants plus
those already connected to your shop, so nothing you use stops working.

**Connecting an assistant happens on a page of your shop.** When an assistant
asks to connect, a page of the shop itself now asks for your back office email
and password, then shows what the assistant will be able to do and, in large
letters, the address the answer will be sent to - the same page, without going
through the back office. Only the account that signed in can approve, with its
own rights, on the shop the assistant was sent to. A wrong password, an account
switched off, a profile not allowed to connect assistants or an account not
attached to that shop all get the same message, and the real reason is written
in the module's log. The page carries the module's logo, fits a phone as well as
a computer, keeps the **Refuse** and **Allow** buttons in reach however long the
list of what the assistant may do, and sets apart what it may change from what
it may only read.

**Fewer ways for a robot to try its luck.** The sign-in page of a connection
now has an "I'm not a robot" box, a proof of work your browser solves in about a
second - no image to read, nothing sent to another company, nothing to set up.
Each failed sign-in makes the next box longer to solve, up to about ten seconds.
After five failed sign-ins, the address they come from is blocked for fifteen
minutes, even with the right password; after ten on one account, from any
addresses, that account is blocked on this page for fifteen minutes too, so
changing proxy for each guess gains nothing. Your back office login is not
affected. On a shop behind a proxy or Cloudflare, the visitor's real address is
the one counted, not one they wrote in a header. An address that sends too many
connection requests, or registers too many applications in an hour, is turned
away for a while too.

**An e-mail each time an assistant is connected.** It goes to the employee who
approved the connection and to the shop's address, with the super
administrators in blind copy, and says which assistant, by whom, when, and where
its answers are sent - so a connection nobody expected is noticed at once and
can be removed from the **Connected assistants** tab. It is written in the
shop's default language when the module speaks it (English, French, Spanish,
German), in English otherwise, and it is listed with the shop's other e-mails,
so you can reword it.

**The module's machine addresses stay out of sight.** The addresses meant for
assistants and for O'So AI Relay (sign-in tokens, discovery documents, relay)
now show the shop's ordinary "page not found" when someone opens them in a
browser, as the main assistant address already did. Assistants and the relay
are not affected. The page where you approve an assistant does the same when it
is opened without a real connection request - by hand, for an unknown
application or with a return address off the list.

### Removed

**The "Accept new connections" switch.** Off, it did more than its name said:
it also stopped the assistants already connected from renewing their access,
so they dropped within the hour. New connections are now the **Consent page**
switch's job, and **Enable the MCP server** stops everything at once. A shop
that had it off gets the MCP server switched off on update, so it stays closed
to assistants.

## 1.2.2 - 2026-11-05

### Added

**Switch off a whole group of tools at once.** On **Tools and prompts**, the
tools are now grouped by the permission an assistant is given for them -
**Orders - read**, **Orders - changes**, **Catalog - read** and so on - and
each group has its own two switches, one for the assistants connected over MCP
and one for the built-in assistant. A group switched off holds back every tool
in it, including the ones a later update adds to it, and every prompt that
needs it; the assistants are no longer offered that permission when they
connect. Switching the group back on gives each tool the switch it had. The
built-in assistant can read and change these group switches too, after your
approval.

**See which products are ready for AI assistants.** The new tool
`somcp_audit_product_entities` scores each
product of your catalogue on the data an answer engine needs to describe it -
brand, barcode, manufacturer reference, features, a long enough description, a
summary, images with captions, weight, condition, a real category and a meta
description - and lists the least complete products first, naming for each one
what is missing under the label of its product page. It reads one language and
changes nothing; fill the gaps with the catalogue tools.

**Check whether AI crawlers can read your shop.** The new tool
`somcp_check_ai_crawler_access` tells you, for each of the main AI crawlers
(GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bingbot and more), whether
your robots.txt lets it fetch your home page, a product page and a category
page, with the exact rule that decides it, and whether those pages ask it not
to index them. It reads your robots.txt and your pages and changes nothing;
whether to open your shop to a crawler stays your choice, and the tool for
writing the robots.txt is `somcp_generate_robots_txt`.

**Check the pages buyers look for before they trust your shop.** The new tool
`somcp_audit_store_policies` looks for your legal notice, terms and
conditions, shipping, returns, privacy, about us and contact pages, and says
for each one whether it was found and how, whether it is **Displayed**,
whether **Indexation by search engines** is on, how many words it has and
whether your home page links to it. It also reports the settings these pages
go with: the terms to accept at checkout, returns and their time limit, and
the delivery time texts of your products. When several pages could be the same
one, it lists them so you choose. It changes nothing, and what these pages say
stays your responsibility.

**Read your shop's llms.txt.** The new tool `somcp_get_llms_txt` shows the
llms.txt at the root of your shop, the plain text summary some AI tools look
for, with its size, its last change and the address where it is asked for, and
says whether it was written by O'So MCP Server. It warns when there is none,
when your shop sits in a folder, when the file is too large, or when it does
not start with a title line. llms.txt is a proposed convention, not a
standard: no AI tool is bound to read it. The tool changes nothing.

### Changed

**The SEO settings read now cover the rest of the SEO and URLs page.** The tool
that reads the shop's SEO and URL settings now also reports the two Apache
options (MultiViews and mod_security), the option that puts the attributes in
the product meta title, the address pattern of the module pages, and, for each
language, whether its addresses carry the ISO code - including, on PrestaShop 9,
the option that keeps the default language without a prefix. Reading the
settings still changes nothing, and an unexpected field is now refused instead
of ignored.

**The Tools and prompts lists are folded by group.** Each group shows how many
of its tools or prompts are on in each column, and opens with a click; the
search opens the groups it finds something in. The **Asks for** and
**Requires** columns are gone, to keep the lists readable: the back office
right each tool needs is listed in the tools table of the project page, and a
prompt held back by a group switched off says so on its row.

**The project page is written for merchants.** The front page of the
repository now says what you can ask, the requirements, how to install the
module in three steps from the release zip, which assistants work with it and
how your shop stays protected, with the tools folded by back office page. The
technical details and the installation from the sources moved to the
contributing guide.

**The category SEO audit judges a page like the product audit.** The category
SEO audit now looks at displayed categories only by default, with an option to
include hidden ones, lets you limit it to the checks you want, and flags a meta
description that is too short, so the same page gets the same verdict whether
you audit it as a product or as a category.

**The CMS page audit names the title and the meta title as the page form
does.** Each page in the CMS SEO audit now carries its page title, its meta
title and the title search engines read under the same names the other page
tools use, so an assistant fixing a field passes it back correctly instead of
writing the page heading into the meta title. The audit also flags a meta
description that is too short, as the other SEO audits do.

**Any CMS page can have its SEO changed, not only those the audit flags.**
The tool that changes the meta title, meta description, friendly URL and
indexation of a CMS page used to send the assistant to the CMS SEO audit to
find the page, and the audit only lists the pages with a problem: asked to
change a page with nothing wrong, the assistant could not find it. It now takes
the page from the list of every CMS page and reads it before and after the
change; the audit stays the way to find what needs fixing.

**The brand audit checks the length of the meta title and meta description.**
The brand SEO audit now flags a meta title over 60 characters, which search
results cut, and a meta description under 70 or over 160 characters, with the
same limits as the product, category and page audits, so a brand is judged by
the same rules as the rest of the shop.

**Shorter feature tools.** The seven tools of Catalog > Attributes & Features >
Features - list, read, create and change the features and their values - now
describe themselves in a few sentences, lighter for the assistant on every
step. What matters is kept: that a feature or a value is taken from the list
and never guessed, that each field says whether you or the assistant fill it,
that renaming a feature or a value changes it on every product that carries
it and is confirmed with you first, that a value typed on one product only is
changed on that product, and which read checks a change.

## 1.2.1 - 2026-11-04

### Added

**A security and compatibility report with every release.** Each version is
now published only after the full test suite has passed on the exact code in
the zip, and the release page carries a report of what was checked: every
PrestaShop and PHP combination the module was installed and run on, each
attack tried against a live shop and refused, the permissions of every tool,
the code and dependency scans, and the SHA-256 of the zip, so you can check
that the file you downloaded is the one that was tested. The report sits next
to the zip on the release page, not inside the module.

**Listing the customer groups.** The new tool `somcp_list_customer_groups`
lists the customer groups of the shop as **Shop Parameters > Customer
Settings > Groups** shows them: name, discount, price display method, whether
prices are shown, number of members and creation date, plus the groups used by
default for visitors, guests and registered customers. The assistant now takes
the group of a customer, of a category or of a specific price from this list
instead of guessing it. It needs the `view` right on the **Groups** page and
is part of the **Find a customer** and **Manage products** prompts.

**Checking the shop's links.** The new tool `somcp_check_links` walks the
public pages of your shop from a starting page, as a visitor sees them, and
lists the links that lead to an error page with the pages that contain them,
the addresses that go through several redirects and the pages that take more
than 2 seconds to arrive. Only your shop's own addresses are opened, at most
200 pages and one minute per check; links to other sites are counted, never
followed. It needs the `view` right on **Shop Parameters > Traffic & SEO** and
is part of the **Audit and improve SEO** prompt.

**Checking the sitemap.** The new tool `somcp_audit_sitemap` reads the
sitemaps the **Google sitemap** module generated for your shop and tells you
how many addresses each one holds, when it was last generated, which pages are
left out on the module's screen and whether your robots.txt points search
engines to it. It lists the active products missing from the sitemap and the
disabled products still in it, and opens a sample of its addresses as a
visitor to find those that answer an error. Only your shop's own addresses are
opened, and the sitemap is never generated by the tool. It needs the `view`
right on **Shop Parameters > Traffic & SEO** and is part of the **Audit and
improve SEO** prompt.

**Checking what a product page tells search and answer engines.** The new tool
`somcp_audit_product_schema` opens the public page of a product, or of one of
its combinations, as a visitor sees it, and compares the structured data the
theme prints there with the product page of your back office: name, brand,
reference, MPN, barcodes, condition, price with tax, currency and stock. It
lists each difference with the label of the field in your back office, and
what your shop holds that the page does not print. When the theme prints no
structured data at all, it says so: that comes from the theme, not from your
products. Only your shop's own addresses are opened, and nothing is changed.
It needs the `view` right on **Catalog > Products** and is part of the new
**Get recommended by AI assistants** prompt, which guides the assistant through
these checks and never promises that an assistant will cite your shop.

### Changed

**Each tool and prompt can be switched for the connected assistants and for
the built-in assistant apart.** On the **Tools and prompts** tab, the box in
front of each tool and prompt is replaced by two switches on the right: one
for the assistants connected over MCP, such as Claude or ChatGPT, and one for
the assistant built into your back office. You can keep a tool for the
built-in assistant, which asks before every change, and hide it from the
assistants that connect from outside, or the other way round. Each list also
has a search field, which finds a tool or a prompt by its name, its title, its
publisher or the permission it needs. Nothing changes when you update: a tool
or prompt you had switched off stays off in both columns, and the built-in
assistant can switch either column when you ask it to.

**The server address shows the shop's "page not found" in a browser.** The
address you give to an assistant used to answer a browser with a blank error
page. It now shows the shop's usual page not found, like any other address
that leads nowhere for a visitor. Assistants are not affected: they keep being
answered as before.

**Catalogue tools point plainly to the tool to use next.** Seven category,
feature and attribute tools still told the assistant to use a tool to read or
add a value "when the shop offers it", although every one of those tools is
there. The assistant could take it as a tool that may be missing and skip the
step. They now name the tool plainly.

**Shorter return status tools.** The three tools of Shop Parameters > Order
Settings > Statuses that create, change and delete a return status now
describe themselves in a few sentences, lighter for the assistant on every
step. What matters is kept: that a return status is not an order status, that a
name another return status already has is refused, that the status is taken
from its list and never guessed, that the five statuses every shop starts with
cannot be deleted, and which read checks the outcome.

**Shorter menu link tools.** The three tools of the storefront's Main menu -
list the entries, add one and change a custom link - now describe themselves in
a few sentences, lighter for the assistant on every step. What matters is kept:
that an entry's object is taken from its list tool and never guessed, that an
entry already in the menu is refused, that a change touches one link and never
its place in the menu, and which read checks the outcome.

**Shorter module tools.** The three tools of Modules > Module Manager that
list, read and install modules now describe themselves in a few sentences,
lighter for the assistant on every step. What matters is kept: that a module is
taken from the list and never guessed, that an installed module is refused and
upgraded in the Module Manager, which shops a multistore install reaches, and
which read checks an install.

**Shorter customer tools.** The four tools of Customers > Customers - search,
read, create and change a customer - and the list of customer groups now
describe themselves in a few sentences, lighter for the assistant on every
step. What matters is kept: which tool gives the customer and the group
identifiers, that every value comes from you and is never invented, that no
password goes through the conversation and no email is sent to the customer,
and which read checks a change.

**Shorter supplier tools.** The five tools of Catalog > Brands & Suppliers >
Suppliers - list, read, create, change, enable or disable a supplier - now
describe themselves in a few sentences, lighter for the assistant on every
step. What matters is kept: that the supplier identifier comes from the list
and is never guessed, that a supplier name can exist twice so the list is read
before a creation, who fills each field, that disabling is not a deletion, and
which read checks a change.

**Shorter brand tools.** The six tools of Catalog > Brands & Suppliers >
Brands - list, read, create, change, enable or disable a brand, and the SEO
audit of brands - now describe themselves in a few sentences, lighter for the
assistant on every step. What matters is kept: that the brand identifier comes
from the list and is never guessed, that a brand name can exist twice so the
list is read before a creation, who fills each field, that disabling a brand
keeps its products on sale, and which read checks a change.

**Shorter brand address tools.** The five tools of the Brand addresses list of
Catalog > Brands & Suppliers > Brands - list, read, add, change and delete an
address - now describe themselves in a few sentences, lighter for the
assistant on every step. What matters is kept: that the brand and the address
identifiers come from the lists and are never guessed, that the same address
can be added twice so the list is read first, that every field comes from you
and is never invented, that customer and supplier addresses are refused, and
which read checks a change.

**Shorter theme, cache, tax, carrier and file tools.** The two tools of
Design > Theme & Logo - read the theme and change the layout of a page - and
the lists of tax rules, carriers and Catalog > Files, with the Clear cache button of
Advanced Parameters > Performance, now describe themselves in a few sentences,
lighter for the assistant on every step. What matters is kept: that a page,
a layout, a tax rule, a carrier or a file is taken from its list and never
guessed, that a layout is confirmed with you before it changes, that clearing
the cache clears it for every shop of the installation and slows the next
pages, that a file's download link is public and stays with you, and which
read checks a change.

### Fixed

**Inactive products that follow the shop's default behaviour are judged
correctly.** On PrestaShop 9, an inactive product left on the shop default in
`somcp_audit_product_seo` was always reported as having no redirection, even
when **Shop Parameters > Product Settings > Default behavior for disabled
products** keeps its page shown with a 200 response. The audit now reads that
setting: such a product is no longer reported, and when it is, the entry names
both the product's choice and what the shop default applies, so you know which
setting to change. Nothing changes on PrestaShop 8.

**The module passes the PrestaShop Addons validator again.** The check
`somcp_update_theme_setting` makes before changing the theme, which refuses
to change it on a shop in demo mode, was reported by the validator as a
condition that can never be true. It now reads the shop's demo mode the way
the other tools read the core's settings, and behaves as before.

**The faceted search fields of attributes and features follow the module's
state.** With the faceted search module installed but disabled, the back
office form no longer shows the URL and Meta title fields, yet
`somcp_get_attribute_group`, `somcp_get_attribute_value`, `somcp_get_feature`
and `somcp_get_feature_value` still returned them as empty texts, which the
update tools then refused. They now return them as `null` whenever the module
is not installed and enabled, as the create and update tools already did.

**Attribute values with ; or # in their name are refused on PrestaShop 9.**
On PrestaShop 9, `somcp_create_attribute_value` and
`somcp_update_attribute_value` accepted a name holding `;` or `#`, which the
back office form of an attribute value refuses there: the value was saved,
then its edit page could not be saved again until it was renamed. Both tools
now refuse these characters on PrestaShop 9 as the back office does, and say
which characters the running version refuses. PrestaShop 8, whose form accepts
them, is unchanged.

## 1.2.0 - 2026-11-03

### Added

**Reviewing the modules.** The new tool `somcp_list_modules` lists the
modules of the shop as **Modules > Module Manager** shows them, installed or
only on disk: name, version, author, description, whether each one is
installed, enabled and enabled on mobile, and whether its files are newer
than the installed version and wait for an upgrade. It filters like the
**Status** filter and the search field of the screen, and reads only the shop,
never the marketplace. It comes with the new **Review modules** prompt and
needs the new permission `mcp.modules.read`, which the consent screen
describes as reading the modules of the shop, and the `view` right on
**Modules > Module Manager**.

**Reading one module.** The new tool `somcp_get_module` reads one module of
the shop as its card in **Modules > Module Manager** shows it - the same
version, author, description and state as `somcp_list_modules` - and adds the
hooks it is attached to on the current shop, as **Design > Positions** lists
them. It joins the **Review modules** prompt and needs the same
`mcp.modules.read` permission and `view` right on **Modules > Module Manager**.

**Installing a module.** The new tool `somcp_install_module` installs a module
whose folder is already on the shop, as the **Install** button of its card in
**Modules > Module Manager** does, and answers with the module as
`somcp_get_module` reads it afterwards. A module already installed is refused
and left as it is, so an install never turns into an upgrade, and nothing is
uploaded or downloaded. It joins the **Review modules** prompt and needs the
new permission `mcp.modules.write`, which the consent screen describes as
installing modules already present on the shop, and the `add` right on
**Modules > Module Manager**.

**Enabling or disabling a module.** The new tool `somcp_set_module_active`
enables or disables an installed module on the shop, as the **Enable** and
**Disable** buttons of its card in **Modules > Module Manager** do, and on
PrestaShop 8 switches it on or off for mobile. A request that would change
nothing is refused, since PrestaShop would still report a success and run the
module's hooks; this module never disables itself. It answers with the module
as `somcp_get_module` reads it afterwards. It joins the **Review modules**
prompt and needs the `mcp.modules.write` permission, which the consent screen
now describes as installing, enabling and disabling modules already present
on the shop, and the `edit` right on **Modules > Module Manager**.

**SEO of the shop's own pages.** The new tool `somcp_update_meta_page`
changes the **Page title**, **Meta description** and **Rewritten URL** of one
of the pages listed under **Shop Parameters > Traffic & SEO** - contact, new
products, best sales, search and the others - in the languages you approve,
in the current shop only, and the **Meta keywords** on PrestaShop 8, the only
version whose form has them. It checks a **Rewritten URL** as the form does -
"Only letters and hyphens are allowed." - instead of reshaping what you
approved, requires it in the shop's default language only, accepts the home
page's empty one given back unchanged, refuses a friendly URL another page
already uses, and warns that a new one makes the old address stop working. It
takes the field names `somcp_list_meta_pages` returns. It joins the **Audit and improve SEO**
prompt and needs the `mcp.seo.write` permission and the `edit` right on
**Shop Parameters > Traffic & SEO**.

**Reading one content page.** The new tool `somcp_get_cms_page` reads one
CMS page as its edit form under **Design > Pages** shows it: category,
title, meta title, meta description, meta keywords (PrestaShop 8), friendly
URL, the full page content, **Indexation by search engines** and
**Displayed**, with the page's address on the storefront. What a page says
can now be read and quoted, not only listed, and the SEO fields keep the
names `somcp_update_cms_seo` takes, so a value read can be written back as
is. It joins the **Read content pages** prompt and needs the
`mcp.content.read` permission and the `view` right on **Design > Pages**.

**Deep thinking, web search and memory, switched per conversation.** The
**+** menu of the built-in assistant now has switches for the conversation
you are in. **Deep thinking** asks the assistant to think longer before
answering, for Claude, Gemini and OpenAI's reasoning models; it is slower and
uses more tokens, and what the assistant thought can be unfolded above its
answer. **Web search** lets Claude and Gemini look things up on the web with the
key they already have, and the pages used are listed in the conversation.
Claude searches on its own: each search is billed by Anthropic on top of the
tokens, and web search must be allowed in your Anthropic console. Gemini
searches through a second call to the same model with Google Search, counted
in the tokens like any answer. The other assistants - OpenAI, Mistral,
DeepSeek, Grok, Cohere - can search too once you pick Claude or Gemini under
**Web search** in the **Built-in assistant** tab: that assistant searches for
them with its key, and its searches count in its tokens. The setting follows
the shop levels like the others, and stays greyed until both a Claude or
Gemini assistant and an assistant of another provider are set up. With web
search on, the assistant can also open a page - one you give it, or one a
search found - and read it.

**The built-in assistant knows the module it belongs to.** Asked how to
switch its memory on, it used to search the web and answer about other
products, or say it had no memory at all. It now knows it is the module's
own chat, what its **+** menu does and where each setting lives, and points
to the module's documentation for the installed version. For super
administrators, the employees the settings pages are open to, it can read
every setting of the module - each with its tab and label, and whether it is
set for this shop, the group or all shops - with the assistants, tools and
prompts, and change one setting at a time after you approve it, through the
same checks as the settings page. It never reads nor writes an API key. These
abilities, like asking another assistant and searching the web, belong to the
chat alone: they are not in the module's tool list, and an assistant
connected over MCP never sees them. **Memory**, offered when the memory is switched on
in the module settings, can be switched off for one conversation: the
assistant then works from that conversation alone, and nothing said in it is
added to the memory - what was remembered before stays. A switch only shows
when the assistant can use it, and one set away from how a conversation
starts shows as a pill under the field, with a cross to undo it. The
conversation keeps its switches when you come back to it.

**A local assistant, running on your own computer.** **Add a local assistant**
in the **Built-in assistant** tab connects the chat to an AI on your computer -
Ollama, LM Studio or any software that speaks the OpenAI API - through
[O'So AI Relay](https://github.com/novalo-dev/Somcp-Relay), a small program you
download and run there. Your conversations and your shop data then go to your
computer instead of an AI company, with no key to pay for. The relay calls your
shop, never the other way round, so nothing has to be opened on your network,
and it works whether your shop is hosted online or next to you. The screen
opens on a code valid ten minutes, once, with the command to paste in the
relay, and a progress bar that moves on by itself: the relay linked, your AI
tested, the model to choose. Nothing to click while it waits; **Retry** shows
only when a step gave up, such as a code that expired. Only the models of your
AI able to call the shop's tools are offered. The list of assistants shows whether the relay is online, and
**Test the connection** checks it. Removing the local assistant unlinks the
relay at once. While nobody uses the chat, the relay asks your shop only every
15 seconds instead of keeping a request open, so it does not hold one of your
shop's PHP processes; it is back to answering at once as soon as the chat is
opened.

**Deleting return statuses.** The new tool
`somcp_delete_return_status` deletes one return status the merchant
created, as the **Delete** action of the return status list of **Shop
Parameters > Order Settings > Statuses** does. The five statuses every
shop starts with are refused, and so is a status a merchandise return
still stands on, with the number of returns. The prompt **Change an
order status** names it too.

**Reading the storefront's main menu.** The new tool `somcp_list_menu_links`
lists the entries of the main menu as the **Selected items** of the **Main
menu** module's configuration page show them, in order: categories, products,
CMS pages and categories, brands, suppliers, shops and custom links, each with
its name in the language asked for and the identifier the other tools take.
It also lists the custom links of the **Link list**, in the menu or not, and
names the entries that point to something deleted, which the screen hides.
When the **Main menu** module is not enabled it says so instead of failing.
It comes with the new **Storefront menu and layout** prompt and needs the new
permission `mcp.appearance.read`, which the consent screen describes as
reading how the storefront looks, and the `view` right on **Modules > Module
Manager**.

**Adding an entry to the storefront's main menu.** The new tool
`somcp_add_menu_link` adds one entry to the main menu, as **Add** then **Save**
in the **Menu Top Link** panel of the **Main menu** module: a category, a
product, a CMS page or category, a brand, a supplier, all brands or all
suppliers, a shop or a custom link, at the end or at the position asked. A new
custom link is created from its label and address in each language and put in
the menu in the same call, where the screen leaves it outside the menu. An
empty label or address, which the module would accept, is refused, and so is
an entry already in the menu. Only the menu of the connected shop changes, and
the storefront shows it at once. The **Storefront menu and layout** prompt
names it. It needs the new permission `mcp.appearance.write`, which the
consent screen describes as changing how the storefront looks, and the `edit`
right on **Modules > Module Manager**.

**Changing a custom link of the storefront's main menu.** The new tool
`somcp_update_menu_link` changes the label, the address or **New window** of
one custom link of the **Link list** of the **Main menu** module, as **Edit**
then **Update**. Only what is asked changes: where the form empties a field
left blank, the other texts and languages are kept, an empty label or address
is refused, and a language added to the shop after the link was created gets
its text instead of losing it. A link shared by every shop stays shared. A
call that would change nothing writes nothing, and the storefront shows the
change at once. The **Storefront menu and layout** prompt names it. It needs
the permission `mcp.appearance.write`, which the consent screen now describes
as also changing the custom links of the main menu, and the `edit` right on
**Modules > Module Manager**.

**Reading the storefront's theme and page layouts.** The new tool
`somcp_get_theme` reads **Design > Theme & Logo** for the connected shop: the
theme in use with its version and author, the other installed themes, the
addresses of the header, mail and invoice logos and of the favicon - saying
when the header logo stands in for the mail or invoice logo - and every page
of **Choose layouts** with the layout it uses, and whether that is the theme's
default. The **Storefront menu and layout** prompt names it. It needs the
permission `mcp.appearance.read`, which the consent screen now describes as
also reading the theme, logos and page layouts, and the `view` right on
**Design > Theme & Logo**.

**Changing the layout of a page of the storefront.** The new tool
`somcp_update_theme_setting` sets the layout of one page - full width, three
columns, or two columns with a small left or right column - as one row of
**Design > Theme & Logo > Choose layouts** followed by **Save**. Only that
page changes: every other page keeps its own setting, where the screen saves
every row at once. Only the layouts the theme offers are accepted, a call that
would change nothing writes nothing, and the storefront shows the new columns
at once. It changes the connected shop alone on a multistore installation, and
is refused in demo mode. The **Storefront menu and layout** prompt names it.
It needs the permission `mcp.appearance.write`, which the consent screen now
describes as also changing the layout of the pages, and the `edit` right on
**Design > Theme & Logo**.

**Checking the performance settings.** The new tool `somcp_get_cache_status`
reads every card of **Advanced Parameters > Performance** for the connected
shop: Smarty, Debug mode, Optional features, CCC, Media servers and Caching,
with the caching systems this server can run and the Memcache servers. It
warns about what slows the storefront or should not stay on in production:
debug mode or the debug profiler on, forced template compilation, the Smarty
cache off, overrides disabled, CSS or JavaScript not combined, a caching
system the server cannot run. On PrestaShop 9 it says whether debug mode is
limited to a debug cookie, without ever giving the cookie's name or value. It
changes nothing. It comes with the new **Cache maintenance** prompt and needs
the new permission `mcp.system.read`, which the consent screen describes as
reading how the shop runs, and the `view` right on **Advanced Parameters >
Performance**.

**Clearing the cache.** The new tool `somcp_clear_cache` clears what the
**Clear cache** button of **Advanced Parameters > Performance** clears: the
Symfony cache, the Smarty cache and compiled templates, the XML cache, the
combined CSS and JavaScript files of the theme and the class index. The
caches are shared by every shop of the installation, so they are cleared for
all of them; Memcache or APC is not flushed and no setting changes. The shop
is slower for a few seconds while the caches rebuild, so the tool's own
description warns of it and keeps a busy shop from being cleared unless the
merchant asks. The **Cache maintenance** prompt names it. It needs the new permission
`mcp.system.write`, which the consent screen describes as clearing the cache,
and the `delete` right on **Advanced Parameters > Performance**, the same
right the button asks.

### Changed

**Each assistant runs the model you choose, and the chat lets you switch.**
The Economy, Balanced and Powerful levels are gone: in **Built-in
assistant**, an assistant is now either a **Chat** assistant with a
**Default model** picked from the list your provider gives, or an **Images**
one. An assistant that already ran keeps the model it was using. In the
chat, the assistant button next to **+** opens a menu like the one of **+**:
every assistant with its default model, a **Default** badge on the one that
answers when none is picked, and for each assistant the list of its models,
its default one marked the same way. The model picked there applies to that
conversation only.

**The sign-in and consent pages can no longer be shown inside another site.**
These are the pages where a merchant hands the shop to an assistant.
PrestaShop lets any site display its pages inside a frame, so another site
could have placed the consent button under something else and led a signed-in
merchant to click it without seeing it. Browsers now refuse to show these two
pages anywhere but on their own.

**Claude costs less in a conversation, and the consumption is exact.**
Assistants on Claude now ask Anthropic to keep the start of each request in
its cache: from the second call of a conversation, the tools and the
instructions are read back at a tenth of the price or less. The tokens a
provider reads back from its cache are counted apart, and the estimate in
dollars prices them at that provider's own cache rate (OpenAI, Gemini,
DeepSeek, xAI and Mistral cache on their side too), instead of at the full
price. The summary the assistant writes of the oldest messages of a long
conversation was not counted at all; it now is. **Shop Parameters > MCP
server > Built-in assistant** says how many of this month's tokens were read
from a cache.

**Brand addresses filtered like their list.** `somcp_list_manufacturer_addresses`
now filters like the columns of **Catalog > Brands & Suppliers > Brands >
Addresses**: part of the **Last name**, **First name**, **Zip/Postal code**
or **City**, and one **Country** by its ISO code. Each address now also gives
the name of its country, as the **Country** column shows it, in the shop
default language or the language asked for.

**Meta keywords of the shop pages.** `somcp_list_meta_pages` now also gives
the **Meta keywords** of each page on PrestaShop 8; on PrestaShop 9, which
no longer has that field, they are shown as empty.

**A new look for the built-in assistant.** The chat now looks like the AI
apps you already know. An empty conversation opens on a short welcome and up
to four suggested tasks: the prompts of the page you are on come first, and
only those your rights allow are shown. The **+** button in the field adds
files or starts any prompt, and a prompt picked there hands its guide to the
assistant with your message. A change waiting for your approval now reads in
plain words - each field with its label and its new value, yes or no instead
of true or false - with **Refuse** and **Approve** side by side and the exact
data one click away. The conversations can be searched and are grouped by
day, with the month's spending and its budget under them. Icons are drawn as
lines instead of emoji, a change still waiting for you shows a clock rather
than a check, and an answer can be copied in one click. A prompt declared by
another module can name its icon too.

**Answers drawn as written.** Titles, tables, numbered lists, quotes,
separators and blocks of code in an answer of the built-in assistant now show
as such, instead of the raw `###`, `|` and `---` they were written with. A
wide table scrolls sideways inside the chat.

**The built-in assistant on a multistore shop.** The **Built-in assistant**
tab now follows the shop levels like the other tabs: the assistants, the
employees allowed, the monthly cap, the instructions and the memory are set
for all shops, a group or a shop, each with the **Value of its own here** box
and "inherited from the group" or "inherited from all shops" beside it. A
group or a shop uses the assistants of the level above until it is given its
own: checking the box copies them, keys included, to be changed there.
A local assistant is not copied: each level links its own relay, so removing
a local assistant always unlinks its relay at once.
Conversations and spending stay per shop, and a cap set above applies to
each shop. With the back office on all shops or a group, the chat now
appears as soon as one shop has an assistant, and a new conversation starts
by picking its shop on cards showing each shop's logo. The conversation then
acts on that shop only, whatever the back office is set to later: its name
shows in the chat, each conversation of the list carries its shop number,
and the line shown while the assistant works names the shop too.

**Assistant spending counted in tokens.** The monthly cap of the built-in
assistant is now set in tokens, the exact count every AI service reports,
instead of an estimate in US dollars that went stale whenever a price
changed and counted an unknown model as free. Each assistant can also have
a monthly cap of its own, useful to keep a powerful model below an economy
one. The chat shows the tokens of the month against the cap, with the
estimated cost beside them, marked as an estimate and given to four decimals,
since a conversation costs fractions of a cent. A cap set in dollars
before is converted on upgrade at the highest price among your assistants,
so it is never looser than it was.

**The built-in assistant spends a fraction of the tokens.** Every message
sent the full description of all the shop's tools to the AI service - some
50,000 tokens, a plain hello included, and twice that when a tool was used.
The assistant now starts with a tool finder: it looks up the tools a request
needs by keyword and loads only those, which the conversation keeps for the
messages that follow. A hello now costs a few thousand tokens. A prompt
started from the **+** menu tells the assistant which tools its guide walks
through, and each is loaded when the step needs it. Tools
switched off on the **Tools and prompts** tab, or beyond the employee's
rights, cannot be found nor loaded. Assistants connected over MCP still see
the whole list, as before.

**Tools only point to tools that exist.** A few tools told the assistant to
use a tool not written yet - to list the customer groups, check many links,
change an order status, or install and enable modules - so it could reach for
a call that only failed. They now say where to look in the back office
instead, such as **Customers > Groups**, and each change still names the tool
that shows its result. The link to a new tool is added with that tool.

**Shorter order tools.** The seven tools of the order page - search, read,
change the status, set the tracking number or the private note, add a message,
record a payment - now describe themselves in a few sentences instead of a
paragraph, about a fifth lighter for the assistant on every step. What
matters is kept: when the customer is emailed, which tool gives each
identifier, and which read checks a change.

**Shorter stock and statistics tools.** The three tools of the Stock page -
read the stock of a product, list what runs low, change a quantity - and the
two statistics tools - sales summary and best-selling products - now describe
themselves in a few sentences, a quarter lighter for the assistant on every
step. What matters is kept: what each figure means on the screen, which tool
gives each identifier, and which read checks a stock change.

**Shorter content page tools.** The four tools of Design > Pages - list the
pages, read one, check their SEO, change their SEO fields - now describe
themselves in a few sentences, almost a third lighter for the assistant on
every step. What matters is kept: which tool gives each page identifier, the
warning that a new friendly URL breaks the old address, and which read checks
a change.

**Shorter Traffic & SEO tools.** The six tools of Shop Parameters > Traffic &
SEO - check a public page, read the SEO settings, read and generate
robots.txt, list the shop pages and change their SEO - now describe themselves
in a few sentences, a third lighter for the assistant on every step. What
matters is kept: which tool gives each page identifier, the warning that a new
Rewritten URL breaks the old address, the reminder that generating robots.txt
loses the lines written by hand, and which read checks a change.

### Fixed

**A conversation no longer stops for good after the AI service fails.** When
the AI service failed right after a change you had approved - often a
"high demand" error from Gemini - the change was made, but the conversation
then refused every new message, with this assistant or any other, and had to
be thrown away. The built-in assistant now runs on Neuron AI 4.1.19: the
change is recorded the moment it is made, and the next message is answered.
A conversation that broke this way before the update picks up again too,
without running the change a second time.

**An approved change shows as done in the built-in assistant.** After the
employee approved a change - turning a product on or off, for example - the
change was made and the assistant answered, but its step kept the clock of a
change still waiting, without "approved" next to it. A refused change showed
the same clock. Each step now shows what the employee decided.

**An assistant missing a permission is asked to request it, instead of
signing in again.** When an assistant called a tool its connection was not
allowed to use - writing to the catalogue with a read-only connection, for
example - the shop answered as if the connection had expired. Some assistants
then dropped it and asked the merchant to connect again, which gave the same
connection back and the same refusal. The shop now answers that a permission
is missing and names it, so the assistant can ask the merchant for exactly
that one.

**The update to 1.2.0 no longer stops on "Unknown column 'memory_last'".**
On a shop where an earlier update had not added every column of the
assistant's tables, the update failed with that message and the module stayed
on its old version. The module now compares its tables with the ones a fresh
install creates and adds whatever is missing, on every update. A shop reaches
the right tables whatever version it comes from, and an update that was
stopped can simply be run again.

**The built-in assistant no longer freezes after a slow answer.** When the AI
service took longer to answer than the shop allows a page to run - often 30
seconds, and on Windows the wait counts - the request was stopped mid-way
without a trace: the chat showed "Working..." then nothing, and every new
message was refused for ten minutes with "Answer the change waiting for your
approval first", though no change was waiting. The chat now asks the shop for
the time an answer needs where the host allows it, stops waiting for the AI
service before the shop stops the page, and reports a slow answer as an error
you can retry. While an earlier step still holds a conversation, the chat says
so and waits, and the conversation is freed after five minutes instead of ten.

**The test build no longer reports false translation problems.** Its summary
checked the translations of main's copy too, which the build fetches for its
scripts, and showed strings of the last release as missing; it also said
"of ." instead of the branch it built. It now checks the module it built, and
names the branch.

**Settings that depend on each other are checked before they are saved.**
The memory could be switched on with no embeddings provider, or with Voyage
and no key: the page said it was on, and it did nothing. It is now refused
with a message saying what is missing; no provider is ever picked for you.
A memory that was on and stopped
working - its provider's assistant deleted, its key gone - is now flagged in
red on the page instead of showing a switch on over nothing. A refresh token
set to last less than the access token, which would log every assistant out
at its first renewal, and a profile that does not exist are refused too. The
rest of the form is still saved, and the message says so. The built-in
assistant follows the same rules when it changes a setting, then reads the
setting back and tells you whether the feature actually works. It changes
only the setting you asked for, and asks you whenever a choice is needed -
which provider, which profile - instead of choosing for you.

**Gemini's thinking now counts toward the monthly cap.** Gemini reports the
tokens it spends thinking apart from its answer, and bills them as output;
the built-in assistant left them out of the month's count, so a Gemini
assistant used more than the chat showed. They are counted now.

### Security

**The module no longer tells callers which PHP version the shop runs.** The
answers it sends to a connected assistant - tool calls, sign-in exchanges and
the files it hands out - carried the version of PHP behind the shop, which
nobody connecting needs. It is now left out of them.

## 1.1.3 - 2026-11-01

### Added

**Sales summary over a period.** The new tool `somcp_get_sales_summary`
gives what the shop sold between two dates, counted as the **Stats Dashboard**
and **Sales and orders** pages count it: valid orders by invoice date, amounts
tax excluded in the shop's default currency. It returns the revenue, the
sales after credit slips, the revenue tax included, the number of orders, the
products bought, the average basket and the new customers, and on request the
same figures for the period just before, to compare. It works even when the
statistics modules are disabled. It needs the new permission `mcp.stats.read`,
which the consent screen describes as reading the statistics, and the `view`
right on the back office Stats page.

**Best-selling products over a period.** The new tool
`somcp_list_best_sellers` lists the products that sold most between two
dates, as **Stats > Best-selling products** shows them: reference, name,
quantity sold, price sold, sales, quantity sold in a day, page views,
available quantity and status, sorted by sales or by any of those figures,
page by page. It counts the same valid orders by invoice date as the sales
summary, in the shop's default currency, and works even when the statistics
module is disabled. The sales report prompt now uses it for best sellers.

**Sales report prompt.** A guided task for sales figures, picked from the
assistant's menu: turn the period into two dates and say which, give every
amount with its currency and whether tax is included, and explain why the
list of orders can differ by a few orders at the edges of a period.

**Listing carriers.** The new tool `somcp_list_carriers` lists the
carriers of the shop as **Shipping > Carriers** shows them, enabled or not
and in the same order, with their delay, logo, tracking URL and whether
they are free, filtered by status, free shipping or part of the name. The
carrier identifiers a product takes are read from it instead of guessed,
and since PrestaShop gives a carrier a new identifier each time it is
edited, the reference that stays the same is given too. The prompts
**Change an order status** and **Manage products** name it.

**Listing content pages.** The new tool `somcp_list_cms_pages` lists the
CMS pages of the shop as **Design > Pages** shows them, displayed or not:
URL, title, meta title, position, category and the page's address on the
storefront, filtered by category, by **Displayed** or by part of the title.
The page categories come with it, so a category can be picked by its
identifier. The title and the meta title keep the names the SEO tools use,
so the assistant never writes one in place of the other. It needs the new
permission `mcp.content.read`, which the consent screen describes as
reading the content pages, and the `view` right on **Design > Pages**.

**Content pages prompt.** A guided task for the shop's CMS pages, picked
from the assistant's menu: find the page the merchant means by its title,
quote its texts rather than paraphrasing them, and tell the title from the
meta title.

**Creating return statuses.** The new tool `somcp_create_return_status`
adds one return status - a step a merchandise return goes through - with
its name in the languages being filled and its colour, as the **Add new
return status** form of **Shop Parameters > Order Settings > Statuses**
does. A name another return status already has in that language is
refused, so two steps can never wear the same label and be mistaken for
each other. The prompt **Change an order status** names it too.

**Changing return statuses.** The new tool `somcp_update_return_status`
changes the name or the colour of one return status, as its **Edit** form
of **Shop Parameters > Order Settings > Statuses** does. Only what is
given changes: a language left out keeps its text, and a call that would
change nothing writes nothing and says so. A name another return status
already has in that language is refused, as at creation. The prompt
**Change an order status** names it too.

**Creating order statuses.** The new tool `somcp_create_order_status`
adds one order status with every field of the **Add new order status**
form of the same page: its name and email template in the languages
being filled, its colour, its GIF icon - by address or by a file already
on the shop - and the nine boxes that say what reaching the status sets
off. A name another order status already has in that language is
refused on both PrestaShop 8 and 9, so two statuses can never wear the
same label. The prompt **Change an order status** names it too.

### Changed

**A brand or supplier logo can be a file already on the shop.** The logo of
`somcp_create_manufacturer`, `somcp_update_manufacturer`,
`somcp_create_supplier` and `somcp_update_supplier` used to accept only a web
address, so a photo attached in the built-in assistant or an image it had just
generated could not become a logo. It is now given the way product images are:
either its public https address (`url`) or the reference of a file already on
the shop (`upload_ref`), and it goes through the same download and image checks
as product images. The answer names the logo by the one that was given.

**Reading an order gives what acting on it needs.** `somcp_get_order` now
returns the identifier of each product line, of each discount (with its
invoice and whether it offers free shipping) and of each step of the status
history, so that refunding a line, removing a discount or resending the email
of a status can name what they act on. Each address now comes with its
identifier, its alias, its region and the identification number when there is
one, as the address blocks of the order page show them. A product line that is
a pack lists the products in it, with their quantity and the stock available
now, like the **Products in pack** window of the order page.

**Features can be reordered.** The order of the features - the drag and
drop of **Catalog > Attributes & Features > Features** - could not be changed
by the assistant: `somcp_update_feature` refused `position`. It now takes it,
moves the feature to that place and shifts the others as the list does, and
the answer gives the old and the new place.

**Attribute values can be reordered.** The order of the values of an
attribute - the drag and drop of its list of values in **Catalog > Attributes &
Features > Attributes** - could not be changed by the assistant:
`somcp_update_attribute_value` refused `position`. It now takes it, moves the
value to that place and shifts the others as the list does, also right after
moving the value to another attribute, and the answer gives the old and the
new place.

**Attribute groups can be reordered.** The order of the attribute groups -
the drag and drop of **Catalog > Attributes & Features > Attributes** - could
not be changed by the assistant: `somcp_update_attribute_group` refused
`position`. It now takes it, moves the group to that place and shifts the
others as the list does, and the answer gives the old and the new place.

**Tax rules: found by name, in the order of the list.** With the fifty or so
rules a localization pack creates, the assistant had to read them all to find
one. `somcp_list_tax_rules` now takes part of a rule name, as the **Name**
filter of **International > Taxes > Tax Rules** does, and lists the rules in
the order of that page - by identifier - instead of by name.

**The diagnostic names the real cause.** With friendly URLs off, the three
address lines below also failed, each blaming something else - a rewrite rule,
an error 503 - and sent the merchant after problems that did not exist. When a
line every address depends on fails (the shop disabled, friendly URLs off, no
valid certificate), the addresses are now marked **Not checked** and point to
that line. Two lines are new: **HTTPS certificate**, which says when the
certificate is expired, self-signed, issued for another domain or missing, and
**SSL in PrestaShop**, red when SSL is off - only a warning on a shop running
on a local address. A failing setting now comes with **Go to the setting**,
which opens the PrestaShop page where it is changed, and an address answered by
the maintenance page says so.

**Meet our contributors: wider cards.** On the cards after the top
contributor, the number of contributions and the share of the commits were
cut over four short lines. Every card now has the width of the top one, so
each keeps to a line, and the others come six at a time with Previous and
Next.

**The list of files shows descriptions and can be filtered.**
`somcp_list_attachments` now returns the **Description** of each file beside
its name, which tells two manuals or two data sheets apart, and filters on
part of the **Name** or of the **File** as the list of **Catalog > Files**
does. A language the shop does not have used to be silently replaced by
another one; it is now refused with the list of the shop's languages, the
shop default language is used when none is given, and the answer says which
language the names and descriptions are in.

**The attribute list shows what the back office shows.** `somcp_list_attributes`
now gives, for each attribute group, its type, its position and its number of
values, and for each value its position and, in a colour group, its colour -
the columns of **Catalog > Attributes & Features > Attributes**. Before, it
gave only identifiers and names, so a group's order or a value's colour had to
be read one by one.

**The feature list shows what the back office shows.** `somcp_list_features`
now gives, for each feature, its position and its number of values - the
columns of **Catalog > Attributes & Features > Features** - and on PrestaShop 9
the position of each value, with the values in the order of the back office.
Before, it gave only identifiers and names, and on PrestaShop 9 listed the
values in another order than the back office. `somcp_get_feature_value` also
gives the position of the value on PrestaShop 9.

### Fixed

**Support us button on PrestaShop 8.1 and later.** The button opened GitHub
straight away instead of the thank-you window, and showed no icon, because
PrestaShop rebuilds the buttons of the page header and dropped what the module
gave this one. It now opens the window on every version, with a heart in front
of its label drawn the way PrestaShop draws the icons of its own buttons.

## 1.1.2 - 2026-10-31

### Added

**Listing return statuses.** The new tool `somcp_list_return_statuses` lists
the return statuses of the shop, the steps a merchandise return goes through,
with each status's identifier, its name, its colour and whether it can be
removed. The five statuses every shop starts with cannot be removed, on
PrestaShop 8 and 9 alike. A language the shop does not have is refused, as in
`somcp_list_meta_pages`. The prompt **Change an order status** names it too,
so a return status is never mistaken for an order status. Thanks to
@Ptraaah, whose first contribution this is.

**Meet our contributors.** The Help and updates tab now thanks the people who
build the module: each one with their GitHub profile picture, their number of
contributions and their share of the commits, and a link to their profile. The
first card goes to the top contributor, whoever that is at the time. The others
follow on one row, nine at a time with Previous and Next once there are more,
and past ten people a last card leads to the full list on GitHub.
The list is read from GitHub once a day, like the check for a new version, so
a new contributor or a new profile picture shows without updating the module.
With that check switched off, nothing is asked of GitHub, not even the
pictures: the list shipped with the module is shown without them.

### Fixed

**Buttons of the update notice.** On the notice of a new version, the icon of
Download the zip and of See what is new ran into the first letter of the
button. It now sits apart from the label, as on the other buttons.

## 1.1.1 - 2026-10-31

### Added

**Changing a customer.** The new tool `somcp_update_customer` changes one
customer account, as Customers > Customers > Edit does: name, email, social
title, birthday, enabled, partner offers, newsletter, groups and default
group, the private note of the customer page, and the B2B fields when B2B mode
is on. Only the fields given change, the answer lists each one with its old and
new value, and a call that changes nothing writes nothing. The password is
never touched. It needs the permission `mcp.customers.write` and the `edit`
right on the back office Customers page.

**Creating a customer.** The new tool `somcp_create_customer` creates one
customer account, as Customers > Customers > Add new customer does: name,
email, guest account, social title, birthday, enabled, partner offers,
newsletter, groups and default group, and the B2B fields when B2B mode is on.
No password goes through the conversation: the customer chooses theirs with
"Forgot your password?" on the shop, and no email is sent. It needs the new
permission `mcp.customers.write`, which the consent screen describes as
changing the customers, and the `add` right on the back office Customers page.

### Changed

**Listing suppliers: the columns and filters of the Suppliers list.** The list
of suppliers gave the identifier and name only, so a switched-off supplier
could not be told apart without opening each one. Each supplier now comes with
what **Catalog > Brands & Suppliers > Suppliers** shows: whether it is enabled
and its number of products. The list can also be narrowed as on screen, to
enabled or disabled suppliers and by part of the name.

**Assistants named the same way everywhere.** The module description, the
settings page, the README and the bug form each named a different set of
assistants, and some named one the others did not. They now all say Claude,
ChatGPT or Le Chat, and the README keeps the one full list of compatible
assistants, with the page where each AI provider of the built-in assistant
gives out its API keys.

### Fixed

**The assistant's memory works in the installed module.** With the memory
switched on, the built-in assistant stopped with an error in a shop that
installed the module from its archive: part of the library the memory relies on
was left out of the package. The archive now carries it, and the checks run
before each release look at the package as merchants receive it.

### Security

**A module's tools only ask for its own permissions.** A module publishing
tools or prompts through somcp could have them require one of somcp's own
permissions, such as reading the orders, and declare no back office right. The
merchant was then never asked about that module on the consent screen, and an
employee without access to the Orders page could still use its tools. A tool or
prompt that asks for a permission its module did not declare is now refused and
noted in the somcp log.

## 1.1.0 - 2026-10-31

### Added

**Finding customers.** The new tool `somcp_search_customers` lists the
customers of the shop as **Customers > Customers** does, with the filters of
that list: name, email or company, group, social title, enabled, newsletter,
partner offers, guest accounts and registration dates. Each line gives the
default group, what the customer spent, their registration and their last
visit; customers deleted with their data kept are left out, as on screen. It
comes with a new permission to grant, reading customers, and a new guide,
**Find a customer**, which also opens their orders.

**Reading a customer.** The new tool `somcp_get_customer` reads one customer
as their page in **Customers > Customers** shows it: the account with its
private note and B2B fields, groups, addresses, a summary of the orders with
the newest ones, carts, purchased and viewed products, the vouchers reserved
to them, messages, last emails and last connections. Ask for them by
identifier or by email; an email shared by several accounts is refused with
the identifier of each, so the right person is picked. The guide **Find a
customer** now opens it.

**Changing Home and the order of categories.** Changing a category used to
refuse Home outright and could not change where a category stands in the list.
Home can now be changed with the fields of its own form in the back office -
name, Displayed, descriptions, images, SEO fields and group access - while it
still cannot be moved, and on PrestaShop 9 only answers 404 or 410 while
hidden. A category can also be given a new position among the categories of
the same parent, as when you drag it in the list: the others shift, and with a
new parent it is the place under that parent.

**Product tags.** Creating or updating a product can now fill its tags (the
Étiquettes field), given as a comma separated list per language. They are
written to the shop's own tag field, which is separate from the meta keywords
and exists on every PrestaShop version; the shop creates the tags it does not
have yet. On an update, the tags given replace the product's tags in those
languages, and a language left out keeps the ones it had. Reading a product
now returns its tags as well, so they can be read, changed and written back.

**Tracking numbers.** The new tool `somcp_set_order_tracking` sets the tracking
number of an order's shipment, like the Tracking number field of the Edit
shipping details window on the Carriers tab of the order page. As in the back
office, a new number emails your customer the "in transit" message, with the
tracking link when the carrier has one, and lets carrier modules know; giving
the number the shipment already has changes nothing and sends nothing, and an
empty number removes it without any email. When an order has several
shipments, the assistant has to say which one. The carrier and the order status
are never changed by it. It needs the permission `mcp.orders.write` and the
`edit` right on the back office orders page.

**Order messages.** The new tool `somcp_add_order_message` adds a message to an
order, like the Messages block of the order page. When it is shown to your
customer, they see it in their account and receive the "New message regarding
your order" email in the language of their order; a private message stays
between you and your team, and nobody is emailed. The assistant has to say
which of the two it is, there is no default. As on the order page, the message
is limited to 1200 characters and refused if it holds a script, and it shows the
name of the employee who wrote it. It needs the permission `mcp.orders.write`
and the `edit` right on the back office orders page.

**Order notes.** The new tool `somcp_set_order_note` writes the private note of
an order, the Order note (Order private note on PrestaShop 9) of the Status tab
of the order page. Only your team sees it: your customer is never emailed. As
on the order page, the new text replaces the previous one, so the assistant
reads the current note first when it is asked to add to it; an empty note
clears it, and giving the note the order already has changes nothing. On
PrestaShop 8, a note too long for the shop to store is refused instead of being
cut. It needs the permission `mcp.orders.write` and the `edit` right on the back
office orders page.

**Order payments.** The new tool `somcp_add_order_payment` records a payment you
received for an order - a bank wire or a cheque that arrived, a part payment -
like the Payment block of the order page: amount, payment method, currency,
date, transaction ID, and the invoice when the order has one. The amount paid
of the order goes up, and the assistant tells you what is still due. The order
status does not change: marking the order paid stays a separate step, which the
assistant only takes if you ask. On PrestaShop 9, the payment shows the employee
who recorded it. A zero or negative amount is refused. It needs the permission
`mcp.orders.write` and the `edit` right on the back office orders page.

**Product SEO audit.** The new tool `somcp_audit_product_seo` lists the products
whose search engine fields have a problem, language by language, and names the
problem: a missing, too long or duplicated meta title, a missing, too short or
too long meta description, a friendly URL shared with another product, a
description under 150 words, an image without alternative text, no image, no
brand, no EAN-13, UPC or ISBN, and, when you include inactive products, an
inactive product that redirects nowhere. It counts each problem over your whole
catalogue, and works just as fast on a large one. It only reads: nothing is
changed. It needs the new permission `mcp.seo.read`, shown on the consent screen
as reading how your shop appears to search engines, and the `view` right on the
back office products page. The new prompt "Audit and improve SEO" guides the
assistant through the audit, sorting what it finds by its effect on your sales
and proposing every new title or description to you before anything is written.

**Category SEO audit.** The new tool `somcp_audit_category_seo` lists the
categories whose search engine fields have a problem, language by language, and
names the problem: a missing, too long or duplicated meta title, a missing or
too long meta description, no description, a friendly URL shared with another
category, a displayed category with no active product, and no cover image. It
judges titles and descriptions exactly as the product SEO audit does, counts
each problem over all your categories, and leaves out the root and Home
categories, which have no page of their own. It only reads: nothing is changed.
It needs the permission `mcp.seo.read` and the `view` right on the back office
categories page.

**CMS page SEO audit.** The new tool `somcp_audit_cms_seo` lists the CMS pages
(delivery, legal notice, about us...) whose search engine fields have a
problem, language by language, and names the problem: a title too long or
shared with another page, a missing or too long meta description, a content
under 150 words, a friendly URL shared with another page, and a page set not to
be indexed by search engines. The title checked is the one search engines read:
the page's meta title when you filled it, its title otherwise. It judges titles
and descriptions exactly as the product SEO audit does, and lists every page,
shown or not. It only reads: nothing is changed. It needs the permission
`mcp.seo.read` and the `view` right on the back office Pages page.

**Brand SEO audit.** The new tool `somcp_audit_brand_seo` lists the brands
whose search engine fields or descriptions have a problem, language by
language, and names the problem: no meta title, no meta description, no
description, no logo, and a disabled brand that products of your shop still
carry. Each brand comes with its number of products, the same figure as when
you read the brand, so you can start with the ones that matter. It lists every
brand, enabled or not. It only reads: nothing is changed. It needs the
permission `mcp.seo.read` and the `view` right on the back office Brands page.

**Shop pages and their SEO.** The new tool `somcp_list_meta_pages` lists the
pages of your shop shown under Shop Parameters > Traffic & SEO - home, contact,
new products, best sales, search, account and checkout pages, and the pages of
modules - with their meta title, meta description, friendly URL and public
address, in the language you choose. The product, category and CMS page
templates, which that page does not list because their texts come from each
product, category or page, are shown as such rather than as pages with no
title. It only reads: nothing is changed. It needs the permission
`mcp.seo.read` and the `view` right on the back office Traffic & SEO page.

**SEO and URL settings.** The new tool `somcp_get_seo_settings` reads the
settings that decide what your shop's addresses look like to search engines:
friendly URLs, accented characters in URLs, the redirection to the canonical
URL, HTTPS, the shop's domains, the URL patterns of product, category, brand,
supplier and CMS pages, whether a robots.txt file is there and whether the
sitemap module is installed and enabled, and the shop's languages. It points
out what costs rankings: friendly URLs off, no canonical redirection, a shop
not served in HTTPS, no robots.txt, no sitemap. It only reads: these settings
stay yours to change in the back office. It needs the permission
`mcp.seo.read` and the `view` right on the back office Traffic & SEO page.

**robots.txt.** The new tool `somcp_get_robots_txt` reads your shop's
robots.txt file, the one PrestaShop generates from the Traffic & SEO page, and
lays out its rules for each crawler and the sitemaps it declares. It points
out what hurts your visibility: a group of rules that blocks the whole site, a
rule that blocks your product or category pages (checked on the real address
of one of your products and one of your categories, in each language), a file
without a sitemap line, or a shop installed in a folder, where search engines
never read the file. It only reads: nothing is changed. It needs the
permission `mcp.seo.read` and the `view` right on the back office Traffic &
SEO page.

**Generate robots.txt.** The new tool `somcp_generate_robots_txt` writes your
shop's robots.txt again, exactly as the Generate robots.txt file button of the
Traffic & SEO page does, so the modules that add their own lines to it still
do. Lines you typed into the file by hand are lost: the assistant reads the
file first, shows you what will go and waits for your go-ahead, and the answer
gives back the previous content in full. On a multistore installation the file
is shared by every shop. It needs the new permission `mcp.seo.write`, shown on
the consent screen as changing your shop's SEO settings, page titles and
descriptions, robots.txt and llms.txt, and the same rights as the button: add,
edit and delete on the back office Traffic & SEO page.

**SEO of a CMS page.** The new tool `somcp_update_cms_seo` changes what search
engines read of one of your CMS pages (delivery, legal notice, about us...):
its meta title, meta description, friendly URL and the Indexation by search
engines switch, the same fields as the page form in Design > Pages. Only the
languages you give change, and only in the shop you are connected to. The
assistant shows you the new values before writing them, and warns you when a
title or description will be cut in search results, or when a new friendly
URL means the old address of a displayed page stops working. Giving values the
page already has changes nothing. It needs the permission `mcp.seo.write` and
the `edit` right on the back office Pages page.

**Public page audit.** The new tool `somcp_audit_page` opens one page of your
shop the way a visitor without an account sees it, and reports what search
engines read there: the title and description your theme really prints, the
robots instructions, the canonical address, the language alternates, the h1 to
h3 headings, images without alternative text, links, structured data, Open
Graph tags, the number of words, the redirects on the way and how long the
page took. It warns about a missing or doubled h1, a canonical pointing
elsewhere, a page set to noindex, a missing language alternate, a title or
description too long, a redirect chain and a page slower than 2 seconds. It
only ever opens addresses on your shop's own domains, redirects included, and
changes nothing. It needs the permission `mcp.seo.read` and the `view` right
on the back office Traffic & SEO page.

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

**Listing brand addresses.** The new tool `somcp_list_manufacturer_addresses`
lists the postal addresses of the brands, like the Brand addresses list of the
back office: brand, last name, first name, postal code, city and country. It
can list the addresses of one brand only. It only shows the addresses of brands
of the shop the assistant is connected to, and leaves out deleted addresses. It
needs the permission `mcp.catalog.read` and the `view` right on the back office
brands page.

**Reading a brand address.** The new tool `somcp_get_manufacturer_address`
returns every field of one brand address, as the brand address form of the back
office shows it: brand, last name, first name, address, address line 2, postal
code, city, country, state, national ID number, phone, mobile phone and other
information. It only reads brand addresses: a customer or supplier address is
refused, and so is an address of a brand from another shop. It needs the
permission `mcp.catalog.read` and the `view` right on the back office brands
page.

**Listing suppliers.** The new tool `somcp_list_suppliers` lists the suppliers
of the shop the assistant is connected to, with their identifier and name and
nothing else, so the assistant takes a supplier from the shop rather than
guessing it before creating or changing a product. Suppliers that are switched
off are listed too, since a product can still be attached to one. It needs the
permission `mcp.catalog.read` and the `view` right on the back office suppliers
page.

**Reading a supplier.** The new tool `somcp_get_supplier` returns everything
the back office supplier form shows for one supplier: name, Enabled,
description, phone, mobile phone, address, postal code, city, country, state,
DNI, meta title and description, meta keywords on PrestaShop 8, the logo
address, and how many products of the shop it supplies. A supplier without a
logo says so rather than showing a placeholder image, and one entered without
contact details comes back with those fields empty. It only reads the shop the
assistant is connected to. It needs the permission `mcp.catalog.read` and the
`view` right on the back office suppliers page.

**Listing attributes.** The new tool `somcp_list_attributes` lists the
attribute groups of the shop the assistant is connected to (Size, Colour...)
and, under each one, its values, with their identifier and name and nothing
else, so the assistant takes the right values from the shop rather than
guessing them before creating or changing the combinations of a product. One
group can be asked for on its own. It needs the permission `mcp.catalog.read`
and the `view` right on the back office attributes page.

**Reading an attribute group.** The new tool `somcp_get_attribute_group`
returns everything the back office attribute form shows for one attribute
group: name, public name, type (drop-down list, radio buttons or colour
swatches), position, how many values it holds, and, when the faceted search
module is installed, the word used in filtered URLs, the title tag and whether
filtered pages are indexed. It only reads the shop the assistant is connected
to. It needs the permission `mcp.catalog.read` and the `view` right on the back
office attributes page.

**Reading an attribute value.** The new tool `somcp_get_attribute_value`
returns everything the back office value form shows for one attribute value
(M under Size, Red under Colour...): its name, the group it belongs to, its
position in that group, and for a colour group its colour and the address of
its texture image when it has one; when the faceted search module is installed,
also the word used in filtered URLs and the title tag. It only reads the shop
the assistant is connected to. It needs the permission `mcp.catalog.read` and
the `view` right on the back office attributes page.

**Creating an attribute group.** The new tool `somcp_create_attribute_group`
creates one attribute group (Size, Colour...) with the fields of the back
office attribute form: its name and the name customers see, in each language,
its type (drop-down list, radio buttons or colour swatches) and, when the
faceted search module is installed and enabled, the word used in filtered
URLs, the title tag and whether filtered pages are indexed, which are off
unless you say otherwise. Without that module, those three fields are refused
rather than stored for nothing. The group is created without values and placed
last in the list. On a multistore installation it is created in the shop the
assistant is connected to. The consent screen now says that the catalogue
permission can create attribute groups. It needs the permission
`mcp.catalog.write` and the `add` right on the back office attributes page.

**Changing an attribute group.** The new tool `somcp_update_attribute_group`
changes one existing attribute group with everything the creation can write:
its name and the name customers see, in each language, its type and, when the
faceted search module is installed and enabled, the word used in filtered
URLs, the title tag and whether filtered pages are indexed. Only what is asked
changes: a language left out keeps its text, and the answer lists each field
changed with its old and new value. Asking for what the group already holds
changes nothing. When a colour group becomes a drop-down list or radio
buttons, its values keep their colours and textures but the product page no
longer shows them, and the assistant tells you so. The consent screen now says
that the catalogue permission can edit attribute groups. It needs the
permission `mcp.catalog.write` and the `edit` right on the back office
attributes page.

**Adding an attribute value.** The new tool `somcp_create_attribute_value`
adds one value (M under Size, Red under Colour...) to an attribute group, with
the fields of the back office value form: its name in each language, and for a
colour group its colour and its texture, an image the shop downloads from a
public https address. When the faceted search module is installed and
enabled, it also takes the word used in filtered URLs and the title tag;
without that module, those two fields are refused rather than stored for
nothing. A name the group already has is refused, and so is a colour or a
texture on a drop-down list or radio buttons group, where the product page
would never show it. If the texture cannot be used, the value is still created
and the assistant tells you why. The value is placed last in its group. On a
multistore installation it is created in the shop the assistant is connected
to. The consent screen now says that the catalogue permission can create
attribute values. It needs the permission `mcp.catalog.write` and the `add`
right on the back office attributes page.

**Changing an attribute value.** The new tool `somcp_update_attribute_value`
changes one existing attribute value with everything the creation can write:
its name in each language, the group it belongs to, for a colour group its
colour and texture, and when the faceted search module is installed and
enabled, the word used in filtered URLs and the title tag. A texture can also
be removed. Only what is asked changes: a language left out keeps its name,
and the answer lists each field changed with its old and new value. Asking
for what the value already holds changes nothing. A new name or colour shows
on every combination using the value, and the assistant tells you so before
asking. A value cannot be moved to another group while combinations use it,
and a name the group already holds is refused. If a new texture cannot be
used, the current one is kept and the assistant tells you why. Changing a
value keeps its faceted search fields, which that module would otherwise
clear on any save made outside its own form. The consent screen now says that the
catalogue permission can create and edit attribute values. It needs the
permission `mcp.catalog.write` and the `edit` right on the back office
attributes page.

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

**Changing a feature.** The new tool `somcp_update_feature` changes one
existing feature with everything the creation can write: its name in each
language and, when the faceted search module is installed and enabled, the
word used in filtered URLs, the title tag and whether filtered pages are
indexed. Only what is asked changes: a language left out keeps its text, and
the answer lists each field changed with its old and new value. Asking for
what the feature already holds changes nothing. Renaming a feature renames it
on every product that carries it, and the assistant tells you so before
asking. It needs the permission `mcp.catalog.write` and the `edit` right on
the back office features page.

**Reading a feature value.** The new tool `somcp_get_feature_value` returns
everything the back office value form shows for one predefined value of a
feature (Cotton under Composition...): its text, the feature it belongs to,
and, when the faceted search module is installed, the word used in filtered
URLs and the title tag. A value typed on one product only is not read here: it
belongs to that product. It only reads the shop the assistant is connected to.
It needs the permission `mcp.catalog.read` and the `view` right on the back
office features page.

**Adding a value to a feature.** The new tool `somcp_create_feature_value`
adds one predefined value (Cotton, Linen...) to an existing feature, with the
fields of the back office value form: its text in each language and, when the
faceted search module is installed and enabled, the word used in filtered URLs
and the title tag. Without that module, those two fields are refused rather
than stored for nothing. A value the feature already holds is refused, and the
assistant is told which one to use instead. Values typed on one product only
are not created here: they belong to that product. It needs the permission
`mcp.catalog.write` and the `add` right on the back office features page.

**Changing a feature value.** The new tool `somcp_update_feature_value`
changes one existing predefined value of a feature with everything the
creation can write: its text in each language, the feature it belongs to and,
when the faceted search module is installed and enabled, the word used in
filtered URLs and the title tag. Only what is asked changes: a language left
out keeps its text, and the answer lists each field changed with its old and
new value. Asking for what the value already holds changes nothing. Changing
the text changes it on every product that carries the value, and the
assistant tells you so before asking. A value cannot be moved to another
feature while products carry it, since they would keep showing it under the
old one, and a text the feature already holds in another value is refused.
Values typed on one product only are not changed here. The consent screen now
says that the catalogue permission can create and edit features and their
values. It needs the permission `mcp.catalog.write` and the `edit` right on
the back office features page.

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

**Enabling or disabling a brand.** The new tool `somcp_set_manufacturer_active`
enables or disables one brand, like the Enabled toggle of the back office brand
list, and changes nothing else. A disabled brand no longer has its page on the
storefront, but its products stay on sale: the assistant is told to say so.
Asking for the state the brand already has writes nothing. PrestaShop keeps
Enabled once for all shops, so on a multistore installation the change shows in
every shop sharing the brand, as in the back office. It needs the permission
`mcp.catalog.write` and the `edit` right on the back office brands page.

**Adding a brand address.** The new tool `somcp_create_manufacturer_address`
adds one postal address to a brand with the fields of the back office brand
address form: brand, last name, first name, address, address line 2, postal
code, city, country, state, DNI, phone, mobile phone and other information. The
last name, first name, address, city and country are required, and so are the
state when the country has states and the DNI when the country asks for one; a
postal code that does not match the country's format is refused, as for a
supplier's address. Only a brand of the shop the assistant is connected to can
receive an address. The consent screen now says that the catalogue permission
can create and edit the addresses of brands. It needs the permission
`mcp.catalog.write` and the `edit` right on the back office brands page.

**Changing a brand address.** The new tool `somcp_update_manufacturer_address`
changes one brand address with everything the creation can write: brand, last
name, first name, address, address line 2, postal code, city, country, state,
DNI, phone, mobile phone and other information. Only what you ask for changes,
and the answer lists each change with its old and new value; a request that
would change nothing changes nothing. The fields follow the same rules as at
creation, checked together with what the address already holds: changing the
country asks for a state when the new country has states, and the postal code
must match its format. Giving another brand moves the address to it. Customer
and supplier addresses cannot be changed through it. It needs the permission
`mcp.catalog.write` and the `edit` right on the back office brands page.

**Deleting a brand address.** The new tool `somcp_delete_manufacturer_address`
deletes one brand address, as the Brand addresses list of the back office does;
the brand itself is kept. The assistant is told to ask you first, naming the
brand and the city. Customer and supplier addresses cannot be deleted through
it, and asking again for an address already deleted is refused rather than
reported as done. The consent screen now says that the catalogue permission can
delete the addresses of brands. It needs the permission `mcp.catalog.write` and
the `delete` right on the back office brands page.

**Creating a supplier.** The new tool `somcp_create_supplier` creates one
supplier with the fields of the back office supplier form: name, description,
phone, mobile phone, address, address line 2, postal code, city, country,
state, DNI, meta title and description, meta keywords on PrestaShop 8, logo and
Enabled. It is enabled unless you say otherwise. The address, city and country
are required, as in the back office, and so are the state when the country has
states and the DNI when the country asks for one; a postal code that does not
match the country's format is refused. The logo is given by a public https
address and downloaded by the shop with the same safeguards as product images;
one that cannot be fetched is reported and does not stop the creation. The
answer warns when a meta title or description is missing. On a multistore
installation the supplier is created in the shop the assistant is connected
to. The consent screen now says that the catalogue permission can create
suppliers. It needs the permission `mcp.catalog.write` and the `add` right on
the back office suppliers page.

**Changing a supplier.** The new tool `somcp_update_supplier` changes one
existing supplier with everything the creation can write: name, description,
phone, mobile phone, address, address line 2, postal code, city, country,
state, DNI, meta title and description, meta keywords on PrestaShop 8, logo and
Enabled. Only what you ask for changes, and the answer lists each change with
its old and new value; a request that would change nothing changes nothing.
The contact details follow the same rules as at creation, checked together
with what the supplier already holds: changing the country asks for a state
when the new country has states, and the postal code must match its format. A
new logo replaces the current one, which is kept if the new one cannot be
fetched, and the logo can also be removed. On a multistore installation only
the shop the assistant is connected to is changed. The consent screen now says
that the catalogue permission can edit suppliers. It needs the permission
`mcp.catalog.write` and the `edit` right on the back office suppliers page.

**Enabling or disabling a supplier.** The new tool `somcp_set_supplier_active`
enables or disables one supplier, like the Enabled toggle of the back office
supplier list, and changes nothing else. A disabled supplier no longer has its
page on the storefront, but it stays attached to its products: the assistant is
told to say so. Asking for the state the supplier already has writes nothing.
PrestaShop keeps Enabled once for all shops, so on a multistore installation the
change shows in every shop sharing the supplier, as in the back office. It needs
the permission `mcp.catalog.write` and the `edit` right on the back office
suppliers page.

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

### Changed

**Opening line of the built-in assistant.** An empty conversation opened with
"Hello!", whatever the time, so it said hello at eleven at night. It now opens
with one of several lines, such as "Where shall we start?" or "Your shop is
waiting for your instructions.", drawn at random for each new conversation.

**Listing brands: the columns and filters of the Brands list.** The list of
brands gave the identifier and name only, so a switched-off brand could not be
told apart without opening each one. Each brand now comes with what
**Catalog > Brands & Suppliers > Brands** shows: whether it is enabled, its
number of addresses and its number of products. The list can also be narrowed
as on screen, to enabled or disabled brands and by part of the name.

**Check and adjust stock prompt: categories and suppliers.** The guide offered
to narrow what runs low by category or supplier without giving access to the
lists those come from. It now includes the list of categories and the list of
suppliers, and says what to do on an account allowed stock but not the
catalogue: ask for the product's reference or name, and leave the category and
supplier filters aside.

**Listing what runs low: the filters of the Stock page.** The list of low
stock could only be narrowed by quantity and status. It now also filters by
category, by default supplier, and by a search on the product name, the
product or combination reference or the supplier name, as the Stock page of
the back office does, and each line gives the name of its supplier.

**Creating a product: packs, downloadable files, stock alerts and ecotax.**
Creating a product used to leave a pack empty and a virtual product without
its file, to be completed in the back office, and could not set the minimum
quantity for sale, the low stock level and its email alert, the pack stock
behaviour or the ecotax. A pack is now created with its products and their
quantities, a virtual product with its downloadable file (by a public address
or a file already on the shop) and its download limits, and all of these
fields can be given, the low stock level on each combination too.

**Changing a product: pack contents and downloadable file.** Changing a product
can now set the same fields, and replace the products of a pack or take some
out, and add, replace or delete the downloadable file of a virtual product or
change its download limits alone. Before, these had to be changed in the back
office.

**The built-in assistant across pages.** Reloading or leaving a page while
the assistant was answering made the new page wait until the answer was done.
The page now loads at once: the answer carries on in the background and
appears in the chat - or the change waiting for approval does - when it is
ready, with your message shown above it all along. The chat also stays open
from one back office page to the next, in the same browser tab. While an
answer is coming you can already write the next message, and open another
conversation without being sent back to the one being answered: that one gets
a dot in the list of conversations when its answer is in.

**The built-in assistant knows the time.** It was given the date only, so it
greeted again in the middle of a conversation and spoke the same way morning
and night. It now has the shop's date and time, suits its tone to the time of
day, and greets only to open a conversation.

**Reading an order.** Reading one order now returns what its page in the back
office shows and was missing: the order note, the cart it came from, gift
wrapping, the gift message and recycled packaging; the customer's title, their
number of valid orders, the total they have spent and the private note on them;
for each product, its EAN-13, ISBN, UPC, MPN and supplier reference, its tax
rate, its stock location, the quantity in stock now, the amounts refunded, the
quantity returned and its customizations; each shipment on its own line with
its carrier, weight, cost, tracking number and tracking link; the taxes and the
wrapping without tax in the totals; the currency, invoice and employee of each
payment; the delivery slips; and, for each message, whether the shop or the
customer wrote it. Card details of payments are still never returned.

**Searching orders.** The order search now covers what the Orders list of the
back office shows: whether the customer was ordering for the first time (the
New client column), the delivery country and the customer's company can be
returned and searched on, and each order comes with the colour of its status.
The amount returned and the amount filters now use the Total column of that
list, tax included, and say so in their name.

**Stock of a product.** Reading a product's stock now also shows who is waiting
on it: alongside the reserved quantity, the orders holding that stock are
listed, each with its number and reference and how many units it holds, for the
product and for each of its combinations.

**Stock of a product: the columns of the Stock page.** Reading a product's stock
did not give what the Catalog > Stock page shows beside the quantities. It now
also gives the reference of the product and of each combination, the default
supplier and its name, whether the product is enabled, and whether the email
alert at the low stock level is ticked, for the product and each combination.

**Settings page.** The page was one long column of panels. It is now split
into seven pages: Configuration, Tools and prompts, Access and security,
Connected assistants, Built-in assistant, Security log, Help and updates.
PrestaShop shows the seven pages as tabs under the page title, as it does for
Contacts and Stores, with the module's buttons beside the title. They open
from Shop Parameters > MCP server, right after General, and from the Configure
button of the module manager, which leads to the first page; each page has its
own address.
The switch that turns the MCP server on and off leads the first tab and saves
as soon as it moves, without a trip to the Save button. The address to give an
assistant now sits in a regular back office field with its Copy button, on
the settings page and on the page shown to employees who may only connect an
assistant. Saving brings you back to the page you were on. Every setting
keeps its meaning and is saved the same way.

**Return addresses.** "Only allow these return addresses" used to be a plain
text field where host names had to be typed separated by spaces. Each address
is now a tag, as for a product's tags: type it and press Enter, remove it with
its cross. Under the field, the clients the module works with (Claude,
ChatGPT, Make, n8n Cloud, VS Code, Cursor, Mistral's Le Chat) can be added in one click. The
setting is saved as before.

**Updates.** When a new version is available, a second notice under it reminds
you to reconnect your assistants after updating, so that their access includes
the new permissions.

**Built-in assistant.** "Powered by Neuron AI" now sits in the title of the
"Your assistants" panel instead of under the settings form.

**Supporting the project.** A "Support us" button now sits beside the page
title, on every page of the settings, drawn like the Help button next to it. It
opens a short thank-you window with a link to the project's GitHub Sponsors
page; it never opens on its own. The Help button leads to the module's
documentation.

**Tools and prompts.** The lists of available tools and of available prompts
each have a panel of their own, the full width of the page like the Connected
assistants list, with the number of tools or prompts in the panel title. The
delay an assistant keeps the list is in a Settings panel under them, and one
Save button at the bottom saves the three.

**Logo.** The module has a new logo: a white pulse ending on an orange dot,
on the dark background of the other O'So modules.

**Product search.** Each product found now comes with the name of its default
category and the address of its small cover image, as the Category and Image
columns of the back office product list show them, so the assistant no longer
reads every product to tell them apart. A language the shop does not have is
now refused with a clear message, instead of quietly searching the names in
the default language.

**Reading a product.** Reading one product now also returns what the product
page shows and the read left out: the minimum quantity for sale, the low stock
level and its email alert (for the product and for each combination), the
products of a pack with their quantity and the pack's stock behaviour, the
downloadable file settings of a virtual product (the file name shown, the
number of downloads, the expiration date and the number of days), and the
ecotax with and without tax. A language the shop does not have is now refused
with a clear message, instead of quietly reading the product in the default
language.

### Fixed

**Built-in assistant behind a CDN.** A shop served through a CDN could keep
handing out the previous script of the chat after the module was updated,
which left its opening line empty. The address of the chat's script and style
now changes whenever the files do, so the new ones are always loaded.

**Tracking number of a split order.** When a cart was split across carriers,
its orders share one reference. Setting a tracking number by that reference
used to write it on the newest of those orders and email that customer, without
a word about the others. The call is now refused with the list of the orders,
their status and their carrier, nothing is written and nobody is emailed, so
the right order can be chosen by its identifier.

**Deleting a conversation.** Deleting a conversation of the built-in
assistant removed its messages but left behind what the long-term memory had
kept of it, and the files attached or generated in it until the weekly purge.
Both now go with the conversation. Uninstalling the module also removes the
memory's folder, which used to stay on the server.

**Memory after a summary.** Once a long conversation was summarized, the
long-term memory lost count of what it had already kept: the messages that
followed could be left out of it for good, and the summary could be kept as
if the employee had written it. The memory now picks up exactly after the last
message it kept, and leaves the summary out.

**Memory in a new conversation.** With the long-term memory on, a new
conversation could carry on the topic of an older one, as if it were its
continuation. The assistant now knows what it recalls comes from earlier
conversations: it uses it only when your message calls for it and says so,
and the conversation you are in is no longer searched as a memory of itself.

**Summary shown as your message.** When a long conversation was summarized to
keep the cost down, the summary appeared as a message from the employee,
headed "Previous conversation summary". It now shows as a discreet
note saying the earlier messages were summarized, which unfolds to the
summary.

**Documents the module downloads.** When the module reads a document from
another site - the description a client publishes about itself when it
connects, the list of models of a provider in the chat settings, the check for
a new version - a document of more than about 16 KB was cut off as soon as one
of its parts held an accented letter or an "&", and then read as broken. Such
documents are now read in full, up to the usual size limit.

**Assistant button in the header.** On a multistore shop, the pages where the
shop list replaces "View my shop" left the button at the far right of the
header, on PrestaShop 9 it was drawn in a grey that does not match the
header, and a click left a ring and a highlight on it. It now sits right after
the shop list or "View my shop" - or just before the notification bell on a
page that shows neither - in the same colour as that link, and a click leaves
it as it was.

**Your assistants list.** On PrestaShop 9 the list of assistants in the
Built-in assistant tab ran into the edges of its panel. It now has the same
margins as the other lists.

**PrestaShop Addons validation.** The validator reported the built-in
assistant's button and loader as HTML written in PHP, four places reading the
shop context the way PrestaShop 9 deprecates, and, in the test builds, a
missing `.htaccess`, then the assistant's settings printed without escaping.
The button and the loader are now Smarty templates, the context is read the
module's usual way, the settings are escaped like every other value, and the
test builds carry the `.htaccess` again. Nothing changes on screen.

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
