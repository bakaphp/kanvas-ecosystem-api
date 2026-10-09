<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Webhooks\StripePaymentLinkWebhookJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class StripePaymentLinkWebhookJobTest extends TestCase
{
    private const string SECRET = 'whsec_payment_link_secret';
    private const string ACTION = 'get-deposit';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $kanvasUser;
    private ReceiverWebhook $receiver;
    private PipelineStage $submittedStage;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = auth()->user();
        $this->company = $this->kanvasUser->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => StripePaymentLinkWebhookJob::class],
            ['name' => 'StripePaymentLinkWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->kanvasUser->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testPaidCheckoutMarksTheDepositSubmitted(): void
    {
        $sentMessage = $this->sendDepositLink('plink_paid');

        $result = $this->dispatchCheckout('plink_paid', 'cs_paid');

        $submitted = $this->submittedEngagementsFor($sentMessage);
        $this->assertCount(1, $submitted, $result['message']);
        $this->assertSame($sentMessage->getId(), Message::getById($submitted->first()->message_id)->parent_id);
        $this->assertSame('cs_paid', $sentMessage->fresh()->get(CustomFieldEnum::STRIPE_CHECKOUT_SESSION_ID->value));
    }

    public function testRedeliveredCheckoutDoesNotSubmitTwice(): void
    {
        $sentMessage = $this->sendDepositLink('plink_redelivered');

        $this->dispatchCheckout('plink_redelivered', 'cs_redelivered');
        $result = $this->dispatchCheckout('plink_redelivered', 'cs_redelivered');

        $this->assertStringContainsString('already processed', $result['message']);
        $this->assertCount(1, $this->submittedEngagementsFor($sentMessage));
    }

    public function testUnpaidCheckoutIsNotSubmitted(): void
    {
        $sentMessage = $this->sendDepositLink('plink_unpaid');

        $result = $this->dispatchCheckout('plink_unpaid', 'cs_unpaid', paymentStatus: 'unpaid');

        $this->assertStringContainsString('not paid', $result['message']);
        $this->assertCount(0, $this->submittedEngagementsFor($sentMessage));
    }

    public function testCheckoutWithoutPaymentLinkDoesNotMatchAnyDeposit(): void
    {
        $sentMessage = $this->sendDepositLink('plink_untouched');

        $result = $this->dispatchCheckout(null, 'cs_plain_checkout');

        $this->assertSame('Checkout session has no payment link', $result['message']);
        $this->assertCount(0, $this->submittedEngagementsFor($sentMessage));
    }

    public function testSharedAccountReceiverResolvesTheCompanyFromTheLead(): void
    {
        $this->shareReceiverAcrossApp();
        $sentMessage = $this->sendDepositLink('plink_shared');
        $leadId = Engagement::where('message_id', $sentMessage->getId())->firstOrFail()->leads_id;

        $this->dispatchCheckout('plink_shared', 'cs_shared', metadata: ['leads_id' => (string) $leadId]);

        $this->assertCount(1, $this->submittedEngagementsFor($sentMessage));
    }

    public function testSharedAccountReceiverIgnoresASessionWithoutALead(): void
    {
        $this->shareReceiverAcrossApp();
        $sentMessage = $this->sendDepositLink('plink_shared_no_lead');

        $result = $this->dispatchCheckout('plink_shared_no_lead', 'cs_shared_no_lead');

        $this->assertStringContainsString('No lead found', $result['message']);
        $this->assertCount(0, $this->submittedEngagementsFor($sentMessage));
    }

    public function testReceiverWithoutSecretTrustsUnsignedRequests(): void
    {
        $this->assertTrue(StripePaymentLinkWebhookJob::authenticateRequest(
            $this->request('{}', signature: null),
            $this->receiver,
        ));
    }

    public function testReceiverWithSecretRejectsUnsignedAndForgedRequests(): void
    {
        $this->receiver->configuration = [ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value => self::SECRET];
        $this->receiver->saveOrFail();

        $raw = json_encode($this->checkoutEvent('plink_x', 'cs_x', 'paid'));

        $this->assertFalse(StripePaymentLinkWebhookJob::authenticateRequest($this->request($raw, signature: null), $this->receiver));
        $this->assertFalse(StripePaymentLinkWebhookJob::authenticateRequest(
            $this->request($raw, $this->sign($raw, 'whsec_someone_else')),
            $this->receiver,
        ));
        $this->assertTrue(StripePaymentLinkWebhookJob::authenticateRequest(
            $this->request($raw, $this->sign($raw, self::SECRET)),
            $this->receiver,
        ));
    }

    /**
     * Mirrors what CreateEngagementAction leaves behind for a sent get-deposit link, without calling Stripe.
     */
    private function sendDepositLink(string $paymentLinkId): Message
    {
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
        $lead->leads_owner_id = $this->kanvasUser->getId();
        $lead->users_id = $this->kanvasUser->getId();
        $lead->saveQuietly();
        $lead->refresh();

        $pipeline = Pipeline::firstOrCreate([
            'slug' => self::ACTION,
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
        ], [
            'users_id' => $this->kanvasUser->getId(),
            'name' => 'Get Deposit',
            'weight' => 0,
        ]);

        $sentStage = PipelineStage::firstOrCreate(
            ['pipelines_id' => $pipeline->getId(), 'slug' => ActionStatusEnum::SENT->value],
            ['name' => 'Sent', 'weight' => 0],
        );
        $this->submittedStage = PipelineStage::firstOrCreate(
            ['pipelines_id' => $pipeline->getId(), 'slug' => ActionStatusEnum::SUBMITTED->value],
            ['name' => 'Submitted', 'weight' => 1],
        );

        $action = Action::firstOrCreate(['slug' => self::ACTION], [
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Get Deposit',
        ]);

        $branch = $this->company->defaultBranch ?? $this->company->branch()->firstOrFail();
        $companyAction = CompanyAction::firstOrCreate([
            'actions_id' => $action->getId(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
        ], [
            'users_id' => $this->kanvasUser->getId(),
            'companies_branches_id' => $branch->getId(),
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Get Deposit',
        ]);

        $message = Message::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'message' => [
                    'verb' => self::ACTION,
                    'status' => ActionStatusEnum::SENT->value,
                    'data' => ['amount' => 500],
                ],
            ]);
        $message->set(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value, $paymentLinkId);

        Engagement::create([
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $message->getId(),
            'slug' => self::ACTION,
            'entity_uuid' => fake()->uuid(),
            'pipelines_stages_id' => $sentStage->getId(),
        ]);

        return $message;
    }

    private function submittedEngagementsFor(Message $sentMessage)
    {
        $leadId = Engagement::where('message_id', $sentMessage->getId())->firstOrFail()->leads_id;

        return Engagement::where('leads_id', $leadId)
            ->where('pipelines_stages_id', $this->submittedStage->getId())
            ->get();
    }

    private function shareReceiverAcrossApp(): void
    {
        $this->receiver->configuration = [ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value => true];
        $this->receiver->saveOrFail();
    }

    private function dispatchCheckout(
        ?string $paymentLinkId,
        string $sessionId,
        string $paymentStatus = 'paid',
        array $metadata = []
    ): array {
        $raw = json_encode($this->checkoutEvent($paymentLinkId, $sessionId, $paymentStatus, $metadata));
        $call = new ProcessWebhookAttemptAction($this->receiver, $this->request($raw, $this->sign($raw, self::SECRET)))->execute();

        return new StripePaymentLinkWebhookJob($call)->handle() ?? ['message' => (string) json_encode($call->fresh()->exception)];
    }

    private function checkoutEvent(
        ?string $paymentLinkId,
        string $sessionId,
        string $paymentStatus,
        array $metadata = []
    ): array {
        return [
            'id' => 'evt_' . uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_link' => $paymentLinkId,
                    'payment_status' => $paymentStatus,
                    'amount_total' => 50000,
                    'metadata' => $metadata,
                ],
            ],
        ];
    }

    private function request(string $raw, ?string $signature): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [],
            [],
            [],
            $server,
            $raw,
        );
    }

    private function sign(string $payload, string $secret): string
    {
        $timestamp = time();

        return "t={$timestamp},v1=" . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
