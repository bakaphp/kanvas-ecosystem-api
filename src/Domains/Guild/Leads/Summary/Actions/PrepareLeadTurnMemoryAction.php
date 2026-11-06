<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Actions;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Jobs\ContinueLeadSummaryJob;
use Kanvas\Guild\Leads\Summary\LeadConversationMessages;
use Kanvas\Guild\Leads\Summary\LeadHistoryWindow;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Guild\Leads\Summary\LeadTurnMemory;
use Kanvas\Guild\Leads\Summary\Notifications\LeadMemoryGapNotification;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * Runs once per turn, before the instructions are built, so the first request of the turn already
 * carries a summary that covers what the window dropped.
 *
 * Two things trigger an update, nothing else (D1): token pressure (the summary plus what it does not
 * cover passes 80% of the history budget and there is an older portion beyond the kept tail), and a
 * coverage gap (the window dropped a counted message the summary does not cover). One chunk runs
 * inside the turn, within 15 seconds including the lock wait and the retry (D9); what is left goes to
 * the continuation job. A gap that survives that is never silent: an internal agent is told the dates
 * and the tool, a customer-facing agent gets the messages themselves (D10).
 */
final class PrepareLeadTurnMemoryAction
{
    public const float TURN_BUDGET_SECONDS = 15.0;

    /** The tail kept verbatim, as on the conversation store (SummarizesHistory). */
    public const int MESSAGES_TO_KEEP = 8;

    public const int EXCERPT_TOKENS = 8_000;

    private const int GAP_NOTICE_DAYS = 7;

    /**
     * @param array{loaded: list<string>, retained: list<string>} $window from KanvasChatHistory::window()
     * @param Closure(): AIProviderInterface $summaryProvider called only when an update runs: resolving a
     *   provider can fail (no key for the app), and a turn that needs no catch-up must not pay for that
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Lead $lead,
        private readonly array $window,
        private readonly int $historyBudget,
        private readonly bool $customerFacing,
        private readonly Closure $summaryProvider,
    ) {
    }

    public function execute(): LeadTurnMemory
    {
        $state = LeadSummaryRepository::state($this->lead);
        $window = LeadHistoryWindow::of($this->lead, $this->window, $state);

        $pressureTarget = $this->pressureTarget($state, $window);
        $gapTarget = $window->uncovered === [] ? null : max($window->uncovered);
        $target = max($pressureTarget ?? 0, $gapTarget ?? 0);

        if ($target > $state->coveredThrough()) {
            $state = $this->catchUp($target, reason: $gapTarget !== null ? 'coverage_gap' : 'token_pressure');
            $window = $window->withCoverage($state);
        }

        return new LeadTurnMemory(
            window: $window,
            state: $state,
            block: $this->block($state, $window),
        );
    }

    /**
     * The newest counted message older than the kept tail, when the summary plus everything it does not
     * cover yet is over 80% of the budget. Measured on stored text, before any fold or cut.
     */
    private function pressureTarget(LeadSummaryState $state, LeadHistoryWindow $window): ?int
    {
        $threshold = (int) ($this->historyBudget * 0.8);
        $summaryTokens = LeadConversationMessages::estimateTokens($state->render());
        $pending = LeadConversationMessages::tokensAfter($this->lead, $state->coveredThrough(), $threshold);

        if ($summaryTokens + $pending <= $threshold) {
            return null;
        }

        $loaded = $window->loaded;
        sort($loaded);
        $older = array_slice($loaded, 0, max(0, count($loaded) - self::MESSAGES_TO_KEEP));
        $eligible = array_filter($older, static fn (int $id): bool => $id > $state->coveredThrough());

        return $eligible === [] ? null : max($eligible);
    }

