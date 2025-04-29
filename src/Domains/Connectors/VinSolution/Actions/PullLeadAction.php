<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(?ModelsLead $lead = null, ?int $leadId = null): array
    {
        $vinCompany = Dealer::getById($this->company->get(ConfigurationEnum::COMPANY->value), $this->app);

        $vinUserId = $this->user->get(ConfigurationEnum::getUserKey($this->company, $this->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->app,
        );
        $vinLead = Lead::getAll(
            $vinCompany,
            $user,
            [
                'leadId' => $leadId === null ? $lead->get(CustomFieldEnum::LEADS->value) : $leadId,
            ]
        );

        if (! empty($vinLead['Leads'])) {
            $vinLead = DataTransferObjectLead::fromVinLeadArray(
                $vinLead['Leads'][0],
                $vinCompany,
                $user,
                $this->app,
                $this->company,
                $this->user
            );

            $lead = new SyncLeadByThirdPartyCustomFieldAction($vinLead)->execute();

            return [
                $lead,
            ];
        }

        return [];
    }
}
