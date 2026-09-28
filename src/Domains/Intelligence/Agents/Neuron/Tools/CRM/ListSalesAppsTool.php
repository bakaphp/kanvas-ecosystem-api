<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\Tool;

#[AgentTool(name: 'List Sales Apps', category: 'crm')]
class ListSalesAppsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_sales_apps',
            description: 'List the Sales Apps (Action Pages such as share vehicle, credit application or add trade) '
                . 'that are active for this company. Call it whenever the customer asks for something one of these '
                . 'apps can complete, then pass the matching slug to create_engagement_page. '
                . 'Only offer Sales Apps returned here.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('Sales App list');
        }

        $salesApps = CompanyAction::query()
            ->with('action')
            ->fromApp($this->app)
            // Not fromCompany(): under an AppKey request it widens to every company.
            ->where('companies_id', $this->company->getId())
            ->where('is_active', 1)
            ->notDeleted()
            ->whereHas('action', fn ($query) => $query->notDeleted())
            ->orderBy('weight')
            ->orderByDesc('id')
            ->get()
            ->unique('actions_id')
            ->map(fn (CompanyAction $companyAction): array => [
                'slug' => $companyAction->action->slug,
                'name' => $companyAction->name ?: $companyAction->action->name,
                'description' => $companyAction->description ?: $companyAction->action->description,
            ])
            ->values()
            ->all();

        return [
            'status' => 'success',
            'sales_apps' => $salesApps,
        ];
    }
}
