<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\DataTransferObject;

use Spatie\LaravelData\Data;

class CardNetPurchaseRequest extends Data
{
    public function __construct(
        public readonly string $trxToken,
        public readonly string $order,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $invoice,
        public readonly bool $capture = true,
        public readonly int $installments = 1,
        public readonly ?int $tax = null,
        public readonly ?int $tip = null,
        public readonly ?string $description = null,
        public readonly ?string $uniqueId = null,
        public readonly ?string $customerIp = null,
        public readonly ?string $customerUserAgent = null,
        public readonly ?string $additionalData = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'TrxToken'     => $this->trxToken,
            'Order'        => $this->order,
            'Amount'       => $this->amount,
            'Currency'     => $this->currency,
            'Capture'      => $this->capture,
            'Installments' => $this->installments,
            'DataDo'       => [
                'Invoice' => $this->invoice,
                'Tax'     => $this->tax,
            ],
        ];

        if ($this->tip !== null) {
            $data['Tip'] = $this->tip;
        }

        if ($this->description !== null) {
            $data['Description'] = $this->description;
        }

        if ($this->uniqueId !== null) {
            $data['UniqueID'] = $this->uniqueId;
        }

        if ($this->customerIp !== null) {
            $data['CustomerIP'] = $this->customerIp;
        }

        if ($this->customerUserAgent !== null) {
            $data['CustomerUserAgent'] = $this->customerUserAgent;
        }

        if ($this->additionalData !== null) {
            $data['AdditionalData'] = $this->additionalData;
        }

        return $data;
    }
}
