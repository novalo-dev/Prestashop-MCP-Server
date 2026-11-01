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

**Creating return statuses.** The new tool `somcp_create_return_status`
adds one return status - a step a merchandise return goes through - with
its name in the languages being filled and its colour, as the **Add new
return status** form of **Shop Parameters > Order Settings > Statuses**
does. A name another return status already has in that language is
refused, so two steps can never wear the same label and be mistaken for
each other. The prompt **Change an order status** names it too.

### Changed

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
