<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;

class CreateToolAction
{
    public function __construct(
        protected readonly ToolData $data,
        protected readonly ?int $actorUserId = null,
    ) {
    }

    public function execute(): Tool
    {
        $this->validateFrameworks();

        return DB::connection('intelligence')->transaction(function (): Tool {
            $tool = new Tool();
            $tool->apps_id = $this->data->app->getId();
            $tool->name = $this->data->name;
            $tool->description = $this->data->description;
            $tool->tool_type = $this->data->toolType->value;
            $tool->handler = $this->data->handler;
            $tool->input_schema = $this->data->inputSchema;
            $tool->output_schema = $this->data->outputSchema;
            $tool->requires_permission = $this->data->requiresPermission;
            $tool->frameworks = $this->data->frameworks;
            $tool->version = $this->data->version;
            $tool->is_active = $this->data->isActive;
            $tool->saveOrFail();

            $tool->emitLedgerEvent(
                eventType: 'tool.created',
                payload: [
                    'name' => $tool->name,
                    'tool_type' => $tool->tool_type,
                    'frameworks' => $tool->frameworks,
                    'version' => $tool->version,
                ],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $tool;
        });
    }

    private function validateFrameworks(): void
    {
        $valid = CapabilityFrameworkEnum::values();

        foreach ($this->data->frameworks as $framework) {
            if (! in_array($framework, $valid, true)) {
                throw new ValidationException(sprintf(
                    'Unknown framework "%s". Allowed: %s',
                    $framework,
                    implode(', ', $valid),
                ));
            }
        }

        if (empty($this->data->frameworks)) {
            throw new ValidationException('A tool must declare at least one framework.');
        }
    }
}
