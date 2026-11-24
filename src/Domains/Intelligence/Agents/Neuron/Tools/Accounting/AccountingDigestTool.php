<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Digest\Services\AccountingDigestBuilderService;
use Kanvas\Scribe\Digest\Services\AccountingDigestSummaryService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The same digest the morning email carries, as text the agent can post in chat.
 */
#[AgentTool(name: 'Accounting Digest', category: 'accounting')]
class AccountingDigestTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'accounting_digest';

    protected ?string $description = 'The AR/AP digest for one day: invoices issued, e-CFs accepted / rejected, invoices sent, '
        . 'opened and paid, collections due and overdue, bills received / approved / paid, payments recorded and not yet '
        . 'seen in the bank, bank statement coverage, number ranges running out, approvals waiting with per-approver '
        . 'times, and whether agent invoice approval (issue_ecf) is switched off. Returns a summary to post in chat. Read-only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'date',
                type: PropertyType::STRING,
                description: 'The day to report, YYYY-MM-DD. Omit for today so far.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $date = null): array
    {
        $date = Str::trimToNull($date);

        $parsed = $date !== null ? DateHelper::parseYmd($date) : null;

        if ($date !== null && $parsed === null) {
            return $this->invalidArgs("date must look like 2026-06-30 (got \"{$date}\").");
        }

        $day = $parsed !== null ? Carbon::instance($parsed) : Carbon::today();

        $digest = new AccountingDigestBuilderService($this->app, $this->company, $day)->build();

        return $this->ok([
            'date' => $digest['date'],
            'has_activity' => $digest['has_activity'],
            'summary' => new AccountingDigestSummaryService()->text($digest),
            'digest' => $digest,
        ]);
    }
}
