<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasFileUploadToolProperties;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Put the brief on the plan, where every task inherits it via list_task_files.
 *
 * A URL pasted into the plan description hands the worker nothing it can open — prose is a hint,
 * not a handoff.
 */
#[AgentTool(name: 'Attach File To Plan', category: 'nervous_system')]
class AttachFileToNervousSystemPlanTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use HasFileUploadToolProperties;
    use HasKanvasContext;
    use ResolvesPlanForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_file_to_plan',
            description: 'Attach a document to a plan, so every task on it inherits the file as an input. Use '
                . 'this whenever work depends on a document — attach it once here instead of describing it or '
                . 'pasting a URL into the plan description, which hands the worker nothing it can open. Pass '
                . '`content` with text you wrote plus a `file_name`, or `file_url` for a file that already '
                . 'exists at a public URL. Also the right place for a plan-level final report.',
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
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan to attach the file to.',
                required: true,
            ),
            ...$this->fileUploadProperties('employee-directory.csv'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $plan_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        $plan = $this->resolvePlanOrError($plan_id);

        if (is_array($plan)) {
            return $plan;
        }

        return [
            'plan_id' => $plan->getId(),
            ...$this->attachFileToEntity(
                entity: $plan,
                entityLabel: 'plan',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
