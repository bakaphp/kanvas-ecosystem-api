<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Importers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PullAllPeopleAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Override;

class SalesforcePeopleImporter extends SalesforceRecordsImporter
{
    #[Override]
    protected function pullAll(): array
    {
        return new PullAllPeopleAction($this->app(), $this->company())->execute();
    }

    #[Override]
    protected function pull(string $salesforceId, array $record): Model
    {
        return new PullPeopleAction($this->app(), $this->company(), $record, $salesforceId)->execute();
    }
}
