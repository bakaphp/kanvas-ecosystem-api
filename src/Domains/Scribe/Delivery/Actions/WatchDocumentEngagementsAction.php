<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Services\DocumentEngagementSignalService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * The time-based half of "status → agent" (plan §5.2); the page's own answers (declined) signal as
 * they happen. Daily, per app:
 * - a document nobody opened `quiet_days` after it went out → resend it on another channel;
 * - a quote opened but neither accepted nor declined `quiet_days` after the first open → follow up.
 * An invoice opened but unpaid needs nothing here: the dunning reminder reads the open and changes
 * its tone. Threads older than LOOKBACK_DAYS are left alone.
 */
class WatchDocumentEngagementsAction
{
    public const string QUIET_DAYS_SETTING = 'scribe_document_follow_up_days';
    public const int DEFAULT_QUIET_DAYS = 3;
    private const int LOOKBACK_DAYS = 60;

    public function __construct(
        private readonly Apps $app,
        private readonly DocumentEngagementSignalService $signals = new DocumentEngagementSignalService(),
    ) {
    }

    /**
     * @return int signals raised
     */
    public function execute(): int
    {
        $raised = 0;

        foreach ($this->sentRows() as $sent) {
            $document = $sent->reference;
            if (! $document instanceof Invoice && ! $document instanceof Quote) {
                continue;
            }

            if (! $this->stillAwaitingCustomer($document)) {
                continue;
            }

            $raised += (int) $this->evaluate($sent, $document);
        }

        return $raised;
    }

    private function evaluate(Engagement $sent, Invoice|Quote $document): bool
    {
        $quietSince = Carbon::now()->subDays($this->quietDays($document));
        $thread = $this->thread($sent);
        $statuses = $thread->map(fn (Engagement $row): ?string => $row->stage?->slug)->filter()->unique();

        if ($statuses->diff([ActionStatusEnum::SENT->value])->isEmpty()) {
            if ($sent->created_at->greaterThan($quietSince)) {
                return false;
            }

            return $this->signals->raise(
                $document,
                DocumentSignalEnum::NEVER_OPENED,
                "{$this->label($document)} opened: it went out by {$this->channelOf($sent)} on "
                . $sent->created_at->toDateString() . ' and was never opened; resend it on the other channel with '
                . ($document instanceof Invoice ? 'send_invoice' : 'send_quote') . ' and a short note'
            );
        }

        $answered = $statuses->intersect([ActionStatusEnum::SUBMITTED->value, ActionStatusEnum::DECLINED->value]);
        if (! $document instanceof Quote || $answered->isNotEmpty()) {
            return false;
        }

        $firstOpen = $thread->first(fn (Engagement $row): bool => $row->stage?->slug === ActionStatusEnum::OPEN->value);
        if ($firstOpen === null || $firstOpen->created_at->greaterThan($quietSince)) {
            return false;
        }

        return $this->signals->raise(
            $document,
            DocumentSignalEnum::QUOTE_OPENED_NOT_ANSWERED,
            "an answer on quote {$document->quote_number} from {$document->billable_display_name}: they opened it on "
            . $firstOpen->created_at->toDateString() . ' but have not accepted or declined; follow up in their '
            . 'thread and ask what is holding it'
        );
    }

    /**
     * @return Collection<int, Engagement>
     */
    private function sentRows(): Collection
    {
        return Engagement::query()
            ->where('apps_id', $this->app->getId())
            ->whereIn('entity_type', [EngagementReferenceMap::SCRIBE_INVOICE, EngagementReferenceMap::SCRIBE_QUOTE])
            ->whereIn('slug', array_map(static fn (DocumentActionEnum $action): string => $action->value, DocumentActionEnum::cases()))
            ->where('is_deleted', 0)
            ->where('created_at', '>=', Carbon::now()->subDays(self::LOOKBACK_DAYS))
            ->atStage(ActionStatusEnum::SENT)
            ->with('reference')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Engagement>
     */
    private function thread(Engagement $sent): Collection
    {
        return Engagement::query()
            ->inThreadOf($sent)
            ->with('stage')
            ->orderBy('id')
            ->get();
    }

    private function stillAwaitingCustomer(Invoice|Quote $document): bool
    {
        return $document instanceof Invoice
            ? $document->document_status === InvoiceDocumentStatusEnum::SENT
            : $document->status === QuoteStatusEnum::SENT;
    }

    private function quietDays(Invoice|Quote $document): int
    {
        $days = $document->company->get(self::QUIET_DAYS_SETTING);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DEFAULT_QUIET_DAYS;
    }

    private function channelOf(Engagement $sent): string
    {
        return (string) ($sent->message?->getMessage()['via'] ?? 'email');
    }

    private function label(Invoice|Quote $document): string
    {
        return "{$document->documentKind()} {$document->documentNumber()} for {$document->billable_display_name}";
    }
}
