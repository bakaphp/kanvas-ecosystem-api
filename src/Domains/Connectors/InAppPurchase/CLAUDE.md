# In-App Purchase — App Store, Google Play and RevenueCat subscriptions

Loads when work touches `src/Domains/Connectors/InAppPurchase/`. A subscription row
(`subscriptions`, Cashier) is written from five entry points: the purchase mutation for Apple
(`CreateOrderFromAppleReceiptAction`, receipt or StoreKit 2 signed transaction) and Google
(`CreateOrderFromGoogleReceiptAction`), the two store webhooks (`Jobs/Process*SubscriptionWebhookJob`),
and the RevenueCat webhook (`Jobs/ProcessRevenueCatWebhookJob`) for apps that buy through RevenueCat,
which validates the receipt itself. A RevenueCat row has `provider = revenuecat`,
`stripe_id = rc:<original_transaction_id>`, `type = revenuecat:<product>`, so `me.currentSubscription`
reads it like any store row. Receivers are created by `kanvas:iap-webhook:setup {app} {company} {user}
[--provider=revenuecat] [--rotate-revenuecat-secret]`.
Earlier fixes landed in one entry point and not the others, which reopened the same bugs. Every rule
below has **one** implementation; a new entry point calls it rather than restating it.

## Invariants

| Rule | Why | Where |
|---|---|---|
| **Apple: a stale transaction writes nothing.** Stale = expires before the stored `ends_at`, or — once a canceled row's access has ended — purchased on or before that `ends_at` (no purchase date counts as stale). | Apple delivers a lineage late and out of order to both the mutation and the webhook. A refund cuts `ends_at` below the period's expiry, so expiry alone would let a retried `DID_RENEW` reopen it. A purchase after the refund still reactivates. | `Services/StaleTransactionGuard` |
| **Who moves `ends_at`.** Apple: a non-stale transaction sets it to its expiry (forward only, by the rule above); a refund/revoke sets it back to the revocation date. Google: always Play's reported expiry, either direction. | Play is the source of truth per token; Apple's notification is not, so it is filtered. | Apple action + job, `GooglePlayBillingService::applyPurchaseState` |
| **Apple preference changes never move `ends_at` and never change status**, except `DID_CHANGE_RENEWAL_STATUS` (off → `canceled`, on → `active`), and only while access has not ended. | A price increase or a declined refund says nothing about the paid period. | `AppleNotificationTypeEnum::toSubscriptionStatus` (null = no change) |
| **Google: the notification is never trusted when Play is reachable**, neither its type nor its product id. The job reads the token's state from Play and picks the line item by the stored `stripe_price`. | RTDN is unauthenticated. | `ProcessGoogleSubscriptionWebhookJob` |
| **Google: status is Play's `subscriptionState`** (`purchases.subscriptionsv2`). Grace period stays `active`, account hold is `past_due`, and a revoked or refunded purchase comes back `expired` with the expiry moved to the revocation. | Play reports the state itself, so nothing is inferred from cancellation fields, which Play also fills when a user only turns off auto-renew. In grace Play is still retrying the payment and has already extended the expiry. | `GooglePlayBillingService::statusOf` |
| **Google: the token must be for the product.** A purchase is accepted only when Play's line items include the requested product. | v2 looks a purchase up by token alone, so Play does not reject a mismatch: a cheaper plan's token would otherwise buy a dearer one. | `GooglePlayBillingService::lineItemOf` |
| **No expiry, no subscription.** A Play state without expiry is refused; an Apple signed transaction without `expiresDate` or of another type is refused; a RevenueCat event or API entry without expiry (e.g. `NON_RENEWING_PURCHASE`) writes nothing. | A null `ends_at` is a subscription that never ends. | `GooglePlayBillingService::expiryOf`, signed flow, `RevenueCatSubscriptionState` |
| **Ownership.** A store id already owned by another user or app is refused, never re-pointed. An orphaned row may be adopted. | `subscriptions.stripe_id` is unique across every app, so the row cannot be shared, and a scoped query would only turn the refusal into a unique-index 500. | `findOwnedCashierSubscription`, `Concerns/FindsStoreSubscription`, `ProcessRevenueCatWebhookJob::isOwnedBy` |
| **Apple bundle check, fail closed** on signed transactions and server notifications: the payload's `bundleId` must equal `APPLE_BUNDLE_ID`; unset means reject. Sandbox is accepted (TestFlight, App Review). | Apple signs every app's payloads with the same chain. | `AppleSignedTransactionVerifier::assertBundle` |
| **Legacy receipts: bundle check when configured.** With `APPLE_BUNDLE_ID` set, the receipt's `bundle_id` must match; unset is accepted and `report()`ed. Switch to `assertBundle` once Sentry stops reporting it. | `verifyReceipt` answers valid for any genuine receipt, and a primary shared secret covers every app on the account. Failing closed would stop every live app's purchases on deploy. | `AppleSignedTransactionVerifier::assertReceiptBundle` |
| **RevenueCat: the event is never trusted when the API is reachable.** With `REVENUECAT_SECRET_KEY` the job reads `GET /v1/subscribers/{uuid}` → `subscriptions[product_id]` and the event type only says that something changed; a failed call throws and writes nothing. Without the key the event's fields are trusted, and an event older than (or the same id as) the last one applied to the lineage is ignored (`config.events[stripe_id]` on the customer row). | RevenueCat retries deliveries and does not order them. | `ProcessRevenueCatWebhookJob`, `Services/RevenueCatService` |
| **RevenueCat: fail-closed Authorization.** The request's `Authorization` header must equal the receiver's `configuration.authorization` (`hash_equals`); a receiver without one refuses everything. | It is the only proof the request came from RevenueCat. | `ProcessRevenueCatWebhookJob::authenticateRequest` |
| **RevenueCat: the customer is a Kanvas user uuid.** `app_user_id`, `original_app_user_id` and `aliases` are matched by `users.uuid` among the app's users; `$RCAnonymousID…` ids never match. No user → skipped, nothing written. | The app logs RevenueCat in with the user's uuid. | `ProcessRevenueCatWebhookJob::findAppUsers` |
| **RevenueCat status.** Refund → `expired`, `ends_at` = refund. Billing issue: `active` with `ends_at` extended to the grace period's end, `past_due` once that has passed. Auto-renew off → `canceled` until expiry. Paused → `paused`. Otherwise `active`, or `expired` once past `ends_at`. `trial_ends_at` = expiry on a TRIAL period. | Same meaning as the Google path (grace keeps access, past_due is the only status Cashier treats as inactive). | `DataTransferObject/RevenueCatSubscriptionState` |
| **RevenueCat transfer** (the one exception to the ownership rule): every RevenueCat row the `transferred_from` users hold in the app moves to the `transferred_to` user; with the API, only if it lists the product on the new owner. A row ends (`expired`, `ends_at` = transfer time) when the new owner is not a user of the app or the API does not list it. | RevenueCat moves the purchases with the store account and the event names no lineage. | `ProcessRevenueCatWebhookJob::processTransfer` |
| **One purchase at a time.** Find-or-create for a store purchase id runs under `Cache::lock`; the loser gets the winner's order. The RevenueCat job takes the same lock per lineage. | StoreKit 2 delivers one transaction to `purchase()` and `Transaction.updates` at once; RevenueCat retries a delivery it did not see answered. | `CreateOrderFromReceiptActionBase::withStorePurchaseLock`, `storePurchaseLockName` |

