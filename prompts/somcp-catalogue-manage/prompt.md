# Managing products

Search before reading, and read before changing. A product name the merchant
types is rarely the exact one in the shop, and several products can share a
word, a reference prefix or a barcode fragment.

## Tools

- `somcp_ping`
- `somcp_upload_file`
- `somcp_search_products`
- `somcp_get_product`
- `somcp_list_categories`
- `somcp_get_category`
- `somcp_get_category_kpis`
- `somcp_list_manufacturers`
- `somcp_get_manufacturer`
- `somcp_list_manufacturer_addresses`
- `somcp_get_manufacturer_address`
- `somcp_list_suppliers`
- `somcp_get_supplier`
- `somcp_list_attributes`
- `somcp_get_attribute_group`
- `somcp_get_attribute_value`
- `somcp_list_features`
- `somcp_get_feature`
- `somcp_get_feature_value`
- `somcp_list_attachments`
- `somcp_list_tax_rules`
- `somcp_get_tax_rule`
- `somcp_list_taxes`
- `somcp_list_languages`
- `somcp_create_language`
- `somcp_update_language`
- `somcp_delete_language`
- `somcp_install_language_pack`
- `somcp_export_translations`
- `somcp_list_translations`
- `somcp_update_translation`
- `somcp_get_localization_settings`
- `somcp_update_localization_settings`
- `somcp_import_localization_pack`
- `somcp_get_general_settings`
- `somcp_update_general_settings`
- `somcp_get_maintenance_settings`
- `somcp_update_maintenance_settings`
- `somcp_list_currencies`
- `somcp_get_currency`
- `somcp_create_currency`
- `somcp_update_currency`
- `somcp_delete_currency`
- `somcp_update_exchange_rates`
- `somcp_create_tax`
- `somcp_update_tax`
- `somcp_delete_tax`
- `somcp_get_tax_options`
- `somcp_update_tax_options`
- `somcp_create_tax_rule`
- `somcp_update_tax_rule`
- `somcp_delete_tax_rule`
- `somcp_add_country_rule`
- `somcp_update_country_rule`
- `somcp_delete_country_rule`
- `somcp_list_customer_groups`
- `somcp_get_customer_group`
- `somcp_update_customer_group`
- `somcp_list_carriers`
- `somcp_get_carrier`
- `somcp_get_shipping_preferences`
- `somcp_update_shipping_preferences`
- `somcp_get_payment_preferences`
- `somcp_update_payment_restrictions`
- `somcp_list_contacts`
- `somcp_create_contact`
- `somcp_update_contact`
- `somcp_delete_contact`
- `somcp_get_email_settings`
- `somcp_update_email_settings`
- `somcp_list_email_logs`
- `somcp_delete_email_log`
- `somcp_erase_email_logs`
- `somcp_send_test_email`
- `somcp_get_order_settings`
- `somcp_update_order_settings`
- `somcp_get_product_settings`
- `somcp_update_product_settings`
- `somcp_get_customer_settings`
- `somcp_update_customer_settings`
- `somcp_list_titles`
- `somcp_create_title`
- `somcp_update_title`
- `somcp_delete_title`
- `somcp_list_zones`
- `somcp_create_zone`
- `somcp_update_zone`
- `somcp_delete_zone`
- `somcp_list_countries`
- `somcp_get_country`
- `somcp_update_country`
- `somcp_get_country_options`
- `somcp_update_country_options`
- `somcp_list_states`
- `somcp_create_state`
- `somcp_update_state`
- `somcp_delete_state`
- `somcp_update_carrier`
- `somcp_delete_carrier`
- `somcp_create_product`
- `somcp_update_product`
- `somcp_set_product_active`
- `somcp_delete_product`
- `somcp_create_category`
- `somcp_update_category`
- `somcp_set_category_active`
- `somcp_delete_category`
- `somcp_create_manufacturer`
- `somcp_update_manufacturer`
- `somcp_set_manufacturer_active`
- `somcp_create_manufacturer_address`
- `somcp_update_manufacturer_address`
- `somcp_delete_manufacturer_address`
- `somcp_create_supplier`
- `somcp_update_supplier`
- `somcp_set_supplier_active`
- `somcp_create_attribute_group`
- `somcp_update_attribute_group`
- `somcp_delete_attribute_group`
- `somcp_create_attribute_value`
- `somcp_update_attribute_value`
- `somcp_delete_attribute_value`
- `somcp_create_feature`
- `somcp_update_feature`
- `somcp_delete_feature`
- `somcp_create_feature_value`
- `somcp_update_feature_value`
- `somcp_delete_feature_value`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you touch the catalogue in a
conversation, and name the shop in your first answer. Once is enough.

One connection reaches one shop. A merchant running several will read "no
product found" as being about the shop they have in mind, which may not be the
one that answered. The currency of the prices comes from there too.

## Workflow

1. Work out what the merchant gave you. A name, a reference, an EAN13 or an
   identifier: pass it to `somcp_search_products` in the matching filter, and
   nothing more. An unasked-for filter silently hides products.
2. A reference or an EAN13 also finds a product through one of its
   combinations. When a product comes back with `matched_combinations`, the
   merchant was most likely holding that variant: say which one.
3. Read `total_count`, not the length of the list. Twenty results out of four
   hundred is not "twenty products". Each line already holds the name of the
   default category (`category_default`) and the cover image (`cover_image`,
   a `download_ref` for `somcp_download_file`, null without one): do not read
   each product to learn them.
4. When the merchant asks for several matches, show them the list and let them
   pick. Never choose one for them.
5. To answer about one product - its description, carriers, images, suppliers,
   a combination - read it with `somcp_get_product`, by the identifier the
   search returned. When the search named a matching combination, pass its
   `id_product_attribute` too. The read also holds the minimum quantity for
   sale and the low stock level and alert (`stock`, and on each combination),
   the products of a pack (`pack.items`) and its stock behaviour
   (`stock.pack_stock_type`), and the downloadable file settings of a virtual
   product (`virtual_file`, null without a file).

A pack is created with its products (`pack.items`): find each one with
`somcp_search_products`, take a combination from `somcp_get_product`, and let
the merchant pick - never a guessed identifier. A pack cannot hold a pack. A
virtual product is created with its file (`virtual_file`), given by `url` or
`upload_ref` only, never by its content, with the file name customers see.
Before taking a product out of a pack (`remove_pack_items`) or deleting the
file of a virtual product (`remove_virtual_file`), name it to the merchant:
customers who bought the file can no longer download it.

