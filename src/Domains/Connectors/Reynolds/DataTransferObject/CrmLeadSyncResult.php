<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Kanvas\Connectors\Reynolds\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

final readonly class CrmLeadSyncResult
{
    /**
     * @param list<array{code: string, field: string}> $warnings
     */
    public function __construct(
        public CrmLeadSyncOutcomeEnum $outcome,
        public People $people,
        public ?Lead $lead,
        public bool $peopleCreated,
        public bool $leadCreated,
        public bool $replayed = false,
        public array $warnings = []
    ) {
    }
}
