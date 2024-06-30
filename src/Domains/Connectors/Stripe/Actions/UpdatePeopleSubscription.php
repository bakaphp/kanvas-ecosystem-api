<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\PeopleSubscription;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Stripe\StripeClient;

// Maybe add action at the of the class name
class UpdatePeopleSubscription
{
    public function __construct(public array $data, public ?Apps $app = null)
    {
        $this->app = $app ?? app(Apps::class);
    }

    public function execute()
    {
        $webhookSub = $this->data['data']['object'];

        $stripe = new StripeClient($this->app->get('stripe_secret_key'));
        // Log::info($webhookSub);
        // die;
        $customer = $stripe->customers->retrieve(
            $webhookSub['customer'],
            ['expand' => ['subscriptions']]
        );
        $company = $this->app->companies->sortByDesc('id')->first();
        if (! $customer->email) {
            Log::info('Customer email not found');

            return;
        }
        $people = PeoplesRepository::getByEmail($customer->email, $company);
        if (! $people) {
            Log::info('People not found');

            return;
        }
        PeopleSubscription::updateOrCreate(
            [
                'subscription_type' => $customer->subscription->plan->nickname,
                'status' => '1',
                'first_date' => date('Y-m-d H:i:s',  $customer->subscription->created),
                'start_date' => date('Y-m-d H:i:s',  $customer->subscription->current_period_start),
                'end_date' => date('Y-m-d H:i:s',  $customer->subscription->ended_at),
                'next_renewal' => date('Y-m-d H:i:s',  $customer->subscription->current_period_end),
                'metadata' => json_encode($this->data),
            ],
            [
                'peoples_id' => $people->id,
            ]
        );
    }
}
