<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\SetupReport;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Setup\Actions\SetupCompanyDefaultsAction;
use Kanvas\Communication\Setup\Jobs\SetupPeopleChannelsJob;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use RuntimeException;
use Throwable;

/**
 * Brings an integration's connections in line with what the tenant selected, and every connection
 * in line with what it needs at the vendor and in Kanvas. Shared by the job a completed integration
 * dispatches, the sweep and the On/Off repair pass, so there is one way to provision.
 *
 * Every step is an ensure — look up, create only if absent, correct drift, record — and a recorded
 * "done" is never a reason to skip one: the vendor is read back each time.
 */
final class EnsureCommunicationSetupAction
{
    private const int LOCK_SECONDS = 300;

    /**
     * Minutes before a failed connection is retried, by attempt; past the last, the sweep keeps
     * retrying at its own pace.
     */
    private const array BACKOFF_MINUTES = [1, 5, 30];

    /**
     * @param bool $respectBackoff false when a person just acted (saved the integration, switched a
     *                             connection on) and is waiting on the result
     */
    public function __construct(
        private readonly Apps $app,
        private readonly IntegrationsCompany $integrationCompany,
        private readonly bool $respectBackoff = false
    ) {
    }

    public function execute(): SetupReport
    {
        $provider = new ProviderRegistry()->forIntegration($this->integrationCompany);

        if ($provider === null) {
            return SetupReport::skipped('not_a_communication_provider');
        }

        if ((int) $this->integrationCompany->apps_id !== $this->app->getId()) {
            return SetupReport::skipped('integration_belongs_to_another_app');
        }

        $lock = Cache::lock(
            "communication:setup:{$this->app->getId()}:{$this->integrationCompany->getId()}",
            self::LOCK_SECONDS
        );

        if (! $lock->get()) {
            return SetupReport::skipped('setup_in_progress');
        }

        try {
            $this->reconcile($provider);

            return $this->provisionAll($provider);
        } finally {
            $lock->release();
        }
    }

