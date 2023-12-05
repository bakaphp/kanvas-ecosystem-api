<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Kanvas\Guild\Leads\Models\Lead;
use Workflow\Activity;

class ZohoLeadActivity extends Activity
{
    public function execute(Lead $lead): string
    {
        return 'processing lead ' . $lead->id;
    }
}
