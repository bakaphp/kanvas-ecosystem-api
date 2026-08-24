<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Traits;

use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Config-key enums read the same way: the receiver's configuration array, falling back to the
 * enum's own default. Implement `default()` and get the accessors.
 */
trait ReadsReceiverConfigurationTrait
{
    abstract public function default(): mixed;

    public function get(ReceiverWebhook $receiver): mixed
    {
        return $receiver->configuration[$this->value] ?? $this->default();
    }

    public function getInt(ReceiverWebhook $receiver): int
    {
        return (int) $this->get($receiver);
    }

    /**
     * @return array<int, string>
     */
    public function getList(ReceiverWebhook $receiver): array
    {
        return array_values(array_filter((array) $this->get($receiver), 'is_string'));
    }
}
