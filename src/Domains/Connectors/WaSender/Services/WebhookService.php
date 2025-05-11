<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;

class WebhookService
{
    protected string $webhookSecret;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        // Load the webhook secret from configuration
        $this->webhookSecret = $this->app->get('wasender_webhook_secret') ?? '';
    }

    /**
     * Receive and process webhook events from WaSender.
     * This method should be called from your webhook endpoint controller.
     *
     * @param array $payload The JSON payload from the webhook request
     * @param string $signature The X-Webhook-Signature header value
     * @return array Processed event data
     * @throws ValidationException If the webhook signature is invalid
     */
    public function receiveWebhook(array $payload, string $signature): array
    {
        // Verify the webhook signature
        if (! $this->verifySignature($signature)) {
            throw new ValidationException('Invalid webhook signature', 401);
        }

        // Extract event type
        $eventType = $payload['type'] ?? 'unknown';

        // Process the event based on its type
        // In a real implementation, you might have different handlers for different event types

        // Return a success response
        return [
            'success' => true,
            'event_type' => $eventType,
            'message' => 'Webhook received and processed successfully',
        ];
    }

    /**
     * Verify that a webhook request is genuine by checking the signature.
     *
     * @param string $signature The X-Webhook-Signature header value
     * @return bool True if the signature is valid, false otherwise
     */
    public function verifySignature(string $signature): bool
    {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }

        // The documentation suggests a simple comparison with the stored secret
        return $signature === $this->webhookSecret;
    }

    /**
     * Get the current webhook secret.
     *
     * @return string The current webhook secret
     */
    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    /**
     * Set the webhook secret.
     * This should match the secret you entered in the WaSender dashboard.
     *
     * @param string $secret The webhook secret
     */
    public function setWebhookSecret(string $secret): void
    {
        $this->webhookSecret = $secret;
        // In a real implementation, you would store this securely
    }

    /**
     * Generate a new webhook secret that you can enter in the WaSender dashboard.
     *
     * @param int $length Length of the secret
     * @return string The generated secret
     */
    public function generateWebhookSecret(int $length = 32): string
    {
        // For a random alphanumeric string
        return Str::random($length);
    }
}
