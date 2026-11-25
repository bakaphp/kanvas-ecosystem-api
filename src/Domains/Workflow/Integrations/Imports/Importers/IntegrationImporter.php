<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Importers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Actions\StartIntegrationImportAction;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChoice;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

abstract class IntegrationImporter
{
    public function __construct(
        protected IntegrationsCompany $integrationCompany,
        protected Users $user,
    ) {
    }

    /**
     * Runs synchronously in the mutation, before anything is queued: validate and normalise the
     * options. What it returns is what the run stores.
     */
    public function prepare(array $options): array
    {
        return $options;
    }

    /**
     * @return list<ImportChoice>
     */
    public function choices(string $option): array
    {
        return [];
    }

    /**
     * After the last chunk, with the run already completed. A returned array becomes its summary.
     */
    public function completed(IntegrationImport $run): ?array
    {
        return null;
    }

    /**
     * Starts another entity's import from completed(): {entity: queued}, or {entity: skipped, reason}
     * when the start is refused, so the finished run still reports what happened to its follow-up.
     */
    protected function startFollowUp(string $entity, array $options = []): array
    {
        try {
            new StartIntegrationImportAction(
                integrationCompany: $this->integrationCompany,
                user: $this->user,
                entity: $entity,
                options: $options,
            )->execute();
        } catch (ValidationException $e) {
            return [$entity => 'skipped', 'reason' => $e->getMessage()];
        }

        return [$entity => 'queued'];
    }

    /**
     * A row with no apps_id predates app ownership, so the app bound to the request (or to the job
     * by overwriteAppService) is the only one left.
     */
    protected function app(): Apps
    {
        return $this->integrationCompany->apps_id !== null
            ? $this->integrationCompany->app
            : app(Apps::class);
    }

    protected function company(): Companies
    {
        return $this->integrationCompany->company;
    }
}
