# Boosting sales

Start from data, never from a guess. Every lead you give the merchant comes
from a tool answer of this conversation: a cart, a product, a search, with its
identifier, so the merchant can find it in the back office.

## Tools

- `somcp_ping`
- `somcp_list_abandoned_carts`
- `somcp_list_search_terms`
- `somcp_list_viewed_not_sold`
- `somcp_list_unsold_products`
- `somcp_list_cart_rules`
- `somcp_get_cart_rule`
- `somcp_create_cart_rule`
- `somcp_update_cart_rule`
- `somcp_set_cart_rule_active`
- `somcp_list_catalog_price_rules`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you look at sales in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. Carts are per shop: on a multistore
installation, they are the ones of the shop that answered.

## The period

The period given under *This request* is a number of days. Turn it into
`date_from` and `date_to`, `YYYY-MM-DD`: `date_to` is today, `date_from` that
many days before. Without a period, take 30 days. Say in your answer which
dates you used.

## Abandoned carts

1. **List them** with `somcp_list_abandoned_carts` and the dates of the
   period. A cart counts as abandoned, as on **Orders > Shopping Carts**, when
   it holds products, has no order and was created more than 24 hours ago;
   `older_than_hours` changes that age, 1 hour at least.
2. **Read the amounts as they are named.** `total_products_tax_excl` and
   `total_products_tax_incl` are the products only, without shipping, which
   is what the **Total** column shows. `total_value_tax_incl` adds up every
   matching cart, not only the page, one amount per currency: never add two
   currencies together. When `total_value_complete` is false, the total
   covers the newest carts only: say so.
3. **Point at what helps.** Carts of known customers (`with_customer: true`)
   can be followed up; carts whose visitor is `online` are still being looked
   at and are not lost yet. `products` gives the three most expensive lines
   of each cart: the products that are left behind most are leads for a
   promotion.
4. **Read the next page** with `offset` when `total_count` is larger than
   what came back, rather than concluding from one page.

## Searches on the shop

1. **List them** with `somcp_list_search_terms` and the dates of the period.
   As on **Stats > Shop search**, only the terms searched more than once come
   back; `min_searches: 1` adds the ones searched once.
2. **Read `results` as named**: the most products the term found in the
   period, not an average. `no_results_only: true` keeps the terms that found
   nothing: products visitors want and the catalogue does not show, a lead for
   the merchant (a missing product, a missing word in a product name).
3. **Say when the module is off.** When the tool answers that the Shop search
   module is not active, nothing was recorded: never read it as nobody
   searched.

## Products viewed but not sold

1. **List them** with `somcp_list_viewed_not_sold` and the dates of the period.
   As on **Stats > Product details**, it gives each product its `page_views`
   (Total Viewed), `quantity_sold` (Total bought) and `conversion_rate` (units
   bought per view), most viewed first and lowest conversion first among
   equals. `id_category` narrows it to one category.
2. **Read page views honestly.** When `page_views_recorded` is false, every
   `page_views` is 0 because **Save global page views** is off: say so, and do
   not read a 0 as "never seen". The sales figures stay real.
3. **Point at the gap.** A product with many views and few or no sales is a
   lead: a price, a photo or a description to look at. `min_page_views: 0`
   adds the products nobody opened, which are more about an empty catalogue
   than a lost sale.

## Products never purchased

1. **List them** with `somcp_list_unsold_products`. The period is optional:
   left out, it lists the active products no valid order ever bought, as
   **Products never purchased** on **Stats > Catalog statistics**; given, the
   ones not bought in it, leaving out those added to the shop after its start.
   Never add a period the merchant did not ask for.
2. **Start with the costliest stock.** The list is sorted by
   `stock_value_tax_excl`, units for sale times the cost price; a product
   without a cost price has `null` there and comes last. By default only the
   products with stock are listed: `min_quantity: 0` gives every product, as
   the screen, and `products_available` is the count it compares with.
3. **Say what the figures cover.** Orders are those of this shop only, and
   `last_sold` tells a product that sold long ago from one that never did.
   Setting a product offline or discounting it is the merchant's call.

## Cart rules and vouchers

