<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Find-or-create a Channel + Session for a Lead's People on a given channel type
 * ('sms', 'email', 'whatsapp', 'respondio'). Two People-keyed invariants:
 *
 *   1. The Channel is slug-keyed (per-recipient, e.g. "twilio-+15551234567") so
 *      future inbound webhooks for this contact land on the same row — but its
 *      entity is **People**, not Lead. The channel is durable across sales
 *      cycles; new Leads for the same prospect inherit it for free.
 *
 *   2. The Session is **People-keyed** (entity_namespace = People::class) — same
 *      shape as findOrCreatePeopleSession in AgentChatMutation. The agent sees
 *      one continuous conversation per prospect rather than per-Lead. Cross-cycle
 *      context (prior outreach, prior replies) shows up in history rollup
 *      without needing per-Lead joins.
 *
 * Used by the outbound-first AgentReachOut* flow. Pre-inbound channel bootstrap.
 */
class BootstrapChannelForLeadAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly string $channelType,
        protected readonly string $recipient,
        protected readonly Agent $agent,
    ) {
    }

    /**
     * @return array{0: Channel, 1: Session}
     */
    public function execute(): array
    {
        $people = $this->lead->people;
        if ($people === null) {
            // ResolveLeadChannelPreferencesAction already filters Leads without
            // People (phone/email contacts live on People), so this is a
            // data-integrity check rather than a runtime case we expect.
            throw new ValidationException(sprintf(
                'Lead #%d has no People; cannot bootstrap a People-keyed channel/session.',
                $this->lead->getId(),
            ));
        }

        $slug = SessionChannelService::createChannelSlug($this->channelType, $this->recipient);

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $this->lead->app,
                'companies' => $this->lead->company,
                'users' => $this->lead->user,
                'entity_id' => $people->getId(),
                'entity_namespace' => People::class,
                'name' => ucfirst($this->channelType) . ' — ' . ($people->getName() ?: 'Prospect'),
                'slug' => $slug,
            ])
        )->execute();

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $this->lead->app,
                'company' => $this->lead->company,
                'channel' => $channel,
                'entity_namespace' => People::class,
                'entity_id' => $people->getId(),
                'canal_id' => SessionChannelService::createCanalId($this->channelType, $this->recipient),
                'user' => [
                    'name' => $people->getName() ?: 'Prospect',
                    'id' => $people->getId(),
                    'email' => $people->getEmails()->first()?->value,
                ],
                'agent' => $this->agent,
            ])
        )->execute();

        return [$channel, $session];
    }
}
