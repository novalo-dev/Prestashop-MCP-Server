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

**Discounts by category for a customer group.** somcp_update_customer_group
now changes the Category discount block of a group, as Add a category discount
then Save do on Shop Parameters > Customer Settings > Groups: the whole list is
given, a category with its percent per row, and an empty list removes them all.
The group is given access to each category, as the back office does, and the
answer warns that such a discount replaces the group Discount for the products
of that category. The list read with somcp_get_customer_group and given back as
it is changes nothing.

**Authorized modules of a customer group.** somcp_update_customer_group now
changes the Authorized modules block of a group, as its Yes and No then Save
do on Shop Parameters > Customer Settings > Groups: the whole list of the
modules set to Yes is given, and every module left out is set to No, on the
current shop only. A group cannot be left with no module, as on screen, and
the answer names the payment modules its customers no longer see. The list
read with somcp_get_customer_group and given back as it is changes nothing.

**Create a customer group.** The new tool somcp_create_customer_group does
what Add new group then Save do on Shop Parameters > Customer Settings >
Groups: the name of the group in each language, its discount, its price
display method, whether its members see prices, its category discounts and
the modules it may use - every installed module unless a list is given. The
category discounts are kept, where the back office loses those of a new
group. The answer is the group as somcp_get_customer_group reads it, and
says that a new group sees the home category only until its other categories
are given to it, and which payment modules it was left without.

**Delete a customer group.** The new tool somcp_delete_customer_group does
what Delete does on a row of Shop Parameters > Customer Settings > Groups. The
groups of visitors, guests and customers cannot be deleted, as on screen. Its
members keep their other groups and those left in none go to the Customers
group; the answer holds the group as it was and counts its members, the
customers who had it as default group, the cart rules kept to it and its
specific prices.

**Change the default customer groups.** The new tool
somcp_update_default_groups does what the Save of the card Default groups
options does on Shop Parameters > Customer Settings > Groups: it chooses the
group of the visitors, of the guests and of the newly registered customers,
which decides the prices and discounts they see. Only the groups given change,
and giving back the groups already chosen changes nothing.

**Delete a cart rule.** The new tool somcp_delete_cart_rule does what Delete
does on Catalog > Discounts > Cart Rules: the voucher or discount goes with all
its conditions, and leaves the carts in progress, while the orders that used
it keep their discount. The answer gives the rule as it was, and says when no
active rule is left, since the voucher field then disappears from checkout.

**Export the translations of a language.** The new tool
somcp_export_translations does what the card Export a language of
International > Translations does: the translation pack of one language - the
back office, the storefront, the e-mails, the other wordings, a theme or a
module, any of them together - is built as a zip of XLF files, with the shop's
own changes to the wordings in it. The assistant hands the zip over as a file,
kept 15 minutes; nothing of the shop is changed. It is the pack another shop
imports with Add / Update a language.

**Read the translations of a language.** The new tool somcp_list_translations
reads what the card Modify translations of International > Translations shows:
the domains of the back office, the storefront (core or a theme), a module,
the e-mail subjects or bodies, or the other wordings, with how many wordings
each holds and how many are not translated; then the wordings of one domain,
each with its source, the translation that comes with the files and the
shop's own change, searched by words and page by page.

**Change a translation of a language.** The new tool somcp_update_translation
does what Save and Reset do on the card Modify translations of International >
Translations: the shop's own translation of one wording is saved, or removed so
the translation that comes with the files shows again. A translation that
drops or adds a placeholder of the source (%s, %d) is refused, as on screen,
and a change in the wordings of the back office menu translates the menu again.

**List the stores.** The new tool somcp_list_stores lists the stores of Shop
Parameters > Contact, tab Stores, the ones the storefront page of the stores
shows: each with every field of its Edit form - name, address, country and
state, latitude and longitude, phone, e-mail address, note, whether it is
shown, the opening hours of each day and its picture, which the assistant can
download. It filters on the name, the city, the postcode, the country and
whether the store is shown, as the list does. The country and the state are
given by ISO code, with their names beside them, as every other tool gives
them.

**Create a store.** The new tool somcp_create_store does what Add new store
then Save do on Shop Parameters > Contact, tab Stores: a store gets its name,
address, country and state, latitude and longitude, phone, e-mail address,
note, opening hours of each day, whether it is shown and its picture, each
checked as on screen - a state for a country that has states, the postcode in the format of
the country, coordinates that are not 0. The answer is the store as the list
of the stores gives it.

**Change a store.** The new tool somcp_update_store does what Edit then Save
do on a store of Shop Parameters > Contact, tab Stores, and what its Enabled
switch does: only the fields given change, the picture included, each
checked as when creating a store, a new country checked with its state and postcode. A value the store
already holds is never refused, and a store given back as the list reads it
changes nothing.

**Delete a store.** The new tool somcp_delete_store does what the Delete
action does on a store of Shop Parameters > Contact, tab Stores, one store per
call, and answers the store as it was. Its picture is deleted with it, where
the back office leaves the files on the server.

**Read the contact details of the shop.** The new tool
somcp_get_contact_details reads the card Contact details of Shop Parameters >
Contact, tab Stores: the shop name, e-mail address, registration number,
address, country, state, phone and fax that the e-mails, the invoices and the
page titles print, the country and the state by ISO code with their names
beside them. When no country is saved, the answer says so instead of giving
the country the screen preselects.

**Change the contact details of the shop.** The new tool
somcp_update_contact_details does what Save does on the card Contact details
of Shop Parameters > Contact, tab Stores: the shop name, e-mail address,
registration number, address, country, state, phone and fax change, each
checked as on screen, and only the fields given. The country and the state
are given by ISO code, as every other tool takes them. The details read with
somcp_get_contact_details and given back as they are change nothing. A state
must be one of the country, a saved country or state cannot be emptied, as on
screen, and the answer warns that the shop's e-mails are now sent from a new
shop e-mail address.

### Changed

**The country and the state of an address come as their codes.** The
addresses of an order, of a customer and of a change of address, and the
delivery country of the order search, now give the country and the state by
their ISO codes (US, FL), with their names beside them, as the tools that take
a country or a state want them. An assistant can give the country of one order
back to the search of orders, or to any tool that takes a country, without
looking it up again. The names stay in the answer, and the codes the answers
already carried keep their place.

**The members of a customer group.** The search of customers can list the
members of a group, as the View page of Customer Settings > Groups, and not
only the customers whose default group it is: a customer who belongs to the
group with another default group is now found, and the list agrees with the
Members count of the groups.

**Customer groups by discount, members and date.** The list of customer
groups can be filtered by discount, number of members and creation date, and
sorted as the back office list: the groups that give a discount, or those
nobody is in, no longer need the back office.

## 2.0.6 - 2026-11-26

### Added

**Change the shipping preferences.** The new tool
somcp_update_shipping_preferences does what the Save buttons of Shipping >
Preferences do: the handling charges, the price and the weight from which
shipping is free, the default carrier, and how carriers are sorted on the
storefront. Only the settings given change, and the answer of
somcp_get_shipping_preferences can be given back as it is without writing
anything.

**Read the payment preferences.** The new tool somcp_get_payment_preferences
reads Payment > Preferences: for each payment module, the currencies, customer
groups, countries and carriers it is offered to. The assistant can now check
that customers of a new country or currency have a way to pay before saying
the shop sells there, and it warns about a module offered to nobody.

**Change the payment restrictions.** The new tool
somcp_update_payment_restrictions does what the Save buttons of the four cards
of Payment > Preferences do, for one payment module: the currencies, customer
groups, countries and carriers it is offered to. Each list
given replaces the stored one, and a module read with
somcp_get_payment_preferences can be given back as it is without writing
anything. The assistant warns when a module is left offered to nobody, and
the tools that add a country or a currency now name it when they say a
payment module must be offered there.

**Read the e-mail settings.** The new tool somcp_get_email_settings reads the
Email card of Advanced Parameters > E-mail: where customer messages go, how
the shop sends its e-mails (sendmail, SMTP or never), the SMTP server, the
format, the e-mail log and DKIM signing. The assistant can now tell why
customers get no order confirmation. The SMTP password and the DKIM key are
never shown, only whether they are set.

**Change the e-mail settings.** The new tool somcp_update_email_settings does
what the Save button of the Email card of Advanced Parameters > E-mail does:
where customer messages go, how the shop sends its e-mails, the SMTP server,
username, encryption and port, the format, the e-mail log and DKIM signing.
Only the settings given change, and the answer of somcp_get_email_settings can
be given back as it is without writing anything. The SMTP password and the
DKIM private key are never taken by the tool: the merchant types them on the
page, and the assistant says so when one is missing.

**List the sent e-mails.** The new tool somcp_list_email_logs lists the
e-mails the shop sent, as the list of Advanced Parameters > E-mail shows them:
recipient, template, language, subject and when, newest first, filtered like
the list. The assistant can now check that the order and account e-mails
really go out, and says when Log Emails is off and new e-mails are no longer
kept.

**Delete a sent e-mail from the list.** The new tool somcp_delete_email_log
does what the delete button of a line of that list does, for example for a
customer who asked to be forgotten. The answer gives the line as it was, and
the module's own log keeps only its number and template, never the address.

**Erase the list of sent e-mails.** The new tool somcp_erase_email_logs does
what the Erase all button of that list does. The answer gives the number of
lines erased, and says when they belonged to every shop of a multistore
installation or when Log Emails is off.

**Send a test e-mail.** The new tool somcp_send_test_email does what the Send
a test email button of Advanced Parameters > E-mail does, with the settings the
shop has saved, to the shop's own address or to another one. The assistant can
now prove the shop sends its e-mails right after changing those settings, and
gives the server's own message when it cannot. It refuses when Never send
emails is chosen, and the SMTP password and DKIM key never appear in its answer.

**Read the order settings.** The new tool somcp_get_order_settings reads Shop
Parameters > Order Settings: guest checkout, the minimum purchase total, the
terms of service and the page that holds them, reordering, delayed shipping,
gift wrapping and recycled packaging. The assistant can now tell why a
customer could not place an order, and it warns when the terms of service are
required but no page is chosen.

**Change the order settings.** The new tool somcp_update_order_settings
changes Shop Parameters > Order Settings as its two Save buttons do: guest
checkout, the minimum purchase total, the terms of service and their page,
reordering, the final summary, delayed shipping, the backorder status on
PrestaShop 9, gift wrapping with its price and tax, and recycled packaging.
Only the settings given change, and the answer of somcp_get_order_settings can
be given back as it is without writing anything. When a page of the terms no
longer exists, somcp_get_order_settings now names this tool rather than the
screen.

**Read the product settings.** The new tool somcp_get_product_settings reads
Shop Parameters > Product Settings: catalog mode, the days a product counts as
new, the specific price priorities, the product page options, stock
management, ordering out of stock with the labels and delivery times customers
read in each language, pack stock, and the products per page with their
order. It warns when catalog mode is on or stock management off, the two
settings that explain why a visitor cannot buy.

**Change the product settings.** The new tool somcp_update_product_settings
changes Shop Parameters > Product Settings as the Save buttons of its General
product settings, Product page, Products stock and Pagination cards do:
catalog mode and its prices, the days a product counts as new, the size of the
short description, the specific price priorities, what the product page shows,
stock management, ordering out of stock, the pack stock, the remaining
quantities, the default availability labels and delivery times in each
language, and the products per page with their order. Only the settings given
change, a language left out keeps its texts, and the answer of
somcp_get_product_settings can be given back as it is without writing
anything, even on a shop where one of these settings was never saved. The
warnings name what the call changed first, then what was already so.

**Read the customer settings.** The new tool somcp_get_customer_settings reads
Shop Parameters > Customer Settings: whether the last cart is shown again at
login, the e-mail sent after registration, the delay between two password
resets, B2B mode, the birth date field and partner offers.

**Change the customer settings.** The new tool somcp_update_customer_settings
changes the same settings as the Save button of Customer Settings does. Only
the settings given change, the answer of somcp_get_customer_settings can be
given back as it is without writing anything, and turning B2B mode on or off
shows or hides the page of B2B outstanding amounts as the back office does.

