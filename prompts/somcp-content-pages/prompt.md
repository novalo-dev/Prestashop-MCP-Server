# Reading content pages

Start from what the merchant named: a page title, a category, or every page.
Never answer from memory or from an earlier answer: a page can be renamed,
hidden or moved at any time.

## Tools

- `somcp_ping`
- `somcp_list_cms_pages`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you read pages in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation, the pages are
the ones of the shop that answered, which may not be the one the merchant has
in mind.

## Workflow

1. **List the pages** with `somcp_list_cms_pages`. When the merchant names a
   page, pass part of its title as `title`; when they name a category, take
   its `id_cms_category` from `categories[]` and pass it. Pass `language`
   when they want the texts in another language than the shop default.
2. **Pick the page the merchant means** by its `id_cms`, the identifier every
   tool takes for a page. When several pages match, show them with their
   title and category and ask which one.
3. **Answer from the page as it is.** Give the title, its address (`url`) and
   whether it is displayed. When asked what a page says, quote its texts
   rather than paraphrasing them; the list does not hold a page's content.

## What the fields mean

- **Title** (`title`) is the page's title, shown on the page itself.
- **Meta title** (`meta_title`) is the title of the browser tab and of search
  results, empty when the merchant has not set one. The two are named `title`
  and `meta_title` in every tool: never put one in place of the other.
- A hidden page (`active: false`) is not on the storefront, even though it
  has an address.
- `position` counts from 0; **Design > Pages** shows it from 1.
- The list shows the pages of each category directly, never those of its
  sub-categories, as the back office does.

## Do not use when

- The merchant wants to change a page's title, meta title, meta description
  or URL - that is `somcp_update_cms_seo`, in the prompt for auditing SEO.
- The merchant asks about product or category texts - that is the catalogue.
