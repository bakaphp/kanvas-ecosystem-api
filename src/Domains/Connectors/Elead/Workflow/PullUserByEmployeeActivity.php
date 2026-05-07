<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Employee;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

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
            return [
                 'error' => 'Company not found',
             ];
        }

        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function ($user, $app, $integrationCompany, $additionalParams) use ($params) {
                $company = $params['company'];

                if (! isset($company) || ! $company instanceof Companies) {
                    return $this->failWorkflow([
                        'error' => 'Company not found',
                    ]);
                }

                if (! $company->get(CustomFieldEnum::COMPANY->value)) {
                    return $this->failWorkflow([
                        'error' => 'Company not found in Elead',
                    ]);
                }

                $error = null;
                $matchedEmployee = null;
                $nameMatchEmployee = null;

                foreach ($this->employeePositions as $position) {
                    try {
                        $employees = Employee::getAll($app, $company, $position);

                        foreach ($employees as $employee) {
                            // First try email match
                            try {
                                $email = $employee->getEmails()[0]['address'] ?? null;
                            } catch (Throwable $e) {
                                $email = null;
                                $error = $e->getMessage();
                            }

                            if ($email !== null && $email == $user->email) {
                                $matchedEmployee = $employee;
                                $error = null;

                                break 2;
                            }

                            // Exact first+last name match as fallback
                            if (
                                $employee->firstName !== null
                                && $employee->lastName !== null
                                && mb_strtolower(trim($employee->firstName)) === mb_strtolower(trim($user->firstname))
                                && mb_strtolower(trim($employee->lastName)) === mb_strtolower(trim($user->lastname))
                            ) {
                                $nameMatchEmployee = $employee;

                                break 2;
                            }
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                }

                // Fall back to name match if no email match found
                if ($matchedEmployee === null && $nameMatchEmployee !== null) {
                    $matchedEmployee = $nameMatchEmployee;
                    $error = null;
                }

                if ($matchedEmployee !== null) {
                    $user->set(
                        ConfigurationEnum::getUserKey($company, $user),
                        $matchedEmployee->id
                    );

                    return [
                        'success' => true,
                        'message' => 'User information pulled successfully',
                        'user' => $user,
                        'ELeadEmployeeID' => $matchedEmployee->id,
                    ];
                }

                return $this->failWorkflow([
                    'error' => 'User not found in Elead',
                    'looking' => $user->email,
                    'exception' => $error,
                ]);
            },
            company: $company,
        );
    }
}
