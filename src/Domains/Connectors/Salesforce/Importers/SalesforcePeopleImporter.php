<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Importers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PullAllPeopleAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Override;

class SalesforcePeopleImporter extends SalesforceRecordsImporter
{
    /**
     * A Contact imported while its Account is still queued loses the link to its Organization.
     */
    #[Override]
    public function prepare(array $options): array
    {
        if (IntegrationImport::inProgressFor($this->integrationCompany, 'organizations') !== null) {
            throw new ValidationException(
                'Organizations are still being imported for this integration. Import people when they finish.'
            );
        }

        return $options;
    }

    #[Override]
    protected function pullAll(): array
    {
        return new PullAllPeopleAction($this->app(), $this->company())->execute();
    }

    #[Override]
    protected function pull(string $salesforceId, array $record): Model
    {
        return new PullPeopleAction(
            $this->app(),
            $this->company(),
            $record,
            $salesforceId
        )->execute();
    }
}
