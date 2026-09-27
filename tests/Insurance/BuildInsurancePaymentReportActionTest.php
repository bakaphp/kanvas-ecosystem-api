<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Insurance\Actions\BuildInsurancePaymentReportAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\TestCase;

class BuildInsurancePaymentReportActionTest extends TestCase
{
    private function order(array $fields = []): Order
    {
        $fields = [
            InsuranceCustomFieldEnum::QUOTE_NUMBER->value => '329033',
            InsuranceCustomFieldEnum::TOTAL->value => 5163.0,
            InsuranceCustomFieldEnum::TAX->value => 712.12,
            ...$fields,
        ];

        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')->andReturnUsing(fn (string $key) => $fields[$key] ?? null);

        return $order;
    }

    private function payment(array $metadata = [], array $columns = []): Payments
    {
        $metadata = [
            'iso_code' => '00',
            'response_code' => 'ISO8583',
            'response_message' => 'APROBADA',
            'rrn' => '2025120310503244859568',
            'lot_number' => '',
            'datetime' => '20260827142311',
            'masked_card_number' => '540000******1732',
            ...$metadata,
        ];

        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->shouldReceive('getMetadata')->andReturnUsing(fn (string $key) => $metadata[$key] ?? null);
        $payment->payment_intent_id = $columns['payment_intent_id'] ?? '44859568';
        $payment->authorization_code = $columns['authorization_code'] ?? 'OK7396';
        $payment->number = $columns['number'] ?? '1';
        $payment->payment_method_last_four = $columns['payment_method_last_four'] ?? null;
        $payment->payment_method_brand = $columns['payment_method_brand'] ?? null;
        // Pre-seeded so the brand fallback never reaches the database from here.
        $payment->setRelation('paymentMethod', null);

        return $payment;
    }

    public function testItReadsTheSettledTransactionOffThePayment(): void
    {
        $report = new BuildInsurancePaymentReportAction($this->order(), $this->payment())->execute();

        $this->assertSame('329033', $report->quoteNumber);
        $this->assertSame('44859568', $report->processorReference);
        $this->assertSame('OK7396', $report->authorizationCode);
        $this->assertSame('00', $report->isoResponseCode);
        $this->assertSame('APROBADA', $report->responseMessage);
        $this->assertSame('COT-329033', $report->orderReference);
        $this->assertSame(5163.0, $report->amount);
        $this->assertSame(712.12, $report->tax);
    }

    /**
     * The card columns are only written by the 3DS capture paths, so on the
     * authorization this reports off they are empty and the masked PAN is all
     * there is. Regression for a report that silently carried neither.
     */
    public function testTheMaskedPanAnswersWhenTheCardColumnsWereNeverFilled(): void
    {
        $report = new BuildInsurancePaymentReportAction($this->order(), $this->payment())->execute();

        $this->assertSame('1732', $report->cardLastFour);
    }

    public function testAPopulatedCardColumnWinsOverTheMaskedPan(): void
    {
        $payment = $this->payment(columns: [
            'payment_method_last_four' => '4242',
            'payment_method_brand' => 'VISA',
        ]);

        $report = new BuildInsurancePaymentReportAction($this->order(), $payment)->execute();

        $this->assertSame('4242', $report->cardLastFour);
        $this->assertSame('VISA', $report->cardBrand);
    }

    public function testTheBrandFallsBackToTheStoredPaymentMethod(): void
    {
        $paymentMethod = Mockery::mock(PaymentMethods::class)->makePartial();
        $paymentMethod->payment_methods_brand = 'MASTERCARD';

        $payment = $this->payment();
        $payment->setRelation('paymentMethod', $paymentMethod);

        $report = new BuildInsurancePaymentReportAction($this->order(), $payment)->execute();

        $this->assertSame('MASTERCARD', $report->cardBrand);
    }

    public function testNoCardDataAnywhereLeavesBothFieldsUnset(): void
    {
        $payment = $this->payment(['masked_card_number' => '']);

        $report = new BuildInsurancePaymentReportAction($this->order(), $payment)->execute();

        $this->assertNull($report->cardLastFour);
        $this->assertNull($report->cardBrand);
    }

    public function testAzulsStampIsConvertedToTheIsoFormatTheInsurerExpects(): void
    {
        $report = new BuildInsurancePaymentReportAction($this->order(), $this->payment())->execute();

        $this->assertSame('2026-08-27T14:23:11.000Z', $report->transactedAt);
    }

    public function testAnUnparseableStampIsDroppedRatherThanCrashingTheReport(): void
    {
        foreach (['', 'not-a-date', '99999999999999'] as $stamped) {
            $payment = $this->payment(['datetime' => $stamped]);
            $report = new BuildInsurancePaymentReportAction($this->order(), $payment)->execute();

            $this->assertNull($report->transactedAt, $stamped);
        }
    }

    public function testAnOrderWithoutAQuoteCarriesNoOrderReference(): void
    {
        $order = $this->order([InsuranceCustomFieldEnum::QUOTE_NUMBER->value => '']);

        $report = new BuildInsurancePaymentReportAction($order, $this->payment())->execute();

        $this->assertSame('', $report->quoteNumber);
        $this->assertNull($report->orderReference);
    }
}