**List the contacts.** The new tool somcp_list_contacts lists the contacts of
Shop Parameters > Contact: their title, e-mail address and description, whether
their messages are kept in Customer Service, and which one receives the
customers' messages. A contact read there is given as it is to Send emails to
of somcp_update_email_settings.

**Create a contact.** The new tool somcp_create_contact does what Add new
contact of Shop Parameters > Contact does: a title in each language, the
address that receives the messages, whether they are kept in Customer Service,
and a description, checked with the rules of the back office form. The answer
gives the contact as the list shows it, ready for Send emails to of
somcp_update_email_settings.

**Change a contact.** The new tool somcp_update_contact does what Edit then
Save of a contact do, with the same checks as the creation. Only the fields
given change, a language left out keeps its text, and a contact of the list
given back as it is changes nothing. A contact of the list given back whole is
accepted by every assistant, to the creation as to the change.

**Delete a contact.** The new tool somcp_delete_contact does what Delete does
on a contact of Shop Parameters > Contact. It answers the contact as it was, in
every language, so it can be created again, and warns when the shop has no
contact left. The contact that receives the customers' messages is kept: the
back office would delete it and leave those messages without a receiver.

**List the social titles.** The new tool somcp_list_titles lists the titles of
Shop Parameters > Customer Settings, tab Titles: their name in each language,
the gender they stand for and their image. The assistant now finds the title
to give a customer it creates or changes in this list, instead of learning it
from a refusal.

**Create a social title.** The new tool somcp_create_title adds a title to
Shop Parameters > Customer Settings, tab Titles, as Add new title does: its
name in each language, the gender it stands for and an optional image, resized
as the form resizes it. A title whose image the shop cannot read is not kept,
so a failed call leaves nothing behind.

**Change a social title.** The new tool somcp_update_title changes a title of
Shop Parameters > Customer Settings, tab Titles, as Edit then Save do: its
name in one language or more, the gender it stands for or its image, which can
be replaced but not removed. Only what is given changes, and a title read from
the list and given back as it is stays untouched. When the gender changes, the
answer says how many customers of the shop hold the title.

**Delete a social title.** The new tool somcp_delete_title deletes a title of
Shop Parameters > Customer Settings, tab Titles, as its Delete action does,
and its image with it, which the back office leaves on the server. The answer
gives the title as it was, so it can be created again, and says how many
customers of the shop held it: their accounts then show no title.

**Read a customer group in full.** The new tool somcp_get_customer_group reads
one group of Shop Parameters > Customer Settings > Groups as its Edit form
shows it: what the list of groups gives, plus its name in every language, its
discounts by category, in percent as on screen, and the modules its members
may use, authorized or not.

**Change a customer group.** The new tool somcp_update_customer_group changes
a group of Shop Parameters > Customer Settings > Groups as Edit then Save do:
its name in one language or more, its Discount, its Price display method and
whether its members see prices. Only what is given changes, and a group read
and given back as it is stays untouched. The storefront shows the new prices
at once, and the answer warns when the group is the one of every visitor,
guest or customer.

### Changed

**Find the vouchers that run out.** The list of cart rules can be filtered by
priority, uses left and expiration date, and sorted as the back office list:
"the vouchers that expire before the end of the month" or "the rules with no
use left" no longer take the back office or reading every page.

**Keep a voucher to some products.** somcp_create_cart_rule and
somcp_update_cart_rule now write Product selection: a voucher can be kept to
products, categories, brands, suppliers or attribute values, with the number
of such products the cart must hold, as on the Conditions tab of the rule, and
is created that way in one go. "10% on the shoes" no longer needs the back
office. A selection given back as read changes nothing and is not
checked again, so a rule saved in the back office with a group of quantity 0
or a product since removed can still be updated from its own read.

**Limit a cart rule to countries, carriers and customer groups.**
somcp_update_cart_rule refused every restriction of the Conditions tab, so a
discount for wholesale customers only, or free shipping to one country with
one carrier, had to be finished in the back office. It now sets the Country
selection, the Carrier selection and the Customer group selection as the form
does, or lifts them, and leaves the compatibility with other rules, the
product selection and the shops untouched. somcp_create_cart_rule takes the
same three selections, so such a rule is created in one call.

**A localization pack names the country it turned on as the country tools do.**
When somcp_import_localization_pack switched on the country of the pack, its
answer said so with `enabled`, a name no other country tool uses, so an
assistant looking for `active` missed it and one giving `enabled` back to
somcp_update_country was refused. The answer now says `active`, as
somcp_get_country and somcp_update_country do.

**A localization pack brings its currencies from any assistant.** Importing a
localization pack with somcp_import_localization_pack refused the currencies
from an assistant connected from outside the shop, which then had to add each
currency one by one. The import now adds the currencies of the pack the shop
does not have, at rate 1 and enabled for every payment module, then refreshes
their rates, from every assistant as from the built-in chat. When one currency
cannot be created, the answer names by their codes the currencies created
before it, so the merchant knows which ones now exist.

**Choose which cart rules a voucher combines with.** somcp_update_cart_rule
refused Compatibility with other cart rules, so stopping two vouchers from
adding up still meant opening the Conditions tab. It now sets the box and the
list of Combinable cart rules as the back office does, or clears them, and
says which other rules the back office stopped combining with this one when
the box is checked.

**A voucher created by an assistant combines as one created on screen.**
somcp_create_cart_rule refused Compatibility with other cart rules, so a
voucher that must not add up with the others needed a second step in the back
office. It now takes the list of Combinable cart rules, or none at all, and
names the rules the back office then stops combining with it. A voucher
created without the box is also added, as on screen, to the rules that list
the vouchers they combine with.

**A setting the shop's version does not have is refused in the same words.**
The maintenance, order settings, module and page SEO tools each refused a
setting of another PrestaShop version in their own sentence, and some did not
say how to go on. Every tool now answers that the setting is not on the
version the shop runs and that it should be left out, and somcp_update_meta_page
takes the empty meta keywords of a page read on PrestaShop 9 given back as no
change instead of refusing them.

**The explanations of the settings are tips.** On the pages of the module,
what a setting does was written in a paragraph under it, which made the pages
long to read. It now opens from the question mark beside the name of the
setting, or beside the title of a panel, with the mouse or the keyboard. What
tells a state stays on the page: a missing key, a model that no longer passes,
this month's usage.

**A switch never saved reads off, as on screen.** The SEO and performance
settings an assistant reads showed a switch nobody had ever saved as a
setting the shop's version does not have, while the back office shows it as
No, and the performance warnings missed it: on PrestaShop 8, Smart cache for
CSS and Smart cache for JavaScript left off were not named. Such a switch is
now read as off, and only a setting the version truly lacks stays empty.

**Compact mode finds a tool asked for without accents.** A request typed
without its accents, as on a phone, now finds the tool whose words carry
them. The tools and the checks on every call are unchanged.

**The built-in assistant runs on Neuron AI 4.1.28.** Nothing changes in how
you use it.

### Security

**A deposit never keeps more files than it was opened for.** Files sent to
one deposit at the same moment were each counted before any of them was
recorded, so a deposit opened for one file could keep several. The files of
a deposit are now counted one after the other, whichever way they arrive.

## 2.0.5 - 2026-11-25

### Added

**Read the general settings.** The new tool somcp_get_general_settings reads
the price rounding and the storefront pages of Shop Parameters > General:
Round mode, Round type, and whether the suppliers, brands and best sellers
pages are shown. The assistant can now tell how the shop rounds every price
and total before it explains one.

**Change the general settings.** The new tool somcp_update_general_settings
does what the Save button of Shop Parameters > General does for the price
rounding and the suppliers, brands and best sellers pages, and only for
those: the other settings of the tab are never touched. The answer says that
orders and invoices already made keep their totals, and that only new carts
and orders use a new rounding.

**Read the maintenance settings.** The new tool
somcp_get_maintenance_settings reads the Maintenance tab of Shop Parameters >
General: whether the store is enabled, whether employees logged in to the
back office still see it (PrestaShop 9), and the maintenance text in each
language. The assistant can now tell whether the storefront is open to
visitors before saying the shop is live.

**Change the maintenance settings.** The new tool
somcp_update_maintenance_settings does what the Save button of the
Maintenance tab of Shop Parameters > General does: it opens or closes the
storefront, lets employees logged in to the back office see a closed shop
(PrestaShop 9), and changes the maintenance text in each language, cleaned as
the back office cleans it. The addresses of Maintenance IP are never touched.
The assistant closes or opens the shop only when the merchant asks.

**Read the localization settings.** The new tool
somcp_get_localization_settings reads International > Localization >
Localization: the default language, country and currency, browser detection,
the time zone, the weight, distance, volume and dimension units, and the
server's language and country identifiers. The assistant can now tell which
language and currency a shop starts from, and in which units its product
weights and carrier ranges are read, before typing any of them.

**Change the localization settings.** The new tool
somcp_update_localization_settings does what the Save buttons of
International > Localization > Localization do: the default language and country, browser
detection, the time zone, the units and the server's identifiers, so a new
shop can be set to the language, country and units it sells in. A unit is only
a label: the answer says that weights and sizes already typed are not
converted. The default currency can be changed too, as the back office does
it: every exchange rate is reset and read again from PrestaShop's exchange
rate server, which is asked first, so nothing changes when it does not
answer. Product prices are not converted, and the answer says so.

**Read the shipping preferences.** The new tool
somcp_get_shipping_preferences reads Shipping > Preferences: the handling
charges, the price and the weight from which shipping is free, the default
carrier, and how carriers are sorted on the storefront. The assistant can now
tell what every order pays for shipping on top of the carriers' prices, and
which carrier a customer finds selected.

**Import a localization pack.** The new tool somcp_import_localization_pack
does what the card Import a localization pack of International > Localization
> Localization does, from the shop's own pack: a country's states, taxes and
tax rules, units and the price display of the default customer groups, in one
call, so a shop is set up for its country without creating each state and tax
by hand. Its currencies and languages come too, as the back office brings
them: the exchange rates and each new language are fetched from PrestaShop's
servers, and the answer says when one did not arrive. Taxes and tax rules that
already exist are never changed, and the answer lists what was created.
Currencies are imported from the built-in chat; a connected assistant adds
them one by one.

**Add or update a language from PrestaShop.** The new tool
somcp_install_language_pack does what the card Add / Update a language of
International > Translations does: a language is added with PrestaShop's
translations of the storefront, the e-mails and the back office, or the
translations of an installed language are brought up to date. A language
created by hand stayed untranslated; it can now be installed in one step. The
call can take up to two minutes, and says so when PrestaShop's server does not
answer.

**Update the exchange rates.** The new tool somcp_update_exchange_rates does
what the Update button of Update exchange rates does in International >
Localization > Currencies: every currency of the shop takes the rate of
PrestaShop's exchange rate server. The merchant sees which rates moved, from
what to what, and which currencies that server does not list, whose rate
stays as it was. It waits up to 20 seconds for the server, and says so when it
does not answer.

**Change the country options.** The new tool somcp_update_country_options
switches on or off, in International > Locations > Countries, the option that
lets customers pick in their address only a country an active carrier
delivers. A shop that ships to a few countries can now stop customers from
choosing a country it cannot deliver. The merchant is warned when no active
carrier delivers to an active country, and on PrestaShop 9, which no longer
shows the option, that the storefront still applies it.

**Read the country options.** The new tool somcp_get_country_options reads the
Country options of International > Locations > Countries: whether customers
may only pick, in an address, a country an active carrier delivers. Before
enabling a country, the assistant can now tell whether customers will see it.
On PrestaShop 9, which no longer shows the option, the merchant is told the
storefront still applies it.

**Add a language.** The new tool somcp_create_language adds a language to
International > Localization > Languages, as Add new language does: its name,
its ISO code, its language code, its two date formats, its flag and its
"No-picture" image, whether it is written right to left, and whether it is
offered. A shop that opens to a new market can now add the language without the
back office. Every text of the shop is copied into it from the default
language, and the merchant is told to translate them; a code another language
already has is refused, and when an image cannot be stored nothing is left
half made.

