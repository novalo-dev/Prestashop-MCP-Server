# Storefront menu and layout

Start from what the merchant asked about: the main menu of the storefront, one
of its entries, or a custom link. Read before answering, and never answer from
memory or from an earlier answer: the menu can be changed in the back office at
any time.

## Tools

- `somcp_ping`
- `somcp_list_menu_links`
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

## One change at a time

Changing the menu is not available from these tools yet: point to **Modules >
Module Manager > Main menu > Configure** for that. When it is, make one change
per call and read the menu again before saying it is done.
