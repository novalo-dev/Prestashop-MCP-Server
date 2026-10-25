# Contributing

Thanks for looking. somcp is built for PrestaShop developers by PrestaShop
developers, and the most useful contributions are the ones listed in
[ROADMAP.md](ROADMAP.md): more of PrestaShop as tools, tests, clients,
languages.

Before anything large, open an issue or comment on an existing one, so we agree
on the shape before you spend an evening on it. For a new tool, use the
**New tool** issue template.

By taking part you agree to the [code of conduct](CODE_OF_CONDUCT.md).

Before your first pull request is merged, you sign the
[Contributor License Agreement](CLA.md). You keep the copyright on what you
write; the agreement lets the maintainer distribute it under the project's
license, and under another license, commercial included, if the project needs
it later. The CLA Assistant bot posts the link on your pull request: you sign
once, in a few seconds, with your GitHub account.

---

## Set up

You need a PrestaShop 8 and, ideally, a PrestaShop 9, reachable over HTTPS: an
MCP client connects from the outside.

1. Run a shop with the official images, for example
   `prestashop/prestashop:8.0.0-8.1` and `prestashop/prestashop:9.0-8.1`, with
   a MySQL container next to each.
2. Give it a public HTTPS address with a tunnel (`cloudflared tunnel --url
   http://localhost:8080`, or `ngrok http 8080`), set that domain as the shop
   domain, and enable friendly URLs.
3. Clone this repository into `modules/somcp` (the folder name matters), run
   `composer install` in it for Neuron AI, which runs the built-in assistant,
   and install the module.
4. Press **Run the diagnostic** in the module settings: every line green means
   a client can connect.

Development tools live apart from the module, so they never touch its
`vendor/` folder:

```bash
composer install --working-dir=dev/tools
```

## Checks

CI runs all of these on every pull request. Run them before pushing:

```bash
# PrestaShop coding standard
dev/tools/vendor/bin/php-cs-fixer fix --dry-run --diff

# Static analysis, against a PrestaShop folder
_PS_ROOT_DIR_=/path/to/prestashop dev/tools/vendor/bin/phpstan analyse -c phpstan.dist.neon

# Every visible string in the four languages
php .github/scripts/check-translations.php .

# Every tool in the README, its entry, its scope, the CHANGELOG and a prompt
php .github/scripts/check-tools.php .

# Every table shop-scoped, no merchant setting stored globally
php .github/scripts/check-multishop.php .
```

CI also runs a **Security** job, which you can run too:

```bash
# Semgrep: its PHP rules and the module's own (pip install semgrep)
semgrep scan --metrics=off --config p/php --config dev/security/semgrep.yml \
  somcp.php classes controllers src tools views config prompts

# Psalm taint analysis, from every tool's arguments and every request read
# (psalm.phar from the Psalm releases on GitHub)
php dev/security/make-entry.php && (cd dev/security && php psalm.phar --taint-analysis)

# Gitleaks, over the whole history
gitleaks git .
```

A finding is fixed, not silenced: escape with `pSQL()` or cast, print with
`|escape`, and never commit a key. If one is truly a false positive, say why
in the pull request before adding any `nosemgrep` or `@psalm-suppress`.

The shop test also checks permissions: every tool is refused to an employee
whose profile has no right, to an account that does not exist and to a token
without its scope. Only `somcp_ping` may skip `SomcpTool::acts()`.

CI also installs every published PrestaShop 8 and 9, with each PHP version
that release accepts, runs PHPStan against that release on that PHP, and
calls every tool there through
`dev/shop-tests/run.php`, following its scenario in
`dev/shop-tests/scenarios/`. Each call says what it expects: `ok`, `error`
(the tool answers with `isError`), `input` (the tool asks the client first) or
`any` (a write whose outcome depends on the demo data), and optionally
`contains`, text the answer must hold. A notice raised from a file of the
module fails the call too. PrestaShop 8 also runs on PHP 8.2 and later, which
it does not support: those runs report without failing CI.

CI also lints every file on PHP 8.1 to 8.4 and compiles `config/services.yml`
with the Symfony versions of PrestaShop 8 (4.4) and 9 (6.4), with and without
what PrestaShop 9 adds. That last check exists because a service asking for a
PrestaShop 9 class once took the whole PrestaShop 8 back office down.

---

## Rules of the code

- **PHP 8.1, PrestaShop 8.0.0 to 9.x.** No PrestaShop 9 class in a type hint, a
  constructor or a `use` that runs on 8. Check `method_exists()` or
  `class_exists()` where the two differ, and say why in a comment.
- **Comments in English, saying why.** The reader can see what the line does.
  A comment that became wrong is fixed in the same change.