**Change a language.** The new tool somcp_update_language changes a language of
International > Localization > Languages, as its Edit page and the Enabled
switch of the list do: its name, its ISO code, its language code, its two
date formats, whether it is written right to left, and whether it is offered.
A shop that opens a second language can now give it its own date format, or
switch it on and off, without the back office. The default language cannot be switched off,
and when a language is, the employees who used it move to the default language
and the merchant is told how many. A new ISO code takes the translation files
and e-mail templates of the language with it, and is refused when another
language already has it. Its flag, and the "No-picture" image the storefront
shows in that language for a product, a category or a brand without a picture,
are replaced from an address or a file sent in the chat.

**Delete a language.** The new tool somcp_delete_language deletes a language of
International > Localization > Languages, as the Delete of its row does. Every
text of the shop in that language goes with it, so the assistant offers to
switch the language off instead, and the default language and the one the
employee uses in the back office are refused. The employees who used it move
to the default language, and the merchant is told how many customers had
chosen it.

**Add a currency.** The new tool somcp_create_currency adds an official
currency to International > Localization > Currencies, as Add new currency
does: its ISO code, its exchange rate, and optionally its decimals, status,
names and symbols, which the shop otherwise fills in every language. A shop
opening to a new market can now add its currency without the back office. A currency
deleted before comes back as the same currency, and the merchant is told that
payment modules still have to accept it in Payment > Preferences. An
alternative currency can be added too, as Create an alternative currency does:
a local or loyalty currency PrestaShop does not know, with the code, name and
symbol the merchant gives and 2 decimals unless told otherwise.

**Create a delivery zone.** The new tool somcp_create_zone creates a zone of
International > Locations > Zones, with its name and whether shipping to it is
allowed, as Add new zone does. The assistant can now make the zone a merchant
needs (neighbouring countries, the mainland apart from the islands) instead of
only using the zones the install left. It is told the zone starts with no
country, and when another zone already has the same name.

**Rename or close a delivery zone.** The new tool somcp_update_zone changes the
name of a zone, or allows or stops shipping to it, as its edit form and the
Enabled switch of the list do. Only what differs is saved, so giving back a zone
as it was read changes nothing. When a zone is closed, the assistant is told
which carriers served it and how many countries no longer get them.

**Delete a delivery zone.** The new tool somcp_delete_zone deletes a zone that
holds no country and no state, as the Delete of its row does. A zone that still
holds some is refused rather than deleted, because the back office would leave
those countries in no zone, where no carrier reaches their addresses. The
answer gives the name of the zone and the carriers that lost it.

**Create a state.** The new tool somcp_create_state creates a state of
International > Locations > States, with its name, its ISO code, its country,
its zone and its status, as Add new state does. A merchant who sells to a
region PrestaShop does not ship no longer has to open the back office before
the assistant can add a tax rule row or an address there. An ISO code another
state of the same country already has is refused, so the same call twice
creates nothing twice.

**Change a state.** The new tool somcp_update_state changes the name, the ISO
code, the country, the zone or the status of a state of International >
Locations > States, as its edit form and the Enabled switch do. Until now the
assistant could create a state but not correct its name, move it to another
zone for the carrier prices, or switch it off so the address form stops
offering it. A state that addresses or tax rule rows use keeps its country, so
those addresses never end up with a state of another country.

**Delete a state.** The new tool somcp_delete_state deletes a state of
International > Locations > States, as the Delete of its row does. A state
created by mistake, or one the merchant never sells to, no longer has to be
removed in the back office. A state an address or a tax rule row uses is
kept, with the tax rules named, so no tax row is left pointing to a state
that no longer exists.

**Change a country.** The new tool somcp_update_country changes one country
of International > Locations > Countries as its edit page does: its name in
each language, ISO code, call prefix, default currency, zone, whether it is
enabled, whether it has states, whether addresses need a zip code and the
format that code must follow, the address format, whether addresses need a
tax identification number and whether prices show the tax label. The address
format is saved on PrestaShop 9 too, whose edit page shows it empty and does
not save it, while its storefront and invoices still print with it. A country
moved to another zone
takes along its states that were in the old zone, on PrestaShop 9 as on
PrestaShop 8, so its addresses are offered the carriers of the new zone.
Enabling a country never adds payment modules for it; the answer warns when
no payment module or no carrier is offered there yet.

**Read a country.** The new tool somcp_get_country reads one country of
International > Locations > Countries as its edit page shows it, with what
the list of countries leaves out: its default currency, its address format
and whether prices show the tax label. The address format is given both as
stored and as the storefront applies it, so the merchant learns which lines
an address of that country shows, even on PrestaShop 9 where the edit page
displays the field empty.

**Read a currency.** The new tool somcp_get_currency reads one currency of
International > Localization > Currencies as its edit page shows it, with what
the list of currencies leaves out: its number of decimals, its numeric ISO
code, whether it is an alternative currency, its price format in the language
asked and whether its names or symbols were customized. The assistant can now
tell the merchant how prices in that currency are shown before changing it.

**Change a currency.** The new tool somcp_update_currency changes the exchange
rate, the number of decimals and the status of one currency of International >
Localization > Currencies, as its edit page and the Enabled switch of the list
do. It also changes the currency name and the symbol in each language, the
place of the symbol in prices (Customize symbol and format), and puts back the
default names, symbols and format, as Restore default settings does. The rate
is the one of the shop the assistant is connected to; the default currency of a
shop keeps a rate of 1 and cannot be switched off, as in the back office. A
currency read with somcp_get_currency can be given back as it is, and only what
differs is written.

**Delete a currency.** The new tool somcp_delete_currency deletes one currency
of International > Localization > Currencies, as the Delete of its row does.
The default currency of a shop is refused, as in the back office, and so is a
currency a country still uses as its default currency: the merchant sets the
country back to the default store currency first, so no country is left with a
currency the shop no longer lists. Orders placed in the currency keep it, and
the answer says which payment modules stopped accepting it.

### Changed

**Sign-in page of a connection.** The anti-robot check is updated to ALTCHA
3.7.0. Nothing changes on the page.

**Product summaries as long as the back office allows.** somcp_create_product
and somcp_update_product refused a summary outside 100 to 200 characters, so a
one-character fix of an existing longer summary was refused too and had to be
made by hand. A summary is now refused only past the shop's own Max size of
short description (Shop Parameters > Product Settings), as on the product page,
and a summary outside 100 to 200 characters only gives an SEO advice.

### Fixed

**A country given the wrong way when listing states.** When the assistant gave
the country of somcp_list_states as a list instead of a code, it was told the
shop had no country with an empty code, as if a country were missing. It is now
told to give an ISO code of 2 letters, and a state code given the wrong way is
refused the same way instead of being ignored, which listed every state.


### Security

**Stricter handling of authorization codes and refresh tokens.** A code and
a rotated refresh token are now marked used in the same step that checks
them, and signing out with a refresh token now also ends the access token
issued with it.

**One check of a public address for every request the shop sends.** The
addresses a fetch made by the module may reach, and the checks of whether
the shop itself is reachable, now all use the same, stricter definition of
a public address.

**Sign-in attempts on the authorization page are counted before they are
checked.** The limits on sign-ins and on e-mailed codes per address and per
account, and the single use of an anti-robot check, now hold however the
attempts arrive. A successful sign-in still does not count against them.

## 2.0.4 - 2026-11-22

### Added

**Read a carrier in full.** The new tool somcp_get_carrier reads one carrier of
Shipping > Carriers as its edit page shows it: the zones it delivers to, the
customer groups allowed to use it, its tax, its handling costs, whether its
costs are billed by price or by weight, its ranges with the price of each in
every zone, its Out-of-range behavior, its speed grade and its size and weight
limits. Until now the assistant only saw the carrier list, so it could not tell
where a carrier delivers, to whom, nor what a customer pays there, and a
merchant setting up delivery had to open each carrier.

**List the currencies.** The new tool somcp_list_currencies lists the
currencies of International > Localization > Currencies, with their name,
symbol, ISO code, the exchange rate of the shop, whether they are enabled, and
which one is the shop default. Until now the assistant only learnt the codes
from a refused currency change, without the names, the rates or the disabled
currencies.

**List the languages.** The new tool somcp_list_languages lists the languages
of International > Localization > Languages, with their ISO code, language
code, locale and date formats, whether they are enabled, which one is the
shop default, and the flag of each, which the assistant can show. Until now the assistant only read the codes in one line of the
connection check, without the disabled languages, so it could not tell the
merchant why a language was refused.

**List the states.** The new tool somcp_list_states lists the states of
International > Locations > States, with their ISO code, their country and
the zone each belongs to. Until now the assistant had to guess the code of a
state when adding a tax rule row or the address of a supplier.

**List the countries.** The new tool somcp_list_countries lists the countries
of International > Locations > Countries, with their ISO code, their zone,
whether they are enabled, and what an address there must hold: a state, a
postcode and its format, a tax identification number. Until now the assistant
had to guess the ISO code every other tool takes for a country, and could not
tell which countries the shop sells to.

**List the delivery zones.** The new tool somcp_list_zones lists the zones of
International > Locations > Zones, with the number of countries in each. Until
now the assistant only saw the zones a carrier already serves, so it could not
tell which other zones the shop has before choosing where a carrier delivers.

**Change a carrier.** The new tool somcp_update_carrier changes the general
settings of one carrier of Shipping > Carriers: its name, its transit time in
each language, its speed grade, its tracking URL, whether it is active and the
customer groups allowed to use it. It also changes the order of the carriers,
as the list of Shipping > Carriers does, which is the order the checkout offers
them in. It also changes or removes the logo of a carrier, from a picture sent
in the chat or an address. It also chooses the zones a carrier delivers to, as the
zone boxes of Shipping locations and costs do; the prices already typed are
kept. Until now the assistant could read a carrier but had to send the
merchant to the back office to change any of it. A carrier no order uses keeps
its identifier; one that orders already use gets a new one, as the back office
does, so those orders keep the carrier they were placed with. Moving a carrier
never gives it a new identifier.

**Set how a carrier charges.** somcp_update_carrier also sets whether a carrier
is free, adds the handling costs, bills by price or weight, which tax it
charges and what happens beyond its last range, as the Shipping locations and
costs step of a carrier does. Turning a carrier free keeps the prices of its
ranges, so they are still there if it becomes paid again. It also sets the
largest and heaviest package a carrier takes, as Size and weight does. It also
sets the ranges of a carrier and their price in each zone, as the table of
Shipping locations and costs does.

**Delete a carrier.** The new tool somcp_delete_carrier deletes one carrier of
Shipping > Carriers, such as the demo carriers of a new shop. A carrier orders
use stays hidden for them, as the back office keeps it; one no order uses is
removed. The default carrier and a carrier a module manages are refused, so
the checkout never points to a carrier that is gone and a module never loses
its own carrier behind its back.

**Change the tax options.** The new tool somcp_update_tax_options changes the
Tax options of International > Taxes > Taxes, as their Save: Enable tax,
Display tax in the shopping cart, Based on (invoice or delivery address), Use
ecotax and the Ecotax tax rule. Until now the assistant could read them but a
merchant setting up a shop had to change them by hand. Turning the ecotax off
sets the ecotax of every product to 0, as the back office does, and nothing
gives it back: when products carry one, the tool asks for an explicit
confirmation first.

**Create a tax.** The new tool somcp_create_tax creates a tax of International >
Taxes > Taxes, with its name in each language, its rate and whether it is
enabled, as the Add new tax form does, and answers with the tax as the list
shows it. Until now the assistant could list the taxes a shop had but not make a
new one, so a rate the install did not provide (a reduced VAT, a new sales tax)
could not be set up at all; now a tax rule can be given a rate the merchant
needs. A tax is created disabled, as the form's default, and is shared by every
shop of a multistore, as PrestaShop stores it.

**Change a tax.** The new tool somcp_update_tax changes the name, rate or
enabled state of a tax of International > Taxes > Taxes, as its edit page and
the Enabled switch of the list do, and answers with the old and new value of
each change and the tax as it was saved. Only the fields given change, and a
call that would change nothing writes nothing. Changing a rate changes it for
every tax rule that charges the tax, without touching the rules. On PrestaShop
8, disabling a tax that tax rules still charge would delete those rows from
every rule and leave those countries untaxed, so the tool refuses it and asks
for the rows to be moved first; on PrestaShop 9 the rows stay. A tax used by
past orders is kept unchanged for them and the change applies under a new
identifier, which the answer gives.

