<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

/**
 * A vendor resource setup changed for one connection and a disconnect has to undo — kept with the
 * value we set, so the undo can tell our registration from a newer owner's.
 */
final readonly class OwnedResource
{
    public function __construct(
        public string $type,
        public string $key,
        public ?string $externalId,
        public ?string $expectedValue
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'external_id' => $this->externalId,
            'expected_value' => $this->expectedValue,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) $data['type'],
            key: (string) $data['key'],
            externalId: $data['external_id'] ?? null,
            expectedValue: $data['expected_value'] ?? null
        );
    }
}
