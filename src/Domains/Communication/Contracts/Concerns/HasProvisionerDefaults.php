<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts\Concerns;

use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * What a `Provisioner` answers when there is nothing to do, so a provider only writes the methods
 * where it differs: no past to retire, no prerequisite, nothing of ours left at the vendor.
 */
trait HasProvisionerDefaults
{
    public function legacyReceiverJobs(): array
    {
        return [];
    }

    public function missingPrerequisite(Connection $connection): ?string
    {
        return null;
    }

    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        return CleanupTaskStatusEnum::SUPERSEDED;
    }

    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        return CleanupTaskStatusEnum::SUPERSEDED;
    }

    public function retireLegacy(Connection $connection): array
    {
        return [];
    }

    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        return [];
    }
}
