# O'So MCP Server for PrestaShop

<div align="center">

[![PHP tests](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/php.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/php.yml)
[![Integration](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/integration.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/integration.yml)
[![Security](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/security.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/security.yml)
[![UI tests](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/ui.yml/badge.svg?branch=develop)](https://github.com/novalo-dev/Prestashop-MCP-Server/actions/workflows/ui.yml)

[![Release](https://img.shields.io/github/v/release/novalo-dev/Prestashop-MCP-Server?style=flat&label=release&color=007EC6)](https://github.com/novalo-dev/Prestashop-MCP-Server/releases/latest)
[![PrestaShop](.github/badges/prestashop.svg)](https://prestashop.com)
[![PHP](.github/badges/php.svg)](https://php.net)
[![MCP](.github/badges/mcp.svg)](https://modelcontextprotocol.io)
![Multistore](.github/badges/multistore.svg)
[![License](.github/badges/license.svg)](LICENSE.txt)
[![Docs](.github/badges/docs.svg)](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki)

**Talk to your PrestaShop shop from Claude, ChatGPT or the assistant built into your back office.**

Free and open source - PrestaShop 8.0 to 9.x - English, French, Spanish, German

[**Download the latest version**](https://github.com/novalo-dev/Prestashop-MCP-Server/releases/latest) · [Documentation](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki) · [Ask a question](https://github.com/novalo-dev/Prestashop-MCP-Server/issues)

</div>

https://github.com/user-attachments/assets/cf0b0e3f-1172-4ba9-aad1-11a6298aadf7

Ask your AI assistant to find an order, change a status, check your stock or
audit a product page, and it does it in your back office - with the rights of
the employee who connected it, and never more.

In the back office the module is called **O'So MCP Server**.

---

## What you can ask

> "Which orders are still waiting for shipment since Monday?"

> "Mark order #1042 as shipped."

> "Which products are almost out of stock?"

> "Show me last month's sales."

> "Audit the SEO of my best-selling product and fix its meta description."

> "Who is the customer behind order #1042, and what else did they buy?"

94 tools cover orders, catalogue, stock, customers, statistics, SEO, content
pages, modules and appearance, and 13 ready-made prompts guide the assistant
through the usual jobs. [See them all](#tools).

---

## Requirements

| | |
|---|---|
| PrestaShop | 8.0 to 9.x |
| PHP | 8.1 or later, with the cURL extension |
| Address | the shop reachable from the internet over **HTTPS**, so online assistants can connect to it |
| Friendly URLs | enabled (**Shop Parameters > Traffic & SEO**) |
| Location | the shop enabled, and installed at the root of a domain or subdomain, not in a subfolder |
| Multistore | supported: each shop has its own address and settings |
| Languages | English, French, Spanish, German |

The **Run the diagnostic** button in the module checks all of this from the
outside: certificate, SSL setting, friendly URLs, location and the addresses an
assistant calls. No line should be red before you connect an assistant.

---

## Install in 3 steps

1. **Download** `OSo-MCP-Server-x.y.z.zip` from the
   [latest release](https://github.com/novalo-dev/Prestashop-MCP-Server/releases/latest).
   Do not unzip it.
2. In your back office, open **Modules > Module Manager**, click
   **Upload a module** and drop the zip, then **Configure**.
3. Copy **the address to give to your assistant** - it looks like
   `https://your-shop.example/module/somcp/mcp` - and paste it into Claude,
   ChatGPT or another assistant
   ([where to paste it](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki/Connect-an-assistant)).

The first time, the assistant opens your back office: you sign in and approve
what it may do. That's it. Step by step with screenshots:
[Installation](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki/Installation).

---

## Or use the assistant built into your back office

No account to connect: a chat button at the top of every back office page opens
an assistant that works with the same tools, with the rights of the employee
signed in, and asks for your approval before any change.

- Bring your own key from [Claude](https://console.anthropic.com/settings/keys),
  [ChatGPT](https://platform.openai.com/api-keys),
  [Gemini](https://aistudio.google.com/apikey),
  [Mistral](https://console.mistral.ai/api-keys),
  [DeepSeek](https://platform.deepseek.com/api_keys),
  [Grok](https://console.x.ai) or
  [Cohere](https://dashboard.cohere.com/api-keys) - each link opens the page
  where that provider gives out keys.
- Or run an AI on your own computer, such as Ollama or LM Studio, with
  [O'So AI Relay](https://github.com/novalo-dev/Somcp-Relay): no key, and
  nothing opened on your network.
- Deep thinking, web search and memory can be switched on or off for each
  conversation, from the **+** menu. Several assistants can share the work by
  role.

More in the wiki: [Built-in assistant](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki/Built%E2%80%90in-assistant).

![The settings pages of O'So MCP Server, one after the other](docs/images/somcp-tour.gif)

---

## Compatible assistants

Any assistant that connects to a remote MCP server with OAuth can use your
shop's address. These can:

- **Chat:** [Claude](https://claude.ai) (web, desktop, mobile),
  [ChatGPT](https://chatgpt.com), [Le Chat](https://chat.mistral.ai) (Mistral),
  [Dust](https://dust.tt)
- **Command line:** [Claude Code](https://claude.com/product/claude-code),
  [Codex](https://openai.com/codex/) (OpenAI),
  [Gemini CLI](https://geminicli.com) (Google),
  [GitHub Copilot CLI](https://github.com/features/copilot/cli)
- **Code editor:** [Cursor](https://cursor.com),
  [VS Code](https://code.visualstudio.com) (GitHub Copilot),
  [Windsurf](https://devin.ai/desktop) (Devin Desktop), [Zed](https://zed.dev)
- **Automation:** [n8n](https://n8n.io) (Cloud and self-hosted),
  [Make](https://www.make.com)

Where to paste the address in each of them:
[Connect an assistant](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki/Connect-an-assistant).

---

## Your shop stays protected

- **Each employee decides.** An assistant only gets the rights of the employee
  who connected it, page by page, exactly as set in **Team > Permissions**, and
  only on the shops that employee is attached to.
- **No password and no all-powerful API key.** Assistants sign in with
  OAuth 2.1, and every connection can be withdrawn in one click from the
  module.
- **You choose what is offered.** Each tool and prompt can be switched off for
  connected assistants, for the built-in assistant, or both. Changes are
  flagged as such, which assistants use to ask you before making them.
- **Every refusal is logged**, in a security log that never contains a token
  or a secret.
- **Tested before every release.** Each version is installed and run on 34
  PrestaShop versions (74 PrestaShop and PHP combinations), attacked by 40
  security tests on a live shop and driven through the back office by a real
  browser. The report is attached to every
  [release](https://github.com/novalo-dev/Prestashop-MCP-Server/releases/latest).

Found a security issue? Please do not open an issue: report it privately, see
[SECURITY.md](SECURITY.md).

---

## Tools

Grouped by back office page, each object's reads before its writes. The
**Back office right** column is the right the employee needs in
**Team > Permissions** for the assistant to use the tool. Details of every
tool are in [docs/TOOLS.md](docs/TOOLS.md).

### Connection

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_ping` | `mcp.access` | none | read |

</details>

### Orders

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_search_orders` | `mcp.orders.read` | `view` on `AdminOrders` | read |
| `somcp_get_order` | `mcp.orders.read` | `view` on `AdminOrders` | read |
| `somcp_list_order_statuses` | `mcp.orders.read` | `view` on `AdminStatuses` | read |
| `somcp_list_return_statuses` | `mcp.orders.read` | `view` on `AdminStatuses` | read |
| `somcp_list_carriers` | `mcp.orders.read` | `view` on `AdminCarriers` | read |
| `somcp_set_order_status` | `mcp.orders.write` | `edit` on `AdminOrders` | write |
| `somcp_set_order_tracking` | `mcp.orders.write` | `edit` on `AdminOrders` | write |
| `somcp_add_order_message` | `mcp.orders.write` | `edit` on `AdminOrders` | write |
| `somcp_set_order_note` | `mcp.orders.write` | `edit` on `AdminOrders` | write |
| `somcp_add_order_payment` | `mcp.orders.write` | `edit` on `AdminOrders` | write |
| `somcp_create_return_status` | `mcp.orders.write` | `add` on `AdminStatuses` | write |
| `somcp_update_return_status` | `mcp.orders.write` | `edit` on `AdminStatuses` | write |
| `somcp_delete_return_status` | `mcp.orders.write` | `delete` on `AdminStatuses` | write |
| `somcp_create_order_status` | `mcp.orders.write` | `add` on `AdminStatuses` | write |

</details>

### Catalog - Products

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_search_products` | `mcp.catalog.read` | `view` on `AdminProducts` | read |
| `somcp_get_product` | `mcp.catalog.read` | `view` on `AdminProducts` | read |
| `somcp_create_product` | `mcp.catalog.write` | `add` on `AdminProducts` | write |
| `somcp_update_product` | `mcp.catalog.write` | `edit` on `AdminProducts` | write |
| `somcp_set_product_active` | `mcp.catalog.write` | `edit` on `AdminProducts` | write |
| `somcp_delete_product` | `mcp.catalog.write` | `delete` on `AdminProducts` | write |

</details>

### Catalog - Categories

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_categories` | `mcp.catalog.read` | `view` on `AdminCategories` | read |
| `somcp_get_category` | `mcp.catalog.read` | `view` on `AdminCategories` | read |
| `somcp_create_category` | `mcp.catalog.write` | `add` on `AdminCategories` | write |
| `somcp_update_category` | `mcp.catalog.write` | `edit` on `AdminCategories` | write |
| `somcp_set_category_active` | `mcp.catalog.write` | `edit` on `AdminCategories` | write |

</details>

### Catalog - Brands

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_manufacturers` | `mcp.catalog.read` | `view` on `AdminManufacturers` | read |
| `somcp_get_manufacturer` | `mcp.catalog.read` | `view` on `AdminManufacturers` | read |
| `somcp_create_manufacturer` | `mcp.catalog.write` | `add` on `AdminManufacturers` | write |
| `somcp_update_manufacturer` | `mcp.catalog.write` | `edit` on `AdminManufacturers` | write |
| `somcp_set_manufacturer_active` | `mcp.catalog.write` | `edit` on `AdminManufacturers` | write |
| `somcp_list_manufacturer_addresses` | `mcp.catalog.read` | `view` on `AdminManufacturers` | read |
| `somcp_get_manufacturer_address` | `mcp.catalog.read` | `view` on `AdminManufacturers` | read |
| `somcp_create_manufacturer_address` | `mcp.catalog.write` | `edit` on `AdminManufacturers` | write |
| `somcp_update_manufacturer_address` | `mcp.catalog.write` | `edit` on `AdminManufacturers` | write |
| `somcp_delete_manufacturer_address` | `mcp.catalog.write` | `delete` on `AdminManufacturers` | write |

</details>

### Catalog - Suppliers

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_suppliers` | `mcp.catalog.read` | `view` on `AdminSuppliers` | read |
| `somcp_get_supplier` | `mcp.catalog.read` | `view` on `AdminSuppliers` | read |
| `somcp_create_supplier` | `mcp.catalog.write` | `add` on `AdminSuppliers` | write |
| `somcp_update_supplier` | `mcp.catalog.write` | `edit` on `AdminSuppliers` | write |
| `somcp_set_supplier_active` | `mcp.catalog.write` | `edit` on `AdminSuppliers` | write |

</details>

### Catalog - Attributes

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_attributes` | `mcp.catalog.read` | `view` on `AdminAttributesGroups` | read |
| `somcp_get_attribute_group` | `mcp.catalog.read` | `view` on `AdminAttributesGroups` | read |
| `somcp_create_attribute_group` | `mcp.catalog.write` | `add` on `AdminAttributesGroups` | write |
| `somcp_update_attribute_group` | `mcp.catalog.write` | `edit` on `AdminAttributesGroups` | write |
| `somcp_get_attribute_value` | `mcp.catalog.read` | `view` on `AdminAttributesGroups` | read |
| `somcp_create_attribute_value` | `mcp.catalog.write` | `add` on `AdminAttributesGroups` | write |
| `somcp_update_attribute_value` | `mcp.catalog.write` | `edit` on `AdminAttributesGroups` | write |

</details>

### Catalog - Features

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_features` | `mcp.catalog.read` | `view` on `AdminFeatures` | read |
| `somcp_get_feature` | `mcp.catalog.read` | `view` on `AdminFeatures` | read |
| `somcp_create_feature` | `mcp.catalog.write` | `add` on `AdminFeatures` | write |
| `somcp_update_feature` | `mcp.catalog.write` | `edit` on `AdminFeatures` | write |
| `somcp_get_feature_value` | `mcp.catalog.read` | `view` on `AdminFeatures` | read |
| `somcp_create_feature_value` | `mcp.catalog.write` | `add` on `AdminFeatures` | write |
| `somcp_update_feature_value` | `mcp.catalog.write` | `edit` on `AdminFeatures` | write |

</details>

### Catalog - Files and taxes

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_attachments` | `mcp.catalog.read` | `view` on `AdminAttachments` | read |
| `somcp_list_tax_rules` | `mcp.catalog.read` | `view` on `AdminTaxRulesGroup` | read |

</details>

### Stock

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_get_stock` | `mcp.stock.read` | `view` on `AdminStockManagement` | read |
| `somcp_list_low_stock` | `mcp.stock.read` | `view` on `AdminStockManagement` | read |
| `somcp_set_stock_quantity` | `mcp.stock.write` | `edit` on `AdminStockManagement` | write |

</details>

### Customers

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_search_customers` | `mcp.customers.read` | `view` on `AdminCustomers` | read |
| `somcp_get_customer` | `mcp.customers.read` | `view` on `AdminCustomers` | read |
| `somcp_create_customer` | `mcp.customers.write` | `add` on `AdminCustomers` | write |
| `somcp_update_customer` | `mcp.customers.write` | `edit` on `AdminCustomers` | write |
| `somcp_list_customer_groups` | `mcp.customers.read` | `view` on `AdminGroups` | read |

</details>

### Statistics

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_get_sales_summary` | `mcp.stats.read` | `view` on `AdminStats` | read |
| `somcp_list_best_sellers` | `mcp.stats.read` | `view` on `AdminStats` | read |

</details>

### Content

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_cms_pages` | `mcp.content.read` | `view` on `AdminCmsContent` | read |
| `somcp_get_cms_page` | `mcp.content.read` | `view` on `AdminCmsContent` | read |

</details>

### Modules

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_modules` | `mcp.modules.read` | `view` on `AdminModulesSf` | read |
| `somcp_get_module` | `mcp.modules.read` | `view` on `AdminModulesSf` | read |
| `somcp_install_module` | `mcp.modules.write` | `add` on `AdminModulesSf` | write |
| `somcp_set_module_active` | `mcp.modules.write` | `edit` on `AdminModulesSf` | write |

</details>

### Appearance

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_list_menu_links` | `mcp.appearance.read` | `view` on `AdminModulesSf` | read |
| `somcp_add_menu_link` | `mcp.appearance.write` | `edit` on `AdminModulesSf` | write |
| `somcp_update_menu_link` | `mcp.appearance.write` | `edit` on `AdminModulesSf` | write |
| `somcp_get_theme` | `mcp.appearance.read` | `view` on `AdminThemes` | read |
| `somcp_update_theme_setting` | `mcp.appearance.write` | `edit` on `AdminThemes` | write |

</details>

### System

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_get_cache_status` | `mcp.system.read` | `view` on `AdminPerformance` | read |
| `somcp_clear_cache` | `mcp.system.write` | `delete` on `AdminPerformance` | write |

</details>

### SEO

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_get_seo_settings` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_list_meta_pages` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_audit_page` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_check_links` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_audit_sitemap` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_audit_product_seo` | `mcp.seo.read` | `view` on `AdminProducts` | read |
| `somcp_audit_category_seo` | `mcp.seo.read` | `view` on `AdminCategories` | read |
| `somcp_audit_cms_seo` | `mcp.seo.read` | `view` on `AdminCmsContent` | read |
| `somcp_audit_brand_seo` | `mcp.seo.read` | `view` on `AdminManufacturers` | read |
| `somcp_update_cms_seo` | `mcp.seo.write` | `edit` on `AdminCmsContent` | write |
| `somcp_update_meta_page` | `mcp.seo.write` | `edit` on `AdminMeta` | write |
| `somcp_get_robots_txt` | `mcp.seo.read` | `view` on `AdminMeta` | read |
| `somcp_generate_robots_txt` | `mcp.seo.write` | `add`, `edit` and `delete` on `AdminMeta` | write |

</details>

### AEO

<details>
<summary>Show the tools</summary>

| Tool | Scope | Back office right | Nature |
|---|---|---|---|
| `somcp_audit_product_schema` | `mcp.seo.read` | `view` on `AdminProducts` | read |

</details>

**Reads** change nothing and are safe to repeat. **Writes** change one thing per
call, and are flagged so assistants ask you before running them. `somcp_ping` needs no right: it
only tells the assistant which shop, account, language and currency it is
connected to.

**13 prompts** ship with the module: check the shop connection, find an order,
change an order status, manage products, check and adjust stock, find a
customer, audit and improve SEO, get recommended by AI assistants, sales
report, read content pages, review modules, cache maintenance, storefront menu
and layout. The built-in assistant offers them too, each with its icon: those of
the page you are on first, and all of them from the **+** of its field.

---

## Need help?

- **Documentation:** the [wiki](https://github.com/novalo-dev/Prestashop-MCP-Server/wiki)
  covers requirements, installation, settings, each assistant and the built-in
  assistant.
- **A bug or an idea:** open an [issue](https://github.com/novalo-dev/Prestashop-MCP-Server/issues).
- **Installation, setup or a tool made for your shop:** [Novalo](https://novalo.fr).
- **What changed in each version:** [CHANGELOG.md](CHANGELOG.md).

---

## For developers

How the module works (OAuth, MCP, multistore), how to install it from the
sources, how to publish tools from your own module, how to contribute and where
help is wanted: [CONTRIBUTING.md](CONTRIBUTING.md).

## Contributors

Everyone who helped build the module, the top contributor in the middle. The
names and the counts are read from GitHub each time this page is shown, so
they are always up to date. The settings page of the module shows the same
list, read from GitHub, under **Help and updates > Meet our contributors**.

<div align="center">
<table>
  <tr>
    <td align="center" width="200">
      <sub>2nd</sub><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B1%5D.login&amp;label=contributor&amp;color=brightgreen" alt="2nd contributor"></a><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B1%5D.contributions&amp;label=contributions&amp;color=blue" alt="2nd contributions"></a>
    </td>
    <td align="center" width="200">
      <b>1st</b><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B0%5D.login&amp;label=contributor&amp;color=brightgreen" alt="1st contributor"></a><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B0%5D.contributions&amp;label=contributions&amp;color=blue" alt="1st contributions"></a><br>
      <sub><i>Top contributor</i></sub>
    </td>
    <td align="center" width="200">
      <sub>3rd</sub><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B2%5D.login&amp;label=contributor&amp;color=brightgreen" alt="3rd contributor"></a><br>
      <a href="https://github.com/novalo-dev/Prestashop-MCP-Server/graphs/contributors"><img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fnovalo-dev%2FPrestashop-MCP-Server%2Fcontributors&amp;query=%24%5B2%5D.contributions&amp;label=contributions&amp;color=blue" alt="3rd contributions"></a>
    </td>
  </tr>
</table>

Want to be next? [CONTRIBUTING.md](CONTRIBUTING.md) explains how.
</div>

## License

[Academic Free License 3.0](LICENSE.txt), like the rest of the Novalo modules.
