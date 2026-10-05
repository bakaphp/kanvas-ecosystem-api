<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\ResolvesReviewWindow;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ParsesOrderTypesFilter;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Services\CardVelocityReviewService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Review Card Velocity Cases', category: 'commerce')]
class ReviewCardVelocityCasesTool extends Tool
{
    use GuardsAdminForTool;
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ParsesOrderTypesFilter;
    use ReportsToolOutcome;
    use ResolvesReviewWindow;

    protected string $name = 'review_card_velocity_cases';

    protected ?string $description = 'Read-only review of card-testing abuse, cut over a date range in the '
        . "tenant's own timezone (max " . self::MAX_RANGE_DAYS . ' days). Returns two lists: '
        . '"blocked" is every user the card velocity limit already rejected, grouped with their '
        . 'distinct cards, block count, whether they were banned and whether they are STILL banned '
        . 'right now, whether the company is corporate, and what they got paid in the same window. '
        . '"at_risk" is users nobody has blocked yet but who look like the same pattern: at least '
        . 'min_declines failed or abandoned card attempts with a low share of them actually paid. This '
        . 'tool never bans, unbans or changes any limit — it only reports for a human to decide.';

    private const int DEFAULT_MIN_DECLINES = 10;

    private const float DEFAULT_MAX_PAID_RATIO = 0.1;

    private const int DEFAULT_LIMIT = 20;

    private const int MAX_LIMIT = 50;

    private const int MAX_RANGE_DAYS = 31;

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'Lower-bound date, ISO YYYY-MM-DD, cut in the tenant timezone. Defaults to yesterday.',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'Upper-bound date, ISO YYYY-MM-DD, cut in the tenant timezone. Defaults to the same day as since. The range cannot exceed '
                    . self::MAX_RANGE_DAYS . ' days.',
                required: false,
            ),
            new ToolProperty(
                name: 'order_types',
                type: PropertyType::STRING,
                description: 'Optional comma-separated order type names to restrict to, e.g. "paso_rapido". Omit for all types.',
                required: false,
            ),
            new ToolProperty(
                name: 'min_declines',
                type: PropertyType::INTEGER,
                description: 'Minimum failed/abandoned attempts for a user to count as at-risk. Default ' . self::DEFAULT_MIN_DECLINES . '.',
                required: false,
            ),
            new ToolProperty(
                name: 'max_paid_ratio',
                type: PropertyType::NUMBER,
                description: 'Maximum share (0-1) of a user\'s attempts that may have paid for them to still count as at-risk. Default '
                    . self::DEFAULT_MAX_PAID_RATIO . '.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum rows per list, 1-' . self::MAX_LIMIT . '. Default ' . self::DEFAULT_LIMIT . '.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $since = null,
        ?string $until = null,
        ?string $order_types = null,
        ?int $min_declines = null,
        ?float $max_paid_ratio = null,
        ?int $limit = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $this->denied($denied['message']);
        }

        if (! $this->hasTenantContext()) {
            return $this->denied('This tool has no company context, so it cannot review card velocity cases.');
        }

        return $this->oncePerTurn(
            [
                'since' => $since,
                'until' => $until,
                'order_types' => $order_types,
                'min_declines' => $min_declines,
                'max_paid_ratio' => $max_paid_ratio,
                'limit' => $limit,
            ],
            fn (): array => $this->review(
                $since,
                $until,
                $order_types,
                $min_declines,
                $max_paid_ratio,
                $limit,
            ),
        );
    }

    private function review(
        ?string $since,
        ?string $until,
        ?string $orderTypes,
        ?int $minDeclines,
        ?float $maxPaidRatio,
        ?int $limit,
    ): array {
        $window = $this->resolveReviewWindow($since, $until, self::MAX_RANGE_DAYS);

        if (isset($window['outcome'])) {
            return $window;
        }

        $names = $this->parseOrderTypes($orderTypes);
        $orderTypeIds = null;

        if ($names !== null) {
            $orderTypeIds = OrderTypes::idsForNames($this->app, $this->company, $names);

            if ($orderTypeIds === []) {
                return $this->invalidArgs('Unknown order type(s): ' . implode(', ', $names) . '.');
            }
        }

        $limit = $this->resolveReviewLimit($limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $service = new CardVelocityReviewService($this->app, $this->company);
        $blocked = $service->blocked(
            $window['since'],
            $window['until'],
            $limit,
            $orderTypeIds,
        );
        $atRisk = $service->atRisk(
            $window['since'],
            $window['until'],
            $minDeclines ?? self::DEFAULT_MIN_DECLINES,
            $maxPaidRatio ?? self::DEFAULT_MAX_PAID_RATIO,
            $limit,
            $orderTypeIds,
        );

        $range = ['since' => $window['sinceLabel'], 'until' => $window['untilLabel']];

        if ($blocked === [] && $atRisk === []) {
            return $this->noop(
                ['success' => true, 'range' => $range, 'timezone' => $window['timezone'], 'blocked' => [], 'at_risk' => []],
                'Nothing new for this range — no blocked cases and no at-risk users.',
            );
        }

        return $this->ok([
            'range' => $range,
            'timezone' => $window['timezone'],
            'blocked' => $blocked,
            'at_risk' => $atRisk,
        ]);
    }
}
