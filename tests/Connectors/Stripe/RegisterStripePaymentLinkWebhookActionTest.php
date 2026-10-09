<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Actions\RegisterStripePaymentLinkWebhookAction;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Connectors\Stripe\Webhooks\StripePaymentLinkWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Stripe\WebhookEndpoint;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * Serial: it writes app and company settings, which go to Redis and never roll back.
 */
#[Group('serial')]
final class RegisterStripePaymentLinkWebhookActionTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private FakeStripeClient $stripe;
    private mixed $originalCompanyKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->stripe = new FakeStripeClient();
        $this->originalCompanyKey = $this->company->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);

        $this->kanvasApp->del(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value);
        $this->company->del(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value);

        WorkflowAction::firstOrCreate(
            ['model_name' => StripePaymentLinkWebhookJob::class],
            ['name' => 'StripePaymentLinkWebhookJob'],
        );
    }

    protected function tearDown(): void
    {
        $this->originalCompanyKey === null
            ? $this->company->del(ConfigurationEnum::STRIPE_SECRET_KEY->value)
            : $this->company->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $this->originalCompanyKey);

        parent::tearDown();
    }

    public function testRegistersTheEndpointAndKeepsItsSecretOnTheReceiver(): void
    {
        $this->queueEndpoint('we_company', 'whsec_company');

        $receiver = $this->register(sharedAppAccount: false);

        $call = $this->stripe->getWebhookEndpoints()->getCalls('create')[0]['params'];
        $this->assertSame($receiver->getUrl(), $call['url']);
        $this->assertSame(['checkout.session.completed'], $call['enabled_events']);

        $this->assertSame('whsec_company', $receiver->configuration[ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value]);
        $this->assertSame('we_company', $receiver->configuration[ConfigurationEnum::STRIPE_WEBHOOK_ENDPOINT_ID->value]);
        $this->assertFalse($receiver->configuration[ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value]);
        $this->assertSame($this->company->getId(), $receiver->companies_id);
        $this->assertEquals($receiver->getId(), $this->company->get(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value));
    }

    public function testRegistersOnlyOncePerStripeAccount(): void
    {
        $this->queueEndpoint('we_once', 'whsec_once');

        $first = $this->register(sharedAppAccount: false);
        $second = $this->register(sharedAppAccount: false);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertCount(1, $this->stripe->getWebhookEndpoints()->getCalls('create'));
    }

    public function testSharedAppAccountIsRegisteredOnTheApp(): void
    {
        $this->queueEndpoint('we_app', 'whsec_app');

        $receiver = $this->register(sharedAppAccount: true);

        $this->assertTrue($receiver->configuration[ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value]);
        $this->assertSame($this->kanvasApp->getAppCompany()->getId(), $receiver->companies_id);
        $this->assertEquals($receiver->getId(), $this->kanvasApp->get(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value));
        $this->assertEmpty($this->company->get(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value));
    }

    public function testACompanyKeyIsItsOwnStripeAccount(): void
    {
        $this->company->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, 'sk_test_company_account');

        $account = StripeAccount::resolve($this->kanvasApp, $this->company);

        $this->assertFalse($account->sharedAppAccount);
        $this->assertSame('sk_test_company_account', $account->client()->getApiKey());
    }

    public function testACompanyWithoutAKeySharesTheAppAccount(): void
    {
        $this->company->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, '  ');

        $account = StripeAccount::resolve($this->kanvasApp, $this->company);

        $this->assertTrue($account->sharedAppAccount);
        $this->assertSame($this->kanvasApp->get(ConfigurationEnum::STRIPE_SECRET_KEY->value), $account->secretKey);
    }

    public function testStripeFailureLeavesNoReceiverBehind(): void
    {
        $this->stripe->getWebhookEndpoints()->queueResponse('create', new RuntimeException('Stripe is down'));
        $liveReceivers = $this->livePaymentLinkReceivers();

        try {
            $this->register(sharedAppAccount: false);
            $this->fail('Expected the Stripe failure to propagate');
        } catch (RuntimeException) {
        }

        $this->assertEmpty($this->company->get(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value));
        $this->assertSame($liveReceivers, $this->livePaymentLinkReceivers());
    }

    private function livePaymentLinkReceivers(): int
    {
        return ReceiverWebhook::where('companies_id', $this->company->getId())
            ->where('name', 'Stripe Payment Link Webhook')
            ->notDeleted()
            ->count();
    }

    private function register(bool $sharedAppAccount): ReceiverWebhook
    {
        $sharedAppAccount
            ? $this->company->del(ConfigurationEnum::STRIPE_SECRET_KEY->value)
            : $this->company->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, 'sk_test_company_account');

        return new RegisterStripePaymentLinkWebhookAction($this->kanvasApp, $this->company, $this->stripe)->execute();
    }

    private function queueEndpoint(string $id, string $secret): void
    {
        $this->stripe->getWebhookEndpoints()->queueResponse(
            'create',
            WebhookEndpoint::constructFrom(['id' => $id, 'secret' => $secret]),
        );
    }
}
