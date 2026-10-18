<?php

declare(strict_types=1);

namespace Tests\Communication\Fixtures;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use RuntimeException;

/**
 * Stands in for the vendor's API. `$vendor` is what the vendor currently holds for each account —
 * inbound URL by account key, and by service for shared ones — so a test can put someone else's
 * registration there, or take ours away, and see what setup and cleanup do about it.
 */
final class FakeProvisioner implements Provisioner
{
    /** @var array<string, string> account key or service sid → the inbound URL the vendor holds */
    public array $vendor = [];

    public ?string $probeError = null;

    public ?string $missingPrerequisite = null;

    public int $failRegistrations = 0;

    /** @var array<string, string> account key → messaging service the number sits in */
    public array $services = [];

    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly SharedRegistry $sharedRegistry
    ) {
    }

    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        $this->calls[] = 'probe';

        return $this->probeError;
    }

    public function legacyReceiverJobs(): array
    {
        return [];
    }

    public function autoReply(): ?array
    {
        return ['channel_slug_pattern' => '/^fake-/', 'legacy_responder' => null];
    }

    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $this->calls[] = 'register:' . $connection->account_key;

        if ($this->failRegistrations > 0) {
            $this->failRegistrations--;

            throw new RuntimeException('vendor unavailable');
        }

        $service = $this->services[$connection->account_key] ?? null;

        if ($service !== null) {
            return new InboundRegistration(shared: $this->sharedRegistry->attach(
                $connection,
                'AC-fake',
                'messaging_service',
                $service,
                function () use ($service, $inboundUrl): string {
                    SharedRegistry::assertSettable($this->vendor[$service] ?? null, $inboundUrl, $service);
                    $this->vendor[$service] = $inboundUrl;

                    return $inboundUrl;
                }
            ));
        }

        SharedRegistry::assertSettable($this->vendor[$connection->account_key] ?? null, $inboundUrl, $connection->account_key);
        $this->vendor[$connection->account_key] = $inboundUrl;

        return new InboundRegistration(owned: [
            new OwnedResource('fake_number', $connection->account_key, 'PN-' . $connection->account_key, $inboundUrl),
        ]);
    }

    public function missingPrerequisite(Connection $connection): ?string
    {
        return $this->missingPrerequisite;
    }

    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        $this->calls[] = 'cleanup:' . $task->resource_key;

        if (($this->vendor[$task->resource_key] ?? null) !== $task->expected_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        unset($this->vendor[$task->resource_key]);

        return CleanupTaskStatusEnum::DONE;
    }

    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $this->calls[] = 'release:' . $registration->resource_key;

        if (($this->vendor[$registration->resource_key] ?? null) !== $registration->registered_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        unset($this->vendor[$registration->resource_key]);

        return CleanupTaskStatusEnum::DONE;
    }

    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        return [];
    }
}
