<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

/**
 * Which expense account a bill is coded to, where that came from, and how sure the source is. Vendor
 * default, vendor history and item are certain (1.0); an AI suggestion carries its own confidence, and
 * the approver is shown it.
 */
final readonly class ExpenseAccountCoding
{
    public const string VENDOR = 'vendor';
    public const string HISTORY = 'history';
    public const string ITEM = 'item';
    public const string AI = 'ai';

    public function __construct(
        public int $accountId,
        public string $source,
        public float $confidence = 1.0,
        public ?string $reasoning = null,
    ) {
    }

    public function isSuggestion(): bool
    {
        return $this->source === self::AI;
    }
}
