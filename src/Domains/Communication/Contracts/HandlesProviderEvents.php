<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * For a provider whose webhook carries more than one-to-one messages and their delivery statuses:
 * records only it understands (a contact changed, a conversation closed, a call ended, a group chat
 * it runs itself). The shared pipeline never sees those; the provider files them its own way.
 */
interface HandlesProviderEvents
{
    /**
     * @return array<string, mixed>|null what it did, or null when the payload is not one of its own
     */
    public function handleProviderEvent(array $payload, ReceiverWebhook $receiver): ?array;
}
