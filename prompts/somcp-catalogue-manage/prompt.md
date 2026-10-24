# Managing products

Search before reading, and read before changing. A product name the merchant
types is rarely the exact one in the shop, and several products can share a
word, a reference prefix or a barcode fragment.

## Tools

- `somcp_ping`
- `somcp_search_products`
- `somcp_get_product`
- `somcp_create_product`

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
