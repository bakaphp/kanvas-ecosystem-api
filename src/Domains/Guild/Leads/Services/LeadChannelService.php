<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Per-deal conversation channel keyed by Lead. Sits alongside the People
 * channel — same messages, two channels: People for durable cross-lead
 * history, Lead for deal-scoped UI surfaces (CRM dashboard).
 */
class LeadChannelService
{
    private const SLUG_PREFIX = 'lead-channel-';

    public function findOrCreateForLead(
        Lead $lead,
        Apps $app,
        Companies $company,
        ?Users $user = null,
    ): Channel {
        $owner = $this->resolveOwnerUser($lead, $company, $user);

        $dto = new ChannelDto(
            apps: $app,
            companies: $company,
            users: $owner,
            entity_id: $lead->getId(),
            entity_namespace: Lead::class,
            name: 'Conversation - ' . trim((string) $lead->title),
            description: 'Deal-scoped conversation channel.',
            slug: $this->slugFor($lead),
        );

        return new CreateChannelAction($dto)->execute();
    }

    public function attachMessageToLeadChannel(
        Message $message,
        Lead $lead,
        Apps $app,
        Companies $company,
        ?Users $user = null,
    ): void {
        $channel = $this->findOrCreateForLead($lead, $app, $company, $user);
        $owner = $this->resolveOwnerUser($lead, $company, $user);

        $alreadyInChannel = $channel->messages()
            ->where('messages.id', $message->getId())
            ->exists();

        if (! $alreadyInChannel) {
            $channel->messages()->attach($message->getId(), [
                'users_id' => $message->users_id ?? $owner->getId(),
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => 0,
            ]);
        }

        $message->addEntity($lead);
    }

    /**
     * A message of the lead's customer conversation, on both of its histories: the lead's channel
     * (what happened during this lead) and the person's (the whole conversation, across every lead
     * they ever had). Attached, not posted: nothing is delivered or announced again.
     */
    public function recordOnLeadAndPerson(Message $message, Lead $lead, ?Users $user = null): void
    {
        $this->attachMessageToLeadChannel(
            $message,
            $lead,
            $lead->app,
            $lead->company,
            $user
        );

        if ($lead->people !== null) {
            new PeopleChannelService()->recordOnPerson($message, $lead->people, $user);
        }
    }

    public function slugFor(Lead $lead): string
    {
        return self::SLUG_PREFIX . $lead->getId();
    }

    /**
     * Caller-supplied user → AI agent user → Lead creator. No auth() —
     * runs in queued/background contexts where no request is in scope.
     */
    private function resolveOwnerUser(Lead $lead, Companies $company, ?Users $user): Users
    {
        if ($user !== null) {
            return $user;
        }

        $aiAgentUser = $company->getAiAgentUser();
        if ($aiAgentUser !== null) {
            return $aiAgentUser;
        }

        $creator = $lead->user;
        if ($creator instanceof Users) {
            return $creator;
        }

        throw new ValidationException(
            'Cannot create or attach to a Lead channel: no user passed in, '
            . 'no AI agent user configured for company ' . $company->getId()
            . ', and Lead row ' . $lead->getId() . ' has no creator.'
        );
    }
}
