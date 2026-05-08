<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Models\Tool;

class UpdateToolAction
{
    public function __construct(
        protected readonly Tool $tool,
        protected readonly ToolData $data,
        protected readonly ?int $actorUserId = null,
    ) {
    }

    public function execute(): Tool
    {
        return DB::connection('intelligence')->transaction(function (): Tool {
            $diff = [
                'name' => [$this->tool->name, $this->data->name],
                'version' => [$this->tool->version, $this->data->version],
                'frameworks' => [$this->tool->frameworks, $this->data->frameworks],
            ];

            $this->tool->name = $this->data->name;
            $this->tool->description = $this->data->description;
            $this->tool->tool_type = $this->data->toolType->value;
            $this->tool->handler = $this->data->handler;
            $this->tool->input_schema = $this->data->inputSchema;
            $this->tool->output_schema = $this->data->outputSchema;
            $this->tool->requires_permission = $this->data->requiresPermission;
            $this->tool->frameworks = $this->data->frameworks;
            $this->tool->version = $this->data->version;
            $this->tool->is_active = $this->data->isActive;
            $this->tool->saveOrFail();

            $this->tool->emitLedgerEvent(
                eventType: 'tool.updated',
                payload: ['diff' => $diff],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $this->tool->fresh() ?? $this->tool;
        });
    }
}
