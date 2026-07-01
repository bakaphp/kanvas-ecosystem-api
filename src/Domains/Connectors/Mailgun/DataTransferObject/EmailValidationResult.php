<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\DataTransferObject;

use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;

class EmailValidationResult
{
    public function __construct(
        public readonly string $address,
        public readonly string $result,
        public readonly string $risk,
        public readonly bool $isDisposable,
        public readonly bool $isRoleAddress,
        /** @var string[] */
        public readonly array $reasons,
    ) {
    }

    public static function fromApiResponse(array $data): self
    {
        return new self(
            address: (string) ($data['address'] ?? ''),
            result: (string) ($data['result'] ?? 'unknown'),
            risk: (string) ($data['risk'] ?? 'unknown'),
            isDisposable: (bool) ($data['is_disposable_address'] ?? false),
            isRoleAddress: (bool) ($data['is_role_address'] ?? false),
            reasons: array_values(array_map('strval', (array) ($data['reason'] ?? []))),
        );
    }

    /** Null when inconclusive (catch_all / unknown) — we never flag an address we couldn't confirm. */
    public function toValidationStatus(): ?ContactValidationStatusEnum
    {
        return match ($this->result) {
            'deliverable' => ContactValidationStatusEnum::VALID,
            'undeliverable' => ContactValidationStatusEnum::HARD_BOUNCE,
            'do_not_send' => ContactValidationStatusEnum::INVALID,
            default => null,
        };
    }
}
