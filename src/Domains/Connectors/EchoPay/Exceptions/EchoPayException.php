<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Exceptions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\DataTransferObject\PaymentFailure;
use Throwable;

class EchoPayException extends ValidationException
{
    protected array $errorBody;

    // The HTTP status of the failed call. Kept explicitly because the LightHouseCustomException
    // parent drops the constructor's $code (never passes it to Exception), so getCode() is always 0.
    protected int $statusCode;

    public function __construct(
        string|array $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $errorBody = []
    ) {
        $message = is_array($message) ? implode(', ', $message) : $message;
        parent::__construct($message, $code, $previous);
        $this->errorBody = $errorBody;
        $this->statusCode = $code;
    }

    public function getErrorBody(): array
    {
        return $this->errorBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function isGatewayFailure(): bool
    {
        return $this->statusCode === 0 || $this->statusCode >= 500;
    }

    public function getReason(): ?string
    {
        return $this->getBodySection('errorInformation')['reason'] ?? null;
    }

    public function getGatewayMessage(): ?string
    {
        return $this->getBodySection('errorInformation')['message'] ?? null;
    }

    public function getProcessorResponseCode(): ?string
    {
        return $this->getBodySection('processorInformation')['responseCode'] ?? null;
    }

    public function getResponseInsight(): ?string
    {
        return $this->getBodySection('paymentInsightsInformation')['responseInsights']['category'] ?? null;
    }

    public function toPaymentFailure(): PaymentFailure
    {
        return new PaymentFailure(
            code: $this->getReason() ?? class_basename($this),
            message: $this->getGatewayMessage() ?? $this->getMessage(),
            processorResponseCode: $this->getProcessorResponseCode(),
            responseInsight: $this->getResponseInsight(),
        );
    }

    public function getUserMessage(): string
    {
        $reason = $this->getReason();
        $message = $this->getGatewayMessage();

        if ($reason) {
            $translationKey = 'payment_errors.' . $reason;
            $translated = __($translationKey, [], 'es');

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return $message ?? $reason ?? $this->getMessage();
    }

    private function getBodySection(string $key): array
    {
        $data = $this->errorBody['data'] ?? $this->errorBody;

        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }
}
