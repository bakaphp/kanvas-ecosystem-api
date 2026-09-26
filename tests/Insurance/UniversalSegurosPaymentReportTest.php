<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\PaymentInformation;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class UniversalSegurosPaymentReportTest extends TestCase
{
    private function provider(MockInterface $service): UniversalSegurosProvider
    {
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('getId')->andReturn(1);

        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);
        $company->shouldReceive('get')->with(ConfigurationEnum::SCOPES->value)->andReturn('');

        return new UniversalSegurosProvider(
            app: $app,
            company: $company,
            service: $service,
        );
    }

    private function report(): InsurancePaymentReport
    {
        return new InsurancePaymentReport(
            quoteNumber: '',
            processorReference: '44859568',
            authorizationCode: 'OK7396',
            isoResponseCode: '00',
            responseCode: 'ISO8583',
            responseMessage: 'APROBADA',
            amount: 5163.0,
            tax: 712.12,
            orderReference: 'COT-329033',
            transactedAt: '2026-08-27T14:23:11.000Z',
            cardLastFour: '4242',
            cardBrand: 'VISA',
        );
    }

    public function testReportingAPaymentSendsTheQuoteNumberOffTheOrderAndFlipsItPaid(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('329033');
        $order->shouldReceive('set')
            ->once()
            ->with(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::PAID->value);

        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('assignPaymentInformation')
            ->once()
            ->with(Mockery::on(function (PaymentInformation $payment): bool {
                $payload = $payment->toArray();

                return $payload['numeroCotizacion'] === 329033
                    && $payload['azulOrderId'] === '44859568'
                    && $payload['isoCode'] === '00'
                    && ! array_key_exists('noPoliza', $payload);
            }))
            ->andReturn([]);

        $result = $this->provider($service)->reportPayment($order, $this->report());

        $this->assertTrue($result->success);
    }

    /**
     * A 204 carries no body, so the adapter must not read success out of the
     * response — a call that did not throw is all the signal there is.
     */
    public function testAnEmptyBodyStillCountsAsAReportedPayment(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('329033');
        $order->shouldReceive('set')->once();

        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('assignPaymentInformation')->once()->andReturn([]);

        $this->assertTrue($this->provider($service)->reportPayment($order, $this->report())->success);
    }

    public function testReportingAPaymentOnAnUnquotedOrderIsRejectedBeforeCallingTheInsurer(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ->andReturn('');

        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldNotReceive('assignPaymentInformation');

        $this->expectException(ValidationException::class);

        $this->provider($service)->reportPayment($order, $this->report());
    }

    public function testInvoicingSendsThePolicyNumberInsteadOfTheQuoteNumber(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::POLICY_NUMBER->value)
            ->andReturn('A-PA-0000001392');

        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldReceive('invoicePolicy')
            ->once()
            ->with(Mockery::on(function (PaymentInformation $payment): bool {
                $payload = $payment->toArray();

                return $payload['noPoliza'] === 'A-PA-0000001392'
                    && ! array_key_exists('numeroCotizacion', $payload);
            }))
            ->andReturn([]);

        $this->assertTrue($this->provider($service)->invoicePolicy($order, $this->report())->success);
    }

    public function testInvoicingBeforeEmissionIsRejected(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::POLICY_NUMBER->value)
            ->andReturn('');

        $service = Mockery::mock(UniversalSegurosService::class);
        $service->shouldNotReceive('invoicePolicy');

        $this->expectException(ValidationException::class);

        $this->provider($service)->invoicePolicy($order, $this->report());
    }
}
