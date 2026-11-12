<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\DataTransferObject;

use Kanvas\Connectors\SalesAssist\Enums\CrmPeopleMatchEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * The people a CRM customer was matched to. `ambiguous` is true when more than one person qualified
 * and the oldest was taken.
 */
final readonly class CrmPeopleMatch
{
    public function __construct(
        public People $people,
        public CrmPeopleMatchEnum $matchedBy,
        public bool $ambiguous = false
    ) {
    }
}
