<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Actions;

use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Guild\Leads\Models\Lead;

class PushLeadAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): array
    {
        $pushLead = new DealerSocketLeadService(
            $this->lead->app,
            $this->lead->company,
        );

        return $pushLead->saveLead($this->lead);
    }
}
