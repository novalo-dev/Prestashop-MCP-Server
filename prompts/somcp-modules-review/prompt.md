# Reviewing modules

Start from what the merchant asked: one module, the modules in a given state,
or every module. Read before acting, and never answer from memory or from an
earlier answer: a module can be installed, upgraded or disabled at any time.

## Tools

- `somcp_ping`
- `somcp_list_modules`
- `somcp_get_module`
- `somcp_install_module`
- `somcp_upload_module`
- `somcp_set_module_active`

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

## Installing a module

`somcp_install_module` installs a module whose folder is already on the shop,
as the **Install** button of its card does. Only when the merchant asks for it:

1. **Find the module** with `somcp_list_modules` and `status: "uninstalled"`,
   and confirm its display name and author with the merchant.
2. **Say what installing does** before calling: the module's own code runs and
   it attaches itself where it needs to. Then call `somcp_install_module` with
   its `name`, once.
3. **Answer from the result**: it is the module as `somcp_get_module` reads it
   after the install. If the answer is lost, read `somcp_get_module` instead of
   calling again. On a refusal, give the module's own messages as they are.

A module already installed is refused and left unchanged: upgrading it is done
in **Modules > Module Manager**. A module that is not on the shop is installed
from its archive, below.

## Uploading a module

`somcp_upload_module` puts a module's `.zip` on the shop and installs it, as
the **Upload a module** button of **Modules > Module Manager** does. Only when
the merchant asks for it, with an archive they have:

1. **Get the archive as an upload_ref**: attached to the chat, or deposited on
   the page the shop opens for it. Never from a link.
2. **Say what it does** before calling: the archive's code comes from outside
   the shop and runs on it with full rights, so it should come from a source
   the merchant trusts. Then call `somcp_upload_module` with the `upload_ref`,
   once.
3. **Answer from the result**: the module as `somcp_get_module` reads it, plus
   the archive's `file_name`. The deposit is used up: if the answer is lost,
   read `somcp_get_module` instead of calling again. A module already on the
   shop is refused and nothing is replaced; on a failed install the folder is
   removed again and the module's own messages are given as they are.

## Enabling or disabling a module

`somcp_set_module_active` enables or disables an installed module on this
shop, as the **Enable** and **Disable** buttons of its card do. Only when the
merchant asks for it:

1. **Read the module** with `somcp_get_module` and confirm with the merchant
   which one it is and what it does. A payment or carrier module disabled
   stops orders or deliveries: say so before calling.
2. **Call `somcp_set_module_active` once** with its `name` and `active`. On
   PrestaShop 8, `active_on_mobile` switches it on or off for mobile; enabling
   a module again turns mobile back on, so pass `active_on_mobile: false` with
   `active: true` when it should stay off there.
3. **Answer from the result**: `changed` lists what was written, from and to.
   If the answer is lost, read `somcp_get_module` instead of calling again. On
   a refusal, give the module's own messages as they are.

A request that would change nothing is refused and nothing is touched. This
module cannot disable itself: point to **Modules > Module Manager** for that.
