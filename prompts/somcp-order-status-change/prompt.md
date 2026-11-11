# Changing the status of an order

Changing a status is not a database update. Depending on the status reached it
emails the customer, moves stock, issues an invoice or records a refund. None
of it can be undone by calling again.

## Tools

- `somcp_ping`
- `somcp_list_order_statuses`
- `somcp_create_order_status`
- `somcp_delete_order_status`
- `somcp_list_return_statuses`
- `somcp_list_carriers`
- `somcp_create_return_status`
- `somcp_update_return_status`
- `somcp_search_orders`
- `somcp_get_order`
- `somcp_set_order_status`
- `somcp_set_order_tracking`
- `somcp_add_order_message`
- `somcp_set_order_note`
- `somcp_add_order_payment`
- `somcp_issue_partial_refund`
- `somcp_issue_standard_refund`
- `somcp_return_order_products`
- `somcp_cancel_order_products`
- `somcp_add_order_product`
- `somcp_update_order_product`
- `somcp_delete_order_product`
- `somcp_generate_order_invoice`
- `somcp_add_order_discount`
- `somcp_delete_order_discount`
- `somcp_create_order`

## Know which shop you are in

Call `somcp_ping` **once**, before the first change in a conversation, and name
the shop when you announce what you are about to do. Once is enough.

One connection reaches one shop. A merchant running several may be picturing
the other one, and here that mistake is not a wrong figure on a screen - it is
an email sent to someone else's customer.

## Workflow

1. Identify **one** order. A reference or an identifier. If the merchant named
   several, handle them one at a time - see below. When the merchant gives a
   reference, find the order first with `somcp_search_orders`: a split cart
   gives several orders one reference, and a reference shared by several is
   refused. Then confirm with the merchant which one, and call with
   `id_order`.
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

## An invoice

An order paid by bank wire may need its invoice before the status changes.
Generate it with `somcp_generate_order_invoice` (the order only), then change
the status if the merchant wants the customer to receive it: generating an
invoice emails nobody, and only a status that attaches the invoice sends it.

- `generated: false` means the order already had one: it is in the answer,
  nothing was made twice.
- A shop with invoices turned off is refused: say where the merchant turns
  them on, never work around it.

## A note on an invoice

`somcp_set_invoice_note` sets the note printed on one invoice (the
`id_order_invoice`, from `documents.invoices` of `somcp_get_order`), as **Add
note** / **Edit note** on the invoice line of the **Documents** tab does.

- This note is **on the invoice PDF the customer receives**, unlike the order
  note (`somcp_set_order_note`), which the shop alone reads.
- The note **replaces** the current one: send the whole text. An empty note
  (`""`) clears it, only when the merchant asked.
- `changed: false` means the invoice already had that note; nothing was
  written.

## A partial refund

When the merchant wants to pay back part of a paid order - some units of a
line, a part of a line's price, part of the shipping - use
`somcp_issue_partial_refund`. A partial refund is money paid back: say the
amounts and what will be generated (a credit slip, a voucher or both), and
wait for the merchant's go-ahead before the call.

- Read the order with `somcp_get_order` first: each line is named by its
  `products[].id_order_detail`, and `quantity_refunded` says what was already
  refunded.
- Give each amount in the tax mode of the order: `amount_tax_excluded` or
  `amount_tax_included`. A wrong one is refused with the right field named.
- A credit slip is generated unless the merchant says otherwise; a voucher
  only when asked. `restock` only matters once the order is delivered.
- Never repeat the call on a lost answer: check `quantity_refunded` and
  `documents.credit_slips` with `somcp_get_order`.

## A standard refund

A standard refund pays back whole lines of a paid order not shipped yet and
puts them back in stock: use `somcp_issue_standard_refund`. Confirm the lines
and what will be generated first (a credit slip, a voucher or both), and wait
for the merchant's go-ahead before the call.

- Each line is named by its `products[].id_order_detail` from
  `somcp_get_order`, with a whole quantity: the line's price is refunded, no
  amount is given. The shipping only with `refund_shipping`.
- It needs merchandise returns on (**Customer Service > Merchandise
  Returns**). When they are off, or for a chosen amount, use
  `somcp_issue_partial_refund`; a delivered order goes to
  `somcp_return_order_products`.
- Never repeat the call on a lost answer: check `quantity_refunded` and
  `documents.credit_slips` with `somcp_get_order`.

## Products sent back

