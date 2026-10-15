<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * What a sync is going to do, decided from reads only. A null people or lead is one still to create.
 */
final readonly class CrmLeadPlan
{
    public function __construct(
        public CrmLeadSyncOutcomeEnum $outcome,
        public ?People $people = null,
        public ?Lead $lead = null,
        public bool $ambiguous = false
    ) {
    }

    public function createsPeople(): bool
    {
        return ! $this->people instanceof People;
    }

    public function createsLead(): bool
    {
        return ! $this->lead instanceof Lead;
    }
}