## Images and files

A file never travels in the conversation: an image, a logo or the file of a
virtual product is given by `url` or by `upload_ref`.

1. **A public `https` address**: give it as `url`. The shop downloads it.
2. **A file you have but no address for** (one you made, or one the merchant
   gave you): call `somcp_upload_file` with `for` (the tool) and `field` (its
   argument, e.g. `images`), then follow its answer in order, each step only
   if the one before failed: post the file yourself to the address it gives
   (if your own environment blocks it, tell the merchant once what the answer
   says to allow); hand it over with `somcp_upload_file` and that `upload_ref`
   (`file`, or `data` with its `name`); and only then call it with the
   `upload_ref` alone, which gives you the merchant's deposit page. Then give
   the `upload_ref` to the tool, once.
3. **A file attached in the built-in chat** already has its `upload_ref`.

## Identifiers are never guessed

Product, combination and category identifiers differ from one shop to the
next. Take a product identifier from a search result, and a category
identifier from `somcp_list_categories` when the shop offers it - otherwise ask
the merchant which category they mean. A guessed category filter returns the
wrong products with total confidence.

Two categories can share a name in different branches (Men > T-shirts, Women >
T-shirts). When the name the merchant gave comes back more than once, read
each one with `somcp_get_category` when the shop offers it, show the merchant
the matches with their parent, and let them pick before searching or writing.

To answer about one category - its descriptions, customer groups, friendly
URL, SEO texts, images, how many products it holds - read it with
`somcp_get_category`, by the identifier the list gave.

A brand is taken the same way, from `somcp_list_manufacturers`: never write an
`id_manufacturer` the list did not give. When the brand the merchant names is
not in the list, say so rather than picking the closest one. To answer about
one brand - its descriptions, SEO texts, logo, how many products it holds -
read it with `somcp_get_manufacturer`, by the identifier the list gave. The
postal addresses of the brands - contact name, postal code, city, country - are
listed with `somcp_list_manufacturer_addresses`, for every brand or for one
brand given by the identifier the list gave; never assume an `id_address`. To
answer about one address - its street lines, state, phone numbers, national ID
number - read it with `somcp_get_manufacturer_address`, by the `id_address` the
list gave.

A supplier too, from `somcp_list_suppliers`: never write an `id_supplier` the
list did not give, and when the supplier the merchant names is not in it, say
so. To answer about one supplier - its contact details, description, SEO texts,
logo, how many products it supplies - read it with `somcp_get_supplier`, by the
identifier the list gave.

Attribute values - a size, a colour - come from `somcp_list_attributes`: a
combination is a set of `id_attribute`, never of names. Two groups can hold a
value of the same name, so check the group as well as the value, and ask the
merchant when the name they gave sits in more than one.
To answer about one attribute group - its public name, its type (a list, radio
buttons or colour swatches), its position, how many values it holds, its
faceted search URL word, title tag and indexing - read it with
`somcp_get_attribute_group`, by the identifier the list gave. Those three
faceted search fields are null when the shop has no faceted search module.
To answer about one value - its name, its group, its position in the group,
its colour and texture for a colour group, its faceted search URL word and
title tag - read it with `somcp_get_attribute_value`, by the `id_attribute`
the list gave. The colour and the texture are null when the group is not a
colour group, since the product page does not show them there.

Features - a composition, a material - come from `somcp_list_features`: take
the `id_feature` and the `id_feature_value` from there. Only predefined values
are listed; when the value the merchant wants is not among them, it is a custom
value written on that product alone, so say so before writing it.
To answer about one feature - its position, how many predefined values it
holds, its faceted search URL word, title tag and indexing - read it with
`somcp_get_feature`, by the identifier the list gave. Those three faceted
search fields are null when the shop has no faceted search module.
To answer about one predefined value - its text, its feature, its faceted
search URL word and title tag - read it with `somcp_get_feature_value`, by the
`id_feature_value` the list gave. A custom value is refused there: it belongs
to one product and is read with `somcp_get_product`.

Files - a manual, a data sheet - come from `somcp_list_attachments`: take the
`id_attachment` from there. Files cannot be added through the conversation;
when the one the merchant wants is not listed, they upload it in the back
office first. To open one, pass its `download_ref` to `somcp_download_file`,
as for every file of the shop.

