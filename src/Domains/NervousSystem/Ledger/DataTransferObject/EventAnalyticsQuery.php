<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Spatie\LaravelData\Data;

/**
 * Filter for ledger analytics. `app`/`company` here are the tenant scope the
 * service applies on every query. Never queued, so holding the models is fine.
 */
class EventAnalyticsQuery extends Data
{
    /**
     * @param string[] $eventTypes     restrict to these event_type values (empty = any)
     * @param string[] $payloadHasKeys JSON paths under payload that must be present, e.g. ['changes.current_employer']
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly ?CompanyInterface $company = null,
        public readonly array $eventTypes = [],
        public readonly ?string $sourceDomain = null,
        public readonly ?string $sourceEntityType = null,
        public readonly ?string $actorType = null,
        public readonly ?EventStatusEnum $status = null,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly array $payloadHasKeys = [],
    ) {
    }
}
