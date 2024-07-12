<?php

declare(strict_types=1);

namespace Test\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Jobs\UpdatePeopleStripeSubscription;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Stripe\StripeClient;
use Tests\TestCase;
use Throwable;

final class UpdateSubscriptionTest extends TestCase
{
    public function testUpdateSubscription()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->has(Contact::factory()->count(1), 'contacts')
            ->create();

        $app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        $stripe = new StripeClient($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
        $customer = $stripe->customers->create([
            'email' => $people->getEmails()[0]->value,
            'name' => $people->getName(),
        ]);
        $paymentMethod = $stripe->paymentMethods->create([
          'type' => 'card',
          'card' => [
            'number' => '4242424242424242',
            'exp_month' => 8,
            'exp_year' => 2026,
            'cvc' => '314',
          ],
        ]);

        $stripe->paymentMethods->attach(
            $paymentMethod->id,
            ['customer' => $customer->id]
        );
        $stripe->customers->update(
            $customer->id,
            ['invoice_settings' => ['default_payment_method' => $paymentMethod->id]]
        );

        $prices = $stripe->prices->all();
        $stripe->subscriptions->create([
            'customer' => $customer->id,
            'items' => [
                ['price' => $prices->data[0]->id],
            ],
        ]);
        $payload = [
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'customer' => $customer->id,
                ],
            ],
        ];

        $workflowAction = WorkflowAction::firstOrCreate([
            'name' => 'Update People Subscription',
            'model_name' => UpdatePeopleStripeSubscription::class,
        ]);

        $receiverWebhook = ReceiverWebhook::factory()
               ->app($app->getId())
               ->user($user->getId())
               ->company($company->getId())
               ->create([
                      'action_id' => $workflowAction->getId(),
               ]);

        $request = Request::create('https://localhost/shopifytest', 'POST', $payload);

        // Execute the action and get the webhook request
        $webhookRequest = (new ProcessWebhookAttemptAction($receiverWebhook, $request))->execute();

        // Fake the queue
        Queue::fake();
        $job = new UpdatePeopleStripeSubscription($webhookRequest);
        $result = $job->handle();

        $this->assertArrayHasKey('message', $result);
        $this->assertEquals('People Subscription updated', $result['message']);
    }
}
