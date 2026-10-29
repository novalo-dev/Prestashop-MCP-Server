# Changing the status of an order

Changing a status is not a database update. Depending on the status reached it
emails the customer, moves stock, issues an invoice or records a refund. None
of it can be undone by calling again.

## Tools

- `somcp_ping`
- `somcp_list_order_statuses`
- `somcp_get_order`
- `somcp_set_order_status`

## Know which shop you are in

Call `somcp_ping` **once**, before the first change in a conversation, and name
the shop when you announce what you are about to do. Once is enough.

One connection reaches one shop. A merchant running several may be picturing
the other one, and here that mistake is not a wrong figure on a screen - it is
an email sent to someone else's customer.

## Workflow

1. Identify **one** order. A reference or an identifier. If the merchant named
   several, handle them one at a time - see below.
2. Call `somcp_list_order_statuses`. Always, even if you called it a moment
   ago in this conversation: the merchant may have changed a status since.
3. Pick the target status by its **role**, not by its name. `shipped`,
   `delivered`, `refunded`, `canceled`, `payment_accepted`. The name is
   translated and renameable; the role is not.
4. Check what that status does before acting: `sends_email`,
   `attaches_invoice_pdf`, `paid`, `shipped`. Tell the merchant what will
   happen, in one line, before you do it.
5. Call `somcp_set_order_status` with `status_id` and the order.
6. Report what came back: the previous status, the new one, and whether the
   customer was emailed.

## Several orders

One call per order. Never present a batch as one action.

The merchant is asked to confirm each destructive call, and that is the point:
one order is one question, thirty orders as a single call would be one click
and thirty emails. Loop, and report each result separately - including the ones
that failed.

## If an answer is lost

**Do not repeat the call.** Call `somcp_get_order` and read the current status.
It also gives each shipment with its tracking number and the documents the
order produced, so you can see whether an invoice or a delivery slip came with
the change.

A repeated call is the likeliest way this ends with a customer receiving two
emails. The shop refuses a status the order already has, which catches most of
it, but a lost answer is exactly the case where you cannot tell whether the
first call landed.

## Reading the answer

- `changed: false` with a reason means nothing happened and nothing was sent.
  The order already had that status. Say so; do not try another way.
- `warning` set means the status **did** change but the customer email failed.
  The change is recorded. Do not retry it.
- `email_sent` says whether the customer was actually emailed.

## Do not use when

- The merchant only wants to know a status. That is `somcp-order-lookup`.
- The merchant wants to cancel or refund and the shop has no status with the
  matching role. Say what roles exist rather than picking the nearest name.
- The merchant has not named which orders. Ask. Never apply a status to the
  result of a search nobody reviewed.

## Good triggers

- "Passe cette commande en expédié"
- "Mark order XKBKNABJK as delivered"
- "Annule la commande de Dupont"
- "Ces trois commandes sont parties ce matin"