**Delete a tax.** The new tool somcp_delete_tax deletes a tax of International >
Taxes > Taxes, as the Delete of its row does. It is refused while tax rule rows
still charge the tax, so those countries are never left untaxed without notice:
give them another tax with somcp_update_country_rule, or delete the rows with
somcp_delete_country_rule, first. A tax used by past orders is kept hidden for
them and removed from the list, as PrestaShop does, and the answer says so. The
deletion is irreversible; to keep a tax for later, switch it off with
somcp_update_tax instead.

**Read the tax options.** The new tool somcp_get_tax_options reads the Tax
options below the list of International > Taxes > Taxes: Enable tax, Display
tax in the shopping cart, Based on (invoice or delivery address), Use ecotax
and the Ecotax rule with the rate it charges. Until now the assistant could
create taxes and tax rules but could not see whether the shop charges them at
all, nor on which address; it now says so before setting up a rate, and warns
when taxes are switched off.

**Update a tax rule.** The new tool somcp_update_tax_rule changes the name of
a tax rule of International > Taxes > Tax Rules and whether it is enabled, as
its edit page and the Enabled column of the list do, and answers with the old
and new value of each change and the rule as it was saved. A call that would
change nothing writes nothing. Until now the assistant could create a tax rule
but not rename it or switch it off. A rule used by past orders keeps its old
version for them, and the change applies under a new identifier, which the
answer gives: products and carriers move to it, on PrestaShop 8 as on 9.

**Delete a tax rule.** The new tool somcp_delete_tax_rule deletes a tax rule
of International > Taxes > Tax Rules with its rows per country, as the Delete
action of the list does. Unlike the back office, it refuses a rule that
products or carriers still take, and says how many: deleted, they would be
sold without tax from then on, on every invoice, without anyone noticing.

**Add a country to a tax rule.** The new tool somcp_add_country_rule adds a
row to a tax rule of International > Taxes > Tax Rules, as the New tax rule
panel of its edit page does: a country, some of its states or all of them, a
zip/postal code range, a behavior, a tax and a description. Until now a rule
created by the assistant charged nothing, since no tool could give it a
country. A call adds all its rows or none, and refuses a This tax only row
that would clash with another row of the same country and state. A rule used
by past orders keeps its old version for them, and the rows go on a copy under
a new identifier, which the answer gives. A row read from the rule, or the row
a deletion gives back, can be given again as it was, its state included.
The All choice of Country is offered too: one call adds a row for every
country of the shop, as the panel does, which is how a merchant sets up a rule
that charges the same rate everywhere before changing a few countries. Unlike
the panel, which stops at the first country that clashes and keeps the rows
already written, it writes nothing and names every clashing country at once.

**Delete a country from a tax rule.** The new tool somcp_delete_country_rule
deletes one row of a tax rule, as the Delete of that row on the rule's edit
page does, and answers with the row as it was and the rule as it is left.
When the last row of a rule goes while products or carriers still take it, the
answer says how many, since they are now sold without tax. A rule used by past
orders keeps the row for them, and the deletion applies to a copy under a new
identifier, which the answer gives.

**Change a country of a tax rule.** The new tool somcp_update_country_rule
changes one row of a tax rule in place, as the Edit of that row on the rule's
edit page does: its country, state, zip/postal code range, behavior, tax or
description, only what is asked. Until now a wrong row had to be deleted and
added again. The answer gives the old and new value of each change, and a call
that would change nothing writes nothing. A row cannot be turned into a This
tax only row that would clash with another row of the same country and state.
A rule used by past orders keeps the row as it was for them, and the change
applies to a copy under a new identifier, which the answer gives.

### Changed

**Contributors shown without GitHub.** A shop that cannot reach GitHub, or
whose update check is switched off, shows the contributors from a list shipped
with the module. That list was three weeks old; it now carries the counts of
this release.

**Find a content page by its ID, URL or Meta title.** The list of content pages
takes the filters ID, URL and Meta title of Design > Pages, as well as Title and
Displayed.

**Sales summary: the orders in each status.** The sales summary now gives how
many orders of the period are in each status, and their share, as the
distribution of order statuses of Stats > Sales and orders shows them: every
order placed in the period, valid or not, under its latest status, the status
names in the language asked for. Until now the assistant had to page through
every order of the period to tell how many were still waiting or cancelled.

**Sales summary: one delivery country, and every mistake at once.** The sales
summary can now be limited to the orders delivered in one country, as the All
countries filter of Stats > Sales and orders: the revenue, the sales, the orders
and the products bought then count that country only. A call with several
mistakes (a wrong date, a field the tool does not take, a comparison asked as
"yes") now names them all in one answer, where it used to stop at the first one
or take "yes" as a yes.

**Taxes: one tax, or the taxes at one rate.** The list of taxes can now be
narrowed by ID and by rate, as the ID and Rate filters of International >
Taxes. A rate finds the taxes at exactly that rate: asked for 6, it no longer
gives 6.25 or 6.85 too, as the filter of the page does. Until now the assistant
read every tax and picked the right one itself.

**Abandoned carts: one cart or one carrier.** The list can now be narrowed to
one cart or to the carts of one carrier, as the ID and Carrier filters of
Orders > Shopping Carts. Carts saved before the carrier was edited are found
too. Until now the assistant asked about one cart or one carrier read every
abandoned cart and sorted them itself.

**Customer groups: one group, or the groups that show prices.** The list of
customer groups can now be narrowed to one group or to the groups that show
or hide prices, as the ID and Show prices filters of Shop Parameters >
Customer Settings > Groups. A mistake in the call, such as a misspelt filter,
is now named with the others instead of being ignored, which used to give
every group back as if nothing had been asked.

**Sitemap audit: the image-file check.** The sitemap audit now says whether the
Google sitemap module checks that the image files exist on the server before
listing them, the box of its Configure screen, on the module versions that
still have it. Until now the assistant could not tell why images were missing
from a sitemap generated with the box ticked.

**Products, low stock and customers: an unknown category, supplier, group or
title.** The product search, the low stock list and the customer search now
say when the category, supplier, customer group or social title asked for
does not exist in the shop, and where to find the right one. Until now they
answered that nothing matched, and the assistant told the merchant there was
no such product or customer.

**Best sellers and page SEO: every mistake named at once.** The best sellers
now name every mistake of a request in one answer, a misspelt filter
included, instead of the first one only, or none for a misspelt filter, which
used to answer as if it had not been given. A wrong language given back to
the SEO update of a CMS page is named once, with the shop's languages.

**The built-in assistant runs on Neuron AI 4.1.27.** Nothing changes in how
you use it.

**The anti-robot check of the sign-in page runs on the ALTCHA library 2.4.0.**
Nothing changes on the page.

**Compact mode finds tools asked for in more languages.** A request written in
Chinese, Japanese, Korean or Thai now finds the right tool, and a request of
several words is weighed by its pairs of words. The tools and the checks on
every call are unchanged.

### Fixed

**Orders: searching by an order number that is not one.** Searching the orders
by an order number that is not one is now refused with the reason, instead of
answering the latest orders or none.

**Disabled tax rules and disabled products short of stock.** Asking for the
disabled tax rules, or the disabled products short of stock, now lists those
only, as the Enabled and Status filters of the back office do. Until now the
enabled ones came along, so an assistant that had just listed the disabled
taxes got every tax rule back.

**Tax rules: a name too long for the shop.** On shops whose tax rule names
hold 50 characters, a longer name is now refused with the limit instead of
being cut, so the rule saved always carries the name the merchant gave.

**Products, categories, brands and suppliers: a description too long for the
shop.** On PrestaShop 8, a description of a product, a category, a brand or a
supplier too long for the shop to store, or brand meta keywords past 255
characters, are now refused with the reason, instead of being saved cut
without a word.

### Security

**The file behind a signed link is looked up in the store by its name.** A
file of the assistant opened through its signed link was sent from the path
read in the database. It is now found again by its bare name inside the
uploads folder before it is sent, so nothing reached through a request can
name a file elsewhere. Two rules join the security checks to keep it that
way: one refuses an upload read where PHP put it, the other a file read,
sent or removed at a path the request had a hand in. These are the two
shapes the PrestaShop Addons security review reports.

## 2.0.3 - 2026-11-17

### Added

**List the taxes.** The new tool somcp_list_taxes lists the taxes of
International > Taxes > Taxes with their identifier, name, rate and whether
they are enabled, filtered by part of the name or by Enabled, in the language
asked for. Until now no tool gave the identifier of a tax, so an assistant
setting up the tax rules of a new shop had to guess which tax each one applies.

**Create a tax rule.** The new tool somcp_create_tax_rule creates a tax rule
of International > Taxes > Tax Rules with its name and whether it is enabled,
as Add new tax rule does, and answers with the rule as it was saved. Left
unsaid, the rule is created disabled, as in the form, and the answer says so.
Until now the assistant setting up a new shop could read tax rules but not
add one.

### Changed

**Modules: every mistake of a call named at once.** Listing, reading,
installing, enabling and disabling a module used to answer the first mistake
of a call alone, and a misspelt field came back as a missing name. They now
name every mistake together, the misspelt field by its name, as the other
tools do, and a module the shop does not have is answered with the same
wording in all of them.

**Products: an unchanged ecotax given back is no longer refused.** On
PrestaShop 9 with the ecotax switched off in the tax settings, a product read
and given back unchanged was refused because of its ecotax, which the product
read itself carries. The ecotax already stored, for the product or one of its
combinations, now goes through as unchanged; only a different one is still
refused while the ecotax is off.

**Reading an order: what the order tools need to act on it.** When the
assistant reads an order, it now sees which invoice each product line and
each payment is on, the identifier of each payment, and the payment module of
the order. It can tell the merchant where a line is before moving it, find a
payment it recorded, or place the same order again without guessing.

**Cart rules: an unknown rule reported with every other mistake.** Reading,
switching on or off and changing a cart rule now word a mistake in the call as
the other tools do. Given a cart rule that does not exist together with a
misspelt field, they used to refuse the call twice, one problem at a time;
they now list every problem of the call in one answer, so the assistant fixes
the call in one go.

**Creating a cart rule or a catalog price rule: refusals worded as in the
other tools.** A mistake in the call now reads as it does everywhere else. A
customer, product or condition that is not found is named with the identifier
given and the tool that lists them, and a condition with the word of the
Conditions of the form (category, brand, supplier, attribute, feature value)
rather than the name of a database table. An identifier given as text such as
"3" is read as that number, as in every other tool.

**Sales lists: refusals worded as in the other tools.** The lists of
abandoned carts, cart rules, catalog price rules, search terms, unsold
products and products viewed but not sold now word a mistake in the call as
the catalogue, stock, order and customer tools do. A category or customer
group that is not found is named with the identifier given and the tool that
lists them, and a category given as "3" is read as category 3 instead of
being refused.

**Order statuses: a status read in the list can be given back whole.** Changing
an order status with the fields read in the list of statuses no longer fails on
its roles, its built-in mark or its payment module: these are worked out by the
shop, so when they hold what the shop holds they are taken as unchanged, and
only a different value is refused, with the reason.

**Order statuses: the whole row of the list is accepted back.** A status read
in the list of statuses and given back as it is no longer fails on an empty
colour, nor, on a shop with several languages, on its name or email template
given as one text: when they hold what the shop holds, nothing is written and
the answer says nothing changed.

**Orders: the amounts of an order are named like everywhere else.** The order
read and the order tools that answer with its lines, discounts, shipments,
totals and documents now name each amount with `_tax_excl` or `_tax_incl`, as
the product, statistics and customer tools do, and give the order total as
`total_paid_tax_excl` and `total_paid_tax_incl`, as the order search does.
The same goes for the discount added or removed, the payment recorded, the
invoice generated and the delivery options of a new order, and cancelling
products now answers the order totals in the shape of the order read. The
refunds, partial, standard or on returned products, answer the amounts refunded,
the shipping and the credit slip totals with the same names. Before, the same amount went by two names depending on the tool, and an
assistant reading one answer with the names of another found nothing.

