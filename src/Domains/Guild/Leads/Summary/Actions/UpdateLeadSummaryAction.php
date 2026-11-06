<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\LeadConversationMessages;
use Kanvas\Guild\Leads\Summary\LeadSummaryAgent;
use Kanvas\Guild\Leads\Summary\LeadSummaryBatch;
use Kanvas\Guild\Leads\Summary\LeadSummaryContext;
use Kanvas\Guild\Leads\Summary\LeadSummaryFold;
use Kanvas\Guild\Leads\Summary\LeadSummaryProgress;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Guild\Leads\Summary\LeadSummaryUpdateValidator;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * One bounded step of a lead's rolling summary: the previous summary plus the next chunk of counted
 * messages above its coverage (or from a pending rebuild point), folded into a new summary through
 * Neuron structured output. All of it, lock wait and retry included, runs inside `$deadline`.
 *
 * Nothing is applied unless the whole update validates: applying the valid operations and advancing
 * the marker would move coverage past the evidence for the rejected one, and it would never be looked
 * at again. A rejected update gets one more attempt with the reasons, then the previous summary, items
 * and marker stay exactly as they were.
 */
final class UpdateLeadSummaryAction
{
    /** Messages folded into one update. A Flash call on this much input answers in a few seconds. */
    public const int CHUNK_TOKENS = 12_000;

    private const int LOCK_SECONDS = 120;

    private ?string $context = null;

    public function __construct(
        private readonly Lead $lead,
        private readonly AIProviderInterface $provider,
        private readonly float $deadline,
    ) {
    }

    public function execute(): LeadSummaryProgress
    {
        $lock = Cache::lock(LeadSummaryRepository::lockKey($this->lead), self::LOCK_SECONDS);

        try {
            $lock->block(max(0, (int) floor($this->remaining())));
        } catch (LockTimeoutException) {
            return LeadSummaryProgress::failed(LeadSummaryRepository::state($this->lead), 'lock');
        }

        try {
            return $this->step(LeadSummaryRepository::state($this->lead));
        } finally {
            $lock->release();
        }
    }

    private function step(LeadSummaryState $state): LeadSummaryProgress
    {
        $after = $state->rebuildFrom === null ? $state->through : $state->rebuildFrom - 1;
        $batch = LeadSummaryBatch::fromMessages(LeadConversationMessages::after($this->lead, $after, self::CHUNK_TOKENS));

        if ($batch->isEmpty()) {
            return $state->rebuildFrom === null
                ? LeadSummaryProgress::current($state)
                : $this->save(LeadSummaryFold::covered(
                    $state,
                    $state->sections,
                    $state->items,
                    $state->through,
                ));
        }

        $rejections = [];

        foreach ([1, 2] as $attempt) {
            if ($this->remaining() <= 1) {
                break;
            }

            try {
                $update = $this->generate(LeadSummaryFold::prompt(
                    (string) $this->lead->title,
                    $state,
                    $batch,
                    $rejections,
                    $this->context ??= LeadSummaryContext::for($this->lead),
                ));
            } catch (Throwable $e) {
                Log::warning('Lead summary update failed', [
                    'lead_id' => $this->lead->getId(),
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                return LeadSummaryProgress::failed($state, 'provider');
            }

            $rejections = new LeadSummaryUpdateValidator($state, $batch)->violations($update);

            if ($rejections === []) {
                return $this->save(LeadSummaryFold::apply($state, $update, $batch));
            }

            Log::warning('Lead summary update rejected', [
                'lead_id' => $this->lead->getId(),
                'attempt' => $attempt,
                'violations' => $rejections,
            ]);
        }

        return LeadSummaryProgress::failed($state, $rejections === [] ? 'deadline' : 'rejected');
    }

    private function generate(string $prompt): LeadSummaryUpdate
    {
        // A slow provider must not run the turn past its budget: the request carries what is left of it.
        $this->provider->setHttpClient(new GuzzleHttpClient(timeout: max(1.0, $this->remaining())));

        return LeadSummaryAgent::make()
            ->setAiProvider($this->provider)
            ->setThreadId('lead-summary-' . Str::uuid7())
            ->structured(new UserMessage($prompt), LeadSummaryUpdate::class, maxRetries: 1);
    }

    private function save(LeadSummaryState $state): LeadSummaryProgress
    {
        LeadSummaryRepository::save($this->lead, $state);

        return LeadSummaryProgress::saved($state);
    }

    private function remaining(): float
    {
        return $this->deadline - microtime(true);
    }
}
