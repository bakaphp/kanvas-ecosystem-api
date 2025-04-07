<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Jobs;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Actions\CreateOrUpdatePeopleSubscriptionAction;
use Kanvas\Guild\Customers\DataTransferObject\PeopleSubscription as PeopleSubscriptionDTO;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Stripe\StripeClient;

// Maybe add action at the of the class name
class UpdatePeopleStripeSubscriptionJob extends ProcessWebhookJob
{
    public array $data = [];

    public function execute(): array
    {
        if (! in_array($this->webhookRequest->payload['type'], ['customer.subscription.updated', 'customer.subscription.created', 'customer.subscription.deleted'])) {
            Log::error('Webhook type not found', ['type' => $this->webhookRequest->payload['type']]);

            return [];
        }

        $this->data = $this->webhookRequest->payload;
        $webhookSub = $this->data['data']['object'];
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $user = $this->webhookRequest->receiverWebhook->user;

        $stripe = new StripeClient($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
        $customer = $stripe->customers->retrieve(
            $webhookSub['customer'],
            ['expand' => ['subscriptions']]
        );

        if (! $customer->email) {
            //Log::error('Customer email not found');

            return ['error' => 'Customer email not found ' . $customer->id];
        }
        $people = PeoplesRepository::getByEmail($customer->email, $company);
        if (! $people) {
            //Log::error('People not found');

            return ['error' => 'People not found' . $customer->email];

            return [];
        }
        $subscriptions = $customer->subscriptions->data[0];

        $dto = new PeopleSubscriptionDTO(
            app: $app,
            people: $people,
            subscription_type: $subscriptions['plan']['nickname'] ?? $subscriptions['plan']['product'],
            status: '1',
            first_date: date('Y-m-d H:i:s', $subscriptions['created']),
            start_date: date('Y-m-d H:i:s', $subscriptions['current_period_start']),
            end_date: date('Y-m-d H:i:s', $subscriptions['ended_at']),
            next_renewal: date('Y-m-d H:i:s', $subscriptions['current_period_end']),
            metadata: $this->data ?? [],
        );
        $action = new CreateOrUpdatePeopleSubscriptionAction($dto);
        $peopleSub = $action->handle();

        return [
            'message' => 'People Subscription updated',
            'data' => $peopleSub,
        ];
    }
}