Carriers come from `somcp_list_carriers`: give its `id_carrier` in
`shipping.carriers`, never a guessed one. A disabled carrier is listed too;
say so before tying a product to it. No carrier given means every carrier
may deliver the product. To tell which zones and customer groups a carrier
serves before tying it, read it with `somcp_get_carrier`.
`somcp_get_shipping_preferences` gives the handling charges, the price and
weight from which shipping is free and the default carrier: read it before
telling a customer what an order pays for shipping.
`somcp_update_shipping_preferences` changes them: only the fields given, and
the default carrier by the `id_carrier` of `somcp_list_carriers`. Free
shipping from a price or a weight applies to every carrier, whatever its
ranges: say so to the merchant before setting it.
`somcp_get_payment_preferences` gives, for each payment module, the
currencies, customer groups, countries and carriers it is offered to: read it
before telling the merchant the shop can take payments in a new country or
currency, since a module offered to none of them is not shown at the checkout.
`somcp_update_payment_restrictions` changes the currencies, customer groups,
countries and carriers one payment module is offered to: each list given replaces the stored one, so
read the module first and give back its whole list with the change. A module
left with an empty list is shown to nobody: say so to the merchant
before emptying a list.
`somcp_list_contacts` lists the contacts of the storefront contact form, with
their address and which one receives the customers' messages: read it before
changing Send emails to, and give its id_contact as it is.
`somcp_create_contact` adds a contact to that form, with a title in every
language being filled: ask the merchant for the title and the address, never
invent them, and read the list first, since two contacts may share a title.
`somcp_update_contact` changes a contact: give only what changes, or its list
item back with the change. Which contact receives the customers' messages is
chosen with `somcp_update_email_settings`, not here.
`somcp_delete_contact` deletes a contact for good: ask the merchant first, and
keep its answer, which holds what it takes to create it again. The contact that
receives the customers' messages is refused: choose another receiver first.
`somcp_get_email_settings` says how the shop sends its e-mails (sendmail, SMTP
or never) and where customer messages go: read it before telling a merchant
why a customer got no order confirmation. It says whether the SMTP password
and the DKIM key are set, never their value: never ask the merchant for them.
`somcp_update_email_settings` changes those settings; give back the read with
the change. It never takes the SMTP password nor the DKIM key: when one is
missing, ask the merchant to type it on the E-mail page of the back office.
Never set the method to never without the merchant's clear say-so: customers
would then get no order confirmation.
`somcp_list_email_logs` lists the e-mails the shop sent, newest first: read it
to check that the order and account e-mails really go out. It only grows while
Log Emails is on; each line holds a customer's address, so quote only what the
merchant needs.
`somcp_delete_email_log` deletes one line of that list, for example the line
of a customer who asked to be forgotten: ask the merchant first, it cannot be
undone, and the e-mail itself stays sent.
`somcp_erase_email_logs` empties the whole list, as its Erase all button: on a
multistore installation the lines of every shop go. Ask the merchant first, it
cannot be undone; for one customer use `somcp_delete_email_log` instead.
`somcp_send_test_email` sends the test e-mail of Advanced Parameters > E-mail
with the saved settings, to the shop's own address unless another is given:
call it after changing those settings, ask the merchant first, and give the
server's message as it comes when it fails. A success only means the shop
handed the e-mail over: tell the merchant to check the inbox, spam included.
`somcp_get_order_settings` gives the checkout rules of Order Settings: guest
checkout, the minimum purchase total, the terms of service and their page,
gift wrapping and recycled packaging. Read it before telling a merchant why a
customer could not place an order.
`somcp_update_order_settings` changes those settings; give back the read with
the change. Before turning guest checkout off, tell the merchant that visitors
will have to create an account to order.
`somcp_get_product_settings` gives Product Settings: catalog mode, stock
management and ordering out of stock with the labels customers read, the
product page options and products per page. Read it before telling a merchant
why visitors cannot add a product to the cart.
`somcp_update_product_settings` changes the four cards, the default stock
labels and delivery times included; give back the read with the change. Never
turn catalog mode on, or stock management off, without the merchant's clear
say-so: visitors could no longer order, or could order what is not there.
`somcp_get_customer_settings` gives Customer Settings: the cart shown again at
login, the e-mail after registration, the password reset delay, B2B mode, the
birth date field and partner offers. Read it to know whether the shop is B2B
before explaining the fields of an account.
`somcp_update_customer_settings` changes them; give back the read with the
change. Before turning partner offers off, check that no customer field makes
them required, or new customers could not sign up.
`somcp_list_titles` lists the social titles (Mr., Mrs.) with their gender and
name in each language: take the id_gender a customer tool needs from it.
`somcp_create_title` adds a title the shop lacks, with its gender and an
optional image; check the list first, since two titles may share a name, and
call it once: a second call makes a second title. `somcp_update_title`
renames a title in one language or more, changes its gender or replaces its
image (an image cannot be removed); give it back the item of the list with
only the fields to change. `somcp_delete_title` deletes a title for good, its
image with it: ask first, and say how many customers hold it, since their
accounts then show no title; the answer gives it back as it was, for
`somcp_create_title`.
The zones of the shop, with their `id_zone` and the number of countries in
each, come from `somcp_list_zones`. A zone the merchant needs and the shop
lacks is created with `somcp_create_zone`, after checking the list: it starts
with no country. `somcp_update_zone` renames a zone or allows or stops shipping
to it; before stopping it, tell the merchant which carriers serve it.
`somcp_delete_zone` deletes a zone with no country and no state; the carriers
that served it lose it and their prices for it, so read them with
`somcp_get_carrier` and tell the merchant first. The countries, with their ISO code, their
zone and whether they are enabled, come from `somcp_list_countries`; every
field of one country, its default currency, address format and tax label
included, from `somcp_get_country`; `somcp_update_country` changes them,
and a new zone takes along the states that were in the old one.
`somcp_get_country_options` tells whether customers may only pick a country
an active carrier delivers: read it before enabling a country;
`somcp_update_country_options` switches that restriction on or off. The
states of a country, with the ISO code `state` and `states` take, come from
`somcp_list_states`. A state the merchant needs and the shop lacks is created
with `somcp_create_state`, in a country that uses states; ask for its zone,
proposing the zone of its country. `somcp_update_state` renames a state,
changes its ISO code or zone, or switches it off so the address form stops
offering it; a state used by addresses or tax rule rows keeps its country.
`somcp_delete_state` deletes a state no address and no tax rule row uses;
give its rows to `somcp_delete_country_rule` first, and tell the merchant.
To rename a carrier, change its transit time, speed grade, logo, tracking URL,
status, customer groups, zones, ranges and their prices per zone, place in the
list, free shipping, handling costs, billing, tax, out-of-range behavior or size and weight limits, use
`somcp_update_carrier`; a carrier an order uses gets a new `id_carrier`,
given in the answer: use that one from then on. A move alone keeps the
identifier.
To remove a carrier for good, confirm it with the merchant by name, then use
`somcp_delete_carrier`; when they may want it back, turn it off with
`somcp_update_carrier` instead.

Tax rules come from `somcp_list_tax_rules`: take the `id_tax_rules_group` from
there, 0 for no tax. Match the merchant's "20 %" on the `rate`, not on the
name, and say that the rate is the one of the country given: a customer
delivered elsewhere may pay another one. When `taxes_enabled` is false the
shop charges no tax at all, whatever the rule.
For the rate a customer delivered abroad pays, read the rule with
`somcp_get_tax_rule`: its `country_rules` give the tax per country, state and
zip code range, and `country` narrows them to one country.
The taxes those rows apply, with their `id_tax` and rate, come from
`somcp_list_taxes`, which `rate` narrows to the taxes at exactly that rate;
a product never takes a tax, only a tax rule.
Whether those rates are charged at all, on the invoice or the delivery
address, and with which ecotax, comes from `somcp_get_tax_options`: read it
before setting up taxes, and say so when `taxes_enabled` is false.
They are changed with `somcp_update_tax_options`: give only the fields to
change, or the answer of `somcp_get_tax_options` back with the changes in it.
Turning `use_ecotax` off sets the ecotax of every product to 0, which nothing
gives back: when products carry one, the tool refuses until
`reset_product_ecotax: true` is given, and that is only given once the merchant
has said so.

A rate a shop does not have yet is created with `somcp_create_tax`, after
reading `somcp_list_taxes`: give one `name` per language and a `rate` from 0 to
100. It is created disabled unless `active` is `true`, and a tax rule can only
charge an enabled tax, so enable it before giving its `id_tax` to
`somcp_add_country_rule`. The name is the merchant's; never invent a rate.

