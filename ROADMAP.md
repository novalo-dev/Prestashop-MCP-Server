# Roadmap

The foundations are in place: both eras of the MCP protocol, the OAuth 2.1
server, back office permissions, multistore, file deposits, and the contract
that lets other modules publish tools and prompts. What is listed here is the
work that would make the module useful to many more shops.

Each item can be taken on its own. Comment on the matching issue, or open one,
before starting something large, so two people do not build the same thing.

## More of PrestaShop as tools

Today the module covers orders and order statuses. Sixty-seven more tools are
specified in [docs/TOOLS.md](docs/TOOLS.md), each with its own issue labelled
[`new tool`](https://github.com/novalo-dev/Prestashop-MCP-Server/issues?q=is%3Aissue+is%3Aopen+label%3A%22new+tool%22):

- **Catalogue**: search, read, create, edit, switch on and off, delete
  products; list, read, create, edit, switch on and off categories, brands
  and suppliers; manage brand addresses; list, read, create and edit
  attribute groups, features and their values; list documents, so a product
  is never built on a guessed identifier.
- **Stock**: read quantities, list what runs low, set a quantity.
- **Customers**: search, read, create, edit customers.
- **Orders and configuration**: tracking numbers, carriers, tax rules,
  customer groups.
- **Statistics**: sales over a period, best sellers.
- **Content**: CMS pages.
- **Modules**: list, read, install, enable and disable.
- **Appearance**: main menu links, theme page layouts.
- **System**: cache status, clearing the cache.

The simplest ones, mostly reads, are also labelled
[`good first issue`](https://github.com/novalo-dev/Prestashop-MCP-Server/issues?q=is%3Aissue+is%3Aopen+label%3A%22good+first+issue%22).
Each design is approved by the maintainer before it opens: an issue labelled
`draft` is not open yet. Once it is, comment on the issue to take it, so
two people do not write the same tool.

Every tool is described in `docs/TOOLS.md` before it is written, follows the
conventions at the top of that file, and is tied to the back office page where
a merchant would do the same thing by hand. Reads first; a write tool changes
one object per call.

## Tests

Every push runs the CI: the PrestaShop coding standard, PHPStan, Semgrep and
Psalm, the catalogue, tool and multistore checks, and an end-to-end run of the
shop tests (`dev/shop-tests/`) that installs the module and exercises the tools,
OAuth and permissions across PrestaShop 8 and 9. Still wanted is a formal
PHPUnit unit suite over the same ground: OAuth flows, permission checks, the
multistore boundary, and the contract with other modules first.

## Clients

The clients the module works with are listed once, under
[Compatible clients](README.md#compatible-clients) in the README. Wanted:

- For a client of that list: connect, run the tools, report what works and
  document its exact configuration in the README.
- Any MCP client with OAuth support missing from the list.

A client that behaves differently from the specification is worth an issue
even if you do not have a fix: the module already works around one client that
declares it can open an address and then does not.

## Translations

The interface is in English, French, Spanish and German. More languages are
welcome: add a column to `dev/translations.py`, see
[CONTRIBUTING.md](CONTRIBUTING.md#translations).

## Smaller things

- A `docker compose` setup with PrestaShop 8 and 9 for development.
- Native app redirect URIs (private-use schemes, RFC 8252), refused for now.
- Shops installed in a subfolder: today the authorization server metadata
  cannot be served there.
