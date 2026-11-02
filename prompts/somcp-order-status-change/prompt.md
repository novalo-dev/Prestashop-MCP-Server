# Changing the status of an order

Changing a status is not a database update. Depending on the status reached it
emails the customer, moves stock, issues an invoice or records a refund. None
of it can be undone by calling again.

## Tools

- `somcp_ping`
- `somcp_list_order_statuses`
- `somcp_create_order_status`
- `somcp_list_return_statuses`
- `somcp_list_carriers`
- `somcp_create_return_status`
- `somcp_update_return_status`
- `somcp_get_order`
- `somcp_set_order_status`
- `somcp_set_order_tracking`
- `somcp_add_order_message`
- `somcp_set_order_note`
- `somcp_add_order_payment`

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
   Not `somcp_list_return_statuses`: those are the steps of a merchandise
   return, not of an order, and their identifiers are never a `status_id`.
3. Pick the target status by its **role**, not by its name. `shipped`,
   `delivered`, `refunded`, `canceled`, `payment_accepted`. The name is
   translated and renameable; the role is not.
4. Check what that status does before acting: `sends_email`,
   `attaches_invoice_pdf`, `paid`, `shipped`. Tell the merchant what will
   happen, in one line, before you do it.
5. Call `somcp_set_order_status` with `status_id` and the order.
6. Report what came back: the previous status, the new one, and whether the
   customer was emailed.

## Tracking number

When the merchant gives the parcel number, call `somcp_set_order_tracking`
with the order and `tracking_number`. It is its own call, not part of the
status change: it never moves the order to shipped, and
`somcp_set_order_status` never sets a number.

- A new number **emails the customer** the in transit message. Say so before
  calling. Sending the number the shipment already has changes nothing and
  sends nothing.
- An order with several shipments needs `id_order_carrier`: the call is
  refused with the list, so ask the merchant which parcel it is.
- A reference shared by several orders (a split cart) is refused with their
  identifiers: ask the merchant which order the parcel belongs to, then call
  again with `id_order`.
- An empty number (`""`) removes it without any email. Only when the merchant
  asked for that.
- The carrier of the shipment is never changed here.
- To know which carrier a parcel goes with, or what tracking link the
  customer will get, read `somcp_list_carriers`: its `tracking_url` holds `@`
  where the number goes. An order keeps the `id_carrier` it was placed with,
  which may be an older row of the carrier: match it on `id_reference`.

When the merchant says an order has left with its number, set the number
first, then the shipped status: the shipped email then carries the tracking
link.

## Writing to the customer

When the customer should hear about the order in the merchant's own words -
typically after a status change, to explain a delay or what happens next -
call `somcp_add_order_message` with the order, `message` and
`display_to_customer`.

- `display_to_customer: true` **emails the customer** and shows the message in
  their account. Say so before calling.
- `display_to_customer: false` keeps it private to the shop: nobody is emailed.
- There is no default: if the merchant has not said whether the customer
  should see it, ask.
- Plain text, 1200 characters at most. A status email the shop already sends
  does not need a message repeating it.

## A note for the shop only

When the merchant wants to remember something about the order without telling
the customer - a call to make, a parcel to check - call `somcp_set_order_note`
with the order and `note`. Nobody is emailed.

- The note **replaces** the current one: there is no append. To add a line,
  read `order.note` with `somcp_get_order` first and send the whole new text.
- An empty note (`""`) clears it. Only when the merchant asked for that.
- A message the customer should read is `somcp_add_order_message`, not a note.

## A payment received

When the merchant says money arrived for an order - a bank wire, a cheque, a
part payment - record the payment first with `somcp_add_order_payment` (the
order, `amount`, `payment_method`, and `currency` or `payment_date` when they
are not the order's currency or today), then change the status if the merchant
wants it.

- Recording a payment **never changes the status**. Read `remaining` in the
  answer: tell the merchant whether the order is now fully paid, and offer the
  paid status only then, through the workflow above.
- When the order has an invoice, `id_order_invoice` is required: take it from
  `documents.invoices` of `somcp_get_order`, never guess it.
- A refund is not a negative payment: it is refused.

## A new order status

When the merchant wants a status the shop does not have yet, create it
with `somcp_create_order_status`: the name in the languages being filled,
the boxes that say what reaching it sets off, and a colour, an icon or an
email template when they ask for them.

- A new status is only needed when none of the existing ones plays the
  role. List them first with `somcp_list_order_statuses` and match on the
  role, as in the workflow above: most "we need a new status" requests
  end at an existing one under another name.
- A name another order status already has in the same language is
  refused: two statuses with one label could not be told apart.
- Creating one changes no order: nothing is emailed and nothing moves
  until an order is actually set to it.
- Not to be retried: if the answer is lost, read
  `somcp_list_order_statuses` and look for the name before anything else.

## A new return status

When the merchant wants a step a return does not have yet, create it with
`somcp_create_return_status`: the name in the languages being filled, and
a colour.

- Read `somcp_list_return_statuses` first. A name another return status
  already has in the same language is refused: two steps with one label
  could not be told apart.
- It is a shop setting, not a change to any order or return: nothing is
  emailed and nothing moves. A return status is never an order status, so
  creating one never gives an order a new `status_id`.
- Not to be retried: if the answer is lost, read
  `somcp_list_return_statuses` and look for the name before anything else.

## Changing a return status

When the merchant wants a step renamed or recoloured, change it with
`somcp_update_return_status`: the identifier from
`somcp_list_return_statuses`, and only what changes.

- Only what is given changes. A language left out of `languages` keeps its
  text, so to rename one language, pass `languages` with that one alone.
- Every return status can be changed, the five the shop starts with
  included. Renaming a step renames it on every return standing on it:
  say so before calling.
- The same call twice is safe to read, not to repeat blindly: the second
  answers `changed: false`. If an answer is lost, read
  `somcp_list_return_statuses` and compare before calling again.

## Deleting a return status

When the merchant wants a step gone, delete it with
`somcp_delete_return_status` - only after the merchant has confirmed it
by name: read `somcp_list_return_statuses`, name the exact status, and
let them say yes to that one before calling. Deleting is final.

- The five statuses the shop starts with cannot be deleted, and a status
  a return still stands on is refused with the number of returns. Report
  a refusal as it comes back; do not work around it.
- If an answer is lost, read `somcp_list_return_statuses`: a status that
  is no longer listed is deleted. Do not call again.

## Several orders

One call per order. Never present a batch as one action.

The merchant is asked to confirm each destructive call, and that is the point:
one order is one question, thirty orders as a single call would be one click
and thirty emails. Loop, and report each result separately - including the ones
that failed.

## If an answer is lost

**Do not repeat the call.** Call `somcp_get_order` and read the current status.
It also gives each shipment with its tracking number, the messages of the
order, its note, its payments and the documents it produced, so you can see
whether an invoice or a delivery slip came with the change, or whether a
message, a note or a payment was saved. A message or a payment is never refused
as a repeat: a message sent twice reaches the customer twice, a payment
recorded twice is counted twice.

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
