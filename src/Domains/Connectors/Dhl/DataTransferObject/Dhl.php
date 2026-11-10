<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;

class Dhl
{
    private const array REQUIRED_ORIGIN_FIELDS = [
        'company_name',
        'contact_name',
        'phone',
        'email',
        'address_line1',
        'city',
        'postal_code',
        'country_code',
    ];

    public function __construct(
        public readonly CompanyInterface $company,
        public readonly AppInterface $app,
        public readonly Regions $region,
        public readonly string $username,
        public readonly string $password,
        public readonly string $accountNumber,
        public readonly array $origin,
        public readonly string $baseUrl = ConfigurationEnum::DEFAULT_BASE_URL,
        public readonly string $signatureService = ConfigurationEnum::DEFAULT_SIGNATURE_SERVICE,
        public readonly string $labelTemplate = ConfigurationEnum::DEFAULT_LABEL_TEMPLATE,
        public readonly bool $requestPickup = true,
        public readonly string $pickupCloseTime = ConfigurationEnum::DEFAULT_PICKUP_CLOSE_TIME,
        public readonly ?string $contentDescription = null,
    ) {
    }

    public static function viaRequest(
        array $data,
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): self {
        $origin = $data['origin'] ?? [];

        foreach (self::REQUIRED_ORIGIN_FIELDS as $field) {
            if (empty($origin[$field])) {
                throw new ValidationException("DHL origin {$field} is required");
            }
        }

        return new self(
            company: $company,
            app: $app,
            region: $region,
            username: (string) $data['username'],
            password: (string) $data['password'],
            accountNumber: (string) $data['account_number'],
            origin: $origin,
            baseUrl: $data['base_url'] ?? ConfigurationEnum::DEFAULT_BASE_URL,
            signatureService: $data['signature_service'] ?? ConfigurationEnum::DEFAULT_SIGNATURE_SERVICE,
            labelTemplate: $data['label_template'] ?? ConfigurationEnum::DEFAULT_LABEL_TEMPLATE,
            requestPickup: (bool) ($data['request_pickup'] ?? true),
            pickupCloseTime: $data['pickup_close_time'] ?? ConfigurationEnum::DEFAULT_PICKUP_CLOSE_TIME,
            contentDescription: $data['content_description'] ?? null,
        );
    }
}
