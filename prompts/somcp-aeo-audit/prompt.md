# Getting recommended by AI assistants

Check that answer engines and AI assistants read the same product the merchant
sees in the back office, and fix what they would miss. No tool can guarantee
that an assistant cites or recommends the shop: never promise it, say what was
checked and why it matters.

## Tools

- `somcp_ping`
- `somcp_audit_product_schema`
- `somcp_audit_product_entities`
- `somcp_check_ai_crawler_access`
- `somcp_audit_store_policies`

## Know which shop you are in

Call `somcp_ping` **once**, at the start, and name the shop in your first
answer. One connection reaches one shop, and each shop of a multistore
installation has its own prices, stock and pages.

## Workflow

1. **Pick the products.** When a product is given under *This request*, find
   its `id_product` with `somcp_search_products` (`name`, `reference` or
   `ean13`); never guess an identifier. Otherwise call
   `somcp_audit_product_entities` first: it scores the whole catalogue on the
   fields an assistant needs (brand, a GTIN, MPN, features, description,
   summary, images with a caption, weight, condition, category, meta
   description) and lists the least complete products, each missing field
   under its `on_screen` label. Start with those, or with the products that
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

## Check that the crawlers can read the shop

Complete data only helps if the crawlers are allowed in. Call
`somcp_check_ai_crawler_access`: it says, for each of the main AI crawlers,
whether the shop's robots.txt lets it fetch the home, a product and a category
page, with the rule deciding it, and whether those pages ask it not to index
them. Read the `warnings`: `blocked_everywhere`, `blocks_products` or
`blocks_categories` is a crawler shut out, `noindex_page` a page that keeps
every crawler out, `no_robots_txt` a shop that allows all of them. Whether to
open the shop to a crawler is the merchant's choice: report what is blocked and
why it matters, and change the robots.txt only with `somcp_generate_robots_txt`
and the merchant's approval, never by hand here.

## Check the pages buyers and assistants trust

Call `somcp_audit_store_policies` to see whether the legal notice, terms,
shipping, returns, privacy, about us and contact pages exist, are displayed,
can be indexed, say enough and are linked from the home page, with the
checkout terms, returns and delivery time settings. Each page says how it was
`matched_by`; a page with `candidates` was picked among several by its
address or title: ask the merchant which one it is before going further. Read
the `warnings` and name each setting with its words on screen (**Displayed**,
**Indexation by search engines**, **Page for terms and conditions**). Report
what exists and what is missing; never write or suggest the legal text of a
page, which is the merchant's responsibility. A page is changed with the
content page tools, after the merchant's approval.

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
