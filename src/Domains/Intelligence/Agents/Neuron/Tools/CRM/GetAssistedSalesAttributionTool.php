<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kanvas\Analytics\Actions\BuildAssistedSalesAttributionAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AttributionPopulationEnum;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * "Of the deals we sold last month, how many did the AI touch, and how many had an Action?" The
 * manager-side attribution report; sibling of get_sales_summary (which counts sales but never
 * says who helped). Company-wide, so it belongs to internal agents only.
 */
#[AgentTool(name: 'Get Assisted Sales Attribution', category: 'crm')]
class GetAssistedSalesAttributionTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    /** @var Collection<int, LeadType>|null */
    private ?Collection $leadTypeCatalog = null;

    protected string $name = 'get_assisted_sales_attribution';

    protected ?string $description = 'Which leads the AI agent assisted (it messaged the customer) and which went through an '
        . 'Engage action (any action shared with the customer; submitted ones counted separately), with '
        . 'either/both/neither counts and the per-lead rows. population=sold (default) takes the leads '
        . 'marked Sold in the timeframe by close date: "how many sold deals did the AI assist last month?", '
        . '"how many sales had an action shared?". population=all or open takes the leads created in the '
        . 'timeframe and adds an outcomes block comparing the sold rate of assisted vs unassisted leads: '
        . '"what is the impact of the AI / of actions?", "which open leads has the AI talked to?". By '
        . 'default every lead type counts, service appointments a DMS sync filed as leads included; when a '
        . 'manager says the count is impossible or asks about sales leads, call again with '
        . 'breakdown_by_type=true to see the mix, then with lead_types limited to the sales types '
        . '(Internet, Phone, WALK_IN). Close dates are only recorded from 2026-10-23 on, so '
        . 'population=sold over an earlier range returns nothing, not zero assistance. Reporting only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'timeframe',
                type: PropertyType::STRING,
                description: 'today, yesterday, last_7_days, or last_30_days (default). Ignored when from/to are given.',
                required: false,
            ),
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'Custom range start (YYYY-MM-DD). Requires "to".',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Custom range end (YYYY-MM-DD). Requires "from".',
                required: false,
            ),
            new ToolProperty(
                name: 'population',
                type: PropertyType::STRING,
                description: 'sold (default): leads closed as Sold in the timeframe. all: every lead created in the '
                    . 'timeframe, any status. open: leads created in the timeframe that are still open. all and '
                    . 'open add the outcomes block (sold rate of assisted vs unassisted).',
                required: false,
            ),
            new ToolProperty(
                name: 'lead_types',
                type: PropertyType::STRING,
                description: 'Comma-separated lead type names to include, as they appear in by_type (e.g. '
                    . '"Internet, Phone, WALK_IN"). Case-insensitive. Omit for every type, SERVICE included.',
                required: false,
            ),
            new ToolProperty(
                name: 'breakdown_by_type',
                type: PropertyType::BOOLEAN,
                description: 'true adds by_type (leads, sold, AI-assisted and action-assisted per lead type) and '
                    . 'available_lead_types, the names this company can filter on. Off by default. Use it to '
                    . 'answer "what lead types do we have?".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $timeframe = null,
        ?string $from = null,
        ?string $to = null,
        ?string $population = null,
        ?string $lead_types = null,
        ?bool $breakdown_by_type = null
    ): array {
        $populationEnum = AttributionPopulationEnum::tryFrom(Str::lowerTrim($population) ?: 'sold');
        if ($populationEnum === null) {
            return [
                'status' => 'error',
                'message' => 'Invalid population. Use one of: ' . implode(', ', AttributionPopulationEnum::values()) . '.',
            ];
        }

        try {
            $leadTypes = $this->resolveLeadTypes($lead_types);
        } catch (InvalidArgumentException $e) {
            return ['status' => 'error', 'error' => 'unknown_lead_type', 'message' => $e->getMessage()];
        }

        $args = $this->analyticsRangeArgs($timeframe ?? 'last_30_days', $from, $to);

        $result = new BuildAssistedSalesAttributionAction(
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            population: $populationEnum,
            leadTypeIds: $leadTypes->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            withTypeBreakdown: $breakdown_by_type === true,
        )->execute();

        return [
            'status' => 'success',
            'timeframe' => ['from' => $args['from'], 'to' => $args['to']],
            ...($leadTypes->isEmpty() ? [] : ['lead_types' => $leadTypes->pluck('name')->unique()->values()->all()]),
            ...($breakdown_by_type === true ? ['available_lead_types' => $this->leadTypeNames()] : []),
            ...$result,
            'note' => $result['note'] ?? 'A lead counts as AI-assisted when any AI agent of the company sent the '
                . 'customer a message between the lead\'s creation and its close (or now, while open); as '
                . 'action-assisted when a catalog action was shared with the customer in that same window, and '
                . 'submitted_action counts the ones the customer completed. With population=all or open, '
                . 'outcomes compares the sold rate of assisted and unassisted leads: that difference is the impact. '
                . 'Close dates exist only from 2026-10-23, so earlier sales are not in population=sold at all.',
        ];
    }

    /**
     * Names are matched against the company's own types plus the platform globals; an unknown name
     * throws with the list that exists, so the model corrects itself instead of guessing.
     *
     * @return Collection<int, LeadType> empty when no filter was asked for
     *
     * @throws InvalidArgumentException
     */
    private function resolveLeadTypes(?string $leadTypes): Collection
    {
        $wanted = array_map(Str::lowerTrim(...), Str::commaList($leadTypes));

        if ($wanted === []) {
            return new Collection();
        }

        $available = $this->leadTypeCatalog();
        $matched = $available->filter(fn (LeadType $type): bool => in_array(Str::lowerTrim($type->name), $wanted, true));
        $unknown = array_diff($wanted, $matched->map(fn (LeadType $type): string => Str::lowerTrim($type->name))->all());

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown lead type(s): %s. This company has: %s.',
                implode(', ', $unknown),
                implode(', ', $this->leadTypeNames()),
            ));
        }

        return $matched->values();
    }

    /**
     * The company's own types plus the platform globals. Memoized: a filtered call with the
     * breakdown on reads it twice in one turn.
     *
     * @return Collection<int, LeadType>
     */
    private function leadTypeCatalog(): Collection
    {
        return $this->leadTypeCatalog ??= LeadType::query()
            ->whereIn('apps_id', [0, $this->app->getId()])
            ->whereIn('companies_id', [0, $this->company->getId()])
            ->where('is_deleted', 0)
            ->get(['id', 'name']);
    }

    /**
     * @return array<int, string>
     */
    private function leadTypeNames(): array
    {
        return $this->leadTypeCatalog()->pluck('name')->unique()->sort()->values()->all();
    }
}
