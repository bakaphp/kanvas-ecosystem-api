<?php

declare(strict_types=1);

namespace Tests\Connectors\Azul;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Webhook\AzulMethodNotificationWebhookJob;
use Kanvas\Connectors\Azul\Webhook\AzulTermUrlWebhookJob;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class AzulThreeDSReceiverHtmlTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    public function testTheTermUrlAnswersWithAPageThatNotifiesTheParentFrame(): void
    {
        $receiver = $this->receiver(AzulTermUrlWebhookJob::class, 'Azul 3DS Challenge Result');
        $payment = $this->payment();

        $response = $this->post($receiver->getUrl() . '?payment_id=' . $payment->getId(), ['cRes' => 'eyJ0cmFucyI6IjEifQ']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString(
            'window.parent.postMessage({type: "3ds_complete", status: "success"',
            (string) $response->getContent()
        );
        $this->assertSame('eyJ0cmFucyI6IjEifQ', $payment->refresh()->getMetadata('3ds_pa_res'));
    }

    public function testTheTermUrlWithoutAPaymentStillAnswersWithAnErrorPage(): void
    {
        $receiver = $this->receiver(AzulTermUrlWebhookJob::class, 'Azul 3DS Challenge Result');

        $response = $this->post($receiver->getUrl(), ['cRes' => 'x']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('status: "error"', (string) $response->getContent());
    }

    public function testTheMethodNotificationAnswersWithAPageThatNotifiesTheTopWindow(): void
    {
        $receiver = $this->receiver(AzulMethodNotificationWebhookJob::class, 'Azul 3DS Method Notification');
        $payment = $this->payment();
        $methodData = base64_encode(json_encode(['threeDSServerTransID' => 'trans-123']));

        $response = $this->post($receiver->getUrl() . '?payment_id=' . $payment->getId(), ['threeDSMethodData' => $methodData]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString(
            'window.top.postMessage({"type":"3ds_method_complete","threeDSServerTransID":"trans-123"}, "*")',
            (string) $response->getContent()
        );
        $this->assertSame('trans-123', $payment->refresh()->getMetadata('3ds_server_trans_id'));
    }

    private function receiver(string $jobClass, string $name): ReceiverWebhook
    {
        $action = WorkflowAction::query()->firstOrCreate(['model_name' => $jobClass], ['name' => $name]);

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->kanvasApp,
                company: $this->company,
                user: $this->company->user,
                action: $action,
                name: $name,
                description: $name,
                is_active: true,
                run_async: false,
            ),
        )->execute();
    }

    private function payment(): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = auth()->user()->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = 1;
        $payment->payable_type = 'order';
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->concept = 'Payment';
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }
}
