# Reporting sales

Start from the period the merchant gave. Never answer a sales question from
memory or from an earlier answer: the figures move with every invoice.

## Tools

- `somcp_ping`
- `somcp_get_sales_summary`
- `somcp_search_orders`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you report sales in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation, the figures are
the ones of the shop that answered, which may not be the one the merchant has
in mind.

## Workflow

1. **Turn the period into two dates.** "Last month", "this week" or "the
   summer" become `date_from` and `date_to`, `YYYY-MM-DD`, both days included,
   in the shop's time zone. Say which dates you used in your answer, so the
   merchant can check them against the back office.
2. **Read the figures** with `somcp_get_sales_summary`. Add
   `compare_previous: true` when a comparison helps ("is it better than
   before?"): `previous` then holds the period of the same length that ends the
   day before `date_from`, with its own dates.
3. **The orders behind a figure**, when the merchant asks for them: use
   `somcp_search_orders` with the same dates and `only_valid: true`. It needs
   order access: without it, say the figures are all you can give.

## How the figures are counted

- Only **valid** orders, dated by their **invoice date**, as
  **Stats > Stats Dashboard** and **Stats > Sales and orders** count them.
- `revenue_tax_excl` is **Revenue** on Stats Dashboard. `sales_tax_excl` is
  **Sales** on Sales and orders: the revenue minus the credit slips of those
  orders. `revenue_tax_incl` is the same revenue tax included.
- Every amount is in the shop's **default currency** (`currency`), orders paid
  in another currency converted at their rate. Always give an amount with its
  currency and say whether tax is included.
- `new_customers` is **Registrations**: the accounts created in the period,
  whether or not they ordered.
- `somcp_search_orders` filters on the date the order was **placed**, not the
  invoice date: an order placed on the 31st and invoiced on the 1st falls on
  each side of a month. Expect a few orders to differ at the edges of the
  period, and say so if the merchant compares the two.
- An empty period gives zeros, not an error: say there was no sale.

## Do not use when

- The merchant asks about one order - that is order lookup.
- The merchant asks what is left in stock - that is stock.
