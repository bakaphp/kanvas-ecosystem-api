<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Jobs;

use Exception;
use Kanvas\Guild\Customers\Actions\CreateOrUpdatePeopleSubscription;
use Kanvas\Guild\Customers\DataTransferObject\PeopleSubscription as PeopleSubscriptionDTO;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

// Maybe add action at the of the class name
class UpdatePeopleGhostSubscription extends ProcessWebhookJob
{
    public function execute(): array
    {
        $member = $this->webhookRequest->payload;
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $people = PeoplesRepository::getByEmail($member['email'], $company);
        if (! $people) {
            throw new Exception('People not found');
        }
        $dto = new PeopleSubscriptionDTO(
            app: $app,
            people: $people,
            subscription_type: 'Free',
            status: '1',
            first_date: date('Y-m-d H:i:s', $member['created_at']),
            start_date: date('Y-m-d H:i:s', $member['created_at']),
            metadata: $this->webhookRequest->payload
        );
        $action = new CreateOrUpdatePeopleSubscription($dto);
        $peopleSub = $action->handle();

        return [
            'success' => true,
            'data' => $peopleSub,
        ];
    }
}
