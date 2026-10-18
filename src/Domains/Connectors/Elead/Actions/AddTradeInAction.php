<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\DataTransferObject\TradeIn;
use Kanvas\Guild\Leads\Models\Lead;

class AddTradeInAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message): ?TradeIn
    {
        $tradeIn = TradeIn::fromMessageData($message['data'] ?? []);

        if (! $tradeIn->hasEnoughDetails()) {
            return null;
        }

        new SyncLeadAction($this->lead)->execute()->addTradeIn($tradeIn);

        return $tradeIn;
    }
}
