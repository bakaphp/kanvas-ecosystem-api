# Souk — commerce

Loads when work touches `src/Domains/Souk/`.

## Regular ecommerce vs B2B ecommerce: order and invoice

The **order** (`Souk\Orders\Models\Order`) records what the customer asked for: lines, quantities,
shipping, fulfillment. The **invoice** (`Scribe\Invoices\Models\Invoice`) records what the customer owes
for it: amount, due date, balance, paid or not. Whether the invoice exists as its own record depends on
when the money moves.

| | Regular ecommerce (a person buys a computer) | B2B ecommerce (a store buys from its supplier) |
|---|---|---|
| Payment | By card at checkout, same minute as the order | On terms, days or weeks after shipping, by transfer, check or a payment link |
| Invoice | A receipt. Not modelled; the paid order is the whole story | A real document. One order can have several: one per partial shipment, deposit + balance, or a credit note after damaged goods |
| What gets paid | The order | The invoice |
| Who reads what | Customer and warehouse read the order | Buyer, rep and warehouse read the order; both companies' accounting read the invoice |

So a Souk order never carries a balance due, and a Scribe invoice never carries fulfillment. An order
cancelled before shipping has no invoice; a partially shipped order has more than one.

- The link is `invoices.order_id` (`Invoice::order()`, `Order::invoices()`). The two tables live on
  different connections (`accounting` vs `commerce`): no DB-level FK, and `whereHas` across them is
  silently empty — query by id.
- A **payment link for a B2B sale is built from the invoice balance**, not the order
  (`StripePaymentLinkService::generatePaymentLinkFromInvoice`); `generatePaymentLink(Order)` is the
  checkout-style case. After a partial payment or a credit note the invoice balance is what is still owed.
- An invoice issued in an external ERP (NetSuite, Acumatica) is imported through
  `Scribe\Invoices\Actions\ImportInvoiceFromExternalAction` with no journal entry: the ERP is the books.
  A native Kanvas sale goes `CreateInvoiceAction → IssueInvoiceAction`, which posts the JE. Never route
  an imported invoice through the native path (it double-counts revenue).

## B2B company group

Under `USE_B2B_COMPANY_GROUP` an order is created on the app's global company (`B2B_GLOBAL_COMPANY`)
and only later moved to the buyer by `B2BUpdateCompanyOrderActivity`; `Order::buyerCompany()` is the
accessor that knows both states. Anything company-specific at order creation must ask the user, not
`$order->company`. An order imported from an ERP stays on the global company.
