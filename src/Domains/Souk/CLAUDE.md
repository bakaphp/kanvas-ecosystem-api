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

## Delivery orders

The `delivery` order type is carrier-neutral: Cabinet ships some orders with DHL and others with INPOSDOM rate cards, and both walk the same statuses. It is set up per app by `SetupDeliveryOrderTypeAction` (`kanvas:souk-setup-delivery-order-type {app_id?} {--company=}`, also run by `DhlHandler::setup()`), never by a connector.

`verifying` (default, card hold) -> `confirmed` (staff change the status; the held payment is captured first) -> `ready-for-dispatch` (staff, packed) -> `dispatched` -> `delivered` or `returned`. Exits: `out-of-stock`, `cancelled`.

- **Capture on confirm.** Setup writes `capture_on_status: ["confirmed"]` into the `delivery` order type config (`OrderTypes::capturesOnStatus()`). `TransitionOrderStateAction` runs `CaptureOrderPaymentsOnTransitionAction` before its transaction for any such status: every `authorized` payment is captured through `ProcessorFactory`; a failed capture refuses the transition with the processor message and leaves the order in `verifying`; an order with only `paid` payments goes ahead without a processor call; an order with no authorized or paid payment is refused. If a later payment fails, earlier ones stay captured (the message lists them). Rerunning setup renames a legacy `paid` status to `confirmed` in place.

- **Who moves it.** For a DHL order the booking moves it to `dispatched` and the hourly poller to `delivered`/`returned`. For any other carrier staff move it by hand with the normal status mutation; set `tracking_client_id` and the `tracking_url` custom field first if the shipped email should show them.
- **Customer and owner emails** come from `NotifyDeliveryOrderStatusActivity`, a `STATUS_TRANSITION` rule named `Delivery order status notice`. The customer gets `user-order-<status>` at every status, the owner `owner-order-<status>` at placed, delivered, returned and cancelled. `dispatched` carries `tracking_number` and `tracking_url` (either may be null). The `ready-for-dispatch` notice is skipped when the order is no longer in that status.
- **The rule is not created by the setup action.** `EnsureDeliveryOrderNoticeRuleAction` creates it: `DhlHandler::setup()` calls it when a company connects DHL, and `kanvas:souk-setup-delivery-order-type --company=<id>` calls it for every other company (needs `kanvas:workflow-sync-actions` run first). It is not one of DHL's default rules, so disconnecting DHL leaves it alive; running both paths leaves one rule.
- **Unverified alert.** `kanvas:souk-alert-unverified-delivery-orders` (hourly) emails the owner `owner-order-unverified` once for each order still in `verifying` after 24 hours, marked by the `unverified_alerted_at` custom field. It runs for every app with a `delivery` order type, with or without a connector.
