<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'VinSolution Link User To Dealer Staff',
    description: 'Matches a Kanvas user to their VinSolutions dealer-staff record by email and stores the '
        . 'link, so later pushes can attribute work to the right salesperson. Runs on the USER and '
        . 'needs the company passed in.',
    integration: IntegrationsEnum::VIN_SOLUTION,
    params: [
        'company' => 'The Companies model whose VinSolutions dealer to look in. Required.',
    ],
)]
class PullUserInformationActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Users $user, Apps $app, array $params): array
    {
        $company = $params['company'];

        if (! isset($company) || ! $company instanceof Companies) {
            return [
                'error' => 'Company not found',
            ];
        }

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        $dealer = Dealer::getById($company->get(ConfigurationEnum::COMPANY->value), $app);
        $vinUsers = $dealer->getUsers($dealer, $app);
        $match = false;

        foreach ($vinUsers as $vinUser) {
            if ($vinUser->email == $user->email) {
                $user->set(
                    ConfigurationEnum::getUserKey($company, $user),
                    $vinUser->id
                );

                $match = true;

                break;
            }
        }

        if (! $match) {
            return [
                'error' => 'User not found in VinSolution',
                'looking' => $user->email,
                'vinUsers' => $vinUser,
            ];
        }

        return [
            'success' => $match,
            'message' => 'User information pulled successfully',
            'user' => $user,
            'vinUsers' => $vinUser,
        ];
    }
}
