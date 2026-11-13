<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CardNet\Ztrans\Services\RedirectReceiverService;
use Kanvas\Connectors\CardNet\Ztrans\Webhook\CardNetZtransRedirectWebhookJob;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use ReflectionClass;
use Tests\TestCase;

final class CardNetZtransRedirectWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'workflow'];

    private const string PAN = '5555555555555557';

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    public function testPaymentIdFromTheQueryStringIsRecordedWithOnlyTheRawResult(): void
    {
        $payment = $this->payment($this->company);

        $results = $this->runJob(
            "https://api.test/v1/receiver/abc?payment_id={$payment->getId()}",
            ['result' => 'KO', 'rd' => 'ignored', 'cardNumber' => self::PAN]
        );

        $payment->refresh();
        $this->assertSame('KO', $payment->getMetadata('ztrans_3ds_challenge_result'));
        $this->assertNotNull($payment->getMetadata('ztrans_3ds_challenge_returned_at'));
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertStringNotContainsString(self::PAN, json_encode($payment->metadata));
        $this->assertStringContainsString('"status":"success"', $results['html_response']);
        $this->assertStringContainsString('"payment_id":' . $payment->getId(), $results['html_response']);
    }

    public function testPaymentIdFallsBackToTheEchoedRdField(): void
    {
        $payment = $this->payment($this->company);
        $rd = base64_encode('payment_id=' . $payment->getId() . '&integratorTxId=tx-1');

        $results = $this->runJob('https://api.test/v1/receiver/abc', ['rd' => $rd, 'result' => 'KO']);

        $this->assertSame('KO', $payment->refresh()->getMetadata('ztrans_3ds_challenge_result'));
        $this->assertStringContainsString('"status":"success"', $results['html_response']);
    }

    public function testUnknownPaymentStillAnswersWithAnErrorNotification(): void
    {
        $results = $this->runJob('https://api.test/v1/receiver/abc?payment_id=999999999', ['result' => 'KO']);

        $this->assertStringContainsString('"status":"error"', $results['html_response']);
        $this->assertStringContainsString('"payment_id":null', $results['html_response']);
    }

    public function testMissingPaymentIdAnswersWithAnErrorNotification(): void
    {
        $results = $this->runJob('https://api.test/v1/receiver/abc', ['rd' => '%%%not-base64%%%']);

        $this->assertStringContainsString('"status":"error"', $results['html_response']);
    }

    public function testPaymentOfAnotherCompanyIsIgnored(): void
    {
        $otherCompany = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $foreign = $this->payment($otherCompany);

        $results = $this->runJob("https://api.test/v1/receiver/abc?payment_id={$foreign->getId()}", ['result' => 'KO']);

        $this->assertNull($foreign->refresh()->getMetadata('ztrans_3ds_challenge_result'));
        $this->assertStringContainsString('"status":"error"', $results['html_response']);
    }

    public function testHtmlNotifiesBothTheParentFrameAndTheReactNativeWebView(): void
    {
        $payment = $this->payment($this->company);

        $html = $this->runJob("https://api.test/v1/receiver/abc?payment_id={$payment->getId()}", [])['html_response'];

        $this->assertStringContainsString('"type":"3ds_complete"', $html);
        $this->assertStringContainsString('window.parent.postMessage(message, "*")', $html);
        $this->assertStringContainsString('window.ReactNativeWebView.postMessage(JSON.stringify(message))', $html);
    }

    public function testLogCarriesFieldNamesOnly(): void
    {
        $payment = $this->payment($this->company);

        $this->runJob(
            "https://api.test/v1/receiver/abc?payment_id={$payment->getId()}",
            ['result' => 'KO', 'cardNumber' => self::PAN]
        );

        $log = PaymentLogs::where('payments_id', $payment->getId())
            ->where('event_type', 'ztrans_3ds_redirect_received')
            ->firstOrFail();
        $serialized = json_encode($log->getAttributes());

        $this->assertStringContainsString('cardNumber', $serialized);
        $this->assertStringNotContainsString(self::PAN, $serialized);
    }

    public function testTheChallengeResultIsStoredOnlyAsAShortScalar(): void
    {
        $payment = $this->payment($this->company);
        $url = "https://api.test/v1/receiver/abc?payment_id={$payment->getId()}";

        $this->runJob($url, ['result' => ['nested' => str_repeat('x', 500)]]);
        $this->assertNull($payment->refresh()->getMetadata('ztrans_3ds_challenge_result'));

        $this->runJob($url, ['result' => str_repeat('y', 100)]);
        $this->assertSame(str_repeat('y', 32), $payment->refresh()->getMetadata('ztrans_3ds_challenge_result'));
    }

    public function testTheReceiverEndpointAnswersTheIssuerRedirectWithTheHtmlPage(): void
    {
        WorkflowAction::query()->firstOrCreate(
            ['model_name' => CardNetZtransRedirectWebhookJob::class],
            ['name' => 'CardNet Ztrans 3DS Challenge Redirect']
        );
        $receiver = new RedirectReceiverService()->ensure($this->kanvasApp, $this->company);
        $payment = $this->payment($this->company);

        $response = $this->post($receiver->getUrl() . '?payment_id=' . $payment->getId(), ['result' => 'OK']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('"payment_id":' . $payment->getId(), (string) $response->getContent());
        $this->assertSame('OK', $payment->refresh()->getMetadata('ztrans_3ds_challenge_result'));
    }

    private function runJob(string $url, array $payload): array
    {
        $receiver = new ReceiverWebhook();
        $receiver->apps_id = $this->kanvasApp->getId();
        $receiver->companies_id = $this->company->getId();

        $call = new ReceiverWebhookCall();
        $call->url = $url;
        $call->payload = $payload;

        $job = new ReflectionClass(CardNetZtransRedirectWebhookJob::class)->newInstanceWithoutConstructor();
        $base = new ReflectionClass(ProcessWebhookJob::class);
        $base->getProperty('receiver')->setValue($job, $receiver);
        $base->getProperty('webhookRequest')->setValue($job, $call);

        return $job->execute();
    }

    private function payment(Companies $company): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = auth()->user()->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = 1;
        $payment->payable_type = 'order';
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->concept = 'Payment';
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }
}
