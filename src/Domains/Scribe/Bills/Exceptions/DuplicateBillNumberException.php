<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Exceptions;

use Baka\Exceptions\LightHouseCustomException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Kanvas\Scribe\Bills\Models\Bill;
use Override;

/**
 * A vendor re-sending an invoice is a normal business outcome, not a fault — the person creating
 * the bill needs to hear about it, Sentry does not (KANVAS-ECOSYSTEM-65K).
 */
class DuplicateBillNumberException extends LightHouseCustomException implements ShouldntReport
{
    /**
     * @param Bill|null $existing null when a concurrent insert won the race and isn't visible to this transaction yet
     */
    public function __construct(
        public readonly string $billNumber,
        public readonly ?Bill $existing = null,
    ) {
        parent::__construct("Bill {$billNumber} already exists for this vendor.", 'duplicate_bill_number');
    }

    #[Override]
    public function getExtensions(): array
    {
        return [
            ...parent::getExtensions(),
            'bill_id' => $this->existing?->getId(),
        ];
    }
}
