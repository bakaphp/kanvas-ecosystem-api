<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Capability;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Services\CapabilityLookupService;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets an agent ask what the platform can do, rather than inferring it from the tools it holds.
 *
 * A model cannot reason about what is absent from its own context, so a request it has no tool for
 * gets resolved toward the nearest-named tool it does have. This is the query that turns "I don't
 * see a tool for that" into a fact it can act on — hand off, ask for a grant, or tell the user the
 * platform does not do this yet.
 */
#[AgentTool(name: 'Capability Lookup', category: 'ecosystem')]
class CapabilityLookupTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    public function __construct(
        private readonly ?Agent $executor = null,
    ) {
        parent::__construct(
            name: 'capability_lookup',
            description: 'Check whether Kanvas has a tool for something BEFORE you try it with a tool that only '
                . 'roughly fits. Answers with three things: matching tools you already have, matching tools the '
                . 'platform has that you were NOT granted (and which agents hold them), and whether nothing '
                . 'matches at all. Use it whenever a request sounds like a capability you are not sure you have — '
                . 'especially when the closest tool name is not quite what was asked for.',
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
                name: 'topic',
                type: PropertyType::STRING,
                description: 'What you are trying to do, in a few plain words — e.g. "create a spreadsheet", '
                    . '"refund an order", "send a contract for signature". Not a tool name.',
                required: true,
            ),
            new ToolProperty(
                name: 'category',
                type: PropertyType::STRING,
                description: 'Optional catalog category to narrow the search, e.g. "crm", "accounting", '
                    . '"productivity". Omit unless you already know the area.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $topic, ?string $category = null): array
    {
        if ($this->executor === null) {
            return [
                'status' => 'error',
                'message' => 'This tool needs to run as a specific agent and no agent is in scope, so it cannot '
                    . 'tell what you were granted. Answer from the tools you can already see.',
            ];
        }

        try {
            return new CapabilityLookupService($this->executor)->lookup($topic, $category);
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not read the capability catalog. Do not treat this as "the tool does not '
                    . 'exist" — answer from the tools you can already see, and say the catalog was unavailable.',
            ];
        }
    }
}