1. **List them** with `somcp_list_cart_rules`, as **Catalog > Discounts > Cart
   Rules**: vouchers with a code and discounts applied on their own, newest
   first. `has_code` splits the two; `current: true` keeps only the rules valid
   now (their period holds and they have uses left); `active`, `name` and
   `code` filter as the list does.
2. **Read what each one gives from `summary`**, the one line built from
   `reduction`, `free_shipping` and `gift`. `reduction` is `null`, a percent,
   or an amount with its `currency` and whether tax is in it; `reduction_applies_to`
   says whether it lands on the order or a product. `times_used` is how many
   orders already spent it.
3. **Open a rule only when needed.** `has_restrictions` names the conditions a
   rule carries (country, carrier, group, product); read the full rule with
   `somcp_get_cart_rule` when one of them matters. It gives the three tabs of
   the rule: the name in every language, the minimum amount, the quantities,
   each restriction as a list of identifiers and names (carriers by
   `id_reference`, product selection as groups of rules), and what the rule
   gives, with the same `reduction` and `summary` as the list.

## Creating a voucher or a discount

1. **Agree on every field with the merchant first**: the name the customer
   reads, the code (none means the rule applies on its own to every cart that
   meets its conditions), the dates, and what it gives. Never invent a code,
   an amount or a date. Show the merchant the rule before the call.
2. **Create it** with `somcp_create_cart_rule`, under the names and shapes
   `somcp_get_cart_rule` returns. A rule must give something: a `reduction`,
   `free_shipping` or a `gift`. `id_customer` comes from
   `somcp_search_customers`, a product from `somcp_search_products`, a gift's
   combination from `somcp_get_product`. The answer names every problem at
   once: correct them all and call again.
3. **It is created off** unless `active` is true: say so, and switch it on
   with `somcp_set_cart_rule_active` only once the merchant asks. Countries, carriers, groups, other rules and the
   product selection are set on the rule's **Conditions** tab in the back
   office. If an answer is lost, look for the rule with
   `somcp_list_cart_rules` before calling again.

## Changing a voucher or a discount

1. **Read the rule first** with `somcp_get_cart_rule`, its `id_cart_rule`
   from `somcp_list_cart_rules`, and show the merchant what will change, old
   and new value, before the call.
2. **Change it** with `somcp_update_cart_rule`: give only the fields that
   change, under the same names as the creation; what is left out is kept.
   **Total available** is what is left, orders using the rule have lowered it
   already: do not add those uses again. Orders already placed keep the amount
   they had.
3. The countries, carriers, groups, other rules and the product selection
   stay with the merchant in the back office. A call that changes nothing
   answers `changed: false`. If an answer is lost, read the rule again with
   `somcp_get_cart_rule` before calling again.

## Switching a voucher or a discount on or off

1. Take the rule's `id_cart_rule` from `somcp_list_cart_rules`, and switch it
   with `somcp_set_cart_rule_active`, `active` true or false. Nothing else of
   the rule changes. Switching off stops a promotion early; orders that used
   it keep their discount.
2. A rule **with no code** applies on its own to every cart that meets its
   conditions: tell the merchant before switching it on.
3. Read the `warnings` of the answer to the merchant: a rule switched on whose
   dates are over or still to come, or with nothing left of **Total
   available**, does not apply yet. If an answer is lost, read the rule with
   `somcp_get_cart_rule` before calling again.

## Catalogue price rules

Catalogue price rules discount products **before** the cart, on the price shown
in the shop, unlike cart rules which act in the cart.

1. **List them** with `somcp_list_catalog_price_rules`, as
   **Catalog > Discounts > Catalog Price Rules** shows them. Filter by
   `currency`, `country`, `id_group`, `reduction_type`, or `current` for the
   rules in force right now.
2. A percentage comes back as a fraction (`0.1` for 10 %), the same number
   `somcp_get_product` reports on the products the rule touches. The
   `condition_groups` say which products a rule picks; an empty list means every
   product.

## Never

- Never email, call or contact a customer, and never suggest that a tool
  will: report the carts, the merchant decides what to do with them.
- Never give a customer's email, phone or address from this list: it holds a
  name only.

## Do not use when

- The merchant asks about orders already placed - that is order lookup.
- The merchant asks for revenue over a period - that is the sales report.
