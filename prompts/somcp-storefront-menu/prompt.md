# Storefront menu and layout

Start from what the merchant asked about: the main menu of the storefront, one
of its entries, or a custom link. Read before answering, and never answer from
memory or from an earlier answer: the menu can be changed in the back office at
any time.

## Tools

- `somcp_ping`
- `somcp_list_menu_links`
- `somcp_add_menu_link`
- `somcp_update_menu_link`
- `somcp_get_theme`
- `somcp_update_theme_setting`
- `somcp_list_categories`
- `somcp_list_cms_pages`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you read the menu in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation each shop can
have its own menu: what you read is the menu of the shop that answered.

## Workflow

1. **Read the menu first** with `somcp_list_menu_links`. Pass `language` when
   the merchant works in another language than the shop default: the labels
   are translated.
2. **Check that the menu is there.** `module_active: false` means the **Main
   menu** module (`ps_mainmenu`) is not installed or not enabled on this shop:
   the storefront has no main menu from it, and the lists are empty. Say so
   rather than describing an empty menu.
3. **Answer from what you read.** `entries` is the menu in the order the
   storefront shows it, `position` 1 first, as the **Selected items** of the
   module's configuration page list it.

## What the fields mean

- `type` says what an entry points to, and which list tool gives its
  identifier: `id_category` for a `category` comes from
  `somcp_list_categories`, `id_cms` for a `cms_page` and `id_cms_category` for
  a `cms_category` from `somcp_list_cms_pages`. Take identifiers from those
  tools, never guess one: they are specific to each shop.
- On the storefront a `category` entry shows the category with its whole
  active subtree, a `cms_category` its sub-categories and pages, and
  `all_brands` / `all_suppliers` every brand / supplier.
- `item` is the code the module stores, for example `CAT3`.
- A `link` entry is a custom link: `url` is its address and `new_window`
  whether it opens in a new window. `custom_links` lists every custom link of
  the shop, the **Link list** of the configuration page, with `in_menu` saying
  whether it is in the menu.
- `skipped` lists the stored codes whose object no longer exists - a deleted
  category or page, for example. The storefront and the configuration page
  hide them: tell the merchant they point to nothing.

## Adding an entry

`somcp_add_menu_link` adds one entry per call, as **Add** then **Save** in the
**Menu Top Link** panel.

- Give `type` and the identifier its list tool gives: `id_category` from
  `somcp_list_categories`, `id_cms` or `id_cms_category` from
  `somcp_list_cms_pages`, `id_link` from `custom_links`. Never guess one.
- For a new custom link, leave out `id_link` and give `label` and `url`, one
  text per language keyed by ISO code: the link is created and put in the menu
  in the same call. A language left out shows the default language's text.
- `position` 1 puts it first; left out, it goes at the end. An entry already in
  the menu is refused: say so rather than calling again.
- Confirm with the merchant what will be added and where before calling. If
  the answer is lost, do not call again: read `somcp_list_menu_links` to see
  whether the entry is there.

## Changing a custom link

`somcp_update_menu_link` changes one custom link per call, as **Edit** then
**Update** in the **Link list**: its `label`, its `url` and `new_window`.

- Take `id_link` from `custom_links` of `somcp_list_menu_links`. Never guess
  one.
- Only what you give changes. To change a text in one language, pass
  `languages` with that language alone: without it every language is filled,
  and the text is needed in each. The other languages keep their texts.
- `changed: false` means the link already held those values: nothing was
  written. Say so rather than calling again.
- Confirm the new texts with the merchant before calling. If the answer is
  lost, read `somcp_list_menu_links` and compare before calling again.

It changes the link only, never its place in the menu. Moving or removing an
entry, or deleting a custom link, is not available from these tools yet: point
to **Modules > Module Manager > Main menu > Configure** for that.

## Reading the theme and the page layouts

`somcp_get_theme` reads **Design > Theme & Logo** and **Choose layouts**: the
theme in use, the other installed themes, the four logos and the layout of
each page.

- `pages` lists the rows of **Choose layouts**: `page` is the same page name
  `somcp_list_meta_pages` gives, `layout` the key of the layout used, and
  `uses_default: true` when the page follows `default_layout` rather than a
  setting of its own. Name a layout to the merchant by its `name` from
  `available_layouts`, not by its key.
- `logos.mail` or `logos.invoice` is `null`, with `mail_uses_header` or
  `invoice_uses_header` true, when the shop prints the header logo in its
  place: say so rather than calling the logo missing.
- `other_themes` are installed but not used by this shop. Switching theme or
  uploading a logo is not available from these tools: point to **Design >
  Theme & Logo** for that.

## Changing the layout of a page

`somcp_update_theme_setting` sets the layout of one page per call, as one row
of **Choose layouts** then **Save**. Every other page keeps its setting.

- Take `page` from `pages[].page` and `layout` from
  `available_layouts[].layout` of `somcp_get_theme`. Never guess one: another
  theme can offer other layouts.
- Confirm the page and the new layout with the merchant before calling, naming
  the layout by its `name`.
- `changed: false` means the page already used that layout: nothing was
  written. Say so rather than calling again.
- If the answer is lost, do not call again: read `somcp_get_theme` to see
  which layout the page has.
