<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Handlers;

use Exception;
use Kanvas\Connectors\Calendly\Client;
use Kanvas\Connectors\Calendly\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class CalendlyHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiToken = $this->data['api_token'] ?? null;
        $callbackUrl = $this->data['callback_url'] ?? null;

        if (empty($apiToken)) {
            throw new ValidationException('Calendly API token is required.');
        }

        if (empty($callbackUrl)) {
            throw new ValidationException('Callback URL is required for webhook registration.');
        }

        $this->company->set(ConfigurationEnum::CALENDLY_API_TOKEN->value, $apiToken);

        try {
            $client = new Client($this->company);
            $currentUser = $client->getCurrentUser();

            $userUri = $currentUser['resource']['uri'] ?? '';
            $organizationUri = $currentUser['resource']['current_organization'] ?? '';

            if (empty($userUri) || empty($organizationUri)) {
                throw new ValidationException('Could not retrieve Calendly user or organization info.');
            }

            $this->company->set(ConfigurationEnum::CALENDLY_USER_URI->value, $userUri);
            $this->company->set(ConfigurationEnum::CALENDLY_ORGANIZATION_URI->value, $organizationUri);

            $events = $this->data['events'] ?? ['invitee.created', 'invitee.canceled'];

            $webhook = $client->createWebhookSubscription(
                $callbackUrl,
                $events,
                $organizationUri,
                $userUri
            );

            $webhookUri = $webhook['resource']['uri'] ?? '';
            if (! empty($webhookUri)) {
                $this->company->set(ConfigurationEnum::CALENDLY_WEBHOOK_SUBSCRIPTION_URI->value, $webhookUri);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'Unauthorized') || str_contains($e->getMessage(), '401')) {
                throw new ValidationException('Invalid Calendly API token.');
            }

            throw new ValidationException('Calendly setup failed: ' . $e->getMessage());
        }

        return true;
    }
}
