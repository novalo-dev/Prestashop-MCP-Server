# Auditing and improving SEO

Find what holds the shop back in search results, sort it by what it costs in
sales, and fix it only with the merchant's approval. Never present a guess about
search engines as a fact: say what the audit found and why it matters.

## Tools

- `somcp_ping`
- `somcp_audit_product_seo`
- `somcp_audit_category_seo`

## Know which shop you are in

Call `somcp_ping` **once**, at the start, and name the shop in your first
answer. One connection reaches one shop, and each shop of a multistore
installation has its own titles, descriptions and URLs.

## Workflow

1. **Audit first.** Call `somcp_audit_product_seo` without `checks` for the
   whole picture, and `somcp_audit_category_seo` for the category pages.
   Read `counts`: it covers the whole catalogue, while `products` or
   `categories` is one page. Read the next page with `offset` while
   `total_count` says there is more, before saying that is all.
2. **Sort by effect on sales.** The products that sell, or should, come first;
   a missing title on a best seller matters more than a long description on a
   product nobody looks at. Ask the merchant which products matter most when
   you cannot tell.
3. **Narrow down.** Pass `checks` to work through one problem at a time, and
   `language` to work in one language.
4. **Propose, do not write.** For each fix, show the merchant the new title or
   description, in each language of the shop, and wait for their approval. The
   audit never changes anything.

## Reading the checks

- `meta_title_too_long` (over 60 characters) and `meta_description_long` (over
  160) are cut in search results; `meta_description_short` (under 70) gives
  little to show.
- `meta_title_duplicate` and `link_rewrite_duplicate` mean another product of
  the shop has the same value in that language: two pages competing for one
  search.
- `description_short` gives the number of words of the description, tags
  removed: under 150, a page has little text to rank on. Never invent facts
  about a product to lengthen it: ask the merchant.
- `image_legend_missing` lists the images without an alternative text in that
  language.
- `no_image`, `no_brand` and `no_gtin` (no EAN-13, UPC nor ISBN) concern the
  whole product and repeat on each language.
- For categories, `description_missing` means the category page has no text
  to rank on, `empty_category` a displayed category with no active product to
  list, and `no_cover_image` a category without its cover. The root and Home
  categories are never audited: they have no page of their own.
- `inactive_without_redirect` is checked only with `active: false`: an inactive
  product whose page sends no redirection leaves a dead address. Changing a URL
  or a redirection of a live page makes the old address stop working: say so
  before proposing it.

## Do not use when

- The merchant wants to read or change one product or one category: that is
  the catalogue prompt, `somcp-catalogue-manage`.

## Good triggers

- "Audite le SEO de mes produits"
- "Which products have no meta description?"
- "Mes fiches produits sont-elles bien référencées ?"
- "Quelles catégories n'ont pas de description ?"
