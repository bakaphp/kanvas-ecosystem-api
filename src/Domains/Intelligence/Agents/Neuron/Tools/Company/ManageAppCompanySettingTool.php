<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\AppCompanyToolExecutor;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Manage App Company Setting', category: 'company')]
class ManageAppCompanySettingTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'manage_app_company_setting';

    protected ?string $description = 'For the Company Configuration Administrator at app scope with an identified app administrator: inspect or '
        . 'change one setting in an explicitly selected company of this app. This does NOT switch '
        . 'the chat, user or agent company. To copy, use get with the SOURCE UUID, adapt the value, '
        . 'then set with the DESTINATION UUID. Never copy agent/user IDs unchanged; resolve their '
        . 'destination equivalents first. Credentials are status-only. Writes are private. '
        . 'Supports general keys, with additional validation for known settings. A successful set '
        . 'only confirms that setting, not a complete company import.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'company_uuid',
                type: PropertyType::STRING,
                description: 'Explicit source UUID for get; explicit destination UUID for set. Must belong to this app.',
                required: true,
            ),
            new ToolProperty(
                name: 'operation',
                type: PropertyType::STRING,
                description: 'get, set or catalog (known setting types).',
                required: true,
            ),
            new ToolProperty(
                name: 'key',
                type: PropertyType::STRING,
                description: 'Exact setting key; required for get and set.',
                required: false,
            ),
            new ToolProperty(
                name: 'value_json',
                type: PropertyType::STRING,
                description: 'For set: JSON-encoded destination value. Never provide credentials.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $company_uuid,
        string $operation,
        ?string $key = null,
        ?string $value_json = null,
    ): array {
        $agent = $this->contextAgent();
        if (! isset($this->app, $this->user) || $agent === null || $this->requestingUser === null) {
            return $this->denied('App, agent and identified human context are required. Nothing was changed.');
        }

        try {
            return new AppCompanyToolExecutor()->execute(
                $this->app,
                $agent,
                $this->requestingUser,
                $company_uuid,
                function (Companies $company) use ($operation, $key, $value_json, $agent): array {
                    // New instance per call: reading A cannot redirect a subsequent write intended for B.
                    $tool = new ManageCompanySettingTool()
                        ->withContext(
                            $this->app,
                            $company,
                            $this->user,
                            $agent,
                        )
                        ->forRequestingUser($this->requestingUser);

                    return [
                        ...$tool($operation, $key, $value_json),
                        'company_uuid' => $company->uuid,
                        'company_name' => $company->name,
                    ];
                },
            );
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed('Could not complete the company operation. Verify the saved state before retrying.');
        }
    }
}