**Refunds and discounts take the amounts under the names of the other order
tools.** A partial refund took the amount of each line and of the shipping
"tax excluded" / "tax included" in full, and a discount on an order took its
amount type as "amount tax included", while every other order tool uses the
short "tax excl." / "tax incl." names of the order page. They now take the
same names, and a former name is answered with the one to send instead of
being refused as unknown.

**The language of a customer's last emails is given by its code.** The
customer read gave the language of each of the last emails sent as its name,
such as "English (English)", where every other answer gives a language by its
code, such as `en`, and every tool takes that code back. It is now the code,
so the assistant can pass it on as it reads it.

**The record of a change reads the same in every update tool.** Changing
the SEO of a CMS page answered each change with its language apart and the
value before under `previous`, changing a cart rule's name gave its language
apart too, and editing an order line gave its changes as one block keyed by
field instead of a list. The other update tools list each change with its
`field`, `old` and `new` value, a translated text named with its language,
such as `meta_description (en)`. These three updates now answer the same way,
so the assistant reads every update with one habit.

**Products can be searched in every order of the product list.** The product
search sorted only by date, name from A to Z, price and quantity, so an
assistant asked for the products by reference or the last ones by ID read
the whole catalogue to sort it itself. It now also sorts by ID, reference,
category and status, each way, and by name from Z to A, as the columns of the
product list in the back office do.

**Updates: the news of a new version in a window.** The Help and updates
page used to announce a new version with two blocks of text: the first five
points of what was new, then a separate reminder to reconnect the assistants.
It now shows one line with a See what is new button, which opens a window
listing every point of the release, how to install it, the reconnection
reminder and the buttons to read the full notes or download the zip.

### Fixed

**Asking the SEO and product data audits for disabled products or hidden
categories now audits those only.** The product SEO audit, the category SEO
audit and the product data audit read "not active" as every product or
category, enabled ones included, and reported their issues as if they were
disabled. They now take it as the Status filter of the product list does:
enabled only, or disabled only. The product data audit also refuses a Status
that is not yes or no instead of auditing every product.

**An order of priorities given with Use default order is checked against the
shop's default order.** When the assistant switched a product with its own
order of specific price priorities back to the default, it accepted that
product's own order beside it, so the product was moved to the default order
while the call named another one. The order given is now checked against the
shop's default order, and the change is refused when it differs.

**Packs: the quantity of a pack counted from its products is kept up to date.**
A pack whose stock is worked out from the products it holds kept 0 in its own
stock line when the assistant created it, and its old figure when its contents,
the way it counts its stock or the stock of one of its products changed: the
back office product list and the other modules showed a wrong quantity, while
the shop itself kept selling it correctly. The pack is now recounted from its
products after each of those changes, as the shop works out what can be sold,
and the answers give the new quantity: pack_quantity when creating or changing
the pack, packs when changing the stock of a product it holds.

## 2.0.2 - 2026-11-16

### Added

**Read one tax rule with the rate it applies in each country.** The new tool
somcp_get_tax_rule opens a rule of International > Taxes > Tax Rules as its
edit page shows it: its name, whether it is enabled, and every row of its list,
with the tax it applies per country, state and zip code range and its
behaviour. Until now the assistant only knew the rate in the shop's default
country, so it could not say what a customer delivered abroad pays.

**Products: the ecotax of each combination can be read and set, as on the
combination's page.** The product's own ecotax was there, not the one a
combination can carry, so a shop charging an ecotax per size or model could
not read or set it, and a product copied with its combinations lost it. It is
refused on PrestaShop 9 while the ecotax is switched off in the shop's tax
settings, as for the product.

### Changed

**The customer and main menu tools read identifiers as the others do.** A
customer group written in quotes, as the assistant sometimes copies it from an
answer ("3"), was refused when creating or changing a customer, though the
customer search took it: it is now read as group 3. When adding an entry to
the main menu or changing a custom link, an identifier the shop does not have
now names the tool that lists the right ones (the categories, products, CMS
pages, brands, suppliers or custom links), as every other tool does, and an
identifier that is not a number gets the same refusal everywhere.

**A product with its own order of specific price priorities can go back to the
shop's default order.** The product tools could give a product an order of its
own, never take it away, so a product kept it even after the merchant changed
the default. somcp_update_product now takes use_default_priorities, the Use
default order choice of the Pricing tab, and somcp_get_product says which of
the two the product follows.

**A meta title or description longer than search results show is pointed out
with its length, the same way by every tool.** The category, brand and
supplier tools said how long the text was; the product, CMS page and SEO & URLs
tools only said it was too long, each in words of its own, so the assistant
had to count before shortening it. Every tool now gives the same advice, with
the length: for example, the meta title is 85 characters, past 70, and a
search result cuts it.

**The prices given to the order tools take the names the other tools use.**
The unit price of a line added to an order, changed on an order or set on a
new order was named "tax excluded" / "tax included" in full, while the product
and catalogue tools read it with the short "tax excl." / "tax incl." names of
the order page, so a price read on a product and given back to an order was
refused as an unknown field, with nothing to say which name to send. The order
tools now take the same names, and a former name is answered with the one to
send instead of being refused as unknown.

**A main menu link read in the list can be given back to its update.**
Changing a custom link of the main menu from the row the list gave was refused
for "In menu", the flag the list itself shows, so the assistant had to drop it
by hand. The row now goes back as it was read: an unchanged "In menu" changes
nothing, and a different one is refused with what does it: the tool that
puts a link in the menu, or, for a link the merchant wants out of the menu,
the Main menu module's configuration screen, rather than a tool that would
add it. The list also says when a link has no label in the language read and
shows the default language's instead; given back, that label is no longer
copied into the other language, which stays as it was.

**A return status read in the list can be given back to its update.** Changing
a return status from the row the list gave was refused twice over: the
Removable flag the list shows was refused as worked out by the shop, and a
status created without a colour, which the list reads as empty, was refused
for that empty colour, so the assistant could not rename it without dropping
fields by hand. The row now goes back as it was read: an unchanged Removable
flag and an empty colour on a status that has none change nothing, and only a
different value is refused, with the reason. Every colour the module takes,
for an order status, a return status or an attribute value, is now checked by
one and the same rule.

**The stock and movement searches find what the search box of Catalog > Stock
finds.** A search for a colour or a size, such as Black, found nothing in the
low stock list or the stock movements, and on PrestaShop 9 neither did a
scanned barcode, so the assistant told the merchant that no such stock existed
while the Stock page listed it. Both searches now also match the attributes of
a combination and, on PrestaShop 9, the EAN-13, ISBN, UPC and MPN of the
product or the combination, exactly as the search box of the running version
does.

**Before an attribute group is deleted, the assistant can show what goes with
it.** Deleting a group such as Size removes every combination built only on
its values, with their stock, and the products concerned were only named once
the delete was done. Reading a group now also gives how many combinations hold
one of its values, how many are built only on them, and the products
concerned, counted exactly as the delete counts them, so the merchant confirms
knowing which combinations go.

**A feature delete names the products it touched as the feature read does.**
Deleting a feature answered the number of products that lost it as
`products`, while reading the feature gives the same number as
`products_count`, and the attribute deletes use `products` for the list of
the products themselves. The feature delete now answers `products_count`, so
the assistant reads the same count under the same name before and after the
delete.

**The shop pages' SEO reads and writes under the names of the other SEO
tools.** The pages of **Shop Parameters > Traffic & SEO > SEO & URLs** named
their Page title, Meta description, Meta keywords and Rewritten URL `title`,
`description`, `keywords` and `url_rewrite`, where the product, category, brand
and CMS tools say `meta_title`, `meta_description`, `meta_keywords` and
`link_rewrite` - and where `title` is the CMS page's own Title. The assistant
that followed the habit of the other tools was refused. The shop pages now use
the same names; a call with a former name is refused with the name to send
instead, and the record of a change reads as in the other update tools.

**The order address, carrier and currency changes give the total before and
after.** The assistant was told to say the new total before changing an
address or a carrier, which it could not know: PrestaShop recomputes the
shipping cost, the taxes and the total only during the change. It now warns
that they will be recomputed and gives the current total first, then the
previous and the new total once the change is made, from the same totals as
the order page. The currency change names those two totals the same way. When
PrestaShop fails half-way through an address change, the assistant no longer
says nothing was changed: it reads the order's addresses again before trying
once more, as it already did for the carrier.

**A CMS page read by the assistant can be given back to change its SEO.**
When the assistant read a page with `somcp_get_cms_page` and gave it back to
`somcp_update_cms_seo` with a new Meta title, the call was refused for the
Title, the content, the status and the other fields it carried, and had to be
made again. They are now accepted as long as they are unchanged; a changed one
is refused with where you change it, in **Design > Pages > Edit**. On
PrestaShop 8, the assistant can also change the page's **Meta keywords**.

**The same order status icon given again changes nothing.** When the assistant
gave an order status the icon it already had, `somcp_update_order_status`
wrote the file again and reported a change, so the same call twice never said
that nothing was written. The icon is now compared with the stored one first:
the same file is left as it is, and the change record says whether an icon was
replaced or a status without one got its first.

**A misspelt filter or field of the content page tools is refused rather than
ignored.** When the assistant listed the pages of Design > Pages with a filter
named wrongly, such as `id_category` for the page category, it got the pages of
every category and took them for the ones asked for; a wrong page number was
answered in a sentence of its own, and a call with two mistakes needed two
calls. `somcp_list_cms_pages` and `somcp_get_cms_page` now report every problem
of a call at once, worded as in the other tools, and read a page number given
as text, such as "3", as the other tools do.

**The dates of abandoned carts and searched terms carry your shop's time
zone.** The abandoned carts list and the searched terms list gave their dates
as stored, without a time zone, while orders, customers and vouchers give
theirs with your shop's offset. Set side by side, the assistant could find an
hour between a cart and an order that was not there, or tell a customer abroad
the wrong time. They now read like every other date.

### Fixed

**A product changed by the assistant is found by the shop search with its new
words.** Changing only the tags, categories, features, brand, references or
combinations of a product left the search index as it was, so a customer
searching for a new tag did not find the product until the next full
reindexing. The product is now indexed again after every change, as the back
office does on each save, when automatic indexing is turned on.

**A product image can no longer be linked to a combination the same change
deletes.** When the assistant removed a combination of a product and, in the
same change, named that combination among those an image illustrates, the
combination was deleted and the image was then linked to it, leaving a link
to nothing. The change is now refused, as it already was for a specific price
or a supplier reference, and nothing is saved.

**A built-in order status refused for deletion reads like the other
refusals, and a new status gives its identifier under the usual name.** When
the assistant tried to delete one of the order statuses of PrestaShop or of a
module, the refusal was a sentence of its own, unlike the other refusals of
the tool. It is now given in the same list, with the name of the status and
the back office sentence "For security reasons, you cannot delete default
order statuses.". The assistant is also pointed to the **Hide this status in
all customer orders.** box when the merchant only wants customers to stop
seeing a status. A status created by `somcp_create_order_status` now gives
its identifier as `status_id`, the name the update and delete tools take,
instead of `id_order_state`.

**A deleted category no longer announces what stayed behind.** When
PrestaShop could not delete one of the subcategories of a category, the
assistant still listed it as deleted; it is now listed apart, as still in
your shop, with a sentence asking to check it. When a product deleted with
its category was removed from the shop but PrestaShop could not clean up
everything attached to it, the assistant now says so, in the same words as
the deletion of a single product. Before deleting, it tells you how many
subcategories go with the category and what the chosen mode does to its
products.

**A deleted return status always says which language its name is in.**
When a return status had no name in your shop's default language,
`somcp_delete_return_status` answered with the name found in another
language, but left the language empty if that language had been turned off
since. It now gives its code in every case, as the deletion of an order
status does.

**A new order is refused rather than placed without what was asked.** When
the free shipping of a new order could not be saved, `somcp_create_order`
placed the order anyway, with the shipping charged. It is now refused and
nothing is recorded. A combination is also checked to be sold in your shop,
like the product, so an order of one shop can no longer carry a combination of
another; a combination given as "0" means none, as 0 does, and a product or
combination that is not a whole number above 0 is named as such.

