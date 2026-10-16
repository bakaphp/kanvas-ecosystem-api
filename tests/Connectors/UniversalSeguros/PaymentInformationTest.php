<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Kanvas\Connectors\UniversalSeguros\DataTransferObject\PaymentInformation;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Tests\TestCase;

class PaymentInformationTest extends TestCase
{
    private function report(array $overrides = []): InsurancePaymentReport
    {
        $value = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        return new InsurancePaymentReport(
            quoteNumber: $value('quoteNumber', '201729'),
            processorReference: $value('processorReference', '44859568'),
            authorizationCode: $value('authorizationCode', 'OK7396'),
            isoResponseCode: $value('isoResponseCode', '00'),
            responseCode: $value('responseCode', 'ISO8583'),
            responseMessage: $value('responseMessage', 'APROBADA'),
            amount: $value('amount', 5323.0),
            tax: $value('tax', 734.0),
            orderReference: $value('orderReference', 'COT-201729'),
            transactedAt: $value('transactedAt', '2025-12-03T14:23:11.158Z'),
            cardLastFour: $value('cardLastFour', '4242'),
            cardBrand: $value('cardBrand', 'VISA'),
            retrievalReference: $value('retrievalReference', '2025120310503244859568'),
            batchNumber: $value('batchNumber', ''),
            receiptNumber: $value('receiptNumber', '1'),
            policyNumber: $value('policyNumber', null),
        );
    }

    public function testItMapsTheReportOntoTheInsurersFieldNames(): void
    {
        $payload = PaymentInformation::fromReport($this->report())->toArray();

        $this->assertSame('OK7396', $payload['authorizationCode']);
        $this->assertSame('44859568', $payload['azulOrderId']);
        $this->assertSame('00', $payload['isoCode']);
        $this->assertSame('ISO8583', $payload['responseCode']);
        $this->assertSame('APROBADA', $payload['responseMessage']);
        $this->assertSame('COT-201729', $payload['customOrderId']);
        $this->assertSame('2025120310503244859568', $payload['rrn']);
        $this->assertSame('1', $payload['ticket']);
        $this->assertSame(201729, $payload['numeroCotizacion']);
        $this->assertArrayNotHasKey('noPoliza', $payload);
    }

    public function testAmountsKeepTheirDecimalsInsteadOfBeingSentAsCents(): void
    {
        $payload = PaymentInformation::fromReport(
            $this->report(['amount' => 5163.0, 'tax' => 712.12])
        )->toArray();

        $this->assertSame(5163.0, $payload['amount']);
        $this->assertSame(712.12, $payload['tax']);
    }

    public function testInvoicingSwapsTheQuoteNumberForThePolicyNumber(): void
    {
        $payload = PaymentInformation::fromReport(
            $this->report()->forPolicy('A-PA-0000001392')
        )->toArray();

        $this->assertSame('A-PA-0000001392', $payload['noPoliza']);
        $this->assertArrayNotHasKey('numeroCotizacion', $payload);
    }

    /**
     * Their deserialiser 500s on an explicit null, so an absent optional has to
     * leave the payload entirely.
     */
    public function testUnsetOptionalsAreOmittedRatherThanSentAsNull(): void
    {
        $payload = PaymentInformation::fromReport(
            $this->report(['transactedAt' => null, 'retrievalReference' => null])
        )->toArray();

        $this->assertArrayNotHasKey('dateTime', $payload);
        $this->assertArrayNotHasKey('rrn', $payload);
        $this->assertArrayNotHasKey('noPoliza', $payload);
    }

    public function testACardBrandTheInsurerDoesNotAcceptIsDroppedRatherThanRefused(): void
    {
        foreach (['MasterCard', 'MASTER CARD', 'mc'] as $brand) {
            $payload = PaymentInformation::fromReport($this->report(['cardBrand' => $brand]))->toArray();
            $this->assertSame('MASTERCARD', $payload['cardBrand'], $brand);
        }

        foreach (['American Express', 'amex'] as $brand) {
            $payload = PaymentInformation::fromReport($this->report(['cardBrand' => $brand]))->toArray();
            $this->assertSame('AMEX', $payload['cardBrand'], $brand);
        }

        foreach (['Discover', '', null] as $brand) {
            $payload = PaymentInformation::fromReport($this->report(['cardBrand' => $brand]))->toArray();
            $this->assertArrayNotHasKey('cardBrand', $payload, (string) $brand);
        }
    }

    public function testCardLastFourIsOnlySentWhenItIsExactlyFourDigits(): void
    {
        $masked = PaymentInformation::fromReport(
            $this->report(['cardLastFour' => '540000******1732'])
        )->toArray();
        $this->assertArrayNotHasKey('cardLast4Digits', $masked);

        $short = PaymentInformation::fromReport($this->report(['cardLastFour' => '42']))->toArray();
        $this->assertArrayNotHasKey('cardLast4Digits', $short);

        $exact = PaymentInformation::fromReport($this->report(['cardLastFour' => '4242']))->toArray();
        $this->assertSame('4242', $exact['cardLast4Digits']);
    }
}
