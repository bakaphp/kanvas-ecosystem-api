<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\DataTransferObject;

final class ZtransResponse
{
    public function __construct(
        public readonly string $responseCode,
        public readonly string $internalResponseCode,
        public readonly string $description,
        public readonly string $source,
        public readonly string $approvalCode,
        public readonly string $reference,
        public readonly array $body,
    ) {
    }

    public static function fromArray(array $body): self
    {
        return new self(
            responseCode: (string) ($body['response-code'] ?? ''),
            internalResponseCode: (string) ($body['internal-response-code'] ?? ''),
            description: (string) ($body['response-code-desc'] ?? ''),
            source: (string) ($body['response-code-source'] ?? ''),
            approvalCode: (string) ($body['approval-code'] ?? ''),
            reference: (string) ($body['pnRef'] ?? $body['txToken'] ?? ''),
            body: $body,
        );
    }

    public function isApproved(): bool
    {
        return $this->responseCode === '00';
    }

    public function isAuthenticationFailure(): bool
    {
        return $this->internalResponseCode === '4901';
    }
}
