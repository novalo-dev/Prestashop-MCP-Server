# Getting recommended by AI assistants

Check that answer engines and AI assistants read the same product the merchant
sees in the back office, and fix what they would miss. No tool can guarantee
that an assistant cites or recommends the shop: never promise it, say what was
checked and why it matters.

## Tools

- `somcp_ping`
- `somcp_audit_product_schema`

## Know which shop you are in

Call `somcp_ping` **once**, at the start, and name the shop in your first
answer. One connection reaches one shop, and each shop of a multistore
installation has its own prices, stock and pages.

## Workflow

1. **Pick the products.** When a product is given under *This request*, find
   its `id_product` with `somcp_search_products` (`name`, `reference` or
   `ean13`); never guess an identifier. Otherwise start with the products that
   sell, and ask the merchant which ones matter most when you cannot tell.
2. **Compare the page.** Call `somcp_audit_product_schema` for each product,
   with `id_product_attribute` for one combination's page and `language` for
   another language than the shop default. It reads the public page as a
   visitor and sets the structured data it prints against the product page of
   the back office.
3. **Read the answer.** `mismatches` is what the page tells assistants that
   the shop does not hold: an old price, another brand, a wrong barcode, a
   product shown in stock that is not. `missing` is what the shop holds and
   the page does not print. Name each field with its `on_screen` label, the
   words of the merchant's back office. When `note` says the page prints no
   Product structured data at all, that is up to the theme or a module: say
   so, and do not propose to write it.
4. **Fix the data itself.** Brand, GTIN, MPN, reference, condition and
   description are changed with `somcp_update_product`, in the prompt
   `somcp-catalogue-manage`, with the merchant's approval: never add text for
   robots. A price or a stock that differs on the page usually comes from a
   cache or a module; say so rather than changing the product.
5. **Check again.** After a change, call `somcp_audit_product_schema` on the
   same product: a value in the database is not proof of what the theme
   prints.

## Never invent

Never invent a fact, a review, a rating or a figure about a product: when the
data is missing, ask the merchant. `rating` is only what the page prints, and
an empty one is not a problem to fix with made-up reviews.

## Do not use when

- The merchant wants the meta titles and descriptions of the products: that is
  the SEO prompt, `somcp-seo-audit`.
- The merchant wants to read or change one product: that is the catalogue
  prompt, `somcp-catalogue-manage`.

## Good triggers

- "Est-ce que ChatGPT voit le bon prix de mes produits ?"
- "Check the structured data of this product"
- "Mes fiches produits sont-elles lisibles par les IA ?"
- "Why does Google show another price for this product?"
- "Vérifie le JSON-LD de ma fiche produit"
