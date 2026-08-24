<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tools\ToolInterface;
use Throwable;

class RunNeuronChatAction
{
    /**
     * @param list<string> $media Attachment URLs (image/audio/PDF/text/CSV) sent natively as content blocks.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly mixed $handler,
        protected readonly array $media = []
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        // Agents whose chatHistory already records each turn (KanvasMessageHistory) must not also
        // logTurn here — that writes a second, parallel conversation. SalesAssist-style agents write
        // their history to Social messages, so they keep logTurn as their only conversation record.
        $selfRecords = $this->handler instanceof BehavesAsKanvasAgent
            && $this->handler->persistsTurnsToConversationStore();

        $userMessage = new UserMessage($this->message);
        foreach ($this->media as $attachment) {
            // One unreachable/oversized attachment must not sink the whole turn — fetch failures
            // (SafeUrlFetcher throws on transport/SSRF) are reported and skipped, not propagated.
            try {
                // SSRF guard: remote URLs go through the validated fetcher (blocks internal
                // hosts / cloud-metadata); data: URIs and local paths keep the raw read.
                if (preg_match('#^https?://#i', $attachment)) {
                    $binary = SafeUrlFetcher::fetch($attachment);
                } else {
                    $raw = file_get_contents($attachment);
                    $binary = $raw === false ? '' : $raw;
                }

                $block = $this->buildContentBlock($binary);
                if ($block !== null) {
                    $userMessage->addContent($block);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $toolCalls = [];
        $toolResults = [];
        $usage = [];

        try {
            // chat() returns immediately with an AgentHandler; the actual LLM
            // round-trip happens inside run() / getMessage(). Both must be
            // inside the try block so provider errors (e.g. Gemini returning
            // STOP with no `parts`) don't bubble as 500s.
            $responseContent = $this->handler->chat($userMessage);

            if ($responseContent instanceof AgentHandler) {
                $state = $responseContent->run();
                $responseMessage = $state->getMessage();
                [$toolCalls, $toolResults, $usage] = $this->extractTurnTelemetry($state, $responseMessage);
            } else {
                $responseMessage = $responseContent;
                if ($responseMessage instanceof Message && ($u = $responseMessage->getUsage())) {
                    $usage = $u->jsonSerialize();
                }
            }
        } catch (Throwable $e) {
            report($e);

            $fallback = $this->humanizedFallback($e);

            if (! $selfRecords) {
                new KanvasConversationStore()->logTurn(
                    userId: $this->user->getId(),
                    sessionId: $sessionId,
                    agentClass: get_class($this->handler),
                    userMessage: $this->message,
                    assistantResponse: $fallback,
                    agentId: $this->agent->getId(),
                    usage: ['error' => $e::class, 'message' => $e->getMessage()],
                );
            }

            return $fallback;
        }

        $content = $responseMessage->getContent() ?? '';

        if (! $selfRecords) {
            // Record the model the agent resolved to so the daily rollup can price the
            // turn — Neuron doesn't surface the model on the response itself.
            if (is_object($this->handler) && method_exists($this->handler, 'resolvedModelName')) {
                $usage['model'] = $this->handler->resolvedModelName();
            }

            new KanvasConversationStore()->logTurn(
                userId: $this->user->getId(),
                sessionId: $sessionId,
                agentClass: get_class($this->handler),
                userMessage: $this->message,
                assistantResponse: ChatHelper::extractTextFromResponse($content),
                agentId: $this->agent->getId(),
                toolCalls: $toolCalls,
                toolResults: $toolResults,
                usage: $usage,
            );
        }

        // Idempotent backfill so SalesAssistKanvasMessageHistory (entity-keyed query)
        // sees every channel message on the next turn.
        $this->backfillChannelMessagesToLead();

        return $content;
    }

    /** A duplicate-key violation is a recoverable, explainable case — everything else stays generic. */
    private function humanizedFallback(Throwable $e): string
    {
        if ($this->isDuplicateEntryError($e)) {
            return "It looks like that already exists — I didn't create a duplicate. Let me know if you'd "
                . 'like me to look into it or handle it a different way.';
        }

        // Any tool can trip the run budget, not just the people lookups this copy used to name —
        // a reporting tool looping on an empty date range hits it too (KANVAS-ECOSYSTEM-682).
        if ($e instanceof ToolRunsExceededException) {
            return 'I kept retrying the same lookup without getting anywhere. Could you narrow it down for me — '
                . 'an exact name, email, or date range — and ask again?';
        }

        return 'I ran into a hiccup processing that. Could you try rephrasing, '
            . 'or let me know if you want me to hand off to a human?';
    }

