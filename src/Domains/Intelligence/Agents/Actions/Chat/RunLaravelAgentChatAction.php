<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Laravel\Contracts\TransformsStructuredOutput;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Services\AttachmentBudgetService;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Agents\Services\AttachmentFetchService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;

class RunLaravelAgentChatAction
{
    /**
     * @param list<string> $media Attachment URLs/paths (image/audio/PDF) for this turn's prompt.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly KanvasLaravelAgent $handler,
        protected readonly array $media = [],
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';
        $sessionEntity = $this->session?->entity();
        $usesMemory = in_array(RemembersConversations::class, class_uses_recursive($this->handler));

        if ($usesMemory) {
            $sessionId !== ''
                ? $this->handler->continueLastConversation($this->user)
                : $this->handler->forUser($this->user);
        }

        [$attachments, $promptBlocks] = $this->buildAttachments();
        $prompt = implode("\n\n", [$this->message, ...$promptBlocks]);

        $response = $this->handler->promptWithConfig($prompt, $attachments);
        // Structured-output agents (HasStructuredOutput) return their payload in
        // ->structured; ->text is empty in JSON mode. Surface the JSON as the
        // reply so the recommendations actually reach the caller instead of "".
        $responseText = $response instanceof StructuredAgentResponse
            ? $this->resolveStructuredPayload($response)
            : $response->text;

        if ($sessionEntity !== null) {
            $history = AgentHistory::create([
                'agent_id' => $this->agent->getId(),
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'entity_namespace' => get_class($sessionEntity),
                'entity_id' => $sessionEntity->getId(),
                'context' => $sessionId,
                'input' => ['role' => 'user', 'content' => $this->message],
                'output' => ['role' => 'assistant', 'content' => $responseText],
            ]);

            // History rebuild (messages()) re-sends text only, so describe the attachments with the
            // agent's own model and fold the text into this row's input.content — that's how the
            // Laravel agent "remembers" the attachment on later turns.
            if ($this->media !== []) {
                DescribeMessageAttachmentsJob::dispatch(
                    $this->app,
                    $this->agent,
                    $this->user,
                    CaptionTargetEnum::AGENT_HISTORY,
                    (string) $history->getId(),
                    array_values($this->media),
                );
            }
        }

        if (! $usesMemory) {
            // Fold the model laravel-ai used (response meta) into the usage blob so
            // the daily rollup can price the turn — Laravel doesn't persist it elsewhere.
            $usage = $response->usage->toArray();
            if ($response->meta->model !== null) {
                $usage['model'] = $response->meta->model;
            }

            // Forward tool calls/results/usage so the agent_conversation_messages row
            // mirrors the Neuron + RemembersConversations paths (else empty tool_calls).
            new KanvasConversationStore()->logTurn(
                userId: $this->user->getId(),
                sessionId: $sessionId,
                agentClass: get_class($this->handler),
                userMessage: $this->message,
                assistantResponse: $responseText,
                agentId: $this->agent->getId(),
                toolCalls: $response->toolCalls->toArray(),
                toolResults: $response->toolResults->toArray(),
                usage: $usage,
            );
        }

        return $responseText;
    }

    /**
     * Agents implementing TransformsStructuredOutput declare a minimal schema and
     * rebuild the full payload server-side, so the model never spends output
     * tokens re-emitting rows a tool already handed it.
     */
    private function resolveStructuredPayload(StructuredAgentResponse $response): string
    {
        if (! $this->handler instanceof TransformsStructuredOutput) {
            return $response->toJson();
        }

        return (string) json_encode(
            $this->handler->transformStructuredOutput($response->toArray()),
        );
    }

    /**
     * Wrap each attachment as the matching base64 laravel-ai file (image / audio / document) so the
     * model sees it on this turn. laravel-ai has no file type for text, so anything the extractor can
     * read (CSV, JSON, YAML, source, DOCX, XLSX) is inlined into the prompt instead; a type that is
     * neither is skipped, and an unreadable or over-budget one becomes a note on the prompt.
     *
     * @return array{0: list<File>, 1: list<string>} `[$attachments, $promptBlocks]`
     */
    private function buildAttachments(): array
    {
        $attachments = [];
        $promptBlocks = [];
        $allowStructuredText = ! $this->agent->conversesWithCustomer();
        $budget = new AttachmentBudgetService();

        foreach ($this->media as $url) {
            $binary = AttachmentFetchService::fetch($url);

            if ($binary === null) {
                $promptBlocks[] = AttachmentFetchService::unavailableNote($url);

                continue;
            }

            if ($binary === '') {
                continue;
            }

            if (! $budget->admits($url, strlen($binary))) {
                continue;
            }

            $mimeType = FilesystemServices::detectMimeTypeFromBytes($binary);
            $kind = AttachmentDescriptionService::nativeKind($mimeType, $allowStructuredText);
            $file = $this->wrapAttachment($binary, $mimeType, $kind);

            if ($file !== null) {
                $attachments[] = $file;

                continue;
            }

            if ($kind === 'text') {
                $promptBlocks[] = AttachmentDescriptionService::wrapTextForBlock($binary, $mimeType);
            }
        }

        $overBudget = $budget->skippedNote();
        if ($overBudget !== null) {
            $promptBlocks[] = $overBudget;
        }

        return [$attachments, $promptBlocks];
    }

    /** Takes the resolved $kind rather than re-deriving it, so the customer-facing gate can't be bypassed here. */
    private function wrapAttachment(string $binary, string $mimeType, ?string $kind): ?File
    {
        $base64 = base64_encode($binary);

        return match ($kind) {
            'image' => Image::fromBase64($base64, $mimeType),
            'audio' => Audio::fromBase64($base64, $mimeType),
            'pdf' => Document::fromBase64($base64, $mimeType),
            default => null,
        };
    }
}
