# Reading content pages

Start from what the merchant named: a page title, a category, or every page.
Never answer from memory or from an earlier answer: a page can be renamed,
hidden or moved at any time.

## Tools

- `somcp_ping`
- `somcp_list_cms_pages`
- `somcp_get_cms_page`
- `somcp_get_cms_category`
- `somcp_create_cms_page`
- `somcp_update_cms_page`
- `somcp_delete_cms_page`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you read pages in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation, the pages are
the ones of the shop that answered, which may not be the one the merchant has
in mind.

## Workflow

1. **List the pages** with `somcp_list_cms_pages`. When the merchant names a
   page, pass part of its title as `title`, of its URL as `link_rewrite` or
   of its Meta title as `meta_title`; when they name a category, take
   its `id_cms_category` from `categories[]` and pass it. Pass `language`
   when they want the texts in another language than the shop default.
2. **Pick the page the merchant means** by its `id_cms`, the identifier every
   tool takes for a page. When several pages match, show them with their
   title and category and ask which one.
3. **Read the page** with `somcp_get_cms_page` and its `id_cms` when the
   merchant asks what it says, or about its meta description, keywords or
   indexation: the list does not hold a page's content. Pass the same
   `language` as for the list.
   For a page category itself - its name, description or SEO texts - read
   it with `somcp_get_cms_category` and its `id_cms_category`.
4. **Answer from the page as it is.** Give the title, its address (`url`) and
   whether it is displayed. When asked what a page says, quote its content
   rather than paraphrasing it.

## Creating a page

`somcp_create_cms_page` adds one page, as **Add new page** then **Save** do
in **Design > Pages**. Only the `title` is required.

- Show the merchant the title and the content and wait for their yes before
  calling. A second call creates a second page: when an answer is lost, look
  for the page with `somcp_list_cms_pages` before calling again.
- The page is created hidden (`active: false`) unless the merchant asks for
  it to be shown; switch it on with `somcp_update_cms_page` once its texts
  are checked.
- Left out, the **Friendly URL** is made from the title: pass on the
  `warning` that gives it.

## Changing a page

`somcp_update_cms_page` changes the **Title**, the **Page content**, the
**Page category** and **Displayed** of one page, in the languages filled,
only what is given.

- Read the page with `somcp_get_cms_page` first, then show the merchant the
  new title or text and wait for their yes before calling: the text is saved
  as shown.
- A page category comes from `categories[]` of `somcp_list_cms_pages`.
- Hiding a page (`active: false`) breaks its links in the footer and the
  menu: say so before.
- `changed: false` means the page already held every value: nothing was
  written.

## Deleting a page

`somcp_delete_cms_page` deletes one page, by its `id_cms`, and cannot be
undone.

- Name the page and its Title to the merchant and wait for their yes before
  calling. When they only want it off the storefront, hide it with
  `somcp_update_cms_page` (`active: false`) instead.
- The page customers accept at checkout is refused: another one is chosen
  first with `somcp_update_order_settings` (`terms_id_cms`).
- Pass on the `warning`: a footer link block that still lists the page is
  changed in **Design > Link List**.

## What the fields mean

- **Title** (`title`) is the page's title, shown on the page itself.
- **Meta title** (`meta_title`) is the title of the browser tab and of search
  results, empty when the merchant has not set one. The two are named `title`
  and `meta_title` in every tool: never put one in place of the other.
- `seo_title` is the title search engines actually read: the meta title when
  filled, the title otherwise.
- `content` is the page's HTML as stored; `meta_keywords` is `null` on
  PrestaShop 9, which has no such field.
- `indexation: false` keeps the page out of search engines, even when it is
  displayed.
- A hidden page (`active: false`) is not on the storefront, even though it
  has an address.
- `position` counts from 0; **Design > Pages** shows it from 1.
- The list shows the pages of each category directly, never those of its
  sub-categories, as the back office does.

## Do not use when

- The merchant wants to change a page's meta title, meta description, URL or
  indexation - that is `somcp_update_cms_seo`, in the prompt for auditing SEO.
- The merchant asks about product or category texts - that is the catalogue.
