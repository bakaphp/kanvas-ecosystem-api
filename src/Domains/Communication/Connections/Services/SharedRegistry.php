<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedDependent;
use Kanvas\Communication\Connections\Models\SharedRegistration;

/**
 * Vendor resources several connections share are owned by the vendor account and resource, not by
 * whichever integration row provisioned them first. Every change to one happens under one lock on
 * that key, so two rows provisioning at once — or a teardown racing a new owner — serialise.
 */
class SharedRegistry
{
    private const int LOCK_SECONDS = 120;
    private const int WAIT_SECONDS = 30;

    /**
     * @param callable(?SharedRegistration): string $ensure reads the vendor back and sets our value if
     *        it is missing or drifted; returns the value now registered, or throws
     *        SetupBlockedException when the vendor holds someone else's
     */
    public function attach(
        Connection $connection,
        string $account,
        string $type,
        string $key,
        callable $ensure
    ): SharedRegistration {
        $lockKey = SharedRegistration::lockKeyFor($connection->provider, $account, $type . ':' . $key);

        return Cache::lock($lockKey, self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($connection, $account, $type, $key, $ensure) {
            $registration = SharedRegistration::query()
                ->where('provider', $connection->provider)
                ->where('provider_account_key', $account)
                ->where('resource_type', $type)
                ->where('resource_key', $key)
                ->first();

            $active = $registration?->status === SharedRegistration::ACTIVE ? $registration : null;
            $value = $ensure($active);

            $registration ??= new SharedRegistration([
                'provider' => $connection->provider,
                'provider_account_key' => $account,
                'resource_type' => $type,
                'resource_key' => $key,
            ]);
            $registration->forceFill([
                'registered_value' => $value,
                'status' => SharedRegistration::ACTIVE,
                'released_at' => null,
            ])->saveOrFail();

            SharedDependent::query()->updateOrCreate(
                ['registration_id' => $registration->getId(), 'connection_id' => $connection->getId()],
                [
                    'apps_id' => $connection->apps_id,
                    'companies_id' => $connection->companies_id,
                    'integrations_company_id' => $connection->integrations_company_id,
                    'released_at' => null,
                ]
            );

            return $registration;
        });
    }

    /**
     * The connection stops depending on every shared resource. The vendor side is left for the
     * release pass, which also waits for this connection's outstanding deliveries.
     */
    public function detach(Connection $connection): void
    {
        SharedDependent::query()
            ->where('connection_id', $connection->getId())
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    /**
     * The guard every provider applies before touching a shared resource: empty, or already ours, or
     * pointing at one of our own receivers, may be set. Anything else belongs to someone else.
     *
     * @throws SetupBlockedException
     */
    public static function assertSettable(?string $current, string $ours, string $resource): void
    {
        if (! self::isOursOrFree($current, $ours)) {
            throw new SetupBlockedException(
                'foreign_registration',
                $resource . ' already sends to ' . $current . '; it is not replaced automatically.'
            );
        }
    }

    public static function isOursOrFree(?string $current, string $ours): bool
    {
        $current = trim((string) $current);

        return $current === ''
            || $current === $ours
            || str_starts_with($current, rtrim((string) config('app.url'), '/') . '/v1/receiver/');
    }
}
