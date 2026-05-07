<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\ReceiverWebhook;

interface OAuthProviderInterface
{
    /**
     * Build the authorization URL for the provider.
     */
    public function getAuthorizationUrl(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request,
        string $nonce
    ): string;

    /**
     * Handle the callback: exchange code for tokens, store credentials,
     * and return an array of result data.
     */
    public function handleCallback(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request
    ): array;

    /**
     * Get the Redis state key prefix for this provider.
     */
    public function getStateKeyPrefix(): string;
}
