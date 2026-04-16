<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSubSources\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\LeadSubSources\DataTransferObject\LeadSubSource as LeadSubSourceData;
use Kanvas\Guild\LeadSubSources\Models\LeadSubSource;

class UpdateLeadSubSourceAction
{
    public function __construct(
        protected readonly LeadSubSource $subSource,
        protected readonly LeadSubSourceData $data,
    ) {
    }

    public function execute(): LeadSubSource
    {
        return DB::connection('crm')->transaction(function () {
            $this->subSource->leads_sources_id = $this->data->source->getId();
            $this->subSource->name = $this->data->name;
            $this->subSource->saveOrFail();

            return $this->subSource;
        });
    }
}
