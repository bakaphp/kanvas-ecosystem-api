<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSubSources\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\LeadSubSources\DataTransferObject\LeadSubSource as LeadSubSourceData;
use Kanvas\Guild\LeadSubSources\Models\LeadSubSource;

class CreateLeadSubSourceAction
{
    public function __construct(
        protected readonly LeadSubSourceData $data,
    ) {
    }

    public function execute(): LeadSubSource
    {
        return DB::connection('crm')->transaction(function () {
            $subSource = new LeadSubSource();
            $subSource->apps_id = $this->data->app->getId();
            $subSource->companies_id = $this->data->company->getId();
            $subSource->leads_sources_id = $this->data->source->getId();
            $subSource->name = $this->data->name;
            $subSource->saveOrFail();

            return $subSource;
        });
    }
}