    /**
     * Database only, so it can also run inside the transaction that saves the integration: the
     * pending rows it writes are the durable marker the sweep recovers from.
     *
     * @param class-string<CommunicationProvider> $provider
     */
    public function reconcile(string $provider): void
    {
        $desired = $this->desiredAccounts($provider);
        $current = Connection::query()
            ->live()
            ->where('integrations_company_id', $this->integrationCompany->getId())
            ->get()
            ->keyBy('account_key');

        foreach ($desired as $key => $account) {
            $connection = $current->get($key);

            if ($connection === null) {
                $this->createConnection($provider, $account);

                continue;
            }

            if (($connection->config ?? []) !== $account->attributes) {
                $connection->forceFill(['config' => $account->attributes ?: null])->saveOrFail();
            }
        }

        // Not except(): on an Eloquent collection it matches model ids, not these keys.
        foreach ($current->reject(fn (Connection $connection): bool => $desired->has($connection->account_key)) as $connection) {
            new RetireConnectionAction($connection, 'account_removed')->execute();
        }
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     */
    private function provisionAll(string $provider): SetupReport
    {
        $provisioner = app($provider::provisioner());
        $statuses = [];
        $blocked = [];
        $failed = [];

        $connections = Connection::query()
            ->live()
            ->where('integrations_company_id', $this->integrationCompany->getId())
            ->orderBy('account_key')
            ->get();

        foreach ($connections as $connection) {
            if ($this->respectBackoff && $connection->next_setup_at?->isFuture()) {
                $statuses[$connection->account_key] = 'backing_off';

                continue;
            }

            $this->provision($provider, $provisioner, $connection);

            $statuses[$connection->account_key] = $connection->setup_status->value;

            match ($connection->setup_status) {
                SetupStatusEnum::BLOCKED => $blocked[] = $connection->account_key . ': ' . $connection->last_error,
                SetupStatusEnum::FAILED => $failed[] = $connection->account_key . ': ' . $connection->last_error,
                default => null,
            };
        }

        return SetupReport::of($statuses, $blocked, $failed);
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     */
    private function provision(
        string $provider,
        Provisioner $provisioner,
        Connection $connection
    ): void {
        // A ready connection being repaired stays ready, or every send during the repair would
        // fail as not ready.
        if ($connection->setup_status !== SetupStatusEnum::READY) {
            $connection->forceFill([
                'setup_status' => SetupStatusEnum::PROVISIONING,
                'provisioning_started_at' => now(),
            ])->saveOrFail();
        }

        try {
            if (! $this->holdsClaim($connection)) {
                throw new SetupBlockedException(
                    'claimed_by_another_workspace',
                    $connection->account_key . ' is connected to another Kanvas workspace; disconnect it there first.'
                );
            }

            $probeError = $provisioner->probe($this->integrationCompany, $this->app);

            if ($probeError !== null) {
                throw new RuntimeException($probeError);
            }

            $registration = $this->registerWithVendor($provisioner, $connection);
            $this->record($connection, $registration);

            $prerequisite = $provisioner->missingPrerequisite($connection);

            if ($prerequisite !== null) {
                throw new SetupBlockedException($prerequisite);
            }

            new SetupCompanyDefaultsAction($this->app, $connection->company)->execute();
            $this->ensureAutoReply($provider, $provisioner);
            $this->ensureChannelDefaults($provider, $connection);

            $connection->forceFill([
                'setup_status' => SetupStatusEnum::READY,
                'last_error' => null,
                'setup_attempts' => 0,
                'next_setup_at' => null,
            ]);

            // Switched on once, by the first setup that succeeds. After that only the person's
            // own On/Off decides — repair must never turn back on what they turned off.
            $firstReady = $connection->first_ready_at === null;

            if ($firstReady) {
                $connection->first_ready_at = now();
                $connection->enabled = true;
            }

            $connection->saveOrFail();

            // The people a company already has get their channels once, when it first goes live.
            if ($firstReady) {
                SetupPeopleChannelsJob::dispatch($this->app, $connection->company);
            }
        } catch (SetupBlockedException $blocked) {
            $connection->forceFill([
                'setup_status' => SetupStatusEnum::BLOCKED,
                'last_error' => $blocked->reason . ($blocked->getMessage() !== $blocked->reason ? ': ' . $blocked->getMessage() : ''),
            ])->saveOrFail();
        } catch (Throwable $exception) {
            report($exception);

            $attempts = $connection->setup_attempts + 1;
            $connection->forceFill([
                'setup_status' => SetupStatusEnum::FAILED,
                'last_error' => $exception->getMessage(),
                'setup_attempts' => $attempts,
                'next_setup_at' => now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]),
            ])->saveOrFail();
        }
    }

    /**
     * Under the resource lock disconnect cleanup takes too, so a new owner's registration and an
     * old owner's cleanup of the same number never interleave.
     */
    private function registerWithVendor(Provisioner $provisioner, Connection $connection): InboundRegistration
    {
        $inbound = new CommunicationReceivers()->ensure($this->app, $connection->company, $connection->provider);

        return Cache::lock(self::resourceLock($connection->provider, $connection->account_key), self::LOCK_SECONDS)
            ->block(30, fn () => $provisioner->registerInbound($connection, $inbound->getUrl()));
    }

