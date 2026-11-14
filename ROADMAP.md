# Roadmap

The foundations are in place: both eras of the MCP protocol, the OAuth 2.1
server, back office permissions, multistore, file deposits, the contract that
lets other modules publish tools and prompts, the assistant built into the back
office, the relay to an assistant running on the merchant's own computer, and
compact mode for assistants that cap the number of tools. What is listed here
is the work that would make the module useful to many more shops.

Each item can be taken on its own. Comment on the matching issue, or open one,
before starting something large, so two people do not build the same thing.

## The shop from A to Z

The module's tools are listed in the [README](README.md#tools) and
described in [docs/TOOLS.md](docs/TOOLS.md): orders, catalogue, stock,
customers, discounts, statistics, content, modules, appearance, system, SEO,
AEO and sales. The last ones still to write are tracked as issues labelled
[`new tool`](https://github.com/novalo-dev/Prestashop-MCP-Server/issues?q=is%3Aissue+is%3Aopen+label%3A%22new+tool%22).

What comes next is letting an assistant set up a whole shop, in the order a
merchant does it:

- **Base settings**: shop details, countries, currencies, taxes.
- **Shipping and payment preferences**: carriers and their zones, and the
  preferences of **Payment > Preferences**. The payment modules themselves,
  their accounts and keys, stay with the merchant.
- **Appearance**: the `custom.css` of a child theme of Classic or Hummingbird,
  never the parent theme or its templates, and the native modules of the
  storefront those themes show - the slider, the home texts, the footer links,
  the newsletter block and the like.
- **E-mails and preferences**: the shop's e-mails and the order, customer and
  product preferences.

The simplest tools, mostly reads, are labelled
[`good first issue`](https://github.com/novalo-dev/Prestashop-MCP-Server/issues?q=is%3Aissue+is%3Aopen+label%3A%22good+first+issue%22).
Each design is approved by the maintainer before it opens: an issue labelled
`draft` is not open yet. Once it is, comment on the issue to take it, so
two people do not write the same tool.

Every tool is described in `docs/TOOLS.md` before it is written, follows the
conventions at the top of that file, and is tied to the back office page where
a merchant would do the same thing by hand. Reads first; a write tool changes
one object per call.

## Tests

Every push runs the CI: the PrestaShop coding standard, PHPStan, Semgrep,
Psalm and Gitleaks, the catalogue, tool and multistore checks, an end-to-end
run of the shop tests (`dev/shop-tests/`) on every published PrestaShop 8 and
9 with each PHP version it supports - every tool called from its scenario, its
permissions checked, the module's public addresses attacked, a ZAP scan, and
an uninstall that must leave nothing behind - and a real browser
(`dev/ui-tests/`) through the module's back office pages and the page that
connects an assistant. Still wanted is a formal PHPUnit unit suite over the
same ground: OAuth flows, permission checks, the multistore boundary, and the
contract with other modules first.

## Clients

The clients the module works with are listed once, under
[Compatible assistants](README.md#compatible-assistants) in the README. Wanted:

- For a client of that list: connect, run the tools, report what works and
  document its exact configuration for the
  [wiki](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki/Connect-an-assistant).
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
