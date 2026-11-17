# DHL Express (MyDHL API) — order fulfillment

Rates, booking, tracking and proof of delivery for orders shipped with DHL; Cabinet is the first app on it. Plan and decisions live in `.planning/cabinet/features/dhl-fulfillment/` (design.md, tasks.md, deployment.md).

## How an order moves

The `delivery` order type, its statuses, the status emails and the 24 hour alert live in Souk (see `src/Domains/Souk/CLAUDE.md`, "Delivery orders"); DHL plugs into them. `verifying` -> `paid` -> `ready-for-dispatch` (staff, package packed) -> `dispatched` (set by the booking) -> `delivered` or `returned` (set by the poller). `DhlHandler::setup()` runs Souk's `SetupDeliveryOrderTypeAction` when a company connects DHL (the command only recreates or repairs it). Setup is the generic `integrationCompany` mutation; there is no DHL-specific one.

DHL owns the booking, the cancel flag and tracking. Connecting DHL creates two default rules (booking and cancel flag) and, through Souk's `EnsureDeliveryOrderNoticeRuleAction`, the shared status notice rule. The notice rule is not a DHL default rule, so disconnecting DHL does not remove it:

- **Booking** is `Activities/CreateDhlShipmentActivity`: `STATUS_TRANSITION` on `Order` with condition `to_status == ready-for-dispatch`. The activity re-checks the status as a backup. It stores `tracking_client_id` and the `tracking_url` custom field before moving the order to `dispatched`, so Souk's shipped email carries them. The booking sends no email itself.
- **Cancel after booking** is `Activities/FlagCancelledDhlShipmentActivity`: `to_status == cancelled` on a booked shipment flips the booking state to `manual_review` and emails the owner. MyDHL has no void endpoint, staff cancel the pickup in MyDHL.
- **Tracking** is polled hourly by `kanvas:dhl-poll-tracking` (MyDHL has no webhooks). It overwrites the `dhl_tracking` custom field with the last response and lets the latest mapped event code drive the transition (`DhlTrackingEventEnum`). Unmapped codes only update the snapshot.

## Foot-guns

- **Booking is reserve-then-call, under a per-order lock, only while the order is still `ready-for-dispatch`.** The workflow carries the transition, not the current state: a cancel that lands before the queued job runs must not book. `dhl_booking_state = pending` is written before `/shipments` (a double booking is a double charge). A DHL 4xx releases the reservation; a 5xx or timeout keeps it, fails the workflow and emails the owner to check MyDHL, because `executeIntegration` does not rethrow and nothing retries on its own. A later run that finds `pending` without a tracking number fails the same way instead of booking twice.
- **A failed label or invoice upload never blocks the dispatch.** The AWB is already paid; the error is reported and the order still moves to `dispatched` and the customer gets the shipped email.
- **The `ready-for-dispatch` notice is skipped by Souk once the order moved on** (the booking moved it to `dispatched`), so a queued notice never goes out after the shipped email. Only that pair is skipped.
- **The booking replays the quote.** The quote stamps `product_code` and `signature_code` into `metadata.shipping.booking_meta`; the booking sends exactly those. If the company's signature service changed since, it fails instead of re-deriving.
- **The signature service is sent on `/rates` and `/shipments`.** Sending it only at booking makes every quote low.
- **Planned times are in the pickup's local time** (company timezone, else the origin country's). DHL compares `plannedShippingDateAndTime` against the pickup close time as local time, so a UTC time is rejected every afternoon. After the close time or on a weekend the booking plans the next weekday at 09:00.
- **Each DHL location has its own booking cutoff, earlier than the close time and not exposed by the API.** A `5001` rejection (past the cutoff) is rebooked once for the next weekday; every other 4xx fails with DHL's `additionalDetails` in the message.
- **DHL has no postal codes in the DR.** Origin and consignee postal codes are optional and sent as `""`; `/rates` and `/shipments` accept that.
- **Unpriced products are skipped.** `/rates` also returns products like `7` EXPRESS EASY and `C` MEDICAL EXPRESS at price 0 with no currency.
- **A booked AWB is not trackable until DHL scans it**: `/tracking` answers 404, which the poller treats as no events.
- **International lanes need customs data on every product, never a fallback.** A lane is international when the destination country differs from `dhl_origin.country_code`. `supports()` is false for it while any cart product lacks a required `CustomsAttributeEnum` attribute (`customs-description`, `manufacturer-country`; `hs-code` is optional) or its variant weight, so a customer is never charged for a shipment DHL cannot book; the booking re-checks and names the product. International sends `isCustomsDeclarable: true`, which makes `/rates` answer the dutiable product codes (`P`, `Y`, `M`) instead of `D`, `T`, `L`, plus the `exportDeclaration` and a DHL-generated commercial invoice. `supports()` is also false when a parcel has no dimensions (`shipping_default_box_cm` app setting fills them).
- **The poller also revisits `delivered` orders with no stored proof** (`dhl_epod_stored_at` empty) because the proof can lag the `OK` scan; it stops 30 days after `shipped_date`.
- **The activities need the DHL integration ACTIVE in the company default region**, like every `executeIntegration` activity.
- **No client is cached in a static**: `Client` is built per call from company settings (Octane).
- Region restriction lives in `Souk/Shipping/Restrictions/CheckShippingRestrictionsAction` (product attribute `shipping-destinations`, `DO,US-FL`), enforced once in `BuildShipmentRequestAction`.
