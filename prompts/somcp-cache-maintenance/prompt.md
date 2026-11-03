# Cache maintenance

Start from what the merchant asked about: a change that does not show on the
storefront, a slow shop, or how caching is set up. Read before answering, and
never answer from memory or from an earlier answer: these settings can be
changed in the back office at any time.

## Tools

- `somcp_ping`
- `somcp_get_cache_status`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you read the settings in a
conversation, and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation some of these
settings can differ from one shop to another: what you read is the shop that
answered. Debug mode and the caching system apply to the whole installation.

## Workflow

1. **Read the status first** with `somcp_get_cache_status`. It returns every
   card of **Advanced Parameters > Performance**: `smarty`, `debug`,
   `optional_features`, `ccc`, `media_servers` and `caching`.
2. **Start from `warnings`.** Each one has a `code` and a `why`: explain the
   `why` in the merchant's words, and say which card and setting of the
   Performance page it is about.
3. **Answer from what you read.** A setting returned as `null` does not exist
   on this version of PrestaShop: say so rather than guessing a value.

## What the fields mean

- `smarty.template_compilation` is **Template compilation**: `never`,
  `if_updated` or `force`. `force` recompiles every page: fine while editing
  the theme, slow in production.
- `debug.debug_mode` is the **Debug mode** written in the shop's defines file.
  `debug.debug_cookie: true` means it only applies to a browser that sends the
  debug cookie; its name and value are never given, they work as a password.
- `caching.caching_system` is the system stored, even when `use_cache` is
  false. `available_systems` lists the ones this server can run: a system
  missing from it needs its PHP extension installed by the host.
- `ccc` is **CCC (Combine, Compress and Cache)**: combining CSS and
  JavaScript makes the storefront load fewer files.

## Clearing the cache

Clearing the cache is not available from these tools yet: point to
**Advanced Parameters > Performance > Clear cache** for that. Clearing slows
the storefront while the cache rebuilds: say so, and clear it once when a
change does not show, not as a reflex. Changing these settings stays in the
back office.
