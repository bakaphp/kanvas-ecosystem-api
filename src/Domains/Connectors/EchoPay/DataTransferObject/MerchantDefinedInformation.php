<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\DataTransferObject;

use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantOptionsEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Spatie\LaravelData\Data;

class MerchantDefinedInformation extends Data
{
    public function __construct(
        public readonly MerchantCategoryEnum $category,
        public readonly string $cardIdentifier,
        public readonly MerchantPlatformEnum $platform,
        public readonly string $customerId,
        public readonly MerchantTokenizationEnum $tokenization,
        public readonly MerchantDocumentTypesEnum $documentType,
        public readonly string $documentNumber,
    ) {
    }

    public function toArray(): array
    {
        return [
            [
                'key' => MerchantOptionsEnum::MERCHANT_CATEGORY->value,
                'value' => $this->category->value,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_CARD_IDENTIFIER->value,
                'value' => $this->cardIdentifier,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_PLATFORM->value,
                'value' => $this->platform->value,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_CUSTOMER_ID->value,
                'value' => $this->customerId,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_TOKENIZATION->value,
                'value' => $this->tokenization->value,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_DOCUMENT_TYPE->value,
                'value' => $this->documentType->value,
            ],
            [
                'key' => MerchantOptionsEnum::MERCHANT_DOCUMENT_NUMBER->value,
                'value' => $this->documentNumber,
            ],
        ];
    }
}
