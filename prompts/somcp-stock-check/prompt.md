# Checking and adjusting stock

Start from what the merchant gave you: a named product, or a question about what
runs low. Never answer a stock question from memory or from an earlier answer:
quantities move with every order.

## Tools

- `somcp_ping`
- `somcp_get_stock`
- `somcp_search_products`

## Know which shop you are in

Call `somcp_ping` **once**, the first time you touch stock in a conversation,
and name the shop in your first answer. Once is enough.

One connection reaches one shop. On a multistore installation, shops may share
their stock or keep their own: a quantity is the one of the shop that answered,
which may not be the one the merchant has in mind.

## Workflow

1. **A named product.** Find it with `somcp_search_products` (name, reference
   or EAN13), then read its quantities with `somcp_get_stock`. Several matches:
   show them and let the merchant pick. Never choose one for them.
2. **What runs low.** Use `somcp_list_low_stock` when the shop offers it, with
   the threshold the merchant gave, or each product's own alert level when they
   gave none. Without it, say that this shop cannot list low stock yet, and offer
   to check named products one by one.
3. Report the quantities as the tool gives them, and say which one you mean.

## Quantities

- `quantity` is what the storefront can still sell. `physical_quantity` is what
  is on the shelves, and `reserved_quantity` what orders not shipped yet already
  hold. "How many do I have" usually means the physical quantity; "can I still
  sell it" means `quantity`. Say which one you are giving.
- A product with combinations has a quantity **per combination**, and the
  product line is their total. Name the combination by its attributes
  (Size: M, Color: Blue), not by its identifier.
- `low_stock_threshold` is the alert level the merchant set. `null` means no
  alert is set, not an alert at zero.

## Changing quantities

When the shop offers `somcp_set_stock_quantity`:

- One product or one combination per call. A product with combinations is
  changed combination by combination.
- Prefer a `quantity` (the new level) to a `delta` (a movement). A `delta`
  moves stock and is **never retried**: on a lost answer, read
  `somcp_get_stock` first and check whether the movement went through before
  doing anything else.
- Tell the merchant the quantity before and after, in one line.

## Do not use when

- The merchant asks about prices, texts or categories - that is the catalogue.
- The merchant asks what sold, or how much. That is statistics, read from the
  orders.

## Good triggers

- "Combien reste-t-il de T-shirts en taille M ?"
- "What is the stock of the product with reference demo_1?"
- "Quels produits sont presque en rupture ?"
- "Is the blue mug still available to order?"