    private function isDuplicateEntryError(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function backfillChannelMessagesToLead(): void
    {
        if ($this->session === null) {
            return;
        }

        $freshSession = $this->session->fresh();
        if ($freshSession === null || $freshSession->entity_namespace !== Lead::class || $freshSession->entity_id === null) {
            return;
        }

        $lead = Lead::find($freshSession->entity_id);
        $channel = $freshSession->channel;

        if ($lead === null || $channel === null) {
            return;
        }

        $people = $lead->people;
        $peopleChannelService = $people !== null ? new PeopleChannelService() : null;
        $leadChannelService = new LeadChannelService();

        foreach ($channel->messages()->get() as $message) {
            $leadChannelService->attachMessageToLeadChannel(
                $message,
                $lead,
                $lead->app,
                $lead->company,
                $this->user,
            );

            if ($peopleChannelService !== null && $people !== null) {
                $peopleChannelService->attachMessageToPeopleChannel(
                    $message,
                    $people,
                    $lead->app,
                    $lead->company,
                    $this->user,
                );
            }
        }
    }

    /**
     * Wrap a fetched attachment in the matching Neuron content block by sniffing its bytes —
     * image / audio / PDF ride as binary blocks the model reads natively; text/CSV rides inline as a
     * TextContent block (it's just text). Anything else returns null (skipped; its URL is already
     * folded into the prompt text by AttachmentPromptBuilder upstream).
     */
    private function buildContentBlock(string $binary): ?ContentBlockInterface
    {
        if ($binary === '') {
            return null;
        }

        $mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($binary);
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $base64 = base64_encode($binary);

        return match (AttachmentDescriptionService::nativeKind($mimeType)) {
            'image' => new ImageContent($base64, SourceType::BASE64, $mimeType),
            'audio' => new AudioContent($base64, SourceType::BASE64, $mimeType),
            'pdf' => new FileContent($base64, SourceType::BASE64, $mimeType),
            'text' => new TextContent(AttachmentDescriptionService::wrapTextForBlock($binary, $mimeType)),
            default => null,
        };
    }

    /**
     * Walk this turn's intermediate steps (tool calls + their results) plus the
     * final assistant message, returning aggregated telemetry for persistence.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, int>}
     */
    private function extractTurnTelemetry(AgentState $state, Message $finalMessage): array
    {
        $toolCalls = [];
        $toolResults = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $seenFinal = false;

        $accumulate = function (Message $m) use (&$toolCalls, &$toolResults, &$inputTokens, &$outputTokens): void {
            if ($m instanceof ToolCallMessage) {
                foreach ($m->getTools() as $tool) {
                    /** @var ToolInterface $tool */
                    $toolCalls[] = $tool->jsonSerialize();
                }
            }
            if ($m instanceof ToolResultMessage) {
                foreach ($m->getTools() as $tool) {
                    /** @var ToolInterface $tool */
                    $toolResults[] = $tool->jsonSerialize();
                }
            }
            if ($u = $m->getUsage()) {
                $inputTokens += $u->inputTokens;
                $outputTokens += $u->outputTokens;
            }
        };

        foreach ($state->getSteps() as $step) {
            if (! $step instanceof Message) {
                continue;
            }
            $accumulate($step);
            if ($step === $finalMessage) {
                $seenFinal = true;
            }
        }

        if (! $seenFinal) {
            $accumulate($finalMessage);
        }

        return [
            $toolCalls,
            $toolResults,
            ($inputTokens > 0 || $outputTokens > 0)
                ? ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]
                : [],
        ];
    }
}
