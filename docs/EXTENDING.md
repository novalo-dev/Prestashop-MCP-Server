# Extending somcp from your own module

Any PrestaShop module can publish its own MCP tools and prompts through somcp.
Your module keeps its business logic; somcp takes care of the protocol, OAuth,
the consent screen, permissions, logging and the merchant's on/off switches.

This page is the whole contract. If something here does not match what the code
does, the code wins and this page has a bug: please open an issue.

A complete working module is in [`example/mymodule`](example/mymodule). Copy it
to `modules/mymodule`, install it next to somcp, and its tool and prompt show up
in the assistant.

---

## The one rule: return arrays, never call somcp

Your module must not name a single class of somcp: no `SomcpTool`, no
`SomcpUpload`, no `Somcp::` call, no type hint, no `instanceof`.

The reason is practical. The PrestaShop Addons validator analyses your module on
its own, without somcp installed. Every somcp class you mention is a class it
cannot find, and that is a rejection, even if the line only runs when somcp is
there.

So you describe what you publish as plain PHP arrays, returned from two hooks,
and you read what somcp hands you as plain arrays too.

You do not need `class_exists()` guards either. Without somcp, nobody fires
these hooks and your methods are never called.

Install order does not matter: `registerHook()` creates a hook that does not
exist yet, and somcp reads the list of subscribed modules on every request.

---

## The two hooks

```php
public function install(): bool
{
    return parent::install()
        && $this->registerHook('actionSomcpRegisterTools')
        && $this->registerHook('actionSomcpRegisterPrompts');
}
```

| Hook | Your method returns | `$params` |
|---|---|---|
| `actionSomcpRegisterTools` | `['scopes' => [...], 'tools' => [...]]` | contains an internal object, ignore it |
| `actionSomcpRegisterPrompts` | `['prompts' => [...]]` | empty |

Both methods must be declared `: array` and **return** the array. somcp calls
the hook with PrestaShop's array-return flag, which files each module's return
value under the module name. A method declared `: void`, or one that returns
nothing, publishes nothing.

A module that has tools but no scope of its own may return the list of tools
directly instead of wrapping it in `'tools'`.

Everything you publish is prefixed with your module folder: a tool named
`count_customers` in `mymodule` is published as `mymodule-count_customers`. You
cannot opt out. The prefix is what stops two modules that both chose
`get_tracking` from cancelling each other out, and what stops a module from
publishing something that reads as one of somcp's own tools.

---

## Scopes: declare your own

```php
'scopes' => [
    'customers.read' => $this->trans('Count the customers of the shop', [], 'Modules.Mymodule.Admin'),
],
```

A scope is a permission the merchant grants to an assistant on the consent
screen. Short name on the left, label on the right. somcp qualifies the name
with your folder (`mcp.mymodule.customers.read`). The label is what the
merchant reads, so it goes through your translations.

Declare your own scopes rather than reusing somcp's (`mcp.access`,
`mcp.orders.read`, `mcp.orders.write`, `mcp.catalog.read`, `mcp.catalog.write`, `mcp.stock.read`, `mcp.stock.write`). Someone who let an assistant read their
orders has not let it read their customers, and the consent screen has to be
able to ask the two questions separately. A module cannot declare a name that
belongs to somcp: it is refused and logged.

Every tool and prompt you publish also requires `mcp.access`, the scope every
token carries. somcp adds it for you.

---

## Anatomy of a tool

```php
[
    'name' => 'count_customers',
    'title' => $this->trans('Count customers', [], 'Modules.Mymodule.Admin'),
    'description' => 'Counts the customer accounts of this shop, ...',
    'input' => [
        'type' => 'object',
        'properties' => [
            'since' => ['type' => 'string', 'format' => 'date', 'description' => 'Only count accounts created on or after this day.'],
        ],
        'additionalProperties' => false,
    ],
    'scopes' => ['customers.read'],
    'permission' => ['view', 'AdminCustomers'],
    'handler' => [$this, 'somcpCountCustomers'],
]
```

| Key | Required | What it does | If you leave it out |
|---|---|---|---|
| `name` | yes | Identifier, `[a-zA-Z0-9_-]`. Other characters are dropped. At most 64 characters **with** the prefix. | The tool is refused. |
| `description` | yes | What the assistant reads to decide when to call the tool. See below. | The tool is refused. |
| `handler` | yes | A public callable, usually `[$this, 'method']`. | The tool is refused. |
| `title` | no | Short label, shown in the somcp settings and by some clients. Translated. | The name is used. |
| `input` | no | JSON Schema of the arguments. Root `type: object`. | An object with no properties. |
| `scopes` | no | Short names (`customers.read`) or full ones (`mcp.mymodule.customers.read`). They must be declared in `scopes`. | Only `mcp.access` is required. |
| `permission` | no | `[action, tab]`, see below. | No back office right is checked, and no hint is given to the assistant. |
| `accepts_files` | no | `true` if the tool may ask the merchant for files. | Required for file deposits, see below. |