Products sent back by the customer after delivery are recorded and refunded
with `somcp_return_order_products`. Ask first whether they go back in stock
(`restock`, no default), then confirm the lines and what will be generated (a
credit slip, a voucher or both), and wait for the merchant's go-ahead before
the call.

- Each line is named by its `products[].id_order_detail` from
  `somcp_get_order`, with a whole quantity: the line's price is refunded. The
  shipping only with `refund_shipping`.
- It needs merchandise returns on (**Customer Service > Merchandise
  Returns**) and a delivered order; an order not delivered yet goes to
  `somcp_issue_standard_refund`.
- Never repeat the call on a lost answer: check `quantity_refunded` and
  `documents.credit_slips` with `somcp_get_order`.

## A product added to an order

A product added to an order that is not shipped yet goes through
`somcp_add_order_product`. Adding a product changes what the customer owes:
say the new total before adding it, and wait for the merchant's go-ahead.

- The product comes from `somcp_search_products`, the combination from
  `somcp_get_product`; a product already in the order is refused: its line
  is changed instead, never added twice.
- Left out, the prices are those of the product for this customer; on an
  invoiced order, the line goes to a new invoice unless `id_order_invoice`
  names one.
- Never repeat the call on a lost answer: check `products` with
  `somcp_get_order`.

## A product line edited

The unit price, the quantity or the invoice of a line of an order not
delivered is changed with `somcp_update_order_product`. Changing a price or a
quantity changes what the customer owes: say the new total first, and wait for
the merchant's go-ahead.

- The line comes from `products[].id_order_detail` of `somcp_get_order`. Give
  only the fields to change; the others keep their value.
- A quantity of 0 is refused: to take the line off, use
  `somcp_delete_order_product`; to add one, `somcp_add_order_product`.
- The values are absolute, not a delta. Never repeat the call on a lost
  answer: check `products` with `somcp_get_order`.

## A product line removed

A line of an order not delivered is taken off with
`somcp_delete_order_product`, as Delete on the order page does. Removing a
line is not a refund: say what the customer owes once it is gone, say that
nothing is refunded, and wait for the merchant's go-ahead.

- The line comes from `products[].id_order_detail` of `somcp_get_order`.
- The last line of an order is refused: to drop the whole order, cancel it
  with `somcp_set_order_status`.
- Units the customer paid for and will not receive go through
  `somcp_cancel_order_products` or `somcp_issue_partial_refund` instead.
- Never repeat the call on a lost answer: check `products` with
  `somcp_get_order`.

## Products taken off an unpaid order

Products removed from an order that is not paid yet go through
`somcp_cancel_order_products`. On an unpaid order, removing products changes
what the customer will pay; when every line goes, the order is cancelled and
the customer emailed. Confirm the lines and quantities, and wait for the
merchant's go-ahead before the call.

- Each line is named by its `products[].id_order_detail` from
  `somcp_get_order`, with a whole quantity.
- It needs merchandise returns on (**Customer Service > Merchandise
  Returns**); when they are off, the whole order can still be cancelled with
  `somcp_set_order_status`. A paid order goes to
  `somcp_issue_standard_refund`, a delivered one to
  `somcp_return_order_products`.
- Never repeat the call on a lost answer: check `products[].quantity` and
  `status` with `somcp_get_order`.

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
- A language of the shop left out of `languages` takes the default
  language's text: say so when only some languages are filled.
- The icon is written once the status exists. An answer whose `icon` says
  `refused` means the status was created without one: never call again for
  it, a second call would be refused on the name.
- An existing order status is changed in the back office, never by
  creating a second one next to it.
- Creating one changes no order: nothing is emailed and nothing moves
  until an order is actually set to it.
- Not to be retried: if the answer is lost, read
  `somcp_list_order_statuses` and look for the name before anything else.

## Deleting an order status

When the merchant wants an order status gone, delete it with
`somcp_delete_order_status` - only after the merchant has confirmed it by
name: read `somcp_list_order_statuses`, name the exact status, and let them
say yes to that one before calling.

- The orders that have it keep it: deleting a status takes it off the list
  and off the choices of `somcp_set_order_status`, but every order already
  on it keeps it in its history. `orders_with_it` says how many that is.
- The statuses of PrestaShop and of modules (`built_in: true`) cannot be
  deleted, and are refused by name. Report a refusal as it comes back; do
  not work around it.
- If an answer is lost, read `somcp_list_order_statuses`: a status that is
  no longer listed is deleted. Do not call again.

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

## A discount on the order

