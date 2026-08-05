<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

class RecordLeadNoteAction
{
    public function __construct(
        protected readonly Lead $lead,
    ) {
    }

    /**
     * @param  Users|null  $actingUser  Who the note is attributed to. Defaults to the AI agent user
     *                                  (agent-authored notes). Pass a human for manager-driven records.
     * @param  bool  $fromIa  Whether the note is AI-authored. Pair with $actingUser=null for AI,
     *                        or a human + false for manager-attributed records (e.g. batch outreach).
     */
    public function execute(
        string $body,
        string $tag = 'note',
        ?Users $actingUser = null,
        bool $fromIa = true
    ): ?Message {
        try {
            $user = $actingUser ?? $this->lead->company->getAiAgentUser() ?? $this->lead->user;
            if ($user === null) {
                return null;
            }

            $channel = $this->lead->systemNotes
                ?? new LeadChannelService()->findOrCreateForLead(
                    $this->lead,
                    $this->lead->app,
                    $this->lead->company,
                    $user
                );
            if ($channel === null) {
                return null;
            }

            $messageType = MessageTypeService::getOrCreate($this->lead->app, 'system');

            $messagePayload = new AiChatMessagePayload(
                content: $body,
                from_me: true,
                from_ia: $fromIa,
                raw_data: $body,
            );

            $messageInput = new MessageInput(
                app: $this->lead->app,
                company: $this->lead->company,
                user: $user,
                type: $messageType,
                message: $messagePayload->toArray(),
                is_public: 0,
            );

            $note = new CreateSocialMessageAction(
                $messageInput,
                SystemModulesRepository::getByModelName(Lead::class, $this->lead->app),
                $this->lead->getId(),
            )->execute();

            $channel->addMessage($note);
            $note->addTag($tag);

            return $note;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
