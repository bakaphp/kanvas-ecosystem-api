# DHL Express (MyDHL API) — cabinet fulfillment

Rates, booking, tracking and proof of delivery for the Cabinet app. Plan and decisions live in `.planning/cabinet/features/dhl-fulfillment/` (design.md, tasks.md, deployment.md).

## How an order moves

`verifying` (default, card hold) -> `paid` (existing `capturePayment`) -> `ready-for-dispatch` (staff, package packed) -> `dispatched` (set by the booking) -> `delivered` or `returned` (set by the poller). Exits: `out-of-stock`, `cancelled`. Built by `kanvas:dhl-setup-cabinet-order {app_id}`; the order type is `cabinet` and slugs are dash-cased.

- **Booking** is `Activities/CreateDhlShipmentActivity`, a default rule created when the company connects DHL: `STATUS_TRANSITION` on `Order` with condition `to_status == ready-for-dispatch`. The activity re-checks the status as a backup.
- **Customer and owner emails** for delivered, returned, out-of-stock and cancelled come from `Activities/NotifyCabinetOrderStatusActivity` (second default rule). A cancel after booking flips the booking state to `manual_review`: MyDHL has no void endpoint, staff cancel the pickup in MyDHL.
- **Tracking** is polled hourly by `kanvas:dhl-poll-tracking` (MyDHL has no webhooks). It overwrites the `dhl_tracking` custom field with the last response and lets the latest mapped event code drive the transition (`DhlTrackingEventEnum`). Unmapped codes only update the snapshot.

## Foot-guns

- **Booking is reserve-then-call.** `dhl_booking_state = pending` is written before `/shipments`; a queue retry that finds `pending` without a tracking number fails the workflow and emails the owner instead of booking twice (a double booking is a double charge). A DHL 4xx releases the reservation, a 5xx or timeout keeps it.
- **The booking replays the quote.** The quote stamps `product_code` and `signature_code` into `metadata.shipping.meta`; the booking sends exactly those. If the company's signature service changed since, it fails instead of re-deriving.
- **The signature service is sent on `/rates` and `/shipments`.** Sending it only at booking makes every quote low.
- **Domestic only.** `DhlShippingProvider::supports()` is false when the destination country differs from the configured origin country, and when a parcel has no dimensions (`shipping_default_box_cm` app setting fills them).
- **The poller also revisits `delivered` orders with no stored proof** (`dhl_epod_stored_at` empty) because the proof can lag the `OK` scan; it stops 30 days after `shipped_date`.
- **The activities need the DHL integration ACTIVE in the company default region**, like every `executeIntegration` activity.
- **No client is cached in a static**: `Client` is built per call from company settings (Octane).
- Region restriction lives in `Souk/Shipping/Restrictions/CheckShippingRestrictionsAction` (product attribute `shipping-destinations`, `DO,US-FL`), enforced once in `BuildShipmentRequestAction`.
