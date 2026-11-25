<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Importers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PullAllOrganizationsAction;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Override;

class SalesforceOrganizationsImporter extends SalesforceRecordsImporter
{
    /**
     * People start only once every Account is in: PullPeopleAction links a Contact to its
     * Organization only if that Organization already exists.
     */
    #[Override]
    public function completed(IntegrationImport $run): ?array
    {
        if (($run->options['then_people'] ?? false) !== true) {
            return null;
        }

        return $this->startFollowUp('people');
    }

    #[Override]
    protected function pullAll(): array
    {
        return new PullAllOrganizationsAction($this->app(), $this->company())->execute();
    }

    #[Override]
    protected function pull(string $salesforceId, array $record): Model
    {
        return new PullOrganizationAction($this->app(), $this->company(), $record, $salesforceId)->execute();
    }
}
