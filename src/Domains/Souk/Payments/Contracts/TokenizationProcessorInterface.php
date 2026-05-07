<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;

interface TokenizationProcessorInterface
{
    /**
     * Store card details with the processor and return a reusable token.
     * The token should be persisted on the PaymentMethods.metadata for future charges.
     *
     * @param array $cardDetails  Raw card data: number, expiration, cvc, billing info
     */
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult;

    /**
     * Delete a previously stored token from the processor's vault.
     */
    public function deleteToken(string $token): bool;
}
