<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

class CreateSocialChannelsAfterPullAction
{
    public function __construct(
        protected readonly Model $entity,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly ?People $people = null,
        protected readonly ?Lead $leadModel = null,
    ) {
    }

    public function execute(): void
    {
        if (empty($this->params['agent_id'])) {
            return;
        }

        if (! $this->entity instanceof Lead) {
            return;
        }

        $lead = $this->resolveLead();

        if (! $lead instanceof Lead || $lead->id === 0) {
            return;
        }

        $contacts = $lead->people?->contacts;

        if (! $contacts || $contacts->isEmpty()) {
            return;
        }

        foreach ($contacts as $contact) {
            new CreateSocialChannelForContactAction(
                $contact,
                $this->app,
                $this->params,
                $lead,
                sendPusherNotification: true
            )->execute();
        }
    }

    private function resolveLead(): ?Lead
    {
        if ($this->leadModel instanceof Lead && $this->leadModel->id > 0) {
            return $this->leadModel;
        }

        if ($this->people instanceof People) {
            return LeadsRepository::getPeopleActiveLead($this->people);
        }

        if ($this->entity->id > 0) {
            return $this->entity->refresh();
        }

        return null;
    }
}