A tax is changed with `somcp_update_tax`: give `id_tax` and only the fields to
change (`name`, `rate`, `active`). Changing a `rate` changes it for every tax
rule that charges the tax, without touching the rules. On PrestaShop 8,
disabling a tax that tax rules charge would delete those rows; the tool refuses
it, so move the rows with `somcp_update_country_rule` first. A tax carried by
past orders is kept for them and the change gets a new `id_tax`, which the
answer gives.

A tax is deleted with `somcp_delete_tax`, after confirming with the merchant by
name: it is irreversible. It is refused while tax rule rows still charge it, so
give those countries another tax with `somcp_update_country_rule` or delete the
rows with `somcp_delete_country_rule` first; to keep a tax for later, switch it
off with `somcp_update_tax` instead. A tax carried by past orders is kept hidden
for them and gone from the list.

A missing tax rule is created with `somcp_create_tax_rule`, after reading
`somcp_list_tax_rules` with `active: true` and with `active: false` (the
enabled rules, then the disabled ones): two rules may carry the same name.
The name is the merchant's; the rule is created without countries and
disabled unless `active` is `true`.

The Name of a rule, and whether it is enabled, are changed with
`somcp_update_tax_rule`, which gives the old and new value of each field it
changed. A rule that orders already placed carry stays unchanged for them and
the change gets a new identifier: the one in the answer is used from then on,
and the merchant is told the products and carriers moved to it.

A rule is deleted with `somcp_delete_tax_rule` only after reading it with
`somcp_get_tax_rule` and asking the merchant, by its name: the deletion cannot
be undone, and switching it off with `somcp_update_tax_rule` keeps it for
later. It is refused while products or carriers still take it; the products
get another rule with `somcp_update_product` first.

A rule charges a tax only through its rows: one is added with
`somcp_add_country_rule` after reading the rule with `somcp_get_tax_rule`,
with the tax from `somcp_list_taxes`, and shown to the merchant first. The
same call twice adds the row twice, so a lost answer is checked by reading
the rule again. A rule used by past orders gets a new identifier, given in the
answer, as for a change. A row is changed in place with
`somcp_update_country_rule`, giving its `id_tax_rule` and only what changes,
after showing the merchant the change; a second state is a second row, added
with `somcp_add_country_rule`. A row is deleted with `somcp_delete_country_rule`,
by the `id_tax_rule` the rule gives, after asking the merchant: a rule left
without rows charges no tax to the products that take it.

## Who fills each field

Every field of `somcp_create_product` and `somcp_update_product` says at the
start of its description who provides it, and that is a rule for you:

- **Merchant** - ask the merchant and never invent the value; you may suggest
  one for them to confirm.
- **Merchant, from the shop** - show the list read with the matching
  `somcp_list_*` tool (categories, brands, carriers, tax rules, suppliers,
  attributes, features, attachments, customer groups) and let the merchant
  pick; never guess an identifier, and never create the object - the tool
  refuses one that does not exist.
- **Assistant** - write it yourself in every language being filled, and show it
  to the merchant before the call.
- **Merchant and assistant** - the merchant gives the material, you prepare the
  rest and show it first.

Texts are objects keyed by ISO code, given for every language in `languages`; a
language of the shop left out takes the default language's text. Lengths count
the visible text, spaces included, HTML tags not counted. The ISO codes of the
shop's languages, which one is the default and which are enabled, come from
`somcp_list_languages`: only an enabled language is accepted. A language is
added with `somcp_create_language`, its flag and "No-picture" image given, and
every text of the shop is copied into it from the default language: tell the
merchant they are to translate. A language's
name, ISO code, date formats, status, flag and "No-picture" image are changed
with `somcp_update_language`; the default language stays enabled, switching one off
moves the employees who use it to the default, and a new ISO code moves the
translation files and e-mail templates of the old one: confirm with the
merchant first. `somcp_delete_language` deletes a language with every
text of the shop in it, which cannot be undone: offer to switch it off first,
and confirm with the merchant before deleting. A language with PrestaShop's
own translations of the storefront, the e-mails and the back office is added,
or those translations of an installed one replaced, with
`somcp_install_language_pack`: it reaches PrestaShop's server and can take two
minutes, and an update replaces the texts PrestaShop ships (order statuses,
page titles) in that language: confirm with the merchant first.
`somcp_export_translations` builds the translation pack of a language - the
core parts, a theme, a module - as a zip of XLF files, as Export a language
does, and answers a `download_ref` for `somcp_download_file`, kept 15 minutes:
it changes nothing, and the pack is what another shop imports.
`somcp_list_translations` reads the wordings as Modify translations shows
them: without `domain`, the domains of a type with their counts; with one, its
wordings, each with its `source`, the translation of the files and the shop's
own (`shop_translation`, `null` when the shop has none).
`somcp_update_translation` saves the shop's own translation of one wording, or
removes it with `reset` so the translation of the files shows again, as Save
and Reset of the same card: give the `domain` and the `source` the list
answered, keep the placeholders (`%s`, `%d`) of the source, and remember the
change reaches every shop.
`somcp_get_localization_settings` says which language, country and currency
the shop starts from, its time zone, and the weight, distance, volume and
dimension units every product weight and carrier range is read in: read it
before typing a weight or a size. `somcp_update_localization_settings`
changes them; a unit is only a label, so values already typed are not
converted: confirm with the merchant before changing a unit of a shop that
already has products or carrier ranges. A new default currency resets every
exchange rate and converts no price, each keeps its figure in the new
currency: confirm with the merchant, who may want maintenance mode on first.
`somcp_import_localization_pack` brings a country's states, taxes and tax
rules, currencies, languages, units and price display in one call, from the
shop's own pack (currencies and languages are fetched from PrestaShop's
servers, and are created enabled): use it
when a shop is set up for a country, rather than creating each state and tax
by hand. It creates and is not undone, and it overwrites the units: confirm
with the merchant first.
`somcp_get_general_settings` gives the price rounding (Round mode and Round
type, which decide every price shown and every total invoiced) and whether
the storefront shows a suppliers, a brands and a best sellers page.
`somcp_update_general_settings` changes them: a new rounding applies to new
carts and orders only, those already made keep their totals; confirm with the
merchant before changing it. `somcp_get_maintenance_settings` says whether
the storefront is open to visitors or shows the maintenance text: read it
before telling the merchant the shop is live.
`somcp_update_maintenance_settings` opens or closes the storefront and changes
its maintenance text: close or open the shop only when the merchant asks, and
reopen it when the set-up they asked for is done.

