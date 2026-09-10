<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\Intelligence\Agents\Enums\CustomerUpdateSkipEnum;

/**
 * The outcome of one drafting run: a draft, or the reason there isn't one.
 *
 * A bare null told an operator "nothing to send" without saying whether Kanvas shipped nothing or the
 * agent had nothing to say about this account — and those call for opposite next steps.
 */
class CustomerUpdateResult
{
    private function __construct(
        public readonly ?CustomerUpdateDraft $draft,
        public readonly ?CustomerUpdateSkipEnum $skipped,
        public readonly int $releasesConsidered,
    ) {
    }

    public static function drafted(CustomerUpdateDraft $draft, int $releasesConsidered): self
    {
        return new self($draft, null, $releasesConsidered);
    }

    public static function skipped(CustomerUpdateSkipEnum $reason, int $releasesConsidered): self
    {
        return new self(null, $reason, $releasesConsidered);
    }

    public function hasDraft(): bool
    {
        return $this->draft !== null;
    }
}
