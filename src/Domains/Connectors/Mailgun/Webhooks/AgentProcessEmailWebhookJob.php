<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Kanvas\Connectors\Mailgun\Actions\CreateMessageFromEmailAction;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class AgentProcessEmailWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $from = $this->webhookRequest->payload['sender'] ?? null;
        $lead = null;

        $people = PeoplesRepository::getByEmail(
            $from,
            $this->webhookRequest->receiverWebhook->company,
            $this->webhookRequest->receiverWebhook->app,
        );

        if ($people !== null) {
            $lead = LeadsRepository::getPeopleActiveLead($people);
        }

        return new CreateMessageFromEmailAction($this->webhookRequest, $lead ?? null)
            ->execute()
            ->toArray();
    }
}