`somcp_add_order_discount` adds a discount to an order already placed, as
**Add a discount** on the order page does: a `percent`, an
`amount_tax_included` or `free_shipping`, on the whole order or on one
`id_order_invoice`. It changes what the customer owes.

- A discount **lowers the total**. Say the new total before adding it, not
  after: the answer gives `total_tax_included` and `discounts_tax_included`,
  but the merchant decides on the figure first.
- `name` is one plain text, the line the customer reads on the invoice.
- A percentage is 1 to 100; an amount is at most the order total (the refusal
  gives that total). `free_shipping` takes no value.
- **Do not repeat the call** on a lost answer: two identical discounts are both
  applied. Check `discounts` with `somcp_get_order`, and remove an extra one
  with `somcp_delete_order_discount`.

`somcp_delete_order_discount` removes one discount from an order, as the delete
action of the discounts table does. It takes the `id_order_cart_rule` of
`discounts[]` in `somcp_get_order`, or of the answer of
`somcp_add_order_discount`; never guess it.

- Removing a discount **raises what the customer owes**. Say the new total
  first, then remove it.
- A discount that gave a free gift product also takes one unit off that line
  (`gift_line_updated`).
- A discount already removed answers `deleted: false` and nothing changes.
  On a lost answer, read `discounts` with `somcp_get_order`: a discount no
  longer listed was removed. Do not call again.

## An address of the order

`somcp_set_order_address` points the `delivery` or the `invoice` address of an
order to another address of the same customer, as **Select another address** of
the order page does. Take `id_address` from `somcp_get_customer`
(`addresses[].id_address`), or from the list a refused call returns; never
guess it.

- Changing the delivery address **can change the taxes and the total**, because
  the tax follows the country. Say the new total (`total_tax_included` in the
  answer) before the change, not after.
- An address that is not the customer's is refused with the list of the ones
  that are: pick from that list.
- The same address as now answers `changed: false` and nothing happens. On a
  lost answer, read `addresses` with `somcp_get_order` rather than calling
  again.

## The carrier of the order

`somcp_set_order_carrier` changes the carrier of one shipment, as the
**Carrier** field of **Edit shipping details** on the Carriers tab does. Take
`id_carrier` from `somcp_list_carriers`, or from the list a refused call
returns; never guess it. The tracking number stays with
`somcp_set_order_tracking`.

- Changing the carrier **changes the shipping cost and the total**. Say the new
  amount before the change, not after.
- A carrier that does not deliver this order is refused with the carriers that
  do: pick from that list.
- The carrier already in place answers `changed: false` and nothing happens.
  On a lost answer, read `shipping.shipments[].id_carrier` with
  `somcp_get_order` rather than calling again.

## The currency of the order

`somcp_set_order_currency` changes the currency of an order that is not
validated yet, as **Change currency** in the Payment block does. Give the ISO
code (`EUR`, `USD`); a refused call lists the shop's active currencies.

- A currency change **converts every amount of the order at today's rate**.
  Say the new total first, before the change, and suggest checking the
  exchange rates of the shop beforehand.
- A validated order is refused, as on screen: its amounts are final.
- The currency already in place answers `changed: false` and nothing happens.
  On a lost answer, read `currency` with `somcp_get_order` rather than calling
  again.

## Resending a status email

When a customer says they did not receive the email of a status, resend it once
with `somcp_resend_order_status_email` rather than setting the status again:
setting it again would move the order and fire its hooks a second time.

- Take `id_order_history` from `history[].id_order_history` of
  `somcp_get_order`; never guess it.
- A status the shop does not email (`send_email` 0) is refused: nothing is sent,
  so do not try to force it.
- Every call emails the customer and the order records nothing. Call it once; on
  a lost answer, do not repeat it, or the customer is emailed twice.

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

## A new order

When the merchant asks for an order to be placed for a customer - a phone
order, a quote accepted by email - use `somcp_create_order`. It creates a real
order and sends the emails a new order sends for its status.

- Find the customer with `somcp_search_customers` and the address with
  `somcp_get_customer`, the products with `somcp_search_products` and, for a
  product with combinations, the combination with `somcp_get_product`. The
  status comes from `somcp_list_order_statuses`.
- Before the call, show the merchant the lines, the carrier, the total and the
  status, and wait for their yes.
- A carrier or a payment module the shop does not offer is refused with the
  choices: offer them to the merchant rather than picking one.
- Never repeat the call on a lost answer: a second call creates a second
  order. Look for it with `somcp_search_orders` (this customer, today), then
  `somcp_get_order`.
