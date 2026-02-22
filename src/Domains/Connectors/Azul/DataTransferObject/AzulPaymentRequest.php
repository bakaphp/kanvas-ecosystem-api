<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\DataTransferObject;

use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Spatie\LaravelData\Data;

class AzulPaymentRequest extends Data
{
    public function __construct(
        public readonly string $channel,
        public readonly string $store,
        public readonly TransactionTypeEnum $trxType,
        public readonly string $amount,
        public readonly string $itbis,
        public readonly string $currencyPosCode = '$',
        public readonly string $posInputMode = 'E-Commerce',
        public readonly string $payments = '1',
        public readonly string $plan = '0',
        public readonly string $acquirerRefData = '1',
        public readonly string $eCommerceUrl = '',
        public readonly string $customOrderId = '',
        public readonly ?string $cardNumber = null,
        public readonly ?string $expiration = null,
        public readonly ?string $cvc = null,
        public readonly ?string $dataVaultToken = null,
        public readonly ?string $azulOrderId = null,
        public readonly ?string $rrn = null,
        public readonly string $customerServicePhone = '',
        public readonly string $orderNumber = '',
        public readonly string $forceNo3DS = '1',
        public readonly string $saveToDataVault = '0',
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'Channel'              => $this->channel,
            'Store'                => $this->store,
            'PosInputMode'         => $this->posInputMode,
            'TrxType'              => $this->trxType->value,
            'Amount'               => $this->amount,
            'Itbis'                => $this->itbis,
            'CurrencyPosCode'      => $this->currencyPosCode,
            'Payments'             => $this->payments,
            'Plan'                 => $this->plan,
            'AcquirerRefData'      => $this->acquirerRefData,
            'RRN'                  => $this->rrn,
            'CustomerServicePhone' => $this->customerServicePhone,
            'OrderNumber'          => $this->orderNumber,
            'ECommerceUrl'         => $this->eCommerceUrl,
            'CustomOrderId'        => $this->customOrderId,
            'ForceNo3DS'           => $this->forceNo3DS,
            'SaveToDataVault'      => $this->saveToDataVault,
        ];

        if ($this->expiration !== null) {
            $data['Expiration'] = $this->expiration;
        }

        if ($this->cardNumber !== null) {
            $data['CardNumber'] = $this->cardNumber;
            $data['CVC']        = $this->cvc;
        }

        if ($this->dataVaultToken !== null) {
            $data['DataVaultToken'] = $this->dataVaultToken;
        }

        if ($this->azulOrderId !== null) {
            $data['AzulOrderId'] = $this->azulOrderId;
        }

        return $data;
    }
}