Subscriptions use the library's v2 client (`Domain\Purchase\SubscriptionService`). One-time products still go
through the `laravel-purchases` `Product` facade, which sits on the library's deprecated product client;
`Domain\Purchase\ProductService` is its replacement.

Statuses are `Enums/SubscriptionStatusEnum`; providers are `Enums/StoreProviderEnum`. Cashier only treats
`past_due` as inactive — every other status keeps access until `ends_at`, so `ends_at` is the real gate.

## App settings

| Setting | Needed for |
|---|---|
| `APPLE_BUNDLE_ID` | every Apple path; without it signed transactions and notifications are rejected, and legacy receipts pass unchecked with a Sentry report |
| `APPLE_PAYMENT_SHARED_SECRET` | Apple receipt (StoreKit 1) verification |
| `google-payment-client-config` | Play Developer API service account; without it the Google webhook falls back to trusting the notification |
| `GOOGLE_PLAY_PACKAGE_NAME` | every Play API call |
| `REVENUECAT_SECRET_KEY` | RevenueCat secret API key (`sk_…`, private setting); without it the RevenueCat webhook falls back to trusting the event |
| `IAP_ALLOW_XCODE_STOREKIT_TESTING` | local and factory test apps only: accepts Xcode-signed StoreKit transactions. Never on a production app |
