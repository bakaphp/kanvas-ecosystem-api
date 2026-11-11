<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Plan\Actions\OpenFollowUpAction;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalDispatchStatusEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalEnum;
use Kanvas\Scribe\Delivery\Models\DocumentSignalDispatch;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use RuntimeException;
use Throwable;

/**
 * Hands the AR agent what a customer's reaction to a document calls for (plan §5.2): a ledger event the
 * digest and the agent's memory read, and a follow-up task on the agent, due at `until`, whose brief
 * says what to do.
 *
 * Once per signal per document: the dispatch row's unique key is claimed before the task is opened, so
 * a concurrent caller (or the daily watcher re-finding the same quiet quote) gets `false` instead of a
 * second task. The row is marked dispatched only once the task exists; with no agent yet, or a task
 * that failed to open, it stays `failed` with a next_retry_at for `scribe:retry-document-signals`.
 */
class DocumentEngagementSignalService
{
    public const int MAX_ATTEMPTS = 8;
    private const int STALE_PENDING_MINUTES = 30;
    private const int NO_AGENT = 404;

    public function __construct(
        private readonly AccountingAgentLocatorService $agents = new AccountingAgentLocatorService(),
    ) {
    }

    public function raise(
        Invoice|Quote $document,
        DocumentSignalEnum $signal,
        string $brief,
        ?Carbon $until = null
    ): bool {
        try {
            $dispatch = DocumentSignalDispatch::create([
                'apps_id' => $document->apps_id,
                'companies_id' => $document->companies_id,
                'signal_key' => DocumentSignalDispatch::keyFor($document, $signal),
                'document_type' => $document->documentKind(),
                'document_id' => $document->getId(),
                'signal' => $signal,
                'brief' => $brief,
                'due_at' => $until ?? Carbon::now(),
                'status' => DocumentSignalDispatchStatusEnum::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $this->dispatch($dispatch, $document);

        $document->emitLedgerEvent('scribe.' . $document->documentKind() . '.engagement.signal', payload: [
            'signal' => $signal->value,
            'brief' => $brief,
            'agent_task_id' => $dispatch->task_id,
            'dispatch_status' => $dispatch->status->value,
        ]);

        return true;
    }

    /**
     * @return int dispatches that opened their task on this run
     */
    public function retryDue(Apps $app): int
    {
        $now = Carbon::now();
        $dispatched = 0;

        $due = DocumentSignalDispatch::query()
            ->where('apps_id', $app->getId())
            ->where(fn ($query) => $query
                ->where(fn ($failed) => $failed
                    ->where('status', DocumentSignalDispatchStatusEnum::FAILED->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '<=', $now))
                ->orWhere(fn ($stuck) => $stuck
                    ->where('status', DocumentSignalDispatchStatusEnum::PENDING->value)
                    ->where('updated_at', '<=', $now->copy()->subMinutes(self::STALE_PENDING_MINUTES))))
            ->orderBy('id')
            ->get();

        foreach ($due as $dispatch) {
            if (! $this->claimForRetry($dispatch)) {
                continue;
            }

            $document = $dispatch->document();
            if ($document === null) {
                $dispatch->status = DocumentSignalDispatchStatusEnum::SKIPPED;
                $dispatch->next_retry_at = null;
                $dispatch->last_error = 'The document no longer exists.';
                $dispatch->save();

                continue;
            }

            $this->dispatch($dispatch, $document);
            $dispatched += (int) ($dispatch->status === DocumentSignalDispatchStatusEnum::DISPATCHED);
        }

        return $dispatched;
    }

    /**
     * Two retry workers can read the same failed row; only the one whose update still sees it unchanged
     * gets to open the task.
     */
    private function claimForRetry(DocumentSignalDispatch $dispatch): bool
    {
        $claimed = DocumentSignalDispatch::query()
            ->whereKey($dispatch->getId())
            ->where('status', $dispatch->status->value)
            ->where('attempts', $dispatch->attempts)
            ->update([
                'status' => DocumentSignalDispatchStatusEnum::PENDING->value,
                'updated_at' => Carbon::now(),
            ]) === 1;

        if ($claimed) {
            $dispatch->refresh();
        }

        return $claimed;
    }

    private function dispatch(DocumentSignalDispatch $dispatch, Invoice|Quote $document): void
    {
        $dispatch->attempts++;

        try {
            $dispatch->task_id = $this->openAgentTask($document, $dispatch->brief, $dispatch->due_at ?? Carbon::now());
            $dispatch->status = DocumentSignalDispatchStatusEnum::DISPATCHED;
            $dispatch->next_retry_at = null;
            $dispatch->last_error = null;
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException || $e->getCode() !== self::NO_AGENT) {
                report($e);
            }

            $dispatch->status = DocumentSignalDispatchStatusEnum::FAILED;
            $dispatch->last_error = mb_substr($e->getMessage(), 0, 2000);
            $dispatch->next_retry_at = $dispatch->attempts < self::MAX_ATTEMPTS
                ? Carbon::now()->addHours(min(2 ** ($dispatch->attempts - 1), 24))
                : null;
        }

        $dispatch->save();
    }

    private function openAgentTask(Invoice|Quote $document, string $brief, Carbon $until): int
    {
        /** @var Apps $app */
        $app = $document->app;
        /** @var Companies $company */
        $company = $document->company;
        $agent = $this->agents->receivables($app, $company);

        if ($agent === null || $agent->user === null) {
            throw new RuntimeException('The company has no AR agent with its own user to hand the signal to.', self::NO_AGENT);
        }

        // The wake has to be in the future; a signal due now (or a retry of one long due) wakes in a minute.
        return new OpenFollowUpAction(
            agent: $agent,
            recipient: null,
            waitingFor: $brief,
            until: $until->max(Carbon::now()->addMinute()),
            requestedBy: $agent->user,
        )->execute()->getId();
    }
}
