<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Jobs;

use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromReceiptActionBase;
use Kanvas\Connectors\InAppPurchase\Concerns\FindsStoreSubscription;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\RevenueCatSubscriptionState;
use Kanvas\Connectors\InAppPurchase\Enums\RevenueCatEventTypeEnum;
use Kanvas\Connectors\InAppPurchase\Enums\StoreProviderEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;
use Kanvas\Connectors\InAppPurchase\Services\RevenueCatService;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Laravel\Cashier\Subscription as CashierSubscription;
use Override;

/**
 * RevenueCat webhook (v1.0). With the app's REVENUECAT_SECRET_KEY + REVENUECAT_PROJECT_ID the event is
 * only a signal: status and ends_at come from REST API v2 (the customer's subscriptions), so the event
 * type never decides the outcome, and a failed call throws and leaves the row alone. Without them the
 * event's own fields are trusted.
 */
#[WorkflowAction]
class ProcessRevenueCatWebhookJob extends ProcessWebhookJob
{
    use FindsStoreSubscription;

    public const string AUTHORIZATION_CONFIG_KEY = 'authorization';
    // stripe_id is unique across every app and the Apple/Google paths store raw store ids.
    public const string LINEAGE_PREFIX = 'rc:';
    private const string ANONYMOUS_ID_PREFIX = '$RCAnonymousID';
    private const int LOCK_SECONDS = 60;
    private const int LOCK_WAIT_SECONDS = 15;
    private const string SOURCE_API = 'revenuecat_api';
    private const string SOURCE_EVENT = 'event';

    /**
     * RevenueCat sends the configured string verbatim as the Authorization header. No secret on the
     * receiver means every request is refused.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = $receiver->configuration[self::AUTHORIZATION_CONFIG_KEY] ?? null;
        $presented = $request->header('Authorization');

        return is_string($secret)
            && $secret !== ''
            && is_string($presented)
            && hash_equals($secret, $presented);
    }

    #[Override]
    public function execute(): array
    {
        /** @var array $payload */
        $payload = $this->webhookRequest->payload;
        $event = $payload['event'] ?? null;

        if (! is_array($event)) {
            return ['message' => 'Missing event', 'status' => 'skipped'];
        }

        $type = RevenueCatEventTypeEnum::tryFrom((string) ($event['type'] ?? ''));

        if ($type === RevenueCatEventTypeEnum::TEST) {
            return ['message' => 'Test event received', 'event_type' => $type->value];
        }

        if ($type === null || ! $type->changesSubscription()) {
            return [
                'message' => 'RevenueCat event does not change a subscription, skipping',
                'event_type' => $event['type'] ?? null,
                'status' => 'skipped',
            ];
        }

        if ($type === RevenueCatEventTypeEnum::TRANSFER) {
            return $this->processTransfer($event);
        }

        $user = $this->findAppUsers($this->customerIds($event))->first();
        $productId = (string) ($event['product_id'] ?? '');

        if (! $user instanceof Users) {
            return [
                'message' => 'No user of this app matches the RevenueCat customer',
                'event_type' => $type->value,
                'app_user_id' => $event['app_user_id'] ?? null,
                'status' => 'skipped',
            ];
        }

        $lineageId = $this->lineageId($event, $user);

        if ($lineageId === null || $productId === '') {
            return [
                'message' => 'RevenueCat event has no transaction or product',
                'event_type' => $type->value,
                'user_uuid' => $user->uuid,
                'status' => 'skipped',
            ];
        }

