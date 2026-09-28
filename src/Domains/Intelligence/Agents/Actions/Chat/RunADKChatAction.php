<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Carbon\Carbon;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use Kanvas\Users\Models\Users;

class RunADKChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Users $user,
        protected readonly ?Channel $sourceChannel = null,
        protected readonly ?SocialMessage $sourceMessage = null,
        protected readonly ?string $appName = null,
        protected readonly ?string $baseUrl = null,
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? $this->sourceChannel?->slug ?? '';
        $userId = $this->resolveUserId();

        $service = new GoogleADKService(
            $this->agent->app,
            $this->agent->company,
            $this->appName,
            $this->baseUrl
        );
        $service->startSession($userId, $sessionId);

        $response = $service->chat($userId, $sessionId, $this->message);

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: GoogleADKService::class,
            userMessage: $this->message,
            assistantResponse: $response,
            agentId: $this->agent->getId(),
        );

        return $response;
    }

    /**
     * Mirrors ADKAgent::chat() identity rules: prefer the inbound message's user,
     * and when the channel is Lead-scoped and the entity is new enough, use the
     * Lead id as the ADK user. Without this, ADK's remote memory key drifts and
     * the agent appears to forget the conversation.
     */
    private function resolveUserId(): string
    {
        $userId = $this->sourceMessage !== null
            ? (string) $this->sourceMessage->users_id
            : (string) $this->user->getId();

        $dateAdkUserId = $this->agent->app->get(AppSettingsEnums::DATE_ADK_AGENT_RESPONSES->getValue()) ?? null;
        $dateParse = $dateAdkUserId ? Carbon::parse($dateAdkUserId) : null;
        $sessionEntity = $this->session?->entity();

        if ($this->sourceChannel?->entity_namespace === Lead::class
            && $sessionEntity instanceof Lead
            && ($dateParse === null || $sessionEntity->created_at->greaterThan($dateParse))
        ) {
            return (string) $this->sourceChannel->entity_id;
        }

        return $userId;
    }
}
