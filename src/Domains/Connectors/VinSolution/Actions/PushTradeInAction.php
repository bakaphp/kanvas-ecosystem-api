<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\VinSolution\DataTransferObject\TradeIn;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Connectors\VinSolution\Services\LeadUserService;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Social\Messages\Models\Message;

class PushTradeInAction
{
    public function __construct(
        protected ModelsLead $lead,
        protected Message $message,
    ) {
    }

    public function execute(): array
    {
        $vinCompany = Dealer::getById($this->lead->company->get(ConfigurationEnum::COMPANY->value), $this->lead->app);

        $vinUser = LeadUserService::resolve($this->lead);
        $vinUserId = $vinUser?->get(ConfigurationEnum::getUserKey($this->lead->company, $vinUser));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser($vinCompany, $vinUserId, $this->lead->app);
        $vinLead = Lead::getById(
            $vinCompany,
            $user,
            $this->lead->get(CustomFieldEnum::LEADS->value)
        );

        return $vinLead->addTradeIn(
            $vinCompany,
            $user,
            TradeIn::from($this->message, $this->lead)->toVinSolutionArray()
        );
    }
}