        return $this->withLineageLock(
            $lineageId,
            fn (): array => $this->syncLineage(
                $event,
                $type,
                $user,
                $lineageId,
                $productId
            )
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function syncLineage(
        array $event,
        RevenueCatEventTypeEnum $type,
        Users $user,
        string $lineageId,
        string $productId
    ): array {
        $subscription = $this->findStoreSubscription($lineageId);
        $result = [
            'event_type' => $type->value,
            'event_id' => $event['id'] ?? null,
            'user_uuid' => $user->uuid,
            'product_id' => $productId,
            'stripe_id' => $lineageId,
        ];

        if (! $this->isOwnedBy($subscription, $user)) {
            return ['message' => 'This purchase belongs to another account', 'status' => 'rejected'] + $result;
        }

        $revenueCat = $this->revenueCat();
        $source = $revenueCat->hasApiAccess() ? self::SOURCE_API : self::SOURCE_EVENT;

        if ($source === self::SOURCE_EVENT && $this->isStaleEvent($this->customerFor($user), $lineageId, $event)) {
            return [
                'message' => 'Duplicate or older RevenueCat event, current access left unchanged',
                'new_status' => $subscription?->stripe_status,
                'ends_at' => $subscription?->ends_at?->toDateTimeString(),
                'source' => $source,
            ] + $result;
        }

        $state = $source === self::SOURCE_API
            ? $revenueCat->getSubscriptionState($user->uuid, $event)
            : RevenueCatSubscriptionState::fromEvent($event, $type);

        if (! $state instanceof RevenueCatSubscriptionState) {
            return [
                'message' => 'RevenueCat reports no expiring subscription for the product, nothing written',
                'status' => 'skipped',
                'source' => $source,
            ] + $result;
        }

        $subscription = $this->writeSubscription(
            $user,
            $subscription,
            $lineageId,
            $productId,
            $state,
            $event
        );

        return [
            'message' => 'RevenueCat webhook processed',
            'new_status' => $subscription->stripe_status,
            'ends_at' => $subscription->ends_at?->toDateTimeString(),
            'source' => $source,
        ] + $result;
    }

    /**
     * A transfer moves the purchases of the transferred_from customers to the transferred_to one and
     * names no lineage, so every RevenueCat row the old owners hold in this app moves (the customer row
     * scopes it to the app). A row ends instead when the new owner is not a user of this app, or the API
     * does not list its product on the new owner.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function processTransfer(array $event): array
    {
        $newOwner = $this->findAppUsers((array) ($event['transferred_to'] ?? []))->first();
        $previousOwnerIds = Users::query()
            ->whereIn('uuid', $this->kanvasUuids((array) ($event['transferred_from'] ?? [])))
            ->pluck('id');
        $lineageId = $this->lineageId($event, user: null);

        $customerIds = AppsStripeCustomer::query()
            ->where('apps_id', $this->receiver->app->getId())
            ->where('companies_id', 0)
            ->where('provider', StoreProviderEnum::REVENUECAT->value)
            ->whereIn('users_id', $previousOwnerIds)
            ->pluck('id');

        $subscriptions = CashierSubscription::query()
            ->whereIn('apps_stripe_customer_id', $customerIds)
            ->when($lineageId !== null, fn (Builder $query) => $query->where('stripe_id', $lineageId))
            ->get();

        $revenueCat = $this->revenueCat();
        $source = $revenueCat->hasApiAccess() ? self::SOURCE_API : self::SOURCE_EVENT;
        $moved = [];
        $ended = [];

        foreach ($subscriptions as $subscription) {
            $stripeId = (string) $subscription->stripe_id;
            $productId = (string) $subscription->stripe_price;
            $state = $newOwner instanceof Users && $source === self::SOURCE_API
                ? $revenueCat->getSubscriptionState($newOwner->uuid, [
                    'product_id' => $productId,
                    'store' => $event['store'] ?? null,
                    'environment' => $event['environment'] ?? null,
                    'original_transaction_id' => Str::after($stripeId, self::LINEAGE_PREFIX),
                ])
                : null;

            if ($newOwner instanceof Users && ($source === self::SOURCE_EVENT || $state !== null)) {
                $this->withLineageLock($stripeId, fn (): CashierSubscription => $this->writeSubscription(
                    $newOwner,
                    $subscription,
                    $stripeId,
                    $productId,
                    $state,
                    $event
                ));
                $moved[] = $stripeId;

                continue;
            }

            $this->withLineageLock($stripeId, fn (): CashierSubscription => $this->endSubscription($subscription, $event));
            $ended[] = $stripeId;
        }

        return [
            'message' => $subscriptions->isEmpty()
                ? 'No RevenueCat subscription of this app was held by the transferred_from customers'
                : 'RevenueCat transfer processed',
            'event_type' => RevenueCatEventTypeEnum::TRANSFER->value,
            'event_id' => $event['id'] ?? null,
            'user_uuid' => $newOwner?->uuid,
            'moved' => $moved,
            'ended' => $ended,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function endSubscription(CashierSubscription $subscription, array $event): CashierSubscription
    {
        $transferredAt = $this->eventTime($event);

        if ($subscription->ends_at === null || $subscription->ends_at->greaterThan($transferredAt)) {
            $subscription->ends_at = $transferredAt;
        }
        $subscription->stripe_status = SubscriptionStatusEnum::EXPIRED->value;
        $subscription->saveOrFail();

        return $subscription;
    }

    /**
     * A null state re-owns the row and leaves its access as it is: an event-mode transfer carries no expiry.
     *
     * @param array<string, mixed> $event
     */
    private function writeSubscription(
        Users $user,
        ?CashierSubscription $subscription,
        string $lineageId,
        string $productId,
        ?RevenueCatSubscriptionState $state,
        array $event
    ): CashierSubscription {
        $customer = $this->customerFor($user);
        $config = $customer->config ?? [];
        $lastEvent = $config['events'][$lineageId] ?? [];
        $isNewerEvent = (int) ($event['event_timestamp_ms'] ?? 0) >= (int) ($lastEvent['timestamp_ms'] ?? 0);

        $customer->stripe_id = $lineageId;
        $customer->config = [
            'environment' => Str::trimToNull($state?->environment) ?? ($config['environment'] ?? null),
            'store' => Str::trimToNull($state?->store) ?? ($config['store'] ?? null),
            'product_id' => $productId,
            'app_user_id' => $user->uuid,
            'events' => [
                $lineageId => $isNewerEvent
                    ? ['id' => $event['id'] ?? null, 'timestamp_ms' => (int) ($event['event_timestamp_ms'] ?? 0)]
                    : $lastEvent,
            ] + ($config['events'] ?? []),
        ];

        $subscription ??= new CashierSubscription();

        return DB::connection($customer->getConnectionName())->transaction(
            function () use ($customer, $subscription, $lineageId, $productId, $state): CashierSubscription {
                $customer->saveOrFail();

                $subscription->apps_stripe_customer_id = $customer->getId();
                $subscription->type = StoreProviderEnum::REVENUECAT->value . ':' . $productId;
                $subscription->stripe_id = $lineageId;
                $subscription->stripe_price = $productId;
                $subscription->quantity ??= 1;
                if ($state instanceof RevenueCatSubscriptionState) {
                    $subscription->stripe_status = $state->status()->value;
                    $subscription->ends_at = $state->endsAt();
                    $subscription->trial_ends_at = $state->trialEndsAt();
                }
                $subscription->saveOrFail();

                return $subscription;
            }
        );
    }

    /**
     * An orphaned row has no account to protect, so it may be adopted; one held by another user or app is
     * refused rather than re-pointed.
     */
    private function isOwnedBy(?CashierSubscription $subscription, Users $user): bool
    {
        $owner = $subscription?->owner;

        if (! $owner instanceof AppsStripeCustomer) {
            return true;
        }

        return (int) $owner->users_id === $user->getId()
            && (int) $owner->apps_id === $this->receiver->app->getId()
            && $owner->provider === StoreProviderEnum::REVENUECAT->value;
    }

    private function customerFor(Users $user): AppsStripeCustomer
    {
        return AppsStripeCustomer::firstOrNew([
            'apps_id' => $this->receiver->app->getId(),
            'companies_id' => 0,
            'users_id' => $user->getId(),
            'provider' => StoreProviderEnum::REVENUECAT->value,
        ]);
    }

    /**
     * RevenueCat retries a delivery it did not see answered and may deliver events out of order, so
     * without the API as the truth an event older than the last one applied must not roll the row back.
     *
     * @param array<string, mixed> $event
     */
    private function isStaleEvent(AppsStripeCustomer $customer, string $lineageId, array $event): bool
    {
        $lastEvent = $customer->config['events'][$lineageId] ?? [];

        if ($lastEvent === []) {
            return false;
        }

        if (($event['id'] ?? null) !== null && ($lastEvent['id'] ?? null) === $event['id']) {
            return true;
        }

        return (int) ($event['event_timestamp_ms'] ?? 0) < (int) ($lastEvent['timestamp_ms'] ?? 0);
    }

    /**
     * @param array<string, mixed> $event
     * @return string[]
     */
    private function customerIds(array $event): array
    {
        return [
            $event['app_user_id'] ?? null,
            $event['original_app_user_id'] ?? null,
            ...(array) ($event['aliases'] ?? []),
        ];
    }

    /**
     * Ordered as the ids were given, so app_user_id wins over an alias.
     *
     * @param array<int, mixed> $ids
     * @return Collection<int, Users>
     */
    private function findAppUsers(array $ids): Collection
    {
        $uuids = $this->kanvasUuids($ids);

        if ($uuids === []) {
            return new Collection();
        }

        return UsersRepository::findAppUsersByUuids($uuids, $this->receiver->app)
            ->sortBy(fn (Users $user): int => (int) array_search($user->uuid, $uuids, true))
            ->values();
    }

    /**
     * @param array<int, mixed> $ids
     * @return string[]
     */
    private function kanvasUuids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            fn (mixed $id): bool => is_string($id) && $id !== '' && ! str_starts_with($id, self::ANONYMOUS_ID_PREFIX)
        )));
    }

    /**
     * A promotional grant may come with no store transaction, so its lineage is the customer's grant of
     * that product: a later grant of the same product extends the same row.
     *
     * @param array<string, mixed> $event
     */
    private function lineageId(array $event, ?Users $user): ?string
    {
        $transactionId = $event['original_transaction_id'] ?? $event['transaction_id'] ?? null;

        if (is_scalar($transactionId) && (string) $transactionId !== '') {
            return self::LINEAGE_PREFIX . $transactionId;
        }

        $isPromotional = strtolower((string) ($event['store'] ?? '')) === RevenueCatService::STORE_PROMOTIONAL;
        $productId = (string) ($event['product_id'] ?? '');

        return $isPromotional && $user instanceof Users && $productId !== ''
            ? self::LINEAGE_PREFIX . RevenueCatService::STORE_PROMOTIONAL . ':' . $user->uuid . ':' . $productId
            : null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventTime(array $event): Carbon
    {
        $timestampMs = $event['event_timestamp_ms'] ?? null;

        return is_numeric($timestampMs) ? Carbon::createFromTimestampMs((int) $timestampMs) : now();
    }

    /**
     * RevenueCat retries any delivery it did not see answered, so two copies of one event can race on the
     * insert of the unique stripe_id.
     *
     * @template T
     * @param callable(): T $sync
     * @return T
     */
    private function withLineageLock(string $lineageId, callable $sync): mixed
    {
        return Cache::lock(CreateOrderFromReceiptActionBase::storePurchaseLockName($lineageId), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $sync);
    }

    private function revenueCat(): RevenueCatService
    {
        return app(RevenueCatService::class, ['app' => $this->receiver->app]);
    }
}
