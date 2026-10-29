# Finding and reading orders

Finding is one tool and reading is another. Going straight to the detail of an
order nobody has identified means picking one of twelve and being wrong.

## Tools

- `somcp_ping`
- `somcp_search_orders`
- `somcp_get_order`
- `somcp_list_order_statuses`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you touch orders in a conversation,
and name the shop in your first answer. Once is enough - not before every
question.

One connection reaches one shop. A merchant running several will read "0
orders" as being about the shop they have in mind, which may not be the one
that answered. The currency and the time zone come from there too, so "this
week" and "€ 240" mean nothing until you know which shop said them.

## Workflow

1. Work out whether the merchant has already named one order. A reference like
   `XKBKNABJK` or an identifier is one order; "Dupont's March orders" is not.
2. If a status is part of what they asked - "awaiting payment", "shipped" -
   call `somcp_list_order_statuses` **first** and take the identifier from
   there. Status identifiers differ from one shop to the next.
3. Call `somcp_search_orders` with the filters that match what was asked, and
   nothing more. An unasked-for filter silently hides orders. Besides
   reference, customer, dates, status, amount and payment method, it filters
   on `new_client` ("first orders", "returning customers"), `delivery_country`
   as an ISO code ("orders shipped to Belgium" is `BE`) and `company`.
4. Read `total_count`, not the length of the list. Twenty results out of three
   hundred is not "twenty orders".
5. Call `somcp_get_order` only for an order the merchant named, or one they
   chose from the list you showed them.

## Choosing a status without guessing

Each status in `somcp_list_order_statuses` carries a `roles` array - `shipped`,
`delivered`, `payment_accepted`, `refunded`, and others. **Match on the role,
never on the name.** The name is translated and the merchant can rename it; the
role is what the shop itself uses.

A status with an empty `roles` is one the merchant created. It has no meaning
you can infer - ask rather than assume.

## What to report

For a list: the reference, the date, the customer, the amount paid with its
currency, and the status. The delivery country, the company and whether the
customer was new when they matter to the question. Not the addresses, not the phone numbers. The
merchant asked what came in, not for their address book.

For one order: answer the question that was asked. A merchant asking whether an
order was paid does not want the product lines read out.

Always say the currency with an amount, and whether a total is with or without
tax - the field names say which.

## Do not use when

- The merchant wants to **change** an order. That is
  `somcp-order-status-change`, and it asks before acting.
- The merchant asks about a cart that was never paid for. A cart is not an
  order and these tools do not see it.
- The merchant asks about marketplace customer-service conversations. Those
  belong to whichever module publishes them.

## Good triggers

- "Montre-moi les commandes de la semaine"
- "What did order XKBKNABJK contain?"
- "Combien de commandes en attente de paiement ?"
- "Find Dupont's orders"
