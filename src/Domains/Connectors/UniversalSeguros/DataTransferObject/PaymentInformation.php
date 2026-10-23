<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Kanvas\Connectors\UniversalSeguros\Concerns\OmitsNulls;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Override;
use Spatie\LaravelData\Data;

/**
 * Their field names are Azul's, but the report is fed from the Payment, so any
 * processor that yields an ISO-8583 style response maps onto the same shape.
 */
class PaymentInformation extends Data
{
    use OmitsNulls;

    public function __construct(
        public string $authorizationCode,
        public string $responseMessage,
        public string $responseCode,
        public string $isoCode,
        public string $azulOrderId,
        public float $amount,
        public float $tax,
        public ?string $customOrderId = null,
        public ?string $dateTime = null,
        public ?string $lotNumber = null,
        public ?string $rrn = null,
        public ?string $ticket = null,
        public ?string $cardLast4Digits = null,
        public ?string $cardBrand = null,
        public int|string|null $numeroCotizacion = null,
        public ?string $noPoliza = null,
    ) {
    }

    public static function fromReport(InsurancePaymentReport $report): self
    {
        return new self(
            authorizationCode: $report->authorizationCode,
            responseMessage: $report->responseMessage,
            responseCode: $report->responseCode,
            isoCode: $report->isoResponseCode,
            azulOrderId: $report->processorReference,
            amount: $report->amount,
            tax: $report->tax,
            customOrderId: $report->orderReference,
            dateTime: $report->transactedAt,
            lotNumber: $report->batchNumber,
            rrn: $report->retrievalReference,
            ticket: $report->receiptNumber,
            cardLast4Digits: self::toLastFour($report->cardLastFour),
            cardBrand: self::toBrand($report->cardBrand),
            numeroCotizacion: $report->policyNumber === null
                ? self::toQuoteNumber($report->quoteNumber)
                : null,
            noPoliza: $report->policyNumber,
        );
    }

    #[Override]
    public function toArray(): array
    {
        return self::withoutNulls(parent::toArray());
    }

    /**
     * They reject anything but four digits, and the field is optional, so a
     * masked or absent value is dropped rather than sent and refused.
     */
    protected static function toLastFour(?string $lastFour): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $lastFour);

        return strlen((string) $digits) === 4 ? $digits : null;
    }

    protected static function toBrand(?string $brand): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $brand));

        return match ($normalized) {
            'VISA' => 'VISA',
            'MASTERCARD', 'MC' => 'MASTERCARD',
            'AMEX', 'AMERICANEXPRESS' => 'AMEX',
            default => null,
        };
    }

    protected static function toQuoteNumber(string $quoteNumber): int|string|null
    {
        if ($quoteNumber === '') {
            return null;
        }

        return ctype_digit($quoteNumber) ? (int) $quoteNumber : $quoteNumber;
    }
}
