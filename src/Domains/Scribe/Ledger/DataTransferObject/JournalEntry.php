<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Typed payload PostJournalEntryAction consumes.
 *
 * Per the memory rule "Don't queue Spatie Data DTOs with Eloquent models", this DTO holds AppInterface +
 * CompanyInterface model references. PostJournalEntryAction is SYNCHRONOUS (not a queued job), so the rule
 * doesn't bite — but never put a JournalEntryData instance directly on a ShouldQueue job's constructor; rebuild
 * it inside handle() from primitive fields + model lookups.
 *
 * @property DataCollection<JournalEntryLine> $lines
 */
class JournalEntry extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Carbon $postedAt,
        public readonly string $sourceType,
        /** @var DataCollection<JournalEntryLine> */
        public readonly DataCollection $lines,
        public readonly ?int $sourceId = null,
        public readonly ?string $sourceExternalId = null,
        public readonly ?string $jeNumber = null,
        public readonly ?string $memo = null,
        public readonly bool $isAdjustment = false,
        public readonly ?int $isReversalOf = null,
        public readonly string $source = 'kanvas',
        public readonly ?string $externalId = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
        public readonly ?array $metadata = null,
    ) {
    }
}
