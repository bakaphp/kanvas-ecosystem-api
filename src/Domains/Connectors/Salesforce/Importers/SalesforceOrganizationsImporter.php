<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Importers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PullAllOrganizationsAction;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Override;

class SalesforceOrganizationsImporter extends SalesforceRecordsImporter
{
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