## Prices

Every price is either **tax included** or **tax excluded**: the field name says
which, and so must you. Always give the currency with an amount. The ISO codes
of the shop's currencies, the default and the enabled ones come from
`somcp_list_currencies`; the decimals, the price format and whether a
currency is an alternative one, from `somcp_get_currency`. An official
currency the shop does not have yet is added with `somcp_create_currency`, its
ISO code and the exchange rate the merchant gives; the shop fills its names and
symbols, and payment modules accept it only once set in Payment > Preferences.
A currency PrestaShop does not know (a local or loyalty currency) is added by
the same tool with `alternative`, and the name and symbol the merchant gives.
A currency's
exchange rate, decimals, status, names, symbols and symbol position are changed
with `somcp_update_currency`, which also restores the default settings: the
default currency keeps a rate of 1 and cannot be switched off. Every rate is
updated at once from PrestaShop's exchange rates with
`somcp_update_exchange_rates`, which names the currencies those rates leave
out. A
currency is deleted with `somcp_delete_currency`, never the default one nor one
a country uses; orders placed in it keep it.

The prices returned are the base price of the product, before specific prices,
discounts and combination impacts. A customer may pay something else: say so
when the merchant asks what a customer pays. `somcp_get_product` lists the
specific prices apart, with who they apply to and when: never present one of
them as the product's price. The ecotax is apart too (`ecotax_tax_excl`,
`ecotax_tax_incl`), and is not included in `price_tax_incl`.

A customer group - for a specific price, or for the groups that see a
category - comes from `somcp_list_customer_groups`: take the `id_group` from
there, never a guessed one. When `customer_groups_enabled` is false the shop
ignores groups for prices: say so before writing a price for one group.
`somcp_get_customer_group` reads one group in full: its name in every
language, its discounts by category (a percent that replaces the group
discount for the products whose default category it is) and the modules its
members may use. `somcp_update_customer_group` changes its name, Discount (a
percent, 5 for 5 %), Price display method, Show prices, its category
discounts and its authorized modules: give back the read with only the fields to change, and say when the
group is a default one, since the change then reaches every visitor, guest or
customer. The category discounts are always the whole list: read it, add or
remove a row, and give the whole list back, since a row left out is removed.
Take each `id_category` from `somcp_list_categories`, and say that a category
discount replaces the group Discount for those products, and that the group
can then see the category. The authorized modules are the whole list of the
modules set to Yes, by the technical name of `somcp_list_modules`: every
module left out is set to No, and at least one must stay. Say which payment
modules the group loses, since its members can no longer pay with them.

## Changing products

When the tools to change the catalogue are available:

- One product per write. If the merchant names several, change them one at a
  time and report each result.
- A write is **never retried** on a lost answer. Search for the product again
  and check whether the change went through before doing anything else.
- Switching a product off hides it from the shop and is reversible. Deleting a
  product is not. Tell the merchant which of the two is about to happen, in one
  line, before doing it.

## Creating a product

`somcp_create_product` creates one product, offline unless the merchant asks
for it to go live. Before calling it:

1. Search first with `somcp_search_products`, by the reference or the name: the
   product may already exist, and then it is a change, not a creation.
2. Each field says who fills it. Ask the merchant for what is theirs (name,
   price, reference, stock, dimensions) and never invent it. Take categories,
   brands, carriers, tax rules, suppliers, attributes and features from the
   shop's own lists and let the merchant pick. Write the summary, the
   description and the SEO texts yourself, in every language being filled.
3. Show the merchant the whole product - texts, prices with or without tax,
   combinations - and call once they agree.

The answer lists every problem at once when the call is refused; nothing was
created then. When it succeeds, show the merchant the product as returned,
the `seo_warnings`, and any image in `images` that was refused, with its
reason.

## Changing a product

`somcp_update_product` changes one existing product. Before calling it:

1. Read the product with `somcp_get_product`, so you change what the shop
   holds and not what you remember of it.
2. Give only what changes. A list that is a set - categories, carriers,
   features, related products, documents, customization fields - replaces the
   current one: give the whole set, not just what is added. Combinations,
   images, specific prices and suppliers are only removed when named in their
   `remove_` list; name each one to the merchant first, since a removed
   combination takes its stock with it.
3. To change a text in one language, pass `languages` with that language
   alone; otherwise every language of the shop must be given. A new
   `link_rewrite` on an active product breaks the old address of its page:
   say so before.
4. Show the merchant what will change, with the old value next to the new one,
   and call once they agree.

The answer lists each field changed with its old and new value: show it to the
merchant, with the `seo_warnings` and any refused image. `changed: false`
means the product already held those values and nothing was written. If the
call fails part way, the answer names what was already saved: read the
product again and tell the merchant before anything else.

## Switching a product on or off

`somcp_set_product_active` puts one product online or takes it offline, and
changes nothing else: use it rather than `somcp_update_product` when the
merchant only asks for that. Taking a product offline hides it from customers
and can be undone; say so, so the merchant does not mistake it for a deletion.
`changed: false` means the product already had that status.

## Deleting a product

`somcp_delete_product` deletes one product for good, with its combinations,
images and stock. Before calling it:

1. Read the product with `somcp_get_product` and name it to the merchant by its
   name and reference.
2. Offer to switch it off with `somcp_set_product_active` instead: it hides the
   product and can be undone. Delete only once the merchant confirms.

Orders that contain the product keep their lines as sold. When
`still_in_shops` is above 0, the product is gone from this shop only: tell the
merchant, who removes it from a connection to each of the other shops. On a lost
answer, search for the product again rather than calling twice.

## Creating a category

`somcp_create_category` creates one category, under Home and displayed unless
the merchant says otherwise. Before calling it:

1. Read `somcp_list_categories`: the category may already exist, and the
   parent is taken from there, never guessed. Two categories can share a name
   in different branches, so name the parent's branch to the merchant.
2. Ask the merchant for the name, the parent, whether it is displayed and
   which customer groups see it. Write the descriptions and the SEO texts
   yourself, in every language being filled, and show them first. Lengths
   count the visible text, spaces included, HTML tags not counted. A language
   of the shop left out of `languages` takes the default language's text.
3. An image is given by `url` (a public `https` address the shop downloads) or
   by `upload_ref` (a file already on the shop - one attached in the chat, or
   one from `somcp_upload_file`); one of the two, never both.