A tool that breaks one of these rules is refused on its own and the reason goes
to the somcp log as `tool_declaration_refused`. Your other tools and every other
module's tools stay published.

### `permission`: the PrestaShop right, and what the assistant is told

`['view', 'AdminCustomers']` means: the employee behind the token must be able
to view the `AdminCustomers` page in the back office. The action is one of
`view`, `add`, `edit`, `delete`, the four rights PrestaShop already manages per
profile. The tab is the `class_name` of the back office controller where a
merchant would do the same thing by hand, which for your feature is usually
your own module's page.

The action also tells the assistant what kind of call this is:

- `view`: flagged read-only and safe to repeat. Clients usually run it without
  asking.
- `add`, `edit`, `delete`: the call changes the shop. The tool is flagged as
  destructive and not idempotent, which is what MCP clients use to ask the
  merchant before running it. The confirmation itself is the client's job; the
  flags are what makes it ask. Write tools should change one object per call and must say in
  their description that a lost answer is not a reason to call again.

The right is checked on every call, against the employee's current profile, and
on a multistore install somcp also checks that the employee is attached to the
shop the token belongs to. You do not have to do any of this yourself.

### `input`: the empty schema trap

A tool with no arguments is the classic trap. In PHP, `'properties' => []`
serialises to `[]`, a JSON array, where JSON Schema expects `{}`, an object. A
client that receives `[]` rejects the **whole** `tools/list` answer, and the
tools of every module on the shop disappear because of one.

somcp repairs this when it registers your tool: a missing `type` is added, and
an empty array where an object belongs becomes an object, at the root and one
level down. What it cannot repair, such as a root that is not an object, refuses
that one tool with `tool_schema_refused` in the log.

You can rely on the repair, or write it right in the first place:

```php
'input' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
```

### Descriptions decide whether the tool gets used correctly

The assistant never sees your code. It sees the name, the description and the
schema, and decides from those alone when to call the tool and with what. A
vague description gives a tool that is called at the wrong moment, with guessed
arguments.

A good description says three things:

1. **When to call it**, in the merchant's words: "use it for *how many
   customers do I have*".
2. **When not to**, and which tool to use instead: "do not use it to find or
   read a customer".
3. **What must never be guessed**: identifiers, dates, statuses. "Never guess
   the date: if the merchant gave none, leave it out." For a write tool, add
   what to do if the answer is lost: read the object again rather than repeat
   the call.

Property descriptions in the schema follow the same idea: the format expected,
and where the value should come from ("taken from `mymodule-list_x`").

Tool descriptions and schema property descriptions stay in **plain English**
and do not go through the translation system. They are read by the assistant,
not shown to the merchant. The `title`, the scope labels and everything in a
prompt's menu entry are shown to the merchant, so those are translated.

---

## The handler

```php
public function somcpCountCustomers(array $args, array $context): array
{
    $idShop = (int) $context['id_shop'];
    // ...
    return ['json' => ['customers' => $count]];
}
```

`$args` is what the assistant sent. `$context` contains exactly these keys:

| Key | Meaning |
|---|---|
| `id_shop` | The shop this call is for, decided by somcp from the token. **Never take a shop id from `$args`.** |
| `id_employee` | The employee the assistant acts for. |
| `id_lang` | That employee's language, or the shop's default. |
| `files` | Only when coming back from a file deposit, see below. |

Return one of:

| Return | Effect |
|---|---|
| `['json' => $data]` | Structured data, encoded by somcp. |
| `['text' => '...']` | Plain text. |
| `['text' => '...', 'error' => true]` | A business failure (order not found, account not linked). The assistant reports it instead of retrying. |
| `['upload' => [...]]` | Ask the merchant for files. |

Anything else is treated as a failure: it is logged as `tool_result_refused`
and the assistant gets a generic error. An exception thrown by your handler is
caught, logged, and reported without its message, which could contain a query
or a path.

---

## Asking the merchant for files

Files do not travel through the conversation: the assistant would have to write
them out byte by byte. Instead somcp opens a deposit page in the merchant's own
back office, and your handler is called again with the files.

Declare the tool with `'accepts_files' => true`, then:

```php
public function somcpAttach(array $args, array $context): array
{
    if (empty($context['files'])) {
        return ['upload' => [
            'purpose' => $this->trans('Photos of the damaged parcel', [], 'Modules.Mymodule.Admin'),
            'accept' => 'image',
            'files' => 5,
            'bytes' => 8388608,
        ]];
    }

    foreach ($context['files'] as $file) {
        // $file['name'], $file['mimeType'], $file['size'], $file['contents']
    }

    return ['json' => ['attached' => count($context['files'])]];
}
```

