<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows\Concerns;

use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;

trait DeliversLeadReply
{
    protected function sender(Lead $lead): SendMessageToLeadAction
    {
        return new SendMessageToLeadAction($lead);
    }

    /**
     * A send the lead action refuses comes back as `success: false`, not as a throw. Null when the
     * reply went out.
     */
    protected function failUnsentReply(array $result, string $channelType, array $context): ?array
    {
        if (($result['success'] ?? true) !== false) {
            return null;
        }

        return $this->failWorkflow([
            'message' => 'Message not sent to lead via ' . $channelType . ': ' . ($result['error'] ?? 'unknown error'),
            'result' => $result,
            'context' => $context,
        ]);
    }
}
