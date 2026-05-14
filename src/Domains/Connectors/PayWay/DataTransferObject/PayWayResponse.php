<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\DataTransferObject;

final class PayWayResponse
{
    public function __construct(
        public readonly string $returnCode,
        public readonly string $message,
        public readonly bool $rawSuccess,
        public readonly array $payload,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            returnCode: (string) ($data['returnCode'] ?? '98'),
            message: (string) ($data['message'] ?? ''),
            rawSuccess: (bool) ($data['success'] ?? false),
            payload: $data,
        );
    }

    public static function networkFailure(string $errorMessage): self
    {
        return new self('98', $errorMessage, false, []);
    }

    public function isSuccess(): bool
    {
        return $this->returnCode === '00';
    }

    public function is3dsStepRequired(): bool
    {
        return $this->returnCode === '01';
    }

    public function isDeclined(): bool
    {
        return $this->returnCode === '02';
    }

    public function isError(): bool
    {
        return ! in_array($this->returnCode, ['00', '01', '02'], true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function getNested(string $parentKey, string $childKey, mixed $default = null): mixed
    {
        return $this->payload[$parentKey][$childKey] ?? $default;
    }
}
