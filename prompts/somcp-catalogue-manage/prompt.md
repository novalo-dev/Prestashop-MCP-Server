# Managing products

Search before reading, and read before changing. A product name the merchant
types is rarely the exact one in the shop, and several products can share a
word, a reference prefix or a barcode fragment.

## Tools

- `somcp_ping`
- `somcp_search_products`
- `somcp_get_product`
- `somcp_list_categories`
- `somcp_get_category`
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
- `somcp_create_product`
- `somcp_update_product`
- `somcp_set_product_active`
- `somcp_delete_product`
- `somcp_create_category`
- `somcp_update_category`
- `somcp_set_category_active`
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
- `somcp_create_attribute_value`
- `somcp_update_attribute_value`
- `somcp_create_feature`
- `somcp_update_feature`
- `somcp_create_feature_value`
- `somcp_update_feature_value`

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
   hundred is not "twenty products".
4. When the merchant asks for several matches, show them the list and let them
   pick. Never choose one for them.
5. To answer about one product - its description, carriers, images, suppliers,
   a combination - read it with `somcp_get_product`, by the identifier the
   search returned. When the search named a matching combination, pass its
   `id_product_attribute` too.

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
office first. Its `download_url` opens without being signed in: give it to the
merchant, never put it anywhere public.

Tax rules come from `somcp_list_tax_rules`: take the `id_tax_rules_group` from
there, 0 for no tax. Match the merchant's "20 %" on the `rate`, not on the
name, and say that the rate is the one of the country given: a customer
delivered elsewhere may pay another one. When `taxes_enabled` is false the
shop charges no tax at all, whatever the rule.

## Prices

Every price is either **tax included** or **tax excluded**: the field name says
which, and so must you. Always give the currency with an amount.

The prices returned are the base price of the product, before specific prices,
discounts and combination impacts. A customer may pay something else: say so
when the merchant asks what a customer pays. `somcp_get_product` lists the
specific prices apart, with who they apply to and when: never present one of
them as the product's price.

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
   yourself, in every language being filled, and show them first.
3. An image is given by a public `https` address, which the shop downloads.

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
   thumbnails replace the current ones; the cover and the thumbnails are only
   removed through `remove_cover_image` and `remove_menu_thumbnails`.
3. To change a text in one language, pass `languages` with that language alone.
   A new `link_rewrite` on a displayed category breaks the old address of its
   page: tell the merchant before.

The root category and Home are changed in the back office, not here. When it
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

## Creating a brand

`somcp_create_manufacturer` creates one brand, enabled unless the merchant says
otherwise. Before calling it:

1. Read `somcp_list_manufacturers`: the brand may already exist, and the shop
   accepts two brands of the same name.
2. Ask the merchant for the name, whether the brand is enabled and the address
   of its logo. The name is one text, the same in every language. Write the
   descriptions and the SEO texts yourself, in every language being filled, and
   show them first.
3. The logo is given by a public `https` address, which the shop downloads.

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
   address of its logo. Never invent a phone number or an address. Write the
   description and the SEO texts yourself, in every language being filled, and
   show them first.
3. The logo is given by a public `https` address, which the shop downloads.

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

When it succeeds, show the merchant each change with its old and new value,
and the `note` when there is one. `changed: false` means the group already held
those values. On a lost answer, read the group again with
`somcp_get_attribute_group` before calling twice.

## Adding an attribute value

`somcp_create_attribute_value` adds one value - a size, a shade - to an
attribute group. Before calling it:

1. Take the group from `somcp_list_attributes`, never guessed, and look for
   the value there: a name the group already holds is refused.
2. Ask the merchant for the name, in every language being filled. For a group
   of type `color` only, ask for the colour as a hex code and, when they want
   a pattern rather than a plain colour, the public https address of the
   texture image; on any other group, leave both out. When the shop has the
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
   combination uses it. A texture given is downloaded again and replaces the
   current one.

When it succeeds, show the merchant each change with its old and new value,
and the `note` when there is one. If `texture` says `refused`, the value keeps
its current texture: tell the merchant why. `changed: false` means the value
already held those values. On a lost answer, read the value again with
`somcp_get_attribute_value` before calling twice.

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

When it succeeds, show the merchant each change with its old and new value.
`changed: false` means the feature already held those values. On a lost
answer, read the feature again with `somcp_get_feature` before calling twice.

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
