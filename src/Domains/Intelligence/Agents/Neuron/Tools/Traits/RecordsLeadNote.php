<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Throwable;

trait RecordsLeadNote
{
    protected function recordLeadNote(Lead $lead, string $body, string $tag = 'note'): bool
    {
        try {
            $user = $lead->company->getAiAgentUser() ?? $lead->user;
            if ($user === null) {
                return false;
            }

            $channel = $lead->systemNotes
                ?? new LeadChannelService()->findOrCreateForLead(
                    $lead,
                    $lead->app,
                    $lead->company,
                    $user
                );
            if ($channel === null) {
                return false;
            }

            $messageType = MessageTypeService::getOrCreate($lead->app, 'system');

            $payload = new AiChatMessagePayload(
                content: $body,
                from_me: true,
                from_ia: true,
                raw_data: $body,
            );

            $messageInput = MessageInput::from([
                'app' => $lead->app,
                'company' => $lead->company,
                'user' => $user,
                'type' => $messageType,
                'message' => $payload->toArray(),
                'is_public' => 0,
            ]);

            $note = new CreateSocialMessageAction(
                $messageInput,
                SystemModulesRepository::getByModelName(Lead::class, $lead->app),
                $lead->getId(),
            )->execute();

            $channel->addMessage($note);
            $note->addTag($tag);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
