<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts\Concerns;

use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * What an `InboundSource` answers when its vendor has nothing special to say, so a provider only
 * writes the methods where it differs: each message is its own turn, the vendor reports no delivery statuses and does not answer HELP itself.
 */
trait HasInboundDefaults
{
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        return null;
    }

    public function burstPolicy(InboundMessage $inbound, Companies $company): ?BurstPolicy
    {
        return null;
    }

    public function answersHelpItself(): bool
    {
        return false;
    }
}
