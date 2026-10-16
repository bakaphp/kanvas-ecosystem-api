<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmFollowers extends Data
{
    /**
     * @param list<string> $attach emails
     * @param list<string> $detach emails
     */
    public function __construct(
        public readonly array $attach = [],
        public readonly array $detach = []
    ) {
    }
}
