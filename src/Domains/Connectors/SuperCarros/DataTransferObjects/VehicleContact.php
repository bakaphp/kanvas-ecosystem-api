<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleContact extends Data
{
    public function __construct(
        public bool $hasContact,
        public string $fullName,
        public string $title,
        public string $phone,
        public string $phone2,
        public string $cellPhone,
        public string $whatsapp,
        public string $whatsappLink
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            hasContact: $data['HasContact'] ?? false,
            fullName: $data['ContactFullName'] ?? '',
            title: $data['ContactTitle'] ?? '',
            phone: $data['ContactPhone'] ?? '',
            phone2: $data['ContactPhone2'] ?? '',
            cellPhone: $data['ContactCellPhone'] ?? '',
            whatsapp: $data['ContactWhatsApp'] ?? '',
            whatsappLink: $data['ContactWhatsAppLink'] ?? ''
        );
    }
}
