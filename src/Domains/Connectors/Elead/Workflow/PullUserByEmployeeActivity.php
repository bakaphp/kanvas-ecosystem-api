<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Employee;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PullUserByEmployeeActivity extends KanvasActivity
{
    protected array $employeePositions = [
        'Administrator',
        'Appraiser',
        'BDC Agent',
        'BDC Manager',
        'Desking Salesperson',
        'Desk Manager',
        'Marketing',
        'F&I Manager',
        'GoldDigger Specialist',
        'General Manager',
        'General Sales Manager',
        'Dealer Owner',
        'Perfect Prospect Specialist',
        'Service Parts Tech',
        'Receptionist',
        'Salesperson',
        'Autopilot Specialist',
        'Service Parts Manager',
        'Sales Manager',
        'Service Advisor',
        'Service Manager',
        'Service Technician',
        'Internet Manager',
    ];

    public $tries = 3;

    public function execute(Users $user, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $params['company'];

        if (! isset($company) || ! $company instanceof Companies) {
            $this->failWorkflow([
                'error' => 'Company not found',
            ]);
        }

        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function ($user, $app, $integrationCompany, $additionalParams) use ($params) {
                $company = $params['company'];

                if (! isset($company) || ! $company instanceof Companies) {
                    $this->failWorkflow([
                        'error' => 'Company not found',
                    ]);
                }

                if (! $company->get(ConfigurationEnum::COMPANY->value)) {
                    $this->failWorkflow([
                        'error' => 'Company not found in Elead',
                    ]);
                }

                foreach ($this->employeePositions as $position) {
                    foreach (Employee::getAll($app, $company, $position) as $employee) {
                        $email = $employee->firstName . '.' . $employee->lastName . '@' . $params['email_domain'];
                        if ($email == $user->email) {
                            $user->set(
                                ConfigurationEnum::getUserKey($company, $user),
                                $employee->id
                            );

                            $match = true;

                            break;
                        }
                    }
                }

                if (! $match) {
                    $this->failWorkflow([
                        'error' => 'User not found in Elead',
                        'looking' => $user->email,
                        'ELeadEmployeeID' => $employee->id,
                    ]);
                }

                return [
                    'success' => $match,
                    'message' => 'User information pulled successfully',
                    'user' => $user,
                    'ELeadEmployeeID' => $employee->id,
                ];
            },
            company: $company,
        );
    }
}
