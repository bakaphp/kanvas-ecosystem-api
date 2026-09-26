<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

/**
 * Reads the settled transaction off the Payment rather than the processor's live
 * response, so a report can be rebuilt and retried long after the charge cleared.
 */
class BuildInsurancePaymentReportAction
{
    public function __construct(
        protected Order $order,
        protected Payments $payment,
    ) {
    }

    public function execute(): InsurancePaymentReport
    {
        $quoteNumber = (string) $this->order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value);

        return new InsurancePaymentReport(
            quoteNumber: $quoteNumber,
            processorReference: (string) ($this->payment->payment_intent_id ?? ''),
            authorizationCode: (string) ($this->payment->authorization_code ?? ''),
            isoResponseCode: $this->fromMetadata('iso_code'),
            responseCode: $this->fromMetadata('response_code'),
            responseMessage: $this->fromMetadata('response_message'),
            amount: (float) $this->order->get(InsuranceCustomFieldEnum::TOTAL->value),
            tax: (float) $this->order->get(InsuranceCustomFieldEnum::TAX->value),
            orderReference: $quoteNumber !== '' ? 'COT-' . $quoteNumber : null,
            transactedAt: $this->transactedAt(),
            cardLastFour: $this->payment->payment_method_last_four ?: null,
            cardBrand: $this->payment->payment_method_brand ?: null,
            retrievalReference: $this->fromMetadata('rrn'),
            batchNumber: $this->fromMetadata('lot_number'),
            receiptNumber: (string) ($this->payment->number ?? ''),
        );
    }

    protected function fromMetadata(string $key): string
    {
        return (string) ($this->payment->getMetadata($key) ?? '');
    }

    /**
     * Azul stamps `YmdHis`; the insurer wants ISO-8601. A processor that already
     * hands back an ISO string parses here too, so this is not Azul-specific.
     */
    protected function transactedAt(): ?string
    {
        $stamped = $this->fromMetadata('datetime');

        if ($stamped === '') {
            return null;
        }

        try {
            $parsed = strlen($stamped) === 14 && ctype_digit($stamped)
                ? Carbon::createFromFormat('YmdHis', $stamped)
                : Carbon::parse($stamped);
        } catch (Throwable) {
            return null;
        }

        return $parsed === false ? null : $parsed->toIso8601ZuluString('millisecond');
    }
}
