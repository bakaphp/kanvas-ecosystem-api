<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\RevenueCatSubscriptionState;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * RevenueCat REST API v2 for an app, configured by its REVENUECAT_SECRET_KEY (a v2 secret key; v1 keys
 * are refused by v2 and v2 keys by v1) and REVENUECAT_PROJECT_ID settings.
 */
class RevenueCatService
{
    private const string API_URL = 'https://api.revenuecat.com/v2/projects/';
    private const int TIMEOUT_SECONDS = 15;
    private const int PAGE_LIMIT = 100;
    private const int MAX_PAGES = 10;
    public const string STORE_PROMOTIONAL = 'promotional';

    /** @var array<string, string> */
    private array $storeIdentifiers = [];

    public function __construct(
        protected readonly AppInterface $app
    ) {
    }

    public function hasApiAccess(): bool
    {
        return $this->secretKey() !== '' && $this->projectId() !== '';
    }

    /**
     * The lineage an event belongs to. v2 lists subscriptions by RevenueCat's own product id, so the
     * event's store product is matched against each product's store_identifier; a promotional grant has
     * no product and is matched by store. Null when the customer holds nothing for that product.
     *
     * @param array<string, mixed> $event the webhook event, or one built from a stored row
     * @throws RequestException|ValidationException
     */
    public function getSubscriptionState(string $customerId, array $event): ?RevenueCatSubscriptionState
    {
        $productId = (string) ($event['product_id'] ?? '');
        $store = strtolower((string) ($event['store'] ?? ''));
        $isPromotional = $store === self::STORE_PROMOTIONAL;

        $candidates = array_values(array_filter(
            $this->listSubscriptions($customerId, strtolower((string) ($event['environment'] ?? ''))),
            fn (array $item): bool => ($store === '' || ($item['store'] ?? null) === $store)
                && ($isPromotional || $this->storeIdentifier((string) ($item['product_id'] ?? '')) === $productId)
        ));

        $subscription = $this->pickLineage($candidates, $event);

        if ($subscription === null) {
            return $isPromotional ? $this->promotionalGrantState($customerId, $productId) : null;
        }

        return RevenueCatSubscriptionState::fromV2Subscription(
            $productId,
            $subscription,
            $this->accessExtensionFor($customerId, $subscription)
        );
    }

    /**
     * store_subscription_identifier is the latest transaction (App Store) or renewal order (Play), so it
     * names the lineage only until the next renewal; otherwise the one that still gives access wins.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function pickLineage(array $candidates, array $event): ?array
    {
        $transactionIds = array_filter([
            (string) ($event['transaction_id'] ?? ''),
            (string) ($event['original_transaction_id'] ?? ''),
        ]);

        foreach ($candidates as $candidate) {
            if (in_array((string) ($candidate['store_subscription_identifier'] ?? ''), $transactionIds, true)) {
                return $candidate;
            }
        }

        usort($candidates, fn (array $a, array $b): int => [! empty($b['gives_access']), self::periodEnd($b)]
            <=> [! empty($a['gives_access']), self::periodEnd($a)]);

        return $candidates[0] ?? null;
    }

    /**
     * Grace is reported as a status, not as a later end, so a subscription that still gives access past
     * its period end is extended to its customer's latest entitlement expiry.
     *
     * @param array<string, mixed> $subscription
     * @throws RequestException|ValidationException
     */
    private function accessExtensionFor(string $customerId, array $subscription): ?Carbon
    {
        $periodEnd = self::periodEnd($subscription);

        if (empty($subscription['gives_access']) || ($periodEnd !== 0 && $periodEnd > now()->getTimestampMs())) {
            return null;
        }

        return $this->latestEntitlementExpiry($customerId);
    }

    /**
     * @throws RequestException|ValidationException
     */
    private function promotionalGrantState(string $customerId, string $productId): ?RevenueCatSubscriptionState
    {
        $expiresAt = $this->latestEntitlementExpiry($customerId);

        if (! $expiresAt instanceof Carbon) {
            return null;
        }

        return new RevenueCatSubscriptionState(
            productId: $productId,
            expiresAt: $expiresAt,
            store: strtoupper(self::STORE_PROMOTIONAL)
        );
    }

    /**
     * @throws RequestException|ValidationException
     */
    private function latestEntitlementExpiry(string $customerId): ?Carbon
    {
        $expiries = array_filter(array_map(
            fn (array $entitlement): mixed => $entitlement['expires_at'] ?? null,
            $this->listAll($this->customerPath($customerId) . '/active_entitlements')
        ), is_numeric(...));

        return $expiries === [] ? null : Carbon::createFromTimestampMs((int) max($expiries));
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws RequestException|ValidationException
     */
    private function listSubscriptions(string $customerId, string $environment): array
    {
        return $this->listAll(
            $this->customerPath($customerId) . '/subscriptions',
            $environment !== '' ? ['environment' => $environment] : []
        );
    }

    /**
     * @throws RequestException|ValidationException
     */
    private function storeIdentifier(string $revenueCatProductId): ?string
    {
        if ($revenueCatProductId === '') {
            return null;
        }

        $path = rawurlencode($this->projectId()) . '/products/' . rawurlencode($revenueCatProductId);

        return $this->storeIdentifiers[$revenueCatProductId] ??= (string) ($this->get($path)['store_identifier'] ?? '');
    }

    /**
     * @param array<string, scalar> $query
     * @return array<int, array<string, mixed>>
     * @throws RequestException|ValidationException
     */
    private function listAll(string $path, array $query = []): array
    {
        $items = [];
        $query['limit'] = self::PAGE_LIMIT;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get($path, $query);
            $pageItems = array_values(array_filter((array) ($response['items'] ?? []), is_array(...)));
            $items = [...$items, ...$pageItems];

            if (empty($response['next_page']) || $pageItems === []) {
                break;
            }
            $query['starting_after'] = (string) end($pageItems)['id'];
        }

        return $items;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     * @throws RequestException|ValidationException
     */
    private function get(string $path, array $query = []): array
    {
        if (! $this->hasApiAccess()) {
            throw new ValidationException('RevenueCat API access needs REVENUECAT_SECRET_KEY and REVENUECAT_PROJECT_ID');
        }

        $body = Http::withToken($this->secretKey())
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::API_URL . $path, $query)
            ->throw()
            ->json();

        return is_array($body) ? $body : [];
    }

    private function customerPath(string $customerId): string
    {
        return rawurlencode($this->projectId()) . '/customers/' . rawurlencode($customerId);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private static function periodEnd(array $subscription): int
    {
        return (int) ($subscription['ends_at'] ?? $subscription['current_period_ends_at'] ?? 0);
    }

    protected function secretKey(): string
    {
        return (string) $this->app->get(ConfigurationEnum::REVENUECAT_SECRET_KEY->value);
    }

    protected function projectId(): string
    {
        return (string) $this->app->get(ConfigurationEnum::REVENUECAT_PROJECT_ID->value);
    }
}
