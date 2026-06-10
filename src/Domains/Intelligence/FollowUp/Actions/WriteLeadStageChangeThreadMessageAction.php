<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Throwable;

/**
 * "Stage: X → Y" system message into the lead's DEFAULT Social thread.
 * Best-effort — all paths swallow + report; failure must never block the
 * underlying Lead update. The Ledger event is the audit truth.
 */
final class WriteLeadStageChangeThreadMessageAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly ?int $fromStageId,
        protected readonly int $toStageId,
    ) {
    }

    public function execute(): ?Message
    {
        try {
            $channel = $this->lead->systemNotes;
            if ($channel === null) {
                return null;
            }

            $body = $this->renderBody();
            $user = $this->lead->company->getAiAgentUser() ?? $this->lead->user;
            if ($user === null) {
                return null;
            }

            $messageType = MessageTypeService::getOrCreate($this->lead->app, 'system');

            $messagePayload = new AiChatMessagePayload(
                content: $body,
                from_me: true,
                from_ia: true,
                raw_data: $body,
            );

            $messageInput = MessageInput::from([
                'app' => $this->lead->app,
                'company' => $this->lead->company,
                'user' => $user,
                'type' => $messageType,
                'message' => $messagePayload->toArray(),
                'is_public' => 0,
            ]);

            $message = new CreateSocialMessageAction(
                $messageInput,
                SystemModulesRepository::getByModelName(Lead::class, $this->lead->app),
                $this->lead->getId(),
            )->execute();

            $channel->addMessage($message);
            $message->addTag('stage-change');

            return $message;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function renderBody(): string
    {
        $fromName = $this->stageName($this->fromStageId) ?? 'start';
        $toName = $this->stageName($this->toStageId) ?? '(unknown)';
        $tz = $this->lead->company->timezone ?? 'UTC';
        $when = Carbon::now($tz)->format('Y-m-d H:i');

        return "Stage: {$fromName} → {$toName} · {$when}";
    }

    private function stageName(?int $stageId): ?string
    {
        if ($stageId === null) {
            return null;
        }

        try {
            $stage = PipelineStage::getById($stageId);

            return $stage?->name;
        } catch (Throwable) {
            return null;
        }
    }
}
