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
- `somcp_list_suppliers`
- `somcp_list_attributes`
- `somcp_list_features`
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
read it with `somcp_get_manufacturer`, by the identifier the list gave.

A supplier too, from `somcp_list_suppliers`: never write an `id_supplier` the
list did not give, and when the supplier the merchant names is not in it, say
so.

Attribute values - a size, a colour - come from `somcp_list_attributes`: a
combination is a set of `id_attribute`, never of names. Two groups can hold a
value of the same name, so check the group as well as the value, and ask the
merchant when the name they gave sits in more than one.

Features - a composition, a material - come from `somcp_list_features`: take
the `id_feature` and the `id_feature_value` from there. Only predefined values
are listed; when the value the merchant wants is not among them, it is a custom
value written on that product alone, so say so before writing it.

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