When it succeeds, show the merchant the category as returned, the
`seo_warnings`, and any image in `images` that was refused, with its reason. On
a lost answer, read `somcp_list_categories` again rather than calling twice: a
second call creates a second category.

## Changing a category

`somcp_update_category` changes one existing category. Before calling it:

1. Take the category from `somcp_list_categories`, never guessed, and name its
   branch to the merchant: two categories can share a name. Read it with
   `somcp_get_category` to know what it holds before changing it.
2. Give only what changes. `groups` replaces the current set, and new menu
   thumbnails replace the current ones; the cover, the category thumbnail
   (`thumbnail_image`) and the menu thumbnails are only removed through
   `remove_cover_image`, `remove_thumbnail_image` (PrestaShop 9 only) and
   `remove_menu_thumbnails` (PrestaShop 8 only).
3. To change a text in one language, pass `languages` with that language alone.
   A new `link_rewrite` on a displayed category breaks the old address of its
   page: tell the merchant before.

`position` moves the category among those of the same parent, from 0 as
`somcp_get_category` gives it; the others shift. Home can be changed like any
category, except that it is never moved: no `id_parent` and no `position`, and
on PrestaShop 9 only 404 or 410 while hidden. The root category is changed in
the back office, not here. When it
succeeds, show the merchant each change with its old and new value, the
`seo_warnings`, and any image in `images` that was refused. `changed: false`
means the category already held those values. On a lost answer, read the
category again before calling twice.

## Showing or hiding a category

`somcp_set_category_active` shows or hides one category on the storefront, and
changes nothing else: use it rather than `somcp_update_category` when the
merchant only asks for that. Take the category from `somcp_list_categories` and
name its branch. Tell the merchant that hiding it does not take its products
offline: they stay reachable through search and their other categories. On a
multistore installation the change shows in every shop sharing the category.
`changed: false` means it was already in that state; the root category and Home
cannot be hidden. On a lost answer, read it with `somcp_get_category` before
calling twice.

## Deleting a category

`somcp_delete_category` deletes one category for good, with all its
subcategories. Before calling it:

1. Read the category with `somcp_get_category` and tell the merchant how many
   subcategories go with it (`children_count`) and how many products it holds
   (`products_count`).
2. Ask what becomes of the products left in no category, as the back office
   asks: moved to the parent and switched off (`associate_and_disable`, the
   one it recommends), moved only (`associate_only`), or deleted as well
   (`remove_associated`). Say plainly that the last one deletes products.
3. Offer to hide it with `somcp_set_category_active` instead, which can be
   undone, and delete only once the merchant confirms by the category's name.

Products that keep another category stay in it. Show the merchant the lists
the answer gives: the subcategories deleted, the products moved, switched off
or deleted, and those whose default category changed. The root category and
Home cannot be deleted. On a lost answer, look with `somcp_list_categories`
before calling twice.

## Creating a brand

`somcp_create_manufacturer` creates one brand, enabled unless the merchant says
otherwise. Before calling it:

1. Read `somcp_list_manufacturers`: the brand may already exist, and the shop
   accepts two brands of the same name.
2. Ask the merchant for the name, whether the brand is enabled and its logo.
   The name is one text, the same in every language. Write the
   descriptions and the SEO texts yourself, in every language being filled, and
   show them first.
3. The logo is an object: `url`, a public `https` address the shop downloads,
   or `upload_ref`, a file already on the shop (attached or deposited by the
   merchant, or generated in the built-in assistant), exactly as given in the
   conversation. One of the two, never both, never an address you made up.

When it succeeds, show the merchant the brand as returned, the `seo_warnings`,
and the logo if it was refused, with its reason. On a lost answer, read
`somcp_list_manufacturers` again rather than calling twice: a second call
creates a second brand.

## Changing a brand

`somcp_update_manufacturer` changes one existing brand. Before calling it:

1. Take the brand from `somcp_list_manufacturers`, never guessed, and read it
   with `somcp_get_manufacturer` to know what it holds before changing it.
2. Give only what changes. A new logo replaces the current one; the logo is
   only removed through `remove_logo`. The name is one text, the same in every
   language.
3. To change a text in one language, pass `languages` with that language alone.

When it succeeds, show the merchant each change with its old and new value, the
`seo_warnings`, and the logo if it was refused, with its reason.
`changed: false` means the brand already held those values. On a lost answer,
read the brand again with `somcp_get_manufacturer` before calling twice.

## Enabling or disabling a brand

`somcp_set_manufacturer_active` enables or disables one brand, and changes
nothing else: use it rather than `somcp_update_manufacturer` when the merchant
only asks for that. Take the brand from `somcp_list_manufacturers` and name it.
Tell the merchant that disabling it hides its page on the storefront, while its
products stay on sale. On a multistore installation the change shows in every
shop sharing the brand. `changed: false` means it was already in that state. On
a lost answer, read it with `somcp_get_manufacturer` before calling twice.

## Adding a brand address

`somcp_create_manufacturer_address` adds one postal address to a brand. Before
calling it:

1. Take the brand from `somcp_list_manufacturers`, never guessed, and read
   `somcp_list_manufacturer_addresses` for that brand: the address may already
   be there, and the shop accepts the same address twice.
2. Ask the merchant for every field - at least the last name, the first name,
   the address, the city and the country, the state when the country has
   states, the DNI when the country asks for one. Never invent a name, a phone
   number or an address. Each field is one text, the same in every language.

When it succeeds, show the merchant the address as returned. On a lost answer,
read `somcp_list_manufacturer_addresses` for the brand again rather than
calling twice: a second call adds a second address.

## Changing a brand address

`somcp_update_manufacturer_address` changes one brand address. Take it from
`somcp_list_manufacturer_addresses` and read it with
`somcp_get_manufacturer_address` first, then show the merchant what will change
and call once they agree. Give only what changes: the rest is kept.
`id_manufacturer` moves the address to another brand. When the country changes,
give the state again if the new country has states. Customer and supplier
addresses are refused. The answer lists each change with its old and new value;
`changed: false` means the address already held every value given. On a lost
answer, read it again with `somcp_get_manufacturer_address` before calling
twice.

## Deleting a brand address

`somcp_delete_manufacturer_address` deletes one brand address for good; the
brand itself is kept. Take the address from
`somcp_list_manufacturer_addresses`, read it with
`somcp_get_manufacturer_address`, and name the brand and the city to the
merchant. Delete only once they confirm. Customer and supplier addresses are
refused. On a lost answer, read `somcp_list_manufacturer_addresses` for the
brand again rather than calling twice.