    private function record(Connection $connection, InboundRegistration $registration): void
    {
        $provisioning = $connection->provisioning ?? [];

        foreach ($registration->owned as $resource) {
            $provisioning['owned'][$resource->type . ':' . $resource->key] = $resource->toArray();
        }

        if ($registration->shared !== null) {
            $provisioning['shared'][] = $registration->shared->getId();
            $provisioning['shared'] = array_values(array_unique($provisioning['shared']));
        }

        $connection->provisioning = $provisioning;
        $connection->saveOrFail();
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     */
    private function ensureAutoReply(string $provider, Provisioner $provisioner): void
    {
        $autoReply = $provisioner->autoReply();

        if ($autoReply === null) {
            return;
        }

        foreach (array_keys($provider::transports()) as $channel) {
            $agent = new AutoReplyPolicy()->agentFor(
                $this->app,
                $this->integrationCompany->company,
                ChannelTypeEnum::from($channel)
            );

            if ($agent !== null) {
                new EnsureAutoReplyRuleAction(
                    app: $this->app,
                    company: $this->integrationCompany->company,
                    agent: $agent,
                    channelSlugPattern: $autoReply['channel_slug_pattern'],
                    legacyResponder: $autoReply['legacy_responder']
                )->execute();

                return;
            }
        }
    }

    /**
     * Set once, when a channel has none; an existing default — even a disabled one — is the
     * tenant's choice and is never moved by setup.
     *
     * @param class-string<CommunicationProvider> $provider
     */
    private function ensureChannelDefaults(string $provider, Connection $connection): void
    {
        if (! $this->isPreferred($provider, $connection)) {
            return;
        }

        foreach (array_keys($provider::transports()) as $channel) {
            ChannelDefault::query()->firstOrCreate(
                [
                    'apps_id' => $connection->apps_id,
                    'companies_id' => $connection->companies_id,
                    'channel' => $channel,
                ],
                ['connection_id' => $connection->getId()]
            );
        }
    }

    /**
     * The account the tenant flagged as default, else the lowest key — stable across runs, so two
     * passes never pick differently.
     *
     * @param class-string<CommunicationProvider> $provider
     */
    private function isPreferred(string $provider, Connection $connection): bool
    {
        $accounts = $this->desiredAccounts($provider);
        $flagged = $accounts->first(fn (AccountSelection $account): bool => $account->isDefault);
        $preferred = $flagged?->key ?? $accounts->keys()->sort()->first();

        return $preferred === $connection->account_key;
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     *
     * @return Collection<string, AccountSelection>
     */
    private function desiredAccounts(string $provider): Collection
    {
        $retired = (array) ($this->integrationCompany->config['retired_accounts'] ?? []);

        return collect($provider::accounts($this->integrationCompany))
            ->keyBy(fn (AccountSelection $account): string => $account->key)
            ->except($retired);
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     */
    private function createConnection(string $provider, AccountSelection $account): void
    {
        DB::connection('workflow')->transaction(function () use ($provider, $account) {
            $connection = Connection::query()->create([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->integrationCompany->companies_id,
                'integrations_company_id' => $this->integrationCompany->getId(),
                'provider' => $provider::slug(),
                'account_key' => $account->key,
                'label' => $account->label,
                'config' => $account->attributes ?: null,
                'setup_status' => SetupStatusEnum::PENDING_SETUP,
                'health' => 'unknown',
                'enabled' => false,
                'activation_version' => 1,
            ]);

            $this->holdsClaim($connection);
        });
    }

    /**
     * Inserted with the connection and held through every state up to disconnected, so a number
     * being set up or torn down is owned and a concurrent signup elsewhere is refused, not raced.
     * The unique index decides a race; the loser is blocked until the claim is released.
     */
    private function holdsClaim(Connection $connection): bool
    {
        $claim = InboundClaim::query()
            ->where('provider', $connection->provider)
            ->where('inbound_identity', $connection->account_key)
            ->first();

        if ($claim !== null) {
            return $claim->connection_id === $connection->getId();
        }

        try {
            DB::connection('workflow')->transaction(fn () => InboundClaim::query()->create([
                'provider' => $connection->provider,
                'inbound_identity' => $connection->account_key,
                'connection_id' => $connection->getId(),
                'apps_id' => $connection->apps_id,
                'companies_id' => $connection->companies_id,
                'claimed_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public static function resourceLock(string $provider, string $resourceKey): string
    {
        return "communication:resource:{$provider}:{$resourceKey}";
    }
}