**A product or combination that is not an identifier is named as such when
adding a product to an order.** When the assistant gave
`somcp_add_order_product` a product or a combination that is not a whole
number above 0, for example -1, it was told to give a product as if it had
left the field out, or that the identifier must be positive in words no other
tool uses. It now reads "a whole number above 0", as everywhere else, and a
missing product keeps its own message. The refund lines and the invoice of a
payment read their identifiers by the same rule as every other order tool.

**A resent order status email that fails now says where to look.** When the
shop could not send the email again, the assistant only knew it had failed and
could not tell the merchant what to check. It now says to look at the email
settings in **Advanced Parameters > E-mail**, and whether the customer or the
email template of the status could not be loaded. The status line it gives back
is built by the same code as the order's history, so it always matches the line
the assistant read.

**A misnamed field is refused on refunds, returns and cancellations.** When
the assistant gave `somcp_issue_standard_refund`, `somcp_issue_partial_refund`,
`somcp_return_order_products` or `somcp_cancel_order_products` a field they do
not take, for example a shipping refund on a cancellation, the field was
ignored and the rest carried out, while you were told it had been done. The
call is now refused with the name of the field, and nothing is recorded. A
standard refund refused on an unpaid order now points to the cancellation of
products, and the refund and return tools say which one fits a delivered
order, an order not delivered yet or a chosen amount.

**A discount refused on an order with invoices gives the right ceiling.** When
an amount added to an order with invoices was too high, the assistant was
told the order total, while PrestaShop compares the amount with the invoice
chosen, or with each invoice of the order; it then tried a figure that was
refused again. The refusal now names the invoice and its total. Free shipping
that PrestaShop refuses, because the shipping is above the total of the
invoice or the order, is now explained instead of a bare refusal. If adding a
discount fails half-way, the assistant no longer says nothing was saved: it
checks the order's discounts before trying again.

### Security

**An assistant's return address is read the way the browser reads it.** The
address an assistant gives to receive the merchant's approval is now refused
when it carries a user name, a backslash or a blank, whatever site it seems to
name, at registration and again at each sign-in. The known assistants never
use such addresses: nothing changes for them.

## 2.0.1 - 2026-11-15

### Added

**Upgrade a module.** The assistant can now upgrade a module already
installed, as **Upgrade** in **Modules > Module Manager** does
(`somcp_upgrade_module`): from the archive of a newer version you give it, or
from newer files already in the module's folder, put there by FTP for
example. It first checks, as PrestaShop does before installing a module, that
the new version is higher, made for your PrestaShop version, with the modules
it needs already installed; otherwise nothing is changed. With an archive, the
previous files are put back if anything fails before the upgrade starts.
Uploading a module still takes only modules that are not on the shop yet.

**Your shop is in maintenance while a module is installed or upgraded.** The
assistant closes the shop just before a module's files or scripts change it,
and reopens it once everything went through, so customers never meet a module
half installed. A shop you had already closed stays closed. If the install or
the upgrade fails, the shop stays in maintenance and the assistant tells you:
reopen it in **Shop Parameters > General > Maintenance** once you have checked
it.

**Category thumbnail.** The assistant can now set the **Category thumbnail**
of a category, the small image shown where its parent category lists its
subcategories, when it creates or changes the category, and reads it back with
the category. The files are written where the back office writes them on your
PrestaShop version. On PrestaShop 9, which has a Delete button for it, the
assistant can also remove it.

### Changed

**Refund an order's shipping in the tax mode it shows.** When you ask the
assistant to refund part of an order's shipping, it now gives the amount in the
tax mode the order shows, tax excluded or tax included, the same way it already
gives the amounts of the lines; the field in the wrong mode is refused with the
right one named. The refunded shipping is reported back both tax excluded and
tax included.

**An image's combinations, as the product page reads them.** When you ask the
assistant to change which combinations a product image illustrates, it can now
name them by the identifiers it just read on the product, instead of counting
their place among the combinations listed in the same call. Giving an image
back the combinations it already shows changes nothing, and a combination of
another product is refused.

**Cart rule identifiers, named as everywhere else.** When the assistant reads
a cart rule's **Product selection**, each product, category, attribute, brand
or supplier now comes with the identifier name of its kind (`id_product`,
`id_category`, `id_attribute`, `id_manufacturer`, `id_supplier`) instead of a
bare `id`, so it can tell them apart and give them back. A Memcache server of
**Advanced Parameters > Performance** is read with `id_memcached_server`. A
cart rule identifier, or a customer group in the catalogue price rules,
written as digits in quotes is now accepted by every cart rule tool, as the
order tools already did.

**A combination's specific price and supplier, as the product page reads
them.** When you ask the assistant to change the specific price or the
supplier reference of one combination of a product, it can now name that
combination by the identifier it just read on the product, instead of first
listing it again among the combinations to count its place. A combination of
another product is refused, and nothing is changed.

**Limiting a specific price to one customer.** When you ask the assistant to
set a product's specific price for a single customer, it is now told that
`somcp_search_customers` finds that customer, so it points you to the search
instead of leaving the field unexplained.

**The built-in assistant runs on Neuron AI 4.1.25.** A conversation answered
from two tabs at once now shows the answer of the latest one instead of an
error.

**Sign-in page of a connection.** The anti-robot check is updated to ALTCHA
3.6.0, with corrected apostrophes in its English and French texts.

**Order dates say their time zone.** The assistant now reads every date of an
order - when it was placed, its payments, shipments, status changes, invoices,
delivery and credit slips, messages - with the time zone of your shop, as it
already did for customers, so it no longer gets an hour wrong for a customer
elsewhere or sees a gap between two tools that is not there. A date not set
yet is given as empty instead of `0000-00-00`. A file the assistant hands
over, an order document or a photo, says when its link stops working in your
time zone too. Searching orders gives the order date as `date_add` beside
`date`, which is kept for the tools already built on it.

**Promotion dates say their time zone too.** The start and end of a specific
price and of a catalog price rule are read with your shop's time zone. A start
or end the assistant gives in another time zone, in UTC for example, is saved
at the right hour in yours instead of an hour off, and an end set before the
start is caught even when the two were written in different time zones. The
dates of a voucher are saved the same way.

**A voucher with no date reads as not set.** Listing vouchers and reading one
gave a missing start or end date as an empty text, where every other tool says
it is not set. Both now say it is not set, so a voucher read and given back to
be changed carries the same value the other tools use.

**The SEO and AI visibility tools refuse a misspelt setting.** When the
assistant calls one of these tools with a setting it does not take - a field
name spelt wrong, for example - the tool now says so instead of ignoring it
and answering as if the setting had applied. Every mistake of a call comes
back in one answer. The page audit now refuses an address outside the shop in
the same words as the link check, together with any other mistake of the call,
and the product page audit says which identifier is wrong and where to find
the right one. The product and category SEO audits no longer swap a wrong
setting for a default - a check name that does not exist, a page size given as
a word, a "no" for active products - but name it, and the changes of a CMS page
or of a Traffic & SEO page name the identifier that matches no page and where
to find the right one, with the other mistakes of the call.

### Fixed

**A customer group of another shop is refused in price rules.** On a
multistore, the assistant could filter the catalogue price rules by a customer
group that the shop does not have, or give one to a new catalogue price rule or
a product's specific price, because the group existed somewhere else in the
installation. Only the groups of the shop, as **Shop Parameters > Customer
Settings > Groups** lists them, are accepted now, so a rule or a price always
targets a group your customers can belong to.

**The viewed-but-not-sold list no longer says every page view is zero.** When
*Save global page views* is off, the note of `somcp_list_viewed_not_sold` said
every page-view count was zero. If the setting had been on earlier in the
period, some counts were in fact above zero, so the assistant could tell you a
product had no views when it had. The note now says page views are not being
recorded any more, so the counts cover only the time the setting was on during
the period, if any; the sales figures stay real.

**The total of the abandoned carts says when a cart is missing from it.**
When the assistant adds up the value of your abandoned carts, a cart that could
no longer be priced, because a product in it was deleted since, was left out of
the sum while the answer still said every cart was counted. The answer now
says the total is partial in that case too, so the assistant tells you rather
than giving the figure as the whole. The carts of the page are also no longer
priced twice, which makes the list quicker on a shop with many carts.

**An llms.txt the module did not write is refused, not reported as written.**
When the shop already has an llms.txt made by hand or by another tool, and the
assistant asks to generate one without saying to replace it, the answer was a
normal one, so an assistant could tell you the file was written when nothing
was. It is now refused as an error, nothing is written, and the current file is
shown so you can decide whether to replace it.

**Installing a module from the assistant works.** Installing or uploading a
module from an assistant failed on every shop with the PrestaShop module that
fetches modules from PrestaShop's servers, installed by default: it needs
something only the back office provides, and stopped the install. The
assistant now installs modules the way the back office does.

**The file of a virtual product shows in the back office.** A virtual product
the assistant created with its file, still offline, showed **Add downloadable
file** at **No** in the back office, on a shop that had never sold a virtual
product: the file was stored, but PrestaShop only starts looking for such
files once a virtual product is saved online. The assistant now tells
PrestaShop to look for them as soon as it stores one. A customer still
downloads a file only from a paid order of their own.

**A file the assistant sends goes in once, under its name.** Asked to attach
one PDF to a reply, an assistant could send it several times, and the reply
went out with three attachments, two of them named "file" and blank: it took
"received" for "replaced", and when it could not copy the real file it built
an empty one. The shop now tells it after each file how many it holds, that a
new send adds one, and the name and size of each, so it can see a file that
is not the one it meant. A file it sends as raw content keeps the name it
gives.

**The upload page comes last.** When a file is needed, the assistant first
sends it to the shop itself, then hands over a file it holds from the
conversation, and only when both fail does it give you the page to drop the
file on. Before, it got the page at once and often went straight to it, asking
you to upload by hand a file it already had. If your assistant tells you to
allow your shop's domain in its network settings, doing so once lets it send
files itself from then on.

**PNG photos reach the assistant as images.** With the image quality setting
on PNG, PrestaShop keeps product photos, logos and category images as PNG in
files named .jpg. The assistant was handed them as files of unknown type and
told not to read them, so it could not show or describe the photo. They now
go out as the PNG images they are. A file that is not an image at all still
goes out under no type.

## 2.0.0 - 2026-11-14

A major version, for compact mode: connected assistants can now receive six
tools instead of the whole list, search the one they need and run it, with the
same permissions and the same checks. It is on for a new shop and stays off
for a shop that updates, until you turn it on in **Tools and prompts >
Settings**. Your settings, connections and conversations are kept.

### Added

**Files go to and from any assistant.** Every file now goes through two new
tools, whatever assistant you connect, and no other tool hands out a file or
its address on its own:

- `somcp_upload_file` puts a file on the shop for the tool that needs it - a
  product photo, a brand logo, the file of a virtual product, a module archive.
  A photo you attach in your ChatGPT conversation reaches the shop directly,
  without a web address and without the deposit page; an assistant that holds
  the file itself sends it in the call; one that has a web address gives it.
  Otherwise the assistant sends the file to the shop itself when it can, and
  only when it cannot does it give you the deposit page to drop it on. If the
  assistant's own settings block it from reaching your shop, it tells you once
  which address to allow there, so it can do it alone next time. Each tool
  keeps its own rules - formats and size - whichever way the file comes, and a
  module archive is never taken from a link.
- `somcp_download_file` gives the assistant any file of the shop another tool
  found - a product photo, a logo, an attachment, an invoice, a delivery slip,
  a credit slip - attached to its answer, a photo as a picture the assistant
  can see: it reads the file or shows it to you instead of handing you a link.
  Only a file too large to attach comes as an address it fetches itself for
  15 minutes, or a link for you to open it in your back office, as in the
  built-in assistant. It is handed over only to an account that may use the
  tool that found it, and under the type its content shows: a file of
  **Catalog > Files** whose content is not what its name says is never passed
  off as a picture or a document. Its name keeps plain letters and digits
  only, so an invoice numbered #FA000104/2026 arrives as FA000104-2026.pdf,
  which the assistant can show you.

Before, an assistant connected from outside could only give the shop a web
address: a file it had made, or one you gave it, had no way in. Modules asking for files
through O'So MCP Server get the same: the assistant now puts the file in itself
when it can - a photo attached in your ChatGPT conversation included - without
any change on their side. And a module can now hand a file to the assistant
the same way, attached to the answer, instead of a link of its own.