## Creating a supplier

`somcp_create_supplier` creates one supplier, enabled unless the merchant says
otherwise. Before calling it:

1. Read `somcp_list_suppliers`: the supplier may already exist, and the shop
   accepts two suppliers of the same name.
2. Ask the merchant for the name, the contact details - at least the address,
   the city and the country, the state when the country has states, the DNI
   when the country asks for one - whether the supplier is enabled and the
   logo. Never invent a phone number or an address. Write the
   description and the SEO texts yourself, in every language being filled, and
   show them first.
3. The logo is an object: `url`, a public `https` address the shop downloads,
   or `upload_ref`, a file already on the shop (attached or deposited by the
   merchant, or generated in the built-in assistant), exactly as given in the
   conversation. One of the two, never both, never an address you made up.

When it succeeds, show the merchant the supplier as returned, the
`seo_warnings`, and the logo if it was refused, with its reason. On a lost
answer, read `somcp_list_suppliers` again rather than calling twice: a second
call creates a second supplier.

## Changing a supplier

`somcp_update_supplier` changes one existing supplier. Before calling it:

1. Take the supplier from `somcp_list_suppliers`, never guessed, and read it
   with `somcp_get_supplier` to know what it holds before changing it.
2. Give only what changes. A new logo replaces the current one; the logo is
   only removed through `remove_logo`. The name and each contact detail are one
   text, the same in every language. Never invent a phone number or an address.
3. When the country changes, ask for the state again if the new country has
   states, and for the DNI if it asks for one. To change a text in one
   language, pass `languages` with that language alone.

When it succeeds, show the merchant each change with its old and new value, the
`seo_warnings`, and the logo if it was refused, with its reason.
`changed: false` means the supplier already held those values. On a lost
answer, read the supplier again with `somcp_get_supplier` before calling twice.

## Enabling or disabling a supplier

`somcp_set_supplier_active` enables or disables one supplier, and changes
nothing else: use it rather than `somcp_update_supplier` when the merchant only
asks for that. Take the supplier from `somcp_list_suppliers` and name it. Tell
the merchant that disabling it hides its page on the storefront, while it stays
attached to its products. On a multistore installation the change shows in
every shop sharing the supplier. `changed: false` means it was already in that
state. On a lost answer, read it with `somcp_get_supplier` before calling twice.

## Creating an attribute group

`somcp_create_attribute_group` creates one attribute group - a size, a colour -
without any value. Before calling it:

1. Read `somcp_list_attributes`: the group may already exist, and the shop
   accepts two groups of the same name.
2. Ask the merchant for the name the back office lists, the name customers see
   on the product page (often the same), in every language being filled, and
   the type: a drop-down list (`select`), radio buttons (`radio`) or colour
   swatches (`color`). When the shop has the faceted search module, write the
   URL word and the title tag yourself and show them first, and ask the
   merchant whether filtered pages on this group are open to search engines
   (`indexable`, off by default). Without that module, leave these three
   fields out: they are refused.
3. The values come afterwards, one per call, with
   `somcp_create_attribute_value`.

When it succeeds, show the merchant the group as returned. On a lost answer,
read `somcp_list_attributes` again rather than calling twice: a second call
creates a second group.

## Changing an attribute group

`somcp_update_attribute_group` changes one existing attribute group. Before
calling it:

1. Take the group from `somcp_list_attributes`, never guessed, and read it with
   `somcp_get_attribute_group` to know what it holds before changing it.
2. Give only what changes. To change a text in one language, pass `languages`
   with that language alone. Without the faceted search module, leave
   `url_name`, `meta_title` and `indexable` out: they are refused.
3. A new public name or type shows on every product page offering the group:
   tell the merchant before calling. Away from `color`, the values keep their
   colours and textures but the product page no longer shows them.
4. To reorder the groups, give `position`, the new place from 0 as
   `somcp_get_attribute_group` gives it: the other groups shift, as with the
   drag and drop of the back office list.

When it succeeds, show the merchant each change with its old and new value,
and the `note` when there is one. `changed: false` means the group already held
those values. On a lost answer, read the group again with
`somcp_get_attribute_group` before calling twice.

## Deleting an attribute group

`somcp_delete_attribute_group` deletes one attribute group for good, with all
its values. Before calling it:

1. Take the group from `somcp_list_attributes`, never guessed, and read it with
   `somcp_get_attribute_group`: tell the merchant how many values go with it
   (`values_count`), which products offer it (`products`), how many
   combinations hold it (`combinations_count`) and how many are built only on
   it (`combinations_only_on_it`), since those go with their stock.
2. Say plainly what goes with it: every combination built only on this group
   is deleted with its stock and images, and the others lose that one value.
3. Delete only once the merchant confirms by the group's name.

Show the merchant what the answer counts: `values_deleted`,
`combinations_deleted`, `combinations_changed` and the `products` concerned.
When `catalog_price_rules` is not empty, those rules still have a condition on
a deleted value and match nothing any more: tell the merchant to change or
remove them. On multistore, `still_in_shops` says how many other stores keep
the group. On a lost answer, read it with `somcp_get_attribute_group`, which
then answers "not found", rather than calling twice.

## Adding an attribute value

`somcp_create_attribute_value` adds one value - a size, a shade - to an
attribute group. Before calling it:

1. Take the group from `somcp_list_attributes`, never guessed, and look for
   the value there: a name the group already holds is refused.
2. Ask the merchant for the name, in every language being filled. For a group
   of type `color` only, ask for the colour as a hex code and, when they want
   a pattern rather than a plain colour, the texture image: its public https
   address (`url`) or a photo already on the shop, such as one attached in the
   chat (`upload_ref`); on any other group, leave both out. When the shop has the
   faceted search module, write the URL word and the title tag yourself and
   show them first; without it, leave them out: they are refused.
3. The value comes last in its group. One value per call.

When it succeeds, show the merchant the value as returned. If `texture` says
`refused`, the value exists without it: tell the merchant why. On a lost
answer, read `somcp_list_attributes` again rather than calling twice.

## Changing an attribute value

`somcp_update_attribute_value` changes one existing attribute value. Before
calling it:

1. Take the value from `somcp_list_attributes`, never guessed, and read it with
   `somcp_get_attribute_value` to know what it holds.
2. Pass only what changes. To change one language, give `languages` with that
   language alone. A new name or colour shows on every combination using the
   value: tell the merchant so and call once they agree. Colour, texture and
   `remove_texture` only go with a group of type `color`.
