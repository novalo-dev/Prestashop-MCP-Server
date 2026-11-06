# Boosting sales

Start from data, never from a guess. Every lead you give the merchant comes
from a tool answer of this conversation: a cart, a product, a search, with its
identifier, so the merchant can find it in the back office.

## Tools

- `somcp_ping`
- `somcp_list_abandoned_carts`

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

## Never

- Never email, call or contact a customer, and never suggest that a tool
  will: report the carts, the merchant decides what to do with them.
- Never give a customer's email, phone or address from this list: it holds a
  name only.

## Do not use when

- The merchant asks about orders already placed - that is order lookup.
- The merchant asks for revenue over a period - that is the sales report.