**Modules the assistant can upload.** The new tool `somcp_upload_module`
takes a module's .zip archive you attached to the chat or deposited on the
shop, puts it in the modules folder and installs it, as the **Upload a
module** button of **Modules > Module Manager** does. The archive is checked
before anything is written: one folder holding the module's main file, nothing
outside it, no link, and a reasonable unpacked size. A module already on the
shop is refused and never replaced or upgraded, and a module whose install
fails is removed again, leaving nothing behind. Because the archive's code
comes from outside the shop and runs on it with full rights, the assistant
needs a permission of its own for this, **Modules - upload**, which the
consent screen asks for apart from installing the modules already there. It
also needs the **add** right on the **Module Manager**. A link is never
accepted: the archive comes from the shop.

**Feature values the assistant can delete.** The new tool
`somcp_delete_feature_value` deletes one value of a feature, such as Cotton
under Composition, as the **Delete** action on the values of a feature in
**Catalog > Attributes & Features > Features** does. As in the back office,
every product that had the value loses it. The answer says how many products
lost it and which catalogue price rules still have a condition on it, so you
can change or remove them. A value typed on one product only is refused: it is
changed on that product. On a multistore installation, a value of a feature
shared with other stores is refused, because deleting it would remove it from
their products too. It needs the **delete** right on **Features**.
`somcp_get_feature_value` now also says how many products have the value, so
the assistant can tell you before you confirm a delete.

**Features the assistant can delete.** The new tool `somcp_delete_feature`
deletes a feature such as Composition with all its values, as the **Delete**
action of **Catalog > Attributes & Features > Features** does. As in the back
office, every product that showed the feature loses it. The answer says how
many values went, how many products lost the feature, and which catalogue
price rules still have a condition on one of the deleted values, so you can
change or remove them. On a multistore installation, a feature shared with
other stores is refused, because deleting it would empty it in all of them:
delete it from the back office for all stores. It needs the **delete** right on
**Features**.

**How many products show a feature.** `somcp_get_feature` now also gives
`products_count`, the number of products of the store that show the feature,
so the assistant can tell you what a deletion would take away before asking
you to confirm.

**Attribute groups the assistant can delete.** The new tool
`somcp_delete_attribute_group` deletes an attribute group such as Size or Color
with all its values, as the **Delete** action of **Catalog > Attributes &
Features > Attributes** does. As in the back office, the combinations built
only on that attribute are deleted with their stock and images, and the others
lose that one value. The answer says how many values and combinations went,
which products were concerned, and which catalogue price rules still have a
condition on one of the deleted values, so you can change or remove them. On
a multistore installation the group only leaves the current store. It needs
the **delete** right on **Attributes**.

**Attribute values the assistant can delete.** The new tool
`somcp_delete_attribute_value` deletes one value such as a size or a colour, as
the **Delete** action of that value on **Catalog > Attributes & Features >
Attributes** does. As in the back office, the combinations that use the value
go with their stock and images, a product that loses its default combination
gets a new one, and the cart rules that restricted on the value are cleaned.
The answer lists the combinations and products that went, the cart rules that
changed, and the catalogue price rules that still have a condition on the
value, so you can change or remove them. Before deleting, `somcp_get_attribute_value`
now shows **combinations_count** and the **products** that would go, so you can
confirm with the merchant first. On a multistore installation the value only
leaves the current store. It needs the **delete** right on **Attributes**.

**The category dashboard figures.** The new tool `somcp_get_category_kpis`
returns the four numbers shown above the category list in **Catalog >
Categories**: how many categories are disabled, how many hold no product, the
best selling category of the last month and the average number of products per
category, counted as the back office counts them. The assistant reads them in
one call instead of going through every category one by one. It needs the
**view** right on **Categories**.

**Compact mode.** More than a hundred tools weigh on every request a connected
assistant makes, and some cap the count - VS Code stops at 128. In compact
mode, inspired by the MCP servers of Cloudflare and Azure, connected
assistants receive six tools instead of the whole list: `somcp_ping`, the two
file tools `somcp_upload_file` and `somcp_download_file`, `somcp_search_tools`
to find the tool they need and read its parameters, then
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
the carrier, the total and the status first. It needs the **add** right on
**Orders**.

**Invoices, delivery slips and credit slips handed over.** The new tool
`somcp_get_order_document` finds the PDF of an invoice, a delivery slip or a
credit slip of an order, the same document **View invoice**, **View delivery
slip** and the **Documents** tab open, and the assistant then gets the file
itself with `somcp_download_file` (below), with a link that opens it in your
own signed-in back office, for you only, for 15 minutes. It needs the **view**
right on **Orders**. `somcp_get_order` now also gives the number of each credit slip,
as the **Documents** tab shows it.

**Order statuses the assistant can change.** The new tool
`somcp_update_order_status` changes an existing order status, as its **Edit**
form of **Shop Parameters > Order Settings > Statuses** does: its name in each
language, its colour and icon, the boxes that say what reaching it sets off,
and its email templates. Only what you ask for changes, and a call that would
change nothing writes nothing. A name another status already has is refused,
as at creation. The orders already past the status are not affected. It needs
the **edit** right on **Statuses**.

**Order statuses the assistant can delete.** The new tool
`somcp_delete_order_status` removes an order status you created, as the
**Delete** action of **Shop Parameters > Order Settings > Statuses** does. The
orders already on it keep it in their history; the status just leaves the list
and the choices when you change an order. The statuses of PrestaShop and of
your modules cannot be deleted, as in the back office. It needs the **delete**
right on **Statuses**.

**Categories the assistant can delete.** The new tool `somcp_delete_category`
deletes a category with all its subcategories, as the **Delete** action of
**Catalog > Categories** does, and asks you, as the back office does, what
becomes of the products left without a category: moved to the parent category
and turned offline, moved only, or deleted as well. Unlike the back office,
only the products of the deleted categories are touched, never a product that
already had no category, and a product whose default category was deleted
gets the parent category as its new default on PrestaShop 8 too. The answer
lists the subcategories deleted and every product moved, turned offline or
deleted. The root category and Home cannot be deleted. It needs the **delete**
right on **Categories**.

**Stock movements the assistant can read.** The new tool
`somcp_list_stock_movements` lists the movements of the **Movements** tab of
**Catalog > Stock**, newest first: the product and its combination, the
reference, the type of movement, the quantity added or removed, the date, the
employee who made it and the order behind it when there is one. It takes the
filters of that tab - a product, a word of the name, reference or supplier, a
movement type, an employee, a period, a category, a supplier and the product
status - so a question such as "who changed the stock of this product
yesterday" now has an answer. It needs the **view** right on **Stock**.

### Changed

**A guide no longer shares its name with a tool.** In French, Spanish and
German, the guide **Change an order status** read exactly like the tool that
changes the status of one order, and both show in **Tools and prompts**. The
guide is now named **Changer le statut des commandes**, **Cambiar el estado de
los pedidos** and **Den Status von Bestellungen ändern**.

**Photos, logos and files come through `somcp_download_file`.** The tools
that read a product, a category, a brand, a supplier, an attribute value, the
order statuses, the carriers, the theme or the files of **Catalog > Files** no
longer give a web address for their pictures and files: each one now comes as
a reference the assistant passes to `somcp_download_file`, which checks your
rights again and hands the file over - a photo as a picture it can show and
look at. The storefront link of a file of **Catalog > Files**, which opened
for anyone who had it, is no longer handed out. Giving a read answer back to
the matching update still changes nothing: the reference of the current photo,
logo or texture keeps it as it is.

**Compact mode finds the right tool more often.** In compact mode the
assistant can now say what it looks for in separate words - the action, the
object and the request - and the search weighs the name, title, description and
parameters of each tool, with plurals and near spellings found. The built-in
assistant's tool finder uses the same search. The tools, their names and the
checks on every call are unchanged. The search comes from Novalo's open-source
library novalo/mcp-tool-discovery, which the module now installs with its other
dependencies.

**Photos you attach show in the chat.** A photo you attach in the built-in
assistant now shows as a picture in your message, as soon as you send it and
when you open the conversation again, instead of its file name only. A PDF or
a text file still shows by its name, and so does a photo whose link has
expired.

**The product audits name each missing field as your PrestaShop version does.**
`somcp_audit_product_entities` and `somcp_audit_product_schema` now label the
weight, the default category and the condition exactly as the product page of
your shop shows them (for example **Main category** on PrestaShop 8 and
**Default category** on PrestaShop 9), from one shared table, so a field never
comes back under two different names.

**A product's images read and given back are no longer refused.**
When the assistant reads a product with `somcp_get_product`, changes one field
and gives the whole answer back to `somcp_update_product`, the address each
image came with is now ignored next to its `id_image`, instead of refusing the
image as if it were being replaced. Any other address next to an `id_image` is
still refused: an existing image cannot be swapped for another one.

**A category's images and customer groups read and given back no longer count as a change.**
When the assistant reads a category with `somcp_get_category`, changes one field
and gives the whole answer back to `somcp_update_category`, the cover image and
menu thumbnails it read are now left untouched: they are not downloaded again,
not refused, and the answer no longer lists an image change that did not happen.
The customer groups it read are taken as they are, as they already were by the
shop, and an assistant that checks its own call before sending it no longer
stops at them. A real new image, or removing one with `remove_cover_image` or
`remove_menu_thumbnails`, works as before.

**Empty attribute and feature fields read and given back are no longer refused.**
When the assistant reads an attribute value, an attribute group, a feature or a
feature value, changes one field and gives the whole answer back to its update,
the fields that came back empty because they do not apply are now skipped
instead of refused: the colour and texture of a value whose group is not a
colour group, and the faceted search fields (URL word, title tag, indexable)
when the faceted search module is not installed and enabled. Filling one of
these fields in where it does not apply is still refused, as before.

**A colour texture can be a photo attached in the chat.** The texture of
`somcp_create_attribute_value` and `somcp_update_attribute_value` used to be
only a web address. It is now given the way product images and logos are:
either its public https address (`url`) or the reference of a file already on
the shop (`upload_ref`), such as a photo attached in the built-in assistant,
with the same download and image checks. A texture sent as a bare address, as
before, is now refused with a message that says which form to use. A texture
read with `somcp_get_attribute_value` and given back unchanged is no longer
downloaded again nor counted as a change. The icon of an order status is now
fetched by the same shared code as every other image, with its GIF check on
top.

**A brand or supplier logo read and given back no longer counts as a change.**
When the assistant reads a brand or supplier with `somcp_get_manufacturer` or
`somcp_get_supplier`, changes one field and gives the whole answer back to
`somcp_update_manufacturer` or `somcp_update_supplier`, the logo address it read
(or the empty logo) is now left untouched: the file is not downloaded again and
the answer no longer lists a logo change that did not happen. A real new logo,
or removing one with `remove_logo`, works as before.

**Vouchers on the orders the assistant creates.** `somcp_create_order` now
takes the vouchers to apply, as the **Vouchers** block of **Orders > Orders >
Add new order** does. Each voucher is checked on the cart once the products,
addresses and carrier are set, as the screen checks it: one that does not apply,
for example below its minimum amount or restricted to another customer, is
refused with the shop's own reason, and nothing is created. The answer lists
the vouchers applied, as `somcp_get_order` does, and `somcp_get_order` now also
gives the voucher behind each discount of an order.

**Your own price for a line of the orders the assistant creates.**
`somcp_create_order` now takes a unit price tax excluded for each line, as the
**Price** field of the **Cart** block of **Add new order** does: the order
records the line at that price, while the lines left without one keep the price
the shop computes for the customer. The price stays tied to that order's cart
only, as on the screen, and a refused call leaves no price behind. The answer
now lists the lines as the order records them, with the same names as
`somcp_get_order`, so the assistant can show you the price it set.

