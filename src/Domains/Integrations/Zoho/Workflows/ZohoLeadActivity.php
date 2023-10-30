<?php

declare(strict_types=1);

namespace Kanvas\Integrations\Zoho\Workflows;

use Exception;
use Kanvas\Guild\Leads\Models\Lead;
use Workflow\Activity;

class ZohoLeadActivity extends Activity
{
    public function execute(Lead $lead): string
    {
        return 'processing lead ' . $lead->id;
    }
}
