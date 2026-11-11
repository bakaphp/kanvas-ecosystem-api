<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * The company as DGII knows it. It is both the `POST /company` body and the `sender` block on every document,
 * stored on the company (ConfigurationEnum::SENDER_PROFILE) from what Alanube answers.
 */
class AlanubeCompanyProfile extends Data
{
    public function __construct(
        public readonly string $rnc,
        public readonly string $companyName,
        public readonly string $address,
        public readonly ?string $tradeName = null,
        public readonly ?string $province = null,
        public readonly ?string $municipality = null,
        public readonly ?string $mail = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $company Alanube's company record
     */
    public static function fromAlanube(array $company): self
    {
        return new self(
            rnc: (string) ($company['identification'] ?? ''),
            companyName: (string) ($company['name'] ?? ''),
            address: (string) ($company['address'] ?? ''),
            tradeName: isset($company['tradeName']) ? (string) $company['tradeName'] : null,
            province: isset($company['province']) ? (string) $company['province'] : null,
            municipality: isset($company['municipality']) ? (string) $company['municipality'] : null,
            mail: isset($company['email']) ? (string) $company['email'] : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toCompanyBody(): array
    {
        return array_filter([
            'name' => $this->companyName,
            'tradeName' => $this->tradeName,
            'identification' => $this->rnc,
            'address' => $this->address,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'email' => $this->mail,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