**The same meta title and description lengths as the back office, for the
category and brand tools.** `somcp_create_category`, `somcp_update_category`,
`somcp_create_manufacturer`, `somcp_update_manufacturer`,
`somcp_create_supplier` and `somcp_update_supplier` now refuse a Meta title or
Meta description only when it is longer than the shop stores (255 and 512
characters), exactly as the back office does, instead of the shorter limits
they used before. A title read by `somcp_get_category` can now be given back to
`somcp_update_category` unchanged. Past the length a search result keeps (70
characters for a title, 160 for a description), the answer still advises, in
`seo_warnings`, without refusing.

**The SEO audits flag a meta title at the same length the writes advise.**
`somcp_audit_product_seo`, `somcp_audit_category_seo`, `somcp_audit_cms_seo`,
`somcp_audit_brand_seo` and `somcp_audit_page` used to flag a meta title past 60
characters, while the tools that write it only advised past 70: a 65-character
title written without a warning was then reported as too long. Every audit now
flags a title past 70 characters and a description past 160, the lengths the
back office recommends, and `somcp_update_cms_seo` and `somcp_update_meta_page`
advise at those same lengths.

**The same meta title and description lengths as the back office, for
products, CMS pages and SEO & URLs pages.** `somcp_create_product` and
`somcp_update_product` used to refuse a Meta title past 55 characters,
`somcp_update_cms_seo` a Meta title past 70 and `somcp_update_meta_page` a Page
title past 70, while the back office stores up to 255 for a product or a CMS
page and 128 for a page of **Shop Parameters > Traffic & SEO > SEO & URLs**. A
title read by `somcp_get_product` can now be given back to
`somcp_update_product` unchanged. All four now refuse a title or a description
only when it is longer than the shop stores, as the back office does, and
advise in `seo_warnings` past 70 characters for a title and 160 for a
description.

**Clearer refusals from the category tools.** When the assistant gets a call
to `somcp_get_category`, `somcp_create_category`, `somcp_update_category`,
`somcp_set_category_active` or `somcp_delete_category` wrong, the answer now
lists every problem in the same form, one line per field, and names the field
concerned. A misspelt field is reported as such instead of being read as a
missing category, and an unknown category or customer group is always worded
the same way, with the tool that lists them, so the assistant corrects the
call instead of trying other identifiers. `somcp_delete_attribute_group`
answers the same way, and so do now the tools that create and change
attribute groups, attribute values, features, feature values, brands, brand
addresses and suppliers: an unknown attribute group, attribute value, feature,
feature value, brand, brand address or supplier is named with the identifier
given and the tool that lists them. `somcp_create_product` and
`somcp_update_product` answer the same way: every category, brand, feature,
document, carrier, tax rule, attribute value, customer group, customer,
supplier or related product they do not find is named with its identifier and
the tool that lists it, a combination, specific price or image the product
does not have points to `somcp_get_product`, a misspelt field reads as such
even inside a group, and a product that does not exist is refused in the same
answer as any other mistake. The read tools `somcp_get_attribute_group`,
`somcp_get_attribute_value`, `somcp_get_feature` and `somcp_get_feature_value`
now answer this way too: a misspelt field is named instead of being read as a
missing record, and an unknown attribute group, attribute value, feature or
feature value is worded with the identifier given and the tool that lists it.
The brand and supplier tools `somcp_get_manufacturer`, `somcp_get_supplier`,
`somcp_get_manufacturer_address`, `somcp_list_manufacturer_addresses`,
`somcp_set_manufacturer_active`, `somcp_set_supplier_active` and
`somcp_delete_manufacturer_address` follow the same rule: a misspelt field or
filter is named and nothing is read, changed or deleted, rather than being
ignored or read as a missing brand, and an unknown country code and an unknown
brand are reported together in one answer. `somcp_get_product`,
`somcp_set_product_active`, `somcp_delete_product`, `somcp_get_stock` and
`somcp_set_stock_quantity` do the same: a misspelt field is named and nothing
is read, switched, deleted or moved, an unknown product is worded with the
identifier given and `somcp_search_products`, a combination the product does
not have points to `somcp_get_product` or `somcp_get_stock`, and a stock change
reports a quantity that is not a whole number, a missing product and a wrong
combination together in one answer. The lists `somcp_list_categories`,
`somcp_list_attachments`, `somcp_list_attributes`, `somcp_list_features`,
`somcp_list_manufacturers` and `somcp_list_suppliers` name a misspelt filter
instead of answering the whole list as if it had been applied, refuse an
Enabled filter that is not true or false instead of ignoring it, and word an
unknown attribute group or feature as the matching read tool does, with the
identifier given. `somcp_list_stock_movements` and `somcp_get_category_kpis`,
written more recently, name a misspelt field or filter and read nothing the
same way, so every catalogue and stock refusal reads alike. A product or
category read on one PrestaShop version and given back unchanged to its update
is no longer refused over a field that version does not have: the empty
meta keywords of PrestaShop 9, its empty menu thumbnails, the empty
redirection of a hidden category on PrestaShop 8 and the empty labels per
combination on PrestaShop 8.0 are skipped, and a value typed in one of them is
refused naming the version you run. `somcp_search_products` now refuses an
order it does not know, such as "price", with the list of the ones it has,
instead of answering the newest products first, and refuses a misspelt
filter, an Enabled filter that is not true or false and a price, quantity,
product or category that is not a number instead of ignoring them;
`somcp_list_low_stock` does the same for its filters instead of listing the
whole stock.

**Refusals of the order writes read like the others.** The refund, return
and cancel tools, the tools that add, change or delete an order line or a
discount, `somcp_create_order`, `somcp_generate_order_invoice`,
`somcp_set_invoice_note`, `somcp_set_order_address`, `somcp_set_order_carrier`,
`somcp_set_order_currency` and `somcp_resend_order_status_email` now refuse a
call under the same first line as the catalogue tools - "Nothing was
recorded", "changed", "deleted", "generated" or "sent", then "Correct these and
call again:" - so the assistant reads every refusal the same way and fixes
every problem listed before it calls again. Nothing else in these answers
changes. The order and return status tools and the carrier and tax rule lists
follow the same rule: `somcp_list_order_statuses`, `somcp_list_return_statuses`,
`somcp_list_carriers` and `somcp_list_tax_rules` name a misspelt filter instead
of answering the whole list, and refuse an Enabled or Free shipping filter that
is not true or false instead of ignoring it; `somcp_update_order_status`,
`somcp_delete_order_status`, `somcp_update_return_status` and
`somcp_delete_return_status` word an unknown status with the identifier given
and the list that holds it, and say when the identifier is not a number at
all; the two deletions also name a misspelt field, and delete nothing.

**Clearer refusals from `somcp_set_order_note`.** A wrong call to
`somcp_set_order_note` now lists every problem in one answer, in the same form
as the other tools, names a misspelt field instead of ignoring it, and reports
a note and a missing order together rather than one at a time, so the private
note of an order is corrected in a single call.

**Clearer refusals from the order payment, message, tracking and status
tools.** A wrong call to `somcp_add_order_payment`, `somcp_add_order_message`,
`somcp_set_order_tracking` or `somcp_set_order_status` now lists every problem
in one answer, each named by its field, in the same form as the other tools,
instead of stopping at the first one: a payment with a wrong amount and a wrong
date is corrected in one call, not two. A misspelt field is named instead of
ignored, an unknown status, invoice or shipment points to the tool that lists
them, and nothing is recorded, changed or emailed while a problem remains.

**`somcp_search_orders` refuses a filter it cannot use.** A misspelt field, a
sort it does not know such as "amount", a date that is not YYYY-MM-DD, a
`total_min` or `total_max` that is not a number, a `new_client` that is not true
or false, or a `status_id` that is not a whole number above 0 or matches no
order status is now listed in one answer and nothing is read, instead of being
dropped or read in the default date order and reported as the orders asked for.
An unknown status points to `somcp_list_order_statuses`, the way every order
tool words it.

**A misspelt field reads the same in every order and invoice tool.**
`somcp_create_order`, `somcp_add_order_product`, `somcp_update_order_product`,
`somcp_delete_order_product`, `somcp_add_order_discount`,
`somcp_delete_order_discount`, `somcp_set_order_currency`,
`somcp_issue_partial_refund`, `somcp_issue_standard_refund`,
`somcp_return_order_products`, `somcp_cancel_order_products`,
`somcp_generate_order_invoice`, `somcp_set_invoice_note` and
`somcp_get_order_document` now name a field they do not take, on the call or on
one of its lines, with "not a field of this tool", the wording every other tool
already gives, instead of a wording of their own. The misspelt name is fixed in
one call rather than read as a wrong value.

**An unknown order reads the same in every order and billing tool.** When an
order cannot be found, every order and billing tool now words it the same way,
named by the field that was wrong: `id_order: no order of this shop has the
identifier <n> - find it with somcp_search_orders`, the same line on
`reference`, or `id_order: give id_order or reference - find the order with
somcp_search_orders` when both are missing. A reference held by several orders
is still refused with their identifiers. `somcp_get_order` now reports a
misspelt field as such and lists it beside a wrong order in one answer, instead
of reading an unknown order on its own line, so the assistant recovers the same
way wherever it reads an order.

**A customer read gives back its groups the way its tools take them.**
`somcp_get_customer` now returns `group_ids` and `id_default_group` inside the
customer, beside the fields `somcp_update_customer` and `somcp_create_customer`
already take, so an account read there can be handed straight back to a change
without reshaping the groups first. The named groups stay, for the merchant.
Handing the `groups` list back to a change no longer fails without a hint: it
is answered with where the groups go.

**A customer that cannot be found reads like the rest.** `somcp_get_customer`
now names a misspelt field instead of reading it as a missing customer, and
words an unknown `id_customer` or `email` the same way as every other read
tool, with the tool that lists customers, so the assistant looks it up the
same way it does elsewhere. `somcp_update_customer` and
`somcp_create_customer` follow: an unknown customer is reported together with
any misspelt field of the same call, and a group the shop does not have is
named with `somcp_list_customer_groups`, where the assistant finds the right
one. `somcp_search_customers` now refuses a misspelt filter, an order or
direction it does not know, a date that is not YYYY-MM-DD and a yes/no filter
that is not true or false, instead of ignoring them and answering the whole
list newest first.

**SEO of CMS pages and shop pages written like the rest of the catalogue.**
`somcp_update_cms_seo` and `somcp_update_meta_page` now take the languages to
fill and their texts as the product, category and brand tools do: a
`languages` list, all the shop's languages by default, and each text given in
each of them, a plain text being accepted when only one language is filled.
Their errors are now the same as those tools', so an assistant that has
written a product writes a page the same way. `somcp_delete_order_status`
names the deleted status in the default language of the shop it is
connected to, rather than in the default language of the whole
installation, and says which language that is; `somcp_ping` marks that same
language as the default.

### Fixed

**A new image named like the current one is no longer taken for it.**
When the assistant gave `somcp_update_category`, `somcp_update_manufacturer` or
`somcp_update_supplier` a new cover or logo whose file happened to have the same
name as the current one (for example a photo published as `3.jpg` for category
3), the new image was silently dropped and the answer said nothing had changed.
Only the exact address the shop gave for its own image is now left untouched;
any other address is downloaded and stored as a new image.

**Products and shop pages read in the shop's default language.**
`somcp_get_product`, `somcp_search_products` and `somcp_list_meta_pages` now
read in the shop's default language when no language is given, rather than in
a language picked from the assistant's browser settings, and say in their
answer which language the texts are in. A language the shop does not have is
refused with the list of the ones it has, as the SEO audits now do too. A
product or a category created with texts in some languages only fills the
others from the default language of the shop being written to.

**Orders, customers, stock and return statuses read in the shop's default
language.** `somcp_search_orders` and `somcp_list_low_stock` now take a
`language`, and with none given read country, status and product names in the
shop's default language rather than in a language picked from the assistant's
browser settings - a product name searched in the wrong language found
nothing. `somcp_list_return_statuses` does the same, refuses a language the
shop does not have with the list of the ones it has, like the customer tools,
and says which language its names are in by its code rather than by a number
no tool lists; so do `somcp_get_order` and `somcp_list_customer_groups`. The
name in the answer of `somcp_delete_return_status` and `somcp_delete_product`
is now in the default language of the shop being worked on, with its code, and
on a multistore the product tools and the free shipping voucher of
`somcp_create_order` use that shop's default language rather than the global
one.

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