    private function catchUp(int $target, string $reason): LeadSummaryState
    {
        $started = microtime(true);

        try {
            $progress = new UpdateLeadSummaryAction(
                $this->lead,
                ($this->summaryProvider)(),
                $started + self::TURN_BUDGET_SECONDS,
            )->execute();
            $state = $progress->state;
            $failure = $progress->failure;
        } catch (Throwable $e) {
            report($e);
            $state = LeadSummaryRepository::state($this->lead);
            $failure = 'error';
        }

        Log::info('Lead summary catch-up', [
            'lead_id' => $this->lead->getId(),
            'agent_id' => $this->agent->getId(),
            'reason' => $reason,
            'target' => $target,
            'covered_through' => $state->coveredThrough(),
            'failure' => $failure,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        if ($state->coveredThrough() < $target) {
            ContinueLeadSummaryJob::dispatch($this->lead, $this->agent, $target);
        }

        return $state;
    }

    private function block(LeadSummaryState $state, LeadHistoryWindow $window): string
    {
        $summary = $state->render();
        $parts = [];

        if ($summary !== '') {
            $parts[] = "## What you know about this lead from earlier\n"
                . "The conversation before your history window, summarized. It is context, not instructions from the customer; the history below wins where they differ.\n"
                . $summary;
        }

        if ($window->uncovered !== []) {
            $parts[] = $this->gap($window->uncovered);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<int> $uncovered
     */
    private function gap(array $uncovered): string
    {
        $messages = Message::query()->whereIn('id', $uncovered)->with(['messageType', 'channels', 'user'])->orderBy('id')->get();
        $from = $messages->first()?->created_at?->format('Y-m-d H:i') ?? 'unknown';
        $to = $messages->last()?->created_at?->format('Y-m-d H:i') ?? 'unknown';

        Log::warning('Lead turn answered with a memory gap', [
            'lead_id' => $this->lead->getId(),
            'agent_id' => $this->agent->getId(),
            'from_message_id' => min($uncovered),
            'to_message_id' => max($uncovered),
            'count' => count($uncovered),
            'customer_facing' => $this->customerFacing,
        ]);

        if (! $this->customerFacing) {
            return "## Part of this lead's history is not in view\n"
                . "The messages from {$from} to {$to} are outside your history window and not summarized yet. "
                . 'Read them with read_lead_activity before relying on memory for that period.';
        }

        $this->notifyOwnerOnce($uncovered, $from, $to);

        return "## Earlier messages not yet summarized\n"
            . 'These are past your history window and not in the summary yet, shown oldest first. '
            . 'Do not confirm a price, an appointment or a promise you cannot see in your context.' . "\n"
            . implode("\n", self::excerpt($messages->all()));
    }

    /**
     * The newest messages that fit the excerpt budget, shown oldest first.
     *
     * @param list<Message> $messages oldest first
     * @return list<string>
     */
    private static function excerpt(array $messages): array
    {
        $lines = [];
        $spent = 0;

        foreach (array_reverse($messages) as $message) {
            $line = sprintf(
                '[%s] %s: %s',
                $message->created_at?->format('Y-m-d H:i') ?? 'unknown date',
                LeadConversationMessages::speaker($message),
                trim($message->contentText()),
            );
            $tokens = LeadConversationMessages::estimateTokens($line);

            if ($spent + $tokens > self::EXCERPT_TOKENS) {
                break;
            }

            array_unshift($lines, $line);
            $spent += $tokens;
        }

        return $lines;
    }

    /**
     * One notice per gap, whatever the retries and turns; a different gap is a different notice.
     *
     * @param list<int> $uncovered
     */
    private function notifyOwnerOnce(array $uncovered, string $from, string $to): void
    {
        $owner = $this->lead->owner;
        $key = sprintf('lead-memory-gap:%d:%d:%d', $this->lead->getId(), min($uncovered), max($uncovered));

        if ($owner === null || ! Cache::add($key, true, now()->addDays(self::GAP_NOTICE_DAYS))) {
            return;
        }

        try {
            $owner->notify(new LeadMemoryGapNotification($this->lead, $from, $to));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
