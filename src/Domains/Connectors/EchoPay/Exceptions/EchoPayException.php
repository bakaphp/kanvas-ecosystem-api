<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Exceptions;

use Kanvas\Exceptions\ValidationException;
use Throwable;

class EchoPayException extends ValidationException
{
    protected array $errorBody;

    public function __construct(string|array $message = "", int $code = 0, ?Throwable $previous = null, array $errorBody = [])
    {
        $message = is_array($message) ? implode(', ', $message) : $message;
        parent::__construct($message, $code, $previous);
        $this->errorBody = $errorBody;
    }

    public function getErrorBody(): array
    {
        return $this->errorBody;
    }

    public function getUserMessage(): string
    {
        $data = $this->errorBody['data'] ?? $this->errorBody;
        $reason = $data['errorInformation']['reason'] ?? null;
        $message = $data['errorInformation']['message'] ?? null;

        if ($reason) {
            $translationKey = 'payment_errors.' . $reason;
            $translated = __($translationKey, [], 'es');

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return $message ?? $reason ?? $this->getMessage();
    }
}
