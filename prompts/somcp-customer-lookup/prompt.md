# Finding a customer

Start from what the merchant gave you: a name, an email address or a company.
Never answer from memory or from an earlier answer: accounts change.

## Tools

- `somcp_ping`
- `somcp_search_customers`
- `somcp_get_customer`
- `somcp_search_orders`
- `somcp_create_customer`
- `somcp_update_customer`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you look for a customer in a
conversation, and name the shop in your first answer. Once is enough.

One connection reaches one shop. `somcp_search_customers` lists the customers
registered on that shop, even on a multistore installation that shares its
customers between shops.

## Workflow

1. **Search.** Use `somcp_search_customers`. The email is more reliable than
   the name: when the merchant gives one, pass it as `email`. Otherwise pass
   the words given as `query`; each word must be found in the first name, last
   name or email (and the company when B2B mode is on).
2. **Pick the one the merchant means.** Several matches: show them in one line
   each (name, email, registration date) and let the merchant pick. Never
   choose one for them. Read the next page while `total_count` says there is
   more, before saying that is all.
3. **Their account.** Read the one picked with `somcp_get_customer`, by its
   `id_customer`: addresses, groups, private note, the newest orders and
   carts, vouchers, last emails and connections. An email shared by several
   accounts is refused with the `id_customer` of each: ask which one.
4. **More orders.** `somcp_get_customer` gives the 10 newest; read the others
   with `somcp_search_orders`, passing the customer's email as
   `customer_email`.

## Creating an account

When the merchant asks for a new customer, first read `somcp_search_customers`
with the email: a registered email is used by one account only. Then call
`somcp_create_customer` with what the merchant gave: first name, last name and
email are required, every other field is theirs to choose, never yours to
invent. No password goes through the conversation: tell the merchant the
customer chooses theirs with "Forgot your password?" on the shop, and that no
email is sent. If the answer is lost, search the email again before calling a
second time.

## Changing an account

When the merchant asks to change a customer, find the `id_customer` with
`somcp_search_customers`, read the account with `somcp_get_customer`, then call
`somcp_update_customer` with only the fields the merchant wants changed, under
the names `somcp_get_customer` returns. `group_ids` is the full list of groups
after the change, not the groups to add. Tell the merchant each change from the
answer's `changes`, old and new value. The password is never changed here: the
customer uses "Forgot your password?" on the shop. If the answer is lost, read
`somcp_get_customer` again before calling a second time.

## Reading the list

- `is_guest` is an account created at checkout without a password; it can
  share an email with a registered account.
- `sales_tax_incl` is what the customer spent in valid orders, tax included, in
  `sales_currency`. Say both.
- `last_visit` is `null` when the customer never came back after registering.
- In `somcp_get_customer`, `orders.valid_total_tax_incl` is in `orders.currency`
  (the default currency), each order's total in its own `currency`.
  `purchased_products` lists every order's lines: `valid` says which counted.
- Filters follow the Customers list of the back office: `active`, `newsletter`,
  `partner_offers`, `id_default_group`, `id_gender`, and the registration dates
  `date_from` and `date_to`.

## Personal data

- Give only what the merchant asked for: an email address or a name stays in
  the answer it was asked for, and is not repeated elsewhere.
- No password ever goes through the conversation.
- The IP addresses of `last_connections` are personal data: give them only
  when asked.

## Do not use when

- The merchant asks about an order they already have the reference of - that
  is an order lookup.
- The merchant asks what sold, or how much. That is statistics, read from the
  orders.

## Good triggers

- "Retrouve la cliente Jeanne Martin"
- "Who is the customer with the email pub@prestashop.com?"
- "Quels clients se sont inscrits cette semaine ?"
- "List the customers subscribed to the newsletter"