3. Moving the value to another group (`id_attribute_group`) is refused while a
   combination uses it. A new texture, by `url` or `upload_ref`, replaces the
   current one; the texture given back as read changes nothing.
4. To reorder the values of a group, give `position`, the new place from 0 as
   `somcp_get_attribute_value` gives it: the other values shift, as with the
   drag and drop of the back office list.

When it succeeds, show the merchant each change with its old and new value,
and the `note` when there is one. If `texture` says `refused`, the value keeps
its current texture: tell the merchant why. `changed: false` means the value
already held those values. On a lost answer, read the value again with
`somcp_get_attribute_value` before calling twice.

## Deleting an attribute value

`somcp_delete_attribute_value` deletes one value of an attribute for good.
Before calling it:

1. Take the value from `somcp_list_attributes`, never guessed, and read it with
   `somcp_get_attribute_value`: `combinations_count` and `products` say how
   many combinations go with the value and which products offer them.
2. Say plainly what goes with it: every combination that uses the value is
   deleted with its stock and images, and a product that loses its default
   combination gets a new one.
3. Delete only once the merchant confirms by the value's name.

Show the merchant what the answer counts: the `combinations_deleted`, the
`default_changed` products, and the `cart_rules` that were cleaned. When
`catalog_price_rules` is not empty, those rules still have a condition on the
deleted value and match nothing any more: tell the merchant to change or remove
them. On multistore, `still_in_shops` says how many other stores keep the
value. On a lost answer, read it with `somcp_get_attribute_value`, which then
answers "not found", rather than calling twice.

## Creating a feature

`somcp_create_feature` creates one feature - a composition, a material -
without any value. Before calling it:

1. Read `somcp_list_features`: the feature may already exist, and the shop
   accepts two features of the same name.
2. Ask the merchant for the name, in every language being filled. When the
   shop has the faceted search module, write the URL word and the title tag
   yourself and show them first, and ask the merchant whether filtered pages
   on this feature are open to search engines (`indexable`, off by default).
   Without that module, leave these three fields out: they are refused.
3. The values come afterwards, one per call, with
   `somcp_create_feature_value`.

When it succeeds, show the merchant the feature as returned. On a lost answer,
read `somcp_list_features` again rather than calling twice: a second call
creates a second feature.

## Changing a feature

`somcp_update_feature` changes one existing feature. Before calling it:

1. Take the feature from `somcp_list_features`, never guessed, and read it
   with `somcp_get_feature` to know what it holds before changing it.
2. Give only what changes. To change a text in one language, pass `languages`
   with that language alone. Without the faceted search module, leave
   `url_name`, `meta_title` and `indexable` out: they are refused.
3. A new name shows on every product that carries the feature: tell the
   merchant before calling.
4. To reorder the features, give `position`, the new place from 0 as
   `somcp_get_feature` gives it: the other features shift, as with the drag
   and drop of the back office list.

When it succeeds, show the merchant each change with its old and new value.
`changed: false` means the feature already held those values. On a lost
answer, read the feature again with `somcp_get_feature` before calling twice.

## Deleting a feature

`somcp_delete_feature` deletes one feature for good, with all its values.
Before calling it:

1. Take the feature from `somcp_list_features`, never guessed, and read it
   with `somcp_get_feature`: tell the merchant how many products lose it
   (`products_count`).
2. Say plainly what goes with it: every product that shows the feature loses
   it, with its value, custom values included.
3. Delete only once the merchant confirms by the feature's name.

Show the merchant what the answer counts: `values_deleted` and
`products_count`. When `catalog_price_rules` is not empty, those rules still
have a condition on a deleted value and match nothing any more: tell the
merchant to change or remove them. A feature shared with other stores is refused: it is deleted from
the back office, in the all-stores context. On a lost answer, read it with
`somcp_get_feature`, which then answers "not found", rather than calling twice.

## Adding a value to a feature

`somcp_create_feature_value` adds one predefined value - Cotton, Linen - to an
existing feature. Before calling it:

1. Take the feature from `somcp_list_features`, never guessed, and look at the
   values it already holds: a value whose text it already has is refused.
2. Ask the merchant for the value, in every language being filled. When the
   shop has the faceted search module, write the URL word and the title tag
   yourself and show them first. Without that module, leave `url_name` and
   `meta_title` out: they are refused.
3. To give one product a text of its own rather than a value every product can
   take, use `somcp_update_product` with a custom value instead.

When it succeeds, show the merchant the value as returned. On a lost answer,
read `somcp_list_features` again rather than calling twice.

## Changing a feature value

`somcp_update_feature_value` changes one existing predefined value. Before
calling it:

1. Take the value from `somcp_list_features`, never guessed, and read it with
   `somcp_get_feature_value` to know what it holds.
2. Pass only what changes. To change one language, give `languages` with that
   language alone. Changing the text changes it on every product that carries
   the value: tell the merchant so and call once they agree.
3. Moving the value to another feature (`id_feature`) is refused while a
   product carries it. A value typed on one product only is changed with
   `somcp_update_product` instead.

When it succeeds, show the merchant each change with its old and new value.
`changed: false` means the value already held those values. On a lost answer,
read the value again with `somcp_get_feature_value` before calling twice.

## Deleting a feature value

`somcp_delete_feature_value` deletes one predefined value of a feature for
good. Before calling it:

1. Take the value from `somcp_list_features`, never guessed, and read it with
   `somcp_get_feature_value`: tell the merchant how many products lose it
   (`products_count`).
2. Say plainly that every product that has the value loses it. A value typed
   on one product only is changed with `somcp_update_product`; to delete the
   whole feature, use `somcp_delete_feature`.
3. Delete only once the merchant confirms by the value's text.

Show the merchant `products_count` from the answer. When `catalog_price_rules`
is not empty, those rules still have a condition on the deleted value and
match nothing any more: tell the merchant to change or remove them. A value of
a feature shared with other stores is refused: it is deleted from the back
office, in the all-stores context. On a lost answer, read it with
`somcp_get_feature_value`, which then answers "not found", rather than calling
twice.

## Do not use when

- The merchant asks about stock levels across the catalogue - that is the stock
  part of the shop, not the product list.
- The merchant asks what sold, or how much. That is statistics, read from the
  orders.

## Good triggers

- "Trouve le produit avec la référence TSHIRT-M"
- "Which products are inactive?"
- "Quels produits coûtent moins de 10 € TTC ?"
- "Find the product with EAN 3601234567890"
