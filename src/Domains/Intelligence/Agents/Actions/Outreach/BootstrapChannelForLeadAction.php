<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Find-or-create the Channel + Session for the outbound-first reach-out flow.
 * Uses the master People channel — conversations are per-Person, not per-protocol.
 * Legacy tenants still on per-protocol slug channels run via the deprecated
 * LeadAgentFirstMessageOutreachActivity; this new activity is opt-in.
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
            // ResolveLeadChannelPreferencesAction filters these out upstream — this
            // guard is for data-integrity, not an expected runtime case.
            throw new ValidationException(sprintf(
                'Lead #%d has no People; cannot bootstrap a People-keyed channel/session.',
                $this->lead->getId(),
            ));
        }

        $channel = new PeopleChannelService()->findOrCreateForPeople(
            $people,
            $this->lead->app,
            $this->lead->company,
            $this->lead->user,
        );

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
