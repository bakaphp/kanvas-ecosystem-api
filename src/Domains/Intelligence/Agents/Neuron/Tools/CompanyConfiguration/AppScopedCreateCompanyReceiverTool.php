<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyReceiverTool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * create_company_receiver for the Company Configuration Administrator: the stable receiver
 * creation, in an explicitly authorized company.
 */
class AppScopedCreateCompanyReceiverTool extends CreateCompanyReceiverTool
{
    use RunsInExplicitCompany;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [...parent::properties(), $this->companyUuidProperty()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(
        string $receiver,
        string $name,
        ?string $description = null,
        ?string $configuration = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool(
                $receiver,
                $name,
                $description,
                $configuration,
            ));
        }

        return parent::__invoke(
            $receiver,
            $name,
            $description,
            $configuration,
        );
    }
}
