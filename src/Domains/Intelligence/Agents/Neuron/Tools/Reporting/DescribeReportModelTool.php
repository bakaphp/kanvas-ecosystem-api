<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Reporting;

use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * The agent's schema. Without this it would be guessing column names.
 *
 * Listing with no model is how it discovers what this tenant even has — which is why the same
 * three tools serve every app without a per-app tool being written.
 */
#[AgentTool(name: 'Describe Report Model', category: 'reporting')]
class DescribeReportModelTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'describe_report_model',
            description: 'List the reporting models available for this company, or describe one '
                . "model's columns, grain and filterable fields. Call this BEFORE run_report so "
                . 'you use real column names instead of guessing. The grain tells you whether '
                . 'counting rows answers the question or whether you need a distinct count.',
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
                name: 'model',
                type: PropertyType::STRING,
                description: 'Model name, e.g. "ejecutivo" or "inscripcion". Omit to list all available models.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $model = null): array
    {
        try {
            $registry = new ReportRegistry();
            $definitions = $registry->for($this->app);

            if ($definitions === []) {
                return ['models' => [], 'message' => 'This company has no reporting models configured.'];
            }

            if ($model === null) {
                return [
                    'models' => array_map(
                        fn ($d) => [
                            'model' => $d->model(),
                            'label' => $d->label(),
                            'grain' => $d->grain()->description(),
                        ],
                        array_values($definitions)
                    ),
                ];
            }

            $definition = $registry->find($this->app, $model);

            return [
                'model' => $definition->model(),
                'label' => $definition->label(),
                'grain' => $definition->grain()->description(),
                'primary_key' => $definition->primaryKey(),
                'operators' => ReportFilter::OPERATORS,
                'columns' => array_map(
                    fn (ReportColumn $c) => array_filter([
                        'name' => $c->name,
                        'label' => $c->label,
                        'type' => $c->type,
                        // Only the array columns accept MEMBER OF; saying so here stops the
                        // agent trying it on a scalar.
                        'multi_valued' => $c->multiValued ?: null,
                        'indexed' => $c->indexed ?: null,
                    ], fn ($v) => $v !== null),
                    $definition->columns()
                ),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
