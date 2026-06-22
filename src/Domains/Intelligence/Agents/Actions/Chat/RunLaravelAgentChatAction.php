<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\CaptionMessageImagesJob;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class RunLaravelAgentChatAction
{
    /**
     * @param list<string> $images Image URLs/paths for this turn's prompt.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly KanvasLaravelAgent $handler,
        protected readonly array $images = [],
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

        $response = $this->handler->promptWithConfig($this->message, $this->buildImageAttachments());
        // Structured-output agents (HasStructuredOutput) return their payload in
        // ->structured; ->text is empty in JSON mode. Surface the JSON as the
        // reply so the recommendations actually reach the caller instead of "".
        $responseText = $response instanceof StructuredAgentResponse
            ? $response->toJson()
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

            // History rebuild (messages()) re-sends text only, so caption the images with the
            // agent's own model and fold the description into this row's input.content — that's
            // how the Laravel agent "remembers" the image on later turns.
            if ($this->images !== []) {
                CaptionMessageImagesJob::dispatch(
                    $this->app,
                    $this->agent,
                    $this->user,
                    CaptionTargetEnum::AGENT_HISTORY,
                    (string) $history->getId(),
                    array_values($this->images),
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
     * Fetch each image and wrap it as a base64 laravel-ai attachment so the model sees it on this
     * turn. SSRF guard: remote URLs go through the validated fetcher (blocks internal hosts /
     * cloud-metadata); local paths keep the raw read. A failed fetch is skipped, not fatal.
     *
     * @return list<Image>
     */
    private function buildImageAttachments(): array
    {
        $attachments = [];

        foreach ($this->images as $image) {
            try {
                if (preg_match('#^https?://#i', $image)) {
                    $binary = SafeUrlFetcher::fetch($image);
                } else {
                    $raw = file_get_contents($image);
                    $binary = $raw === false ? '' : $raw;
                }

                if ($binary === '') {
                    continue;
                }

                $attachments[] = Image::fromBase64(base64_encode($binary), $this->detectMediaType($binary));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $attachments;
    }

    private function detectMediaType(string $binary): string
    {
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($binary);

        return is_string($detected) && str_starts_with($detected, 'image/')
            ? $detected
            : 'image/png';
    }
}
