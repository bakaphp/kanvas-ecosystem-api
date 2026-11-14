# DHL Express (MyDHL API) — order fulfillment

Rates, booking, tracking and proof of delivery for orders shipped with DHL; Cabinet is the first app on it. Plan and decisions live in `.planning/cabinet/features/dhl-fulfillment/` (design.md, tasks.md, deployment.md).

## How an order moves

`verifying` (default, card hold) -> `paid` (existing `capturePayment`) -> `ready-for-dispatch` (staff, package packed) -> `dispatched` (set by the booking) -> `delivered` or `returned` (set by the poller). Exits: `out-of-stock`, `cancelled`. Built by `SetupDhlOrderTypeAction`; the order type is `delivery` (`OrderTypeEnum`), created by `DhlHandler::setup()` when a company connects DHL (the command only recreates or repairs it) and slugs are dash-cased. Setup is the generic `integrationCompany` mutation; there is no DHL-specific one.

- **Booking** is `Activities/CreateDhlShipmentActivity`, a default rule created when the company connects DHL: `STATUS_TRANSITION` on `Order` with condition `to_status == ready-for-dispatch`. The activity re-checks the status as a backup.
- **Customer and owner emails** come from `Activities/NotifyOrderStatusActivity` (second default rule): the customer gets `user-order-<status>` at every status but `dispatched` (sent by the booking, with the tracking number), the owner `owner-order-<status>` when an order is placed, delivered, returned or cancelled. Subjects live on the template rows. A cancel after booking flips the booking state to `manual_review`: MyDHL has no void endpoint, staff cancel the pickup in MyDHL.
- **Tracking** is polled hourly by `kanvas:dhl-poll-tracking` (MyDHL has no webhooks). It overwrites the `dhl_tracking` custom field with the last response and lets the latest mapped event code drive the transition (`DhlTrackingEventEnum`). Unmapped codes only update the snapshot.

## Foot-guns

- **`delivery` is a carrier-neutral order type that lives here for now.** Orders quoted with another carrier (INPOSDOM rate cards) walk the same statuses and the booking skips them, but the status emails and the 24 hour alert only run for companies with DHL connected, and nothing moves a non-DHL order to `dispatched`/`delivered`. Moving the pipeline to Souk is planned as its own change.
- **Booking is reserve-then-call.** `dhl_booking_state = pending` is written before `/shipments`; a queue retry that finds `pending` without a tracking number fails the workflow and emails the owner instead of booking twice (a double booking is a double charge). A DHL 4xx releases the reservation, a 5xx or timeout keeps it.
- **The booking replays the quote.** The quote stamps `product_code` and `signature_code` into `metadata.shipping.booking_meta`; the booking sends exactly those. If the company's signature service changed since, it fails instead of re-deriving.
- **The signature service is sent on `/rates` and `/shipments`.** Sending it only at booking makes every quote low.
- **Planned times are in the pickup's local time** (company timezone, else the origin country's). DHL compares `plannedShippingDateAndTime` against the pickup close time as local time, so a UTC time is rejected every afternoon. After the close time or on a weekend the booking plans the next weekday at 09:00.
- **Each DHL location has its own booking cutoff, earlier than the close time and not exposed by the API.** A `5001` rejection (past the cutoff) is rebooked once for the next weekday; every other 4xx fails with DHL's `additionalDetails` in the message.
- **DHL has no postal codes in the DR.** Origin and consignee postal codes are optional and sent as `""`; `/rates` and `/shipments` accept that.
- **Unpriced products are skipped.** `/rates` also returns products like `7` EXPRESS EASY and `C` MEDICAL EXPRESS at price 0 with no currency.
- **A booked AWB is not trackable until DHL scans it**: `/tracking` answers 404, which the poller treats as no events.
- **Domestic only.** `DhlShippingProvider::supports()` is false when the destination country differs from the configured origin country, and when a parcel has no dimensions (`shipping_default_box_cm` app setting fills them).
- **The poller also revisits `delivered` orders with no stored proof** (`dhl_epod_stored_at` empty) because the proof can lag the `OK` scan; it stops 30 days after `shipped_date`.
- **The activities need the DHL integration ACTIVE in the company default region**, like every `executeIntegration` activity.
- **No client is cached in a static**: `Client` is built per call from company settings (Octane).
- Region restriction lives in `Souk/Shipping/Restrictions/CheckShippingRestrictionsAction` (product attribute `shipping-destinations`, `DO,US-FL`), enforced once in `BuildShipmentRequestAction`.
