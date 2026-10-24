<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\TestCase;

final class PortalProcessorDeclineLogTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testCaptureDeclineFillsThePaymentLogErrorColumns(): void
    {
        $payment = $this->seedAuthorizedPayment();
        $service = Mockery::mock(EchoPayService::class);
        $service->shouldReceive('capturePayment')->once()->andThrow(new EchoPayException(
            message: 'Payment not approved',
            code: 400,
            errorBody: ['data' => [
                'errorInformation' => ['reason' => 'INSUFFICIENT_FUND', 'message' => 'Decline - Insufficient funds in the account.'],
                'processorInformation' => ['responseCode' => '51'],
                'paymentInsightsInformation' => ['responseInsights' => ['category' => 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME']],
            ]]
        ));
        $processor = new PortalProcessor(
            app: app(Apps::class),
            company: static::$cachedUser->getCurrentCompany(),
            service: $service,
        );

        $processor->capture($payment, $payment->payable);

        $log = PaymentLogs::query()
            ->where('payments_id', $payment->getId())
            ->where('event_type', 'payment_error')
            ->firstOrFail();

        $this->assertSame('INSUFFICIENT_FUND', $log->error_code);
        $this->assertSame('Decline - Insufficient funds in the account.', $log->error_message);
        $this->assertSame('51', $log->processor_response_code);
        $this->assertSame('ISSUER_CANNOT_APPROVE_AT_THIS_TIME', $log->response_insight);
        $this->assertSame('capture_payment', $log->metadata['context']);
    }

    private function seedAuthorizedPayment(): Payments
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'status' => OrderStatusEnum::PENDING->value,
                'total_gross_amount' => 100.0,
                'total_net_amount' => 100.0,
            ]);

        $payment = new Payments();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->users_id = $user->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = now()->toDateString();
        $payment->payment_method = 'card';
        $payment->concept = 'Payment ' . $order->reference;
        $payment->amount = 100.0;
        $payment->currency = 'DOP';
        $payment->status = PaymentStatusEnum::AUTHORIZED->value;
        $payment->payment_intent_id = 'intent-1';
        $payment->is_deleted = false;
        $payment->saveOrFail();

        return $payment;
    }
}
