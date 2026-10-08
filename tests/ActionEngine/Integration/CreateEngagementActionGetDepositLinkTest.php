<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use ReflectionMethod;
use Stripe\PaymentLink;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

final class CreateEngagementActionGetDepositLinkTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testStoresThePaymentLinkNextToTheAmount(): void
    {
        $message = $this->replaceLink(['amount' => 250]);

        $this->assertSame(250, $message->message['data']['amount']);
        $this->assertNotEmpty($message->message['data']['link']);
        $this->assertSame($message->message['action_link'], $message->message['data']['link']);
        $this->assertSame($message->message['preview_link'], $message->message['data']['link']);
        $this->assertSame('https://action.page/get-deposit', $message->message['normal_action_link']);
    }

    public function testLeavesTheMessageAloneWithoutAnAmount(): void
    {
        $message = $this->replaceLink([]);

        $this->assertArrayNotHasKey('link', $message->message['data']);
        $this->assertSame('https://action.page/get-deposit', $message->message['action_link']);
    }

    private function replaceLink(array $data): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'action_link' => 'https://action.page/get-deposit',
                    'preview_link' => 'https://action.page/get-deposit?preview=true',
                    'data' => $data,
                ],
            ]);

        $stripe = new FakeStripeClient();
        $stripe->getPaymentLinks()->queueResponse(
            'create',
            PaymentLink::constructFrom(['id' => 'plink_test', 'url' => 'https://buy.stripe.com/test'])
        );

        // replaceLink reads only the lead and the message; the real constructor resolves a receiver and a sales app.
        $action = new class ($stripe) extends CreateEngagementAction {
            public function __construct(
                private readonly FakeStripeClient $stripe
            ) {
            }

            protected function stripePaymentLinkService(Lead $lead): StripePaymentLinkService
            {
                return new StripePaymentLinkService($lead->app, $lead->company, $this->stripe);
            }
        };

        new ReflectionMethod($action, 'replaceLink')->invoke(
            $action,
            $lead,
            $message,
            'get-deposit',
            $data
        );

        return $message->refresh();
    }
}
