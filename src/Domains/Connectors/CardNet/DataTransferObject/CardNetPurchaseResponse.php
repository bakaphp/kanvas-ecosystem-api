<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\DataTransferObject;

use Kanvas\Connectors\CardNet\Enums\TransactionStatusEnum;

class CardNetPurchaseResponse
{
    public function __construct(
        protected readonly array $raw,
    ) {
    }

    public static function fromResponse(array $response): self
    {
        return new self($response);
    }

    public function isApproved(): bool
    {
        return $this->getTransactionStatusId() === TransactionStatusEnum::APPROVED->value
            || $this->getResponseCode() === '00';
    }

    public function isPreauthorized(): bool
    {
        return $this->getTransactionStatusId() === TransactionStatusEnum::PREAUTHORIZED->value;
    }

    public function isRejected(): bool
    {
        return $this->getTransactionStatusId() === TransactionStatusEnum::REJECTED->value;
    }

    public function getPurchaseId(): int
    {
        return (int) ($this->raw['Response']['PurchaseId'] ?? 0);
    }

    public function getApprovalCode(): string
    {
        return (string) ($this->getTransaction()['ApprovalCode'] ?? '');
    }

    public function getResponseCode(): string
    {
        $steps = $this->getTransaction()['Steps'] ?? [];

        if (empty($steps)) {
            return '';
        }

        $lastStep = end($steps);

        return (string) ($lastStep['ResponseCode'] ?? '');
    }

    public function getResponseMessage(): string
    {
        $steps = $this->getTransaction()['Steps'] ?? [];

        if (empty($steps)) {
            return (string) ($this->getTransaction()['Description'] ?? '');
        }

        $lastStep = end($steps);

        return (string) ($lastStep['ResponseMessage'] ?? '');
    }

    public function getTransactionId(): int
    {
        return (int) ($this->getTransaction()['TransactionID'] ?? 0);
    }

    public function getStatus(): string
    {
        return (string) ($this->getTransaction()['Status'] ?? '');
    }

    public function getErrors(): array
    {
        return $this->raw['Errors'] ?? [];
    }

    protected function getTransaction(): array
    {
        return $this->raw['Response']['Transaction'] ?? [];
    }

    protected function getTransactionStatusId(): int
    {
        return (int) ($this->getTransaction()['TransactionStatusId'] ?? 0);
    }
}
