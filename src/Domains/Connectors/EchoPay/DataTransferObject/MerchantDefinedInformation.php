<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantOptionsEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Spatie\LaravelData\Data;

class MerchantDefinedInformationData extends Data
{
    public function __construct(
        public readonly MerchantCategoryEnum $merchantCategory,
        public readonly string $merchantCardIdentifier,
        public readonly MerchantPlatformEnum $merchantPlatform,
        public readonly string $merchantCustomerId,
        public readonly MerchantTokenizationEnum $merchantTokenization,
        public readonly MerchantDocumentTypesEnum $merchantDocumentType,
        public readonly string $merchantDocumentNumber,
    ) {
    }

    public function toArray(): array
    {
        return [
            [
                'key' => MerchantOptionsEnum::MERCHANT_CATEGORY->value,
                'value' => $this->merchantCategory,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_CARD_IDENTIFIER->value,
                'value' => $this->merchantCardIdentifier,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_PLATFORM->value,
                'value' => $this->merchantPlatform,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_CUSTOMER_ID->value,
                'value' => $this->merchantCustomerId,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_TOKENIZATION->value,
                'value' => $this->merchantTokenization,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_DOCUMENT_TYPE->value,
                'value' => $this->merchantDocumentType,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_DOCUMENT_NUMBER->value,
                'value' => $this->merchantDocumentNumber,
            ],
        ];
    }
}
