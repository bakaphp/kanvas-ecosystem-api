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
 * Resolves a vendor name (e.g. off an incoming invoice) to the Guild organizations it could be, with
 * each one's Acumatica vendor code. This is how the AP agent turns "Globex Supply Co" on a PDF into
 * the ERP vendor it should code the bill to. Matching is suffix-normalized + substring, so it returns
 * candidates to disambiguate rather than a single guess.
 */
#[AgentTool(name: 'Find Vendor', category: 'accounting')]
class FindVendorTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_vendor',
            description: 'Finds vendor organizations matching a name, each with its Acumatica vendor code (when '
                . 'synced). Use this to resolve the vendor named on an invoice before matching a PO or coding a '
                . 'bill. Returns candidates — confirm the right one with the user if there is more than one.',
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
                description: 'The vendor name to look up (as it appears on the invoice).',
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
            'vendors' => $matches->map(fn (Organization $org): array => [
                'organization_id' => $org->getId(),
                'name' => $org->name,
                'acumatica_vendor_code' => (string) $org->get(CustomFieldEnum::VENDOR_ID->value, '') ?: null,
            ])->all(),
        ];

        if ($matches->isEmpty()) {
            // Without an explicit dead-end the model re-calls with the same name until the run budget
            // trips and the whole turn aborts (Sentry KANVAS-ECOSYSTEM-64Q).
            $result['message'] = 'No vendor matched that name. Retrying the same name will not help — try a '
                . 'shorter distinctive fragment, or tell the user it is not in the synced data.';
        }

        return $result;
    }
}