- **Every PHP file of the module** starts with the license header and refuses to run outside
  PrestaShop (`if (!defined('_PS_VERSION_')) { exit; }`): the Addons validator
  checks both.
- **Reuse before writing.** If the logic exists somewhere in the module, call
  it. A second version of the same rule is how two parts of the module end up
  disagreeing.
- **Nothing secret in the log**, in an error message or in an exception: no
  token, no code, no client secret, not even truncated.
- **The shop comes from the token**, never from an argument. The employee's
  rights are checked on every call.
- **Everything belongs to a shop.** Every table the module creates carries an
  `id_shop`, unless it is the child of one that does and is reached only through
  its parent's id (`somcp_upload_file`, `somcp_assistant_message`,
  `somcp_assistant_run`). Every read and write is scoped to the shop in context.
  A merchant setting is stored per shop with `Configuration::updateValue(...,
  $idShop)` and read with `Configuration::get($key, null, null, $idShop)` -
  never `getGlobalValue` / `updateGlobalValue`, which are for the module's own
  state and minted secrets, the same on every shop. `check-multishop.php`
  enforces the table rule and guards the assistant's settings; it runs in CI.
- **Files enter the shop through two doors, and no third.** No tool takes file
  bytes in an argument - no base64, no data URL. A file comes in either as a
  **deposit**, read through `upload_ref` (`SomcpUpload`: bytes checked against
  the extension, tied to one employee, single use), or as a **public https
  address the shop downloads itself** through `SomcpHttp::fetch()` with its
  address guard on. It works the same for an assistant connected over MCP and
  for the built-in assistant, where a file the employee attached is already a
  deposit. Going the other way, a tool hands a file out as a link of its own,
  signed and expiring, never as bytes. See
  [Files: the two doors](docs/EXTENDING.md#files-the-two-doors).
- **Data goes through the PrestaShop core**, never the Webservice REST API. The
  module runs inside the shop, so a tool reads and writes with the core classes
  the back office uses (`ObjectModel`, `OrderHistory`, the services) - hooks
  fire and validation runs exactly as on screen. The Webservice is for callers
  outside the shop and would skip part of that.
- **The built-in assistant is held to every rule here.** Its code
  (`SomcpAssistant`, `SomcpAssistantChat`, `AdminSomcpAssistantController`) uses
  the same tool contract, the same shop-from-token and employee-rights checks,
  the same four-language strings and the same core classes as everything else -
  no second data path, no Webservice, no hardcoded text. A capability added for
  assistants connected over MCP is added for the built-in one in the same
  change, and the reverse. The chat makes one model call per HTTP request; the
  step loop lives in the browser, not in a long-running PHP process.

## Writing a tool, from A to Z

Every tool to write has an entry in [docs/TOOLS.md](docs/TOOLS.md) and an
issue labelled `new tool`, which repeats these steps with that tool's own
names. The entry is the specification: follow it, and if it turns out wrong,
say so in the issue before writing something else.

Only entries marked **to write** can be taken. An entry marked **draft**, whose
issue carries the `draft` label, waits for the maintainer to approve its
design: do not start it.

A tool needs nothing more to reach the built-in assistant: the chat offers
every tool of the registry the employee may use, and asks for approval before
any tool whose `acts()` is not a `view`.

1. **Take the issue.** Comment on it so nobody else starts the same tool. Read
   the [shared conventions](docs/TOOLS.md#shared-conventions) and the entry.
2. **Branch.** Fork the repository and create a branch named after the tool,
   for example `tool/somcp_get_stock`.
3. **Write the tool**, one file in `tools/`: `somcp_get_stock` is
   `tools/SomcpToolGetStock.php`, a class of that name with a static
   `define()` returning a `SomcpTool`, and a `handle()` doing the work. Copy
   the shape of `tools/SomcpToolListOrderStatuses.php`. The permission is the
   one in the entry, through `SomcpTool::acts()`. Reads first; a write changes
   one object per call, refuses a call that would change nothing, says in its
   description that it is not to be retried and which tool to read instead,
   and goes through PrestaShop's own objects so every hook fires.
4. **Add the scope** if the entry names one `SomcpScope` does not declare yet
   (the first tool of a section does): a constant, its place in `core()`, and
   its label in `describe()`, which the merchant reads on the consent screen.
5. **Translate** every text a merchant sees - the tool's title, the scope
   label, any message - in `dev/translations.py`, in the four languages, then
   run `python3 dev/translations.py`. The tool's description and its schema stay
   in English: the model reads them, not the merchant.
6. **Document the tool** in the same pull request:
   - its entry in `docs/TOOLS.md` says **written**, and is corrected wherever
     the code had to differ;
   - a row in the **Tools** table of `README.md`;
   - a line in `CHANGELOG.md`, under `## Unreleased`;
   - its name in the **prompt of its section** (see below), in `prompt.json`
     and in the guide `prompt.md`.
7. **Test** on PrestaShop 8 and 9, multistore included if you can, with a real
   MCP client: the call that should work, the account without the permission,
   and for a write, the call that changes nothing. Then write its **scenario**,
   `dev/shop-tests/scenarios/<tool name>.json`: the calls CI makes on every
   published PrestaShop 8 and 9 with their demo data (see Checks). A tool
   without one fails CI.
8. **Run the checks** listed above, then open the pull request with
   `Closes #<issue>` in its description. Sign the CLA when the bot asks.
9. **Review.** CI must be green. The maintainer reviews, may build a test zip
   of your pull request, tries it on a test shop, and merges.

CI checks step 6 for you: a tool missing from the README, still marked "to
write", without its scope, its CHANGELOG line or a prompt fails the build.

Tools for your own module do not belong here: publish them from your module,
see [docs/EXTENDING.md](docs/EXTENDING.md).

## Writing a prompt

There is one prompt per section, specified at the end of
[docs/TOOLS.md](docs/TOOLS.md#prompts) with an issue labelled `new prompt`. As for
tools, a prompt marked **draft** (its issue labelled `draft`) is not open yet. A
prompt is a folder in `prompts/` named after it, holding `prompt.json` (title,
description, arguments, scopes, permission, tools) and `prompt.md`, the guide
the assistant reads. Copy `prompts/somcp-order-lookup/`.

A prompt can only list tools that exist, so it is written in the pull request
of the first tool of its section, and each later tool of that section adds
itself to it. Its title, description and argument descriptions are translated
like any other text.

## Translations

No text a merchant can see is written in a template or a class without going
through the translation system, domain `Modules.Somcp.Admin`. Tool and schema
descriptions are the exception: the model reads them, so they stay in English.

The four catalogues in `translations/` are generated from one table:

1. Add the English string and its French, Spanish and German forms to
   `dev/translations.py`.
2. Run `python3 dev/translations.py`.
3. Commit the table and the regenerated files together.

Never edit an XLF file by hand: CI regenerates them and fails on any
difference. Not sure of a translation? Write your best attempt and say so in
the pull request.

A new language is a new column in the table and a new entry in `LANGS`.

## Versions and the CHANGELOG

- Every change a merchant or a developer would notice gets a line in
  `CHANGELOG.md`, under `## Unreleased`, in the part it belongs to: Added,
  Changed, Fixed, Security or Removed.
- Never change the version number in `config.xml` or `somcp.php`, and never add
  a file in `upgrade/`. The maintainer picks the number and writes the upgrade
  when a release is published.
- A change to the database schema goes in `sql/install.sql` and
  `sql/uninstall.sql`, and the pull request says so in its description, so the
  upgrade for shops already running the module is not forgotten at release
  time. On your test shop, reinstall the module.

---

## Pull requests

A good pull request:

- does one thing, and says what and why in a few sentences;
- says on which PrestaShop versions it was tried, multistore or not, and with
  which MCP client;
- updates `docs/TOOLS.md`, `docs/EXTENDING.md` or `docs/ARCHITECTURE.md` when
  it changes what they describe;
- passes CI.

Commits are yours, under your name. Keep the history readable: squash the
"fix typo" commits before asking for review.

**Welcome:** tools from the roadmap, tests, fixes with a way to reproduce them,
client reports, translations, documentation that was wrong or missing.

**Probably declined:** a dependency added to the module (it ships with none, on
purpose), a tool that does several unrelated things, a write that acts on many
objects at once, tools specific to one third-party module (publish those from
that module), reformatting unrelated code.

## Building the Addons package

Files only useful to developers are marked `export-ignore` in
`.gitattributes`. The builds lay the module out from git, add its Composer
dependencies, then remove what the module never runs, listed in
`dev/package/prune.txt`:

```bash
mkdir -p build && git archive --prefix=somcp/ HEAD | tar -x -C build
composer install --working-dir=build/somcp --no-dev --no-scripts --no-plugins --optimize-autoloader
dev/package/prune.sh build/somcp
(cd build && zip -qr ../somcp.zip somcp)
```

The version of Neuron AI is pinned in `composer.json`. The CI **Dependencies**
job checks it for known vulnerabilities, checks the part of its API the
assistant calls (`dev/package/contract.php`) and that every class of the pruned
archive still loads; the weekly **Dependencies** workflow opens an issue when a
newer version is out.

## Security

Found a vulnerability? Do not open an issue or a pull request: see
[SECURITY.md](SECURITY.md).
