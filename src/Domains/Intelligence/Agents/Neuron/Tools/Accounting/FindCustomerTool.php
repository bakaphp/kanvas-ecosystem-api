<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Resolves a customer name to the Guild organizations it could be, with each one's Acumatica customer
 * code. The AR mirror of find_vendor — it turns "MARKETING SAMPLE" or "Acme Corp" on a question into
 * the ERP customer whose invoices/orders you want. Matching is suffix-normalized + substring, so it
 * returns candidates to disambiguate rather than a single guess.
 */
#[AgentTool(name: 'Find Customer', category: 'accounting')]
class FindCustomerTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_customer',
            description: 'Finds customer organizations matching a name, each with its Acumatica customer code (when '
                . 'synced). Use this to resolve who a customer is before looking up their invoices or sales orders. '
                . 'Returns candidates — confirm the right one with the user if there is more than one.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The customer name to look up.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max candidates to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $name, ?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(50, $limit ?? 10));

        $needle = OrganizationNameNormalizerService::normalize($name);
        $term = $needle !== '' ? $needle : $name;

        $matches = Organization::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('name', 'like', '%' . $term . '%')
            ->limit($limit)
            ->get();

        $result = [
            'query' => $name,
            'normalized' => $term,
            'count' => $matches->count(),
            'customers' => $matches->map(fn (Organization $org): array => [
                'organization_id' => $org->getId(),
                'name' => $org->name,
                'acumatica_customer_code' => (string) $org->get(CustomFieldEnum::CUSTOMER_ID->value, '') ?: null,
            ])->all(),
        ];

        if ($matches->isEmpty()) {
            // Without an explicit dead-end the model re-calls with the same name until the run budget
            // trips and the whole turn aborts (Sentry KANVAS-ECOSYSTEM-64Q).
            $result['message'] = 'No customer matched that name. Retrying the same name will not help — try a '
                . 'shorter distinctive fragment, or tell the user it is not in the synced data.';
        }

        return $result;
    }
}
