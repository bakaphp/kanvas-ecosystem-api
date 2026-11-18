# In-App Purchase — App Store and Google Play subscriptions

Loads when work touches `src/Domains/Connectors/InAppPurchase/`. A subscription row
(`subscriptions`, Cashier) is written from four entry points: the purchase mutation for Apple
(`CreateOrderFromAppleReceiptAction`, receipt or StoreKit 2 signed transaction) and Google
(`CreateOrderFromGoogleReceiptAction`), and the two webhooks (`Jobs/Process*SubscriptionWebhookJob`).
Earlier fixes landed in one entry point and not the others, which reopened the same bugs. Every rule
below has **one** implementation; a new entry point calls it rather than restating it.

## Invariants

| Rule | Why | Where |
|---|---|---|
| **Apple: a stale transaction writes nothing.** Stale = expires before the stored `ends_at`, or — once a canceled row's access has ended — purchased on or before that `ends_at` (no purchase date counts as stale). | Apple delivers a lineage late and out of order to both the mutation and the webhook. A refund cuts `ends_at` below the period's expiry, so expiry alone would let a retried `DID_RENEW` reopen it. A purchase after the refund still reactivates. | `Services/StaleTransactionGuard` |
| **Who moves `ends_at`.** Apple: a non-stale transaction sets it to its expiry (forward only, by the rule above); a refund/revoke sets it back to the revocation date. Google: always Play's reported expiry, either direction. | Play is the source of truth per token; Apple's notification is not, so it is filtered. | Apple action + job, `GooglePlayBillingService::applyPurchaseState` |
| **Apple preference changes never move `ends_at` and never change status**, except `DID_CHANGE_RENEWAL_STATUS` (off → `canceled`, on → `active`), and only while access has not ended. | A price increase or a declined refund says nothing about the paid period. | `AppleNotificationTypeEnum::toSubscriptionStatus` (null = no change) |
| **Google: the notification type is never trusted when Play is reachable.** A `SUBSCRIPTION_REVOKED` ends access only if Play's expiry has passed. | Play fills `cancelReason` when a user merely turns off auto-renew, so cancellation fields are not a revocation. RTDN is unauthenticated. | `ProcessGoogleSubscriptionWebhookJob` |
| **No expiry, no subscription.** A Play state without expiry is refused; an Apple signed transaction without `expiresDate` or of another type is refused. | A null `ends_at` is a subscription that never ends. | `GooglePlayBillingService::expiryOf`, signed flow |
| **Ownership.** A store id already owned by another user or app is refused, never re-pointed. An orphaned row may be adopted. | `subscriptions.stripe_id` is unique across every app, so the row cannot be shared, and a scoped query would only turn the refusal into a unique-index 500. | `findOwnedCashierSubscription`, `Concerns/FindsStoreSubscription` |
| **Apple bundle check, fail closed** on transactions and server notifications: the payload's `bundleId` must equal `APPLE_BUNDLE_ID`; unset means reject. Sandbox is accepted (TestFlight, App Review). | Apple signs every app's payloads with the same chain. | `AppleSignedTransactionVerifier::assertBundle` |
| **One purchase at a time.** Find-or-create for a store purchase id runs under `Cache::lock`; the loser gets the winner's order. | StoreKit 2 delivers one transaction to `purchase()` and `Transaction.updates` at once. | `CreateOrderFromReceiptActionBase::withStorePurchaseLock` |

Statuses are `Enums/SubscriptionStatusEnum`; providers are `Enums/StoreProviderEnum`. Cashier only treats
`past_due` as inactive — every other status keeps access until `ends_at`, so `ends_at` is the real gate.

## App settings

| Setting | Needed for |
|---|---|
| `APPLE_BUNDLE_ID` | every Apple path; without it Apple transactions and notifications are rejected |
| `APPLE_PAYMENT_SHARED_SECRET` | Apple receipt (StoreKit 1) verification |
| `google-payment-client-config` | Play Developer API service account; without it the Google webhook falls back to trusting the notification |
| `GOOGLE_PLAY_PACKAGE_NAME` | every Play API call |
| `IAP_ALLOW_XCODE_STOREKIT_TESTING` | local and factory test apps only: accepts Xcode-signed StoreKit transactions. Never on a production app |