| Key | Required | Rule |
|---|---|---|
| `purpose` | yes | Shown to the merchant on the deposit page. Translated. |
| `accept` | yes | `image`, `document`, `data`, `archive`, or a list of extensions from the list somcp accepts. |
| `files` | no | How many. Default 1, capped at 20 and at what PHP accepts. |
| `bytes` | no | Size per file. Default 8 MiB, capped at 64 MiB and at what PHP accepts. |

somcp handles the rest: the page, checking that the person depositing is the
employee the call was made for, checking each file's bytes against its
extension, expiry, cleanup, and handing the address to the assistant as text
when the client cannot open it. Your handler is called again with the same
`$args` and the files in `$context['files']`.

Two cases are handled without calling you. If the assistant comes back while
the deposit is still open and empty, somcp hands the same address out again. If
it comes back with a deposit that no longer exists (cancelled, expired, already
used), the call is treated as a new one: your handler runs without files and
asks again, which opens a fresh deposit.

`accepts_files` matters: it declares the `upload_ref` argument the assistant
uses to come back with the deposit. Without it, a client that validates
arguments refuses the second call.

In the built-in assistant, a file the employee attached to a message is
already a deposit, named to the model with its `upload_ref`. The model can call
your tool with that reference straight away: your handler receives the file in
`$context['files']` on the first call, with no deposit page. It gets a copy,
so the conversation keeps showing the file.

---

## Anatomy of a prompt

A prompt is a guided task the merchant starts from the assistant's menu. It
costs nothing until it is picked, then hands the assistant a written guide for
using your tools. It never starts on its own.

```php
public function hookActionSomcpRegisterPrompts(array $params): array
{
    return ['prompts' => [[
        'name' => 'customer-overview',
        'title' => $this->trans('Customer overview', [], 'Modules.Mymodule.Admin'),
        'description' => $this->trans('How many customers the shop has, and how many are new.', [], 'Modules.Mymodule.Admin'),
        'file' => 'prompts/customer-overview.md',
        'scopes' => ['customers.read'],
        'tools' => ['mymodule-count_customers'],
        'permission' => ['view', 'AdminCustomers'],
        'arguments' => [
            ['name' => 'since', 'description' => $this->trans('Count new accounts from this day', [], 'Modules.Mymodule.Admin'), 'required' => false],
        ],
    ]]];
}
```

| Key | Required | Rule |
|---|---|---|
| `name` | yes | Published with your prefix: `mymodule-customer-overview`. |
| `description` | yes | Shown in the assistant's menu. Translated. |
| `file` | yes | Markdown file of the guide, **relative to your module folder**. A path that leads outside your folder is refused. 64 KiB at most. |
| `title` | no | Menu label. Translated. Defaults to the name. |
| `scopes`, `permission` | no | Same as a tool. A merchant who may not use the tools does not see the prompt. |
| `tools` | no | Published names of the tools the guide talks about, shown in the somcp settings. |
| `arguments` | no | Fields the merchant can fill in: `name` (`[A-Za-z0-9_-]`, unique), `description` (translated), `required` (default `false`). |

### Arguments are appended, never substituted

This surprises everyone, so it gets its own section. somcp does **not** replace
anything in your guide. There is no `{{since}}` templating. What the merchant
fills in is added **below** the guide, under its own heading:

```
...your guide...

## This request

- since: 2026-01-01
```

Line breaks are removed from each value, each is cut at 2000 characters, and an
argument you did not declare is dropped. The guide is written by you, the
values are typed by a person, and splicing one into the other would let a value
rewrite the instructions around it.

So write the guide to refer to that section: "If a date is given under *This
request*, use it. Otherwise ask the merchant which period they mean."

A front matter block at the top of the file (`---` ... `---`) is removed before
the guide is sent, so an existing skill file can be reused as it is.

---

## What the merchant controls

Every tool and prompt you publish appears in the somcp settings with an on/off
switch, per shop. A switched-off tool is left out of the listing and refused if
called anyway, and a switched-off prompt cannot be fetched. Settings are stored
as the list of what is off, so a tool you add in an update is on without anyone
having to allow it.

---

## Checking your integration

1. In the somcp settings, your tools and prompts are listed with your module
   name under *Published by*.
2. The somcp security log has no `tool_declaration_refused`,
   `prompt_declaration_refused`, `tool_schema_refused` or `tool_result_refused`
   line naming your module.
3. In your assistant, start a new conversation: your tools are listed, and your
   prompts appear in the prompt menu.
4. `grep -rn "Somcp" --include=*.php` in your module only finds the two hook
   names.
