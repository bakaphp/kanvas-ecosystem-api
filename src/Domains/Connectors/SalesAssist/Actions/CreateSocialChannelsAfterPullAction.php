<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;

class CreateSocialChannelsAfterPullAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly int $agentId,
    ) {
    }

    public function execute(): void
    {
        if ($this->lead->id === 0) {
            return;
        }

        $contacts = $this->lead->people?->contacts;

        if (! $contacts || $contacts->isEmpty()) {
            return;
        }

        $contacts = $contacts->sortBy('contacts_types_id');

        foreach ($contacts as $contact) {
            /**
             * @todo we have to pass the agent not the id
             */
            new CreateSocialChannelForContactAction(
                $contact,
                $this->app,
                array_merge($this->params, ['agent_id' => $this->agentId]),
                $this->lead,
                sendPusherNotification: true
            )->execute();
        }
    }
}
