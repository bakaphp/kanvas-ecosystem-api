<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Neuron\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\PasoRapido\Services\TagVerificationReviewService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\ResolvesReviewWindow;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Review Tag Verification Blocks', category: 'commerce')]
class ReviewTagVerificationBlocksTool extends Tool
{
    use GuardsAdminForTool;
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesReviewWindow;

    protected string $name = 'review_tag_verification_blocks';

    protected ?string $description = 'Read-only review of PasoRapido tag-verification abuse, cut over a date range in the '
        . "tenant's own timezone (max " . self::MAX_RANGE_DAYS . ' days). Returns "by_user": every user '
        . 'with at least one blocked verification attempt, with a count per block reason, the number of '
        . 'distinct tags and IPs they tried, whether they are STILL banned right now, whether the company '
        . 'is corporate, and when the first and last block happened. Distinct tags is a LOWER BOUND — '
        . 'repeat hits of the same reason within an hour are deduplicated to one log row, so a user '
        . 'probing many tags may show fewer than they actually tried. "by_ip" is the same rows grouped by '
        . 'IP instead, including blocks with no known user, with the number of distinct users seen from '
        . 'that IP. "success_count" is how many verifications succeeded in the same range, for context. '
        . 'This tool never bans, unbans or changes any limit — it only reports for a human to decide.';

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
                description: 'Upper-bound date, ISO YYYY-MM-DD, cut in the tenant timezone. Defaults to the same '
                    . 'day as since. The range cannot exceed ' . self::MAX_RANGE_DAYS . ' days.',
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
        ?int $limit = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $this->denied($denied['message']);
        }

        if (! $this->hasTenantContext()) {
            return $this->denied('This tool has no company context, so it cannot review tag verification blocks.');
        }

        if (! $this->isAppCompany()) {
            return $this->denied(
                'Tag verification blocks carry no company, so this tool only reports for the '
                    . "app's own default company."
            );
        }

        return $this->oncePerTurn(
            ['since' => $since, 'until' => $until, 'limit' => $limit],
            fn (): array => $this->review($since, $until, $limit),
        );
    }

    private function isAppCompany(): bool
    {
        try {
            return $this->company->getId() === $this->app->getAppCompany()->getId();
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    private function review(?string $since, ?string $until, ?int $limit): array
    {
        $window = $this->resolveReviewWindow($since, $until, self::MAX_RANGE_DAYS);

        if (isset($window['outcome'])) {
            return $window;
        }

        $limit = $this->resolveReviewLimit($limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $service = new TagVerificationReviewService($this->app);
        $byUser = $service->byUser($window['since'], $window['until'], $limit);
        $byIp = $service->byIp($window['since'], $window['until'], $limit);
        $successCount = $service->successCount($window['since'], $window['until']);

        $range = ['since' => $window['sinceLabel'], 'until' => $window['untilLabel']];

        if ($byUser === [] && $byIp === []) {
            return $this->noop(
                [
                    'success' => true,
                    'range' => $range,
                    'timezone' => $window['timezone'],
                    'by_user' => [],
                    'by_ip' => [],
                    'success_count' => $successCount,
                ],
                'Nothing new for this range — no blocked tag verifications.',
            );
        }

        return $this->ok([
            'range' => $range,
            'timezone' => $window['timezone'],
            'by_user' => $byUser,
            'by_ip' => $byIp,
            'success_count' => $successCount,
        ]);
    }
}
