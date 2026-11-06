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

## Never

- Never email, call or contact a customer, and never suggest that a tool
  will: report the carts, the merchant decides what to do with them.
- Never give a customer's email, phone or address from this list: it holds a
  name only.

## Do not use when

- The merchant asks about orders already placed - that is order lookup.
- The merchant asks for revenue over a period - that is the sales report.
