<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Services\EntityContextBriefService;
use NeuronAI\Tools\Tool;
use Override;

#[AgentTool(name: 'Read Entity Context', category: 'ecosystem')]
class ReadEntityContextTool extends Tool
{
    public function __construct(
        private readonly ?Model $subject = null,
    ) {
        parent::__construct(
            name: 'read_entity_context',
            description: 'Get a structured brief of the record you are currently working on (the lead, order, invoice, etc.) — its key facts and current status.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        if ($this->subject === null) {
            return [
                'status' => 'error',
                'message' => 'There is no record in scope for this conversation.',
            ];
        }

        return new EntityContextBriefService()->brief($this->subject);
    }
}
