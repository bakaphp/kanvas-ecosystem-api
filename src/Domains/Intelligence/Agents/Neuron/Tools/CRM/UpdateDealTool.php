<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Deals\Actions\RecordDealNoteAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesDealForTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Update an existing CRM deal: rename it, revise its description, reassign its owner, move it to a
 * different pipeline/stage (or advance it one stage), or set its status. Only the fields you pass are
 * changed — omit the rest. Save deals here the same way you save qualification onto a lead.
 */
#[AgentTool(name: 'Update Deal', category: 'crm')]
class UpdateDealTool extends Tool
{
    use ResolvesDealForTool;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
    ) {
        parent::__construct(
            name: 'update_deal',
            description: 'Update an existing deal. Use this after the opportunity progresses: change the title or '
                . 'description, reassign the owner, move it to a pipeline/stage, or set advance_stage=true to push '
                . 'it one stage forward. Set status to close it (2 = won/closed). Only pass the fields that changed.',
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
                name: 'deal_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the deal to update.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'New deal title, if it should change.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'New / revised description of the opportunity.',
                required: false,
            ),
            new ToolProperty(
                name: 'owner_id',
                type: PropertyType::INTEGER,
                description: 'User ID of the sales rep to reassign the deal to.',
                required: false,
            ),
            new ToolProperty(
                name: 'pipeline_id',
                type: PropertyType::INTEGER,
                description: 'Pipeline ID to move the deal into. Usually pass pipeline_stage_id alongside it.',
                required: false,
            ),
            new ToolProperty(
                name: 'pipeline_stage_id',
                type: PropertyType::INTEGER,
                description: 'Pipeline stage ID to move the deal to. Prefer advance_stage for a simple step forward.',
                required: false,
            ),
            new ToolProperty(
                name: 'advance_stage',
                type: PropertyType::BOOLEAN,
                description: 'Set true to move the deal to the next stage of its pipeline (only when it is genuinely progressing).',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::INTEGER,
                description: 'Deal status: 0 = open, 2 = closed/won. Set 2 to close the deal.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Any other context worth saving on the deal for the team.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $deal_id,
        ?string $title = null,
        ?string $description = null,
        ?int $owner_id = null,
        ?int $pipeline_id = null,
        ?int $pipeline_stage_id = null,
        ?bool $advance_stage = null,
        ?int $status = null,
        ?string $notes = null,
    ): array {
        $result = $this->resolveDealOrError($deal_id);
        if (is_array($result)) {
            return $result;
        }
        $deal = $result;

        $request = [];
        if ($title !== null && trim($title) !== '') {
            $request['title'] = trim($title);
        }
        if ($description !== null && trim($description) !== '') {
            $request['description'] = trim($description);
        }
        if ($owner_id !== null && $owner_id > 0) {
            $request['owner_id'] = $owner_id;
        }
        if ($pipeline_id !== null && $pipeline_id > 0) {
            $request['pipeline_id'] = $pipeline_id;
        }
        if ($pipeline_stage_id !== null && $pipeline_stage_id > 0) {
            $request['pipeline_stage_id'] = $pipeline_stage_id;
        }
        if ($status !== null) {
            $request['status'] = $status;
        }

        $stageAdvanced = false;
        if ($advance_stage === true && ! isset($request['pipeline_stage_id'])) {
            $nextStageId = $this->resolveNextStageId($deal);
            if ($nextStageId !== null) {
                $request['pipeline_stage_id'] = $nextStageId;
                $stageAdvanced = true;
            }
        }

        $hasNotes = $notes !== null && trim($notes) !== '';

        if ($request === [] && ! $hasNotes) {
            return [
                'status' => 'noop',
                'deal_id' => $deal_id,
                'message' => 'Nothing to update — no fields, stage change, or notes were provided.',
            ];
        }

        try {
            if ($request !== []) {
                $deal = new UpdateDealAction(
                    $deal,
                    DealData::fromMultiple($this->user, $this->app, $this->company, $request, $deal),
                )->execute();
            }

            if ($hasNotes) {
                $deal->set('deal_notes', trim($notes));
            }

            new RecordDealNoteAction($deal)->execute(
                $this->describeUpdate($request, $hasNotes),
                'deal-update',
                $this->user,
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to update deal {$deal_id}: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'deal_id' => $deal->getId(),
            'stage_advanced' => $stageAdvanced,
            'current_pipeline_stage' => $deal->pipelineStage?->name,
            'deal_status' => $deal->status,
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function describeUpdate(array $request, bool $hasNotes): string
    {
        $labels = [
            'title' => 'title',
            'description' => 'description',
            'owner_id' => 'owner',
            'pipeline_id' => 'pipeline',
            'pipeline_stage_id' => 'stage',
            'status' => 'status',
        ];

        $parts = array_map(fn (string $key): string => $labels[$key] ?? $key, array_keys($request));
        if ($hasNotes) {
            $parts[] = 'notes';
        }

        return 'Deal updated: ' . ($parts === [] ? 'no changes' : implode(', ', $parts)) . '.';
    }

    /**
     * Deal (unlike Lead) has no moveToNextPipelineStage helper, so resolve the next stage by weight
     * from the deal's own pipeline. Returns null when there is no pipeline or no further stage.
     */
    private function resolveNextStageId(Deal $deal): ?int
    {
        $pipeline = $deal->pipeline;
        if ($pipeline === null) {
            return null;
        }

        $stages = $pipeline->stages; // ordered by weight ASC
        $currentIndex = $stages->search(fn (PipelineStage $stage): bool => $stage->getId() === $deal->pipeline_stage_id);

        $next = $currentIndex === false
            ? $stages->first()
            : $stages->get((int) $currentIndex + 1);

        if ($next === null || $next->getId() === $deal->pipeline_stage_id) {
            return null;
        }

        return $next->getId();
    }
}
