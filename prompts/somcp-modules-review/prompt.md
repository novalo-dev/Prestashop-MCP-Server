# Reviewing modules

Start from what the merchant asked: one module, the modules in a given state,
or every module. Read before acting, and never answer from memory or from an
earlier answer: a module can be installed, upgraded or disabled at any time.

## Tools

- `somcp_ping`
- `somcp_list_modules`
- `somcp_get_module`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you read modules in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation, a module can be
enabled on one shop and disabled on another: what you read is the state on the
shop that answered.

## Workflow

1. **List the modules** with `somcp_list_modules`. When the merchant names a
   module, pass part of its name as `query`; when they ask about the enabled,
   disabled, installed or uninstalled ones, pass `status`. Read the next page
   with `offset` while `total_count` says there is more, before saying that
   is all.
2. **Pick the module the merchant means** by its `name`, the technical name
   every module tool takes. When several modules match, show them with their
   display name and author and ask which one.
3. **Read the module** with `somcp_get_module` and its `name` when the
   merchant wants one module in detail or asks where it runs: it adds `hooks`,
   the hooks the module is attached to on this shop.
4. **Answer from what you read.** Give the display name, the version, the
   author and the state, and say what the module does from its description.

## What the fields mean

- `name` is the technical name, the module's folder; `display_name` is the
  title of its card in **Modules > Module Manager**.
- `version` is the version of the files on disk, `version_installed` the one
  recorded when the module was installed or last upgraded. `needs_upgrade:
  true` means the files are newer: the module's **Upgrade** applies them.
- `installed: false` is a module on disk that was never installed, or was
  uninstalled: it does nothing on the shop.
- `active` is whether the module is enabled on this shop, `active_on_mobile`
  whether it also runs on mobile devices.
- `hooks` lists where the module runs on this shop, as **Design > Positions**
  shows it: each hook's `name` and the module's `position` in it. It is empty
  when the module is not installed or disabled on this shop.
- The marketplace's **Upgrade available** and categories are not returned:
  they come from the PrestaShop marketplace, not from the shop.

## Before acting on a module

Installing, enabling or disabling a module is not possible from here yet. When
the merchant asks for it, name the module and what it does, and point to
**Modules > Module Manager**. A payment or carrier module disabled stops
orders or deliveries: say so before they do it.
